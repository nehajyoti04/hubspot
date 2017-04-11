<?php

namespace Drupal\hubspot\Plugin\WebformHandler;

use Drupal;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Serialization\Yaml;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\webform\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\webform\WebformTokenManagerInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Webform submission remote post handler.
 *
 * @WebformHandler(
 *   id = "hubspot_webform_handler",
 *   label = @Translation("HubSpot Webform Handler"),
 *   category = @Translation("External"),
 *   description = @Translation("Posts webform submissions to a Hubspot form."),
 *   cardinality = \Drupal\webform\WebformHandlerInterface::CARDINALITY_UNLIMITED,
 *   results = \Drupal\webform\WebformHandlerInterface::RESULTS_PROCESSED,
 * )
 */
class HubspotWebformHandler extends WebformHandlerBase {

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The HTTP client to fetch the feed data with.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The token manager.
   *
   * @var \Drupal\webform\WebformTranslationManagerInterface
   */
  protected $tokenManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerInterface $logger, EntityTypeManagerInterface $entity_type_manager, ModuleHandlerInterface $module_handler, ClientInterface $http_client, WebformTokenManagerInterface $token_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $logger, $entity_type_manager);
    $this->moduleHandler = $module_handler;
    $this->httpClient = $http_client;
    $this->tokenManager = $token_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory')->get('webform.remote_post'),
      $container->get('entity_type.manager'),
      $container->get('module_handler'),
      $container->get('http_client'),
      $container->get('webform.token_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary() {
    $configuration = $this->getConfiguration();

    // If the saving of results is disabled clear update and delete URL.
    if ($this->getWebform()->getSetting('results_disabled')) {
      $configuration['settings']['update_url'] = '';
      $configuration['settings']['delete_url'] = '';
    }

    return [
      '#settings' => $configuration['settings'],
    ] + parent::getSummary();
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $field_names = array_keys(\Drupal::service('entity_field.manager')->getBaseFieldDefinitions('webform_submission'));
    $excluded_data = array_combine($field_names, $field_names);
    return [
      'type' => 'x-www-form-urlencoded',
      'insert_url' => '',
      'update_url' => '',
      'delete_url' => '',
      'excluded_data' => $excluded_data,
      'custom_data' => '',
      'insert_custom_data' => '',
      'update_custom_data' => '',
      'delete_custom_data' => '',
      'debug' => FALSE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);


  }

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
//    $operation = ($update) ? 'update' : 'insert';
//    $this->remotePost($operation, $webform_submission);



    /** Code by JYoti  */
//    dpm("inside");
//    $form_guid = '9355b71c-f963-4031-9c3c-4f69fd8f5874';
    $form_guid = '994db33e-47c9-419e-8819-d9c801f251c1';
//    $form_guid = 'decae340-3e0a-4dce-b344-99b5054b7fc8';
    $portal_id = '3088872';
    $api = 'https://forms.hubspot.com/uploads/form/v2/' . $portal_id . '/' . $form_guid;
//    $options = [
//      'query' => ['q' => 'isbn:'. $this->configuration['isbn']],
//    ];

    $options = [
      'query' => ['firstname' => 'Jyoti', 'lastname' => 'Bohra', 'email' => 'jyoti@qed42.com'],
    ];
    $url = Url::fromUri($api, $options)->toString();



//    $client = \Drupal::httpClient();
//    $request = $client->createRequest('GET', $url);
//    $request->addHeader(array('Content-Type' => 'application/x-www-form-urlencoded'));






    try {
//      $response = $client->get($url, [
//        'headers' => [
//          'Content-Type' => 'application/x-www-form-urlencoded',
//        ],
//      ]);
//      // Expected result.
//      // getBody() returns an instance of Psr\Http\Message\StreamInterface.
//      // @see http://docs.guzzlephp.org/en/latest/psr7.html#body
//      $data = \Drupal::httpClient()->send($response);


      $page_url = \Drupal\Core\Url::fromUserInput('/node/2', array('absolute' => TRUE))->toString();
      $hs_context = array(
        'hutk' => isset($_COOKIE['hubspotutk']) ? $_COOKIE['hubspotutk'] : '',
        'ipAddress' => Drupal::request()->getClientIp(),
        'pageName' => 'testing westing',
        'pageUrl' => $page_url,
      );



//      $fields['hs_context'] = Json::encode($hs_context);


      $request_body = [
        'hs_context' => Json::encode($hs_context),
        'firstname' => 'Jyoti', 'lastname' => 'Bohra', 'email' => 'jyoti@qed42.com'
      ];

      $fields = ['firstname' => 'Jyoti', 'lastname' => 'Bohra', 'email' => 'jyoti@qed42.com'];
//      $string = 'hs_context=%7B%22hutk%22%3A%221c62b00222e1d783c6bab35c173f89ab%22%2C%22ipAddress%22%3A%22%3A%3A1%22%2C%22pageName%22%3A%22test%20Webform%201%22%2C%22pageUrl%22%3A%22http%3A%5C/%5C/drupal7%5C/node%5C/6%22%7D&firstname=Neha&lastname=Bohra&email=neha.jyoti%40mailinator.com';
//      $string = 'hs_context=%7B%22hutk%22%3A%221c62b00222e1d783c6bab35c173f89ab%22%2C%22ipAddress%22%3A%22%3A%3A1%22%2C%22pageName%22%3A%22test%20Webform%201%22%2C%22pageUrl%22%3A%22http%3A%5C/%5C/drupal7%5C/node%5C/6%22%7D&'. Json::encode($fields);
      $string = 'hs_context=' . Json::encode($hs_context) . '&'. Json::encode($fields);

      $request_options = [
        RequestOptions::HEADERS => ['Content-Type' => 'application/x-www-form-urlencoded'],
//      RequestOptions::BODY => Json::encode($request_body),
        RequestOptions::BODY => $string,
      ];
      $response = $this->httpClient->request('POST', $url, $request_options);
//      $this->assertSame(200, $response->getStatusCode());
//      print '<pre>'; print_r("reponse"); print '</pre>';
//      print '<pre>'; print_r($response); print '</pre>';exit;


//      print '<pre>'; print_r("data"); print '</pre>';
//      print '<pre>'; print_r($data); print '</pre>';exit;




    }
    catch (RequestException $e) {
      watchdog_exception('my_module', $e);
    }


//    try {
//
//      $response = \Drupal::httpClient()->post($api, $options);
//      $response = \Drupal::httpClient()->send($response);
//
//
////      $request = \Drupal::httpClient()->createRequest('GET', $url);
////      $response = \Drupal::httpClient()->send($request);
//
//      print '<pre>'; print_r("reponse"); print '</pre>';
//      print '<pre>'; print_r($response); print '</pre>';exit;
//
////      $response = $this->http_client->get($url);
////      $res = json_decode($response->getBody(), true)['items'][0];
////      $volume_info = $res['volumeInfo'];
////      $title = $res['volumeInfo']['title'];
////      $subtitle = $res['volumeInfo']['subtitle'];
////      $authors = $res['volumeInfo']['authors'][0];
////      $publishedDate = $volume_info['publishedDate'];
////      $description = $volume_info['description'];
////      $build = [
////        '#theme' => 'item_list',
////        '#items' => [
////          $title,
////          $subtitle,
////          $authors,
////          $publishedDate,
////          $description
////        ]
////      ];
//
//    }
//    catch (RequestException $e) {
//
//    }


    /** code by JYOti ends */

  }

  /**
   * {@inheritdoc}
   */
  public function postDelete(WebformSubmissionInterface $webform_submission) {
    $this->remotePost('delete', $webform_submission);
  }

  /**
   * Execute a remote post.
   *
   * @param string $operation
   *   The type of webform submission operation to be posted. Can be 'insert',
   *   'update', or 'delete'.
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission to be posted.
   */
  protected function remotePost($operation, WebformSubmissionInterface $webform_submission) {
   }

  /**
   * Get a webform submission's post data.
   *
   * @param string $operation
   *   The type of webform submission operation to be posted. Can be 'insert',
   *   'update', or 'delete'.
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission to be posted.
   *
   * @return array
   *   A webform submission converted to an associative array.
   */
  protected function getPostData($operation, WebformSubmissionInterface $webform_submission) {

  }



}
