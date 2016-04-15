<?php

/**
 * @file
 * fastcache class.
 */

namespace Drupal\Driver\Database\sqlsrv;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\Cache;

use Drupal\wincache\Cache\DummyTagChecksum;
use Drupal\wincache\Cache\WincacheBackend;

/**
 * Static caching layer.
 *
 * We need a special caching layer for the special
 * SQLServer use case.
 *
 * Virtual binaries are loaded at once, stored and manipulated
 * in memory during request execution and persisted at once
 * during shutdown.
 *
 * It uses regular cache backends for storage (see enabled())
 * and when not available, will only work as a volatile per
 * request cache.
 *
 */
class FastCache {

  /**
   * Build an instance of FastCache.
   *
   * @param string $prefix
   *   Unique site prefix.
   */
  public function __construct($prefix) {

    if (!is_string($prefix)) {
      throw new \Exception("FastCache prefix must be a string.");
    }

    $this->prefix = $prefix;
  }

  /** @var FastCacheItem[]  $fastcacheitems */
  private $fastcacheitems = array();

  /** @var bool $enabled */
  private $enabled = NULL;

  /** @var bool $shutdown_registered */
  private $shutdown_registered = FALSE;

  /**
   * Site prefix.
   *
   * @var string
   */
  private $prefix = '';

  /**
   * Make sure that keys without binaries have their own binaries
   * and that a valid test prefix is used.
   *
   * @param string $key
   * @param string $bin
   * @return void
   */
  private function FixKeyAndBin(&$key, &$bin) {
    // We always need a binary, if non is specified, this item
    // should be treated as having it's own binary.
    if (empty($bin)) {
      $bin = $key;
    }
    $bin = $this->prefix . $bin;
  }

  /**
   * Summary of $cache
   *
   * @var WincacheBackend
   */
  private $cache;

  /**
   * Tell if cache persistence is enabled. If not, this cache
   * will behave as DRUPAL_STATIC until the end of request.
   *
   * Only enable this cache if the backend is DrupalWinCache
   * and the lock implementation is DrupalWinCache
   */
  public function Enabled($refresh = FALSE) {
    return !empty($this->cache);
  }

  /**
   * cache_clear_all wrapper.
   */
  public function cache_clear_all($cid = NULL, $bin = NULL, $wildcard = FALSE) {
    $this->FixKeyAndBin($cid, $bin);
    if (!isset($this->fastcacheitems[$bin])) {
      $this->cache_load_ensure($bin, TRUE);
    }
    // If the cache did not exist, it will still not be loaded.
    if (isset($this->fastcacheitems[$bin])) {
      $this->fastcacheitems[$bin]->clear($cid, $wildcard);
    }
  }

  /**
   * Ensure cache binary is statically loaded.
   */
  private function cache_load_ensure($bin, $skiploadifempty = FALSE) {
    if (!isset($this->fastcacheitems[$bin])) {
      // If storage is enabled, try to load from cache.
      if ($this->Enabled()) {
        if ($cache = $this->cache->get($bin)) {
          $this->fastcacheitems[$bin] = new FastCacheItem($bin, $cache);
        }
        // Don't bother initializing this.
        elseif ($skiploadifempty) {
          return;
        }
      }
      // If still not set, initialize.
      if (!isset($this->fastcacheitems[$bin])) {
        $this->fastcacheitems[$bin] = new FastCacheItem($bin);
      }
      // Register shutdown persistence once, only if enabled!
      if ($this->shutdown_registered == FALSE && $this->Enabled()) {
        register_shutdown_function(array(&$this, 'persist'));
        $this->shutdown_registered = TRUE;
      }
    }
  }

  /**
   * cache_get wrapper.
   */
  public function get($cid, $bin = NULL) {
    $this->FixKeyAndBin($cid, $bin);
    $this->cache_load_ensure($bin);
    return $this->fastcacheitems[$bin]->data_get($cid);
  }

  /**
   * cache_set wrapper.
   */
  public function set($cid, $data, $bin = NULL) {
    $this->FixKeyAndBin($cid, $bin);
    $this->cache_load_ensure($bin);
    if ($this->fastcacheitems[$bin]->changed == FALSE) {
      $this->fastcacheitems[$bin]->changed = TRUE;
      // Do not lock if this is an atomic binary ($cid = $bin).
      if ($cid === $bin) {
        $this->fastcacheitems[$bin]->persist = TRUE;
        //$this->fastcacheitems[$bin]->locked = FALSE;
      }
      else {
        // Do persist or lock if it is not enabled!
        if ($this->Enabled()) {
          // Hold this locks longer than usual because
          // they run after the request has finished.
          // if (function_exists('lock_acquire') && lock_acquire('fastcache_' . $bin, 120)) {
          $this->fastcacheitems[$bin]->persist = TRUE;
          //  $this->fastcacheitems[$bin]->locked = TRUE;
          //}
        }
      }
    }
    $this->fastcacheitems[$bin]->data_set($cid, $data);
  }

  /**
   * Called on shutdown, persists the cache
   * if necessary.
   */
  public function persist() {
    foreach ($this->fastcacheitems as $cache) {
      if ($cache->persist == TRUE) {
        $this->cache->set($cache->bin, $cache->rawdata(), CacheBackendInterface::CACHE_PERMANENT);
        //if ($cache->locked) {
        //  lock_release('fastcache_' . $cache->bin);
        //}
      }
    }
  }
}