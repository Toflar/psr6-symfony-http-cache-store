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

use Symfony\Component\HttpKernel\HttpCache\StoreInterface;

/**
 * Interface for the Psr6Store that eases mocking the
 * final implementation for third party libraries.
 *
 * @author Yanick Witschi <yanick.witschi@terminal42.ch>
 */
interface Psr6StoreInterface extends StoreInterface
{
    /**
     * Remove/Expire cache objects based on cache tags.
     *
     * @param array $tags Tags that should be removed/expired from the cache
     *
     * @throws \RuntimeException if incompatible cache adapter provided
     *
     * @return bool true on success, false otherwise
     */
    public function invalidateTags(array $tags);

    /**
     * Prunes expired entries.
     * This method must not throw any exception but silently try to
     * prune the file system if the cache adapter supports it.
     */
    public function pruneExpiredEntries();
}
