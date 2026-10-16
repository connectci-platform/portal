<?php

namespace Drupal\ood_contributions\Plugin\EmailBuilder;

use Drupal\symfony_mailer\EmailInterface;
use Drupal\symfony_mailer\Processor\EmailBuilderBase;

/**
 * Email Builder plug-in for the ood_contributions module.
 *
 * Subject and body come from the mailer policy
 * (symfony_mailer.mailer_policy.ood_contributions.discourse_sync_alert).
 *
 * @EmailBuilder(
 *   id = "ood_contributions",
 *   sub_types = {
 *     "discourse_sync_alert" = @Translation("Discourse sync alert"),
 *   },
 *   common_adjusters = {"email_subject", "email_body"},
 * )
 */
class OodContributionsEmailBuilder extends EmailBuilderBase {

  /**
   * {@inheritdoc}
   */
  public function build(EmailInterface $email): void {
    // The sender defaults to the site email address.
  }

}
