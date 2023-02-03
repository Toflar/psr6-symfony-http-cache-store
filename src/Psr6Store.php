<?php

declare(strict_types=1);

/*
 * This file is part of the toflar/psr6-symfony-http-cache-store package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @copyright  Yanick Witschi <yanick.witschi@terminal42.ch>
 */

namespace Toflar\Psr6HttpCacheStore;

use Psr\Cache\InvalidArgumentException as CacheInvalidArgumentException;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\FilesystemTagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Lock\Exception\InvalidArgumentException as LockInvalidArgumentException;
use Symfony\Component\Lock\Exception\LockReleasingException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Lock\Store\SemaphoreStore;
use Symfony\Component\OptionsResolver\Exception\MissingOptionsException;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Implements a storage for Symfony's HttpCache that supports PSR-6 cache
 * back ends, auto-pruning of expired entries on local filesystem and cache
 * invalidation by tags.
 *
 * @author Yanick Witschi <yanick.witschi@terminal42.ch>
 */
class Psr6Store implements Psr6StoreInterface, ClearableInterface
{
    public const NON_VARYING_KEY = 'non-varying';
    public const COUNTER_KEY = 'write-operations-counter';
    public const CLEANUP_LOCK_KEY = 'cleanup-lock';

    private array $options;
    private AdapterInterface $cache;
    private LockFactory $lockFactory;

    /**
     * @var LockInterface[]
     */
    private array $locks = [];

    private string $hashAlgorithm;

    /**
     * When creating a Psr6Store you can configure a number of options.
     * See the README for a list of all available options and their description.
     */
    public function __construct(array $options = [])
    {
        $resolver = new OptionsResolver();

        $resolver->setDefined('cache_directory')
            ->setAllowedTypes('cache_directory', 'string');

        $resolver->setDefault('prune_threshold', 500)
            ->setAllowedTypes('prune_threshold', 'int');

        $resolver->setDefault('cache_tags_header', 'Cache-Tags')
            ->setAllowedTypes('cache_tags_header', 'string');

        $resolver->setDefault('generate_content_digests', true)
            ->setAllowedTypes('generate_content_digests', 'boolean');

        $resolver->setDefault('cache', function (Options $options) {
            if (!isset($options['cache_directory'])) {
                throw new MissingOptionsException('The cache_directory option is required unless you set the cache explicitly');
            }

            return new FilesystemTagAwareAdapter('', 0, $options['cache_directory']);
        })->setAllowedTypes('cache', AdapterInterface::class);

        $resolver->setDefault('lock_factory', function (Options $options) {
            if (!isset($options['cache_directory'])) {
                throw new MissingOptionsException('The cache_directory option is required unless you set the lock_factory explicitly as by default locks are also stored in the configured cache_directory.');
            }

            $defaultLockStore = $this->getDefaultLockStore($options['cache_directory']);

            return new LockFactory($defaultLockStore);
        })->setAllowedTypes('lock_factory', LockFactory::class);

        $this->options = $resolver->resolve($options);
        $this->cache = $this->options['cache'];
        $this->lockFactory = $this->options['lock_factory'];
        $this->hashAlgorithm = \PHP_VERSION_ID >= 80100 ? 'xxh128' : 'sha256';
    }

    public function lookup(Request $request): ?Response
    {
        $cacheKey = $this->getCacheKey($request);

        /** @var CacheItem $item */
        $item = $this->cache->getItem($cacheKey);

        if (!$item->isHit()) {
            return null;
        }

        $entries = $item->get();

        foreach ($entries as $varyKeyResponse => $responseData) {
            // This can only happen if one entry only
            if (self::NON_VARYING_KEY === $varyKeyResponse) {
                return $this->restoreResponse($responseData);
            }

            // Otherwise we have to see if Vary headers match
            $varyKeyRequest = $this->getVaryKey(
                $responseData['vary'],
                $request
            );

            if ($varyKeyRequest === $varyKeyResponse) {
                return $this->restoreResponse($responseData);
            }
        }

        return null;
    }

    public function write(Request $request, Response $response): string
    {
        if (null === $response->getMaxAge()) {
            throw new \InvalidArgumentException('HttpCache should not forward any response without any cache expiration time to the store.');
        }

        // Save the content digest if required
        $this->saveContentDigest($response);

        $cacheKey = $this->getCacheKey($request);
        $headers = $response->headers->all();
        unset($headers['age']);

        /** @var CacheItem $item */
        $item = $this->cache->getItem($cacheKey);

        if (!$item->isHit()) {
            $entries = [];
        } else {
            $entries = $item->get();
        }

        // Add or replace entry with current Vary header key
        $varyKey = $this->getVaryKey($response->getVary(), $request);
        $entries[$varyKey] = [
            'vary' => $response->getVary(),
            'headers' => $headers,
            'status' => $response->getStatusCode(),
            'uri' => $request->getUri(), // For debugging purposes
        ];

        // Add content if content digests are disabled
        if (!$this->options['generate_content_digests']) {
            $entries[$varyKey]['content'] = $response->getContent();
        }

        // If the response has a Vary header we remove the non-varying entry
        if ($response->hasVary()) {
            unset($entries[self::NON_VARYING_KEY]);
        }

        // Tags
        $tags = [];
        foreach ($response->headers->all($this->options['cache_tags_header']) as $header) {
            foreach (explode(',', $header) as $tag) {
                $tags[] = $tag;
            }
        }

        // Prune expired entries on file system if needed
        $this->autoPruneExpiredEntries();

        $this->saveDeferred($item, $entries, $response->getMaxAge(), $tags);

        // Commit all deferred cache items
        $this->cache->commit();

        return $cacheKey;
    }

    public function invalidate(Request $request): void
    {
        $cacheKey = $this->getCacheKey($request);

        $this->cache->deleteItem($cacheKey);
    }

    public function lock(Request $request): bool|string
    {
        $cacheKey = $this->getCacheKey($request);

        if (isset($this->locks[$cacheKey])) {
            return false;
        }

        $this->locks[$cacheKey] = $this->lockFactory
            ->createLock($cacheKey);

        return $this->locks[$cacheKey]->acquire();
    }

    public function unlock(Request $request): bool
    {
        $cacheKey = $this->getCacheKey($request);

        if (!isset($this->locks[$cacheKey])) {
            return false;
        }

        try {
            $this->locks[$cacheKey]->release();
        } catch (LockReleasingException) {
            return false;
        } finally {
            unset($this->locks[$cacheKey]);
        }

        return true;
    }

    public function isLocked(Request $request): bool
    {
        $cacheKey = $this->getCacheKey($request);

        if (!isset($this->locks[$cacheKey])) {
            return false;
        }

        return $this->locks[$cacheKey]->isAcquired();
    }

    public function purge(string $url): bool
    {
        $cacheKey = $this->getCacheKey(Request::create($url));

        return $this->cache->deleteItem($cacheKey);
    }

    public function cleanup(): void
    {
        try {
            foreach ($this->locks as $lock) {
                $lock->release();
            }
        } catch (LockReleasingException) {
            // noop
        } finally {
            $this->locks = [];
        }
    }

    /**
     * The tags are set from the header configured in cache_tags_header.
     *
     * {@inheritdoc}
     */
    public function invalidateTags(array $tags): bool
    {
        if (!$this->cache instanceof TagAwareAdapterInterface) {
            throw new \RuntimeException('Cannot invalidate tags on a cache
            implementation that does not implement the TagAwareAdapterInterface.');
        }

        try {
            return $this->cache->invalidateTags($tags);
        } catch (CacheInvalidArgumentException) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function prune(): void
    {
        if (!$this->cache instanceof PruneableInterface) {
            return;
        }

        // Make sure we do not have multiple clearing or pruning processes running
        $lock = $this->lockFactory->createLock(self::CLEANUP_LOCK_KEY);

        if ($lock->acquire()) {
            $this->cache->prune();

            $lock->release();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): void
    {
        // Make sure we do not have multiple clearing or pruning processes running
        $lock = $this->lockFactory->createLock(self::CLEANUP_LOCK_KEY);

        if ($lock->acquire()) {
            $this->cache->clear();

            $lock->release();
        }
    }

    public function getCacheKey(Request $request): string
    {
        // Strip scheme to treat https and http the same
        $uri = $request->getUri();
        $uri = substr($uri, \strlen($request->getScheme().'://'));

        return 'md'.hash($this->hashAlgorithm, $uri);
    }

    /**
     * @internal Do not use in public code, this is for unit testing purposes only
     */
    public function generateContentDigest(Response $response): ?string
    {
        if ($response instanceof BinaryFileResponse) {
            return 'bf'.hash_file('sha256', $response->getFile()->getPathname());
        }

        if (!$this->options['generate_content_digests']) {
            return null;
        }

        return 'en'.hash($this->hashAlgorithm, $response->getContent());
    }

    private function getVaryKey(array $vary, Request $request): string
    {
        if (0 === \count($vary)) {
            return self::NON_VARYING_KEY;
        }

        // Normalize
        $vary = array_map('strtolower', $vary);
        sort($vary);

        $hashData = '';

        foreach ($vary as $headerName) {
            if ('cookie' === $headerName) {
                continue;
            }

            $hashData .= $headerName.':'.$request->headers->get($headerName);
        }

        if (\in_array('cookie', $vary, true)) {
            $hashData .= 'cookies:';
            foreach ($request->cookies->all() as $k => $v) {
                $hashData .= $k.'='.$v;
            }
        }

        return hash('sha256', $hashData);
    }

    private function saveContentDigest(Response $response): void
    {
        if ($response->headers->has('X-Content-Digest')) {
            return;
        }

        $contentDigest = $this->generateContentDigest($response);

        if (null === $contentDigest) {
            return;
        }

        $digestCacheItem = $this->cache->getItem($contentDigest);

        if ($digestCacheItem->isHit()) {
            $cacheValue = $digestCacheItem->get();

            // BC
            if (\is_string($cacheValue)) {
                $cacheValue = [
                    'expires' => 0, // Forces update to the new format
                    'contents' => $cacheValue,
                ];
            }
        } else {
            $cacheValue = [
                'expires' => 0, // Forces storing the new entry
                'contents' => $this->isBinaryFileResponseContentDigest($contentDigest) ?
                    $response->getFile()->getPathname() :
                    $response->getContent(),
            ];
        }

        $responseMaxAge = (int) $response->getMaxAge();

        // Update expires key and save the entry if required
        if ($responseMaxAge > $cacheValue['expires']) {
            $cacheValue['expires'] = $responseMaxAge;

            if (false === $this->saveDeferred($digestCacheItem, $cacheValue, $responseMaxAge)) {
                throw new \RuntimeException('Unable to store the entity.');
            }
        }

        $response->headers->set('X-Content-Digest', $contentDigest);

        // Make sure the content-length header is present
        if (!$response->headers->has('Transfer-Encoding')) {
            $response->headers->set('Content-Length', (string) \strlen((string) $response->getContent()));
        }
    }

    /**
     * Test whether a given digest identifies a BinaryFileResponse.
     *
     * @param string $digest
     */
    private function isBinaryFileResponseContentDigest($digest): bool
    {
        return 'bf' === substr($digest, 0, 2);
    }

    /**
     * Increases a counter every time a write action is performed and then
     * prunes expired cache entries if a configurable threshold is reached.
     * This only happens during write operations so cache retrieval is not
     * slowed down.
     */
    private function autoPruneExpiredEntries(): void
    {
        if (0 === $this->options['prune_threshold']) {
            return;
        }

        $item = $this->cache->getItem(self::COUNTER_KEY);
        $counter = (int) $item->get();

        if ($counter > $this->options['prune_threshold']) {
            $this->prune();
            $counter = 0;
        } else {
            ++$counter;
        }

        $item->set($counter);

        $this->cache->saveDeferred($item);
    }

    private function saveDeferred(CacheItem $item, $data, ?int $expiresAfter = null, array $tags = []): bool
    {
        $item->set($data);
        $item->expiresAfter($expiresAfter);

        if (0 !== \count($tags)) {
            $item->tag($tags);
        }

        return $this->cache->saveDeferred($item);
    }

    /**
     * Restores a Response from the cached data.
     *
     * @param array $cacheData An array containing the cache data
     */
    private function restoreResponse(array $cacheData): ?Response
    {
        // Check for content digest header
        if (!isset($cacheData['headers']['x-content-digest'][0])) {
            // No digest was generated but the content was stored inline
            if (isset($cacheData['content'])) {
                return new Response(
                    $cacheData['content'],
                    $cacheData['status'],
                    $cacheData['headers']
                );
            }

            // No content digest and no inline content means we cannot restore the response
            return null;
        }

        $item = $this->cache->getItem($cacheData['headers']['x-content-digest'][0]);

        if (!$item->isHit()) {
            return null;
        }

        $value = $item->get();

        // BC
        if (\is_string($value)) {
            $value = ['contents' => $value];
        }

        if ($this->isBinaryFileResponseContentDigest($cacheData['headers']['x-content-digest'][0])) {
            try {
                $file = new File($value['contents']);
            } catch (FileNotFoundException) {
                return null;
            }

            return new BinaryFileResponse(
                $file,
                $cacheData['status'],
                $cacheData['headers']
            );
        }

        return new Response(
            $value['contents'],
            $cacheData['status'],
            $cacheData['headers']
        );
    }

    /**
     * Build and return a default lock factory for when no explicit factory
     * was specified.
     * The default factory uses the best quality lock store that is available
     * on this system.
     */
    private function getDefaultLockStore(string $cacheDir): PersistingStoreInterface
    {
        try {
            return new SemaphoreStore();
        } catch (LockInvalidArgumentException) {
            return new FlockStore($cacheDir);
        }
    }
}
