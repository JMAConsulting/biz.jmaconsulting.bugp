<?php
/*
 +--------------------------------------------------------------------+
 | CiviCRM version 4.5                                                |
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC (c) 2004-2014                                |
 +--------------------------------------------------------------------+
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007 and the CiviCRM Licensing Exception.   |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License and the CiviCRM Licensing Exception along                  |
 | with this program; if not, contact CiviCRM LLC                     |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +--------------------------------------------------------------------+
*/

/**
 *
 * @package CRM
 * @copyright CiviCRM LLC (c) 2004-2014
 * $Id$
 *
 */

/**
 * This class provides the functionality for batch profile update for grants
 */
class CRM_Grant_Form_Task_Batch extends CRM_Grant_Form_Task {

  /**
   * the title of the group
   *
   * @var string
   */
  protected $_title;

  /**
   * maximum profile fields that will be displayed
   *
   */
  protected $_maxFields = 20;

  /**
   * variable to store redirect path
   *
   */
  protected $_userContext;

  /**
   * maximum contacts that should be allowed to update
   *
   */
  protected $_maxGrants = 100;

  /**
   * contact details
   *
   */
  protected $_contactDetails;

  /**
   * build all the data structures needed to build the form
   *
   * @return void
   * @access public
   */
  function preProcess() {
    /*
     * initialize the task and row fields
     */
    parent::preProcess();
    $this->_contactDetails = $this->get('contactDetails');
    $this->assign('contactDetails', $this->_contactDetails);
    $this->assign('readOnlyFields', array('sort_name' => ts('Name')));
  }

  /**
   * Build the form
   *
   * @access public
   *
   * @return void
   */
  function buildQuickForm() {
    $ufGroupId = $this->get('ufGroupId');

    if (!$ufGroupId) {
      CRM_Core_Error::fatal('ufGroupId is missing');
    }
    $this->_title = ts('Batch Update for Grant(s)') . ' - ' . CRM_Core_BAO_UFGroup::getTitle($ufGroupId);
    CRM_Utils_System::setTitle($this->_title);
    
    $this->_fields = array();
    $this->_fields = CRM_Core_BAO_UFGroup::getFields($ufGroupId, FALSE, CRM_Core_Action::VIEW);

    // remove file type field and then limit fields
    $suppressFields = FALSE;
    $removehtmlTypes = array('File');
    foreach ($this->_fields as $name => $field) {
      if ($cfID = CRM_Core_BAO_CustomField::getKeyID($name) &&
        in_array($this->_fields[$name]['html_type'], $removehtmlTypes)
      ) {
        $suppressFields = TRUE;
        unset($this->_fields[$name]);
      }
      //fix to reduce size as we are using this field in grid
      if (is_array($field['attributes']) && isset($this->_fields[$name]['attributes']['size']) && $this->_fields[$name]['attributes']['size'] > 19) {
        //shrink class to "form-text-medium"
        $this->_fields[$name]['attributes']['size'] = 19;
      }
    }
    
    $this->_fields = array_slice($this->_fields, 0, $this->_maxFields);

    $this->assign('profileTitle', $this->_title);
    $this->assign('componentIds', $this->_grantIds);

    //fix for CRM-2752
    $customFields = CRM_Core_BAO_CustomField::getFields('Grant');
    $entityColumnValue = array();
    foreach ($this->_grantIds as $grantId) {
      $typeId = CRM_Core_DAO::getFieldValue("CRM_Grant_DAO_Grant", $grantId, 'grant_type_id');
      foreach ($this->_fields as $name => $field) {
        if ($customFieldID = CRM_Core_BAO_CustomField::getKeyID($name)) {
          $customValue = CRM_Utils_Array::value($customFieldID, $customFields);
          if (!empty($customValue['extends_entity_column_value'])) {
            $entityColumnValue = explode(CRM_Core_DAO::VALUE_SEPARATOR,
              $customValue['extends_entity_column_value']
            );
          }

          if (!empty($entityColumnValue[$typeId]) || 
            CRM_Utils_System::isNull(CRM_Utils_Array::value($typeId, $entityColumnValue))
          ) {
            CRM_Core_BAO_UFGroup::buildProfile($this, $field, NULL, $grantId);
          }
        }
        else {
          // handle non custom fields
          CRM_Core_BAO_UFGroup::buildProfile($this, $field, NULL, $grantId);
          
          if (in_array($name, array(
            'amount_total', 'grant_amount_requested', 'amount_granted'))) {
            $this->addRule("field[{$grantId}][{$name}]", ts('Please enter a valid amount.'), 'money');
          }
        }
      }
    }

    $this->assign('fields', $this->_fields);
    // don't set the status message when form is submitted.
    $buttonName = $this->controller->getButtonName('submit');

    if ($suppressFields && $buttonName != '_qf_Batch_next') {
      CRM_Core_Session::setStatus(ts("FILE field(s) in the selected profile are not supported for Batch Update and have been excluded."), ts('Unsupported Field Type'), 'error');
    }

    $this->addDefaultButtons(ts('Update Grant(s)'));
  }

  /**
   * This function sets the default values for the form.
   *
   * @access public
   *
   * @return void
   */
  function setDefaultValues() {
    if (empty($this->_fields)) {
      return;
    }

    $defaults = array();
    foreach ($this->_grantIds as $grantId) {
      CRM_Core_BAO_UFGroup::setProfileDefaults(NULL, $this->_fields, $defaults, FALSE, $grantId, 'Grant');
      CRM_Mrg_BAO_Mrg::setProfileDefaults($this->_contactDetails[$grantId]['contact_id'], $this->_fields, $defaults, $grantId);
    }

    return $defaults;
  }

  /**
   * process the form after the input has been submitted and validated
   *
   * @access public
   *
   * @return void
   */
  public function postProcess() {
    $params = $this->controller->exportValues($this->_name);
    $dates = array(
      'application_received_date',
      'decision_date',
      'money_transfer_date',
      'grant_due_date',
    );
    if (isset($params['field'])) {
      foreach ($params['field'] as $key => $value) {

        CRM_Contact_BAO_Contact::createProfileContact($value, $this->_fields, $this->_contactDetails[$key]['contact_id']);

        $value['custom'] = CRM_Core_BAO_CustomField::postProcess($value,
          CRM_Core_DAO::$_nullObject,
          $key,
          'Grant'
        );

        $ids['grant_id'] = $key;
        //TODO: need to QA date fields for grants
        foreach ($dates as $val) {
          if (isset($value[$val])) {
            $value[$val] = CRM_Utils_Date::processDate($value[$val]);
          }
        }
        
        if (!empty($value['grant_money_transfer_date'])) {
          $value['money_transfer_date'] = $value['grant_money_transfer_date'];   
          unset($value['grant_money_transfer_date']);
        }
        if (!empty($value['grant_amount_requested'])) {
          $value['amount_requested'] = $value['grant_amount_requested'];   
          unset($value['grant_amount_requested']);
        }
        if (!empty($value['grant_application_received_date'])) {
          $value['application_received_date'] = $value['grant_application_received_date'];   
          unset($value['grant_application_received_date']);
        }
        
        $grant = CRM_Grant_BAO_Grant::add($value, $ids);
        
        // add custom field values
        if (!empty($value['custom']) &&
          is_array($value['custom'])
        ) {
          CRM_Core_BAO_CustomValueTable::store($value['custom'], 'civicrm_grant', $grant->id);
        }
      }
      CRM_Core_Session::setStatus(ts("Your updates have been saved."), ts('Saved'), 'success');
    }
    else {
      CRM_Core_Session::setStatus(ts("No updates have been saved."), ts('Not Saved'), 'alert');
    }
  }
}

