<?php

namespace Drupal\Tests\sqlsrv\Unit;

use Drupal\Composer\Plugin\VendorHardening\Config;
use Drupal\Core\Composer\Composer;
use Drupal\Tests\Composer\ComposerIntegrationTrait;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests Composer integration.
 *
 * @group Composer
 */
class ComposerIntegrationTest extends UnitTestCase {
  use ComposerIntegrationTrait;

  /**
   * Tests the vendor cleanup utilities do not have obsolete packages listed.
   *
   * @dataProvider providerTestVendorCleanup
   */
  public function testVendorCleanup($class, $property) {
    $lock = json_decode(file_get_contents($this->root . '/composer.lock'), TRUE);
    $packages = [];
    foreach (array_merge($lock['packages'], $lock['packages-dev']) as $package) {
      $packages[] = $package['name'];
    }
    $reflection = new \ReflectionProperty($class, $property);
    $reflection->setAccessible(TRUE);
    $config = $reflection->getValue();
    foreach (array_keys($config) as $package) {
      $this->assertContains(strtolower($package), $packages);
    }
  }

  /**
   * Data provider for the vendor cleanup utility classes.
   *
   * @return array[]
   */
  public function providerTestVendorCleanup() {
    return [
      [Composer::class, 'packageToCleanup'],
      [Config::class, 'defaultConfig'],
    ];
  }
}
