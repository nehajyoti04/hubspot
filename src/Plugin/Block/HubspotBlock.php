<?php

namespace Drupal\hubspot\Plugin\Block;

use Drupal;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Link;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\LinkGeneratorTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Url;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
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
  /**
   * Stores the configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  protected $http_client;

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
  public function __construct(array $configuration, $plugin_id, $plugin_definition, Client $client) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->http_client = $client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client')
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
            '@time' => \Drupal::service('date.formatter')->formatInterval(REQUEST_TIME - floor($lead['addedAt'] / 1000))
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


    $access_token = \Drupal::config('hubspot.settings')->get('hubspot_access_token');

    $n = intval($n);

    if (empty($access_token)) {
      return array('Error' => t('This site is not connected to a HubSpot Account.'));
    }


    $api = 'https://api.hubapi.com/contacts/v1/lists/recently_updated/contacts/recent';


    $options = [
      'query' => [
        'access_token' => $access_token,
        'count' => $n
      ]
    ];
    $url = Url::fromUri($api, $options)->toString();

    try {
      $result = $this->http_client->get($url);
      //    $result = drupal_http_request("https://api.hubapi.com/contacts/v1/lists/recently_updated/contacts/recent?access_token={$access_token}&count={$n}");
      if ($result->getStatusCode() == 401) {
        $refresh = $this->hubspot_oauth_refresh();
        if ($refresh) {
          $access_token = \Drupal::state()->get('hubspot_access_token', '');
          $result = $this->http_client->get("https://api.hubapi.com/contacts/v1/lists/recently_updated/contacts/recent?access_token={$access_token}&count={$n}");
        }
      }

      return array(
        'Data' => json_decode($result->getBody(), true),
        'Error' => isset($result->error) ? $result->error : '',
        'HTTPCode' => $result->getStatusCode()
      );

    }
    catch (RequestException $e) {

    }

  }


  /**
   * Refreshes HubSpot OAuth Access Token when expired.
   */
  public function hubspot_oauth_refresh() {
    $data = array(
      'refresh_token' => \Drupal::state()->get('hubspot_refresh_token'),
      'client_id' => HUBSPOT_CLIENT_ID,
      'grant_type' => 'refresh_token',
    );

    $data = drupal_http_build_query($data);

    $options = array(
      'headers' => array(
        'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8',
      ),
      'method' => 'POST',
      'data' => $data,
    );

    $return = drupal_http_request('https://api.hubapi.com/auth/v1/refresh', $options);

    if ($return->code == '200') {
      $return_data = json_decode($return->data, TRUE);

      $hubspot_access_token = $return_data['access_token'];
      \Drupal::state()->get('hubspot_access_token', $hubspot_access_token);

      $hubspot_refresh_token = $return_data['refresh_token'];
      \Drupal::state()->get('hubspot_refresh_token', $hubspot_refresh_token);

      $hubspot_expires_in = $return_data['expires_in'];
      \Drupal::state()->get('hubspot_expires_in', $hubspot_expires_in);

      return TRUE;
    }
    else {
      drupal_set_message(t('Refresh token failed with Error Code "%code: %status_message". Reconnect to your Hubspot
      account.'), 'error', FALSE);
      watchdog('hubspot', 'Refresh token failed with Error Code "%code: %status_message". Visit the Hubspot module
      settings page and reconnect to your Hubspot account.', array(
        '%code' => $return->code,
        '%status_message' => $return->status_message,
      ), WATCHDOG_INFO);

      return FALSE;
    }
  }

}
