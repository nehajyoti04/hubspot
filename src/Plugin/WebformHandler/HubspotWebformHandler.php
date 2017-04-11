<?php

namespace Drupal\hubspot\Plugin\WebformHandler;

use Drupal;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Serialization\Yaml;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\webform\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\webform\WebformTokenManagerInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
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

//  /**
//   * The module handler.
//   *
//   * @var \Drupal\Core\Extension\ModuleHandlerInterface
//   */
//  protected $moduleHandler;

  /**
   * The HTTP client to fetch the feed data with.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
//   * The token manager.
//   *
//   * @var \Drupal\webform\WebformTranslationManagerInterface
//   */
//  protected $tokenManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerInterface $logger, EntityTypeManagerInterface $entity_type_manager, ClientInterface $http_client) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $logger, $entity_type_manager);
    $this->httpClient = $http_client;
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
      $container->get('http_client')
    );
  }

//  /**
//   * {@inheritdoc}
//   */
//  public function getSummary() {
//    $configuration = $this->getConfiguration();
//
//    // If the saving of results is disabled clear update and delete URL.
//    if ($this->getWebform()->getSetting('results_disabled')) {
//      $configuration['settings']['update_url'] = '';
//      $configuration['settings']['delete_url'] = '';
//    }
//
//    return [
//      '#settings' => $configuration['settings'],
//    ] + parent::getSummary();
//  }

//  /**
//   * {@inheritdoc}
//   */
//  public function defaultConfiguration() {
//    $field_names = array_keys(\Drupal::service('entity_field.manager')->getBaseFieldDefinitions('webform_submission'));
//    $excluded_data = array_combine($field_names, $field_names);
//    return [
//      'type' => 'x-www-form-urlencoded',
//      'insert_url' => '',
//      'update_url' => '',
//      'delete_url' => '',
//      'excluded_data' => $excluded_data,
//      'custom_data' => '',
//      'insert_custom_data' => '',
//      'update_custom_data' => '',
//      'delete_custom_data' => '',
//      'debug' => FALSE,
//    ];
//  }

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
    $operation = ($update) ? 'update' : 'insert';
    $this->remotePost($operation, $webform_submission);


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
    $request_post_data = $this->getPostData($operation, $webform_submission);

    $entity_id = $request_post_data['entity_id'];
    $node = Node::load($entity_id);
    $node_title = $node->getTitle();

    $form_guid =  \Drupal::database()->select('hubspot', 'h')
      ->fields('h', ['hubspot_guid'])
      ->condition('nid', $entity_id)
      ->range(0,1)
      ->execute()->fetchField();

    $portal_id = \Drupal::config('hubspot.settings')->get('hubspot_portalid');

    $api = 'https://forms.hubspot.com/uploads/form/v2/' . $portal_id . '/' . $form_guid;

    $options = [
      'query' => $request_post_data,
    ];

    $url = Url::fromUri($api, $options)->toString();

    try {
      $page_url = \Drupal\Core\Url::fromUserInput($request_post_data['uri'], array('absolute' => TRUE))->toString();
      $hs_context = array(
        'hutk' => isset($_COOKIE['hubspotutk']) ? $_COOKIE['hubspotutk'] : '',
        'ipAddress' => Drupal::request()->getClientIp(),
        'pageName' => $node_title,
        'pageUrl' => $page_url,
      );


//      $request_body = [
//        'hs_context' => Json::encode($hs_context),
//        'firstname' => 'Jyoti', 'lastname' => 'Bohra', 'email' => 'jyoti@qed42.com'
//      ];

//      $fields = ['firstname' => 'Jyoti', 'lastname' => 'Bohra', 'email' => 'jyoti@qed42.com'];

      $fields = $request_post_data;
//      $string = 'hs_context=%7B%22hutk%22%3A%221c62b00222e1d783c6bab35c173f89ab%22%2C%22ipAddress%22%3A%22%3A%3A1%22%2C%22pageName%22%3A%22test%20Webform%201%22%2C%22pageUrl%22%3A%22http%3A%5C/%5C/drupal7%5C/node%5C/6%22%7D&firstname=Neha&lastname=Bohra&email=neha.jyoti%40mailinator.com';
//      $string = 'hs_context=%7B%22hutk%22%3A%221c62b00222e1d783c6bab35c173f89ab%22%2C%22ipAddress%22%3A%22%3A%3A1%22%2C%22pageName%22%3A%22test%20Webform%201%22%2C%22pageUrl%22%3A%22http%3A%5C/%5C/drupal7%5C/node%5C/6%22%7D&'. Json::encode($fields);
      $string = 'hs_context=' . Json::encode($hs_context) . '&'. Json::encode($fields);

      $request_options = [
        RequestOptions::HEADERS => ['Content-Type' => 'application/x-www-form-urlencoded'],
//      RequestOptions::BODY => Json::encode($request_body),
        RequestOptions::BODY => $string,
      ];
      $response = $this->httpClient->request('POST', $url, $request_options);

    }
    catch (RequestException $e) {
      watchdog_exception('my_module', $e);
    }


//    print '<pre>'; print_r("request post data"); print '</pre>';
//    print '<pre>'; print_r($request_post_data); print '</pre>';exit;
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
    // Get submission and elements data.
    $data = $webform_submission->toArray(TRUE);

//    // Flatten data.
//    // Prioritizing elements before the submissions fields.
//    $data = $data['data'] + $data;
//    unset($data['data']);
//
//    // Excluded selected submission data.
//    $data = array_diff_key($data, $this->configuration['excluded_data']);
//
//    // Append custom data.
//    if (!empty($this->configuration['custom_data'])) {
//      $data = Yaml::decode($this->configuration['custom_data']) + $data;
//    }
//
//    // Append operation data.
//    if (!empty($this->configuration[$operation . '_custom_data'])) {
//      $data = Yaml::decode($this->configuration[$operation . '_custom_data']) + $data;
//    }
//
//    // Replace tokens.
//    $data = $this->tokenManager->replace($data, $webform_submission);

    return $data;
  }



}
