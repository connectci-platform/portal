<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * The public review summary carries the block ids the catalog chips link to.
 *
 * The reviewer's page already gives each category block the id
 * block-<app id>-<category>, and the repository's blocks block-<category>;
 * the summary visitors see uses the same ids, so one link from the catalog
 * works whichever page the visitor gets (D8-2928).
 *
 * @group ood_software
 */
class ReviewSummaryAnchorsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'filter', 'options',
    'datetime', 'link', 'taxonomy', 'path', 'path_alias',
    'content_moderation', 'workflows', 'key', 'flag', 'views', 'file', 'ood_software',
  ];

  /**
   * A category block as ReviewPageData builds it, with no findings.
   *
   * @return array<string, mixed>
   */
  private function block(string $key): array {
    return ['key' => $key, 'title' => ucfirst($key), 'level' => 'solid', 'summary' => "$key fine", 'groups' => []];
  }

  /**
   * Renders the summary for one app plus the repository section.
   *
   * @param array<int, array<string, mixed>> $apps
   * @param array<string, array<string, mixed>> $repoBlocks
   */
  private function renderSummary(array $apps, array $repoBlocks = []): string {
    $element = [
      '#theme' => 'appverse_review_summary',
      '#page' => [
        'apps' => $apps,
        'repo_blocks' => $repoBlocks,
        'maintenance' => $this->block('maintenance'),
        'level_labels' => [],
        'conclusion_labels' => [],
      ],
    ];
    return (string) \Drupal::service('renderer')->renderInIsolation($element);
  }

  /**
   * @return array<string, mixed>
   */
  private function app(string $appId): array {
    return [
      'app_id' => $appId,
      'name' => "App $appId",
      'conclusion' => NULL,
      'blocks' => [
        'security' => $this->block('security'),
        'portability' => $this->block('portability'),
        'documentation' => $this->block('documentation'),
      ],
    ];
  }

  public function testSingleAppRowsCarryRootScopedIds(): void {
    $html = $this->renderSummary([$this->app('root')]);
    foreach (['security', 'portability', 'documentation'] as $key) {
      $this->assertStringContainsString("id=\"block-root-$key\"", $html);
    }
    $this->assertStringContainsString('id="block-maintenance"', $html);
  }

  public function testMonorepoRowsAreScopedPerApp(): void {
    $repoSecurity = $this->block('security');
    $repoSecurity['groups'] = [['severity' => 'high', 'count' => 1, 'findings' => [['rule' => 'SEC-01', 'summary' => 'x', 'source' => 'tool', 'override' => NULL, 'evidence' => '']]]];
    $html = $this->renderSummary([$this->app('apps/notebook'), $this->app('apps/desktop')], ['security' => $repoSecurity]);
    $this->assertStringContainsString('id="block-apps/notebook-portability"', $html);
    $this->assertStringContainsString('id="block-apps/desktop-portability"', $html);
    // Repository-level blocks are unscoped, as on the reviewer's page.
    $this->assertStringContainsString('id="block-security"', $html);
  }

}
