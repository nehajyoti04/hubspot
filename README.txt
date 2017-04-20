CONTENTS OF THIS FILE
---------------------

 * Introduction
 * Installation
 * Configuration
 * Maintainers

INTRODUCTION
------------


INSTALLATION
--------------------------

Install the module like any other Drupal module.

You can find detailed help on how to use HubSpot with Webforms in this
module's Help page in the Drupal administrator interface. Head to Help and
select "HubSpot Drupal integration" for complete instructions.

Extended documentation, with instructions on using HubSpot's Salesforce
integration and details on automated receipt emails, is available in the Drupal
handbook pages:

http://drupal.org/node/1195370


CONFIGURATION
--------------------------

- For Contact form submission
1.GO to hubspot site and  Go to contacts -> Forms(can use test accounts)
2. Add fields in the form.
In Drupal site:
* Create a new webform OR use exisiting contact webform.
* Add fields in the contact form.
* Create a new node of webform type created above.
* Go to admin/structure/webform/manage/{webform_type}/handlers. eg : admin/structure/webform/manage/test_1/handlers
* Go to admin/structure/webform/manage/ i.e structure => webforms => your webform type (eg: contact)
=> handler tab (admin/structure/webform/manage/contact/handlers)
* Add hubspot Webform handler(Mandatory for contact form submissions).
* Map form fields in the drupal form to hubspot form on configuration page.

- To view your form submissions click on the link - https://app.hubspot.com/l/forms

For hubspot tracking code : refer this : https://knowledge.hubspot.com/articles/KCS_Article/Reports/How-do-I-install-the-HubSpot-tracking-code-on-my-Drupal-site


- For Web analytics
1. Enable your tracking code in the configuraton
2. Go to https://app.hubspot.com/reports-dashboard/{portalid}/web-analytics(Your portal id).
3. There is a list of analysis report like session, Traffic metrics etc available for your site.


- Lead Flow
* Login to hubspot account-> GO to marketing profile and in the navigation click on Lead Flows.
* Click on Create Lead Flow button -> Add the lead flow as desired.
* To Enable Lead Flow in drupal site, go to admin configuration form and check Tracking Code on.


- Event ( a)Clicked  b) View)
Site visits - https://app.hubspot.com/reports/3089539/events/000002042577/#/assists/content/completions/?range=1M&frequency=weekly&start=04/01/17&end=04/19/17


configuration :
- Go to reports -> reports settings  in hubspot site , copy tracking code.


2. View unique contacts - https://app.hubspot.com/sales/3089539/contacts/list/view/all/?


Maintainers
---------------------

nehajyoti (Jyoti Bohra)



Requirements
------------



Installation Information
-------------------


---




