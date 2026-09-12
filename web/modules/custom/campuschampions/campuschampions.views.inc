<?php

/**
 * @file
 * Views integration for the campuschampions module.
 */

/**
 * Implements hook_views_data_alter().
 */
function campuschampions_views_data_alter(array &$data) {
  $data['webform_submission']['cc_duplicate_name'] = [
    'title' => t('Duplicate applicant name'),
    'help' => t('Limit results to submissions whose applicant name appears on more than one submission of the same webform.'),
    'filter' => [
      'field' => 'sid',
      'label' => t('Duplicate names only'),
      'id' => 'campuschampions_duplicate_submission_name',
    ],
  ];

  $data['webform_submission']['cc_applicant_search'] = [
    'title' => t('Applicant search'),
    'help' => t('Free-text search matching a term against the applicant first name, last name, or email.'),
    'filter' => [
      'field' => 'sid',
      'label' => t('Search'),
      'id' => 'campuschampions_applicant_search',
    ],
  ];
}
