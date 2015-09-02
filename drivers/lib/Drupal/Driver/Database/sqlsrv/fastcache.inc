<?php

/**
 * @file
 * fastcache class.
 */

include_once 'fastcacheitem.inc';

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\Cache;

use Drupal\wincache\Cache\DummyTagChecksum;
use Drupal\wincache\Cache\WincacheBackend;

/**
 * Static caching layer.
 *
 * Database layer for SQL Server
 * is very Regex intensive.
 * Cannot use a regultar cache
 * backend because the enormous number
 * of cache_get and cache_set calls
 * end up crashing memcache or wincache!
 * Here everything is statically managed
 * and sent to a real cache backend once
 * the request is over.
 */
class fastcache {
  
  // @var fastcacheitem[]  $fastcacheitems
  private static $fastcacheitems = array();

  // @var bool $enabled
  private static $enabled = NULL;

  // @var bool $shutdown_registered
  private static $shutdown_registered = FALSE;

  /**
   * Test info is loaded at database bootstrap phase
   * but this cache can be used earlier. Make sure
   * we have a valid test prefix before any operation on
   * the cache is performed.
   *
   * @var string
   */
  private static $test_run_id;

  private static function FixKeyAndBin($key, $bin) {}

  /**
   * Summary of $cache
   * 
   * @var WincacheBackend
   */
  private static $cache;
  
  /**
   * Tell if cache persistence is enabled. If not, this cache
   * will behave as DRUPAL_STATIC until the end of request.
   * 
   * Only enable this cache if the backend is DrupalWinCache
   * and the lock implementation is DrupalWinCache
   */
  public static function Enabled($refresh = FALSE) {
    if (empty(static::$cache)) {
      if (class_exists(\Drupal\wincache\Cache\WincacheBackend::class)) {
        $checksum_provider = new DummyTagChecksum();
        static::$cache = new WincacheBackend('fastcache', 'test', $checksum_provider);
      }
    }
    return !empty(static::$cache);
  }

  /**
   * cache_clear_all wrapper.
   */
  public static function cache_clear_all($cid = NULL, $bin = NULL, $wildcard = FALSE) {
    static::FixKeyAndBin($cid, $bin);
    if (!isset(static::$fastcacheitems[$bin])) {
      static::cache_load_ensure($bin, TRUE);
    }
    // If the cache did not exist, it will still not be loaded.
    if (isset(static::$fastcacheitems[$bin])) {
      static::$fastcacheitems[$bin]->clear($cid, $wildcard);
    }
  }

  /**
   * Ensure cache binary is statically loaded.
   */
  private static function cache_load_ensure($bin, $skiploadifempty = FALSE) {
    if (!isset(static::$fastcacheitems[$bin])) {
      // If storage is enabled, try to load from cache.
      if (static::Enabled()) {
        if ($cache = static::$cache->get($bin)) {
          static::$fastcacheitems[$bin] = new fastcacheitem($bin, $cache);
        }
        // Don't bother initializing this.
        elseif ($skiploadifempty) {
          return;
        }
      }
      // If still not set, initialize.
      if (!isset(static::$fastcacheitems[$bin])) {
        static::$fastcacheitems[$bin] = new fastcacheitem($bin);
      }
      // Register shutdown persistence once, only if enabled!
      if (static::$shutdown_registered == FALSE && static::Enabled()) {
        register_shutdown_function(array('fastcache','fastcache_persist'));
        static::$shutdown_registered = TRUE;
      }
    }
  }

  /**
   * cache_get wrapper.
   */
  public static function cache_get($cid, $bin = NULL) {
    static::FixKeyAndBin($cid, $bin);
    static::cache_load_ensure($bin);
    return static::$fastcacheitems[$bin]->data_get($cid);
  }

  /**
   * cache_set wrapper.
   */
  public static function cache_set($cid, $data, $bin = NULL) {
    static::FixKeyAndBin($cid, $bin);
    static::cache_load_ensure($bin);
    if (static::$fastcacheitems[$bin]->changed == FALSE) {
      static::$fastcacheitems[$bin]->changed = TRUE;
      // Do not lock if this is an atomic binary ($cid = $bin).
      if ($cid === $bin) {
        static::$fastcacheitems[$bin]->persist = TRUE;
        //static::$fastcacheitems[$bin]->locked = FALSE;
      }
      else {
        // Do persist or lock if it is not enabled!
        if (static::Enabled()) {
          // Hold this locks longer than usual because
          // they run after the request has finished.
          // if (function_exists('lock_acquire') && lock_acquire('fastcache_' . $bin, 120)) {
          static::$fastcacheitems[$bin]->persist = TRUE;
          //  static::$fastcacheitems[$bin]->locked = TRUE;
          //}
        }
      }
    }
    static::$fastcacheitems[$bin]->data_set($cid, $data);
  }

  /**
   * Called on shutdown, persists the cache
   * if necessary.
   */
  public static function fastcache_persist () {
    foreach (static::$fastcacheitems as $cache) {
      if ($cache->persist == TRUE) {
        static::$cache->set($cache->bin, $cache->rawdata(), CacheBackendInterface::CACHE_PERMANENT);
        //if ($cache->locked) {
        //  lock_release('fastcache_' . $cache->bin);
        //}
      }
    }
  }
}
