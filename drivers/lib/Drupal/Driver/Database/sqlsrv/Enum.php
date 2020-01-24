<?php

namespace Drupal\Driver\Database\sqlsrv;

/**
 * Base Enum class.
 *
 * Create an enum by implementing this class and adding class constants.
 */
abstract class Enum {

  /**
   * Enum value.
   *
   * @var mixed
   */
  protected $value;

  /**
   * Store existing constants in a static cache per object.
   *
   * @var array
   */
  private static $cache = [];

  /**
   * Creates a new value of some type.
   *
   * @param mixed $value
   *   Save the value.
   *
   * @throws \UnexpectedValueException
   *   If incompatible type is given.
   */
  public function __construct($value) {
    if (!$this->isValid($value)) {
      throw new \UnexpectedValueException("Value '$value' is not part of the enum " . get_called_class());
    }
    $this->value = $value;
  }

  /**
   * Get the enum value.
   *
   * @return mixed
   *   Enum value.
   */
  public function getValue() {
    return $this->value;
  }

  /**
   * Returns the enum key (i.e. the constant name).
   *
   * @return mixed
   *   Enum key.
   */
  public function getKey() {
    return self::search($this->value);
  }

  /**
   * To String.
   *
   * @return string
   *   The value as a string.
   */
  public function __toString() {
    return (string) $this->value;
  }

  /**
   * Returns the names (keys) of all constants in the Enum class.
   *
   * @return array
   *   An array of the keys.
   */
  public static function keys() {
    return array_keys(self::toArray());
  }

  /**
   * Returns instances of the Enum class of all Enum constants.
   *
   * @return array
   *   Constant name in key, Enum instance in value.
   */
  public static function values() {
    $values = [];
    foreach (self::toArray() as $key => $value) {
      $values[$key] = new static($value);
    }
    return $values;
  }

  /**
   * Returns all possible values as an array.
   *
   * @return array
   *   Constant name in key, constant value in value.
   */
  public static function toArray() {
    $class = get_called_class();
    if (!array_key_exists($class, self::$cache)) {
      $reflection = new \ReflectionClass($class);
      self::$cache[$class] = $reflection->getConstants();
    }
    return self::$cache[$class];
  }

  /**
   * Check if is valid enum value.
   *
   * @param mixed $value
   *   Enum value.
   *
   * @return bool
   *   Is the value valid?
   */
  public static function isValid($value) {
    return in_array($value, self::toArray(), TRUE);
  }

  /**
   * Check if is valid enum key.
   *
   * @param mixed $key
   *   Key name.
   *
   * @return bool
   *   Is the key valid?
   */
  public static function isValidKey($key) {
    $array = self::toArray();
    return isset($array[$key]);
  }

  /**
   * Return key for value.
   *
   * @param mixed $value
   *   Value.
   *
   * @return mixed
   *   Key.
   */
  public static function search($value) {
    return array_search($value, self::toArray(), TRUE);
  }

  /**
   * Call Static.
   *
   * Returns a value when called statically like so:
   * MyEnum::SOME_VALUE() given SOME_VALUE is a class constant.
   *
   * @param string $name
   *   Name.
   * @param array $arguments
   *   Arguments.
   *
   * @return static
   *
   * @throws \BadMethodCallException
   */
  public static function __callStatic($name, array $arguments) {
    if (defined("static::$name")) {
      return new static(constant("static::$name"));
    }
    throw new \BadMethodCallException("No static method or enum constant '$name' in class " . get_called_class());
  }

}
