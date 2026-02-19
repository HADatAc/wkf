<?php

namespace Drupal\ctt\Service;

use GuzzleHttp\ClientInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * HTTP client for communicating with the HASCO API (hascoapi).
 *
 * Handles all REST requests from the CTT module to the Play Framework
 * backend.  Uses JWT authentication when a key is configured.
 */
class CttHascoClient {

  /**
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected $keyRepository;

  /**
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * URI prefix map (same as Drupal Utils::uriGen prefixes).
   */
  const URI_PREFIXES = [
    'instrument' => 'INS',
    'questionnaire' => 'QNR',
    'process' => 'PC0',
    'workflow' => 'PC0',
    'task' => 'TSK',
    'componentstem' => 'CSM',
    'component' => 'COM',
    'codebook' => 'CBK',
    'responseoption' => 'ROP',
    'container' => 'CNT',
    'slot' => 'SLT',
  ];

  /**
   * Cached namespace URL from the API.
   *
   * @var string|null
   */
  protected $namespace = NULL;

  /**
   * Constructs a CttHascoClient.
   */
  public function __construct(
    ClientInterface $http_client,
    ConfigFactoryInterface $config_factory,
    KeyRepositoryInterface $key_repository,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->keyRepository = $key_repository;
    $this->logger = $logger_factory->get('ctt');
  }

  /**
   * Get the hascoapi base URL from configuration.
   */
  protected function getApiUrl(): string {
    $config = $this->configFactory->get('ctt.settings');
    return rtrim($config->get('hasco_api_url') ?: 'http://localhost:9000', '/');
  }

  /**
   * Get JWT token from the Key module.
   */
  protected function getJwtToken(): ?string {
    $config = $this->configFactory->get('ctt.settings');
    $key_id = $config->get('jwt_key_id');
    if ($key_id) {
      $key = $this->keyRepository->getKey($key_id);
      if ($key) {
        return $key->getKeyValue();
      }
    }
    return NULL;
  }

  /**
   * Perform an HTTP request to hascoapi.
   */
  protected function request(string $method, string $endpoint, array $options = []): array {
    $api_url = $this->getApiUrl();
    $url = $api_url . $endpoint;

    $headers = [
      'Accept' => 'application/json',
      'Content-Type' => 'application/json',
    ];

    $jwt = $this->getJwtToken();
    if ($jwt) {
      $headers['Authorization'] = 'Bearer ' . $jwt;
    }

    $options['headers'] = array_merge($headers, $options['headers'] ?? []);
    $options['timeout'] = $options['timeout'] ?? 10;
    $options['connect_timeout'] = $options['connect_timeout'] ?? 5;

    // Allow disabling SSL verification for local dev.
    $config = $this->configFactory->get('ctt.settings');
    if ($config->get('disable_ssl_verification')) {
      $options['verify'] = FALSE;
    }

    $this->logger->debug('CTT API @method @url', [
      '@method' => $method,
      '@url' => $url,
    ]);

    try {
      $response = $this->httpClient->request($method, $url, $options);
      $body = $response->getBody()->getContents();
      return json_decode($body, TRUE) ?: [];
    }
    catch (\Exception $e) {
      $this->logger->error('CTT API error: @message', ['@message' => $e->getMessage()]);
      throw $e;
    }
  }

  /**
   * Generic passthrough request for controller-level proxy routes.
   */
  public function proxyRequest(string $method, string $endpoint, array $options = []): array {
    return $this->request($method, $endpoint, $options);
  }

  // ================================================================
  // Generic operations
  // ================================================================

  /**
   * Fetch any element by URI.
   */
  public function getByUri(string $uri): array {
    $endpoint = '/hascoapi/api/uri/' . rawurlencode($uri);
    return $this->request('GET', $endpoint);
  }

  /**
   * Create an element of the given type.
   */
  public function createElement(string $element_type, array $data): array {
    $json = json_encode($data);
    $endpoint = '/hascoapi/api/' . $element_type . '/create/' . rawurlencode($json);
    return $this->request('POST', $endpoint);
  }

  /**
   * Delete an element by URI.
   */
  public function deleteElement(string $element_type, string $uri): array {
    $endpoint = '/hascoapi/api/' . $element_type . '/delete/' . rawurlencode($uri);
    return $this->request('POST', $endpoint);
  }

  // ================================================================
  // Process operations
  // ================================================================

  /**
   * List processes with pagination and optional filters.
   */
  public function listProcesses(int $page_size = 50, int $offset = 0, ?string $status = NULL, ?string $managed_by = NULL): array {
    if ($managed_by) {
      $endpoint = '/hascoapi/api/process/manageremail/' . rawurlencode($managed_by) . '/' . $page_size . '/' . $offset;
    }
    elseif ($status) {
      $endpoint = '/hascoapi/api/process/status/' . rawurlencode($status) . '/' . $page_size . '/' . $offset;
    }
    else {
      $endpoint = '/hascoapi/api/process/elements/' . $page_size . '/' . $offset;
    }
    return $this->request('GET', $endpoint);
  }

  /**
   * Get all tasks belonging to a process.
   */
  public function getTasksByProcess(string $process_uri): array {
    // Get the process to find its top task URI.
    $process = $this->getByUri($process_uri);
    $top_task_uri = $process['hasTopTaskUri'] ?? $process['hasTopTask'] ?? NULL;

    if (!$top_task_uri) {
      return [];
    }

    // Recursively collect tasks starting from the top task.
    return $this->collectTaskTree($top_task_uri);
  }

  /**
   * Recursively collect tasks from a root task URI.
   */
  protected function collectTaskTree(string $task_uri, array &$visited = []): array {
    if (in_array($task_uri, $visited)) {
      return []; // Prevent infinite loops.
    }
    $visited[] = $task_uri;

    try {
      $task = $this->getByUri($task_uri);
    }
    catch (\Exception $e) {
      return [];
    }

    $tasks = [$task];

    // Get subtask URIs.
    $subtask_uris = $task['hasSubtaskUris'] ?? $task['hasSubtask'] ?? [];
    if (is_string($subtask_uris)) {
      $subtask_uris = [$subtask_uris];
    }

    foreach ($subtask_uris as $subtask_uri) {
      $subtasks = $this->collectTaskTree($subtask_uri, $visited);
      $tasks = array_merge($tasks, $subtasks);
    }

    return $tasks;
  }

  // ================================================================
  // Task operations
  // ================================================================

  /**
   * List tasks with pagination.
   */
  public function listTasks(int $page_size = 50, int $offset = 0): array {
    $endpoint = '/hascoapi/api/task/elements/' . $page_size . '/' . $offset;
    return $this->request('GET', $endpoint);
  }

  // ================================================================
  // Instrument operations
  // ================================================================

  /**
   * List instruments with pagination.
   */
  public function listInstruments(int $page_size = 50, int $offset = 0): array {
    $endpoint = '/hascoapi/api/instrument/elements/' . $page_size . '/' . $offset;
    return $this->request('GET', $endpoint);
  }

  /**
   * Get components of an instrument.
   */
  public function getInstrumentComponents(string $instrument_uri): array {
    $endpoint = '/hascoapi/api/instrument/components/' . rawurlencode($instrument_uri);
    return $this->request('GET', $endpoint);
  }

  /**
   * Search instruments by keyword (label/comment).
   *
   * Since hascoapi doesn't have a search endpoint, we fetch all and filter.
   */
  public function searchInstruments(string $query, int $limit = 20): array {
    $all = $this->listInstruments(200, 0);
    if (empty($query)) {
      return array_slice($all, 0, $limit);
    }

    $query_lower = mb_strtolower($query);
    $filtered = array_filter($all, function ($inst) use ($query_lower) {
      $label = mb_strtolower($inst['label'] ?? $inst['rdfs:label'] ?? '');
      $comment = mb_strtolower($inst['comment'] ?? $inst['rdfs:comment'] ?? '');
      $uri = mb_strtolower($inst['uri'] ?? $inst['hasURI'] ?? '');
      return str_contains($label, $query_lower)
        || str_contains($comment, $query_lower)
        || str_contains($uri, $query_lower);
    });

    return array_values(array_slice($filtered, 0, $limit));
  }

  // ================================================================
  // Utility operations
  // ================================================================

  /**
   * Generate a platform-compliant URI.
   *
   * Format: {namespace}/{PREFIX}{timestamp}{random4}{userId}
   */
  public function generateUri(string $element_type, string $user_id = ''): string {
    $namespace = $this->getNamespace();
    $prefix = self::URI_PREFIXES[strtolower($element_type)] ?? strtoupper(substr($element_type, 0, 3));
    $timestamp = (int) (microtime(TRUE) * 1000);
    $random = rand(1000, 9999);
    $user_suffix = preg_replace('/[^a-zA-Z0-9]/', '', $user_id);

    return $namespace . $prefix . $timestamp . $random . $user_suffix;
  }

  /**
   * Get repository info (including namespace).
   */
  public function getRepoInfo(): array {
    return $this->request('GET', '/hascoapi/api/repo');
  }

  /**
   * Get the default namespace URL.
   */
  protected function getNamespace(): string {
    if ($this->namespace) {
      return $this->namespace;
    }

    try {
      $repo = $this->getRepoInfo();
      $ns = $repo['hasDefaultNamespaceURL'] ?? $repo['namespace'] ?? 'http://example.org/pmsr/';
      $this->namespace = rtrim($ns, '/') . '/';
    }
    catch (\Exception $e) {
      $this->namespace = 'http://example.org/pmsr/';
    }

    return $this->namespace;
  }

}
