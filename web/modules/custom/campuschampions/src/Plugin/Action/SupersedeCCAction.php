<?php

namespace Drupal\campuschampions\Plugin\Action;

use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\webform\Entity\WebformSubmission;

/**
 * @Action(
 *   id = "campuschampions_supersede",
 *   label = @Translation("Mark as superseded"),
 *   type = ""
 * )
 */
class SupersedeCCAction extends ViewsBulkOperationsActionBase {

  /**
   * Mark a Campus Champion application as superseded.
   *
   * Approval already supersedes a champion's prior approved applications
   * automatically. This action is the manual counterpart for the applications
   * view: when an applicant has more than one approved submission, an admin
   * keeps the current one and supersedes the older duplicate(s).
   *
   * {@inheritdoc}
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The result message shown by VBO.
   */
  public function execute(WebformSubmission $entity = NULL) {
    $submission = WebformSubmission::load($entity->id());
    // Direct save, not submitWebformSubmission(): the form path
    // re-validates against the current form and silently aborts on any
    // pre-org-migration submission. See ApproveCCAction::execute().
    $submission->setElementData('status', 'superseded');
    $submission->save();
    return $this->t('Campus Champion application(s) marked as superseded');
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}
