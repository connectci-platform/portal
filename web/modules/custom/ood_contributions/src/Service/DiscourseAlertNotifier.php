<?php

namespace Drupal\ood_contributions\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\symfony_mailer\EmailFactoryInterface;

/**
 * Alerts site developers when the Discourse sync fails or stalls.
 *
 * Every alert is written to the log as an error on every environment. On live
 * only, it is also emailed to active site developers. Each alert type is
 * throttled to once per 24 hours. Never throws: an alerting problem must not
 * replace the job's own failure.
 */
class DiscourseAlertNotifier {

  /**
   * Alert type: the sync could not fetch or store data.
   */
  public const TYPE_FAILURE = 'failure';

  /**
   * Alert type: the sync runs but its stored counts have stopped tracking.
   */
  public const TYPE_STALL = 'stall';

  /**
   * State key prefix; the alert type is appended.
   */
  public const STATE_PREFIX = 'ood_contributions.discourse_alerted.';

  /**
   * Seconds between alerts of the same type.
   */
  public const THROTTLE_SECONDS = 86400;

  /**
   * Role that receives the alert.
   */
  public const RECIPIENT_ROLE = 'site_developer';

  /**
   * The email factory, or NULL when symfony_mailer is not installed.
   *
   * @var \Drupal\symfony_mailer\EmailFactoryInterface|null
   */
  protected ?EmailFactoryInterface $emailFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected TimeInterface $time;

  /**
   * The Pantheon environment name, or NULL to read it from the environment.
   *
   * @var string|null
   */
  protected ?string $environment;

  /**
   * Constructs a DiscourseAlertNotifier.
   *
   * @param \Drupal\symfony_mailer\EmailFactoryInterface|null $email_factory
   *   The email factory. The service definition references it as optional so
   *   the container still compiles where symfony_mailer is not enabled; email
   *   is then skipped.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param string|null $environment
   *   Environment name override for tests. NULL reads PANTHEON_ENVIRONMENT.
   */
  public function __construct(
    ?EmailFactoryInterface $email_factory,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    StateInterface $state,
    TimeInterface $time,
    ?string $environment = NULL,
  ) {
    $this->emailFactory = $email_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('ood_contributions');
    $this->state = $state;
    $this->time = $time;
    $this->environment = $environment;
  }

  /**
   * Logs an alert and, on live, emails site developers, once per day per type.
   *
   * @param string $type
   *   The alert type: 'failure' or 'stall'.
   * @param string $subject
   *   A short summary, used in the email subject.
   * @param string $message
   *   The details.
   */
  public function notify(string $type, string $subject, string $message): void {
    try {
      if (!in_array($type, [self::TYPE_FAILURE, self::TYPE_STALL], TRUE)) {
        $this->logger->error('Unknown Discourse alert type @type: @subject', [
          '@type' => $type,
          '@subject' => $subject,
        ]);
        return;
      }

      // Always logged, even when throttled, so watchdog shows every run.
      $this->logger->error('Discourse sync @type: @subject. @message', [
        '@type' => $type,
        '@subject' => $subject,
        '@message' => $message,
      ]);

      $key = self::STATE_PREFIX . $type;
      $now = $this->time->getRequestTime();
      $last = (int) $this->state->get($key, 0);
      if ($last && ($now - $last) < self::THROTTLE_SECONDS) {
        return;
      }

      if ($this->getEnvironment() === 'live') {
        $this->sendEmails($type, $subject, $message);
      }

      // Recorded after the attempt, so a crash above retries on the next run
      // rather than being silenced by a throttle that never alerted anyone.
      $this->state->set($key, $now);
    }
    catch (\Throwable $notify_error) {
      $this->logger->error('Discourse alert failed: @message', [
        '@message' => $notify_error->getMessage(),
      ]);
    }
  }

  /**
   * Gets the Pantheon environment name.
   *
   * @return string|null
   *   The environment, or NULL when not running on Pantheon.
   */
  protected function getEnvironment(): ?string {
    if ($this->environment !== NULL) {
      return $this->environment;
    }
    $env = getenv('PANTHEON_ENVIRONMENT');
    return $env === FALSE ? NULL : $env;
  }

  /**
   * Emails every active site developer.
   *
   * @param string $type
   *   The alert type.
   * @param string $subject
   *   A short summary.
   * @param string $message
   *   The details.
   */
  protected function sendEmails(string $type, string $subject, string $message): void {
    if (!$this->emailFactory) {
      $this->logger->warning('Discourse alert not emailed: symfony_mailer is not available.');
      return;
    }

    $storage = $this->entityTypeManager->getStorage('user');
    $uids = $storage->getQuery()
      ->condition('status', 1)
      ->condition('roles', self::RECIPIENT_ROLE)
      ->accessCheck(FALSE)
      ->execute();
    $recipients = $uids ? $storage->loadMultiple($uids) : [];
    if (!$recipients) {
      $this->logger->warning('Discourse alert not emailed: no active users have the @role role.', [
        '@role' => self::RECIPIENT_ROLE,
      ]);
      return;
    }

    // The message can carry text from Discourse or Guzzle, so it is passed as
    // a plain variable and left to the mailer policy template to escape.
    $variables = [
      'type' => $type,
      'alert_subject' => $subject,
      'message' => $message,
    ];
    foreach ($recipients as $user) {
      if (!$user->getEmail()) {
        continue;
      }
      // One failing address must not stop the others.
      try {
        $email = $this->emailFactory->newTypedEmail('ood_contributions', 'discourse_sync_alert');
        foreach ($variables as $name => $value) {
          $email->setVariable($name, $value);
        }
        $email->setTo($user->getEmail());
        $email->send();
      }
      catch (\Throwable $mail_error) {
        $this->logger->error('Could not send Discourse alert to user @uid: @message', [
          '@uid' => $user->id(),
          '@message' => $mail_error->getMessage(),
        ]);
      }
    }
  }

}
