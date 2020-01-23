<?php

namespace Drupal\Driver\Database\sqlsrv;

/**
 * An item in the fast cache.
 */
class FastCacheItem {

  /**
   * Persist.
   *
   * @var bool
   */
  public $persist = FALSE;

  /**
   * Changed.
   *
   * @var bool
   */
  public $changed = FALSE;

  /**
   * Locked.
   *
   * @var bool
   */
  public $locked = FALSE;

  /**
   * Binary.
   *
   * @var mixed
   */
  public $bin;

  /**
   * Cache Data.
   *
   * @var mixed
   */
  private $data;

  /**
   * Constructor.
   *
   * Construct with a DrupalCacheInterface object that comes from a real cache
   * storage.
   */
  public function __construct($binary, $cache = NULL) {
    if (isset($cache)) {
      $this->data = $cache->data;
    }
    else {
      $this->data = [];
    }
    $this->bin = $binary;
  }

  /**
   * Aux starts with string.
   */
  private function startsWith($haystack, $needle) {
    return $needle === "" || strpos($haystack, $needle) === 0;
  }

  /**
   * Get the raw data.
   *
   * Get the global contents of this cache.
   * Used to be sent to a real cache storage.
   */
  public function rawdata() {
    return $this->data;
  }

  /**
   * Set a value in cache.
   *
   * @param mixed $key
   *   Cache key.
   * @param mixed $value
   *   Value to be cached.
   */
  public function dataSet($key, $value) {
    $container = new \stdClass();
    $container->data = $value;
    $this->data[$key] = $container;
  }

  /**
   * Set a value in cache.
   *
   * @param mixed $key
   *   Cache key.
   * @param mixed $value
   *   Value to be cached.
   *
   * @deprecated in 8.x-1.0-rc6 and is removed from 8.x-1.0
   * @see Drupal Project Issue
   */
  public function data_set($key, $value) {
    $this->dataSet($key, $value);
  }
  
  /**
   * Retrieve a value from cache.
   *
   * @param mixed $key
   *   Cache key.
   *
   * @return bool|object
   *   Cache value.
   */
  public function dataGet($key) {
    if (isset($this->data[$key])) {
      return $this->data[$key];
    }
    return FALSE;
  }

  /**
   * Retrieve a value from cache.
   *
   *
   * @param mixed $key
   *   Cache key.
   *
   * @return bool|object
   *   Cache value.
   *
   * @deprecated in 8.x-1.0-rc6 and is removed from 8.x-1.0
   * @see Drupal Project Issue
   */
  public function data_get($key) {
    return $this->dataGet($key);
  }

  /**
   * Clear a cache item.
   *
   * @param string $key
   *   If set, the cache ID or an array of cache IDs. Otherwise, all cache
   *   entries that can expire are deleted. The $wildcard argument will be
   *   ignored if set to NULL.
   * @param bool $wildcard
   *   If TRUE, the $cid argument must contain a string value and cache IDs
   *   starting with $cid are deleted in addition to the exact cache ID
   *   specified by $cid. If $wildcard is TRUE and $cid is '*', the entire cache
   *   is emptied.
   */
  public function clear($key, $wildcard = FALSE) {
    if (!isset($key)) {
      if (empty($this->data)) {
        return;
      }
      else {
        $this->data = [];
      }
    }
    elseif (isset($key) && $wildcard === FALSE) {
      unset($this->data[$key]);
    }
    else {
      if ($key == '*') {
        // Completely reset this binary.
        unset($this->data);
        $this->data = [];
      }
      else {
        // Only reset items that start with $key.
        foreach ($this->data as $k => $v) {
          if ($this->startsWith($k, $key)) {
            unset($this->data[$k]);
          }
        }
      }
    }
    $this->persist = TRUE;
  }

}
