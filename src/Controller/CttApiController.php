<?php

namespace Drupal\ctt\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\ctt\Service\CttHascoClient;

/**
 * API proxy controller - sits between the CTT React editor and hascoapi.
 *
 * All endpoints return JsonResponse so the frontend DrupalAdapter can
 * consume them via fetch().  Each method delegates to CttHascoClient
 * which handles the HTTP request to hascoapi.
 */
class CttApiController extends ControllerBase {

  /**
   * @var \Drupal\ctt\Service\CttHascoClient
   */
  protected $hascoClient;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = new static();
    $instance->hascoClient = $container->get('ctt.hasco_client');
    return $instance;
  }

  // ================================================================
  // Process endpoints
  // ================================================================

  /**
   * GET /workflow/api/process/list
   */
  public function listProcesses(Request $request) {
    $pageSize = $request->query->get('pageSize', 50);
    $offset = $request->query->get('offset', 0);
    $status = $request->query->get('status');
    $managedBy = $request->query->get('managedBy');

    try {
      $result = $this->hascoClient->listProcesses($pageSize, $offset, $status, $managedBy);
      return new JsonResponse($result);
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => $e->getMessage()], 500);
    }
  }

  /**
   * GET /workflow/api/process/get?uri=...
   */
  public function getProcess(Request $request) {
    try {
      $uri = $request->query->get('uri', '');
      $result = $this->hascoClient->getByUri($uri);
      return new JsonResponse($result);
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => $e->getMessage()], 404);
    }
  }

  /**
   * POST /workflow/api/process/create
   */
  public function createProcess(Request $request) {
    $data = json_decode($request->getContent(), TRUE);
    if (empty($data)) {
      return new JsonResponse(['error' => 'Invalid JSON body'], 400);
    }

    // Ensure process payload uses HASCO-compatible manager field.
    $account = $this->currentUser();
    $user = \Drupal\user\Entity\User::load($account->id());
    if ($user && empty($data['hasSIRManagerEmail'])) {
      $data['hasSIRManagerEmail'] = $user->getEmail();
    }

    // HASCO Process POJO does not accept managerEmail.
    if (isset($data['managerEmail'])) {
      unset($data['managerEmail']);
    }

    try {
      $result = $this->hascoClient->createElement('process', $data);
      return new JsonResponse($result, 201);
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => $e->getMessage()], 500);
    }
  }

  /**
   * DELETE /workflow/api/process/delete?uri=...
   */
  public function deleteProcess(Request $request) {
    try {
      $uri = $request->query->get('uri', '');
      $this->hascoClient->deleteElement('process', $uri);
      return new JsonResponse(['status' => 'deleted']);
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => $e->getMessage()], 500);
    }
  }

  /**
   * GET /workflow/api/process/tree?uri=...
   */
  public function getProcessTree(Request $request) {
    try {
      $uri = $request->query->get('uri', '');
      $process = $this->hascoClient->getByUri($uri);
      $tasks = $this->hascoClient->getTasksByProcess($uri);
      return new JsonResponse([
        'process' => $process,
        'tasks' => $tasks,
      ]);
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => $e->getMessage()], 500);
    }
  }

  /**
   * GET /workflow/api/process/{process_uri}/versions
   *
   * The JS editor expects an array of version entries.
   * Currently this is stored locally in Drupal (not in hascoapi).
   */
  public function getProcessVersions(Request $request, $process_uri) {
    try {
      $uri = is_string($process_uri) ? $process_uri : '';
      if (strpos($uri, '%') !== FALSE) {
        $uri = rawurldecode($uri);
      }
      $uri = trim($uri);

      $collection = \Drupal::keyValue('ctt.process_versions');
      $key = hash('sha256', $uri);
      $versions = $collection->get($key, []);
      if (!is_array($versions)) {
        $versions = [];
      }

      // Return newest-first for convenience.
      $versions = array_values(array_reverse($versions));
      return new JsonResponse($versions);
    }
    catch (\Exception $e) {
      // Be permissive: version history should never break the editor.
      return new JsonResponse([], 200);
    }
  }

  /**
   * POST /workflow/api/process/{process_uri}/versions
   * Body: { changelog: string }
   */
  public function createProcessVersion(Request $request, $process_uri) {
    try {
      $uri = is_string($process_uri) ? $process_uri : '';
      if (strpos($uri, '%') !== FALSE) {
        $uri = rawurldecode($uri);
      }
      $uri = trim($uri);
      if ($uri === '') {
        return new JsonResponse(['error' => 'Missing process URI'], 400);
      }

      $data = json_decode($request->getContent(), TRUE);
      if (!is_array($data)) {
        $data = [];
      }
      $changelog = trim((string) ($data['changelog'] ?? ''));

      $collection = \Drupal::keyValue('ctt.process_versions');
      $key = hash('sha256', $uri);
      $versions = $collection->get($key, []);
      if (!is_array($versions)) {
        $versions = [];
      }

      $versionNumber = count($versions) + 1;
      $createdAt = gmdate('c');
      $createdBy = '';
      try {
        $account = $this->currentUser();
        $user = \Drupal\user\Entity\User::load($account->id());
        if ($user) {
          $createdBy = (string) $user->getEmail();
        }
      }
      catch (\Throwable $ignored) {
        // Ignore.
      }

      $entry = [
        'uri' => 'ctt:version:' . $key . ':' . $versionNumber,
        'processUri' => $uri,
        'versionNumber' => $versionNumber,
        'changelog' => $changelog,
        'createdAt' => $createdAt,
        'createdBy' => $createdBy,
      ];

      $versions[] = $entry;
      $collection->set($key, $versions);

      return new JsonResponse($entry, 201);
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => $e->getMessage()], 500);
    }
  }

  /**
   * GET /workflow/api/process/versions?uri=...
   * Optional: uri_b64=... (base64url)
   */
  public function getProcessVersionsQuery(Request $request) {
    $uri = trim((string) $request->query->get('uri', ''));
    if ($uri === '') {
      $uri_b64 = trim((string) $request->query->get('uri_b64', ''));
      if ($uri_b64 !== '') {
        $b64 = strtr($uri_b64, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad) {
          $b64 .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($b64, TRUE);
        if ($decoded !== FALSE) {
          $uri = trim((string) $decoded);
        }
      }
    }

    // Keep behavior permissive.
    if ($uri === '') {
      return new JsonResponse([]);
    }

    $collection = \Drupal::keyValue('ctt.process_versions');
    $key = hash('sha256', $uri);
    $versions = $collection->get($key, []);
    if (!is_array($versions)) {
      $versions = [];
    }
    $versions = array_values(array_reverse($versions));
    return new JsonResponse($versions);
  }

  /**
   * POST /workflow/api/process/versions?uri=...
   * Body: { changelog: string }
   */
  public function createProcessVersionQuery(Request $request) {
    $uri = trim((string) $request->query->get('uri', ''));
    if ($uri === '') {
      // Also accept processUri in JSON body for compatibility.
      $data = json_decode($request->getContent(), TRUE);
      if (is_array($data) && !empty($data['processUri'])) {
        $uri = trim((string) $data['processUri']);
      }
    }
    if ($uri === '') {
      $uri_b64 = trim((string) $request->query->get('uri_b64', ''));
      if ($uri_b64 !== '') {
        $b64 = strtr($uri_b64, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad) {
          $b64 .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($b64, TRUE);
        if ($decoded !== FALSE) {
          $uri = trim((string) $decoded);
        }
      }
    }

    if ($uri === '') {
      return new JsonResponse(['error' => 'Missing parameter: uri'], 400);
    }

    $data = json_decode($request->getContent(), TRUE);
    if (!is_array($data)) {
      $data = [];
    }
    $changelog = trim((string) ($data['changelog'] ?? ''));

    $collection = \Drupal::keyValue('ctt.process_versions');
    $key = hash('sha256', $uri);
    $versions = $collection->get($key, []);
    if (!is_array($versions)) {
      $versions = [];
    }

    $versionNumber = count($versions) + 1;
    $createdAt = gmdate('c');
    $createdBy = '';
    try {
      $account = $this->currentUser();
      $user = \Drupal\user\Entity\User::load($account->id());
      if ($user) {
        $createdBy = (string) $user->getEmail();
      }
    }
    catch (\Throwable $ignored) {
      // Ignore.
    }

    $entry = [
      'uri' => 'ctt:version:' . $key . ':' . $versionNumber,
      'processUri' => $uri,
      'versionNumber' => $versionNumber,
      'changelog' => $changelog,
      'createdAt' => $createdAt,
      'createdBy' => $createdBy,
    ];

    $versions[] = $entry;
    $collection->set($key, $versions);
    return new JsonResponse($entry, 201);
  }

  // ================================================================
  // Task endpoints
  // ================================================================

  /**
   * GET /workflow/api/task/list
   */
  public function listTasks(Request $request) {
    $pageSize = $request->query->get('pageSize', 50);
    $offset = $request->query->get('offset', 0);
    $processUri = $request->query->get('processUri');

    try {
      if ($processUri) {
        $result = $this->hascoClient->getTasksByProcess($processUri);
      }
      else {
        $result = $this->hascoClient->listTasks($pageSize, $offset);
      }
      return new JsonResponse($result);
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => $e->getMessage()], 500);
    }
  }

  /**
   * GET /workflow/api/task/get?uri=...
   */
  public function getTask(Request $request) {
    try {
      $uri = $request->query->get('uri', '');
      $result = $this->hascoClient->getByUri($uri);
      return new JsonResponse($result);
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => $e->getMessage()], 404);
    }
  }

  /**
   * POST /workflow/api/task/create
   */
  public function createTask(Request $request) {
    $data = json_decode($request->getContent(), TRUE);
    if (empty($data)) {
      return new JsonResponse(['error' => 'Invalid JSON body'], 400);
    }

    try {
      $result = $this->hascoClient->createElement('task', $data);
      return new JsonResponse($result, 201);
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => $e->getMessage()], 500);
    }
  }

  /**
   * DELETE /workflow/api/task/delete?uri=...
   */
  public function deleteTask(Request $request) {
    try {
      $uri = $request->query->get('uri', '');
      $this->hascoClient->deleteElement('task', $uri);
      return new JsonResponse(['status' => 'deleted']);
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => $e->getMessage()], 500);
    }
  }

  /**
   * PUT /workflow/api/task/instruments?uri=...
   *
   * Query-param version to avoid encoded-slash routing issues under Apache.
   * Accepts the frontend payload { instruments: [{ instrumentUri, componentUris: string[] }] }
   * and maps it to hascoapi's expected structure.
   */
  public function setTaskRequiredInstrumentsQuery(Request $request) {
    try {
      $task_uri = $request->query->get('uri', '');
      if ($task_uri === '') {
        $uri_b64 = $request->query->get('uri_b64', '');
        if ($uri_b64 !== '') {
          // Base64url decode with optional padding.
          $b64 = strtr($uri_b64, '-_', '+/');
          $pad = strlen($b64) % 4;
          if ($pad) {
            $b64 .= str_repeat('=', 4 - $pad);
          }
          $decoded = base64_decode($b64, TRUE);
          if ($decoded !== FALSE) {
            $task_uri = $decoded;
          }
        }
      }

      if ($task_uri === '') {
        return new JsonResponse(['error' => 'Missing parameter: uri'], 400);
      }

      $data = json_decode($request->getContent(), TRUE);
      if (!is_array($data)) {
        return new JsonResponse(['error' => 'Invalid JSON body'], 400);
      }

      $instruments = $data['instruments'] ?? [];
      if (!is_array($instruments)) {
        $instruments = [];
      }

      $instruments_input_count = count($instruments);

      // Map to hascoapi payload shape.
      $required_instrument = [];
      foreach ($instruments as $inst) {
        if (!is_array($inst)) {
          continue;
        }
        $instrument_uri = trim((string) ($inst['instrumentUri'] ?? ''));
        if ($instrument_uri === '') {
          continue;
        }

        $entry = [
          'instrumentUri' => $instrument_uri,
        ];

        $component_uris = $inst['componentUris'] ?? [];
        if (is_string($component_uris)) {
          $component_uris = [$component_uris];
        }
        if (is_array($component_uris) && count($component_uris) > 0) {
          $required_components = [];
          foreach ($component_uris as $comp_uri) {
            $comp_uri = trim((string) $comp_uri);
            if ($comp_uri === '') {
              continue;
            }
            $required_components[] = [
              'componentUri' => $comp_uri,
              // containerSlotUri is optional in hascoapi.
              'containerSlotUri' => '',
            ];
          }
          if (count($required_components) > 0) {
            $entry['requiredComponents'] = $required_components;
          }
        }

        $required_instrument[] = $entry;
      }

      if ($instruments_input_count > 0 && count($required_instrument) === 0) {
        return new JsonResponse(['error' => 'Invalid instruments payload'], 400);
      }

      $result = $this->hascoClient->setTaskRequiredInstruments($task_uri, $required_instrument);
      return new JsonResponse($result);
    }
    catch (\RuntimeException $e) {
      $code = (int) $e->getCode();
      // If the underlying HTTP call failed, CttHascoClient uses the upstream status code
      // as the exception code. Preserve it for easier debugging in the browser.
      if ($code >= 400 && $code <= 599) {
        return new JsonResponse(['error' => $e->getMessage()], $code);
      }
      return new JsonResponse(['error' => $e->getMessage()], 500);
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => $e->getMessage()], 500);
    }
  }

  // ================================================================
  // Instrument endpoints
  // ================================================================

  /**
   * GET /workflow/api/instrument/list
   */
  public function listInstruments(Request $request) {
    $pageSize = $request->query->get('pageSize', 50);
    $offset = $request->query->get('offset', 0);

    try {
      $result = $this->hascoClient->listInstruments($pageSize, $offset);
      return new JsonResponse($result);
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => $e->getMessage()], 500);
    }
  }

  /**
   * GET /workflow/api/instrument/get?uri=...
   */
  public function getInstrument(Request $request) {
    try {
      $uri = $request->query->get('uri', '');
      $result = $this->hascoClient->getInstrumentByUri($uri);
      return new JsonResponse($result);
    }
    catch (\Throwable $e) {
      return new JsonResponse(['error' => $e->getMessage()], 404);
    }
  }

  /**
   * GET /workflow/api/instrument/components?uri=...
   */
  public function getInstrumentComponents(Request $request) {
    try {
      $uri = $request->query->get('uri', '');
      $result = $this->hascoClient->getInstrumentComponents($uri);
      return new JsonResponse($result);
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => $e->getMessage()], 500);
    }
  }

  /**
   * GET /workflow/api/instrument/containerslots?uri=...
   */
  public function getInstrumentContainerSlotsQuery(Request $request) {
    try {
      $uri = $request->query->get('uri', '');
      if ($uri === '') {
        // Optional: allow URL-safe base64 to avoid any encoding issues.
        // Example: /workflow/api/instrument/containerslots?uri_b64=...
        $uri_b64 = $request->query->get('uri_b64', '');
        if ($uri_b64 !== '') {
          $normalized = strtr($uri_b64, '-_', '+/');
          $remainder = strlen($normalized) % 4;
          if ($remainder) {
            $normalized .= str_repeat('=', 4 - $remainder);
          }
          $decoded = base64_decode($normalized, TRUE);
          if ($decoded !== FALSE) {
            $uri = $decoded;
          }
        }
      }
      if ($uri === '') {
        return new JsonResponse(['error' => 'Missing uri'], 400);
      }
      $result = $this->hascoClient->getInstrumentContainerSlots($uri);
      return new JsonResponse($result);
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => $e->getMessage()], 500);
    }
  }

  /**
   * GET /workflow/api/instrument/{instrument_uri}/containerslots
   */
  public function getInstrumentContainerSlots(Request $request, $instrument_uri) {
    try {
      $uri = rawurldecode($instrument_uri);
      $result = $this->hascoClient->getInstrumentContainerSlots($uri);
      return new JsonResponse($result);
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => $e->getMessage()], 500);
    }
  }

  /**
   * GET /workflow/api/instrument/search?q=keyword
   * Autocomplete search for instruments.
   */
  public function searchInstruments(Request $request) {
    $query = $request->query->get('q', '');
    $pageSize = $request->query->get('pageSize', 20);

    try {
      $result = $this->hascoClient->searchInstruments($query, $pageSize);
      return new JsonResponse($result);
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => $e->getMessage()], 500);
    }
  }

  // ================================================================
  // Utility endpoints
  // ================================================================

  /**
   * GET /workflow/api/uri/generate/{element_type}
   * Generates a platform-compliant URI.
   */
  public function generateUri($element_type) {
    $account = $this->currentUser();
    $uri = $this->hascoClient->generateUri($element_type, (string) $account->id());
    return new JsonResponse(['uri' => $uri]);
  }

  /**
   * GET /workflow/api/repo
   * Returns namespace and repository configuration.
   */
  public function getRepoInfo() {
    try {
      $result = $this->hascoClient->getRepoInfo();
      return new JsonResponse($result);
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => $e->getMessage()], 500);
    }
  }

  /**
   * GET /workflow/api/repo/languages
   * Returns repository language table.
   */
  public function getRepoLanguages() {
    try {
      $result = $this->hascoClient->getRepoLanguages();
      return new JsonResponse($result);
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => $e->getMessage()], 500);
    }
  }

  /**
   * Proxy arbitrary hascoapi calls from frontend through Drupal (same-origin).
   */
  public function proxyHasco(Request $request, $proxy_path = '') {
    $proxy_path = is_string($proxy_path) ? $proxy_path : '';

    // Primary: path-param route (/workflow/hascoapi/api/{proxy_path})
    // Fallback: query-param route (/workflow/hascoapi/api?path=...)
    $path_from_query = (string) $request->query->get('path', '');
    $use_query_path = ($proxy_path === '' && $path_from_query !== '');

    if ($use_query_path) {
      // Query params are already URL-decoded once by Symfony.
      // Frontend must double-encode any embedded URIs so we preserve a single-encoded form here.
      $proxy_path = $path_from_query;
    }

    if ($proxy_path === '') {
      return new JsonResponse(['error' => 'Missing parameter: proxy_path (path) or path (query)'], 400);
    }

    $path = $use_query_path ? $proxy_path : rawurldecode($proxy_path);
    $endpoint = '/hascoapi/api/' . ltrim($path, '/');
    $options = [];

    $query = $request->query->all();
    if ($use_query_path) {
      unset($query['path']);
    }
    if (!empty($query)) {
      $options['query'] = $query;
    }

    $rawBody = $request->getContent();
    if ($rawBody !== '') {
      $decoded = json_decode($rawBody, TRUE);
      $options['json'] = $decoded !== NULL ? $decoded : $rawBody;
    }

    try {
      $result = $this->hascoClient->proxyRequest($request->getMethod(), $endpoint, $options);
      return new JsonResponse($result);
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => $e->getMessage(), 'endpoint' => $endpoint], 500);
    }
  }

  // ================================================================
  // Debug endpoints (admin)
  // ================================================================

  /**
   * GET /workflow/api/debug/task-instruments?uri=...
   *
   * Returns Drupal-local key/value override for a task's instrument selection.
   */
  public function debugTaskInstruments(Request $request) {
    try {
      $task_uri = $request->query->get('uri', '');
      if ($task_uri === '') {
        $uri_b64 = $request->query->get('uri_b64', '');
        if ($uri_b64 !== '') {
          $b64 = strtr($uri_b64, '-_', '+/');
          $pad = strlen($b64) % 4;
          if ($pad) {
            $b64 .= str_repeat('=', 4 - $pad);
          }
          $decoded = base64_decode($b64, TRUE);
          if ($decoded !== FALSE) {
            $task_uri = $decoded;
          }
        }
      }

      if ($task_uri === '') {
        return new JsonResponse(['error' => 'Missing parameter: uri'], 400);
      }

      $result = $this->hascoClient->debugTaskInstrumentOverride($task_uri);
      return new JsonResponse($result);
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => $e->getMessage()], 500);
    }
  }

}
