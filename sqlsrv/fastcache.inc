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
   * Only enable this cache if the backend is DrupalWinCache
   * and the lock implementation is DrupalWinCache
   */
  public static function Enabled($refresh = FALSE) {
    if (static::$enabled === NULL || $refresh) {
      // Make sure _cache_get_object exists, if fastache
      // used out of database driver there is a chance that
      // cache storage might not yet be initialized.
      if (function_exists('_cache_get_object')) {
        global $conf;
        $lock_is_wincache = isset($conf['lock_inc']) && basename($conf['lock_inc'], '.inc') == 'wincache-lock';
        // Only enabled storage if Cache Backend is DrupalWinCache.
        static::$enabled = is_a(_cache_get_object('fastcache'), 'DrupalWinCache') && $lock_is_wincache;
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
    static::cache_load_ensure($bin);
    if (static::$fastcacheitems[$bin]->changed == FALSE) {
      static::$fastcacheitems[$bin]->changed = TRUE;
      // Do not lock if this is an atomic binary ($cid = $bin).
      if ($cid === $bin) {
        static::$fastcacheitems[$bin]->persist = TRUE;
        static::$fastcacheitems[$bin]->locked = FALSE;
      }
      else {
        // Do persist or lock if it is not enabled!
        if (static::Enabled()) {
          // Only persist if we are able to acquire a lock
          // for the entire binary.
          $phase = drupal_bootstrap(NULL, FALSE);
          // Hold this locks longer than usual because
          // they run after the request has finished.
          if ($phase >= 3 && lock_acquire('fastcache_' . $bin, 120)) {
            static::$fastcacheitems[$bin]->persist = TRUE;
            static::$fastcacheitems[$bin]->locked = TRUE;
          }
        }
      }
    }
    static::$fastcacheitems[$bin]->data($cid, $data);
  }

  /**
   * Called on shutdown, persists the cache
   * if necessary.
   */
  public static function fastcache_persist () {
    foreach (static::$fastcacheitems as $cache) {
      if ($cache->persist == TRUE) {
        cache_set($cache->bin, $cache->rawdata(), 'fastcache', CACHE_TEMPORARY);
        if ($cache->locked) {
          lock_release('fastcache_' . $cache->bin);
        }
      }
    }
  }
}
