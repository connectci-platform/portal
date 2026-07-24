<?php

namespace Drupal\campuschampions\Plugin\Action;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\webform\WebformSubmissionForm;
use Drupal\webform\Entity\WebformSubmission;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Approve Campus Champion action.
 *
 * @Action(
 *   id = "campuschampions_approve_cc_action",
 *   label = @Translation("Approve Campus Champion"),
 *   type = ""
 * )
 */
class ApproveCCAction extends ViewsBulkOperationsActionBase implements ContainerFactoryPluginInterface {

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs an ApproveCCAction object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The mail manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    LoggerChannelFactoryInterface $logger_factory,
    MailManagerInterface $mail_manager,
    MessengerInterface $messenger
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->loggerFactory = $logger_factory;
    $this->mailManager = $mail_manager;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory'),
      $container->get('plugin.manager.mail'),
      $container->get('messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function execute(?WebformSubmission $entity = NULL) {
    // Update the status of the submission to 'approved'.
    $sid = $entity->id();
    $webform_submission = WebformSubmission::load($sid);
    if (!$webform_submission) {
      $this->loggerFactory->get('campuschampions')->error('Could not load webform submission @sid for approval.', ['@sid' => $sid]);
      return $this->t('Error: Could not load submission.');
    }

    $submission_data = $webform_submission->getData();
    $champion_user_type = $submission_data['champion_user_type'] ?? NULL;
    $webform_submission->setElementData('status', 'approved');
    WebformSubmissionForm::submitWebformSubmission($webform_submission);

    // Update user to campus champion.
    $data = $entity->getData();
    $user_email = $data['user_email'] ?? NULL;
    if (!$user_email) {
      $this->loggerFactory->get('campuschampions')->error('No email found in submission @sid.', ['@sid' => $sid]);
      return $this->t('Error: No email in submission.');
    }

    $user = user_load_by_mail($user_email);
    if (!$user) {
      // The applicant may already have an account under a different email
      // whose username matches the one they entered (e.g. an ACCESS-CI ID).
      // Fall back to a username lookup so we update that existing account
      // rather than failing the approval.
      $username = $data['username'] ?? NULL;
      if ($username) {
        $user = user_load_by_name($username);
        if ($user) {
          // Audit trail: the approval is adopting an account whose email
          // differs from the application's. The welcome email goes to the
          // account's registered address, not the one on the application.
          $this->loggerFactory->get('campuschampions')->notice(
            'Approval for @app_email adopted existing account @name (uid @uid, account email @acct_email).',
            [
              '@app_email' => $user_email,
              '@name' => $username,
              '@uid' => $user->id(),
              '@acct_email' => $user->getEmail(),
            ]
          );
        }
      }
    }
    if (!$user) {
      $this->loggerFactory->get('campuschampions')->error('Could not find user with email @email for approval.', ['@email' => $user_email]);
      return $this->t('Error: User not found for email @email.', ['@email' => $user_email]);
    }

    // Set user role.
    if ($champion_user_type == 'user_student') {
      $user->addRole('student_champion');
    }
    if ($champion_user_type == 'user_champion') {
      $user->addRole('research_computing_facilitator');
    }

    $user->set('field_carnegie_code', $data['carnegie_classification']);
    $user->set('field_is_cc', 1);

    // Campus Champions Program ID.
    $cc_id = 572;

    $region = $user->get('field_region')->referencedEntities();

    if (count($region) == 0) {
      $user->set('field_region', $cc_id);
    }
    else {
      // Add Campus Champions program if it's not already set.
      if (!array_filter(
            $region,
            function ($program) use ($cc_id) {
              return $program->id() == $cc_id;
            }
        )) {
        $user->get('field_region')->appendItem(
              [
                'target_id' => $cc_id,
              ]
          );
      }
    }

    $user->save();

    $this->emailAccountNotification($user);

    return $this->t('You are a Campus Champion!');
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    // @see Drupal\Core\Field\FieldUpdateActionBase::access().
    return $object->access('update', $account, $return_as_object);
  }

  /**
   * Email notification of new account to user.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user entity.
   */
  protected function emailAccountNotification($user) {
    $module = 'campuschampions';
    $key = 'approve_campuschampion';
    $to = $user->getEmail();
    $name = $user->getDisplayName();
    $url = user_pass_reset_url($user);
    $params['message'] = '
      <html>
      <head>
      <title>Welcome to the Campus Champions Portal</title>
      </head>
      <body>
      <p>Dear ' . $name . ',</p>
      <p></p>
      <p>The Campus Champions program is introducing <em>Affinity Groups</em> as a lightweight way for Champions with common interests to gather, share experience, and aggregate knowledge and resources. Your account has been created on the  <a href=https://campuschampions.cyberinfrastructure.org>Champions Portal</a> which will be used to manage Affinity Groups. Please take a few minutes to access your account, set up your profile, and choose your affinity groups.</p>
      <p></p>
      <p><strong>ACCESS YOUR ACCOUNT</strong></p>
      <p></p>
      <p>Click on this link or copy and paste it to your browser:</p>
      <p>' . $url . '</p>
      <p>This link can only be used once to log in and will lead you to a page where you can set your password. It expires after seven days and nothing will happen if it&apos;s not used. </p>
      <p></p>
      <p><strong>SET UP YOUR PORTAL PROFILE</strong></p>
      <ol>
      <li>Click <strong>My Profile>Edit my account</strong> in the <strong>My Profile</strong> pulldown in the top right corner and make the following choices:
      <ol>
      <li>Select the program(s) in which you are a participant. The Campus Champions program has been pre-selected. To select the additional programs, command-click (on a MAC) or right-click (on a Windows-based machine).</li>
      <li>Select the role (or roles) that are applicable.</li>
      <li>Upload a picture so that we can recognize each other more readily!</li></ol>
      <li>Indicate your skills and interests by clicking <strong>My profile>add/edit interests</strong> or <strong>My profile>add/edit skills</strong> from the pulldown in the top right corner. "Skills" are topics on which you are willing to answer a question; "Interests" are topics about which you would like to learn more.</li>
      </ol>
      <p></p>
      <p><strong>CHOOSE YOUR AFFINITY GROUPS</strong></p>
      <p>Once you have set up your profile, click on <strong><a href="https://campuschampions.cyberinfrastructure.org/affinity-groups">Community>Affinity Groups</a> </strong> to see a list of current Affinity Groups. Please click "join" for any of those that are of interest, and you will appear in the Affinity Group.</p>
      <p> If you&apos;d like to start a new Affinity Group, please start the process using <a href="https://campuschampions.cyberinfrastructure.org/form/affinity-group-request">this form</a> on the Affinity Groups page.</p>
      <p></p>
      <p>Thank you for your participation!</p>
      <p></p>
      <p>-- The Campus Champions Leadership team</p>
      </body>
      </html>';
    $langcode = $user->getPreferredLangcode();
    $send = TRUE;

    $result = $this->mailManager->mail($module, $key, $to, $langcode, $params, NULL, $send);
    if ($result['result'] != TRUE) {
      $message = $this->t('There was a problem sending your email notification to @email.', ['@email' => $to]);
      $this->messenger->addError($message);
      $this->loggerFactory->get('mail-log')->error($message);
      return;
    }

    $message = $this->t('An email notification has been sent to @email ', ['@email' => $to]);
    $this->messenger->addMessage($message);
    $this->loggerFactory->get('mail-log')->notice($message);
  }

}
