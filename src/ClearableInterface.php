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

/**
 * @author Yanick Witschi <yanick.witschi@terminal42.ch>
 */
interface ClearableInterface
{
    /**
     * Clears the whole store.
     */
    public function clear(): void;
}
