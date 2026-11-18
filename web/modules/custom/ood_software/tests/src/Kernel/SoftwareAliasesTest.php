<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;

/**
 * Covers D8-2960: Software entries carry "Also known as" names.
 *
 * The Appverse review tool reads field_appverse_software_aliases from the
 * catalog API and treats each name as the entry's title when it suggests an
 * app's software. The 10018 deploy hook fills in the first list.
 *
 * @group ood_software
 */
class SoftwareAliasesTest extends KernelTestBase {

  use ProdConfigTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'filter',
    'options',
    'datetime',
    'link',
    'taxonomy',
    'path',
    'path_alias',
    'content_moderation',
    'workflows',
    // ood_software.gh depends on the `key` module's key.repository service.
    'key',
    // ood_software_node_insert() calls the `flag` service.
    'flag',
    'file',
    'ood_software',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('content_moderation_state');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter', 'user', 'node', 'taxonomy', 'content_moderation', 'workflows']);

    $this->importProdConfig([
      'node.type.appverse_software',
      'field.storage.node.field_appverse_software_aliases',
      'field.field.node.appverse_software.field_appverse_software_aliases',
      'field.storage.node.field_appverse_software_website',
      'field.field.node.appverse_software.field_appverse_software_website',
      'taxonomy.vocabulary.tags',
      'taxonomy.vocabulary.appverse_license',
      'field.storage.node.field_tags',
      'field.field.node.appverse_software.field_tags',
      'field.storage.node.field_license',
      'field.field.node.appverse_software.field_license',
      'field.storage.node.body',
      'field.field.node.appverse_software.body',
    ]);
  }

  /**
   * Runs the 10018 deploy hook and returns its message.
   */
  protected function runSeed(): string {
    $path = \Drupal::service('extension.list.module')->getPath('ood_software');
    require_once DRUPAL_ROOT . '/' . $path . '/ood_software.deploy.php';
    $message = (string) ood_software_deploy_10018_seed_software_aliases();
    \Drupal::entityTypeManager()->getStorage('node')->resetCache();
    return $message;
  }

  /**
   * Creates a Software entry.
   */
  protected function software(string $title): NodeInterface {
    $node = Node::create(['type' => 'appverse_software', 'title' => $title, 'status' => 1]);
    $node->save();
    return $node;
  }

  /**
   * The alias values on a node, in order.
   *
   * @return string[]
   *   The aliases.
   */
  protected function aliases(NodeInterface $node): array {
    return array_column($node->get('field_appverse_software_aliases')->getValue(), 'value');
  }

  /**
   * The field holds several names on a Software entry.
   */
  public function testFieldHoldsSeveralNames(): void {
    $node = $this->software('RStudio');
    $node->set('field_appverse_software_aliases', ['RStudio Server', 'rstudio-server']);
    $node->save();
    self::assertSame(['RStudio Server', 'rstudio-server'], $this->aliases(Node::load($node->id())));
  }

  /**
   * The seed fills matching entries without a new revision.
   */
  public function testSeedFillsMatchingEntries(): void {
    $vscode = $this->software('Visual Studio Code');
    $gradio = $this->software('Gradio App');
    $other = $this->software('MATLAB');
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $revisions = count($storage->getQuery()->accessCheck(FALSE)->allRevisions()->condition('nid', $vscode->id())->execute());

    $message = $this->runSeed();

    self::assertSame(['VS Code', 'VSCode', 'code-server', 'Code Server'], $this->aliases(Node::load($vscode->id())));
    self::assertSame(['Gradio'], $this->aliases(Node::load($gradio->id())));
    self::assertSame([], $this->aliases(Node::load($other->id())));
    self::assertCount($revisions, $storage->getQuery()->accessCheck(FALSE)->allRevisions()->condition('nid', $vscode->id())->execute());
    self::assertStringContainsString('Added aliases to 2 Software entries (Visual Studio Code, Gradio App)', $message);
    self::assertStringContainsString('Not in the catalog: Nsight Eclipse, Multitenant Apps, Workflow Composers, SSH Terminal, RStudio, Octave.', $message);
  }

  /**
   * Names staff already entered are kept, and a re-run changes nothing.
   */
  public function testSeedKeepsExistingNamesAndIsIdempotent(): void {
    $vscode = $this->software('Visual Studio Code');
    $vscode->set('field_appverse_software_aliases', ['Code OSS']);
    $vscode->save();
    $gradio = $this->software('Gradio App');

    $this->runSeed();
    self::assertSame(['Code OSS'], $this->aliases(Node::load($vscode->id())));
    self::assertSame(['Gradio'], $this->aliases(Node::load($gradio->id())));

    $message = $this->runSeed();
    self::assertStringContainsString('Added aliases to 0 Software entries (none)', $message);
    self::assertSame(['Gradio'], $this->aliases(Node::load($gradio->id())));
  }


  /**
   * Runs the 10019 deploy hook and returns its message.
   */
  protected function runEessiSoftware(): string {
    $path = \Drupal::service('extension.list.module')->getPath('ood_software');
    require_once DRUPAL_ROOT . '/' . $path . '/ood_software.deploy.php';
    $message = (string) ood_software_deploy_10019_add_eessi_software();
    \Drupal::entityTypeManager()->getStorage('node')->resetCache();
    return $message;
  }

  /**
   * The EESSI software is added published, and an existing title is kept.
   */
  public function testEessiSoftwareIsAdded(): void {
    $existing = $this->software('mlflow');
    foreach (['python', 'plotting', 'visualization', 'data-analysis', 'Programming', 'gui'] as $name) {
      Term::create(['vid' => 'tags', 'name' => $name])->save();
    }
    $oss = Term::create(['vid' => 'appverse_license', 'name' => 'Open-Source License']);
    $oss->save();

    $message = $this->runEessiSoftware();

    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $bokeh = $storage->loadByProperties(['type' => 'appverse_software', 'title' => 'Bokeh']);
    self::assertCount(1, $bokeh);
    $bokeh = reset($bokeh);
    self::assertInstanceOf(NodeInterface::class, $bokeh);
    self::assertTrue($bokeh->isPublished());
    self::assertSame('https://bokeh.org', $bokeh->get('field_appverse_software_website')->first()?->getValue()['uri'] ?? NULL);
    self::assertStringStartsWith('Bokeh is a Python library', (string) ($bokeh->get('body')->first()?->getValue()['value'] ?? ''));
    self::assertSame(['python', 'plotting', 'visualization', 'data-analysis'], $this->termNames($bokeh, 'field_tags'));
    self::assertSame(['Open-Source License'], $this->termNames($bokeh, 'field_license'));
    // "programming" is matched by its exact name, never as "Programming".
    $qt = $storage->loadByProperties(['type' => 'appverse_software', 'title' => 'IPython QtConsole']);
    $qt = reset($qt);
    self::assertInstanceOf(NodeInterface::class, $qt);
    self::assertSame(['python', 'gui'], $this->termNames($qt, 'field_tags'));
    self::assertCount(1, $storage->loadByProperties(['type' => 'appverse_software', 'title' => 'MLflow']));
    self::assertSame('mlflow', Node::load($existing->id())->label());
    self::assertStringContainsString('Created 17 Software entries', $message);
    self::assertStringContainsString('Already in the catalog: MLflow.', $message);
    self::assertStringContainsString('tags:programming', $message);
    self::assertStringContainsString('appverse_license:Apache License 2.0', $message);

    $again = $this->runEessiSoftware();
    self::assertStringContainsString('Created 0 Software entries (none)', $again);
    self::assertCount(18, $storage->loadByProperties(['type' => 'appverse_software']));
  }

  /**
   * The hub's resolver accepts the EESSI declarations once both hooks ran.
   */
  public function testEessiDeclarationsResolve(): void {
    $this->software('RStudio');
    $this->software('Octave');
    $this->runSeed();
    $this->runEessiSoftware();

    $github = \Drupal::service('ood_software.gh');
    foreach (['GRASS GIS' => 'GRASS GIS', 'RStudio Server' => 'RStudio', 'GNU Octave' => 'Octave', 'xterm' => 'xterm'] as $declared => $title) {
      self::assertSame($title, $github->resolveSoftwareForApp($declared)['resolvedTitle'], $declared);
    }
  }

  /**
   * Every logo the 10019 hook names is bundled with the module.
   */
  public function testEessiLogosAreBundled(): void {
    $dir = DRUPAL_ROOT . '/' . \Drupal::service('extension.list.module')->getPath('ood_software') . '/logos/eessi';
    $source = (string) file_get_contents(dirname($dir, 2) . '/ood_software.deploy.php');
    $start = strpos($source, 'function ood_software_deploy_10019_add_eessi_software');
    self::assertNotFalse($start);
    preg_match_all("/'([a-z]+\\.(?:svg|png))'\\]/", substr($source, $start), $m);
    self::assertCount(14, $m[1]);
    foreach ($m[1] as $file) {
      self::assertFileExists($dir . '/' . $file);
    }
  }

  /**
   * The names of the terms a node references in a field, in order.
   *
   * @return string[]
   *   The term names.
   */
  protected function termNames(NodeInterface $node, string $field): array {
    $names = [];
    foreach ($node->get($field)->referencedEntities() as $term) {
      $names[] = (string) $term->label();
    }
    return $names;
  }

}
