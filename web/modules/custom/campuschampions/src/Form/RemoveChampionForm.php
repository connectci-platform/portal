<?php

namespace Drupal\campuschampions\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to enable people with CampusChampionsAdmin role to remove a champion.
 */
class RemoveChampionForm extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a RemoveChampionForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, MessengerInterface $messenger, LoggerChannelFactoryInterface $logger_factory) {
    $this->entityTypeManager = $entity_type_manager;
    $this->messenger = $messenger;
    $this->logger = $logger_factory->get('campuschampions');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('messenger'),
      $container->get('logger.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'remove_champion_form';
  }

  /**
   *
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['champion'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'user',
      '#title' => $this->t('Select a person to remove from the Campus Champions program'),
      '#required' => TRUE,
      '#weight' => '0',
    ];
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Remove'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * Check that the user is a Campus Champion.
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $champion = $form_state->getValue('champion');
    if ($champion == NULL || empty($champion)) {
      $form_state->setErrorByName('champion', $this->t('Please select a Campus Champion to remove.'));
      return;
    }

    // The entity_autocomplete field returns the entity ID directly.
    $user_id = (int) $champion;
    $user = $this->entityTypeManager->getStorage('user')->load($user_id);

    // Check if user exists.
    if (!$user) {
      $form_state->setErrorByName('champion', $this->t('User with ID @id was not found.', ['@id' => $user_id]));
      return;
    }

    // Check if user has field_is_cc field.
    if (!$user->hasField('field_is_cc')) {
      $form_state->setErrorByName('champion', $this->t('User @name does not have Campus Champion field.', ['@name' => $user->getAccountName()]));
      return;
    }

    // Check if user is actually a Campus Champion.
    if (!$user->field_is_cc->value) {
      $form_state->setErrorByName('champion', $this->t($user->getAccountName() . ' is not a Campus Champion.'));
    }

  }

  /**
   * Unset the field_is_cc value and remove the Campus Champions program.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $champion = $form_state->getValue('champion');
    $user_id = (int) $champion;

    if (!$user_id) {
      $this->messenger->addError($this->t('Invalid user selection.'));
      return;
    }

    $user = $this->entityTypeManager->getStorage('user')->load($user_id);
    if (!$user) {
      $this->messenger->addError($this->t('User not found.'));
      return;
    }

    try {
      // Set field_is_cc to 0 to mark as no longer a Campus Champion.
      $user->set('field_is_cc', 0);

      // Remove Campus Champions program (ID: 572) from field_region.
      if ($user->hasField('field_region')) {
        $regions = $user->get('field_region');
        $regions->filter(function (TypedDataInterface $item) {
          // Campus Champions Program ID.
          $cc_id = '572';
          if ($item->getValue()['target_id'] == $cc_id) {
            return FALSE;
          }
          return TRUE;
        });
        $user->field_region = $regions;
      }

      $user->save();

      // Mark their approved Join Campus Champions submissions as removed so the
      // applications list reflects that they are no longer in the program.
      _campuschampions_set_champion_submission_status((int) $user->id(), 'removed');

      $this->messenger->addMessage($this->t('Removed @name from Campus Champions.', [
        '@name' => $user->getAccountName(),
      ]));
    }
    catch (\Exception $e) {
      $this->logger->error('Error removing champion @id: @message', [
        '@id' => $user_id,
        '@message' => $e->getMessage(),
      ]);
      $this->messenger->addError($this->t('An error occurred while removing the champion: @message', [
        '@message' => $e->getMessage(),
      ]));
    }

  }
}
