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
class CRM_Grant_Form_Task_PickProfile extends CRM_Grant_Form_Task {

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

    $session = CRM_Core_Session::singleton();
    $this->_userContext = $session->readUserContext();

    $validate = FALSE;
    //validations
    if (count($this->_grantIds) > $this->_maxGrants) {
      CRM_Core_Session::setStatus(ts("The maximum number of grants you can select for Batch Update is %1. You have selected %2. Please select fewer grants from your search results and try again.", array(1 => $this->_maxGrants, 2 => count($this->_grantIds))), ts('Maximum Exceeded'), 'error');
      $validate = TRUE;
    }

    // then redirect if error
    if ($validate) {
      CRM_Utils_System::redirect($this->_userContext);
    }
  }

  /**
   * Build the form
   *
   * @access public
   *
   * @return void
   */
  function buildQuickForm() {
    CRM_Utils_System::setTitle(ts('Batch Profile Update for Grant'));

    $grantTypes = CRM_Core_PseudoConstant::get('CRM_Grant_DAO_Grant', 'grant_type_id');
    foreach ($this->_grantIds as $id) {
      $grantTypeId = CRM_Core_DAO::getFieldValue('CRM_Grant_DAO_Grant', $id, 'grant_type_id');
      $this->_grantTypes[] = $grantTypes[$grantTypeId];
    }

    //add Contact type profiles
    $this->_grantTypes[] = 'Grant';
    $this->_grantTypes[] = 'Contact';
    $this->_grantTypes[] = 'Individual';

    $profiles = CRM_Core_BAO_UFGroup::getProfiles($this->_grantTypes);

    if (empty($profiles)) {
      $types = implode(' ' . ts('or') . ' ', $this->_contactTypes);
      CRM_Core_Session::setStatus(ts("The contact type selected for Batch Update does not have a corresponding profile. Please set up a profile for %1s and try again.", array(1 => $types)), ts('No Profile Available'), 'error');
      CRM_Utils_System::redirect($this->_userContext);
    }
    $ufGroupElement = $this->add('select', 'uf_group_id', ts('Select Profile'), array('' => ts('- select profile -')) + $profiles, TRUE);
    $this->assign('totalSelectedContacts', count($this->_grantIds));
    $this->addDefaultButtons(ts('Continue >>'));
  }

  /**
   * Add local and global form rules
   *
   * @access protected
   *
   * @return void
   */
  function addRules() {
    $this->addFormRule(array('CRM_Grant_Form_Task_PickProfile', 'formRule'));
  }

  /**
   * global validation rules for the form
   *
   * @param array $fields posted values of the form
   *
   * @return array list of errors to be posted back to the form
   * @static
   * @access public
   */
  static function formRule($fields) {
    if (CRM_Core_BAO_UFField::checkProfileType($fields['uf_group_id'])) {
      $errorMsg['uf_group_id'] = "You cannot select mix profile for batch update.";
    }

    if (!empty($errorMsg)) {
      //  return $errorMsg;
    }

    return TRUE;
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

    $this->set('ufGroupId', $params['uf_group_id']);

    // also reset the batch page so it gets new values from the db
    $this->controller->resetPage('Batch');
  }
  //end of function
}

