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

use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Lock\Exception\LockReleasingException;
use Symfony\Component\Lock\Factory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\OptionsResolver\Exception\MissingOptionsException;

class Psr6StoreTest extends TestCase
{
    /**
     * @var Psr6Store
     */
    private $store;

    protected function setUp()
    {
        $this->store = new Psr6Store(['cache_directory' => sys_get_temp_dir()]);
    }

    protected function tearDown()
    {
        $this->getCache()->clear();
        $this->store->cleanup();
    }

    public function testCustomCacheAndLockFactory()
    {
        $cache = $this->createMock(TagAwareAdapterInterface::class);
        $cache->expects($this->once())
            ->method('deleteItem');
        $lockFactory = $this->createMock(Factory::class);

        $store = new Psr6Store([
            'cache' => $cache,
            'lock_factory' => $lockFactory,
        ]);

        $store->purge('/');
    }

    public function testItLocksTheRequest()
    {
        $request = Request::create('/');
        $result = $this->store->lock($request);

        $this->assertTrue($result, 'It returns true if lock is acquired.');
        $this->assertTrue($this->store->isLocked($request), 'Request is locked.');
    }

    public function testLockReturnsFalseIfTheLockWasAlreadyAcquired()
    {
        $request = Request::create('/');
        $this->store->lock($request);

        $result = $this->store->lock($request);

        $this->assertFalse($result, 'It returns false if lock could not be acquired.');
        $this->assertTrue($this->store->isLocked($request), 'Request is locked.');
    }

    public function testIsLockedReturnsFalseIfRequestIsNotLocked()
    {
        $request = Request::create('/');
        $this->assertFalse($this->store->isLocked($request), 'Request is not locked.');
    }

    public function testIsLockedReturnsTrueIfLockWasAcquired()
    {
        $request = Request::create('/');
        $this->store->lock($request);

        $this->assertTrue($this->store->isLocked($request), 'Request is locked.');
    }

    public function testUnlockReturnsFalseIfLockWasNotAquired()
    {
        $request = Request::create('/');
        $this->assertFalse($this->store->unlock($request), 'Request is not locked.');
    }

    public function testUnlockReturnsTrueIfLockIsReleased()
    {
        $request = Request::create('/');
        $this->store->lock($request);

        $this->assertTrue($this->store->unlock($request), 'Request was unlocked.');
        $this->assertFalse($this->store->isLocked($request), 'Request is not locked.');
    }

    public function testLocksAreReleasedOnCleanup()
    {
        $request = Request::create('/');
        $this->store->lock($request);

        $this->store->cleanup();

        $this->assertFalse($this->store->isLocked($request), 'Request is no longer locked.');
    }

    public function testSameLockCanBeAquiredAgain()
    {
        $request = Request::create('/');

        $this->assertTrue($this->store->lock($request));
        $this->assertTrue($this->store->unlock($request));
        $this->assertTrue($this->store->lock($request));
    }

    public function testWriteThrowsExceptionIfDigestCannotBeStored()
    {
        $innerCache = new ArrayAdapter();
        $cache = $this->getMockBuilder(TagAwareAdapter::class)
            ->setConstructorArgs([$innerCache])
            ->setMethods(['saveDeferred'])
            ->getMock();

        $cache
            ->expects($this->once())
            ->method('saveDeferred')
            ->willReturn(false);

        $store = new Psr6Store([
            'cache_directory' => sys_get_temp_dir(),
            'cache' => $cache,
        ]);

        $request = Request::create('/');
        $response = new Response('hello world', 200);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to store the entity.');
        $store->write($request, $response);
    }

    public function testWriteStoresTheResponseContent()
    {
        $request = Request::create('/');
        $response = new Response('hello world', 200);

        $contentDigest = $this->store->generateContentDigest($response);

        $this->store->write($request, $response);

        $this->assertTrue($this->getCache()->hasItem($contentDigest), 'Response content is stored in cache.');
        $this->assertSame($response->getContent(), $this->getCache()->getItem($contentDigest)->get(), 'Response content is stored in cache.');
        $this->assertSame($contentDigest, $response->headers->get('X-Content-Digest'), 'Content digest is stored in the response header.');
        $this->assertSame(\strlen($response->getContent()), $response->headers->get('Content-Length'), 'Response content length is updated.');
    }

    public function testWriteDoesNotStoreTheResponseContentOfNonOriginalResponse()
    {
        $request = Request::create('/');
        $response = new Response('hello world', 200);

        $contentDigest = $this->store->generateContentDigest($response);

        $response->headers->set('X-Content-Digest', $contentDigest);

        $this->store->write($request, $response);

        $this->assertFalse($this->getCache()->hasItem($contentDigest), 'Response content is not stored in cache.');
        $this->assertFalse($response->headers->has('Content-Length'), 'Response content length is not updated.');
    }

    public function testWriteOnlyUpdatesContentLengthIfThereIsNoTransferEncodingHeader()
    {
        $request = Request::create('/');
        $response = new Response('hello world', 200);
        $response->headers->set('Transfer-Encoding', 'chunked');

        $this->store->write($request, $response);

        $this->assertFalse($response->headers->has('Content-Length'), 'Response content length is not updated.');
    }

    public function testWriteStoresEntries()
    {
        $request = Request::create('/');
        $response = new Response('hello world', 200);
        $response->headers->set('age', 120);

        $cacheKey = $this->store->getCacheKey($request);

        $this->store->write($request, $response);

        $cacheItem = $this->getCache()->getItem($cacheKey);

        $this->assertInstanceOf(CacheItemInterface::class, $cacheItem, 'Metadata is stored in cache.');
        $this->assertTrue($cacheItem->isHit(), 'Metadata is stored in cache.');

        $entries = $cacheItem->get();

        $this->assertInternalType('array', $entries, 'Entries are stored in cache.');
        $this->assertCount(1, $entries, 'One entry is stored.');
        $this->assertSame($entries[Psr6Store::NON_VARYING_KEY]['headers'], array_diff_key($response->headers->all(), ['age' => []]), 'Response headers are stored with no age header.');
    }

    public function testWriteAddsTags()
    {
        $request = Request::create('/');
        $response = new Response('hello world', 200);
        $response->headers->set('Cache-Tags', 'foobar,other tag');

        $cacheKey = $this->store->getCacheKey($request);

        $this->store->write($request, $response);

        $this->assertTrue($this->getCache()->getItem($cacheKey)->isHit());
        $this->assertTrue($this->store->invalidateTags(['foobar']));
        $this->assertFalse($this->getCache()->getItem($cacheKey)->isHit());
    }

    public function testWriteAddsTagsWithMultipleHeaders()
    {
        $request = Request::create('/');
        $response = new Response('hello world', 200);
        $response->headers->set('Cache-Tags', ['foobar,other tag', 'some,more', 'tags', 'split,over', 'multiple-headers']);

        $cacheKey = $this->store->getCacheKey($request);

        $this->store->write($request, $response);

        $this->assertTrue($this->getCache()->getItem($cacheKey)->isHit());
        $this->assertTrue($this->store->invalidateTags(['multiple-headers']));
        $this->assertFalse($this->getCache()->getItem($cacheKey)->isHit());
    }

    public function testInvalidateTagsThrowsExceptionIfWrongCacheAdapterProvided()
    {
        $this->expectException(\RuntimeException::class);
        $store = new Psr6Store([
            'cache' => $this->createMock(AdapterInterface::class),
            'cache_directory' => 'foobar',
        ]);
        $store->invalidateTags(['foobar']);
    }

    public function testInvalidateTagsReturnsFalseOnException()
    {
        $innerCache = new ArrayAdapter();
        $cache = $this->getMockBuilder(TagAwareAdapter::class)
            ->setConstructorArgs([$innerCache])
            ->setMethods(['invalidateTags'])
            ->getMock();

        $cache
            ->expects($this->once())
            ->method('invalidateTags')
            ->willThrowException(new \Symfony\Component\Cache\Exception\InvalidArgumentException());

        $store = new Psr6Store([
            'cache_directory' => sys_get_temp_dir(),
            'cache' => $cache,
        ]);

        $this->assertFalse($store->invalidateTags(['foobar']));
    }

    public function testVaryResponseDropsNonVaryingOne()
    {
        $request = Request::create('/');
        $nonVarying = new Response('hello world', 200);
        $varying = new Response('hello world', 200, ['Vary' => 'Foobar', 'Foobar' => 'whatever']);

        $this->store->write($request, $nonVarying);

        $cacheKey = $this->store->getCacheKey($request);
        $cacheItem = $this->getCache()->getItem($cacheKey);
        $entries = $cacheItem->get();

        $this->assertCount(1, $entries);
        $this->assertSame(Psr6Store::NON_VARYING_KEY, key($entries));

        $this->store->write($request, $varying);

        $cacheItem = $this->getCache()->getItem($cacheKey);

        $entries = $cacheItem->get();

        $this->assertCount(1, $entries);
        $this->assertNotSame(Psr6Store::NON_VARYING_KEY, key($entries));
    }

    public function testRegularCacheKey()
    {
        $request = Request::create('https://foobar.com/');
        $expected = 'md'.hash('sha256', 'foobar.com/');
        $this->assertSame($expected, $this->store->getCacheKey($request));
    }

    public function testHttpAndHttpsGenerateTheSameCacheKey()
    {
        $request = Request::create('https://foobar.com/');
        $cacheKeyHttps = $this->store->getCacheKey($request);
        $request = Request::create('http://foobar.com/');
        $cacheKeyHttp = $this->store->getCacheKey($request);

        $this->assertSame($cacheKeyHttps, $cacheKeyHttp);
    }

    public function testRegularLookup()
    {
        $request = Request::create('https://foobar.com/');
        $response = new Response('hello world', 200);
        $response->headers->set('Foobar', 'whatever');

        $this->store->write($request, $response);

        $result = $this->store->lookup($request);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(200, $result->getStatusCode());
        $this->assertSame('hello world', $result->getContent());
        $this->assertSame('whatever', $result->headers->get('Foobar'));
    }

    public function testLookupWithEmptyCache()
    {
        $request = Request::create('https://foobar.com/');

        $result = $this->store->lookup($request);

        $this->assertNull($result);
    }

    public function testLookupWithVaryResponse()
    {
        $request = Request::create('https://foobar.com/');
        $request->headers->set('Foobar', 'whatever');
        $response = new Response('hello world', 200, ['Vary' => 'Foobar']);

        $this->store->write($request, $response);

        $request = Request::create('https://foobar.com/');
        $result = $this->store->lookup($request);
        $this->assertNull($result);

        $request = Request::create('https://foobar.com/');
        $request->headers->set('Foobar', 'whatever');
        $result = $this->store->lookup($request);
        $this->assertSame(200, $result->getStatusCode());
        $this->assertSame('hello world', $result->getContent());
        $this->assertSame('Foobar', $result->headers->get('Vary'));
    }

    public function testLookupWithMultipleVaryResponse()
    {
        $jsonRequest = Request::create('https://foobar.com/');
        $jsonRequest->headers->set('Accept', 'application/json');
        $htmlRequest = Request::create('https://foobar.com/');
        $htmlRequest->headers->set('Accept', 'text/html');

        $jsonResponse = new Response('{}', 200, ['Vary' => 'Accept', 'Content-Type' => 'application/json']);
        $htmlResponse = new Response('<html></html>', 200, ['Vary' => 'Accept', 'Content-Type' => 'text/html']);

        // Fill cache
        $this->store->write($jsonRequest, $jsonResponse);
        $this->store->write($htmlRequest, $htmlResponse);

        // Should return null because no header provided
        $request = Request::create('https://foobar.com/');
        $result = $this->store->lookup($request);
        $this->assertNull($result);

        // Should return null because header provided but non matching content
        $request = Request::create('https://foobar.com/');
        $request->headers->set('Accept', 'application/xml');
        $result = $this->store->lookup($request);
        $this->assertNull($result);

        // Should return a JSON response
        $request = Request::create('https://foobar.com/');
        $request->headers->set('Accept', 'application/json');
        $result = $this->store->lookup($request);
        $this->assertSame(200, $result->getStatusCode());
        $this->assertSame('{}', $result->getContent());
        $this->assertSame('Accept', $result->headers->get('Vary'));
        $this->assertSame('application/json', $result->headers->get('Content-Type'));

        // Should return an HTML response
        $request = Request::create('https://foobar.com/');
        $request->headers->set('Accept', 'text/html');
        $result = $this->store->lookup($request);
        $this->assertSame(200, $result->getStatusCode());
        $this->assertSame('<html></html>', $result->getContent());
        $this->assertSame('Accept', $result->headers->get('Vary'));
        $this->assertSame('text/html', $result->headers->get('Content-Type'));
    }

    public function testInvalidate()
    {
        $request = Request::create('https://foobar.com/');
        $response = new Response('hello world', 200);
        $response->headers->set('Foobar', 'whatever');

        $this->store->write($request, $response);
        $cacheKey = $this->store->getCacheKey($request);

        $cacheItem = $this->getCache()->getItem($cacheKey);
        $this->assertTrue($cacheItem->isHit());

        $this->store->invalidate($request);

        $cacheItem = $this->getCache()->getItem($cacheKey);
        $this->assertFalse($cacheItem->isHit());
    }

    public function testPurge()
    {
        $request = Request::create('https://foobar.com/');
        $response = new Response('hello world', 200);
        $response->headers->set('Foobar', 'whatever');

        $this->store->write($request, $response);
        $cacheKey = $this->store->getCacheKey($request);

        $cacheItem = $this->getCache()->getItem($cacheKey);
        $this->assertTrue($cacheItem->isHit());

        $this->store->purge('https://foobar.com/');

        $cacheItem = $this->getCache()->getItem($cacheKey);
        $this->assertFalse($cacheItem->isHit());
    }

    public function testPruneIgnoredIfCacheBackendDoesNotImplementPrunableInterface()
    {
        $cache = $this->getMockBuilder(RedisAdapter::class)
            ->disableOriginalConstructor()
            ->setMethods(['prune'])
            ->getMock();
        $cache
            ->expects($this->never())
            ->method('prune');

        $store = new Psr6Store([
            'cache_directory' => sys_get_temp_dir(),
            'cache' => $cache,
        ]);

        $store->prune();
    }

    public function testAutoPruneExpiredEntries()
    {
        $innerCache = new ArrayAdapter();
        $cache = $this->getMockBuilder(TagAwareAdapter::class)
            ->setConstructorArgs([$innerCache])
            ->setMethods(['prune'])
            ->getMock();

        $cache
            ->expects($this->exactly(3))
            ->method('prune');

        $store = new Psr6Store([
            'cache_directory' => sys_get_temp_dir(),
            'cache' => $cache,
            'prune_threshold' => 5,
        ]);

        foreach (range(1, 21) as $entry) {
            $request = Request::create('https://foobar.com/'.$entry);
            $response = new Response('hello world', 200);

            $store->write($request, $response);
        }

        $store->cleanup();
    }

    public function testAutoPruneIsSkippedIfThresholdDisabled()
    {
        $innerCache = new ArrayAdapter();
        $cache = $this->getMockBuilder(TagAwareAdapter::class)
            ->setConstructorArgs([$innerCache])
            ->setMethods(['prune'])
            ->getMock();

        $cache
            ->expects($this->never())
            ->method('prune');

        $store = new Psr6Store([
            'cache_directory' => sys_get_temp_dir(),
            'cache' => $cache,
            'prune_threshold' => 0,
        ]);

        foreach (range(1, 21) as $entry) {
            $request = Request::create('https://foobar.com/'.$entry);
            $response = new Response('hello world', 200);

            $store->write($request, $response);
        }

        $store->cleanup();
    }

    public function testItFailsWithoutCacheDirectoryForCache()
    {
        $this->expectException(MissingOptionsException::class);
        new Psr6Store([]);
    }

    public function testItFailsWithoutCacheDirectoryForLockStore()
    {
        $this->expectException(MissingOptionsException::class);
        new Psr6Store(['cache' => $this->createMock(AdapterInterface::class)]);
    }

    public function testUnlockReturnsFalseOnLockReleasingException()
    {
        $lock = $this->createMock(LockInterface::class);
        $lock
            ->expects($this->once())
            ->method('release')
            ->willThrowException(new LockReleasingException());

        $lockFactory = $this->createMock(Factory::class);
        $lockFactory
            ->expects($this->once())
            ->method('createLock')
            ->willReturn($lock);

        $store = new Psr6Store([
            'cache' => $this->createMock(AdapterInterface::class),
            'lock_factory' => $lockFactory,
        ]);

        $request = Request::create('/foobar');
        $store->lock($request);

        $this->assertFalse($store->unlock($request));
    }

    public function testLockReleasingExceptionIsIgnoredOnCleanup()
    {
        $lock = $this->createMock(LockInterface::class);
        $lock
            ->expects($this->once())
            ->method('release')
            ->willThrowException(new LockReleasingException());

        $lockFactory = $this->createMock(Factory::class);
        $lockFactory
            ->expects($this->once())
            ->method('createLock')
            ->willReturn($lock);

        $store = new Psr6Store([
            'cache' => $this->createMock(AdapterInterface::class),
            'lock_factory' => $lockFactory,
        ]);

        $request = Request::create('/foobar');
        $store->lock($request);
        $store->cleanup();

        // This test will fail if an exception is thrown, otherwise we mark it
        // as passed.
        $this->addToAssertionCount(1);
    }

    /**
     * @param null $store
     *
     * @return TagAwareAdapterInterface
     */
    private function getCache($store = null)
    {
        if (null === $store) {
            $store = $this->store;
        }

        $reflection = new \ReflectionClass($store);
        $cache = $reflection->getProperty('cache');
        $cache->setAccessible(true);

        return $cache->getValue($this->store);
    }
}
