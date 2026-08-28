<?php

declare(strict_types=1);

namespace Drupal\Tests\campuschampions\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Entity\WebformSubmission;

/**
 * The approve/decline VBO actions must persist status on LEGACY submissions.
 *
 * The join form gained a required field_access_organization element (the
 * org-first migration) AFTER most applications were submitted, so nearly the
 * whole corpus no longer passes the current form's validation. The actions
 * used to persist the status flip through WebformSubmissionForm::
 * submitWebformSubmission() — a fully validated form submission whose error
 * return was ignored — so on any legacy submission the save silently aborted
 * while VBO reported success. A status flip on an already-accepted
 * application must not re-validate the historical data against today's form.
 *
 * @group campuschampions
 */
class CcActionStatusTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'path',
    'path_alias',
    'webform',
    'views',
    'views_bulk_operations',
    'taxonomy',
    'text',
    'campuschampions',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('webform_submission');
    $this->installSchema('webform', ['webform']);
    $this->installConfig(['webform']);

    // campuschampions form/view hooks consult the multisite domain helper,
    // which lives in a module outside this repo's kernel environment. Stub it.
    $sitetools = new class {

      public function getDomainId(): string {
        return 'campuschampions_cyberinfrastructure_org';
      }

    };
    \Drupal::getContainer()->set('access_misc.sitetools', $sitetools);

    // Mirror the site form's shape: the org element is REQUIRED, as it is in
    // production since the org-first migration.
    Webform::create([
      'id' => 'join_campus_champions',
      'title' => 'Join Campus Champions',
      'elements' => <<<YAML
status:
  '#type': textfield
  '#title': Status
user_email:
  '#type': textfield
  '#title': Email
username:
  '#type': textfield
  '#title': Username
champion_user_type:
  '#type': textfield
  '#title': Type
field_access_organization:
  '#type': textfield
  '#title': Organization
  '#required': true
YAML,
    ])->save();
  }

  /**
   * Creates a LEGACY-shaped submission: no organization element value.
   *
   * @param array<string, mixed> $data
   *   The submission element data.
   */
  private function createLegacySubmission(array $data): WebformSubmission {
    $submission = WebformSubmission::create([
      'webform_id' => 'join_campus_champions',
      'data' => $data + ['status' => 'new'],
    ]);
    $submission->save();
    return $submission;
  }

  /**
   * Reloads a submission bypassing static caches.
   */
  private function reload(WebformSubmission $submission): WebformSubmission {
    return \Drupal::entityTypeManager()->getStorage('webform_submission')
      ->loadUnchanged($submission->id());
  }

  /**
   * Declining a legacy submission persists the status.
   */
  public function testDeclinePersistsOnLegacySubmission(): void {
    $submission = $this->createLegacySubmission([
      'user_email' => 'legacy@example.com',
    ]);

    $action = \Drupal::service('plugin.manager.action')
      ->createInstance('campuschampions_approve_cc_decline');
    $action->execute($submission);

    $this->assertSame('declined', $this->reload($submission)->getElementData('status'));
  }

  /**
   * Superseding a legacy submission persists the status.
   *
   * Supersede targets older duplicate APPROVED applications — the population
   * most certain to be legacy-shaped — and shared the same silent-failure
   * pattern as approve/decline.
   */
  public function testSupersedePersistsOnLegacySubmission(): void {
    $submission = $this->createLegacySubmission([
      'user_email' => 'legacy@example.com',
    ]);

    $action = \Drupal::service('plugin.manager.action')
      ->createInstance('campuschampions_supersede');
    $action->execute($submission);

    $this->assertSame('superseded', $this->reload($submission)->getElementData('status'));
  }

  /**
   * Approving a legacy submission persists the status and updates the user.
   */
  public function testApprovePersistsOnLegacySubmission(): void {
    foreach (['student_champion', 'research_computing_facilitator'] as $rid) {
      Role::create(['id' => $rid, 'label' => $rid])->save();
    }
    // The user fields the approve action writes unconditionally.
    $fields = [
      ['field_is_cc', 'integer'],
      ['field_region', 'entity_reference', ['target_type' => 'taxonomy_term']],
    ];
    foreach ($fields as $def) {
      FieldStorageConfig::create([
        'field_name' => $def[0],
        'entity_type' => 'user',
        'type' => $def[1],
        'cardinality' => $def[0] === 'field_region' ? -1 : 1,
        'settings' => $def[2] ?? [],
      ])->save();
      FieldConfig::create([
        'field_name' => $def[0],
        'entity_type' => 'user',
        'bundle' => 'user',
      ])->save();
    }

    $user = User::create([
      'name' => 'legacy_champion',
      'mail' => 'legacy@example.com',
      'status' => 1,
    ]);
    $user->save();

    $submission = $this->createLegacySubmission([
      'user_email' => 'legacy@example.com',
      'username' => 'legacy_champion',
      'champion_user_type' => 'user_champion',
    ]);

    $action = \Drupal::service('plugin.manager.action')
      ->createInstance('campuschampions_approve_cc_action');
    $action->execute($submission);

    $this->assertSame('approved', $this->reload($submission)->getElementData('status'));
    $reloadedUser = User::load($user->id());
    $this->assertTrue($reloadedUser->hasRole('research_computing_facilitator'));
    $this->assertSame(1, (int) $reloadedUser->get('field_is_cc')->value);
  }

}
