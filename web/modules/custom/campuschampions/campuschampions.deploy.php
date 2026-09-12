<?php

/**
 * @file
 * Deploy hooks for the Campus Champions module.
 *
 * Deploy hooks run via `drush deploy` after database updates and config
 * import, i.e. with the new code active.
 */

use Drupal\webform\Entity\WebformSubmission;

/**
 * Settles two applications whose status changes the approval bug swallowed.
 *
 * The approve/decline VBO actions silently failed on any submission created
 * before the org-first form migration (the status flip went through full form
 * re-validation, which legacy data cannot pass, and the error was discarded).
 * A reviewer's approve of one pending application and decline of another both
 * reported success without persisting. The account-side effects of the
 * approval (role, field_is_cc, Carnegie code) were already applied manually
 * on 2026-08-13, so only the submission statuses are outstanding — set them
 * directly, with no emails re-sent.
 *
 * Guarded three ways so the hook is a no-op everywhere but the production
 * database: the sid must exist on this webform, must still be in the 'new'
 * status, and the applicant email on the record must match the expected
 * value, compared by hash so no address is embedded in code.
 */
function campuschampions_deploy_settle_stuck_applications(): string {
  $changes = [
    6674 => ['approved', 'c101f51eee5b62639f40cf95fbea6bde487db587b5623b2404898abb7a4a785c'],
    6412 => ['declined', '29b9bde63ad9f8d800f7abfb3179df022a6d2a9bd4f570518acea4bc11dab871'],
  ];
  $results = [];

  foreach ($changes as $sid => [$new_status, $email_hash]) {
    $submission = WebformSubmission::load($sid);
    if (!$submission
      || $submission->getWebform()->id() !== 'join_campus_champions'
      || hash('sha256', (string) $submission->getElementData('user_email')) !== $email_hash
      || $submission->getElementData('status') !== 'new') {
      $results[] = "sid $sid: skipped (not the expected pending application)";
      continue;
    }
    $submission->setElementData('status', $new_status);
    $submission->save();
    $results[] = "sid $sid: $new_status";
  }

  return 'Stuck applications: ' . implode('; ', $results);
}
