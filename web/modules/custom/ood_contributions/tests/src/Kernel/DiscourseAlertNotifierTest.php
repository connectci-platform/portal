<?php

namespace Drupal\Tests\ood_contributions\Kernel;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\KernelTestBase;
use Drupal\ood_contributions\Plugin\CronManager;
use Drupal\ood_contributions\Service\DiscourseAlertNotifier;
use Drupal\symfony_mailer\EmailFactoryInterface;
use Drupal\symfony_mailer_test\MailerTestTrait;
use Drupal\Tests\ood_contributions\Kernel\Traits\DiscourseTestTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Psr\Log\AbstractLogger;

/**
 * Covers Discourse failure alerts and failure handling in the cron wrapper.
 *
 * @group ood_contributions
 */
class DiscourseAlertNotifierTest extends KernelTestBase {

  use DiscourseTestTrait;
  use MailerTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'filter', 'options', 'datetime',
    'link', 'taxonomy', 'path', 'path_alias', 'content_moderation', 'workflows',
    'key', 'flag', 'file', 'symfony_mailer', 'symfony_mailer_test',
    'ood_software', 'ood_contributions',
  ];

  /**
   * Error-level log messages captured from the ood_contributions channel.
   *
   * @var string[]
   */
  protected array $errors = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installSchema('ood_contributions', ['ood_disc_daily_posts']);
    $this->installConfig(['symfony_mailer']);
    $this->config('system.site')->set('mail', 'sender@example.com')->save();
    FilterFormat::create(['format' => 'basic_html', 'name' => 'Basic HTML'])->save();
    Role::create(['id' => 'site_developer', 'label' => 'Site developer'])->save();
    $this->setUpDiscourseClock();

    $errors = &$this->errors;
    $this->container->get('logger.factory')->addLogger(new class($errors) extends AbstractLogger {

      /**
       * Constructs the capturing logger.
       *
       * @param string[] $errors
       *   Receives error messages by reference.
       */
      public function __construct(protected array &$errors) {}

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        $is_error = is_int($level) ? $level <= 3 : in_array($level, ['error', 'critical', 'alert', 'emergency'], TRUE);
        if ($is_error && ($context['channel'] ?? '') === 'ood_contributions') {
          $this->errors[] = strtr((string) $message, array_filter($context, fn($v, $k) => $k[0] === '@' && is_scalar($v), ARRAY_FILTER_USE_BOTH));
        }
      }

    });
  }

  /**
   * Creates a user, optionally with the developer role.
   */
  protected function createUser(string $name, bool $developer, bool $active = TRUE): void {
    User::create([
      'name' => $name,
      'mail' => $name . '@example.com',
      'status' => $active ? 1 : 0,
      'roles' => $developer ? ['site_developer'] : [],
    ])->save();
  }

  /**
   * Builds a notifier with a controllable clock and environment.
   */
  protected function notifier(?string $env, ?EmailFactoryInterface $factory = NULL): DiscourseAlertNotifier {
    return new DiscourseAlertNotifier(
      $factory ?? $this->container->get('email_factory'),
      $this->container->get('entity_type.manager'),
      $this->container->get('logger.factory'),
      $this->container->get('state'),
      $this->fakeTime(),
      $env ?? 'dev',
    );
  }

  /**
   * Counts emails sent since the last call.
   */
  protected function sentCount(): int {
    $this->emails = NULL;
    $this->init();
    return count($this->emails);
  }

  /**
   * Live: one email per active developer, then throttled for 24 hours.
   */
  public function testLiveSendsToEachDeveloperThenThrottles(): void {
    $this->createUser('dev1', TRUE);
    $this->createUser('dev2', TRUE);
    $this->createUser('inactive', TRUE, FALSE);
    $this->createUser('other', FALSE);
    $notifier = $this->notifier('live');

    $notifier->notify('failure', 'Daily post stats could not be updated', 'Server error');
    $this->assertSame(2, $this->sentCount());
    $this->readMail(FALSE);
    $this->assertTo('dev1@example.com', 'dev1');
    $this->assertSubject('Discourse sync failure: Daily post stats could not be updated');
    $this->assertBodyContains('Server error');
    $this->readMail();
    $this->assertTo('dev2@example.com', 'dev2');
    $this->assertSame($this->now, $this->container->get('state')->get('ood_contributions.discourse_alerted.failure'));

    // A second failure within 24 hours sends nothing.
    $this->now += 3600;
    $notifier->notify('failure', 'again', 'Server error');
    $this->assertSame(0, $this->sentCount());

    // After 24 hours it sends again.
    $this->now += 86400;
    $notifier->notify('failure', 'again', 'Server error');
    $this->assertSame(2, $this->sentCount());
  }

  /**
   * Failure and stall alerts are throttled independently.
   */
  public function testTypesThrottleIndependently(): void {
    $this->createUser('dev1', TRUE);
    $notifier = $this->notifier('live');
    $state = $this->container->get('state');

    $notifier->notify('failure', 'f', 'm');
    $this->assertSame(1, $this->sentCount());
    $this->assertNull($state->get('ood_contributions.discourse_alerted.stall'));

    // A stall right after a failure still sends.
    $notifier->notify('stall', 's', 'm');
    $this->assertSame(1, $this->sentCount());
    $this->assertSame($this->now, $state->get('ood_contributions.discourse_alerted.stall'));

    // Each is throttled on its own.
    $notifier->notify('failure', 'f', 'm');
    $notifier->notify('stall', 's', 'm');
    $this->assertSame(0, $this->sentCount());
  }

  /**
   * Not live: nothing is emailed, but the error is logged.
   */
  public function testNonLiveLogsButDoesNotEmail(): void {
    $this->createUser('dev1', TRUE);
    $this->notifier('dev')->notify('failure', 'Stats down', 'Server error');
    $this->assertSame(0, $this->sentCount());
    $this->assertNotEmpty(array_filter($this->errors, fn($m) => str_contains($m, 'Stats down') && str_contains($m, 'Server error')));
  }

  /**
   * An unknown type is logged and never throws.
   */
  public function testUnknownTypeIsIgnored(): void {
    $this->createUser('dev1', TRUE);
    $this->notifier('live')->notify('bogus', 's', 'm');
    $this->assertSame(0, $this->sentCount());
    $this->assertNull($this->container->get('state')->get('ood_contributions.discourse_alerted.bogus'));
  }

  /**
   * Untrusted text is escaped in the HTML email body.
   */
  public function testMessageIsEscapedInEmail(): void {
    $this->createUser('dev1', TRUE);
    $this->notifier('live')->notify('failure', 'Subject', '<script>alert(1)</script>');
    $this->assertSame(1, $this->sentCount());
    $this->readMail(FALSE);
    $this->assertBodyContains('&lt;script&gt;');
  }

  /**
   * A mail transport failure is swallowed, and the throttle is still set.
   */
  public function testMailFailureIsSwallowed(): void {
    $this->createUser('dev1', TRUE);
    $factory = $this->createMock(EmailFactoryInterface::class);
    $factory->method('newTypedEmail')->willThrowException(new \RuntimeException('smtp down'));

    $this->notifier('live', $factory)->notify('failure', 's', 'm');
    $this->assertNotEmpty(array_filter($this->errors, fn($m) => str_contains($m, 'smtp down')));
    $this->assertSame($this->now, $this->container->get('state')->get('ood_contributions.discourse_alerted.failure'));
  }

  /**
   * Without symfony_mailer's factory nothing is emailed and nothing throws.
   */
  public function testMissingEmailFactoryIsTolerated(): void {
    $notifier = new DiscourseAlertNotifier(
      NULL,
      $this->container->get('entity_type.manager'),
      $this->container->get('logger.factory'),
      $this->container->get('state'),
      $this->fakeTime(),
      'live',
    );
    $notifier->notify('failure', 's', 'm');
    $this->assertSame($this->now, $this->container->get('state')->get('ood_contributions.discourse_alerted.failure'));
  }

  /**
   * A failure while recording or sending never escapes notify().
   */
  public function testNotifyNeverThrows(): void {
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->willThrowException(new \RuntimeException('boom'));
    $notifier = new DiscourseAlertNotifier(
      $this->container->get('email_factory'),
      $etm,
      $this->container->get('logger.factory'),
      $this->container->get('state'),
      $this->fakeTime(),
      'live',
    );
    $notifier->notify('failure', 's', 'm');
    $this->assertNotEmpty(array_filter($this->errors, fn($m) => str_contains($m, 'boom')));
  }

  /**
   * A failed fetch does not advance or overwrite stored rows.
   */
  public function testFailedFetchLeavesStoredRowsAlone(): void {
    $this->seedCount('2026-10-13', 5);
    $this->seedCount('2026-10-14', 6);
    $this->addPost('2026-10-14T15:00:00Z');
    $this->httpFails = TRUE;

    $result = $this->statsService()->updateDailyPostStats();

    $this->assertFalse($result['success']);
    $this->assertSame(['2026-10-13' => 5, '2026-10-14' => 6], $this->storedCounts());
  }

  /**
   * Cron: a failed run raises a failure alert, a good run re-arms it.
   */
  public function testCronWrapperAlertsOnFailureAndClearsOnSuccess(): void {
    $this->container->set('ood_contributions.discourse_stats', $this->statsService());
    $this->container->set('ood_contributions.discourse_alert', $this->notifier('dev'));
    \Drupal::setContainer($this->container);
    $state = $this->container->get('state');
    $this->seedCount('2026-10-14', 6);

    $this->httpFails = TRUE;
    CronManager::discourseUpdateDailyStats();
    $this->assertSame($this->now, $state->get('ood_contributions.discourse_alerted.failure'));
    $this->assertSame(['2026-10-14' => 6], $this->storedCounts());

    $this->httpFails = FALSE;
    $this->posts30Days = 0;
    CronManager::discourseUpdateDailyStats();
    $this->assertNull($state->get('ood_contributions.discourse_alerted.failure'));
    $this->assertArrayHasKey('2026-10-16', $this->storedCounts());
  }

  /**
   * Cron: a stall found after a successful run raises a stall alert.
   */
  public function testCronWrapperAlertsOnStall(): void {
    $this->container->set('ood_contributions.discourse_stats', $this->statsService());
    $this->container->set('ood_contributions.discourse_alert', $this->notifier('dev'));
    \Drupal::setContainer($this->container);
    $this->posts30Days = 82;

    CronManager::discourseUpdateDailyStats();

    $state = $this->container->get('state');
    $this->assertSame($this->now, $state->get('ood_contributions.discourse_alerted.stall'));
    $this->assertNull($state->get('ood_contributions.discourse_alerted.failure'));
  }

}
