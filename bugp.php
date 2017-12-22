<?php

require_once 'bugp.civix.php';

/**
 * Implements hook_civicrm_config().
 */
function bugp_civicrm_config(&$config) {
  _bugp_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 */
function bugp_civicrm_xmlMenu(&$files) {
  _bugp_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 */
function bugp_civicrm_install() {
  bugp_addRemoveMenu(TRUE);
  return _bugp_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_uninstall().
 */
function bugp_civicrm_uninstall() {
  bug_enableDisableDeleteData(2);
  return _bugp_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 */
function bugp_civicrm_enable() {
  bug_enableDisableDeleteData(1);
  return _bugp_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 */
function bugp_civicrm_disable() {
  bug_enableDisableDeleteData(0);
  return _bugp_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 */
function bugp_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _bugp_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 */
function bugp_civicrm_managed(&$entities) {
  return _bugp_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_searchTasks().
 *
 */
function bugp_civicrm_searchTasks($objectName, &$tasks) {
  if ($objectName == 'grant') {
    $tasks[CRM_Grant_Task::UPDATE_GRANTS]['title'] = ts('Batch Update Grants via Profile');
    $tasks[CRM_Grant_Task::UPDATE_GRANTS]['class'] = array(
      'CRM_Grant_Form_Task_PickProfile',
      'CRM_Grant_Form_Task_Batch',
    );
  }
}

/**
 * Implements hook_civicrm_buildForm().
 *
 */
function bugp_civicrm_buildForm($formName, &$form) {
  // Code to be done to avoid core editing
  if ($formName == "CRM_UF_Form_Field" && CRM_Core_Permission::access('CiviGrant')) {
    if (!$form->elementExists('field_name')) {
      return NULL;
    }

    $elements = & $form->getElement('field_name');

    if ($elements && !array_key_exists('Grant', $elements->_options[0])) {
      $elements->_options[0]['Grant'] = 'Grant';
      $elements->_options[1]['Grant'] = $form->_mapperFields['Grant'];

      $elements->_elements[0]->_options[] = array(
        'text' => 'Grant',
        'attr' => array('value' => 'Grant'),
      );

      $elements->_js .= 'hs_field_name_Grant = ' . json_encode($form->_mapperFields['Grant']) . ';';
    }

    // set default mapper when updating profile fields
    if ($form->_defaultValues && array_key_exists('field_name', $form->_defaultValues)
      && $form->_defaultValues['field_name'][0] == 'Grant') {
      $defaults['field_name'] = $form->_defaultValues['field_name'];
      $form->setDefaults($defaults);
    }
  }
}

/**
 * function to perform enable, disable, un-install actions
 *
 */
function bug_enableDisableDeleteData($action) {
  if ($action != 1) {
    $enableDisableDeleteData = CRM_BUGP_BAO_Bugp::checkRelatedExtensions();
    if ($enableDisableDeleteData) {
      return FALSE;
    }
    elseif ($enableDisableDeleteData == 0) {
      $action = 0;
    }
  }

  if ($action < 2) {
    CRM_Core_DAO::executeQuery(
      "UPDATE civicrm_uf_group SET is_active = %1 WHERE group_type LIKE '%Grant%'",
      array(
        1 => array($action, 'Integer'),
      )
    );

  }
  else {
    CRM_Core_DAO::executeQuery(
      "DELETE uj.*, uf.*, g.* FROM civicrm_uf_group g
       LEFT JOIN civicrm_uf_join uj ON uj.uf_group_id = g.id
       LEFT JOIN civicrm_uf_field uf ON uf.uf_group_id = g.id
       WHERE g.group_type LIKE '%Grant%';"
    );
  }

  bugp_addRemoveMenu($action);

}

/**
 * function to enable / disable component
 *
 */
function bugp_addRemoveMenu($enable) {
  $config = CRM_Core_Config::singleton();

  $params['enableComponents'] = $config->enableComponents;
  if ($enable) {
    if (array_search('CiviGrant', $config->enableComponents)) {
      return NULL;
    }
    $params['enableComponents'][] = 'CiviGrant';
  }
  else {
    $key = array_search('CiviGrant', $params['enableComponents']);
    if ($key) {
      unset($params['enableComponents'][$key]);
    }
  }

  CRM_Core_BAO_Setting::setItem(
    $params['enableComponents'],
    CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME,
    'enable_components'
  );

}
