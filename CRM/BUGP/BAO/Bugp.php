<?php
/*
 +--------------------------------------------------------------------+
 | CiviCRM version 4.7                                                |
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
 * This class contains function for BUGP
 *
 */
class CRM_BUGP_BAO_Bugp extends CRM_Core_DAO {

  /**
   * This function retrieve component related contact information.
   *
   * @param array $componentIds array of component Ids.
   *
   * @return array $contactDetails array of contact info.@static
   */
  static public function contactDetails($componentIds) {
    $contactDetails = array();

    $autocompleteContactSearch = CRM_Core_BAO_Setting::valueOptions(CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME,
      'contact_autocomplete_options',
      TRUE, NULL, FALSE, 'name', TRUE
    );
    $returnProperties = array_fill_keys(array_merge(array('sort_name'),
      array_keys($autocompleteContactSearch)
    ), 1);

    $compTable = 'civicrm_grant';

    $select = $from = array();
    foreach ($returnProperties as $property => $ignore) {
      $value = (in_array($property, array(
        'city', 'street_address'))) ? 'address' : $property;
      switch ($property) {
        case 'sort_name':
          $select[] = "$property as $property";
          $from[$value] = "INNER JOIN civicrm_contact contact ON ( contact.id = $compTable.contact_id )";
          break;

        case 'email':
        case 'phone':
        case 'city':
        case 'street_address':
          $select[] = "$property as $property";
          $from[$value] = "LEFT JOIN civicrm_{$value} {$value} ON ( contact.id = {$value}.contact_id AND {$value}.is_primary = 1 ) ";
          break;

        case 'country':
        case 'state_province':
          $select[] = "{$property}.name as $property";
          if (!in_array('address', $from)) {
            $from['address'] = 'LEFT JOIN civicrm_address address ON ( contact.id = address.contact_id AND address.is_primary = 1) ';
          }
          $from[$value] = " LEFT JOIN civicrm_{$value} {$value} ON ( address.{$value}_id = {$value}.id  ) ";
          break;
      }
    }

    //finally retrieve contact details.
    if (!empty($select) && !empty($from)) {
      $fromClause   = implode(' ', $from);
      $selectClause = implode(', ', $select);
      $whereClause  = "{$compTable}.id IN (" . implode(',', $componentIds) . ')';
      $groupBy = CRM_Contact_BAO_Query::getGroupByFromSelectColumns($select, array("{$compTable}.id", 'contact.id'));
      $query = "
  SELECT  contact.id as contactId, $compTable.id as componentId, $selectClause
    FROM  $compTable as $compTable $fromClause
   WHERE  $whereClause
{$groupBy}";

      $contact = CRM_Core_DAO::executeQuery($query);
      while ($contact->fetch()) {
        $contactDetails[$contact->componentId]['contact_id'] = $contact->contactId;
        foreach ($returnProperties as $property => $ignore) {
          $contactDetails[$contact->componentId][$property] = $contact->$property;
        }
      }
      $contact->free();
    }

    return $contactDetails;
  }


  /**
   * Function to set profile defaults
   *
   * @params int     $contactId      contact id
   * @params array   $fields         associative array of fields
   * @params array   $defaults       defaults array
   *
   * @param $contactId
   * @param $fields
   * @param $defaults
   *
   * @static
   * @access public
   */
  static public function setProfileDefaults($contactId, &$fields, &$defaults, $grantId) {

    //get the contact details
    list($contactDetails, $options) = CRM_Contact_BAO_Contact::getHierContactDetails($contactId, $fields);
    $details = CRM_Utils_Array::value($contactId, $contactDetails);
    $multipleFields = array('website' => 'url');

    //start of code to set the default values
    foreach ($fields as $name => $field) {
      // skip pseudo fields
      if (substr($name, 0, 9) == 'phone_ext'
        || !in_array($field['field_type'], array('Individual', 'Organization', 'Household', 'Contact'))) {
        continue;
      }

      $fldName = "field[$grantId][$name]";

      if ($name == 'group') {
        CRM_Contact_Form_Edit_TagsAndGroups::setDefaults($contactId, $defaults, CRM_Contact_Form_Edit_TagsAndGroups::GROUP, $fldName);
      }
      if ($name == 'tag') {
        CRM_Contact_Form_Edit_TagsAndGroups::setDefaults($contactId, $defaults, CRM_Contact_Form_Edit_TagsAndGroups::TAG, $fldName);
      }

      if (!empty($details[$name]) || isset($details[$name])) {
        //to handle custom data (checkbox) to be written
        // to handle birth/deceased date, greeting_type and few other fields
        if (($name == 'birth_date') || ($name == 'deceased_date')) {
          list($defaults[$fldName]) = CRM_Utils_Date::setDateDefaults($details[$name], 'birth');
        }
        elseif (in_array($name, CRM_Contact_BAO_Contact::$_greetingTypes)) {
          $defaults[$fldName] = $details[$name . '_id'];
          $defaults[$name . '_custom'] = $details[$name . '_custom'];
        }
        elseif ($name == 'preferred_communication_method') {
          $v = explode(CRM_Core_DAO::VALUE_SEPARATOR, $details[$name]);
          foreach ($v as $item) {
            if ($item) {
              $defaults[$fldName . "[$item]"] = 1;
            }
          }
        }
        elseif ($name == 'world_region') {
          $defaults[$fldName] = $details['worldregion_id'];
        }
        elseif ($customFieldId = CRM_Core_BAO_CustomField::getKeyID($name)) {
          //fix for custom fields
          $customFields = CRM_Core_BAO_CustomField::getFields(CRM_Utils_Array::value('contact_type', $details));

          switch ($customFields[$customFieldId]['html_type']) {
            case 'Multi-Select State/Province':
            case 'Multi-Select Country':
            case 'AdvMulti-Select':
            case 'Multi-Select':
              $v = explode(CRM_Core_DAO::VALUE_SEPARATOR, $details[$name]);
              foreach ($v as $item) {
                if ($item) {
                  $defaults[$fldName][$item] = $item;
                }
              }
            break;

            case 'CheckBox':
              $v = explode(CRM_Core_DAO::VALUE_SEPARATOR, $details[$name]);
              foreach ($v as $item) {
                if ($item) {
                  $defaults[$fldName][$item] = 1;
                  // seems like we need this for QF style checkboxes in profile where its multiindexed
                  // CRM-2969
                  $defaults["{$fldName}[{$item}]"] = 1;
                }
              }
            break;

            case 'Select Date':
              // CRM-6681, set defult values according to date and time format (if any).
              $dateFormat = NULL;
              if (!empty($customFields[$customFieldId]['date_format'])) {
                $dateFormat = $customFields[$customFieldId]['date_format'];
              }

              if (empty($customFields[$customFieldId]['time_format'])) {
                list($defaults[$fldName]) = CRM_Utils_Date::setDateDefaults($details[$name], NULL,
                  $dateFormat
                );
              }
              else {
                $timeElement = $fldName . '_time';
                if (substr($fldName, -1) == ']') {
                  $timeElement = substr($fldName, 0, -1) . '_time]';
                }
                list($defaults[$fldName], $defaults[$timeElement]) = CRM_Utils_Date::setDateDefaults($details[$name],
                  NULL, $dateFormat, $customFields[$customFieldId]['time_format']);
                }
            break;

            default:
              $defaults[$fldName] = $details[$name];
            break;
          }
        }
        else {
          $defaults[$fldName] = $details[$name];
        }
      }
      else {
        $blocks = array('email', 'phone', 'im', 'openid');
        list($fieldName, $locTypeId, $phoneTypeId) = CRM_Utils_System::explode('-', $name, 3);
        if (!in_array($fieldName, $multipleFields)) {
          if (is_array($details)) {
            foreach ($details as $key => $value) {
              // when we fixed CRM-5319 - get primary loc
              // type as per loc field and removed below code.
              $primaryLocationType = FALSE;
              if ($locTypeId == 'Primary') {
                if (is_array($value) && array_key_exists($fieldName, $value)) {
                  $primaryLocationType = TRUE;
                  if (in_array($fieldName, $blocks)) {
                    $locTypeId = CRM_Contact_BAO_Contact::getPrimaryLocationType($contactId, FALSE, $fieldName);
                  }
                  else {
                    $locTypeId = CRM_Contact_BAO_Contact::getPrimaryLocationType($contactId, FALSE, 'address');
                  }
                }
              }

              // fixed for CRM-665
              if (is_numeric($locTypeId)) {
                if ($primaryLocationType || $locTypeId == CRM_Utils_Array::value('location_type_id', $value)) {
                  if (!empty($value[$fieldName])) {
                    //to handle stateprovince and country
                    if ($fieldName == 'state_province') {
                      $defaults[$fldName] = $value['state_province_id'];
                    }
                    elseif ($fieldName == 'county') {
                      $defaults[$fldName] = $value['county_id'];
                    }
                    elseif ($fieldName == 'country') {
                      if (!isset($value['country_id']) || !$value['country_id']) {
                        $config = CRM_Core_Config::singleton();
                        if ($config->defaultContactCountry) {
                          $defaults[$fldName] = $config->defaultContactCountry;
                        }
                      }
                      else {
                        $defaults[$fldName] = $value['country_id'];
                      }
                    }
                    elseif ($fieldName == 'phone') {
                      if ($phoneTypeId) {
                        if (isset($value['phone'][$phoneTypeId])) {
                          $defaults[$fldName] = $value['phone'][$phoneTypeId];
                        }
                        if (isset($value['phone_ext'][$phoneTypeId])) {
                          $defaults[str_replace('phone', 'phone_ext', $fldName)] = $value['phone_ext'][$phoneTypeId];
                        }
                      }
                      else {
                        $phoneDefault = CRM_Utils_Array::value('phone', $value);
                        // CRM-9216
                        if (!is_array($phoneDefault)) {
                          $defaults[$fldName] = $phoneDefault;
                        }
                      }
                    }
                    elseif ($fieldName == 'email') {
                      //adding the first email (currently we don't support multiple emails of same location type)
                      $defaults[$fldName] = $value['email'];
                    }
                    elseif ($fieldName == 'im') {
                      //adding the first im (currently we don't support multiple ims of same location type)
                      $defaults[$fldName] = $value['im'];
                      $defaults[$fldName . '-provider_id'] = $value['im_provider_id'];
                    }
                    else {
                      $defaults[$fldName] = $value[$fieldName];
                    }
                  }
                  elseif (substr($fieldName, 0, 14) === 'address_custom' &&
                          CRM_Utils_Array::value(substr($fieldName, 8), $value)
                          ) {
                    $defaults[$fldName] = $value[substr($fieldName, 8)];
                  }
                }
              }
            }
          }
        }
        else {
          if (is_array($details)) {
            if ($fieldName === 'url'
                && !empty($details['website'])
                && !empty($details['website'][$locTypeId])) {
              $defaults[$fldName] = CRM_Utils_Array::value('url', $details['website'][$locTypeId]);
            }
          }
        }
      }
    }
  }

  static public function getProfileTypes($profileId, $grantIds) {
    $totalGrantType = count(CRM_Core_PseudoConstant::get('CRM_Grant_DAO_Grant', 'grant_type_id'));
    $sql = "SELECT ccg.extends_entity_column_value FROM civicrm_uf_field cuf
INNER JOIN civicrm_custom_field ccf ON ccf.id = REPLACE(cuf.field_name, 'custom_', '')
INNER JOIN civicrm_custom_group ccg ON ccg.id = ccf.custom_group_id
WHERE cuf.uf_group_id = {$profileId} AND ccg.extends LIKE 'Grant' and cuf.is_active = 1
GROUP BY ccg.id";
    $dao = CRM_Core_DAO::executeQuery($sql);
    $grantTypes = $commonGrantType = $gts = array();
    while ($dao->fetch()) {
      if ($dao->extends_entity_column_value) {
        $gTypes = array_filter(explode(CRM_Core_DAO::VALUE_SEPARATOR, $dao->extends_entity_column_value));
        if (count($gTypes) == $totalGrantType) {
          continue;
        }
        $gts[] = $gTypes;
        $commonGrantType = array_intersect(array_merge($commonGrantType, $grantTypes), $gTypes);
        $grantTypes = array_merge($grantTypes, $gTypes);
      }
    }

    $groupByClause = array();
    if (!empty($grantTypes)) {
      $groupByClause[] = 'grant_type_id';
    }
    $dao = CRM_Core_DAO::executeQuery("SELECT GROUP_CONCAT(field_type) field_type FROM civicrm_uf_field WHERE uf_group_id = {$profileId}
      AND field_type IN ('Individual', 'Organization', 'Household') AND is_active = 1");

    $contactTypes = array();
    if ($dao->fetch()) {
      $groupByClause[] = 'contact_type';
      $contactTypes = $dao->field_type ? explode(',', $dao->field_type) : array();
    }

    $query = 'SELECT cc.contact_type, ANY_VALUE(cg.grant_type_id) FROM civicrm_grant cg INNER JOIN civicrm_contact cc ON cc.id = cg.contact_id WHERE cg.id IN (' . implode(',', $grantIds) . ') ';
    if (!empty($groupByClause)) {
      $query .= ' GROUP BY ' . implode(',', $groupByClause);
    }
    else {
      return FALSE;
    }
    $result = CRM_Core_DAO::executeQuery($query);

    if ($result->N > 1) {
      if (count(array_unique($gts, SORT_REGULAR)) > 1) {
        return TRUE;
      }
      $ignoreFlag = FALSE;
      if (!empty($grantTypes)) {
        $ignoreFlag = TRUE;
        while ($result->fetch()) {
          if (!in_array($result->grant_type_id, $grantTypes)) {
            $ignoreFlag = FALSE;
            break;
          }
        }
      }
      if (!$ignoreFlag) {
        return TRUE;
      }
    }
    elseif ($result->N == 1) {
      $result->fetch();
      if ((!empty($contactTypes) && !in_array($result->contact_type, $contactTypes))
        || (!empty($grantTypes) && (!in_array($result->grant_type_id, $grantTypes) || (!empty($commonGrantType) && !in_array($result->grant_type_id, $commonGrantType))))
      ) {
        return TRUE;
      }
    }
    return FALSE;
  }

  static public function getGrantFields() {
    $exportableFields = self::exportableFields('Grant');

    $skipFields = array('grant_id', 'grant_contact_id');
    foreach ($skipFields as $field) {
      if (isset($exportableFields[$field])) {
        unset($exportableFields[$field]);
      }
    }
    return $exportableFields;
  }

  static public function exportableFields() {
    $grantFields = array(
      'grant_status_id' => array(
        'title' => ts('Grant Status'),
        'name' => 'grant_status',
        'data_type' => CRM_Utils_Type::T_STRING,
      ),
      'amount_requested' => array(
        'title' => ts('Grant Amount Requested'),
        'name' => 'grant_amount_requested',
        'where' => 'civicrm_grant.amount_requested',
        'data_type' => CRM_Utils_Type::T_FLOAT,
      ),
      'grant_note' => array(
        'title' => ts('Grant Note'),
        'name' => 'grant_note',
        'data_type' => CRM_Utils_Type::T_TEXT,
      ),
    );

    $fields = CRM_Grant_DAO_Grant::export();
    $fields = array_merge($fields, $grantFields,
      CRM_Core_BAO_CustomField::getFieldsForImport('Grant'),
      CRM_Financial_DAO_FinancialType::export()
    );
    return $fields;
  }

  /**
   * Function to check if related Grant extension is enabled/disabled
   *
   * return array of enabled extensions
   */
  static public function checkRelatedExtensions() {
    $enableDisable = NULL;
    $sql = "SELECT is_active FROM civicrm_extension WHERE full_name IN ('biz.jmaconsulting.grantapplications')";
    $enableDisable = CRM_Core_DAO::singleValueQuery($sql);
    return $enableDisable;
  }

}
