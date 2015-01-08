<?php

define('PROPOSAL', 'custom_197');
define('ID', 197);

require_once 'bugp.civix.php';

/**
 * Implementation of hook_civicrm_config
 */
function bugp_civicrm_config(&$config) {
  _bugp_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_xmlMenu
 *
 * @param $files array(string)
 */
function bugp_civicrm_xmlMenu(&$files) {
  _bugp_civix_civicrm_xmlMenu($files);
}

/**
 * Implementation of hook_civicrm_install
 */
function bugp_civicrm_install() {
  return _bugp_civix_civicrm_install();
}

/**
 * Implementation of hook_civicrm_uninstall
 */
function bugp_civicrm_uninstall() {
  bug_enableDisableDeleteData(2);
  return _bugp_civix_civicrm_uninstall();
}

/**
 * Implementation of hook_civicrm_enable
 */
function bugp_civicrm_enable() {
  bug_enableDisableDeleteData(1);
  return _bugp_civix_civicrm_enable();
}

/**
 * Implementation of hook_civicrm_disable
 */
function bugp_civicrm_disable() {
  bug_enableDisableDeleteData(0);
  return _bugp_civix_civicrm_disable();
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
function bugp_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _bugp_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implementation of hook_civicrm_managed
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 */
function bugp_civicrm_managed(&$entities) {
  return _bugp_civix_civicrm_managed($entities);
}

function bugp_civicrm_searchTasks($objectName, &$tasks) {
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
        'attr' => array('value' => 'Grant')
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
  
  if ($formName == 'CRM_Grant_Form_Grant' && $form->getVar('_id') == '') {
    CRM_Core_Region::instance('page-body')->add(array(
      'template' => 'CRM/Grant/CustomGrant.tpl',
    ));
  }
}

/*
 * function to perform enable, disable, un-install actions
 *
 */
function bug_enableDisableDeleteData($action) {
  if ($action != 1) {
    $enableDisableDeleteData = CRM_BUGP_BAO_Bugp::checkRelatedExtensions();
    if ($enableDisableDeleteData) {
      return FALSE;
    }
    elseif($enableDisableDeleteData == 0) {
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

/*
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
  
  CRM_Core_BAO_Setting::setItem($params['enableComponents'],
    CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME,'enable_components');
  
}

/**
 * Implementation of hook_civicrm_post
 */
function bugp_civicrm_post($op, $objectName, $objectId, &$objectRef) {
  // For individual grants MRG-6
  if ($objectName == 'Grant' && $op == 'create') {
    // Add value for proposal

    // Calculate fiscal date
    $date = date('m/d/y', strtotime($objectRef->application_received_date));
    $fyStart = "7/1";
    $fyEnd = "6/30";
    $type = '';
    $fiscalDate = calculateFiscalYearForDate($date, $fyStart, $fyEnd);
    $grantTypes = CRM_Core_OptionGroup::values('grant_type');

    // Calculate grant type
    $mapping = array(
      'Board Advised' => 'BA',
      'Critical Response' => 'CR',
      'Donor Advised from Long-Term DA Fund' => 'DA',
      'Donor Advised from Short-Term DA Fund' => 'DP',
      'Funding Cycle' => 'FC',
      'Funding Cycle - Fall' => 'FF',
      'Funding Cycle - Spring' => 'FS',
      'McCay Fund' => 'MC',
      'McCay Fund - Peace Fund' => 'PF',
      'Peace Action Fund' => 'PA',
      'Project Connect - TT' => 'PC',
      'Project Connect - NA' => 'PC',
      'Peace Fund Collaboration' => 'PC',
      'Technical Assistance' => 'TA',
      'Technology Fund' => 'TF',
      'Technology Fund - TI' => 'TI',
      'Technology Fund - NA' => 'TI',
      'Travel' => 'TR',
      'WTO Response Fund' => 'WT',
    );
    if (isset($grantTypes[$objectRef->grant_type_id])) {
      $type = $mapping[$grantTypes[$objectRef->grant_type_id]];
      if (in_array($grantTypes[$objectRef->grant_type_id], array('Project Connect - TT', 'Project Connect - NA', 'Peace Fund Collaboration'))) {
        $type = 'PC';
      }
      if (in_array($grantTypes[$objectRef->grant_type_id], array('Technology Fund - TI', 'Technology Fund - NA', 'Technology Fund'))) {
        $type = 'TI';
      }
    }

    $proposal = array(
      'entity_id' =>  $objectId,
      PROPOSAL => (string)$fiscalDate.$type.$objectId,
    );
    $smarty = CRM_Core_Smarty::singleton();
    $smarty->assign('proposalValue', $proposal);
  }
}

function calculateFiscalYearForDate($inputDate, $fyStart, $fyEnd) {
  $date = strtotime($inputDate);
  $inputyear = strftime('%y',$date);
  
  $fystartdate = strtotime($fyStart.'/'.$inputyear);
  $fyenddate = strtotime($fyEnd.'/'.$inputyear);
  
  if ($date <= $fyenddate){
    $fy = intval($inputyear);
  }
  else{
    $fy = intval(intval($inputyear) + 1);
  }
  
  return $fy;
}

function bugp_civicrm_searchColumns($objectName, &$headers, &$rows, &$selector) {
  if ($objectName == 'grant') {
    $remove = array(
      'grant_amount_total' => 'Requested',
      'grant_application_received_date' => 'Application Received',
      'grant_report_received' => 'Report Received', 
      'money_transfer_date' => 'Money Transferred',
    );
    foreach ($headers as $key => $value) {
      if (isset($value['name']) && in_array($value['name'], $remove)) {
        unset($headers[$key]);
      }
    }
    $headers[6] = array(
      'name' => 'Proposal Number', 
    );
    ksort($headers);
    foreach ($rows as $key => $value) {
      $rows[$key] = array_diff_key($value, $remove);
      $custom = array('entity_id' => $value['grant_id'],'entity_table' => 'civicrm_grant');
      $c = civicrm_api3('CustomValue', 'get', $custom);
      if (isset($c['values'][ID])) {
        $rows[$key][PROPOSAL] = $c['values'][ID]['latest'];
      }
      else { 
        $rows[$key][PROPOSAL] = NULL;
      }
    }
  }
}

function bugp_civicrm_postProcess($formName, &$form) {
  if ($formName == 'CRM_Grant_Form_Grant' && $form->getVar('_id') == '') {
    $smarty = CRM_Core_Smarty::singleton()->get_template_vars('proposalValue');
    if ($smarty) {
      civicrm_api3('CustomValue', 'create', $smarty);
    }
  }
}
