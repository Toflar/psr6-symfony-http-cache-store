<?php

/*
 * This file is part of the toflar/psr6-symfony-http-cache-store package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @copyright  Yanick Witschi <yanick.witschi@terminal42.ch>
 */

namespace Toflar\Psr6HttpCacheStore;

use Psr\Cache\InvalidArgumentException;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Lock\Exception\LockReleasingException;
use Symfony\Component\Lock\Factory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Lock\Store\SemaphoreStore;
use Symfony\Component\Lock\StoreInterface as LockStoreInterface;
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
class Psr6Store implements Psr6StoreInterface
{
    const NON_VARYING_KEY = 'non-varying';
    const COUNTER_KEY = 'write-operations-counter';

    /**
     * @var array
     */
    private $options;

    /**
     * @var TagAwareAdapterInterface
     */
    private $cache;

    /**
     * @var Factory
     */
    private $lockFactory;

    /**
     * @var LockInterface[]
     */
    private $locks = [];

    /**
     * When creating a Psr6Store you can configure a number options.
     *
     * Either cache_directory or cache and lock_factory are required. If you
     * want to set a custom cache / lock_factory, please **read the warning in
     * the README first**.
     *
     * - cache_directory:   Path to the cache directory for the default cache
     *                      adapter and lock factory.
     *
     * - cache:             Explicitly specify the cache adapter you want to
     *                      use. Make sure that lock and cache have the same
     *                      scope. *Read the warning in the README!*
     *
     *                      Type: Symfony\Component\Cache\Adapter\AdapterInterface
     *
     * - lock_factory:      Explicitly specify the cache adapter you want to
     *                      use. Make sure that lock and cache have the same
     *                      scope. *Read the warning in the README!*
     *
     *                      Type: Symfony\Component\Lock\Factory
     *                      Default: Factory with SemaphoreStore if available,
     *                               FlockStore otherwise.
     *
     * - prune_threshold:   Configure the number of write actions until the
     *                      store will prune the expired cache entries. Pass
     *                      0 to disable automated pruning.
     *
     *                      Type: int
     *                      Default: 500
     *
     * - cache_tags_header: Name of HTTP header containing a comma separated
     *                      list of tags to tag the response with.
     *
     *                      Type: string
     *                      Default: Cache-Tags
     *
     * @param array $options
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

        $resolver->setDefault('cache', function (Options $options) {
            if (!isset($options['cache_directory'])) {
                throw new MissingOptionsException('The cache_directory option is required unless you set the cache explicitly');
            }

            return new TagAwareAdapter(
                new FilesystemAdapter('http_cache', 0, $options['cache_directory'])
            );
        })->setAllowedTypes('cache', AdapterInterface::class);

        $resolver->setDefault('lock_factory', function (Options $options) {
            if (!isset($options['cache_directory'])) {
                throw new MissingOptionsException('The cache_directory option is required unless you set the cache explicitly');
            }

            return new Factory(
                $this->getDefaultLockStore($options['cache_directory'])
            );
        })->setAllowedTypes('lock_factory', Factory::class);

        $this->options = $resolver->resolve($options);
        $this->cache = $this->options['cache'];
        $this->lockFactory = $this->options['lock_factory'];
    }

    /**
     * Locates a cached Response for the Request provided.
     *
     * @param Request $request A Request instance
     *
     * @return Response|null A Response instance, or null if no cache entry was found
     */
    public function lookup(Request $request)
    {
        $cacheKey = $this->getCacheKey($request);

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

    /**
     * Writes a cache entry to the store for the given Request and Response.
     *
     * Existing entries are read and any that match the response are removed. This
     * method calls write with the new list of cache entries.
     *
     * @param Request  $request  A Request instance
     * @param Response $response A Response instance
     *
     * @return string The key under which the response is stored
     */
    public function write(Request $request, Response $response)
    {
        if (!$response->headers->has('X-Content-Digest')) {
            $contentDigest = $this->generateContentDigest($response);

            if (false === $this->saveDeferred($contentDigest, $response->getContent())) {
                throw new \RuntimeException('Unable to store the entity.');
            }

            $response->headers->set('X-Content-Digest', $contentDigest);

            if (!$response->headers->has('Transfer-Encoding')) {
                $response->headers->set('Content-Length', \strlen($response->getContent()));
            }
        }

        $cacheKey = $this->getCacheKey($request);
        $headers = $response->headers->all();
        unset($headers['age']);

        $item = $this->cache->getItem($cacheKey);

        if (!$item->isHit()) {
            $entries = [];
        } else {
            $entries = $item->get();
        }

        // Add or replace entry with current Vary header key
        $entries[$this->getVaryKey($response->getVary(), $request)] = [
            'vary' => $response->getVary(),
            'headers' => $headers,
            'status' => $response->getStatusCode(),
        ];

        // If the response has a Vary header we remove the non-varying entry
        if ($response->hasVary()) {
            unset($entries[self::NON_VARYING_KEY]);
        }

        // Tags
        $tags = [];
        if ($response->headers->has($this->options['cache_tags_header'])) {
            foreach ($response->headers->get($this->options['cache_tags_header'], '', false) as $header) {
                foreach (explode(',', $header) as $tag) {
                    $tags[] = $tag;
                }
            }
        }

        // Prune expired entries on file system if needed
        $this->autoPruneExpiredEntries();

        $this->saveDeferred($cacheKey, $entries, $response->getMaxAge(), $tags);

        $this->cache->commit();

        return $cacheKey;
    }

    /**
     * Invalidates all cache entries that match the request.
     *
     * @param Request $request A Request instance
     */
    public function invalidate(Request $request)
    {
        $cacheKey = $this->getCacheKey($request);

        $this->cache->deleteItem($cacheKey);
    }

    /**
     * Locks the cache for a given Request.
     *
     * @param Request $request A Request instance
     *
     * @return bool|string true if the lock is acquired, the path to the current lock otherwise
     */
    public function lock(Request $request)
    {
        $cacheKey = $this->getCacheKey($request);

        if (isset($this->locks[$cacheKey])) {
            return false;
        }

        $this->locks[$cacheKey] = $this->lockFactory
            ->createLock($cacheKey);

        return $this->locks[$cacheKey]->acquire();
    }

    /**
     * Releases the lock for the given Request.
     *
     * @param Request $request A Request instance
     *
     * @return bool False if the lock file does not exist or cannot be unlocked, true otherwise
     */
    public function unlock(Request $request)
    {
        $cacheKey = $this->getCacheKey($request);

        if (!isset($this->locks[$cacheKey])) {
            return false;
        }

        try {
            $this->locks[$cacheKey]->release();
        } catch (LockReleasingException $e) {
            return false;
        } finally {
            unset($this->locks[$cacheKey]);
        }

        return true;
    }

    /**
     * Returns whether or not a lock exists.
     *
     * @param Request $request A Request instance
     *
     * @return bool true if lock exists, false otherwise
     */
    public function isLocked(Request $request)
    {
        $cacheKey = $this->getCacheKey($request);

        if (!isset($this->locks[$cacheKey])) {
            return false;
        }

        return $this->locks[$cacheKey]->isAcquired();
    }

    /**
     * Purges data for the given URL.
     *
     * @param string $url A URL
     *
     * @return bool true if the URL exists and has been purged, false otherwise
     */
    public function purge($url)
    {
        $cacheKey = $this->getCacheKey(Request::create($url));

        return $this->cache->deleteItem($cacheKey);
    }

    /**
     * Release all locks.
     *
     * {@inheritdoc}
     */
    public function cleanup()
    {
        try {
            foreach ($this->locks as $lock) {
                $lock->release();
            }
        } catch (LockReleasingException $e) {
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
    public function invalidateTags(array $tags)
    {
        if (!$this->cache instanceof TagAwareAdapterInterface) {
            throw new \RuntimeException('Cannot invalidate tags on a cache
            implementation that does not implement the TagAwareAdapterInterface.');
        }

        try {
            return $this->cache->invalidateTags($tags);
        } catch (InvalidArgumentException $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function prune()
    {
        if (!$this->cache instanceof PruneableInterface) {
            return;
        }

        $this->cache->prune();
    }

    /**
     * @param Request $request
     *
     * @return string
     */
    public function getCacheKey(Request $request)
    {
        // Strip scheme to treat https and http the same
        $uri = $request->getUri();
        $uri = substr($uri, \strlen($request->getScheme().'://'));

        return 'md'.hash('sha256', $uri);
    }

    /**
     * @param array   $vary
     * @param Request $request
     *
     * @return string
     */
    public function getVaryKey(array $vary, Request $request)
    {
        if (0 === \count($vary)) {
            return self::NON_VARYING_KEY;
        }

        sort($vary);

        $hashData = '';

        foreach ($vary as $headerName) {
            $hashData .= $headerName.':'.$request->headers->get($headerName);
        }

        return hash('sha256', $hashData);
    }

    /**
     * @param Response $response
     *
     * @return string
     */
    public function generateContentDigest(Response $response)
    {
        return 'en'.hash('sha256', $response->getContent());
    }

    /**
     * Increases a counter every time an item is stored to the cache and then
     * prunes expired cache entries if a configurable threshold is reached.
     * This only happens during write operations so cache retrieval is not
     * slowed down.
     */
    private function autoPruneExpiredEntries()
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

    /**
     * @param string $key
     * @param string $data
     * @param int    $expiresAfter
     * @param array  $tags
     *
     * @return bool
     */
    private function saveDeferred($key, $data, $expiresAfter = null, $tags = [])
    {
        $item = $this->cache->getItem($key);
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
     *
     * @return Response|null
     */
    private function restoreResponse(array $cacheData)
    {
        $body = null;

        if (isset($cacheData['headers']['x-content-digest'][0])) {
            $item = $this->cache->getItem($cacheData['headers']['x-content-digest'][0]);
            if ($item->isHit()) {
                $body = $item->get();
            }
        }

        return new Response(
            $body,
            $cacheData['status'],
            $cacheData['headers']
        );
    }

    /**
     * Build and return a default lock factory for when no explicit factory
     * was specified.
     * The default factory uses the best quality lock store that is available
     * on this system.
     *
     * @param string $cacheDir
     *
     * @return LockStoreInterface
     *
     * @codeCoverageIgnore Depends on your system.
     */
    private function getDefaultLockStore($cacheDir)
    {
        if (SemaphoreStore::isSupported(false)) {
            return new SemaphoreStore();
        }

        return new FlockStore($cacheDir);
    }
}
