<?php /**
 * @file
 * Contains \Drupal\hubspot\Controller\DefaultController.
 */

namespace Drupal\hubspot\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Default controller for the hubspot module.
 */
class DefaultController extends ControllerBase {

  public function hubspot_oauth_connect() {
    if (!empty($_GET['access_token']) && !empty($_GET['refresh_token']) && !empty($_GET['expires_in'])) {
      drupal_set_message(t('Successfully authenticated with Hubspot.'), 'status', FALSE);

//      print '<pre>'; print_r("access token"); print '</pre>';
//      print '<pre>'; print_r($_GET['access_token']); print '</pre>'; exit;
      \Drupal::configFactory()->getEditable('hubspot.settings')->set('hubspot_access_token', $_GET['access_token'])->save();
      \Drupal::configFactory()->getEditable('hubspot.settings')->set('hubspot_refresh_token', $_GET['refresh_token'])->save();
      \Drupal::configFactory()->getEditable('hubspot.settings')->set('hubspot_expires_in', $_GET['expires_in'])->save();
    }

    if (!empty($_GET['error']) && $_GET['error'] == "access_denied") {
      drupal_set_message(t('You denied the request for authentication with Hubspot. Please click the button again and
      choose the AUTHORIZE option.'), 'error', FALSE);
    }

//    $redirect_url = $this->url('admin/config/services/hubspot');
    $redirect_url = Url::fromRoute('hubspot.admin_settings')->toString();
    $response = new RedirectResponse($redirect_url);
    $response->send();
    return $response;
  }

}
