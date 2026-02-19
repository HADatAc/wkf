<?php

namespace Drupal\ctt\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Controller that renders the CTT Workflow Editor page.
 *
 * Returns a render array that attaches the React UMD bundle and passes
 * configuration via drupalSettings.ctt.
 */
class CttEditorController extends ControllerBase {

  /**
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = new static();
    $instance->configFactory = $container->get('config.factory');
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * Render the CTT editor page.
   *
   * @param string|null $process_uri
   *   Optional process URI to load.
   *
   * @return array
   *   Render array with the editor template and attached libraries.
   */
  public function editorPage($process_uri = NULL) {
    $config = $this->configFactory->get('ctt.settings');
    $hasco_api_url = $config->get('hasco_api_url') ?: 'http://localhost:9000';
    $default_namespace_url = $config->get('default_namespace_url') ?: NULL;

    if (!$default_namespace_url && \Drupal::moduleHandler()->moduleExists('rep')) {
      try {
        $api = \Drupal::service('rep.api_connector');
        $repo = $api->repoInfo();
        $obj = json_decode($repo);
        if ($obj && isset($obj->body) && isset($obj->body->hasDefaultNamespaceURL)) {
          $default_namespace_url = $obj->body->hasDefaultNamespaceURL;
        }
      }
      catch (\Exception $e) {
        // Keep default_namespace_url as NULL if API is unavailable.
      }
    }

    // Get current user information.
    $account = $this->currentUser;
    $user = \Drupal\user\Entity\User::load($account->id());
    $email = $user ? $user->getEmail() : '';

    // Get CSRF token for API calls.
    $csrf_token = \Drupal::csrfToken()->get('rest');

    // Build drupalSettings for the React app.
    $drupal_settings = [
      'apiBaseUrl' => '/workflow/api',
      // Force same-origin proxy in embedded mode to avoid browser CORS.
      'hascoApiUrl' => '/workflow',
      'defaultNamespaceUrl' => $default_namespace_url,
      'csrfToken' => $csrf_token,
      'processUri' => $process_uri ? rawurldecode($process_uri) : NULL,
      'currentUser' => [
        'id' => (string) $account->id(),
        'name' => $account->getDisplayName(),
        'email' => $email,
      ],
      'drupalLinks' => [
        'createInstrument' => '/sir/manage/addinstrument',
        'createWorkflow' => '/std/manage/addworkflow/active',
        'manageInstruments' => '/sir/manage/instruments',
        'createTask' => '/std/manage/addtask/active/',
      ],
    ];

    return [
      '#theme' => 'ctt_editor',
      '#title' => '',
      '#process_uri' => $process_uri,
      '#api_settings' => $drupal_settings,
      '#attached' => [
        'library' => [
          'ctt/ctt-editor-init',
        ],
        'drupalSettings' => [
          'ctt' => $drupal_settings,
        ],
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

}
