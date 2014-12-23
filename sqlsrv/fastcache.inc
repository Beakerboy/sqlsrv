<?php

/**
 * @file
 * fastcache class.
 */

include_once 'fastcacheitem.inc';

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
   * Tell if cache persistence is enabled. If not, this cache
   * will behave as DRUPAL_STATIC until the end of request.
   * 
   * Only enable this cache if the backend is
   * not DrupalDatabaseCache!
   */
  private static function Enabled($refresh = FALSE) {
    if (static::$enabled == NULL || $refresh) {
      // Make sure _cache_get_object exists, if fastache
      // used out of database driver there is a chance that
      // cache storage might not yet be initialized.
      if (function_exists('_cache_get_object')) {
        // Only enabled storage if Cache Backend is DrupalWinCache.
        static::$enabled = is_a(_cache_get_object('fastcache'), 'DrupalWinCache');
      }
      else {
        static::$enabled = FALSE;
      }
    }
    return static::$enabled;
  }

  /**
   * cache_clear_all wrapper.
   */
  public static function cache_clear_all($cid = NULL, $bin = NULL, $wildcard = FALSE) {
    if (!isset(static::$fastcacheitems[$bin])) {
      static::cache_load_ensure($bin);
    }
    static::$fastcacheitems[$bin]->clear($cid, $wildcard);
  }

  /**
   * Ensure cache binary is statically loaded.
   */
  private static function cache_load_ensure($bin) {
    if (!isset(static::$fastcacheitems[$bin])) {
      // If storage is enabled, try to load from cache.
      if (static::Enabled()) {
        if ($cache = cache_get($bin, 'fastcache')) {
          static::$fastcacheitems[$bin] = new fastcacheitem($bin, $cache);
        }
      }
      // If still not set, initialize.
      if (!isset(static::$fastcacheitems[$bin])) {
        static::$fastcacheitems[$bin] = new fastcacheitem($bin);
      }
      if (static::$shutdown_registered == FALSE) {
        register_shutdown_function(array('fastcache','fastcache_persist'));
        static::$shutdown_registered = TRUE;
      }
    }
  }

  /**
   * cache_get wrapper.
   */
  public static function cache_get ($cid, $bin = NULL) {
    if ($bin == NULL) {
      $bin = $cid;
    }
    static::cache_load_ensure($bin);
    return static::$fastcacheitems[$bin]->data($cid);
  }

  /**
   * cache_set wrapper.
   */
  public static function cache_set ($cid, $data, $bin = NULL) {
    if ($bin == NULL) {
      $bin = $cid;
    }
    if (static::$fastcacheitems[$bin]->changed == FALSE) {
      static::$fastcacheitems[$bin]->changed = TRUE;
      // Lock this binary until persisted.
      // Locks are only available after BOOTSTRAP_VARIABLE (3)
      // phase.
      $phase = drupal_bootstrap(NULL, FALSE);
      if ($phase >= 3 && lock_acquire('fastcache_' . $bin)) {
        static::$fastcacheitems[$bin]->persist = TRUE;
      }
    }
    static::$fastcacheitems[$bin]->data($cid, $data);
  }

  /**
   * Called on shutdown, persists the cache
   * if necessary.
   */
  public static function fastcache_persist () {
    // Check if storage available, event if availability
    // has changed since initial check, to be consistent
    // only persist if cache was available from start.
    if (!static::Enabled()) {
      return;
    }
    foreach (static::$fastcacheitems as $cache) {
      if ($cache->persist == TRUE) {
        cache_set($cache->bin, $cache->rawdata(), 'fastcache', CACHE_TEMPORARY);
        lock_release('fastcache_' . $cache->bin);
      }
    }
  }
}
