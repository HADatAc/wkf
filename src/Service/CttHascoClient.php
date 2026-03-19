<?php

namespace Drupal\ctt\Service;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
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
   * Key/value collection used to persist task instrument selection.
   *
   * This is a resilience layer so the Drupal CTT module can rehydrate
   * instrument + component selections even if hascoapi branches differ
   * in persistence behavior or response shapes.
   */
  const TASK_INSTRUMENT_KV_COLLECTION = 'ctt.task_instruments.v1';

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
    // CTT depends on REP and should always use the same API base URL.
    // We intentionally ignore ctt.settings.hasco_api_url to prevent
    // misconfiguration (e.g., localhost) from breaking production.
    if (!\Drupal::moduleHandler()->moduleExists('rep')) {
      throw new \RuntimeException('CTT requires the REP module to resolve hascoapi base URL.');
    }

    try {
      $rep_url = (string) \Drupal\rep\Utils::configApiUrl();
      $rep_url = trim($rep_url);
      if ($rep_url !== '' && strpos($rep_url, 'http://x.x.x.x:9000') === FALSE && filter_var($rep_url, FILTER_VALIDATE_URL)) {
        return rtrim($rep_url, '/');
      }
    }
    catch (\Exception $e) {
      // Ignore and throw a config error below.
    }

    throw new \RuntimeException('CTT is not configured: missing/invalid rep.settings.api_url. Configure the REP module settings.');
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
    catch (RequestException $e) {
      $status = NULL;
      $response_body = '';
      if ($e->hasResponse()) {
        $resp = $e->getResponse();
        if ($resp) {
          $status = $resp->getStatusCode();
          try {
            $response_body = (string) $resp->getBody();
          }
          catch (\Exception $ignored) {
            $response_body = '';
          }
        }
      }

      $truncated = $response_body;
      if (strlen($truncated) > 2000) {
        $truncated = substr($truncated, 0, 2000) . '…';
      }

      $this->logger->error('CTT API error: @method @url status=@status body=@body', [
        '@method' => $method,
        '@url' => $url,
        '@status' => $status !== NULL ? (string) $status : 'n/a',
        '@body' => $truncated !== '' ? $truncated : '(empty)',
      ]);

      $message = $e->getMessage();
      if ($status !== NULL) {
        $message = "HASCOAPI $method $endpoint failed with HTTP $status";
        if ($truncated !== '') {
          $message .= ": $truncated";
        }
      }

      throw new \RuntimeException($message, (int) ($status ?? 0), $e);
    }
    catch (\Exception $e) {
      $this->logger->error('CTT API error: @message', ['@message' => $e->getMessage()]);
      throw $e;
    }
  }

  /**
   * Get the key/value store used for task instrument overrides.
   */
  protected function getTaskInstrumentStore() {
    return \Drupal::keyValue(self::TASK_INSTRUMENT_KV_COLLECTION);
  }

  /**
   * Normalize URIs so minor formatting differences don't break lookups.
   *
   * hascoapi/clients sometimes alternate between '#/X' and '#X'.
   */
  protected function normalizeUriForKey(string $uri): string {
    $value = trim($uri);
    if ($value === '') {
      return '';
    }
    return str_replace('#/', '#', $value);
  }

  /**
   * Build a stable key for a task URI.
   */
  protected function taskInstrumentKey(string $task_uri): string {
    $normalized = $this->normalizeUriForKey($task_uri);
    return 'task:' . sha1($normalized);
  }

  /**
   * Normalize requiredInstrument input to a safe, minimal storage shape.
   *
   * Shape:
   *   [{ instrumentUri: string, requiredComponents: [{ componentUri: string }] }]
   */
  protected function normalizeRequiredInstrumentForStorage(array $required_instrument): array {
    $normalized = [];

    foreach ($required_instrument as $entry) {
      if (!is_array($entry)) {
        continue;
      }

      $instrument_uri = trim((string) ($entry['instrumentUri'] ?? $entry['usesInstrument'] ?? $entry['hasInstrument'] ?? ''));
      if ($instrument_uri === '') {
        continue;
      }

      $component_uris = [];
      $required_components = $entry['requiredComponents'] ?? $entry['requiredComponent'] ?? [];

      if (is_string($required_components)) {
        $required_components = [$required_components];
      }

      if (is_array($required_components)) {
        foreach ($required_components as $rc) {
          $component_uri = '';
          if (is_array($rc)) {
            $component_uri = (string) ($rc['componentUri'] ?? $rc['usesComponent'] ?? $rc['hasComponent'] ?? ($rc['component']['uri'] ?? '') ?? ($rc['uri'] ?? ''));
          }
          elseif (is_string($rc)) {
            $component_uri = $rc;
          }

          $component_uri = trim($component_uri);
          if ($component_uri !== '') {
            $component_uris[] = $component_uri;
          }
        }
      }

      $component_uris = array_values(array_unique($component_uris));
      $normalized[] = [
        'instrumentUri' => $instrument_uri,
        'requiredComponents' => array_map(function ($uri) {
          return ['componentUri' => $uri];
        }, $component_uris),
      ];
    }

    return $normalized;
  }

  /**
   * Persist (or clear) the task instrument selection locally.
   */
  protected function saveTaskInstrumentOverride(string $task_uri, array $required_instrument): void {
    $task_uri = $this->normalizeUriForKey($task_uri);
    $key = $this->taskInstrumentKey($task_uri);
    $store = $this->getTaskInstrumentStore();

    $normalized = $this->normalizeRequiredInstrumentForStorage($required_instrument);
    if (empty($normalized)) {
      $store->delete($key);
      $this->logger->debug('CTT KV override cleared for task @task', ['@task' => $task_uri]);
      return;
    }

    $store->set($key, [
      'taskUri' => $task_uri,
      'requiredInstrument' => $normalized,
      'savedAt' => time(),
    ]);

    $this->logger->debug('CTT KV override saved for task @task (instruments=@i)', [
      '@task' => $task_uri,
      '@i' => (string) count($normalized),
    ]);
  }

  /**
   * Load local override for a task, if present.
   */
  protected function loadTaskInstrumentOverride(string $task_uri): ?array {
    $raw_task_uri = trim($task_uri);
    $task_uri = $this->normalizeUriForKey($task_uri);
    $key = $this->taskInstrumentKey($task_uri);
    $store = $this->getTaskInstrumentStore();
    $value = $store->get($key);

    // Backward-compatibility: older versions hashed the raw task URI.
    if (!is_array($value) && $raw_task_uri !== '' && $raw_task_uri !== $task_uri) {
      $legacy_key = 'task:' . sha1($raw_task_uri);
      $legacy_value = $store->get($legacy_key);
      if (is_array($legacy_value)) {
        // Migrate to normalized key for future reads.
        $store->set($key, $legacy_value);
        $store->delete($legacy_key);
        $value = $legacy_value;
      }
    }

    if (!is_array($value)) {
      return NULL;
    }
    $ri = $value['requiredInstrument'] ?? NULL;
    if (!is_array($ri) || empty($ri)) {
      return NULL;
    }

    return $ri;
  }

  /**
   * Determine whether task.requiredInstrument contains usable selections.
   */
  protected function taskHasMeaningfulRequiredInstrument(array $task): bool {
    $ri = $task['requiredInstrument'] ?? NULL;
    if (!is_array($ri) || empty($ri)) {
      return FALSE;
    }
    foreach ($ri as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      $instrument_uri = trim((string) ($entry['instrumentUri'] ?? ''));
      if ($instrument_uri === '') {
        continue;
      }
      // If the API could not dereference RequiredInstrument objects, some branches
      // may surface their URIs as instrumentUri. Treat those as non-meaningful so
      // the local fallback can rehydrate the real instrument selection.
      if ($this->looksLikeRequiredInstrumentUri($instrument_uri)) {
        continue;
      }
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Best-effort check for URIs that point to RequiredInstrument objects.
   */
  protected function looksLikeRequiredInstrumentUri(string $uri): bool {
    $u = strtolower(trim($uri));
    if ($u === '') {
      return FALSE;
    }
    return (strpos($u, 'requiredinstrument') !== FALSE)
      || (strpos($u, ':/rin/') !== FALSE)
      || (strpos($u, '/rin/') !== FALSE)
      || (strpos($u, ':/requiredinstrument') !== FALSE);
  }

  /**
   * Apply a local requiredInstrument override to a task when needed.
   */
  protected function applyTaskInstrumentOverride(array $task, string $task_uri): array {
    $task_uri = $this->normalizeUriForKey($task['uri'] ?? $task['hasURI'] ?? $task_uri);
    $override = $this->loadTaskInstrumentOverride($task_uri);
    if (!$override) {
      return $task;
    }

    // If the API didn't provide anything usable, use the local override.
    if (!$this->taskHasMeaningfulRequiredInstrument($task)) {
      $task['requiredInstrument'] = $override;
      $this->logger->debug('CTT KV override applied for task @task (api missing/invalid)', ['@task' => $task_uri]);
      return $task;
    }

    // If the API provides an instrument but no components, fill components from override.
    $api_ri = $task['requiredInstrument'];
    if (!is_array($api_ri)) {
      return $task;
    }

    $override_by_inst = [];
    foreach ($override as $o) {
      if (!is_array($o)) {
        continue;
      }
      $inst = trim((string) ($o['instrumentUri'] ?? ''));
      if ($inst === '') {
        continue;
      }
      $override_by_inst[$inst] = $o;
    }

    $changed = FALSE;
    foreach ($api_ri as &$entry) {
      if (!is_array($entry)) {
        continue;
      }
      $inst = trim((string) ($entry['instrumentUri'] ?? ''));
      if ($inst === '' || !isset($override_by_inst[$inst])) {
        continue;
      }
      $api_components = $entry['requiredComponents'] ?? [];
      $has_any_components = is_array($api_components) && count($api_components) > 0;
      if (!$has_any_components) {
        $entry['requiredComponents'] = $override_by_inst[$inst]['requiredComponents'] ?? [];
        $changed = TRUE;
      }
    }
    unset($entry);

    if ($changed) {
      $task['requiredInstrument'] = $api_ri;
      $this->logger->debug('CTT KV override applied for task @task (filled components)', ['@task' => $task_uri]);
    }

    return $task;
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
   *
   * The hascoapi response is wrapped in { isSuccessful, body }.
   * We unwrap and return just the body (the entity data).
   */
  public function getByUri(string $uri): array {
    $endpoint = '/hascoapi/api/uri/' . rawurlencode($uri);
    $response = $this->request('GET', $endpoint);

    $body = $response['body'] ?? $response;
    // hascoapi sometimes returns a wrapper where body is a scalar string (e.g. an error message).
    // This service method is typed to return an array, so never return scalars.
    if (!is_array($body)) {
      $error_text = 'Unexpected response from hascoapi /uri';
      if (is_string($body) && trim($body) !== '') {
        $error_text = trim($body);
      }
      elseif (is_scalar($body)) {
        $error_text = (string) $body;
      }

      $is_successful = $response['isSuccessful'] ?? NULL;
      $this->logger->warning('CTT API /uri returned non-object body for @uri (isSuccessful=@ok): @err', [
        '@uri' => $uri,
        '@ok' => $is_successful === NULL ? 'n/a' : ($is_successful ? 'true' : 'false'),
        '@err' => $error_text,
      ]);

      return [
        'uri' => $uri,
        'error' => $error_text,
      ];
    }
    if (is_array($body) && $this->isTaskEntity($body)) {
      $body = $this->enrichTaskRequiredInstruments($body);
      $body = $this->applyTaskInstrumentOverride($body, $uri);
    }

    return $body;
  }

  /**
   * Fetch an Instrument by URI with fallbacks.
   *
   * Some hascoapi branches intermittently fail on /api/uri for Instrument URIs
   * (returning a scalar message in body). For the editor UX we can fall back to
   * the paginated instrument list.
   */
  public function getInstrumentByUri(string $instrument_uri): array {
    $instrument_uri = trim($instrument_uri);
    if ($instrument_uri === '') {
      return ['error' => 'Missing instrument URI'];
    }

    $entity = $this->getByUri($instrument_uri);
    if (is_array($entity) && empty($entity['error'])) {
      return $entity;
    }

    // Common normalization difference: hascoapi sometimes persists elements as '#/XYZ...'
    // while clients/UI may store '#XYZ...'. Try the alternate form first.
    $variant = $instrument_uri;
    if (strpos($instrument_uri, '#/') !== FALSE) {
      $variant = str_replace('#/', '#', $instrument_uri);
    }
    elseif (strpos($instrument_uri, '#') !== FALSE) {
      $variant = preg_replace('/#(?!\/)/', '#/', $instrument_uri, 1) ?? $instrument_uri;
    }

    if ($variant !== $instrument_uri) {
      $entity2 = $this->getByUri($variant);
      if (is_array($entity2) && empty($entity2['error'])) {
        return $entity2;
      }
    }

    // Fallback: scan list pages and find by URI.
    $page_size = 200;
    $max_pages = 10;

    for ($page = 0; $page < $max_pages; $page++) {
      $offset = $page * $page_size;
      $list_response = $this->listInstruments($page_size, $offset);
      $list = $list_response;
      if (is_array($list_response) && array_key_exists('body', $list_response)) {
        $list = $list_response['body'];
      }

      if (!is_array($list) || count($list) === 0) {
        break;
      }

      foreach ($list as $inst) {
        if (!is_array($inst)) {
          continue;
        }
        $candidate = (string) ($inst['uri'] ?? $inst['hasURI'] ?? '');
        if ($candidate !== '' && $this->normalizeUriForKey($candidate) === $this->normalizeUriForKey($instrument_uri)) {
          return $inst;
        }
      }
    }

    // As a last resort, return a minimal shape so the UI can still show something.
    return [
      'uri' => $instrument_uri,
      'label' => $instrument_uri,
      'error' => is_array($entity) && isset($entity['error']) ? (string) $entity['error'] : 'Instrument not found',
    ];
  }

  /**
   * Best-effort identification of Task entities.
   */
  protected function isTaskEntity(array $entity): bool {
    $type = strtolower((string) ($entity['hascoTypeUri'] ?? $entity['hascoType'] ?? $entity['typeUri'] ?? ''));
    if ($type !== '') {
      if (strpos($type, 'requiredinstrument') !== FALSE || strpos($type, 'requiredcomponent') !== FALSE) {
        return FALSE;
      }
      if (strpos($type, '#task') !== FALSE || preg_match('/\btask\b/', $type)) {
        return TRUE;
      }
    }

    // Fallback: tasks typically have subtask relationships.
    return array_key_exists('hasSubtaskUris', $entity) || array_key_exists('hasSupertaskUri', $entity);
  }

  /**
   * Ensure task.requiredInstrument is populated with resolvable shapes.
   *
   * hascoapi may return only hasRequiredInstrumentUris (URIs of RequiredInstrument objects),
   * so we dereference them and map into:
   *   requiredInstrument: [{ instrumentUri, requiredComponents: [{ componentUri }] }]
   */
  protected function enrichTaskRequiredInstruments(array $task): array {
    // Collect RequiredInstrument references.
    $ri_refs = [];

    if (isset($task['hasRequiredInstrumentUris'])) {
      if (is_array($task['hasRequiredInstrumentUris'])) {
        $ri_refs = array_merge($ri_refs, $task['hasRequiredInstrumentUris']);
      }
      elseif (is_string($task['hasRequiredInstrumentUris']) && trim($task['hasRequiredInstrumentUris']) !== '') {
        $ri_refs[] = $task['hasRequiredInstrumentUris'];
      }
    }

    $required_instrument_raw = [];
    if (isset($task['requiredInstrument']) && is_array($task['requiredInstrument'])) {
      $required_instrument_raw = $task['requiredInstrument'];
      foreach ($required_instrument_raw as $entry) {
        if (is_string($entry) && trim($entry) !== '') {
          $ri_refs[] = $entry;
        }
      }
    }

    $ri_refs = array_values(array_unique(array_filter(array_map(function ($v) {
      return is_string($v) ? trim($v) : '';
    }, $ri_refs), function ($v) {
      return $v !== '';
    })));

    // Resolve RequiredInstrument objects (or fall back to treating refs as instrument URIs).
    $resolved_required_instruments = [];
    foreach ($required_instrument_raw as $entry) {
      if (is_array($entry)) {
        $resolved_required_instruments[] = $entry;
      }
    }

    foreach ($ri_refs as $ref_uri) {
      // Skip if already included as an object.
      $already = FALSE;
      foreach ($resolved_required_instruments as $existing) {
        if (is_array($existing) && isset($existing['uri']) && $existing['uri'] === $ref_uri) {
          $already = TRUE;
          break;
        }
      }
      if ($already) {
        continue;
      }

      try {
        $ri_obj = $this->getByUri($ref_uri);
        if (is_array($ri_obj) && !empty($ri_obj)) {
          $resolved_required_instruments[] = $ri_obj;
          continue;
        }
      }
      catch (\Exception $e) {
        // Ignore and fall back.
      }

      // Fallback: treat reference as an instrument URI.
      $resolved_required_instruments[] = [
        'instrumentUri' => $ref_uri,
        'requiredComponents' => [],
      ];
    }

    $normalized = [];
    foreach ($resolved_required_instruments as $ri) {
      if (!is_array($ri)) {
        continue;
      }

      $instrument_uri = '';
      if (isset($ri['instrumentUri'])) {
        $instrument_uri = (string) $ri['instrumentUri'];
      }
      elseif (isset($ri['usesInstrument'])) {
        $instrument_uri = (string) $ri['usesInstrument'];
      }
      elseif (isset($ri['hasInstrument'])) {
        $instrument_uri = (string) $ri['hasInstrument'];
      }
      elseif (isset($ri['instrument']) && is_array($ri['instrument']) && isset($ri['instrument']['uri'])) {
        $instrument_uri = (string) $ri['instrument']['uri'];
      }
      $instrument_uri = trim($instrument_uri);
      if ($instrument_uri === '') {
        continue;
      }

      // Gather required component references.
      $rc_refs = [];
      foreach (['requiredComponents', 'requiredComponent', 'hasRequiredComponents', 'hasRequiredComponentUris', 'hasRequiredComponentURIs'] as $key) {
        if (!isset($ri[$key])) {
          continue;
        }
        $value = $ri[$key];
        if (is_array($value)) {
          $rc_refs = array_merge($rc_refs, $value);
        }
        elseif (is_string($value) && trim($value) !== '') {
          $rc_refs[] = $value;
        }
      }

      $required_components = [];
      foreach ($rc_refs as $rc_ref) {
        $component_uri = '';

        if (is_array($rc_ref)) {
          $component_uri = (string) (
            $rc_ref['componentUri']
            ?? $rc_ref['usesComponent']
            ?? $rc_ref['hasComponent']
            ?? ($rc_ref['component']['uri'] ?? '')
            ?? ($rc_ref['uri'] ?? '')
          );
        }
        elseif (is_string($rc_ref)) {
          $candidate = trim($rc_ref);
          if ($candidate === '') {
            continue;
          }
          // If this is a RequiredComponent URI, dereference to get the actual component URI.
          try {
            $rc_obj = $this->getByUri($candidate);
            if (is_array($rc_obj)) {
              $component_uri = (string) (
                $rc_obj['componentUri']
                ?? $rc_obj['usesComponent']
                ?? $rc_obj['hasComponent']
                ?? ($rc_obj['component']['uri'] ?? '')
              );
            }
          }
          catch (\Exception $e) {
            // ignore
          }

          if (trim($component_uri) === '') {
            // Fallback: assume it's already a component URI.
            $component_uri = $candidate;
          }
        }

        $component_uri = trim($component_uri);
        if ($component_uri !== '') {
          $required_components[] = ['componentUri' => $component_uri];
        }
      }

      // Deduplicate component URIs.
      $seen = [];
      $required_components = array_values(array_filter($required_components, function ($rc) use (&$seen) {
        $uri = is_array($rc) && isset($rc['componentUri']) ? trim((string) $rc['componentUri']) : '';
        if ($uri === '') return FALSE;
        if (isset($seen[$uri])) return FALSE;
        $seen[$uri] = TRUE;
        return TRUE;
      }));

      $normalized[] = [
        'instrumentUri' => $instrument_uri,
        'requiredComponents' => $required_components,
      ];
    }

    if (!empty($normalized)) {
      $task['requiredInstrument'] = $normalized;
    }

    return $task;
  }

  /**
   * Create an element of the given type.
   */
  public function createElement(string $element_type, array $data): array {
    if (strtolower($element_type) === 'process') {
      if (!empty($data['managerEmail']) && empty($data['hasSIRManagerEmail'])) {
        $data['hasSIRManagerEmail'] = $data['managerEmail'];
      }
      if (isset($data['managerEmail'])) {
        unset($data['managerEmail']);
      }
    }

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

  /**
   * Set required instruments for a task.
   *
   * hascoapi expects a JSON payload:
   * { taskuri: string, requiredInstrument: [{ instrumentUri, requiredComponents?: [{componentUri, containerSlotUri}] }] }
   *
   * Returns the updated task body (unwrapped).
   */
  public function setTaskRequiredInstruments(string $task_uri, array $required_instrument): array {
    // IMPORTANT: hascoapi's TaskAPI.setRequiredInstruments implementation is strict and
    // currently rejects requiredComponents unless containerSlotUri is present and reads
    // componentUri from the wrong JSON level.
    //
    // To keep Drupal+CTT working across hascoapi branches (and without backend changes),
    // we:
    //  1) Persist the full instrument+component selection locally (KV override), and
    //  2) Send ONLY instrumentUri entries to hascoapi so the call succeeds.

    // Persist a local copy (full fidelity) to remain robust to API branch differences.
    $this->saveTaskInstrumentOverride($task_uri, $required_instrument);

    // Strip components for hascoapi payload.
    $api_required_instrument = [];
    foreach ($required_instrument as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      $instrument_uri = trim((string) ($entry['instrumentUri'] ?? ''));
      if ($instrument_uri === '') {
        continue;
      }
      $api_required_instrument[] = [
        'instrumentUri' => $instrument_uri,
      ];
    }

    // Avoid calling hascoapi with an empty list (it 400s). In this case we only update
    // the local override.
    if (!empty($api_required_instrument)) {
      $payload = [
        // Note: hascoapi Java controller expects lowercase 'taskuri'.
        'taskuri' => $task_uri,
        'requiredInstrument' => $api_required_instrument,
      ];

      // This endpoint returns plain text on success (not JSON). We treat it as best-effort
      // and then fetch the task by URI to return a consistent JSON object to the frontend.
      $this->request('POST', '/hascoapi/api/task/instruments', [
        'json' => $payload,
      ]);
    }

    return $this->getByUri($task_uri);
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
   * Get container slots of an instrument.
   */
  public function getInstrumentContainerSlots(string $instrument_uri): array {
    // hascoapi supports an instrument/containerslots endpoint, but in some deployments
    // the instrument URI is also used as the container URI and the slot-elements endpoint
    // is the one that actually returns the slot list (with embedded component objects).

    $primary = '/hascoapi/api/instrument/containerslots/' . rawurlencode($instrument_uri);
    $fallback = '/hascoapi/api/slotelements/bycontainer/' . rawurlencode($instrument_uri);

    try {
      $response = $this->request('GET', $primary);
      // If the response is a non-list body, fall back.
      $body = $response['body'] ?? $response;
      if (is_string($body) || (!is_array($body) && $body !== NULL)) {
        return $this->request('GET', $fallback);
      }
      return $response;
    }
    catch (\Exception $e) {
      // Primary endpoint failed - try fallback before giving up.
      try {
        return $this->request('GET', $fallback);
      }
      catch (\Exception $e2) {
        // Both endpoints failed - return empty array rather than crashing.
        $this->logger->warning('Container slots: both endpoints failed for @uri', [
          '@uri' => $instrument_uri,
        ]);
        return [];
      }
    }
  }

  /**
   * Search instruments by keyword (label/comment).
   *
   * Since hascoapi doesn't have a search endpoint, we fetch all and filter.
   */
  public function searchInstruments(string $query, int $limit = 20): array {
    $all_response = $this->listInstruments(200, 0);
    $all = $all_response;
    if (is_array($all_response) && array_key_exists('body', $all_response)) {
      $all = $all_response['body'];
    }
    if (!is_array($all)) {
      $all = [];
    }
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
   * Get available repository languages.
   */
  public function getRepoLanguages(): array {
    $result = $this->request('GET', '/hascoapi/api/repo/table/languages');
    if (isset($result['body']) && is_array($result['body'])) {
      return $result['body'];
    }
    return $result;
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

  // ================================================================
  // Debug helpers (Drupal-side resilience)
  // ================================================================

  /**
   * Debug endpoint helper: returns the persisted key/value override for a task.
   *
   * This is intended for troubleshooting only.
   */
  public function debugTaskInstrumentOverride(string $task_uri): array {
    $raw_task_uri = trim($task_uri);
    $normalized_task_uri = $this->normalizeUriForKey($raw_task_uri);

    $store = $this->getTaskInstrumentStore();
    $key = $this->taskInstrumentKey($normalized_task_uri);
    $value = $store->get($key);

    $legacy_key = $raw_task_uri !== '' ? ('task:' . sha1($raw_task_uri)) : '';
    $legacy_value = $legacy_key !== '' ? $store->get($legacy_key) : NULL;

    return [
      'rawTaskUri' => $raw_task_uri,
      'normalizedTaskUri' => $normalized_task_uri,
      'key' => $key,
      'hasValue' => is_array($value),
      'value' => $value,
      'legacyKey' => $legacy_key,
      'hasLegacyValue' => is_array($legacy_value),
      'legacyValue' => $legacy_value,
    ];
  }

}
