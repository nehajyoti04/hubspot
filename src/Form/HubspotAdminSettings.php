<?php

/**
 * @file
 * Contains \Drupal\hubspot\Form\HubspotAdminSettings.
 */

namespace Drupal\hubspot\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\webform\Plugin\Field\FieldType\WebformEntityReferenceItem;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

class HubspotAdminSettings extends FormBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * Stores the configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  protected $entityTypeManager;

  /**
   * HubspotAdminSettings constructor.
   * @param \Drupal\Core\Database\Connection $connection
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   * @param \Drupal\Core\Entity\EntityTypeManager $entityTypeManager
   */
  function __construct(Connection $connection, ConfigFactoryInterface $config_factory, EntityTypeManager $entityTypeManager) {
    $this->connection = $connection;
    $this->configFactory = $config_factory->getEditable('hubspot.settings');
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('config.factory'),
      $container->get('entity_type.manager')
    );
  }


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
      '#title' => $this->t('Connectivity'),
      '#type' => 'details',
      '#group' => 'additional_settings',
    ];

    $form['settings']['hubspot_portalid'] = [
      '#title' => $this->t('HubSpot Portal ID'),
      '#type' => 'textfield',
      '#required' => TRUE,
      '#default_value' => $this->configFactory->get('hubspot_portalid'),
      '#description' => $this->t('Enter the Hubspot Portal ID for this site.  It can be found by
      <a href="https://login.hubspot.com/login" target="_blank">logging into HubSpot</a> going to the Dashboard and
      examining the url. Example: "https://app.hubspot.com/dashboard-plus/12345/dash/".  The number after
      "dashboard-plus" is your Portal ID.'),
    ];

    if ($this->configFactory->get('hubspot_portalid')) {
      $form['settings']['hubspot_authentication'] = [
        '#value' => $this->t('Connect Hubspot Account'),
        '#type' => 'submit',
        '#submit' => array([$this, 'hubspotOauthSubmitForm']),
      ];

      if ($this->configFactory->get('hubspot_refresh_token')) {
        $form['settings']['hubspot_authentication']['#suffix'] = $this->t('Your Hubspot account is connected.');
        $form['settings']['hubspot_authentication']['#value'] = $this->t('Disconnect Hubspot Account');
        $form['settings']['hubspot_authentication']['#submit'] = array([$this, 'hubspotOauthDisconnect']);
      }
    }

    $form['settings']['hubspot_log_code'] = [
      '#title' => $this->t('HubSpot Traffic Logging Code'),
      '#type' => 'textarea',
      '#default_value' => $this->configFactory->get('hubspot_log_code'),
      '#description' => $this->t('To enable HubSpot traffic logging on your site, paste the External Site Traffic Logging code
      here.'),
    ];

    $form['debug'] = [
      '#title' => $this->t('Debugging'),
      '#type' => 'details',
      '#group' => 'additional_settings',
    ];

    $form['debug']['hubspot_debug_on'] = [
      '#title' => $this->t('Debugging enabled'),
      '#type' => 'checkbox',
      '#default_value' => $this->configFactory->get('hubspot_debug_on'),
      '#description' => $this->t('If debugging is enabled, HubSpot errors will be emailed to the address below. Otherwise, they
      will be logged to the regular Drupal error log.'),
    ];

    // @FIXME
    // Could not extract the default value because it is either indeterminate, or
    // not scalar. You'll need to provide a default value in
    // config/install/hubspot.settings.yml and config/schema/hubspot.schema.yml.
    $form['debug']['hubspot_debug_email'] = [
      '#title' => $this->t('Debugging email'),
      '#type' => 'email',
      '#default_value' => $this->configFactory->get('hubspot_debug_email'),
      '#description' => $this->t('Email error reports to this address if debugging is enabled.'),
    ];

    // Mapping hubspot and webform/ webform node forms.
    $hubspot_form_description  = '';
    $hubspot_forms = _hubspot_get_forms();
    if (isset($hubspot_forms['error'])) {
      $hubspot_form_description =  $hubspot_forms['error'];
    }
    else {
      if (empty($hubspot_forms['value'])) {
        $hubspot_form_description =  $this->t('No HubSpot forms found. You will need to create a form on HubSpot before you can configure it here.');
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
      }
    }

    $form['webforms'] = [
      '#title' => $this->t('Webforms'),
      '#type' => 'details',
      '#group' => 'additional_settings',
      '#description' => $this->t('The following webforms have been detected
       and can be configured to submit to the HubSpot API.'),
      '#tree' => TRUE,
    ];

    $form['webforms']['#description'] = $hubspot_form_description;

    if (!isset($hubspot_forms['error'])) {
      if (!empty($hubspot_forms['value'])) {

        $webform_types = \Drupal::service('entity.manager')->getStorage('webform')->loadMultiple();

        foreach ($webform_types as $webform_type) {
          $webform_type_id = $webform_type->id();
          $webform_type_label = $webform_type->label();
          $form['webforms'][$webform_type_id] = [
            '#title' => $webform_type_label,
            '#type' => 'details',
          ];
          $form['webforms'][$webform_type_id]['hubspot_form'] = [
            '#title' => $this->t('HubSpot form'),
            '#type' => 'select',
            '#options' => $hubspot_form_options,
            '#default_value' => _hubspot_default_value($webform_type_id),
          ];

          foreach ($hubspot_form_options as $key => $value) {
            if ($key != '--donotmap--') {
              $form['webforms'][$webform_type_id][$key] = [
                '#title' => $this->t('Field mappings for @field', [
                  '@field' => $value
                ]),
                '#type' => 'details',
                '#states' => [
                  'visible' => [
                    ':input[name="webforms[' . $webform_type_id . '][hubspot_form]"]' => [
                      'value' => $key
                    ]
                  ]
                ],
              ];

              $webform = $this->entityTypeManager->getStorage('webform')->load($webform_type_id);
              $webform = $webform->getElementsDecoded();

              foreach ($webform as $form_key => $component) {
                if ($component['#type'] !== 'markup') {
                  $form['webforms'][$webform_type_id][$key][$form_key] = [
                    '#title' => $component['#title'] . ' (' . $component['#type'] . ')',
                    '#type' => 'select',
                    '#options' => $hubspot_field_options[$key]['fields'],
                    '#default_value' => _hubspot_default_value($webform_type_id, $key, $form_key),
                  ];
                }
              }
            }
          }
        }
      }
    }

    // Webform node forms mapping.

    $form['webform_nodes'] = [
      '#title' => $this->t('Webform Nodes'),
      '#type' => 'details',
      '#group' => 'additional_settings',
      '#description' => $this->t('The following webform Nodes have been detected
       and can be configured to submit to the HubSpot API.(Note: Webform Nodes module needs to enabled, to create node webforms.)'),
      '#tree' => TRUE,
    ];
    $form['webform_nodes']['#description']  = $hubspot_form_description;

    if (!isset($hubspot_forms['error'])) {
      if (!empty($hubspot_forms['value'])) {

        $nodes =  $this->connection->select('node', 'n')
          ->fields('n', ['nid'])
          ->condition('type', 'webform')
          ->execute()->fetchAll();

        foreach ($nodes as $node) {
          $nid = $node->nid;
          $form['webform_nodes']['nid-' . $nid] = [
            '#title' => Node::load($nid)->getTitle(),
            '#type' => 'details',
          ];

          $form['webform_nodes']['nid-' . $nid]['hubspot_form'] = [
            '#title' => $this->t('HubSpot form'),
            '#type' => 'select',
            '#options' => $hubspot_form_options,
            '#default_value' => _hubspot_default_value($nid),
          ];

          foreach ($hubspot_form_options as $key => $value) {
            if ($key != '--donotmap--') {
              $form['webform_nodes']['nid-' . $nid][$key] = [
                '#title' => $this->t('Field mappings for @field', [
                  '@field' => $value
                ]),
                '#type' => 'details',
                '#states' => [
                  'visible' => [
                    ':input[name="webform_nodes[nid-' . $nid . '][hubspot_form]"]' => [
                      'value' => $key
                    ]
                  ]
                ],
              ];

              $node = Node::load($nid);
              $webform_field_name = WebformEntityReferenceItem::getEntityWebformFieldName($node);
              $webform_id = $node->$webform_field_name->target_id;

              $webform = $this->entityTypeManager->getStorage('webform')->load($webform_id);
              $webform = $webform->getElementsDecoded();

              foreach ($webform as $form_key => $component) {
                if ($component['#type'] !== 'markup') {
                  $form['webform_nodes']['nid-' . $nid][$key][$form_key] = [
                    '#title' => $component['#title'] . ' (' . $component['#type'] . ')',
                    '#type' => 'select',
                    '#options' => $hubspot_field_options[$key]['fields'],
                    '#default_value' => _hubspot_default_value($nid, $key, $form_key),
                  ];
                }

              }
            }
          }
        }
      }
    }

    $form['tracking_code'] = [
      '#title' => $this->t('Tracking Code'),
      '#type' => 'details',
      '#group' => 'additional_settings',
    ];

    $form['tracking_code']['tracking_code_on'] = [
      '#title' => $this->t('Enable Tracking Code'),
      '#type' => 'checkbox',
      '#default_value' => $this->configFactory->get('tracking_code_on'),
      '#description' => $this->t('If Tracking code is enabled, Javascript
      tracking will be inserted in all/specified pages of the site as configured
       in hubspot account.'),
    ];


    $form['submit'] = [
      '#type' => 'submit',
      '#value' => ('Save Configuration'),
    ];

    return $form;
  }

  /**
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   * @throws \Exception
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->configFactory->set('hubspot_portalid', $form_state->getValue('hubspot_portalid'))
      ->set('hubspot_debug_email', $form_state->getValue('hubspot_debug_email'))
      ->set('hubspot_debug_on', $form_state->getValue('hubspot_debug_on'))
      ->set('hubspot_log_code', $form_state->getValue(['hubspot_log_code']))
      ->set('tracking_code_on', $form_state->getValue(['tracking_code_on']))
      ->save();


    // Check if webform values even exist before continuing.
    if (!$form_state->getValue('webforms')) {

      foreach ($form_state->getValue('webforms') as $key => $settings) {
        $this->connection->delete('hubspot')->condition('id', $key)->execute();

        if ($settings['hubspot_form'] != '--donotmap--') {
          foreach ($settings[$settings['hubspot_form']] as $webform_field => $hubspot_field) {
            $fields = [
              'nid' => $key,
              'hubspot_guid' => $settings['hubspot_form'],
              'webform_field' => $webform_field,
              'hubspot_field' => $hubspot_field,
            ];
            $this->connection->insert('hubspot')->fields($fields)->execute();
          }
        }
      }
    }
    else {
      // Insert entry.
      foreach ($form_state->getValue('webforms') as $key => $settings) {
        $this->connection->delete('hubspot')->condition('id', $key)->execute();
        if ($settings['hubspot_form'] != '--donotmap--') {
          foreach ($settings[$settings['hubspot_form']] as $webform_field => $hubspot_field) {
            $fields = [
              'id' => $key,
              'hubspot_guid' => $settings['hubspot_form'],
              'webform_field' => $webform_field,
              'hubspot_field' => $hubspot_field,
            ];
            $this->connection->insert('hubspot')->fields($fields)->execute();
          }
        }
      }
    }

    // Check if webform values even exist before continuing.
    if (!$form_state->getValue('webform_nodes')) {

      foreach ($form_state->getValue('webform_nodes') as $key => $settings) {
        $this->connection->delete('hubspot')->condition('id', str_replace('nid-', '', $key))->execute();

        if ($settings['hubspot_form'] != '--donotmap--') {
          foreach ($settings[$settings['hubspot_form']] as $webform_field => $hubspot_field) {
            $fields = [
              'id' => str_replace('nid-', '', $key),
              'hubspot_guid' => $settings['hubspot_form'],
              'webform_field' => $webform_field,
              'hubspot_field' => $hubspot_field,
            ];
            $this->connection->insert('hubspot')->fields($fields)->execute();
          }
        }
      }
    }
    else {
      // Insert entry.
      foreach ($form_state->getValue('webform_nodes') as $key => $settings) {
        $this->connection->delete('hubspot')->condition('id', str_replace('nid-', '', $key))->execute();
        if ($settings['hubspot_form'] != '--donotmap--') {
          foreach ($settings[$settings['hubspot_form']] as $webform_field => $hubspot_field) {
            $fields = [
              'id' => str_replace('nid-', '', $key),
              'hubspot_guid' => $settings['hubspot_form'],
              'webform_field' => $webform_field,
              'hubspot_field' => $hubspot_field,
            ];
            $this->connection->insert('hubspot')->fields($fields)->execute();
          }
        }
      }


    }


    drupal_set_message($this->t('The configuration options have been saved.'));
  }

  /**
   * Form submission handler for hubspot_admin_settings().
   */
  public function hubspotOauthSubmitForm(array &$form, FormStateInterface $form_state) {
    global $base_url;
    $options = array(
      'query' => [
        'client_id' => HUBSPOT_CLIENT_ID,
        'portalId' => $this->configFactory->get('hubspot_portalid'),
        'redirect_uri' => $base_url . Url::fromRoute('hubspot.oauth_connect')->toString(),
        'scope' => HUBSPOT_SCOPE,
      ]
    );
    $redirect_url = Url::fromUri('https://app.hubspot.com/auth/authenticate', $options)->toString();

    $response = new RedirectResponse($redirect_url);
    $response->send();
    return $response;
  }

  /**
   * Form submit handler.
   *
   * Deletes Hubspot OAuth tokens.
   */
  public function hubspotOauthDisconnect(array &$form, FormStateInterface $form_state) {
    $this->configFactory->clear('hubspot_refresh_token')->save();
  }

}
