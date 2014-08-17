<?php

require_once 'mrg.civix.php';

/**
 * Implementation of hook_civicrm_config
 */
function mrg_civicrm_config(&$config) {
  _mrg_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_xmlMenu
 *
 * @param $files array(string)
 */
function mrg_civicrm_xmlMenu(&$files) {
  _mrg_civix_civicrm_xmlMenu($files);
}

/**
 * Implementation of hook_civicrm_install
 */
function mrg_civicrm_install() {
  return _mrg_civix_civicrm_install();
}

/**
 * Implementation of hook_civicrm_uninstall
 */
function mrg_civicrm_uninstall() {
  return _mrg_civix_civicrm_uninstall();
}

/**
 * Implementation of hook_civicrm_enable
 */
function mrg_civicrm_enable() {
  return _mrg_civix_civicrm_enable();
}

/**
 * Implementation of hook_civicrm_disable
 */
function mrg_civicrm_disable() {
  return _mrg_civix_civicrm_disable();
}

/**
 * Implementation of hook_civicrm_upgrade
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed  based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
 */
function mrg_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _mrg_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implementation of hook_civicrm_managed
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 */
function mrg_civicrm_managed(&$entities) {
  return _mrg_civix_civicrm_managed($entities);
}

/**
 * Function to get list of grant fields for profile
 * For now we only allow custom grant fields to be in
 * profile
 *
 * @param boolean $addExtraFields true if special fields needs to be added
 *
 * @return return the list of grant fields
 * @static
 * @access public
 */
function getGrantFields() {
  $grantFields = CRM_Grant_DAO_Grant::export();
  $grantFields = array_merge($grantFields, CRM_Core_OptionValue::getFields($mode = 'grant'));
       
  $grantFields = array_merge($grantFields, CRM_Financial_DAO_FinancialType::export());
    
  foreach ($grantFields as $key => $var) {
    $fields[$key] = $var;
  }

  return array_merge($fields, CRM_Core_BAO_CustomField::getFieldsForImport('Grant'));
}

function mrg_civicrm_searchTasks($objectName, &$tasks) {
  if ($objectName == 'grant') {
    foreach ($tasks as $key => $value) {
      if ($value['title'] == 'Update Grants') {
        $tasks[$key]['title'] = 'Batch Update Grants via Profile';
        $tasks[$key]['class'] = array( 
          'CRM_Grant_Form_Task_PickProfile',
          'CRM_Grant_Form_Task_Batch',
        );
      }
    }
  }
}

function mrg_civicrm_buildForm($formName, &$form) {
  // Code to be done to avoid core editing
  if ($formName == "CRM_UF_Form_Field" && CRM_Core_Permission::access('CiviGrant')) {
    $grantFields = getProfileFields();
    $fields['Grant'] = $grantFields;
    // Add the grant fields to the form
    $originalFields = $form->getVar('_fields');
    $form->setVar('_fields', array_merge(exportableFields('Grant'), $originalFields));
    $originalSelect = $form->getVar('_selectFields');

    foreach ($fields as $key => $value) {
      foreach ($value as $key1 => $value1) {
        //CRM-2676, replacing the conflict for same custom field name from different custom group.
        if ($customFieldId = CRM_Core_BAO_CustomField::getKeyID($key1)) {
          $customGroupId = CRM_Core_DAO::getFieldValue('CRM_Core_DAO_CustomField', $customFieldId, 'custom_group_id');
          $customGroupName = CRM_Core_DAO::getFieldValue('CRM_Core_DAO_CustomGroup', $customGroupId, 'title');
          $mapperFields[$key][$key1] = $value1['title'] . ' :: ' . $customGroupName;
          $selectFields[$key][$key1] = $value1['title'];
        }
        else {
          $mapperFields[$key][$key1] = $value1['title'];
          $selectFields[$key][$key1] = $value1['title'];
        }
        $hasLocationTypes[$key][$key1] = CRM_Utils_Array::value('hasLocationType', $value1);
      }
    }
    if (!empty($selectFields['Grant'])) {
      $form->setVar('_selectFields', array_merge($selectFields['Grant'], $originalSelect));
    }
    if(!empty($noSearchable)) {
      $form->assign('noSearchable', $noSearchable);
    }
    $grantArray = array(
      'text' => 'Grant',
      'attr' => array('value' => 'Grant')
    );

    foreach ($form->_elements as $eleKey => $eleVal) {
      foreach ($eleVal as $optionKey => $optionVal) {
        if ($optionKey == '_options') {
          $form->_elements[$eleKey]->_options[0]['Grant'] = 'Grant';
          $form->_elements[$eleKey]->_options[1]['Grant'] = $mapperFields['Grant'];
        }
        if ($optionKey == '_elements') {
          $form->_elements[$eleKey]->_elements[0]->_options[] = $grantArray;
        } 
        if ($optionKey == '_js') {
          $form->_elements[$eleKey]->_js .= 'hs_field_name_Grant = ' . json_encode($mapperFields['Grant']) . ';';
        }
      }
    } 
    if ($form->_defaultValues && array_key_exists('field_name', $form->_defaultValues) 
      && $form->_defaultValues['field_name'][0] == 'Grant') {
      $defaults['field_name'] = $form->_defaultValues['field_name'];
      $form->setDefaults($defaults);
    }
  }
}

function &exportableFields() {
  $grantFields = array(
    'grant_status' => array(
      'title' => 'Grant Status',
      'name' => 'grant_status',
      'data_type' => CRM_Utils_Type::T_STRING,
    ),
    'grant_type' => array(
      'title' => 'Grant Type',
      'name' => 'grant_type',
      'data_type' => CRM_Utils_Type::T_STRING,
    ),
    'grant_money_transfer_date' => array(
      'title' => 'Grant Money Transfer Date',
      'name' => 'grant_money_transfer_date',
      'data_type' => CRM_Utils_Type::T_DATE,
    ),
    'grant_amount_requested' => array(
      'title' => 'Grant Amount Requested',
      'name' => 'grant_amount_requested',
      'where' => 'civicrm_grant.amount_requested',
      'data_type' => CRM_Utils_Type::T_FLOAT,
    ),
    'grant_application_received_date' => array(
      'title' => 'Grant Application Recieved Date',
      'name' => 'grant_application_received_date',
      'data_type' => CRM_Utils_Type::T_DATE,
    ),
  );

  $fields = CRM_Grant_DAO_Grant::export();
  $grantNote = array('grant_note' => array(
    'title' => ts('Grant Note'),
    'name' => 'grant_note',
    'data_type' => CRM_Utils_Type::T_TEXT,
  ));
  $fields = array_merge($fields, $grantFields, $grantNote,
    CRM_Core_BAO_CustomField::getFieldsForImport('Grant')
  );
  return $fields;
}

function getProfileFields() {
  $exportableFields = exportableFields('Grant');
      
  $skipFields = array('grant_id', 'grant_contact_id', 'grant_type', 'grant_note', 'grant_status');
  foreach ($skipFields as $field) {
    if (isset($exportableFields[$field])) {
      unset($exportableFields[$field]);
    }
  }
  return $exportableFields;
}