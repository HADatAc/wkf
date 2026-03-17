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

    $base_path = rtrim(
      \Drupal::request()->getBasePath() ?: '/',
      '/'
    );
    $drupal_base_url = ($base_path === '' ? '/' : $base_path . '/');

    // Support loading a process via query parameter to avoid encoded-slash issues on Apache/Windows.
    // Accept either base64-encoded full URI or a raw/percent-encoded URI.
    $process_from_query = \Drupal::request()->query->get('processUri');
    if (empty($process_uri) && !empty($process_from_query)) {
      $decoded = base64_decode((string) $process_from_query, TRUE);
      if (is_string($decoded) && $decoded !== '' && (str_starts_with($decoded, 'http://') || str_starts_with($decoded, 'https://'))) {
        $process_uri = $decoded;
      }
      else {
        $process_uri = rawurldecode((string) $process_from_query);
      }
    }

    $study_from_query = \Drupal::request()->query->get('studyUri');
    $study_uri = NULL;
    if (!empty($study_from_query)) {
      $decoded_study = base64_decode((string) $study_from_query, TRUE);
      if (is_string($decoded_study) && $decoded_study !== '' && (str_starts_with($decoded_study, 'http://') || str_starts_with($decoded_study, 'https://'))) {
        $study_uri = $decoded_study;
      }
      else {
        // If it was passed already as a plain URI, keep it.
        $study_uri = rawurldecode((string) $study_from_query);
      }
    }

    // Execution context passed from the "Create Execution" flow.
    $executionFlag = (string) \Drupal::request()->query->get('execution');
    $isExecutionMode = ($executionFlag === '1' || strtolower($executionFlag) === 'true');

    $encodedDaUri = \Drupal::request()->query->get('daUri');
    $encodedDataFileUri = \Drupal::request()->query->get('dataFileUri');
    $daUri = NULL;
    $dataFileUri = NULL;
    if (!empty($encodedDaUri) && is_string($encodedDaUri)) {
      $decoded = base64_decode($encodedDaUri, TRUE);
      if ($decoded !== FALSE && $decoded !== '') {
        $daUri = $decoded;
      }
    }
    if (!empty($encodedDataFileUri) && is_string($encodedDataFileUri)) {
      $decoded = base64_decode($encodedDataFileUri, TRUE);
      if ($decoded !== FALSE && $decoded !== '') {
        $dataFileUri = $decoded;
      }
    }

    // Build drupalSettings for the React app.
    $drupal_settings = [
      'drupalBaseUrl' => $drupal_base_url,
      'apiBaseUrl' => $drupal_base_url . 'workflow/api',
      // Force same-origin proxy in embedded mode to avoid browser CORS.
      'hascoApiUrl' => $drupal_base_url . 'workflow',
      'defaultNamespaceUrl' => $default_namespace_url,
      'csrfToken' => $csrf_token,
      'processUri' => $process_uri ? rawurldecode($process_uri) : NULL,
      'studyUri' => $study_uri,
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
      'execution' => [
        'mode' => $isExecutionMode ? 'execution' : 'edit',
        'daUri' => $daUri,
        'dataFileUri' => $dataFileUri,
        'studyUri' => $study_uri,
        'processUri' => $process_uri ? rawurldecode($process_uri) : NULL,
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
