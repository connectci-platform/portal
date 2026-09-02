<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\ood_software\Form\AppverseHubCreateTagForm;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;

/**
 * Covers AppverseHubCreateTagForm: create-or-resolve logic.
 *
 * The form's submitForm() must:
 *   1. Create the term VERBATIM (trimmed only) in appverse_implementation_tags.
 *   2. Be idempotent: a second submit with the same tag must not create a
 *      duplicate term.
 *
 * The resync side-effect (batch_set / service call) is not kernel-testable, so
 * we assert only that the term exists after submit.  The resolver round-trip
 * (term created → next sync resolves it) is covered by RepoSyncService tests.
 *
 * @coversDefaultClass \Drupal\ood_software\Form\AppverseHubCreateTagForm
 * @group ood_software
 */
class CreateImplementationTagTest extends KernelTestBase {

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
    'key',
    'flag',
    'ood_software',
  ];

  /**
   * An appverse_app node used as the form's node argument.
   *
   * @var \Drupal\node\Entity\Node
   */
  protected Node $app;

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

    $this->installConfig([
      'system',
      'filter',
      'user',
      'node',
      'taxonomy',
      'content_moderation',
      'workflows',
    ]);

    $this->importProdConfig([
      'node.type.appverse_repo',
      'node.type.appverse_app',
      'node.type.appverse_software',
      'workflows.workflow.appverse_editorial',
      // Repo bundle fields.
      'field.storage.node.field_repo_url',
      'field.field.node.appverse_repo.field_repo_url',
      // body is a base-table field; attach it to appverse_app.
      'field.field.node.appverse_app.body',
      // appverse_app fields.
      'field.storage.node.field_appverse_repo',
      'field.field.node.appverse_app.field_appverse_repo',
      'field.storage.node.field_appverse_github_url',
      'field.field.node.appverse_app.field_appverse_github_url',
      'field.storage.node.field_appverse_app_subpath',
      'field.field.node.appverse_app.field_appverse_app_subpath',
      'field.storage.node.field_appverse_organization',
      'field.field.node.appverse_app.field_appverse_organization',
      'field.storage.node.field_appverse_maintainer_name',
      'field.field.node.appverse_app.field_appverse_maintainer_name',
      'field.storage.node.field_appverse_readme',
      'field.field.node.appverse_app.field_appverse_readme',
      'field.storage.node.field_appverse_app_type',
      'field.field.node.appverse_app.field_appverse_app_type',
      'field.storage.node.field_appverse_software_implemen',
      'field.field.node.appverse_app.field_appverse_software_implemen',
      'field.storage.node.field_appverse_app_validation_st',
      'field.field.node.appverse_app.field_appverse_app_validation_st',
      'field.storage.node.field_appverse_app_validation_er',
      'field.field.node.appverse_app.field_appverse_app_validation_er',
      'field.storage.node.field_add_implementation_tags',
      'field.field.node.appverse_app.field_add_implementation_tags',
      'taxonomy.vocabulary.appverse_organization',
    ]);

    // Import fields that live in the module's own config/install.
    $moduleSource = new FileStorage(
      DRUPAL_ROOT . '/modules/custom/ood_software/config/install'
    );
    $configManager = \Drupal::service('config.manager');
    $entityTypeManager = \Drupal::entityTypeManager();
    foreach ([
      'field.storage.node.field_repo_shape',
      'field.field.node.appverse_repo.field_repo_shape',
      'field.storage.node.field_appverse_unresolved_tags',
      'field.field.node.appverse_app.field_appverse_unresolved_tags',
    ] as $name) {
      $data = $moduleSource->read($name);
      if ($data === FALSE) {
        throw new \RuntimeException("Missing ood_software install config: $name");
      }
      $entityTypeId = $configManager->getEntityTypeIdByName($name);
      $storage = $entityTypeManager->getStorage($entityTypeId);
      $idKey = $storage->getEntityType()->getKey('id');
      $existingId = $data[$idKey] ?? NULL;
      if ($existingId && $storage->load($existingId)) {
        continue;
      }
      $storage->createFromStorageRecord($data)->save();
    }

    // Vocabularies needed by this test.
    Vocabulary::create([
      'vid' => 'appverse_implementation_tags',
      'name' => 'Implementation tags',
    ])->save();
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    Vocabulary::create(['vid' => 'appverse_app_type', 'name' => 'App type'])->save();

    // A repo node — the app node's parent reference.
    $repo = Node::create([
      'type' => 'appverse_repo',
      'title' => 'Test Repo',
      'field_repo_url' => ['uri' => 'https://github.com/x/y'],
      'field_repo_shape' => 'declared',
    ]);
    $repo->save();

    // An app node with one unresolved tag.
    $this->app = Node::create([
      'type' => 'appverse_app',
      'title' => 'Test App',
      'field_appverse_repo' => ['target_id' => $repo->id()],
      'field_appverse_unresolved_tags' => [['value' => 'mxnet']],
    ]);
    $this->app->save();
  }

  /**
   * Helper: invoke submitForm directly, bypassing the HTTP request layer.
   *
   * Stores $declared and $node in form_state, then calls submitForm.
   * The form's resync path may attempt a GitHub call, but without a real
   * `ood_software.gh` service the call will be skipped or throw — in the
   * kernel environment we only assert the term-creation side of submit.
   */
  protected function submitCreateTag(AppverseHubCreateTagForm $form, Node $node, string $declared): FormState {
    $form_state = new FormState();
    $form_state->set('node', $node);
    $form_state->set('declared', $declared);
    $form_array = [];
    $form->submitForm($form_array, $form_state);
    return $form_state;
  }

  /**
   * The submit redirect targets the my_appverse user display route.
   *
   * Regression guard for a bad redirect: the form once pointed at the
   * nonexistent 'view.my_appverse.page_1', which threw RouteNotFoundException
   * (white screen) when the form submitter resolved it during a real request.
   * The view's real page displays are page_admin + page_user. We assert the
   * stored route NAME (not its resolution — the views config isn't installed
   * in this kernel test), so a typo'd display name is caught here; that the
   * route resolves is guaranteed by the view config shipping page_user.
   *
   * @covers ::submitForm
   */
  public function testSubmitRedirectsToMyAppverseUserDisplay(): void {
    $form_state = $this->submitCreateTag(
      AppverseHubCreateTagForm::create($this->container),
      $this->app,
      'mxnet',
    );
    $redirect = $form_state->getRedirect();
    self::assertInstanceOf(\Drupal\Core\Url::class, $redirect, 'submitForm must set a redirect Url.');
    self::assertSame('view.my_appverse.page_user', $redirect->getRouteName());
  }

  /**
   * Term is created verbatim (exact casing/spelling) and idempotent on second submit.
   *
   * @covers ::submitForm
   */
  public function testCreatesTermVerbatimAndIdempotent(): void {
    $form = AppverseHubCreateTagForm::create($this->container);
    $this->submitCreateTag($form, $this->app, 'mxnet');

    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'appverse_implementation_tags', 'name' => 'mxnet']);
    self::assertCount(1, $terms, 'Expected exactly one "mxnet" term after first submit.');

    // Second submit — must be idempotent, no duplicate.
    $this->submitCreateTag(
      AppverseHubCreateTagForm::create($this->container),
      $this->app,
      'mxnet',
    );

    $terms2 = \Drupal::entityTypeManager()->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'appverse_implementation_tags', 'name' => 'mxnet']);
    self::assertCount(1, $terms2, 'Idempotent: no duplicate term on second submit.');
  }

  /**
   * Term label matches the declared string exactly (verbatim, not normalised).
   *
   * Ensures no lowercase/hyphenate/slug normalisation is applied.
   *
   * @covers ::submitForm
   */
  public function testTermLabelIsVerbatim(): void {
    $form = AppverseHubCreateTagForm::create($this->container);
    $this->submitCreateTag($form, $this->app, 'MXNet GPU');

    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'appverse_implementation_tags', 'name' => 'MXNet GPU']);
    self::assertCount(1, $terms, 'Term must be stored with exact original casing and spaces.');
    $term = reset($terms);
    self::assertSame('MXNet GPU', $term->label(), 'Term label must equal the declared string verbatim.');
  }

}
