<?php

/**
 * @file
 * Contains \Drupal\hubspot\Form\HubspotFormSettings.
 */

namespace Drupal\hubspot\Form;


use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Extension\ModuleHandler;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use GuzzleHttp\Client;
use Symfony\Component\DependencyInjection\ContainerInterface;

class HubspotFormSettings extends FormBase {

  protected $http_client;

  protected $entityTypeManager;

  protected $moduleHandler;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  public function __construct(Client $client, EntityTypeManager $entityTypeManager, ModuleHandler $moduleHandler, Connection $database) {
    $this->http_client = $client;
    $this->entityTypeManager = $entityTypeManager;
    $this->moduleHandler = $moduleHandler;
    $this->database = $this->database;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('http_client'),
      $container->get('entity_type.manager'),
      $container->get('module_handler'),
      $container->get('database')
    );
  }



  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'hubspot_form_settings';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $node = NULL) {
    $form = [];

    $hubspot_forms = _hubspot_get_forms();

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
            $hubspot_field_options[$hubspot_form['guid']]['fields'][$hubspot_field['name']] = ($hubspot_field['label'] ? $hubspot_field['label'] : $hubspot_field['name']) . ' (' . $hubspot_field['fieldType'] . ')';
          }
        }

        $nid = $node;
        $form['nid'] = [
          '#type' => 'hidden',
          '#value' => $nid,
        ];

        $form['hubspot_form'] = [
          '#title' => t('HubSpot form'),
          '#type' => 'select',
          '#options' => $hubspot_form_options,
          '#default_value' => _hubspot_default_value($nid),
        ];

        foreach ($hubspot_form_options as $key => $value) {
          if ($key != '--donotmap--') {
            $form[$key] = [
              '#title' => t('Field mappings for @field', array(
                '@field' => $value
              )),
              '#type' => 'details',
              '#tree' => TRUE,
              '#states' => [
                'visible' => [
                  ':input[name="hubspot_form"]' => [
                    'value' => $key
                  ]
                ]
              ],
            ];

            $webform = $this->entityTypeManager->getStorage('webform')->load('test_1');
            $webform = $webform->getElementsDecoded();

            $submission_storage = $this->entityTypeManager->getStorage('webform_submission');

            foreach ($webform as $form_key => $component) {
              if ($component['#type'] == 'addressfield' && $this->moduleHandler->moduleExists('addressfield_tokens')) {
                $addressfield_fields = addressfield_tokens_components();

                foreach ($addressfield_fields as $addressfield_key => $addressfield_value) {
                  $form[$key][$form_key . '_' . $addressfield_key] = [
                    '#title' => $component['#title'] . ': ' . $addressfield_value . ' (' . $component['#type'] . ')',
                    '#type' => 'select',
                    '#options' => $hubspot_field_options[$key]['fields'],
                    '#default_value' => _hubspot_default_value($nid, $key, $form_key . '_' . $addressfield_key),
                  ];
                }
              }

              elseif($component['#type'] !== 'markup') {

                $form[$key][$form_key] = [
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

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => ('Save Configuration'),
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $txn = db_transaction();

    $this->database->delete('hubspot')->condition('nid', $form_state->getValue(['nid']))->execute();

    if ($form_state->getValue(['hubspot_form']) != '--donotmap--') {
      foreach ($form_state->getValue([$form_state->getValue('hubspot_form')]) as $webform_field => $hubspot_field) {
        $fields = [
          'nid' => $form_state->getValue(['nid']),
          'hubspot_guid' => $form_state->getValue(['hubspot_form']),
          'webform_field' => $webform_field,
          'hubspot_field' => $hubspot_field,
        ];
        $this->database->insert('hubspot')->fields($fields)->execute();
      }
    }

    drupal_set_message(t('The configuration options have been saved.'));
  }

}
