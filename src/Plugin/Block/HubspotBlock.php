<?php

namespace Drupal\hubspot\Plugin\Block;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Link;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Url;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'hubspot' block.
 *
 * @Block(
 *   id = "hubspot_block",
 *   admin_label = @Translation("HubSpot Recent Leads"),
 * )
 */
class HubspotBlock extends BlockBase implements ContainerFactoryPluginInterface {
//  use LinkGeneratorTrait;


  protected $http_client;

  protected $loggerFactory;

  protected $dateFormatter;

  protected $json;

  /**
   * Stores the configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;


  /**
   * Constructs a Drupal\Component\Plugin\PluginBase object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Utility\Token $token
   *   The token service.
   *
   * @var string $weatherservice
   *   The information from the Weather service for this block.
   */
  public function __construct(array $configuration, $plugin_id,
                              $plugin_definition, ClientInterface $http_client, LoggerChannelFactory $logger,
                              DateFormatter $dateFormatter, Json $json,
                              ConfigFactoryInterface $config_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->http_client = $http_client;
    $this->loggerFactory = $logger;
    $this->dateFormatter = $dateFormatter;
    $this->json = $json;
    $this->configFactory = $config_factory->getEditable('hubspot.settings');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client'),
      $container->get('logger.factory'),
      $container->get('date.formatter'),
      $container->get('serialization.json'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    return AccessResult::allowed();
    // @TODO add view recent hubspot leads Permission.
//    if ($account->isAnonymous()) {
//      return AccessResult::allowed();
//    }
//    return AccessResult::forbidden();
  }

  /**
   * {@inheritdoc}
   */
  public function build() {

    $leads = $this->hubspot_get_recent();

    // This part of the HubSpot API returns HTTP error codes on failure, with no message
    if (!empty($leads['Error']) || $leads['HTTPCode'] != 200) {
      $output = t('An error occurred when fetching the HubSpot leads data: @error', array(
        '@error' => !empty($leads['Error']) ? $leads['Error'] : $leads['HTTPCode'],
      ));

      return array(
        '#type' => 'markup',
        '#markup' => $output,
      );

    }
    elseif (empty($leads['Data'])) {
      $output = t('No leads to show.');
      return array(
        '#type' => 'markup',
        '#markup' => $output,
      );
    }

    $items = array();

    foreach ($leads['Data']['contacts'] as $lead) {
      $url = Url::fromUri($lead['profile-url']);
      $items[] = ['#markup' => Link::fromTextAndUrl($lead['properties']['firstname']['value'] . ' ' .
          $lead['properties']['lastname']['value'], $url)->toString() . ' ' . t('(@time ago)',
          array(
            '@time' => $this->dateFormatter->formatInterval(REQUEST_TIME - floor($lead['addedAt'] / 1000))
          )
        )];
    }

    $build = [
      '#theme' => 'item_list',
      '#items' => $items
    ];

    return $build;

  }

  /**
   * Gets the most recent HubSpot leads.
   *
   * @param int $n
   *   The number of leads to fetch.
   *
   * @see http://docs.hubapi.com/wiki/Searching_Leads
   *
   * @return array
   */
  public function hubspot_get_recent($n = 5) {
    $access_token = $this->configFactory->get('hubspot_access_token');
    $n = intval($n);

    if (empty($access_token)) {
      return array('Error' => $this->t('This site is not connected to a HubSpot Account.'));
    }

    $api = 'https://api.hubapi.com/contacts/v1/lists/recently_updated/contacts/recent';

    $options = [
      'query' => [
        'access_token' => $access_token,
        'count' => $n
      ]
    ];
    $url = Url::fromUri($api, $options)->toString();

    if(\Drupal::config('hubspot.settings')->get('hubspot_expires_in') > REQUEST_TIME ) {
      $result = $this->http_client->get($url);

    } else {
      $refresh = $this->hubspot_oauth_refresh();
      if ($refresh) {
        $access_token = $this->configFactory->get('hubspot_access_token');
        $options = [
          'query' => [
            'access_token' => $access_token,
            'count' => $n
          ]
        ];
        $url = Url::fromUri($api, $options)->toString();
        $result = $this->http_client->get($url);

      }
    }
    return array(
      'Data' => json_decode($result->getBody(), true),
      'Error' => isset($result->error) ? $result->error : '',
      'HTTPCode' => $result->getStatusCode()
    );
  }


  /**
   * Refreshes HubSpot OAuth Access Token when expired.
   */
  public function hubspot_oauth_refresh() {

    $refresh_token = $this->configFactory->get('hubspot_refresh_token');
    $api = 'https://api.hubapi.com/auth/v1/refresh';
    $string = 'refresh_token='.$refresh_token.'&client_id='.HUBSPOT_CLIENT_ID.'&grant_type=refresh_token';
    $request_options = [
      RequestOptions::HEADERS => ['Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8'],
//      RequestOptions::BODY => Json::encode($request_body),
      RequestOptions::BODY => $string,
    ];
    try {
      $response = $this->http_client->request('POST', $api, $request_options);

      if ($response->getStatusCode() == '200') {

        $data = $this->json->decode($response->getBody());
        $hubspot_access_token = $data['access_token'];
        $hubspot_refresh_token = $data['refresh_token'];

        $hubspot_expires_in = $data['expires_in'];

        $this->configFactory->set('hubspot_access_token', $hubspot_access_token)->save();
        $this->configFactory->getEditable('hubspot.settings')->set('hubspot_refresh_token', $hubspot_refresh_token)->save();
        $this->configFactory->getEditable('hubspot.settings')->set('hubspot_expires_in', ($hubspot_expires_in + REQUEST_TIME))->save();

        return ['value' => $data];

      }
    }
    catch (RequestException $e) {
      watchdog_exception('Hubspot', $e);
    }

    drupal_set_message($this->t('Refresh token failed with Error Code "%code: %status_message". Reconnect to your Hubspot
      account.'), 'error', FALSE);
    $this->loggerFactory->get('hubspot')->notice('Refresh token failed with Error Code "%code: %status_message". Visit the Hubspot module
      settings page and reconnect to your Hubspot account.', array(
      '%code' => $response->getStatusCode(),
      '%status_message' => $response['status_message'],
    ));

    return FALSE;

  }

}
