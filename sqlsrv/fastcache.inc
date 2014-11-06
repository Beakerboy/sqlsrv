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
  
  // Storage for the virtual binaries.
  private static $fastcacheitems = array();
  // If this cache layer should be enabled.
  private static $enabled;
  // If shutdown has been registered.
  private static $shutdown_registered = FALSE;
  
  /**
   * Tell if cache should be enabled.
   * 
   * Only enable this cache if the backend is
   * not DrupalDatabaseCache!
   */
  private static function Enabled() {
    if (!isset(fastcache::$enabled)) {
      // Disable caching if using default database cache system.
      fastcache::$enabled = is_a(_cache_get_object('fastcache'), 'DrupalWinCache');
    }
    return fastcache::$enabled;
  }

  /**
   * cache_clear_all wrapper.
   */
  public static function cache_clear_all($cid = NULL, $bin = NULL, $wildcard = FALSE) {
    if (!fastcache::Enabled()) {
      return;
    }
    if (!isset(fastcache::$fastcacheitems[$bin])) {
      cache_load_ensure($bin);
    }
    fastcache::$fastcacheitems[$bin]->clear($cid, $wildcard);
  }

  /**
   * Ensure cache binary is statically loaded.
   */
  private static function cache_load_ensure($bin) {
    if (!isset(fastcache::$fastcacheitems[$bin])) {
      if ($cache = cache_get($bin, 'fastcache')) {
        fastcache::$fastcacheitems[$bin] = new fastcacheitem($bin, $cache);
      }
      else {
        fastcache::$fastcacheitems[$bin] = new fastcacheitem($bin);
      }
      if (fastcache::$shutdown_registered == FALSE) {
        register_shutdown_function(array('fastcache','fastcache_persist'));
        fastcache::$shutdown_registered = TRUE;
      }
    }
  }

  /**
   * cache_get wrapper.
   */
  public static function cache_get ($cid, $bin) {
    if (!fastcache::Enabled()) {
      return FALSE;
    }
    fastcache::cache_load_ensure($bin);
    return fastcache::$fastcacheitems[$bin]->data($cid);
  }

  /**
   * cache_set wrapper.
   */
  public static function cache_set ($cid, $data, $bin) {
    if (!fastcache::Enabled() || !isset(fastcache::$fastcacheitems[$bin])) {
      return $data;
    }
    if (fastcache::$fastcacheitems[$bin]->changed == FALSE) {
      fastcache::$fastcacheitems[$bin]->changed = TRUE;
      // Lock this binary until persisted.
      // Locks are only available after BOOTSTRAP_VARIABLE (3)
      // phase.
      $phase = drupal_bootstrap(NULL, FALSE);
      if ($phase >= 3 && lock_acquire('fastcache_' . $bin)) {
        fastcache::$fastcacheitems[$bin]->persist = TRUE;
      }
    }
    fastcache::$fastcacheitems[$bin]->data($cid, $data);
  }

  /**
   * Called on shutdown, persists the cache
   * if necessary.
   */
  public static function fastcache_persist () {
    foreach (fastcache::$fastcacheitems as $cache) {
      /* @var $cache fastcacheitem */
      if ($cache->persist == TRUE) {
        cache_set($cache->bin, $cache->rawdata(), 'fastcache', CACHE_TEMPORARY);
        lock_release('fastcache_' . $cache->bin);
      }
    }
  }
}
