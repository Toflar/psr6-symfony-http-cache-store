# PSR-6 compatible Symfony HttpCache Store

[![](https://img.shields.io/travis/Toflar/psr6-symfony-http-cache-store/master.svg?style=flat-square)](https://travis-ci.org/Toflar/psr6-symfony-http-cache-store/)
[![](https://img.shields.io/coveralls/Toflar/psr6-symfony-http-cache-store/master.svg?style=flat-square)](https://coveralls.io/github/Toflar/psr6-symfony-http-cache-store)

## Introduction

Symfony's `HttpCache` store implementation is rather old and was developed
when there were no separate components for locking and caching yet. Moreover, 
expired cache entries are never pruned and thus causes your cache directory
to continue to grow forever until you delete it manually.

Along the way, I needed support for cache invalidation based on tags which was
pretty easy to implement thanks to the Symfony Cache component.

This bundle thus provides an alternative `StoreInterface` implementation
that…

* …instead of re-implementing locking and caching mechanisms again, uses the well
tested Symfony Cache and Lock components, both with the local filesystem adapters
by default.
* …thanks to the `TagAwareAdapterInterface` of the Cache component, supports tag
based cache invalidation.
* …thanks to the `PrunableInterface` of the Cache component, supports auto-pruning
of expired entries on the filesystem trying to prevent flooding the filesystem.
* …allows you to use a different PSR-6 cache adapters as well as a different 
lock adapter than the local filesystem ones.
 However, **be careful about choosing the right adapters**, see warning below.
* …supports `BinaryFileResponse` instances.

## Installation

```
$ composer require toflar/psr6-symfony-http-cache-store
```

## Configuration

For the Symfony 3 standard edition file structure, use the `Psr6Store` by
enabling it in your `AppCache` as follows:

```php
<?php

    // app/AppCache.php

    /**
     * Overwrite constructor to register the Psr6Store.
     */
    public function __construct(
        HttpKernelInterface $kernel,
        SurrogateInterface $surrogate = null,
        array $options = []
    ) {
        $store = new Psr6Store(['cache_directory' => $kernel->getCacheDir()]);

        parent::__construct($kernel, $store, $surrogate, $options);
    }
    
```

For the Symfony 4/Flex structure, you need to adjust your `index.php` like this:

```php
<?php

// public/index.php
$kernel = new Kernel($env, $debug);
$kernel = new HttpCache(
    $kernel,
    new Psr6Store(['cache_directory' => $kernel->getCacheDir()]),
    null,
    ['debug' => $debug]
);
```

That's it, that's all there is to do. The `Psr6Store` will automatically
create the best caching and locking adapters available for your local filesystem.

If you want to go beyond this point, the `Psr6Store` can be configured by
passing an array of `$options` in the constructor:

* **cache_directory**: Path to the cache directory for the default cache
  adapter and lock factory.

  Either this or both `cache` and `lock_factory` are required.

  **Type**: `string`

* **cache**: Explicitly specify the cache adapter you want to use.

  Note that if you want to make use of cache tagging, this cache must
  implement the `Symfony\Component\Cache\Adapter\TagAwareAdapterInterface`
  Make sure that `lock` and `cache` have the same scope. *See warning below!*

  **Type**: `Symfony\Component\Cache\Adapter\AdapterInterface`
  **Default**: `FilesystemAdapter` instance with `cache_directory`

* **lock_factory**: Explicitly specify the lock factory you want to use. Make
  sure that lock and cache have the same scope. *See warning below!*

  **Type**: `Symfony\Component\Lock\Factory`
  **Default**: `Factory` with `SemaphoreStore` if supported, `FlockStore` otherwise

* **prune_threshold**: Configure the number of write actions until the store
  will prune the expired cache entries. Pass `0` to disable automated pruning.

  **Type**: `int`
  **Default**: `500`

* **cache_tags_header**: The HTTP header name used to check for tags

  **Type**: `string`
  **Default**: `Cache-Tags`

* **min_digest_ttl**: The minimum TTL for content digest cache items

  **Type**: `int`
  **Default**: `86400`
  
### Caching `BinaryFileResponse` instances

This cache implementation allows to cache `BinaryFileResponse` instances but the files are not actually copied to
the cache directory. It will just try to fetch the original file and if that does not exist anymore, the store returns
`null`, causing HttpCache to deal with it as a cache miss and continue normally.
It is ideal for use cases such as caching `/favicon.ico` requests where you would like to prevent the application from
being started and thus deliver the response from HttpCache.

### Cache tagging

Tag cache entries by adding a response header with the tags as a comma 
separated value. By default, that header is called `Cache-Tags`, this can be
overwritten in `cache_tags_header`.

To invalidate tags, call the method `Psr6Store::invalidateTags` or use the
`PurgeTagsListener` from the [FOSHttpCache][3] library to handle tag 
invalidation requests.

### Expiry of content digest cache items

To optimize storage, responses that share the same
content also share the same cache item for this content.
E.g. the very same HTML response is never cached twice
but rather referenced to. The cache item for the request
itself shall be called "request meta cache item" and the
content it references to, is the "content digest cache item".
You can find the calculated hash in the "X-Content-Digest"
response header (prefixed with "X-" for compatibility with
the Symfony default Store implementation).

When a request meta cache item gets invalidated,
we cannot invalidate  the content digest cache item because
that would mean, it implicitly invalidates all the other
request meta cache items, as their content digest cache item
is not available anymore.
However, never deleting them at all would mean they remain in
our cache forever.
This is kind of a bad situation because the content digest is
usually a lot bigger than the meta information. So it's the
one we would really like to clean up!

To solve this, we need to also expire content digest cache items.
We cannot, however, expire them the same time we expire the request
meta cache item (based it's Cache-Control or Expire headers).
Why not? Imagine this:

- Request 1:
  The response is a 10 MB file and is cacheable for 10 minutes.
- Request 2:
  The response is the same 10 MB file but in this case, it's
  cacheable for 24 hours.


If we were to expire the content digest item the same as the meta
cache item, it would mean it will expire after 10 minutes and thus,
implicitly also expire our cache item for request 2 although we
could've kept that in the cache for 24 hours!
If request 2 came before request 1, it wouldn't be a problem.
The issue here is with responses that can be cached for a rather short time,
sharing the content with responses that can be cached for a longer time.
If the one with a shorter lifetime generates the digest first, it would
implicitly reduce the cache lifetime of all the other request meta
cache items.

To circumvent this, you can configure a minimum lifetime for the
content digest cache items using the `min_digest_ttl` option.

### Pruning expired cache entries

By default, this store removes expired entries from the cache after every `500`
cache **write** operations. Fetching data does not affect performance.
You can change the automated pruning frequency with the `prune_threshold`
configuration setting.

You can also manually trigger pruning by calling the `prune()` method on the
cache. With this, you could for example implement a cron job that loads the store
and prunes it at a configured interval, to prevent slowing down random requests
that were cache misses because they have to wait for the pruning to happen. If you
have set up a cron job, you should disable auto pruning by setting the threshold
to `0`.

### WARNING

It is possible to configure other cache adapters or lock stores than the
filesystem ones. Only do this if you are sure of what you are doing. In
[this pull request][1] Fabien refused to add PSR-6 store support to
the Symfony `AppCache` with the following arguments:

* Using a filesystem allows for `opcache` to make the cache very
  effective;
* The cache contains some PHP (when using ESI for instance) and storing
  PHP in anything else than a filesystem would mean `eval()`-ing
  strings coming from Redis / Memcache /...;
* HttpCache is triggered very early and does not have access to the
  container or anything else really. And it should stay that way to be
  efficient.

While the first and third point depend on what you do and need, be sure to
respect the second point. If you use network enabled caches like Redis or
Memcache, make sure that they are not shared with other systems to avoid code
injection!


### Credits

I would like to thank [David][2] for his invaluable feedback on this library
while we were working on an integration for the awesome [FOSHttpCache][3] library.

[1]: https://github.com/symfony/symfony/pull/20061#issuecomment-313339092
[2]: https://github.com/dbu
[3]: https://github.com/FriendsOfSymfony/FOSHttpCache
