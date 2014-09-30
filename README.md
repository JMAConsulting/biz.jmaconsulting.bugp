biz.jmaconsulting.bugp
=====================

Batch Update of Grants via Profile
----------------------------------

This extension provides batch update functionality for grants similar that for contacts in CiviCRM core.

Up to 100 grants can be edited simultanously in a table-like grid on a webpage. 

Usage
-----

- Navigate to Grants > Find Grants.
- Select criteria for grants to edit, then click Search button.
- Select records from results to be edited.
- Select Action > Batch Updates Grants via Profile.
- Select from among the available profiles and click continue.
- Edit grants as desired.
- To make all displayed grants have the same value in one field, enter the value in the field in the top row, then click on the copy icon to left of the column's title.
- When done editing, save all rows by clicking Update Grant(s) button at the bottom of the page.

Installation
------------
* Setup Extensions Directory 
  * If you have not already done so, go to Administer >> System Settings >> Directories
    * Set an appropriate value for CiviCRM Extensions Directory. For example, for Drupal, /path/to/drupalroot/sites/all/modules/Extensions/
    * In a different window, ensure the directory exists and is readable by your web server process.
  * Click Save.
* Setup Extensions Resources URL
  * If you have not already done so, go to Administer >> System Settings >> Resource URLs
    * Beside Extension Resource URL, enter an appropriate values such as http://yourorg.org/sites/all/modules/Extensions/
  * Click Save.
* Install Grant Batch Updates extension
  * Go to Administer >> Customize Data and Screens >> Manage Extensions.
  * If you do not see Grant Batch Updates in the list of extensions, download it and unzip it into the extensions direction setup above, then return to this page.
  * Beside Grant Batch Updates, click Install.
  * Review the information, then click Install.
 
  
