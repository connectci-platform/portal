<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel\Traits;

use Drupal\Core\Config\FileStorage;

/**
 * Imports a subset of sites/default/config/default into a kernel test.
 *
 * Strips third_party_settings and module dependencies for modules not enabled
 * in the kernel env so ConfigSchemaChecker doesn't trip. Extracted from the
 * copy-pasted importProdConfig() that lived in each ood_software kernel test.
 */
trait ProdConfigTrait {

  /**
   * @param string[] $names
   *   Config object names (without .yml).
   */
  protected function importProdConfig(array $names): void {
    $source = new FileStorage(DRUPAL_ROOT . '/sites/default/config/default');
    $target = \Drupal::service('config.storage');
    $configManager = \Drupal::service('config.manager');
    $entityTypeManager = \Drupal::entityTypeManager();
    foreach ($names as $name) {
      $data = $source->read($name);
      if ($data === FALSE) {
        throw new \RuntimeException("Missing prod config: $name");
      }
      if (isset($data['third_party_settings']) && is_array($data['third_party_settings'])) {
        foreach (array_keys($data['third_party_settings']) as $module) {
          if (!\Drupal::moduleHandler()->moduleExists($module)) {
            unset($data['third_party_settings'][$module]);
          }
        }
      }
      if (isset($data['dependencies']['module']) && is_array($data['dependencies']['module'])) {
        $data['dependencies']['module'] = array_values(array_filter(
          $data['dependencies']['module'],
          fn($m) => \Drupal::moduleHandler()->moduleExists($m)
        ));
      }
      $entityTypeId = $configManager->getEntityTypeIdByName($name);
      if ($entityTypeId) {
        $storage = $entityTypeManager->getStorage($entityTypeId);
        $idKey = $storage->getEntityType()->getKey('id');
        $existingId = $data[$idKey] ?? NULL;
        if ($existingId && $storage->load($existingId)) {
          continue;
        }
        $storage->createFromStorageRecord($data)->save();
      }
      else {
        $target->write($name, $data);
      }
    }
  }

}
