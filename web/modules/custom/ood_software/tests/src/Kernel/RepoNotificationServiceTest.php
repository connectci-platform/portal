<?php

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\Core\Test\AssertMailTrait;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;

/**
 * @covers \Drupal\ood_software\Service\RepoNotificationService
 * @group ood_software
 */
class RepoNotificationServiceTest extends KernelTestBase {

  use AssertMailTrait {
    getMails as drupalGetMails;
  }

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
    'path',
    'path_alias',
    'content_moderation',
    'workflows',
    'content_moderation_notifications',
    // ood_software.gh depends on the `key` module's key.repository service.
    'key',
    // ood_software_node_insert() on appverse_app nodes calls the `flag` service.
    'flag',
    'ood_software',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('content_moderation_state');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);

    // Install core base configs first.
    $this->installConfig([
      'system',
      'filter',
      'user',
      'node',
      'content_moderation',
      'workflows',
    ]);

    // The Repo bundle, its fields, and the appverse_editorial
    // workflow live in sites/default/config/default — not the module's
    // config/install. Import the bundles + field storages BEFORE
    // installing ood_software's own config (which has a field.field
    // attached to the appverse_repo bundle).
    $this->importProdConfig([
      'node.type.appverse_repo',
      // appverse_app bundle is needed for
      // testAppBundleTransitionDoesNotNotify; without it
      // Node::create(['type' => 'appverse_app', ...]) fails.
      'node.type.appverse_app',
      'workflows.workflow.appverse_editorial',
      // Field storage + field configs that the notification code reads.
      'field.storage.node.field_repo_url',
      'field.field.node.appverse_repo.field_repo_url',
      // appverse_app field configs touched by the app-bundle test.
      'field.storage.node.field_appverse_repo',
      'field.field.node.appverse_app.field_appverse_repo',
      // Bring in the contrib notification config so the 3B suppression
      // test can verify hook_content_moderation_notification_mail_data_alter
      // actually blocks the contrib email. Without this, the alter has
      // nothing to suppress and the test gives a false positive.
      'content_moderation_notifications.content_moderation_notification.appverse_review_requested',
    ]);

    // ood_software's own field_repo_shape lives in module config/install
    // — import via entity API so the field-storage table is created.
    // We skip installConfig(['ood_software']) because the module's install dir
    // also contains ultimate_cron.job.ood_software which has no schema in the
    // kernel-test environment.
    $moduleSource = new FileStorage(
      DRUPAL_ROOT . '/modules/custom/ood_software/config/install'
    );
    $configManager = \Drupal::service('config.manager');
    $entityTypeManager = \Drupal::entityTypeManager();
    foreach ([
      'field.storage.node.field_repo_shape',
      'field.field.node.appverse_repo.field_repo_shape',
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
      $entity = $storage->createFromStorageRecord($data);
      $entity->save();
    }
  }

  /**
   * Import config objects from the prod default snapshot.
   *
   * Uses the entity-storage API for config-entity types so the
   * corresponding DB schema (field storage tables, etc.) is created.
   * Falls back to raw config writes for simple config (non-entity).
   *
   * @param string[] $names
   *   Config object names (without .yml).
   */
  protected function importProdConfig(array $names): void {
    $source = new FileStorage(
      DRUPAL_ROOT . '/sites/default/config/default'
    );
    $target = \Drupal::service('config.storage');
    $configManager = \Drupal::service('config.manager');
    $entityTypeManager = \Drupal::entityTypeManager();
    foreach ($names as $name) {
      $data = $source->read($name);
      if ($data === FALSE) {
        throw new \RuntimeException("Missing prod config: $name");
      }
      // Strip third_party_settings from any module we haven't enabled in this
      // kernel test (e.g. menu_ui on node.type configs). The ConfigSchemaChecker
      // requires schema for every key, and missing modules don't ship theirs.
      if (isset($data['third_party_settings']) && is_array($data['third_party_settings'])) {
        foreach (array_keys($data['third_party_settings']) as $module) {
          if (!\Drupal::moduleHandler()->moduleExists($module)) {
            unset($data['third_party_settings'][$module]);
          }
        }
      }
      // Same scrub for module dependencies — drop unmet ones so config
      // import doesn't fail dependency validation.
      if (isset($data['dependencies']['module']) && is_array($data['dependencies']['module'])) {
        $data['dependencies']['module'] = array_values(array_filter(
          $data['dependencies']['module'],
          fn($m) => \Drupal::moduleHandler()->moduleExists($m)
        ));
      }
      $entityTypeId = $configManager->getEntityTypeIdByName($name);
      if ($entityTypeId) {
        $storage = $entityTypeManager->getStorage($entityTypeId);
        // If already exists (e.g. created by installConfig), skip.
        $idKey = $storage->getEntityType()->getKey('id');
        $existingId = $data[$idKey] ?? NULL;
        if ($existingId && $storage->load($existingId)) {
          continue;
        }
        $entity = $storage->createFromStorageRecord($data);
        $entity->save();
      }
      else {
        $target->write($name, $data);
      }
    }
  }

  /**
   * Create a role with 'administer appverse content' permission.
   */
  protected function createAdminRole(): string {
    $rid = strtolower($this->randomMachineName(8));
    \Drupal::entityTypeManager()->getStorage('user_role')->create([
      'id' => $rid,
      'label' => 'Admin',
      'permissions' => ['administer appverse content'],
    ])->save();
    return $rid;
  }

  /**
   * Create an admin user with 'administer appverse content' permission.
   */
  protected function createAdminUser(string $mail = 'admin@example.com'): User {
    $admin = User::create([
      'name' => $this->randomMachineName(),
      'mail' => $mail,
      'status' => 1,
    ]);
    $admin->addRole($this->createAdminRole());
    $admin->save();
    return $admin;
  }

  /**
   * Create a contributor user.
   */
  protected function createContributor(string $mail = 'contributor@example.com'): User {
    $user = User::create([
      'name' => $this->randomMachineName(),
      'mail' => $mail,
      'status' => 1,
    ]);
    $user->save();
    return $user;
  }

  /**
   * Create a Repo node in the given moderation state.
   */
  protected function createRepo(int $ownerId, string $state = 'draft', string $title = 'Test Repo'): Node {
    $node = Node::create([
      'type' => 'appverse_repo',
      'title' => $title,
      'uid' => $ownerId,
      'moderation_state' => $state,
      'field_repo_url' => ['uri' => 'https://github.com/example/test'],
    ]);
    $node->save();
    return $node;
  }

  /**
   * Flatten a captured mail body (array of strings) for substring checks.
   */
  protected function mailBody(array $mail): string {
    if (is_array($mail['body'])) {
      return implode("\n", array_map('strval', $mail['body']));
    }
    return (string) $mail['body'];
  }

  /**
   * Assert that draft → ready_for_review emails admins.
   */
  public function testReadyForReviewNotifiesAdmins(): void {
    $admin = $this->createAdminUser('admin@example.com');
    $contributor = $this->createContributor();
    $node = $this->createRepo((int) $contributor->id(), 'draft');

    $node->set('moderation_state', 'ready_for_review');
    $node->setNewRevision(TRUE);
    $node->save();

    $mails = $this->drupalGetMails();
    // Filter for our key (ignore contrib mails which should be suppressed).
    $oodMails = array_values(array_filter($mails, fn($m) => $m['module'] === 'ood_software'));
    $this->assertCount(1, $oodMails, 'Exactly one ood_software mail fires on ready_for_review.');
    $this->assertSame('admin@example.com', $oodMails[0]['to']);
    $this->assertStringContainsString('submitted for review', $oodMails[0]['subject']);
  }

  /**
   * The _ood_software_suppress_notifications runtime flag silences the
   * publish notification (used by the inferred-repo backfill, which publishes
   * many repos as a data migration and must not email each owner).
   */
  public function testSuppressFlagSilencesPublishNotification(): void {
    $contributor = $this->createContributor();
    $node = $this->createRepo((int) $contributor->id(), 'draft');

    // Same transition that normally emails the owner — but flagged.
    $node->_ood_software_suppress_notifications = TRUE;
    $node->set('moderation_state', 'published');
    $node->setNewRevision(TRUE);
    $node->save();

    $mails = $this->drupalGetMails();
    $oodMails = array_values(array_filter($mails, fn($m) => $m['module'] === 'ood_software'));
    $this->assertCount(0, $oodMails, 'Suppress flag must prevent any ood_software notification on publish.');
  }

  /**
   * Assert that the contrib `appverse_review_requested` email is suppressed
   * for Repo bundles via hook_content_moderation_notification_mail_data_alter.
   */
  public function testRepoSendForReviewDoesNotTriggerContribMail(): void {
    $this->createAdminUser('admin@example.com');
    $contributor = $this->createContributor();
    $node = $this->createRepo((int) $contributor->id(), 'draft');

    $node->set('moderation_state', 'ready_for_review');
    $node->setNewRevision(TRUE);
    $node->save();

    $mails = $this->drupalGetMails();
    foreach ($mails as $mail) {
      $this->assertStringNotContainsString(
        'New Appverse App submitted',
        (string) $mail['subject'],
        'Contrib App-flavored email must not fire for Repo bundle.'
      );
    }
  }

  /**
   * Needs_adjustment with comment via runtime property.
   */
  public function testNeedsAdjustmentEmailsOwnerWithCommentFromRuntimeProperty(): void {
    $this->createAdminUser('admin@example.com');
    $contributor = $this->createContributor('owner@example.com');
    $node = $this->createRepo((int) $contributor->id(), 'ready_for_review');

    $node->set('moderation_state', 'needs_adjustment');
    $node->setNewRevision(TRUE);
    $node->_ood_software_review_comment = 'fix X';
    $node->save();

    $mails = $this->drupalGetMails();
    $oodMails = array_values(array_filter($mails, fn($m) => $m['module'] === 'ood_software' && $m['key'] === 'needs_adjustment'));
    $this->assertCount(1, $oodMails);
    $this->assertSame('owner@example.com', $oodMails[0]['to']);
    $body = $this->mailBody($oodMails[0]);
    $this->assertStringContainsString('fix X', $body);
    $this->assertStringContainsString("Reviewer's note", $body);
  }

  /**
   * Needs_adjustment falls back to revision log when no runtime property.
   */
  public function testNeedsAdjustmentFallsBackToRevisionLogWhenNoRuntimeProperty(): void {
    $this->createAdminUser();
    $contributor = $this->createContributor('owner@example.com');
    $node = $this->createRepo((int) $contributor->id(), 'ready_for_review');

    $node->set('moderation_state', 'needs_adjustment');
    $node->setRevisionLogMessage('fix Y');
    $node->setNewRevision(TRUE);
    $node->save();

    $mails = $this->drupalGetMails();
    $oodMails = array_values(array_filter($mails, fn($m) => $m['module'] === 'ood_software' && $m['key'] === 'needs_adjustment'));
    $this->assertCount(1, $oodMails);
    $body = $this->mailBody($oodMails[0]);
    $this->assertStringContainsString('fix Y', $body);
    $this->assertStringContainsString("Reviewer's note", $body);
  }

  /**
   * Needs_adjustment with no comment omits the note block.
   */
  public function testNeedsAdjustmentWithNoCommentOmitsNoteBlock(): void {
    $this->createAdminUser();
    $contributor = $this->createContributor('owner@example.com');
    $node = $this->createRepo((int) $contributor->id(), 'ready_for_review');

    $node->set('moderation_state', 'needs_adjustment');
    $node->setNewRevision(TRUE);
    $node->save();

    $mails = $this->drupalGetMails();
    $oodMails = array_values(array_filter($mails, fn($m) => $m['module'] === 'ood_software' && $m['key'] === 'needs_adjustment'));
    $this->assertCount(1, $oodMails);
    $body = $this->mailBody($oodMails[0]);
    $this->assertStringNotContainsString("Reviewer's note", $body);
  }

  /**
   * Publish notifies owner with catalog URL.
   */
  public function testPublishedNotifiesOwner(): void {
    $this->createAdminUser();
    $contributor = $this->createContributor('owner@example.com');
    $node = $this->createRepo((int) $contributor->id(), 'ready_for_review');

    $node->set('moderation_state', 'published');
    $node->setNewRevision(TRUE);
    $node->save();

    $mails = $this->drupalGetMails();
    $oodMails = array_values(array_filter($mails, fn($m) => $m['module'] === 'ood_software' && $m['key'] === 'published'));
    $this->assertCount(1, $oodMails);
    $this->assertSame('owner@example.com', $oodMails[0]['to']);
    $this->assertStringContainsString('Your Repo is published', $oodMails[0]['subject']);
    $body = $this->mailBody($oodMails[0]);
    $this->assertStringContainsString('/appverse/#/repo/', $body);
  }

  /**
   * Archived → published uses the published template.
   */
  public function testArchivedToPublishedUsesPublishedTemplate(): void {
    $this->createAdminUser();
    $contributor = $this->createContributor('owner@example.com');
    $node = $this->createRepo((int) $contributor->id(), 'published');

    // Move to archived first.
    $node->set('moderation_state', 'archived');
    $node->setNewRevision(TRUE);
    $node->save();

    // Reset captured mails before the transition under test.
    \Drupal::state()->set('system.test_mail_collector', []);

    $node->set('moderation_state', 'published');
    $node->setNewRevision(TRUE);
    $node->save();

    $mails = $this->drupalGetMails();
    $oodMails = array_values(array_filter($mails, fn($m) => $m['module'] === 'ood_software' && $m['key'] === 'published'));
    $this->assertCount(1, $oodMails);
    $this->assertStringContainsString('Your Repo is published', $oodMails[0]['subject']);
  }

  /**
   * App bundle transitions do not trigger Repo notifier.
   */
  public function testAppBundleTransitionDoesNotNotify(): void {
    $this->createAdminUser();
    $contributor = $this->createContributor();

    $app = Node::create([
      'type' => 'appverse_app',
      'title' => 'Test App',
      'uid' => $contributor->id(),
      'moderation_state' => 'draft',
    ]);
    $app->save();

    $app->set('moderation_state', 'ready_for_review');
    $app->setNewRevision(TRUE);
    $app->save();

    $mails = $this->drupalGetMails();
    // No ood_software mails should fire for app-bundle transitions.
    $oodMails = array_values(array_filter($mails, fn($m) => $m['module'] === 'ood_software'));
    $this->assertCount(0, $oodMails, 'ood_software mails must not fire for appverse_app transitions.');
  }

  /**
   * Same-state save does not trigger notifier.
   */
  public function testSameStateSaveDoesNotNotify(): void {
    $this->createAdminUser();
    $contributor = $this->createContributor();
    $node = $this->createRepo((int) $contributor->id(), 'draft');

    // Save without changing moderation_state.
    $node->setTitle('Updated title');
    $node->save();

    $mails = $this->drupalGetMails();
    $oodMails = array_values(array_filter($mails, fn($m) => $m['module'] === 'ood_software'));
    $this->assertCount(0, $oodMails, 'No mail should fire when moderation_state is unchanged.');
  }

  /**
   * Resubmit (needs_adjustment → ready_for_review) fires another admin email.
   */
  public function testResubmitFiresAnotherAdminEmail(): void {
    $this->createAdminUser('admin@example.com');
    $contributor = $this->createContributor();
    $node = $this->createRepo((int) $contributor->id(), 'needs_adjustment');

    $node->set('moderation_state', 'ready_for_review');
    $node->setNewRevision(TRUE);
    $node->save();

    $mails = $this->drupalGetMails();
    $oodMails = array_values(array_filter($mails, fn($m) => $m['module'] === 'ood_software' && $m['key'] === 'ready_for_review'));
    $this->assertCount(1, $oodMails);
    $this->assertSame('admin@example.com', $oodMails[0]['to']);
    $this->assertStringContainsString('submitted for review', $oodMails[0]['subject']);
  }

}
