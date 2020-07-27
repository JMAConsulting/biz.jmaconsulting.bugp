<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

/**
 *
 * @package CRM
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 */

/**
 * This class contains function for UFField.
 */
class CRM_BUGP_BAO_UFField extends CRM_Core_BAO_UFField {

  /**
   * Batch entry fields.
   * @var array
   */
  private static $_contriBatchEntryFields = NULL;
  private static $_memberBatchEntryFields = NULL;

 /**
   * Create UFField object.
   *
   * @param array $params
   *   Array per getfields metadata.
   *
   * @return \CRM_Core_BAO_UFField
   * @throws \API_Exception
   */
  public static function create($params) {
    $id = CRM_Utils_Array::value('id', $params);

    // Merge in data from existing field
    if (!empty($id)) {
      $UFField = new CRM_Core_BAO_UFField();
      $UFField->id = $params['id'];
      if ($UFField->find(TRUE)) {
        $defaults = $UFField->toArray();
        // This will be calculated based on field name
        unset($defaults['field_type']);
        $params += $defaults;
      }
      else {
        throw new API_Exception("UFFIeld id {$params['id']} not found.");
      }
    }

    // Validate field_name
    if (strpos($params['field_name'], 'formatting') !== 0 && !CRM_Core_BAO_UFField::isValidFieldName($params['field_name'])) {
      throw new API_Exception('The field_name is not valid');
    }

    // Supply default label if not set
    if (empty($id) && !isset($params['label'])) {
      $params['label'] = self::getAvailableFieldTitles()[$params['field_name']];
    }

    // Supply field_type if not set
    if (empty($params['field_type']) && strpos($params['field_name'], 'formatting') !== 0) {
      $params['field_type'] = CRM_Utils_Array::pathGet(self::getAvailableFieldsFlat(), [$params['field_name'], 'field_type']);
    }
    elseif (empty($params['field_type'])) {
      $params['field_type'] = 'Formatting';
    }

    // Generate unique name for formatting fields
    if ($params['field_name'] === 'formatting') {
      $params['field_name'] = 'formatting_' . substr(uniqid(), -4);
    }

    if (self::duplicateField($params)) {
      throw new API_Exception("The field was not added. It already exists in this profile.");
    }

    //@todo why is this even optional? Surely weight should just be 'managed' ??
    if (CRM_Utils_Array::value('option.autoweight', $params, TRUE)) {
      $params['weight'] = CRM_Core_BAO_UFField::autoWeight($params);
    }

    // Set values for uf field properties and save
    $ufField = new CRM_Core_DAO_UFField();
    $ufField->copyValues($params);

    if ($params['field_name'] == 'url') {
      $ufField->location_type_id = 'null';
    }
    else {
      $ufField->website_type_id = 'null';
    }
    if (!strstr($params['field_name'], 'phone')) {
      $ufField->phone_type_id = 'null';
    }

    $ufField->save();

    $fieldsType = CRM_Core_BAO_UFGroup::calculateGroupType($ufField->uf_group_id, TRUE);
    CRM_Core_BAO_UFGroup::updateGroupTypes($ufField->uf_group_id, $fieldsType);

    civicrm_api3('profile', 'getfields', ['cache_clear' => TRUE]);
    return $ufField;
  }

  /**
   * Check for mix profile fields (eg: individual + other contact types)
   *
   * @param int $ufGroupId
   *
   * @return bool
   *   true for mix profile else false
   */
  public static function checkProfileType($ufGroupId) {
    $ufGroup = new CRM_Core_DAO_UFGroup();
    $ufGroup->id = $ufGroupId;
    $ufGroup->find(TRUE);

    $profileTypes = [];
    if ($ufGroup->group_type) {
      $typeParts = explode(CRM_Core_DAO::VALUE_SEPARATOR, $ufGroup->group_type);
      $profileTypes = explode(',', $typeParts[0]);
    }

    //early return if new profile.
    if (empty($profileTypes)) {
      return FALSE;
    }

    //we need to unset Contact
    if (count($profileTypes) > 1) {
      $index = array_search('Contact', $profileTypes);
      if ($index !== FALSE) {
        unset($profileTypes[$index]);
      }
    }

    // suppress any subtypes if present
    CRM_Contact_BAO_ContactType::suppressSubTypes($profileTypes);

    $contactTypes = ['Contact', 'Individual', 'Household', 'Organization'];
    $components = ['Contribution', 'Participant', 'Membership', 'Activity', 'Grant'];
    $fields = [];

    // check for mix profile condition
    if (count($profileTypes) > 1) {
      //check the there are any components include in profile
      foreach ($components as $value) {
        if (in_array($value, $profileTypes)) {
          return TRUE;
        }
      }
      //check if there are more than one contact types included in profile
      if (count($profileTypes) > 1) {
        return TRUE;
      }
    }
    elseif (count($profileTypes) == 1) {
      // note for subtype case count would be zero
      $profileTypes = array_values($profileTypes);
      if (!in_array($profileTypes[0], $contactTypes)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Get the profile type (eg: individual/organization/household)
   *
   * @param int $ufGroupId
   *   Uf group id.
   * @param bool $returnMixType
   *   This is true, then field type of mix profile field is returned.
   * @param bool $onlyPure
   *   True if only pure profiles are required.
   *
   * @param bool $skipComponentType
   *
   * @return string
   *   profile group_type
   *
   */
  public static function getProfileType($ufGroupId, $returnMixType = TRUE, $onlyPure = FALSE, $skipComponentType = FALSE) {
    $ufGroup = new CRM_Core_DAO_UFGroup();
    $ufGroup->id = $ufGroupId;
    $ufGroup->is_active = 1;

    $ufGroup->find(TRUE);
    return self::calculateProfileType($ufGroup->group_type, $returnMixType, $onlyPure, $skipComponentType);
  }

  /**
   * Get the profile type (eg: individual/organization/household)
   *
   * @param string $ufGroupType
   * @param bool $returnMixType
   *   This is true, then field type of mix profile field is returned.
   * @param bool $onlyPure
   *   True if only pure profiles are required.
   * @param bool $skipComponentType
   *
   * @return string  profile group_type
   *
   */
  public static function calculateProfileType($ufGroupType, $returnMixType = TRUE, $onlyPure = FALSE, $skipComponentType = FALSE) {
    // profile types
    $contactTypes = ['Contact', 'Individual', 'Household', 'Organization'];
    $subTypes = CRM_Contact_BAO_ContactType::subTypes();
    $components = ['Contribution', 'Participant', 'Membership', 'Activity', 'Grant'];

    $profileTypes = [];
    if ($ufGroupType) {
      $typeParts = explode(CRM_Core_DAO::VALUE_SEPARATOR, $ufGroupType);
      $profileTypes = explode(',', $typeParts[0]);
    }

    if ($onlyPure) {
      if (count($profileTypes) == 1) {
        return $profileTypes[0];
      }
      else {
        return NULL;
      }
    }

    //we need to unset Contact
    if (count($profileTypes) > 1) {
      $index = array_search('Contact', $profileTypes);
      if ($index !== FALSE) {
        unset($profileTypes[$index]);
      }
    }

    $profileType = $mixProfileType = NULL;

    // this case handles pure profile
    if (count($profileTypes) == 1) {
      $profileType = array_pop($profileTypes);
    }
    else {
      //check the there are any components include in profile
      $componentCount = [];
      foreach ($components as $value) {
        if (in_array($value, $profileTypes)) {
          $componentCount[] = $value;
        }
      }

      //check contact type included in profile
      $contactTypeCount = [];
      foreach ($contactTypes as $value) {
        if (in_array($value, $profileTypes)) {
          $contactTypeCount[] = $value;
        }
      }
      // subtype counter
      $subTypeCount = [];
      foreach ($subTypes as $value) {
        if (in_array($value, $profileTypes)) {
          $subTypeCount[] = $value;
        }
      }
      if (!$skipComponentType && count($componentCount) == 1) {
        $profileType = $componentCount[0];
      }
      elseif (count($componentCount) > 1) {
        $mixProfileType = $componentCount[1];
      }
      elseif (count($subTypeCount) == 1) {
        $profileType = $subTypeCount[0];
      }
      elseif (count($contactTypeCount) == 1) {
        $profileType = $contactTypeCount[0];
      }
      elseif (count($subTypeCount) > 1) {
        // this is mix subtype profiles
        $mixProfileType = $subTypeCount[1];
      }
      elseif (count($contactTypeCount) > 1) {
        // this is mix contact profiles
        $mixProfileType = $contactTypeCount[1];
      }
    }

    if ($mixProfileType) {
      if ($returnMixType) {
        return $mixProfileType;
      }
      else {
        return 'Mixed';
      }
    }
    else {
      return $profileType;
    }
  }

  /**
   * Get a list of fields which can be added to profiles.
   *
   * @param int $gid : UF group ID
   * @param array $defaults : Form defaults
   * @return array, multidimensional; e.g. $result['FieldGroup']['field_name']['label']
   */
  public static function getAvailableFields($gid = NULL, $defaults = []) {
    $fields = [
      'Contact' => [],
      'Individual' => CRM_Contact_BAO_Contact::importableFields('Individual', FALSE, FALSE, TRUE, TRUE, TRUE),
      'Household' => CRM_Contact_BAO_Contact::importableFields('Household', FALSE, FALSE, TRUE, TRUE, TRUE),
      'Organization' => CRM_Contact_BAO_Contact::importableFields('Organization', FALSE, FALSE, TRUE, TRUE, TRUE),
    ];

    // include hook injected fields
    $fields['Contact'] = array_merge($fields['Contact'], CRM_Contact_BAO_Query_Hook::singleton()->getFields());

    // add current employer for individuals
    $fields['Individual']['current_employer'] = [
      'name' => 'organization_name',
      'title' => ts('Current Employer'),
    ];

    $addressOptions = CRM_Core_BAO_Setting::valueOptions(CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME,
      'address_options', TRUE, NULL, TRUE
    );

    if (empty($addressOptions['county'])) {
      unset($fields['Individual']['county'], $fields['Household']['county'], $fields['Organization']['county']);
    }

    // break out common contact fields array CRM-3037.
    // from a UI perspective this makes very little sense
    foreach ($fields['Individual'] as $key => $value) {
      if (!empty($fields['Household'][$key]) && !empty($fields['Organization'][$key])) {
        $fields['Contact'][$key] = $value;
        unset($fields['Individual'][$key], $fields['Household'][$key], $fields['Organization'][$key]);
      }
    }

    // Internal field not exposed to forms
    unset($fields['Contact']['contact_type']);
    unset($fields['Contact']['master_id']);

    // convert phone extension in to psedo-field phone + phone extension
    //unset extension
    unset($fields['Contact']['phone_ext']);
    //add psedo field
    $fields['Contact']['phone_and_ext'] = [
      'name' => 'phone_and_ext',
      'title' => ts('Phone and Extension'),
      'hasLocationType' => 1,
    ];

    // include Subtypes For Profile
    $subTypes = CRM_Contact_BAO_ContactType::subTypeInfo();
    foreach ($subTypes as $name => $val) {
      //custom fields for sub type
      $subTypeFields = CRM_Core_BAO_CustomField::getFieldsForImport($name, FALSE, FALSE, FALSE, TRUE, TRUE);
      if (array_key_exists($val['parent'], $fields)) {
        $fields[$name] = $fields[$val['parent']] + $subTypeFields;
      }
      else {
        $fields[$name] = $subTypeFields;
      }
    }

    if (CRM_Core_Permission::access('CiviContribute')) {
      $contribFields = CRM_Contribute_BAO_Contribution::getContributionFields(FALSE);
      if (!empty($contribFields)) {
        unset($contribFields['is_test']);
        unset($contribFields['is_pay_later']);
        unset($contribFields['contribution_id']);
        $contribFields['contribution_note'] = [
          'name' => 'contribution_note',
          'title' => ts('Contribution Note'),
        ];
        $fields['Contribution'] = array_merge($contribFields, self::getContribBatchEntryFields());
      }
    }

    if (CRM_Core_Permission::access('CiviGrant')) {
      $grantFields = CRM_BUGP_BAO_Bugp::getGrantFields();
      if (!empty($grantFields)) {
        $fields['Grant'] = $grantFields;
      }
    }

    if (CRM_Core_Permission::access('CiviEvent')) {
      $participantFields = CRM_Event_BAO_Query::getParticipantFields();
      if ($participantFields) {
        // Remove fields not supported by profiles
        CRM_Utils_Array::remove($participantFields,
          'external_identifier',
          'event_id',
          'participant_contact_id',
          'participant_role_id',
          'participant_status_id',
          'participant_is_test',
          'participant_fee_level',
          'participant_id',
          'participant_is_pay_later',
          'participant_campaign'
        );
        if (isset($participantFields['participant_campaign_id'])) {
          $participantFields['participant_campaign_id']['title'] = ts('Campaign');
        }
        $fields['Participant'] = $participantFields;
      }
    }

    if (CRM_Core_Permission::access('CiviMember')) {
      $membershipFields = CRM_Member_BAO_Membership::getMembershipFields();
      // Remove fields not supported by profiles
      CRM_Utils_Array::remove($membershipFields,
        'membership_id',
        'membership_type_id',
        'member_is_test',
        'is_override',
        'member_is_override',
        'status_override_end_date',
        'status_id',
        'member_is_pay_later'
      );
      if ($gid && CRM_Core_DAO::getFieldValue('CRM_Core_DAO_UFGroup', $gid, 'name') == 'membership_batch_entry') {
        $fields['Membership'] = array_merge($membershipFields, self::getMemberBatchEntryFields());
      }
      else {
        $fields['Membership'] = $membershipFields;
      }
    }

    if (CRM_Core_Permission::access('CiviCase')) {
      $caseFields = CRM_Case_BAO_Query::getFields(TRUE);
      $caseFields = array_merge($caseFields, CRM_Core_BAO_CustomField::getFieldsForImport('Case'));
      if ($caseFields) {
        // Remove fields not supported by profiles
        CRM_Utils_Array::remove($caseFields,
          'case_id',
          'case_type',
          'case_role',
          'case_deleted'
        );
      }
      $fields['Case'] = $caseFields;
    }

    $activityFields = CRM_Activity_BAO_Activity::getProfileFields();
    if ($activityFields) {
      // campaign related fields.
      if (isset($activityFields['activity_campaign_id'])) {
        $activityFields['activity_campaign_id']['title'] = ts('Campaign');
      }
      $fields['Activity'] = $activityFields;
    }

    $fields['Formatting']['format_free_html_' . rand(1000, 9999)] = [
      'name' => 'free_html',
      'import' => FALSE,
      'export' => FALSE,
      'title' => 'Free HTML',
    ];

    // Sort by title
    foreach ($fields as &$values) {
      $values = CRM_Utils_Array::crmArraySortByField($values, 'title');
    }

    //group selected and unwanted fields list
    $ufFields = $gid ? CRM_BUGP_BAO_UFGroup::getFields($gid, FALSE, NULL, NULL, NULL, TRUE, NULL, TRUE) : [];
    $groupFieldList = array_merge($ufFields, [
      'note',
      'email_greeting_custom',
      'postal_greeting_custom',
      'addressee_custom',
      'id',
    ]);
    //unset selected fields
    foreach ($groupFieldList as $key => $value) {
      if (is_int($key)) {
        unset($fields['Individual'][$value], $fields['Household'][$value], $fields['Organization'][$value]);
        continue;
      }
      if (!empty($defaults['field_name'])
        && $defaults['field_name']['0'] == $value['field_type']
        && $defaults['field_name']['1'] == $key
      ) {
        continue;
      }
      unset($fields[$value['field_type']][$key]);
    }

    return $fields;
  }

  /**
   * Get a list of fields which can be added to profiles.
   *
   * @param bool $force
   *
   * @return array
   *   e.g. $result['field_name']['label']
   */
  public static function getAvailableFieldsFlat($force = FALSE) {
    if (!isset(Civi::$statics['UFFieldsFlat']) || $force) {
      Civi::$statics['UFFieldsFlat'] = [];
      foreach (self::getAvailableFields() as $fieldType => $fields) {
        foreach ($fields as $fieldName => $field) {
          if (!isset(Civi::$statics['UFFieldsFlat'][$fieldName])) {
            $field['field_type'] = $fieldType;
            Civi::$statics['UFFieldsFlat'][$fieldName] = $field;
          }
        }
      }
    }
    return Civi::$statics['UFFieldsFlat'];
  }

  /**
   * Get a list of fields which can be added to profiles in the format [name => title]
   *
   * @return array
   */
  public static function getAvailableFieldTitles() {
    $fields = self::getAvailableFieldsFlat();
    $fields['formatting'] = ['title' => ts('Formatting')];
    return CRM_Utils_Array::collect('title', $fields);
  }

  /**
   * Determine whether the given field_name is valid.
   *
   * @param string $fieldName
   * @return bool
   */
  public static function isValidFieldName($fieldName) {
    $availableFields = CRM_BUNG_BAO_UFField::getAvailableFieldsFlat();
    return isset($availableFields[$fieldName]);
  }

  /**
   * Check for mix profiles groups (eg: individual + other contact types)
   *
   * @param $ctype
   *
   * @return bool
   *   true for mix profile group else false
   */
  public static function checkProfileGroupType($ctype) {
    $ufGroup = new CRM_Core_DAO_UFGroup();

    $query = "
SELECT ufg.id as id
  FROM civicrm_uf_group as ufg, civicrm_uf_join as ufj
 WHERE ufg.id = ufj.uf_group_id
   AND ufj.module = 'User Registration'
   AND ufg.is_active = 1 ";

    $ufGroup = CRM_Core_DAO::executeQuery($query);

    $fields = [];
    $validProfiles = ['Individual', 'Organization', 'Household', 'Contribution'];
    while ($ufGroup->fetch()) {
      $profileType = self::getProfileType($ufGroup->id);
      if (in_array($profileType, $validProfiles)) {
        continue;
      }
      elseif ($profileType) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * @return array|null
   */
  public static function getContribBatchEntryFields() {
    if (self::$_contriBatchEntryFields === NULL) {
      self::$_contriBatchEntryFields = [
        'send_receipt' => [
          'name' => 'send_receipt',
          'title' => ts('Send Receipt'),
        ],
        'soft_credit' => [
          'name' => 'soft_credit',
          'title' => ts('Soft Credit'),
        ],
        'soft_credit_type' => [
          'name' => 'soft_credit_type',
          'title' => ts('Soft Credit Type'),
        ],
        'product_name' => [
          'name' => 'product_name',
          'title' => ts('Premiums'),
        ],
        'contribution_note' => [
          'name' => 'contribution_note',
          'title' => ts('Contribution Note'),
        ],
        'contribution_soft_credit_pcp_id' => [
          'name' => 'contribution_soft_credit_pcp_id',
          'title' => ts('Personal Campaign Page'),
        ],
      ];
    }
    return self::$_contriBatchEntryFields;
  }

  /**
   * @return array|null
   */
  public static function getMemberBatchEntryFields() {
    if (self::$_memberBatchEntryFields === NULL) {
      self::$_memberBatchEntryFields = [
        'send_receipt' => [
          'name' => 'send_receipt',
          'title' => ts('Send Receipt'),
        ],
        'soft_credit' => [
          'name' => 'soft_credit',
          'title' => ts('Soft Credit'),
        ],
        'product_name' => [
          'name' => 'product_name',
          'title' => ts('Premiums'),
        ],
        'financial_type' => [
          'name' => 'financial_type',
          'title' => ts('Financial Type'),
        ],
        'total_amount' => [
          'name' => 'total_amount',
          'title' => ts('Total Amount'),
        ],
        'receive_date' => [
          'name' => 'receive_date',
          'title' => ts('Date Received'),
        ],
        'payment_instrument' => [
          'name' => 'payment_instrument',
          'title' => ts('Payment Method'),
        ],
        'contribution_status_id' => [
          'name' => 'contribution_status_id',
          'title' => ts('Contribution Status'),
        ],
        'trxn_id' => [
          'name' => 'contribution_trxn_id',
          'title' => ts('Contribution Transaction ID'),
        ],
      ];
    }
    return self::$_memberBatchEntryFields;
  }

}
