<?php

/**
 * @file
 * Contains \Drupal\hubspot\Form\HubspotAdminSettings.
 */

namespace Drupal\hubspot\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;

class HubspotAdminSettings extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'hubspot_admin_settings';
  }

  public function buildForm(array $form, \Drupal\Core\Form\FormStateInterface $form_state) {
    $form = [];

    $form['additional_settings'] = ['#type' => 'vertical_tabs'];

    $form['settings'] = [
      '#title' => t('Connectivity'),
      '#type' => 'details',
      '#group' => 'additional_settings',
    ];

    $form['settings']['hubspot_portalid'] = [
      '#title' => t('HubSpot Portal ID'),
      '#type' => 'textfield',
      '#required' => TRUE,
      '#default_value' => \Drupal::config('hubspot.settings')->get('hubspot_portalid'),
      '#description' => t('Enter the Hubspot Portal ID for this site.  It can be found by
      <a href="https://login.hubspot.com/login" target="_blank">logging into HubSpot</a> going to the Dashboard and
      examining the url. Example: "https://app.hubspot.com/dashboard-plus/12345/dash/".  The number after
      "dashboard-plus" is your Portal ID.'),
    ];

    if (\Drupal::config('hubspot.settings')->get('hubspot_portalid')) {
      $form['settings']['hubspot_authentication'] = [
        '#value' => t('Connect Hubspot Account'),
        '#type' => 'submit',
        '#validate' => [],
        '#submit' => [
          'hubspot_oauth_submit'
          ],
      ];

      if (\Drupal::config('hubspot.settings')->get('hubspot_refresh_token')) {
        $form['settings']['hubspot_authentication']['#suffix'] = t('Your Hubspot account is connected.');
        $form['settings']['hubspot_authentication']['#value'] = t('Disconnect Hubspot Account');
        $form['settings']['hubspot_authentication']['#submit'] = [
          'hubspot_oauth_disconnect'
          ];
      }
    }

    $form['settings']['hubspot_log_code'] = [
      '#title' => t('HubSpot Traffic Logging Code'),
      '#type' => 'textarea',
      '#default_value' => \Drupal::config('hubspot.settings')->get('hubspot_log_code'),
      '#description' => t('To enable HubSpot traffic logging on your site, paste the External Site Traffic Logging code
      here.'),
    ];

    $form['debug'] = [
      '#title' => t('Debugging'),
      '#type' => 'details',
      '#group' => 'additional_settings',
    ];

    $form['debug']['hubspot_debug_on'] = [
      '#title' => t('Debugging enabled'),
      '#type' => 'checkbox',
      '#default_value' => \Drupal::config('hubspot.settings')->get('hubspot_debug_on'),
      '#description' => t('If debugging is enabled, HubSpot errors will be emailed to the address below. Otherwise, they
      will be logged to the regular Drupal error log.'),
    ];

    // @FIXME
    // Could not extract the default value because it is either indeterminate, or
    // not scalar. You'll need to provide a default value in
    // config/install/hubspot.settings.yml and config/schema/hubspot.schema.yml.
    $form['debug']['hubspot_debug_email'] = [
      '#title' => t('Debugging email'),
      '#type' => 'textfield',
      '#default_value' => \Drupal::config('hubspot.settings')->get('hubspot_debug_email'),
      '#description' => t('Email error reports to this address if debugging is enabled.'),
    ];

    $form['webforms'] = [
      '#title' => t('Webforms'),
      '#type' => 'details',
      '#group' => 'additional_settings',
      '#description' => 'The following webforms have been detected and can be configured to submit to the HubSpot API.',
      '#tree' => TRUE,
    ];

    // @FIXME
    // // @FIXME
    // // This looks like another module's variable. You'll need to rewrite this call
    // // to ensure that it uses the correct configuration object.
    // $webform_nodes = variable_get('webform_node_types', array('webform'));

    $nodes = [];

    $hubspot_forms = _hubspot_get_forms();
    // '<pre>'; print_r("hub spot forms"); print '</pre>';
    // '<pre>'; print_r($hubspot_forms); print '</pre>';exit;

    if (isset($hubspot_forms['error'])) {
      $form['webforms']['#description'] = $hubspot_forms['error'];
    }
    else {
      if (empty($hubspot_forms['value'])) {
        $form['webforms']['#description'] = t('No HubSpot forms found. You will need to create a form on HubSpot before you can configure it here.');
      }
      else {
        $hubspot_form_options = ["--donotmap--" => "Do Not Map"];
        $hubspot_field_options = [];
        foreach ($hubspot_forms['value'] as $hubspot_form) {
          $hubspot_form_options[$hubspot_form['guid']] = $hubspot_form['name'];
          $hubspot_field_options[$hubspot_form['guid']]['fields']['--donotmap--'] = "Do Not Map";
          foreach ($hubspot_form['fields'] as $hubspot_field) {
            $hubspot_field_options[$hubspot_form['guid']]['fields'][$hubspot_field['name']] = $hubspot_field['label'] . ' (' . $hubspot_field['fieldType'] . ')';
          }
        }

        foreach ($webform_nodes as $node_type) {
          $query = new EntityFieldQuery();

          $query->entityCondition('entity_type', 'node')
            ->entityCondition('bundle', $node_type)
            ->propertyCondition('status', 1);

          $result = $query->execute();

          if (isset($result['node'])) {
            $node_ids = array_keys($result['node']);
            $nodes = array_merge($nodes, \Drupal::entityManager()->getStorage('node'));
          }
        }

        foreach ($nodes as $node) {
          $nid = $node->nid;
          $form['webforms']['nid-' . $nid] = [
            '#title' => $node->title,
            '#type' => 'details',
          ];

          $form['webforms']['nid-' . $nid]['hubspot_form'] = [
            '#title' => t('HubSpot form'),
            '#type' => 'select',
            '#options' => $hubspot_form_options,
            '#default_value' => _hubspot_default_value($nid),
          ];

          foreach ($hubspot_form_options as $key => $value) {
            if ($key != '--donotmap--') {
              $form['webforms']['nid-' . $nid][$key] = [
                '#title' => t('Field mappings for @field', [
                  '@field' => $value
                  ]),
                '#type' => 'details',
                '#states' => [
                  'visible' => [
                    ':input[name="webforms[nid-' . $nid . '][hubspot_form]"]' => [
                      'value' => $key
                      ]
                    ]
                  ],
              ];

              foreach ($node->webform['components'] as $component) {
                if ($component['type'] !== 'markup') {
                  $form['webforms']['nid-' . $nid][$key][$component['form_key']] = [
                    '#title' => $component['name'] . ' (' . $component['type'] . ')',
                    '#type' => 'select',
                    '#options' => $hubspot_field_options[$key]['fields'],
                    '#default_value' => _hubspot_default_value($nid, $key, $component['form_key']),
                  ];
                }
              }
            }
          }
        }
      }
    }

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => ('Save Configuration'),
    ];

    return $form;
  }

  public function validateForm(array &$form, \Drupal\Core\Form\FormStateInterface $form_state) {
    if ($form_state->getValue(['hubspot_debug_on']) && !valid_email_address($form_state->getValue([
      'hubspot_debug_email'
      ]))) {
      $form_state->setErrorByName('hubspot_debug_email', t('You must provide a valid email address.'));
    }

  }

  public function submitForm(array &$form, \Drupal\Core\Form\FormStateInterface $form_state) {
    \Drupal::configFactory()->getEditable('hubspot.settings')->set('hubspot_portalid', $form_state->getValue(['hubspot_portalid']))->save();
    \Drupal::configFactory()->getEditable('hubspot.settings')->set('hubspot_debug_email', $form_state->getValue(['hubspot_debug_email']))->save();
    \Drupal::configFactory()->getEditable('hubspot.settings')->set('hubspot_debug_on', $form_state->getValue(['hubspot_debug_on']))->save();
    \Drupal::configFactory()->getEditable('hubspot.settings')->set('hubspot_log_code', $form_state->getValue(['hubspot_log_code']))->save();

    $txn = db_transaction();

    // Check if webform values even exist before continuing.
    if (!$form_state->getValue(['webforms'])) {
      foreach ($form_state->getValue(['webforms']) as $key => $settings) {
        db_delete('hubspot')->condition('nid', str_replace('nid-', '', $key))->execute();

        if ($settings['hubspot_form'] != '--donotmap--') {
          foreach ($settings[$settings['hubspot_form']] as $webform_field => $hubspot_field) {
            $fields = [
              'nid' => str_replace('nid-', '', $key),
              'hubspot_guid' => $settings['hubspot_form'],
              'webform_field' => $webform_field,
              'hubspot_field' => $hubspot_field,
            ];
            db_insert('hubspot')->fields($fields)->execute();
          }
        }
      }
    }

    drupal_set_message(t('The configuration options have been saved.'));
  }

}
