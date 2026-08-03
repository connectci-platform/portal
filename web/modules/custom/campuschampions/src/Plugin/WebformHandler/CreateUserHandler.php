<?php

namespace Drupal\campuschampions\Plugin\WebformHandler;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Password\PasswordGeneratorInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\user\Entity\User;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Create a new user entity from a webform submission.
 *
 * @WebformHandler(
 *   id = "create_user",
 *   label = @Translation("Create User"),
 *   category = @Translation("Entity creation"),
 *   description = @Translation("Creates a new drupal user."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_UNLIMITED,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 * )
 */
class CreateUserHandler extends WebformHandlerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The password generator.
   *
   * @var \Drupal\Core\Password\PasswordGeneratorInterface
   */
  protected $passwordGenerator;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->database = $container->get('database');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->languageManager = $container->get('language_manager');
    $instance->passwordGenerator = $container->get('password_generator');
    $instance->loggerFactory = $container->get('logger.factory');
    $instance->renderer = $container->get('renderer');
    $instance->mailManager = $container->get('plugin.manager.mail');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(WebformSubmissionInterface $webformSubmission, $update = TRUE) {
    $data = $webformSubmission->getData();

    // Create a new user only if one does not already exist. The
    // users_field_data table enforces uniqueness on both the email ('mail')
    // and the username ('name'), so we must check both: an applicant may enter
    // a username (e.g. an ACCESS-CI ID) that already belongs to an account
    // created under a different email. Checking email alone lets that slip
    // through and the INSERT then throws an EntityStorageException that aborts
    // the whole approval batch (500 error).
    $query = $this->entityTypeManager->getStorage('user')->getQuery()
      ->accessCheck(FALSE);
    $query->condition($query->orConditionGroup()
      ->condition('mail', $data['user_email'])
      ->condition('name', $data['username'] ?? ''));
    $ids = $query->execute();
    if (empty($ids)) {
      $user = $this->createUser($data);
      $webformSubmission->convert($user);
      $this->emailAccountNotification($user, $data['user_email']);
    }
  }

  /**
   * Create user.
   *
   * @param array $data
   *   The webform submission data.
   *
   * @return \Drupal\user\Entity\User
   *   The created user entity.
   */
  protected function createUser($data): User {
    $email = $data['user_email'];
    $username = $data['username'];
    $lang = $this->languageManager->getCurrentLanguage()->getId();
    $first_name = $data['user_first_name'];
    $last_name = $data['user_last_name'];
    $default_role = 'research_computing_facilitator';
    $carnegie_code = $data['carnegie_classification'];

    $query = $this->database->select('carnegie_codes', 'cc');
    $query->condition('cc.unitid', $carnegie_code, '=');
    $query->fields('cc', ['instnm']);
    $result = $query->execute();
    $record = $result->fetch();
    $institution = $record ? $record->instnm : '';

    // Try to find a matching ACCESS Organization by institution name.
    $access_org_id = $this->findAccessOrganization($institution);

    /** @var \Drupal\user\Entity\User $user */
    $user = User::create();

    // Mandatory fields.
    $generate_password = $this->passwordGenerator->generate();
    $user->setPassword($generate_password);
    $user->enforceIsNew();
    $user->setEmail($email);
    $user->setUsername($username);
    $user->addRole($default_role);

    // Optional fields.
    $user->set('field_user_first_name', $first_name);
    $user->set('field_user_last_name', $last_name);
    $user->set('field_carnegie_code', $carnegie_code);
    $user->set('field_institution', $institution);
    if ($access_org_id) {
      $user->set('field_access_organization', $access_org_id);
    }
    $user->set('init', $email);
    $user->set('langcode', $lang);
    $user->set('preferred_langcode', $lang);
    $user->set('preferred_admin_langcode', $lang);

    $user->activate();

    try {
      $user->save();
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('campuschampions')->error('Failed to create user @username: @message', [
        '@username' => $username,
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }

    return $user;
  }

  /**
   * Find a matching ACCESS Organization by institution name.
   *
   * Attempts to find an ACCESS Organization node that matches the institution
   * name from the Carnegie codes lookup. Checks both the title and the
   * field_organization_name field. Uses exact match first, then falls back
   * to a case-insensitive match.
   *
   * @param string $institution
   *   The institution name from Carnegie codes.
   *
   * @return int|null
   *   The ACCESS Organization node ID, or NULL if no match found.
   */
  protected function findAccessOrganization($institution) {
    if (empty($institution)) {
      return NULL;
    }

    // Try exact match on title first.
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'access_organization')
      ->condition('title', $institution)
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->range(0, 1);
    $nids = $query->execute();

    if (!empty($nids)) {
      return reset($nids);
    }

    // Try exact match on field_organization_name.
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'access_organization')
      ->condition('field_organization_name', $institution)
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->range(0, 1);
    $nids = $query->execute();

    if (!empty($nids)) {
      return reset($nids);
    }

    // Try case-insensitive match on title using LOWER().
    $result = $this->database->select('node_field_data', 'n')
      ->fields('n', ['nid'])
      ->condition('n.type', 'access_organization')
      ->condition('n.status', 1)
      ->where('LOWER(n.title) = LOWER(:title)', [':title' => $institution])
      ->range(0, 1)
      ->execute()
      ->fetchField();

    return $result ?: NULL;
  }

  /**
   * Email notification of new account to user.
   *
   * @param \Drupal\user\Entity\User $user
   *   The user entity.
   * @param string $mail
   *   The email address.
   */
  protected function emailAccountNotification($user, $mail) {
    $name = $user->getDisplayName();
    $url = user_pass_reset_url($user);
    $params = [];
    $params['name'] = $user->getDisplayName();
    $params['to'] = $mail;
    $body['string'] = [
      '#type' => 'inline_template',
      '#template' => '<html><head>
        <title>{{ title }}</title>
        </head><body>
        <p>{{ name }}</p>
        <p></p>
        <p>{{ intro }}</p>
        <p></p>
        <p><strong>{{ account }}</strong></p>
        <p></p>
        <p>{{ linktext }}</p>
        <p>{{ url }}</p>
        <p>{{ logintext }}</p>
        <p></p>
        <p><strong>{{ portalprofile }}</strong></p>
        <ol>
        <li>{{ click }} <strong>{{ myprofile }}</strong> {{ pulldown }}:
          <ol>
          <li>{{ li1 }}</li>
          <li>{{ li2 }}</li>
          <li>{{ li3 }}</li>
          </ol>
        <li>{{ li4one }} <strong>{{ li4strong1 }}</strong> {{ or }} <strong>{{ li4strong2 }}</strong> {{ li4two }}</li>
        </ol>
        <p></p>
        <p><strong>{{ chooseaffinity }}</strong></p>
        <p>{{ setupclick }} <strong>{{ affinitygrouplink }}</strong> {{ affinitygroupinst }}</p>
        <p>{{ startgroup }} {{ groupform }} {{ grouppage }}</p>
        <p></p>
        <p>{{ thanks }}</p>
        <p></p>
        <p>-- {{ teamsignoff }}</p>
        </body></html>',
      '#context' => [
        'title' => $this->t('Welcome to the Campus Champions Portal'),
        'name' => $this->t('Dear ') . $name . ",",
        'intro' => $this->t('The Campus Champions program is introducing <em>Affinity Groups</em> as a lightweight way for Champions with common interests to gather, share experience, and aggregate knowledge and resources. Your account has been created on the  <a href=https://campuschampions.cyberinfrastructure.org>Champions Portal</a> which will be used to manage Affinity Groups. Please take a few minutes to access your account, set up your profile, and choose your affinity groups.'),
        'account' => $this->t('ACCESS YOUR ACCOUNT'),
        'linktext' => $this->t('Click on this link or copy and paste it to your browser:'),
        'url' => $url,
        'logintext' => $this->t('This link can only be used once to log in and will lead you to a page where you can set your password. It expires after seven days and nothing will happen if it&apos;s not used.'),
        'portalprofile' => $this->t('SET UP YOUR PORTAL PROFILE'),
        'click' => $this->t('Click'),
        'myprofile' => $this->t('My Profile>Edit my account in the My Profile'),
        'pulldown' => $this->t('pulldown in the top right corner and make the following choices'),
        'li1' => $this->t('Select the program(s) in which you are a participant. The Campus Champions program has been pre-selected. To select the additional programs, command-click (on a MAC) or right-click (on a Windows-based machine).'),
        'li2' => $this->t('Select the role (or roles) that are applicable.'),
        'li3' => $this->t('Upload a picture so that we can recognize each other more readily!'),
        'li4one' => $this->t('Indicate your skills and interests by clicking'),
        'or' => $this->t('or'),
        'li4strong1' => $this->t('My profile>add/edit interests'),
        'li4strong2' => $this->t('My profile>add/edit skills'),
        'li4two' => $this->t('from the pulldown in the top right corner. "Skills" are topics on which you are willing to answer a question; "Interests" are topics about which you would like to learn more.'),
        'chooseaffinity' => $this->t('CHOOSE YOUR AFFINITY GROUPS'),
        'setupclick' => $this->t('Once you have set up your profile, click on'),
        'affinitygrouplink' => Link::fromTextAndUrl('Affinity Groups', Url::fromUri('https://campuschampions.cyberinfrastructure.org/affinity-groups')),
        'affinitygroupinst' => $this->t('to see a list of current Affinity Groups. Please click "join" for any of those that are of interest, and you will appear in the Affinity Group.'),
        'startgroup' => $this->t('If you&apos;d like to start a new Affinity Group, please start the process using'),
        'groupform' => Link::fromTextAndUrl('this form', Url::fromUri('https://campuschampions.cyberinfrastructure.org/form/affinity-group-request')),
        'grouppage' => $this->t('on the Affinity Groups page.'),
        'thanks' => $this->t('Thank you for your participation!'),
        'teamsignoff' => $this->t('The Campus Champions Leadership team'),
      ],
    ];
    $params['body'] = $this->renderer->render($body);
    $params['title'] = 'Welcome to the Campus Champions Portal!';
    $this->cc_send('join-cc', $params);
  }

  /**
   * Send email.
   *
   * @param string $key
   *   The mail key.
   * @param array $params
   *   The mail parameters.
   *
   * @return array
   *   The result from mail manager.
   */
  private function cc_send($key, $params) {
    $to = $params['to'];
    $langcode = 'en';
    $send = TRUE;
    $module = 'campuschampions';
    $result = $this->mailManager->mail($module, $key, $to, $langcode, $params, NULL, $send);
    return $result;
  }

}
