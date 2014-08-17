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
 * This class provides the functionality for batch profile update
 */
class CRM_Grant_Form_Task_Batch extends CRM_Grant_Form_Task {

  /**
   * the title of the group
   *
   * @var string
   */
  protected $_title;

  /**
   * maximum contacts that should be allowed to update
   *
   */
  protected $_maxGrants = 100;

  /**
   * maximum profile fields that will be displayed
   *
   */
  protected $_maxFields = 9;

  /**
   * variable to store redirect path
   *
   */
  protected $_userContext;

  /**
   * when not to reset sort_name
   */
  protected $_preserveDefault = TRUE;

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
    
    $this->_contactIds =& CRM_Core_DAO::getContactIDsFromComponent($this->_grantIds,
      'civicrm_grant' 
    );
    
    $ufGroupId = $this->get('ufGroupId');
    
    if (!$ufGroupId) {
      CRM_Core_Error::fatal('ufGroupId is missing');
    }
    
    $this->_fields = CRM_Core_BAO_UFGroup::getFields($ufGroupId, FALSE, CRM_Core_Action::VIEW);
    
    $this->_title = ts('Batch Update') . ' - ' . CRM_Core_BAO_UFGroup::getTitle($ufGroupId);
    CRM_Utils_System::setTitle($this->_title);
  }

  /**
   * Build the form
   *
   * @access public
   *
   * @return void
   */
  function buildQuickForm() {
    // remove file type field and then limit fields
    $suppressFields = FALSE;
    $removehtmlTypes = array('File', 'Autocomplete-Select');
    foreach ($this->_fields as $name => $field) {
      if ($cfID = CRM_Core_BAO_CustomField::getKeyID($name) &&
        in_array($this->_fields[$name]['html_type'], $removehtmlTypes)
      ) {
        $suppressFields = TRUE;
        unset($this->_fields[$name]);
      }
    }
    
    $this->assign('profileTitle', $this->_title);
    $this->assign('componentIds', $this->_contactIds);

    // if below fields are missing we should not reset sort name / display name
    // CRM-6794
    $preserveDefaultsArray = array(
      'first_name', 'last_name', 'middle_name',
      'organization_name', 'prefix_id', 'suffix_id',
      'household_name',
    );

    $stateCountryMap = array();

    foreach ($this->_contactIds as $contactId) {
      $profileFields = $this->_fields;
      CRM_Core_BAO_Address::checkContactSharedAddressFields($profileFields, $contactId);
      foreach ($profileFields as $name => $field) {

        // Link state to country, county to state per location per contact
        list($prefixName, $index) = CRM_Utils_System::explode('-', $name, 2);
        if ($prefixName == 'state_province' || $prefixName == 'country' || $prefixName == 'county') {
          $stateCountryMap["$index-$contactId"][$prefixName] = "field_{$contactId}_{$field['name']}";
          $this->_stateCountryCountyFields["$index-$contactId"][$prefixName] = "field[{$contactId}][{$field['name']}]";
        }

        CRM_Core_BAO_UFGroup::buildProfile($this, $field, NULL, $contactId);

        if (in_array($field['name'], $preserveDefaultsArray)) {
          $this->_preserveDefault = FALSE;
        }
      }
    }

    CRM_Core_BAO_Address::addStateCountryMap($stateCountryMap);

    $this->assign('fields', $this->_fields);

    // don't set the status message when form is submitted.
    $buttonName = $this->controller->getButtonName('submit');

    if ($suppressFields && $buttonName != '_qf_BatchUpdateProfile_next') {
      CRM_Core_Session::setStatus(ts("File or Autocomplete Select type field(s) in the selected profile are not supported for Batch Update and have been excluded."), ts('Some Fields Excluded'), 'info');
    }

    $this->addDefaultButtons(ts('Update Grant(s)'));
    $this->addFormRule(array('CRM_Grant_Form_Task_Batch', 'formRule'));
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

    $defaults = $sortName = array();
    foreach ($this->_contactIds as $contactId) {
      $details[$contactId] = array();

      //build sortname
      $sortName[$contactId] = CRM_Core_DAO::getFieldValue('CRM_Contact_DAO_Contact',
        $contactId,
        'sort_name'
      );

      CRM_Core_BAO_UFGroup::setProfileDefaults($contactId, $this->_fields, $defaults, FALSE);
    }

    $this->assign('sortName', $sortName);

    // now fix all state country selectors
    CRM_Core_BAO_Address::fixAllStateSelects($this, $defaults, $this->_stateCountryCountyFields);

    return $defaults;
  }

  /**
   * global form rule
   *
   * @param array $fields  the input form values
   *
   * @return true if no errors, else array of errors
   * @access public
   * @static
   */
  static function formRule($fields) {
    $errors = array();
    $externalIdentifiers = array();
    foreach ($fields['field'] as $componentId => $field) {
      foreach ($field as $fieldName => $fieldValue) {
        if ($fieldName == 'external_identifier') {
          if (in_array($fieldValue, $externalIdentifiers)) {
            $errors["field[$componentId][external_identifier]"] = ts('Duplicate value for External Identifier.');
          }
          else {
            $externalIdentifiers[$componentId] = $fieldValue;
          }
        }
      }
    }

    return $errors;
  }

  /**
   * process the form after the input has been submitted and validated
   *
   * @access public
   *
   * @return void
   */
  public function postProcess() {
    $params = $this->exportValues();

    $ufGroupId = $this->get('ufGroupId');
    $notify = NULL;
    $inValidSubtypeCnt = 0;
    //send profile notification email if 'notify' field is set
    $notify = CRM_Core_DAO::getFieldValue('CRM_Core_DAO_UFGroup', $ufGroupId, 'notify');
    foreach ($params['field'] as $key => $value) {

      //CRM-5521
      //validate subtype before updating
      if (!empty($value['contact_sub_type']) && !CRM_Contact_BAO_ContactType::isAllowEdit($key)) {
        unset($value['contact_sub_type']);
        $inValidSubtypeCnt++;
      }

      $value['preserveDBName'] = $this->_preserveDefault;

      //parse street address, CRM-7768
      CRM_Contact_Form_Task_Batch::parseStreetAddress($value, $this);

      CRM_Contact_BAO_Contact::createProfileContact($value, $this->_fields, $key, NULL, $ufGroupId);
      if ($notify) {
        $values = CRM_Core_BAO_UFGroup::checkFieldsEmptyValues($ufGroupId, $key, NULL);
        CRM_Core_BAO_UFGroup::commonSendMail($key, $values);
      }
    }

    CRM_Core_Session::setStatus('', ts("Updates Saved"), 'success');
    if ($inValidSubtypeCnt) {
      CRM_Core_Session::setStatus(ts('Contact SubType field of 1 contact has not been updated.', array('plural' => 'Contact SubType field of %count contacts has not been updated.', 'count' => $inValidSubtypeCnt)), ts('Invalid Subtype'));
    }
  }
  //end of function
}

