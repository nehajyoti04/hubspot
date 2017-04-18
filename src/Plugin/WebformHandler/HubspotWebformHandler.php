<?php

namespace Drupal\hubspot\Plugin\WebformHandler;

use Drupal;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\webform\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
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

  /**
   * The HTTP client to fetch the feed data with.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

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

    !(empty($entity_id)) ? ($node_title = $node->getTitle()) : $node_title = '';

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

    $cookie = \Drupal::request()->cookies->get('hubspotutk');

    try {
      $page_url = \Drupal\Core\Url::fromUserInput($request_post_data['uri'], array('absolute' => TRUE))->toString();
      $hs_context = array(
        'hutk' => isset($cookie) ? $cookie : '',
        'ipAddress' => Drupal::request()->getClientIp(),
        'pageName' => $node_title,
        'pageUrl' => $page_url,
      );

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

      // Debugging information.
      $mailManager = \Drupal::service('plugin.manager.mail');
      $hubspot_url = 'https://app.hubspot.com';
      $to = \Drupal::config('hubspot.settings')->get('hubspot_debug_email');
      $default_language = \Drupal::languageManager()->getDefaultLanguage()->getId();
      $from = \Drupal::config('hubspot.settings')->get('site_mail');
      $data = (string) $response->getBody();

      if ($response->getStatusCode() == '204') {

        \Drupal::logger('hubspot')->notice('Webform "%form" results succesfully submitted to HubSpot. Response: @msg', array(
//          '@post' => strip_tags($response['POST']),
          '@msg' => strip_tags($data),
          '%form' => $node_title,
        ));
      }
      elseif (!empty($response['Error'])) {
        \Drupal::logger('hubspot')->notice('HTTP error when submitting HubSpot data from Webform "%form": @error', array(
          '@error' => $response['Error'],
          '%form' => $node_title));

        if (\Drupal::config('hubspot.settings')->get('hubspot_debug_on')) {
          $mailManager->mail('hubspot', 'http_error', $to, $default_language, array(
            'errormsg' => $response['Error'],
            'hubspot_url' => $hubspot_url,
            'node_title' => $node_title,
          ), $from, TRUE);

        }
      }
      else {
        \Drupal::logger('hubspot')->notice('HubSpot error when submitting Webform "%form": @error', array(
          '@error' => $data,
          '%form' => $node_title));

        if (\Drupal::config('hubspot.settings')->get('hubspot_debug_on')) {
          $mailManager->mail('hubspot', 'hub_error', $to, $default_language, array(
            'errormsg' => $data,
            'hubspot_url' => $hubspot_url,
            'node_title' => $node_title,
          ), $from);
        }
      }

    }
    catch (RequestException $e) {
      watchdog_exception('Hubspot', $e);
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

    // Flatten data.
    // Prioritizing elements before the submissions fields.
    $data = $data['data'] + $data;
    unset($data['data']);

    return $data;
  }

}
