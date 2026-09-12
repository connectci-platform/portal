<?php

namespace Drupal\campuschampions\Plugin\Action;

use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\webform\Entity\WebformSubmission;

/**
 * @Action(
 *   id = "campuschampions_approve_cc_decline",
 *   label = @Translation("Decline Campus Champion"),
 *   type = ""
 * )
 */
class DeclineCCAction extends ViewsBulkOperationsActionBase
{
    /**
     * Set the status of a Campus Champion application to 'declined'
     *
     * {@inheritdoc}
     *
     * @return \Drupal\Core\StringTranslation\TranslatableMarkup
     *   The result message shown by VBO.
     */
    public function execute(WebformSubmission $entity = null)
    {
        $sid = $entity->id();
        $webform_submission = WebformSubmission::load($sid);
        // Direct save, not submitWebformSubmission(): the form path
        // re-validates against the current form and silently aborts on any
        // pre-org-migration submission. See ApproveCCAction::execute().
        $webform_submission->setElementData('status', 'declined');
        $webform_submission->save();
        return $this->t('Campus Champion application(s) declined');
    }

    /**
     * {@inheritdoc}
     */
    public function access($object, AccountInterface $account = null, $return_as_object = false)
    {
        // @see Drupal\Core\Field\FieldUpdateActionBase::access().
        return $object->access('update', $account, $return_as_object);
    }
}
