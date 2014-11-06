<?php

/**
 * @file
 * fastcacheitem Class.
 */

class fastcacheitem {
  public $persist = FALSE;
  public $changed = FALSE;
  public $bin;
  private $data;

  /**
   * Construct with a DrupalCacheInterface object
   * that comes from a real cache storage.
   */
  public function __construct($binary, $cache = NULL) {
    if (isset($cache)) {
      $this->data = $cache->data;
    }
    else {
      $this->data = array();
    }
    $this->bin = $binary;
  }

  /**
   * Aux starsWith string.
   */
  private function startsWith($haystack, $needle) {
    return $needle === "" || strpos($haystack, $needle) === 0;
  }

  /**
   * Get the global contents of this cache.
   * Used to be sent to a real cache
   * storage.
   */
  public function rawdata() {
    return $this->data;
  }

  /**
   * Get/Set a cache item.
   */
  public function data($key, $value = NULL) {
    if (isset($value)) {
      $this->data[$key] = $value;
    }

    if (isset($this->data[$key])) {
      // Encapsulate inside object!
      $result = new stdClass();
      $result->data = $this->data[$key];
      return $result;
    }

    return FALSE;
  }

  /**
   * Clear a cache item.
   */
  public function clear($key, $wildcard = NULL) {
    if (!isset($key)) {
      $this->data = array();
    }
    else if (isset($key) && !isset($wildcard)) {
      unset($this->data[$key]);
    }
    else {
      foreach ($this->data as $k => $v) {
      	if ($this->startsWith($k, $wildcard)) {
          unset($this->data[$k]);
        }
      }
    }
    $this->persist = TRUE;
  }
}
