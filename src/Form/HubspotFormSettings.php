<?php

/**
 * @file
 * Contains \Drupal\hubspot\Form\HubspotFormSettings.
 */

namespace Drupal\hubspot\Form;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;

class HubspotFormSettings extends FormBase {

  protected $http_client;

  public function __construct(Client $client) {
    $this->http_client = $client;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('http_client')
    );
  }



  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'hubspot_form_settings';
  }

  public function buildForm(array $form, \Drupal\Core\Form\FormStateInterface $form_state, $node = NULL) {
    $form = [];

    $hubspot_forms = $this->_hubspot_get_forms();
//    print '<pre>'; print_r("hubspot forms"); print '</pre>';
//    print '<pre>'; print_r($hubspot_forms); print '</pre>';exit;

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
//        kint("values");
//        kint($hubspot_forms['value']);
        foreach ($hubspot_forms['value'] as $hubspot_form) {
          $hubspot_form_options[$hubspot_form['guid']] = $hubspot_form['name'];
          $hubspot_field_options[$hubspot_form['guid']]['fields']['--donotmap--'] = "Do Not Map";

          foreach ($hubspot_form['fields'] as $hubspot_field) {
            $hubspot_field_options[$hubspot_form['guid']]['fields'][$hubspot_field['name']] = ($hubspot_field['label'] ? $hubspot_field['label'] : $hubspot_field['name']) . ' (' . $hubspot_field['fieldType'] . ')';
          }
        }


//        $nid = $node->nid->value;
        $nid = $node;
//        kint($node);



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
//          dpm("value");
//          dpm($value);
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

            $webform = \Drupal::entityTypeManager()->getStorage('webform')->load('test_1');
//            $webform = $webform->getSubmissionForm();
            $webform = $webform->getElementsDecoded();

            $submission_storage = \Drupal::entityTypeManager()->getStorage('webform_submission');
            $field_definitions = $submission_storage->getFieldDefinitions();
//            kint("webform");
//            kint($webform);



//            print '<pre>'; print_r("node"); print '</pre>';
//            print '<pre>'; print_r($webform); print '</pre>';exit;

//            print '<pre>'; print_r("node"); print '</pre>';
//    print '<pre>'; print_r($node->webform); print '</pre>';exit;

            foreach ($webform as $form_key => $component) {
//              dpm("component type");
//              dpm($component['#type']);
//              dpm("form key");
//              dpm($form_key);
//              dpm("key");
//              dpm($key);


//              kint("component");
//              kint($component);
              if ($component['#type'] == 'addressfield' && \Drupal::moduleHandler()->moduleExists('addressfield_tokens')) {
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

//    return parent::buildForm($form, $form_state);

    return $form;
  }

  public function submitForm(array &$form, \Drupal\Core\Form\FormStateInterface $form_state) {
    $txn = db_transaction();

    db_delete('hubspot')->condition('nid', $form_state->getValue(['nid']))->execute();

    if ($form_state->getValue(['hubspot_form']) != '--donotmap--') {
      foreach ($form_state->getValue([$form_state->getValue('hubspot_form')]) as $webform_field => $hubspot_field) {
        $fields = [
          'nid' => $form_state->getValue(['nid']),
          'hubspot_guid' => $form_state->getValue(['hubspot_form']),
          'webform_field' => $webform_field,
          'hubspot_field' => $hubspot_field,
        ];
        db_insert('hubspot')->fields($fields)->execute();
      }
    }

    drupal_set_message(t('The configuration options have been saved.'));
  }

  /**
   * Gets the list of forms from HubSpot via the API.
   */
  function _hubspot_get_forms() {
    $access_token = \Drupal::config('hubspot.settings')->get('hubspot_access_token');

//  print '<pre>'; print_r("access token"); print '</pre>';
//  print '<pre>'; print_r($access_token); print '</pre>';exit;

    if (empty($access_token)) {
      return array('error' => t('This site is not connected to a HubSpot Account.'));
    }

    // @FIXME
// drupal_http_request() has been replaced by the Guzzle HTTP client, which is bundled
// with Drupal core.
//
//
// @see https://www.drupal.org/node/1862446
// @see http://docs.guzzlephp.org/en/latest
// $request = drupal_http_request("https://api.hubapi.com/contacts/v1/forms?access_token={$access_token}");

    $api = 'https://api.hubapi.com/contacts/v1/forms';
    $options = [
      'query' => ['access_token' => $access_token],
    ];
    $url = Url::fromUri($api, $options)->toString();
    $url = 'https://api.hubapi.com/contacts/v1/forms?access_token=efcdd37d-6b4f-4eb1-8fcd-26eaaa60845e';
    try {
//    print '<pre>'; print_r("url"); print '</pre>';
//    print '<pre>'; print_r($url); print '</pre>';exit;

//      $client = new Client;

//      $response = $client->get($url);


      $client = \Drupal::httpClient();
//      $request = $client->createRequest('GET', $url);
      $response = \Drupal::httpClient()->get($url);

//      print '<pre>'; print_r("response"); print '</pre>';
//      print '<pre>'; print_r($request); print '</pre>';exit;
//
//    $response = $this->http_client->get($url);
//    print '<pre>'; print_r("response"); print '</pre>';
//    print '<pre>'; print_r($response); print '</pre>';exit;
      $code = $response->getStatusCode();

      $data = (string) $response->getBody();
      return array('value' => Json::decode($data));
//      print '<pre>'; print_r("data"); print '</pre>';
//      print '<pre>'; print_r($data); print '</pre>';exit;

      $res = json_decode($response->getBody(), true)['items'][0];
      print '<pre>'; print_r("response"); print '</pre>';
      print '<pre>'; print_r($res); print '</pre>';exit;
      $volume_info = $res['volumeInfo'];
      $title = $res['volumeInfo']['title'];
      $subtitle = $res['volumeInfo']['subtitle'];
      $authors = $res['volumeInfo']['authors'][0];
      $publishedDate = $volume_info['publishedDate'];
      $description = $volume_info['description'];
      $build = [
        '#theme' => 'item_list',
        '#items' => [
          $title,
          $subtitle,
          $authors,
          $publishedDate,
          $description
        ]
      ];

    }
    catch (RequestException $e) {

    }




    if ($request->code == 401) {
      $refresh = hubspot_oauth_refresh();
      if ($refresh) {
        $access_token = \Drupal::config('hubspot.settings')->get('hubspot_access_token');
        // @FIXME
// drupal_http_request() has been replaced by the Guzzle HTTP client, which is bundled
// with Drupal core.
//
//
// @see https://www.drupal.org/node/1862446
// @see http://docs.guzzlephp.org/en/latest
// $request = drupal_http_request("https://api.hubapi.com/contacts/v1/forms?access_token={$access_token}");

      }
    }

    return array('value' => \Drupal\Component\Serialization\Json::decode($request->data));
  }

}
