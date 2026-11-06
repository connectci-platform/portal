<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;

/**
 * A review's report files are for reviewers only (appverse-planning#42).
 *
 * The contributor may read the review once its report lands, but core's file
 * access follows the referencing entity's view access and the field's view
 * access, so the report fields must be forbidden to anyone without
 * "administer appverse content", before and after a decision.
 *
 * @group ood_software
 */
class ReviewReportFileAccessTest extends KernelTestBase {

  use ProdConfigTrait;

  private const FIELDS = [
    'field_arv_report_md' => 'md',
    'field_arv_report_html' => 'html',
    'field_arv_report_pdf' => 'pdf',
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'filter', 'options',
    'datetime', 'link', 'taxonomy', 'path', 'path_alias',
    'content_moderation', 'workflows', 'key', 'flag', 'file', 'ood_software',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['system', 'filter', 'user', 'node']);
    $names = [
      'node.type.appverse_repo',
      'node.type.appverse_review',
      'field.storage.node.field_arv_repo',
      'field.field.node.appverse_review.field_arv_repo',
      'field.storage.node.field_arv_decision_sent_at',
      'field.field.node.appverse_review.field_arv_decision_sent_at',
      'field.storage.node.field_arv_reviewed_at',
      'field.field.node.appverse_review.field_arv_reviewed_at',
    ];
    foreach (array_keys(self::FIELDS) as $field) {
      $names[] = "field.storage.node.$field";
      $names[] = "field.field.node.appverse_review.$field";
    }
    $this->importProdConfig($names);

    foreach ([RoleInterface::ANONYMOUS_ID, RoleInterface::AUTHENTICATED_ID] as $rid) {
      Role::load($rid)?->grantPermission('access content')->save();
    }
    Role::create(['id' => 'appverse_pm', 'label' => 'Appverse PM'])
      ->grantPermission('administer appverse content')
      ->grantPermission('access content')
      ->save();
    User::create(['name' => 'admin'])->save();
  }

  /**
   * Only reviewers download report files, whether or not a decision is sent.
   */
  public function testReportFilesAreReviewerOnly(): void {
    $reviewer = $this->makeUser('reviewer', ['appverse_pm']);
    $owner = $this->makeUser('owner');
    $repo = Node::create([
      'type' => 'appverse_repo',
      'title' => 'example/repo',
      'uid' => $owner->id(),
      'status' => 1,
    ]);
    $repo->save();

    $values = [
      'type' => 'appverse_review',
      'title' => 'Review: example/repo',
      'uid' => $reviewer->id(),
      'status' => 0,
      'field_arv_repo' => $repo->id(),
      'field_arv_reviewed_at' => \Drupal::time()->getRequestTime(),
    ];
    $files = [];
    foreach (self::FIELDS as $field => $ext) {
      $file = File::create([
        'uri' => "private://appverse-review/report-test.$ext",
        'filename' => "report-test.$ext",
        'status' => 1,
        'uid' => $reviewer->id(),
      ]);
      $file->save();
      $files[$field] = $file;
      $values[$field] = $file->id();
    }
    $review = Node::create($values);
    $review->save();

    $this->assertTrue($review->access('view', $owner), 'The owner reads the review once its report landed.');
    $this->assertFilesDownload($files, $owner, FALSE, 'owner before a decision');
    $this->assertFilesDownload($files, $reviewer, TRUE, 'reviewer before a decision');

    $review->set('field_arv_decision_sent_at', \Drupal::time()->getRequestTime())->save();
    $this->assertFilesDownload($files, $owner, FALSE, 'owner after a decision');
    $this->assertFilesDownload($files, $reviewer, TRUE, 'reviewer after a decision');
  }

  /**
   * Asserts whether an account may download each report file.
   *
   * @param array<string, \Drupal\file\FileInterface> $files
   *   The files, keyed by field.
   * @param \Drupal\user\UserInterface $account
   *   The account asking.
   * @param bool $expected
   *   Whether the download is expected to be allowed.
   * @param string $who
   *   A label for the assertion message.
   */
  protected function assertFilesDownload(array $files, UserInterface $account, bool $expected, string $who): void {
    \Drupal::entityTypeManager()->getAccessControlHandler('file')->resetCache();
    \Drupal::entityTypeManager()->getAccessControlHandler('node')->resetCache();
    foreach ($files as $field => $file) {
      $this->assertSame($expected, $file->access('download', $account), sprintf('%s %s download %s.', $who, $expected ? 'can' : 'cannot', $field));
    }
  }

  /**
   * Creates a user with the given roles.
   *
   * @param string $name
   *   The user name.
   * @param array<int, string> $roles
   *   Role ids besides authenticated.
   */
  protected function makeUser(string $name, array $roles = []): UserInterface {
    $user = User::create(['name' => $name, 'status' => 1, 'roles' => $roles]);
    $user->save();
    return $user;
  }

}
