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
   * Cached map of legacy workflow source URIs to materialized workflow URIs.
   *
   * @var array<string, string>|null
   */
  protected $workflowUriAliasMap = NULL;

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
   * Normalize and validate candidate API URL.
   */
  protected function normalizeApiBaseUrl(string $candidate): string {
    $url = trim($candidate);
    if ($url === '') {
      return '';
    }

    if (strpos($url, 'http://x.x.x.x:9000') !== FALSE) {
      return '';
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
      return '';
    }

    return rtrim($url, '/');
  }

  /**
   * Resolve optional API URL override from environment variables.
   */
  protected function getEnvironmentApiUrl(): string {
    foreach (['PMSR_HASCOAPI_URL', 'HASCOAPI_URL'] as $envName) {
      $value = getenv($envName);
      if (!is_string($value)) {
        continue;
      }

      $normalized = $this->normalizeApiBaseUrl($value);
      if ($normalized !== '') {
        return $normalized;
      }
    }

    return '';
  }

  /**
   * Check whether a host points to loopback aliases.
   */
  protected function isLocalhostHost(?string $host): bool {
    $normalized = strtolower(trim((string) $host));
    return $normalized === 'localhost' || $normalized === '127.0.0.1' || $normalized === '::1';
  }

  /**
   * Check whether API URL targets localhost.
   */
  protected function isLocalhostApiUrl(string $url): bool {
    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
      return FALSE;
    }

    return $this->isLocalhostHost($host);
  }

  /**
   * Determine if current request originates from localhost.
   */
  protected function isLocalRequestContext(): bool {
    if (PHP_SAPI === 'cli') {
      return TRUE;
    }

    try {
      $request = \Drupal::requestStack()->getCurrentRequest();
      if (!$request) {
        return TRUE;
      }

      return $this->isLocalhostHost($request->getHost());
    }
    catch (\Throwable $e) {
      // Be permissive when request context is unavailable.
      return TRUE;
    }
  }

  /**
   * Get the hascoapi base URL from configuration.
   */
  protected function getApiUrl(): string {
    // CTT depends on REP and should always use the same API base URL.
    // We intentionally ignore ctt.settings.hasco_api_url to prevent
    // misconfiguration (e.g., localhost) from breaking production.
    if (!\Drupal::moduleHandler()->moduleExists('rep')) {
      throw new \RuntimeException('CTT requires the REP module to resolve hascoapi base URL.');
    }

    $apiUrl = $this->getEnvironmentApiUrl();

    try {
      if ($apiUrl === '') {
        $repUrl = $this->normalizeApiBaseUrl((string) \Drupal\rep\Utils::configApiUrl());
        if ($repUrl !== '') {
          $apiUrl = $repUrl;
        }
      }
    }
    catch (\Exception $e) {
      // Ignore and throw a config error below.
    }

    if ($apiUrl === '') {
      throw new \RuntimeException('CTT is not configured: missing/invalid rep.settings.api_url. Configure the REP module settings with a routable HASCOAPI URL or set PMSR_HASCOAPI_URL.');
    }

    if ($this->isLocalhostApiUrl($apiUrl) && !$this->isLocalRequestContext()) {
      throw new \RuntimeException('CTT is not configured for distributed deployment: HASCOAPI URL resolves to localhost. Configure rep.settings.api_url (or PMSR_HASCOAPI_URL) with a routable API host.');
    }

    static $localhostWarningLogged = FALSE;
    if ($this->isLocalhostApiUrl($apiUrl) && !$localhostWarningLogged) {
      $this->logger->warning('CTT is using localhost HASCOAPI URL (@url). This is valid only when Drupal and HASCOAPI run on the same machine.', [
        '@url' => $apiUrl,
      ]);
      $localhostWarningLogged = TRUE;
    }

    return $apiUrl;
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
   * Normalize URI keys for resilient alias lookups.
   */
  protected function normalizeUriAliasKey(string $uri): string {
    $normalized = trim(rawurldecode($uri));
    if ($normalized === '') {
      return '';
    }

    $normalized = str_replace('#/', '#', $normalized);
    return strtolower(rtrim($normalized, '/'));
  }

  /**
   * Build legacy workflow URI candidates from pmsr/http process URI formats.
   */
  protected function buildLegacyWorkflowSourceCandidates(string $uri): array {
    $candidate = trim(rawurldecode($uri));
    if ($candidate === '') {
      return [];
    }

    $workflow_code = '';
    $process_code = '';

    if (preg_match('#^pmsr:/([^/]+)/PROC/([0-9]+)$#i', $candidate, $matches)) {
      $workflow_code = trim((string) ($matches[1] ?? ''));
      $process_code = trim((string) ($matches[2] ?? ''));
    }
    else {
      $parts = parse_url($candidate);
      $path = is_array($parts) ? trim((string) ($parts['path'] ?? ''), '/') : '';
      if ($path !== '' && preg_match('#(?:^|/)ont/pmsr/([^/]+)/PROC/([0-9]+)$#i', $path, $matches)) {
        $workflow_code = trim((string) ($matches[1] ?? ''));
        $process_code = trim((string) ($matches[2] ?? ''));
      }
    }

    if ($workflow_code === '') {
      return [];
    }

    $workflow_variants = [$workflow_code];
    if (preg_match('/^(.*)_C([0-9]{1,4})$/i', $workflow_code, $workflow_matches)) {
      $workflow_stem = trim((string) ($workflow_matches[1] ?? ''));
      $workflow_number = (int) ($workflow_matches[2] ?? 1);
      if ($workflow_stem !== '') {
        $workflow_variants[] = $workflow_stem . '_' . str_pad((string) max(1, $workflow_number), 4, '0', STR_PAD_LEFT);
      }
    }
    elseif (preg_match('/^(.*)_([0-9]{4})$/', $workflow_code, $workflow_matches)) {
      $workflow_stem = trim((string) ($workflow_matches[1] ?? ''));
      $workflow_number = (int) ($workflow_matches[2] ?? 1);
      if ($workflow_stem !== '') {
        $workflow_variants[] = $workflow_stem . '_C' . str_pad((string) max(1, $workflow_number), 3, '0', STR_PAD_LEFT);
      }
    }

    $process_variants = ['0001'];
    $digits = preg_replace('/[^0-9]/', '', $process_code);
    if ($digits !== '') {
      $process_variants[] = str_pad((string) ((int) $digits), 4, '0', STR_PAD_LEFT);
    }

    $candidates = [];
    foreach (array_values(array_unique($workflow_variants)) as $workflow_variant) {
      $workflow_variant = trim($workflow_variant);
      if ($workflow_variant === '') {
        continue;
      }

      foreach (array_values(array_unique($process_variants)) as $process_variant) {
        $candidates[] = 'pmsr:/' . $workflow_variant . '/PROC/' . $process_variant;
        $candidates[] = 'http://pmsr.net/ont/pmsr/' . $workflow_variant . '/PROC/' . $process_variant;
        $candidates[] = 'https://pmsr.net/ont/pmsr/' . $workflow_variant . '/PROC/' . $process_variant;
      }
    }

    return array_values(array_unique(array_filter($candidates, function ($value) {
      return trim((string) $value) !== '';
    })));
  }

  /**
   * Load alias map from latest materialized system workflow evidence.
   */
  protected function getMaterializedWorkflowAliasMap(): array {
    if (is_array($this->workflowUriAliasMap)) {
      return $this->workflowUriAliasMap;
    }

    $map = [];
    $drupal_root = rtrim((string) \Drupal::root(), DIRECTORY_SEPARATOR);
    $evidence_path = $drupal_root . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'custom' . DIRECTORY_SEPARATOR . 'evidence' . DIRECTORY_SEPARATOR . 'system_workflows_materialized_latest.json';

    if (!is_file($evidence_path) || !is_readable($evidence_path)) {
      $this->workflowUriAliasMap = $map;
      return $map;
    }

    $raw = @file_get_contents($evidence_path);
    if (!is_string($raw) || trim($raw) === '') {
      $this->workflowUriAliasMap = $map;
      return $map;
    }

    $decoded = json_decode($raw, TRUE);
    if (!is_array($decoded)) {
      $this->workflowUriAliasMap = $map;
      return $map;
    }

    $rows = $decoded['materializedWorkflows'] ?? [];
    if (!is_array($rows)) {
      $this->workflowUriAliasMap = $map;
      return $map;
    }

    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }

      $source_uri = trim((string) ($row['sourceWorkflowUri'] ?? ''));
      $workflow_uri = trim((string) ($row['workflowUri'] ?? ''));
      if ($source_uri === '' || $workflow_uri === '') {
        continue;
      }

      $target_key = $this->normalizeUriAliasKey($workflow_uri);
      if ($target_key === '') {
        continue;
      }

      $source_candidates = array_merge([$source_uri], $this->buildLegacyWorkflowSourceCandidates($source_uri));
      foreach ($source_candidates as $source_candidate) {
        $source_key = $this->normalizeUriAliasKey($source_candidate);
        if ($source_key !== '') {
          $map[$source_key] = $workflow_uri;
        }
      }

      $map[$target_key] = $workflow_uri;
    }

    $this->workflowUriAliasMap = $map;
    return $map;
  }

  /**
   * Build ordered URI lookup candidates for resilient process resolution.
   */
  protected function buildUriLookupCandidates(string $uri): array {
    $requested_uri = trim(rawurldecode($uri));
    if ($requested_uri === '') {
      return [];
    }

    $candidates = [$requested_uri];

    if (strpos($requested_uri, '#/') !== FALSE) {
      $candidates[] = str_replace('#/', '#', $requested_uri);
    }
    elseif (strpos($requested_uri, '#') !== FALSE) {
      $candidates[] = preg_replace('/#(?!\/)/', '#/', $requested_uri, 1) ?? $requested_uri;
    }

    $candidates = array_merge($candidates, $this->buildLegacyWorkflowSourceCandidates($requested_uri));

    $alias_map = $this->getMaterializedWorkflowAliasMap();
    foreach ($candidates as $candidate_uri) {
      $candidate_key = $this->normalizeUriAliasKey($candidate_uri);
      if ($candidate_key !== '' && isset($alias_map[$candidate_key])) {
        $candidates[] = $alias_map[$candidate_key];
      }
    }

    $ordered = [];
    foreach ($candidates as $candidate_uri) {
      $candidate_uri = trim((string) $candidate_uri);
      if ($candidate_uri === '') {
        continue;
      }
      if (!isset($ordered[$candidate_uri])) {
        $ordered[$candidate_uri] = TRUE;
      }
    }

    return array_slice(array_keys($ordered), 0, 16);
  }

  /**
   * Fetch and unwrap entity response for /hascoapi/api/uri/{uri}.
   *
   * Returns an empty array for soft misses (e.g. scalar body error) and
   * populates $soft_error with a normalized message.
   */
  protected function fetchEntityByUri(string $uri, string &$soft_error = ''): array {
    $soft_error = '';
    $endpoint = '/hascoapi/api/uri/' . rawurlencode($uri);
    $response = $this->request('GET', $endpoint);

    $body = $response['body'] ?? $response;
    if (is_array($body) && !empty($body)) {
      return $body;
    }

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

    $soft_error = $error_text;
    return [];
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
   *   [{ instrumentUri: string, requiredComponents: [{ componentUri: string, containerSlotUri?: string }] }]
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

      $component_map = [];
      $required_components = $entry['requiredComponents'] ?? $entry['requiredComponent'] ?? [];

      if (is_string($required_components)) {
        $required_components = [$required_components];
      }

      if (is_array($required_components)) {
        foreach ($required_components as $rc) {
          $component_uri = '';
          $container_slot_uri = '';
          if (is_array($rc)) {
            $component_uri = (string) ($rc['componentUri'] ?? $rc['usesComponent'] ?? $rc['hasComponent'] ?? ($rc['component']['uri'] ?? '') ?? ($rc['uri'] ?? ''));
            $container_slot_uri = (string) ($rc['containerSlotUri'] ?? $rc['slotUri'] ?? $rc['hasContainerSlot'] ?? ($rc['containerSlot']['uri'] ?? '') ?? '');
          }
          elseif (is_string($rc)) {
            $component_uri = $rc;
          }

          $component_uri = trim($component_uri);
          if ($component_uri !== '') {
            $payload = ['componentUri' => $component_uri];
            $container_slot_uri = trim($container_slot_uri);
            if ($container_slot_uri !== '') {
              $payload['containerSlotUri'] = $container_slot_uri;
            }

            // Keep first occurrence to preserve user ordering and avoid duplicates.
            if (!isset($component_map[$component_uri])) {
              $component_map[$component_uri] = $payload;
            }
          }
        }
      }

      $normalized[] = [
        'instrumentUri' => $instrument_uri,
        'requiredComponents' => array_values($component_map),
      ];
    }

    return $normalized;
  }

  /**
   * Build API payload entries for task required instruments.
   */
  protected function buildTaskRequiredInstrumentApiEntries(array $required_instrument, bool $include_components = FALSE): array {
    $normalized = $this->normalizeRequiredInstrumentForStorage($required_instrument);
    $entries = [];

    foreach ($normalized as $entry) {
      if (!is_array($entry)) {
        continue;
      }

      $instrument_uri = trim((string) ($entry['instrumentUri'] ?? ''));
      if ($instrument_uri === '') {
        continue;
      }

      $payload_entry = ['instrumentUri' => $instrument_uri];
      if ($include_components) {
        $required_components = $entry['requiredComponents'] ?? [];
        if (is_array($required_components) && !empty($required_components)) {
          $payload_entry['requiredComponents'] = $required_components;
        }
      }

      $entries[] = $payload_entry;
    }

    return $entries;
  }

  /**
   * Persist (or clear) the task instrument selection locally.
   */
  protected function saveTaskInstrumentOverride(string $task_uri, array $required_instrument): void {
    $task_uri = $this->normalizeUriForKey($task_uri);
    $key = $this->taskInstrumentKey($task_uri);
    $store = $this->getTaskInstrumentStore();

    $normalized = $this->normalizeRequiredInstrumentForStorage($required_instrument);
    // IMPORTANT: we must distinguish "no override" vs "explicitly cleared".
    // Clearing instruments cannot be reliably persisted to hascoapi (empty payload 400s),
    // so we store an explicit empty override marker to hide any stale API values.
    $store->set($key, [
      'taskUri' => $task_uri,
      'requiredInstrument' => $normalized,
      'savedAt' => time(),
      'cleared' => empty($normalized),
    ]);

    if (empty($normalized)) {
      $this->logger->debug('CTT KV override saved (cleared) for task @task', ['@task' => $task_uri]);
      return;
    }

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
    if (!is_array($ri)) {
      return NULL;
    }

    // If the override record exists but the array is empty, treat it as an explicit "cleared" marker.
    // This ensures the frontend sees instruments as cleared even if hascoapi still has stale values.
    if (empty($ri)) {
      return [];
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
    if ($override === NULL) {
      return $task;
    }

    // Explicit cleared override: hide any stale API values.
    if (is_array($override) && empty($override)) {
      $task['requiredInstrument'] = [];
      $task['hasRequiredInstrumentUris'] = [];
      $this->logger->debug('CTT KV override applied for task @task (cleared)', ['@task' => $task_uri]);
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
    $requested_uri = trim($uri);
    if ($requested_uri === '') {
      return [
        'uri' => $uri,
        'error' => 'Missing URI',
      ];
    }

    $candidates = $this->buildUriLookupCandidates($requested_uri);
    if (empty($candidates)) {
      return [
        'uri' => $requested_uri,
        'error' => 'Missing URI',
      ];
    }

    $fallback_error = '';
    $last_exception = NULL;

    foreach ($candidates as $candidate_uri) {
      $soft_error = '';
      try {
        $body = $this->fetchEntityByUri($candidate_uri, $soft_error);
      }
      catch (\Exception $e) {
        // Preserve previous behavior for hard failures on the initial URI.
        if ($candidate_uri === $requested_uri) {
          throw $e;
        }
        $last_exception = $e;
        continue;
      }

      if (!empty($body)) {
        if ($candidate_uri !== $requested_uri) {
          $this->logger->debug('CTT URI fallback resolved @requested to @resolved', [
            '@requested' => $requested_uri,
            '@resolved' => $candidate_uri,
          ]);
        }

        if ($this->isTaskEntity($body)) {
          $task_uri = trim((string) ($body['uri'] ?? $body['hasURI'] ?? $candidate_uri));
          $body = $this->enrichTaskRequiredInstruments($body);
          $body = $this->applyTaskInstrumentOverride($body, $task_uri);
        }

        return $body;
      }

      if ($fallback_error === '' && $soft_error !== '') {
        $fallback_error = $soft_error;
      }
    }

    if ($fallback_error !== '') {
      return [
        'uri' => $requested_uri,
        'error' => $fallback_error,
      ];
    }

    if ($last_exception instanceof \Exception) {
      throw $last_exception;
    }

    return [
      'uri' => $requested_uri,
      'error' => 'Unexpected response from hascoapi /uri',
    ];
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
   * Determine if an API response indicates endpoint-level incompatibility.
   */
  protected function shouldFallbackToBodyEndpointFromResponse(array $response): bool {
    if (($response['isSuccessful'] ?? NULL) !== FALSE) {
      return FALSE;
    }

    $error_code = strtolower(trim((string) ($response['error']['code'] ?? $response['code'] ?? '')));
    if (in_array($error_code, ['endpoint_not_found', 'not_found', 'method_not_allowed', 'unsupported_media_type'], TRUE)) {
      return TRUE;
    }

    $message_parts = [
      (string) ($response['error']['message'] ?? ''),
      (string) ($response['body'] ?? ''),
    ];
    $message = strtolower(trim(implode(' ', array_filter($message_parts))));

    if ($message === '') {
      return FALSE;
    }

    return (strpos($message, 'endpoint') !== FALSE && strpos($message, 'not found') !== FALSE)
      || strpos($message, 'method not allowed') !== FALSE
      || strpos($message, 'unsupported media type') !== FALSE;
  }

  /**
   * Determine if an exception suggests trying body-based create/delete endpoints.
   */
  protected function shouldFallbackToBodyEndpointFromException(\Throwable $e): bool {
    $status = (int) $e->getCode();
    if (in_array($status, [404, 405, 415], TRUE)) {
      return TRUE;
    }

    $message = strtolower($e->getMessage());
    return strpos($message, 'endpoint_not_found') !== FALSE
      || (strpos($message, 'not found') !== FALSE && strpos($message, 'hascoapi') !== FALSE)
      || strpos($message, 'method not allowed') !== FALSE
      || strpos($message, 'unsupported media type') !== FALSE;
  }

  /**
   * Fail fast when the API explicitly reports an unsuccessful operation.
   */
  protected function assertOperationSuccessful(array $response, string $operation): void {
    if (($response['isSuccessful'] ?? NULL) !== FALSE) {
      return;
    }

    $error_code = trim((string) ($response['error']['code'] ?? $response['code'] ?? ''));
    $error_message = trim((string) ($response['error']['message'] ?? $response['body'] ?? ''));

    $parts = [];
    if ($error_code !== '') {
      $parts[] = $error_code;
    }
    if ($error_message !== '') {
      $parts[] = $error_message;
    }

    $suffix = !empty($parts) ? (': ' . implode(' - ', $parts)) : '';
    throw new \RuntimeException($operation . ' reported isSuccessful=false' . $suffix);
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

    try {
      $response = $this->request('POST', $endpoint);
      if (!$this->shouldFallbackToBodyEndpointFromResponse($response)) {
        return $response;
      }

      $this->logger->warning('CTT create endpoint fallback activated for @type (path mode returned endpoint incompatibility).', [
        '@type' => $element_type,
      ]);
    }
    catch (\Throwable $e) {
      if (!$this->shouldFallbackToBodyEndpointFromException($e)) {
        throw $e;
      }

      $this->logger->warning('CTT create endpoint fallback activated for @type after path mode failure: @message', [
        '@type' => $element_type,
        '@message' => $e->getMessage(),
      ]);
    }

    $body_endpoint = '/hascoapi/api/' . $element_type . '/create';
    $response = $this->request('POST', $body_endpoint, [
      'json' => $data,
    ]);
    $this->assertOperationSuccessful($response, 'Create ' . $element_type);
    return $response;
  }

  /**
   * Delete an element by URI.
   */
  public function deleteElement(string $element_type, string $uri): array {
    $endpoint = '/hascoapi/api/' . $element_type . '/delete/' . rawurlencode($uri);

    try {
      $response = $this->request('POST', $endpoint);
      if (!$this->shouldFallbackToBodyEndpointFromResponse($response)) {
        return $response;
      }

      $this->logger->warning('CTT delete endpoint fallback activated for @type (path mode returned endpoint incompatibility).', [
        '@type' => $element_type,
      ]);
    }
    catch (\Throwable $e) {
      if (!$this->shouldFallbackToBodyEndpointFromException($e)) {
        throw $e;
      }

      $this->logger->warning('CTT delete endpoint fallback activated for @type after path mode failure: @message', [
        '@type' => $element_type,
        '@message' => $e->getMessage(),
      ]);
    }

    $body_endpoint = '/hascoapi/api/' . $element_type . '/delete';
    $response = $this->request('POST', $body_endpoint, [
      'json' => [
        'uri' => $uri,
        'hasURI' => $uri,
      ],
    ]);
    $this->assertOperationSuccessful($response, 'Delete ' . $element_type);
    return $response;
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
    $top_task_uri = trim((string) ($process['hasTopTaskUri'] ?? $process['hasTopTask'] ?? ''));

    if ($top_task_uri === '') {
      return [];
    }

    // Prefer explicit tree traversal when subtasks are available.
    $visited = [];
    $tree_tasks = $this->collectTaskTree($top_task_uri, $visited);
    $tree_tasks = $this->normalizeTaskCollectionForCanvas($tree_tasks);

    // Fallback for legacy ingested templates where only parent links are
    // available (for example hasParentTask) and root traversal returns 1 node.
    if (count($tree_tasks) > 1) {
      return $tree_tasks;
    }

    $fallback_tasks = $this->collectTasksByProcessFallback($process_uri, $top_task_uri);
    if (empty($fallback_tasks)) {
      return $tree_tasks;
    }

    $merged = $this->mergeTaskCollections($tree_tasks, $fallback_tasks);
    return $this->normalizeTaskCollectionForCanvas($merged);
  }

  /**
   * Recursively collect tasks from a root task URI.
   */
  protected function collectTaskTree(string $task_uri, array &$visited = []): array {
    $task_key = $this->normalizeUriForKey($task_uri);
    if ($task_key === '' || isset($visited[$task_key])) {
      return []; // Prevent infinite loops.
    }
    $visited[$task_key] = TRUE;

    $task = $this->resolveTaskByUri($task_uri);
    if (!is_array($task)) {
      return [];
    }

    $task = $this->normalizeTaskShapeForCanvas($task);

    $resolved_task_uri = trim((string) ($task['uri'] ?? $task['hasURI'] ?? $task_uri));
    $resolved_key = $this->normalizeUriForKey($resolved_task_uri);
    if ($resolved_key !== '') {
      $visited[$resolved_key] = TRUE;
    }

    $tasks = [$task];

    foreach ($this->extractTaskSubtaskUris($task) as $subtask_uri) {
      $subtasks = $this->collectTaskTree($subtask_uri, $visited);
      $tasks = array_merge($tasks, $subtasks);
    }

    return $tasks;
  }

  /**
   * Extract canonical task URI from mixed payload shapes.
   */
  protected function extractTaskUri($value): string {
    if (is_string($value)) {
      $candidate = trim($value);
      return $this->looksLikeUriValue($candidate) ? $candidate : '';
    }

    if (is_array($value)) {
      $candidate = trim((string) ($value['uri'] ?? $value['hasURI'] ?? $value['taskUri'] ?? $value['hasTaskUri'] ?? ''));
      return $this->looksLikeUriValue($candidate) ? $candidate : '';
    }

    if (is_object($value)) {
      $candidate = trim((string) ($value->uri ?? $value->hasURI ?? $value->taskUri ?? $value->hasTaskUri ?? ''));
      return $this->looksLikeUriValue($candidate) ? $candidate : '';
    }

    return '';
  }

  /**
   * Best-effort URI shape guard for mixed API payloads.
   */
  protected function looksLikeUriValue(string $value): bool {
    $candidate = trim($value);
    if ($candidate === '') {
      return FALSE;
    }

    if (preg_match('/\s/', $candidate)) {
      return FALSE;
    }

    if (strpos($candidate, '://') !== FALSE) {
      return TRUE;
    }

    if (stripos($candidate, 'pmsr:/') === 0 || stripos($candidate, 'urn:') === 0) {
      return TRUE;
    }

    return strpos($candidate, '/TSK/') !== FALSE
      || strpos($candidate, '/PROC/') !== FALSE
      || strpos($candidate, '/PC0') !== FALSE;
  }

  /**
   * Parse delimited URI strings used by some hascoapi payload variants.
   */
  protected function parseDelimitedUris(string $value): array {
    $raw = trim($value);
    if ($raw === '') {
      return [];
    }

    $chunks = preg_split('/[;,|]+/', $raw);
    if (!is_array($chunks)) {
      $chunks = [$raw];
    }

    $values = [];
    foreach ($chunks as $chunk) {
      $candidate = trim((string) $chunk);
      if ($candidate !== '' && $this->looksLikeUriValue($candidate)) {
        $values[$this->normalizeUriForKey($candidate)] = $candidate;
      }
    }

    return array_values(array_filter($values, function ($value) {
      return trim((string) $value) !== '';
    }));
  }

  /**
   * Extract task URIs from strings/arrays/objects.
   */
  protected function extractTaskUrisFromValue($value): array {
    $uris = [];

    if (is_string($value)) {
      foreach ($this->parseDelimitedUris($value) as $candidate) {
        $key = $this->normalizeUriForKey($candidate);
        if ($key !== '') {
          $uris[$key] = $candidate;
        }
      }
      return array_values($uris);
    }

    if (is_object($value)) {
      $value = (array) $value;
    }

    if (!is_array($value)) {
      return [];
    }

    $direct = $this->extractTaskUri($value);
    if ($direct !== '') {
      $uris[$this->normalizeUriForKey($direct)] = $direct;
    }

    foreach ($value as $item) {
      foreach ($this->extractTaskUrisFromValue($item) as $candidate) {
        $key = $this->normalizeUriForKey($candidate);
        if ($key !== '') {
          $uris[$key] = $candidate;
        }
      }
    }

    return array_values($uris);
  }

  /**
   * Extract child task URIs from mixed subtask payload fields.
   */
  protected function extractTaskSubtaskUris(array $task): array {
    $uris = [];
    $raw_candidates = [
      $task['hasSubtaskUris'] ?? NULL,
      $task['hasSubtask'] ?? NULL,
      $task['hasSubtasks'] ?? NULL,
      $task['subtaskUris'] ?? NULL,
      $task['hasSubtaskUri'] ?? NULL,
    ];

    foreach ($raw_candidates as $raw_candidate) {
      foreach ($this->extractTaskUrisFromValue($raw_candidate) as $candidate_uri) {
        $key = $this->normalizeUriForKey($candidate_uri);
        if ($key !== '') {
          $uris[$key] = $candidate_uri;
        }
      }
    }

    return array_values($uris);
  }

  /**
   * Extract parent task URI from legacy and current payload variants.
   */
  protected function extractTaskParentUri(array $task): string {
    $raw_candidates = [
      $task['hasSupertaskUri'] ?? NULL,
      $task['supertaskUri'] ?? NULL,
      $task['hasSupertask'] ?? NULL,
      $task['hasParentTaskUri'] ?? NULL,
      $task['parentTaskUri'] ?? NULL,
      $task['hasParentTask'] ?? NULL,
      $task['parentTask'] ?? NULL,
    ];

    foreach ($raw_candidates as $raw_candidate) {
      $uris = $this->extractTaskUrisFromValue($raw_candidate);
      if (!empty($uris)) {
        return trim((string) reset($uris));
      }
    }

    return '';
  }

  /**
   * Extract process URI from known task payload fields.
   */
  protected function extractTaskProcessUri(array $task): string {
    $raw_candidates = [
      $task['processUri'] ?? NULL,
      $task['workflowUri'] ?? NULL,
      $task['workflowuri'] ?? NULL,
      $task['hasWorkflowUri'] ?? NULL,
      $task['hasProcessUri'] ?? NULL,
      $task['partOfProcessUri'] ?? NULL,
      $task['partOfProcess'] ?? NULL,
      $task['partOf'] ?? NULL,
      $task['hasSIRPartOf'] ?? NULL,
      $task['hasProcess'] ?? NULL,
      $task['process'] ?? NULL,
    ];

    foreach ($raw_candidates as $raw_candidate) {
      if (is_string($raw_candidate)) {
        $candidate = trim($raw_candidate);
        if ($candidate !== '' && $this->looksLikeUriValue($candidate)) {
          return $candidate;
        }
        continue;
      }

      $candidate = $this->extractTaskUri($raw_candidate);
      if ($candidate !== '') {
        return $candidate;
      }
    }

    return '';
  }

  /**
   * Canonicalize task relation fields expected by the editor frontend.
   */
  protected function normalizeTaskShapeForCanvas(array $task): array {
    $task_uri = $this->extractTaskUri($task);
    if ($task_uri !== '') {
      if (empty($task['uri'])) {
        $task['uri'] = $task_uri;
      }
      if (empty($task['hasURI'])) {
        $task['hasURI'] = $task_uri;
      }
    }

    $subtask_uris = $this->extractTaskSubtaskUris($task);
    if (!empty($subtask_uris)) {
      $task['hasSubtaskUris'] = $subtask_uris;
      $task['hasSubtask'] = $subtask_uris;
    }

    $parent_uri = $this->extractTaskParentUri($task);
    if ($parent_uri !== '') {
      $task['hasSupertaskUri'] = $parent_uri;
      $task['hasSupertask'] = $parent_uri;
    }

    return $task;
  }

  /**
   * Normalize one task collection and rebuild parent/child aliases.
   */
  protected function normalizeTaskCollectionForCanvas(array $tasks): array {
    $task_map = [];

    foreach ($tasks as $task) {
      if (is_object($task)) {
        $task = (array) $task;
      }
      if (!is_array($task)) {
        continue;
      }

      $task = $this->normalizeTaskShapeForCanvas($task);
      $task_uri = $this->extractTaskUri($task);
      $task_key = $this->normalizeUriForKey($task_uri);
      if ($task_key === '') {
        continue;
      }

      $task_map[$task_key] = $task;
    }

    if (empty($task_map)) {
      return [];
    }

    $children_by_parent = [];

    foreach ($task_map as $task_key => $task) {
      $task_uri = $this->extractTaskUri($task);
      if ($task_uri === '') {
        continue;
      }

      $parent_uri = $this->extractTaskParentUri($task);
      $parent_key = $this->normalizeUriForKey($parent_uri);
      if ($parent_key !== '' && isset($task_map[$parent_key])) {
        $canonical_parent_uri = $this->extractTaskUri($task_map[$parent_key]);
        if ($canonical_parent_uri !== '') {
          $task_map[$task_key]['hasSupertaskUri'] = $canonical_parent_uri;
          $task_map[$task_key]['hasSupertask'] = $canonical_parent_uri;
          if (!isset($children_by_parent[$parent_key])) {
            $children_by_parent[$parent_key] = [];
          }
          $children_by_parent[$parent_key][$task_uri] = $task_uri;
        }
      }

      foreach ($this->extractTaskSubtaskUris($task) as $subtask_uri) {
        $subtask_key = $this->normalizeUriForKey($subtask_uri);
        if ($subtask_key === '' || !isset($task_map[$subtask_key])) {
          continue;
        }

        $canonical_subtask_uri = $this->extractTaskUri($task_map[$subtask_key]);
        if ($canonical_subtask_uri === '') {
          continue;
        }

        if (!isset($children_by_parent[$task_key])) {
          $children_by_parent[$task_key] = [];
        }
        $children_by_parent[$task_key][$canonical_subtask_uri] = $canonical_subtask_uri;
      }
    }

    foreach ($children_by_parent as $parent_key => $child_uri_map) {
      if (!isset($task_map[$parent_key])) {
        continue;
      }

      $child_uris = array_values($child_uri_map);
      $task_map[$parent_key]['hasSubtaskUris'] = $child_uris;
      $task_map[$parent_key]['hasSubtask'] = $child_uris;

      $parent_uri = $this->extractTaskUri($task_map[$parent_key]);
      if ($parent_uri === '') {
        continue;
      }

      foreach ($child_uris as $child_uri) {
        $child_key = $this->normalizeUriForKey($child_uri);
        if ($child_key === '' || !isset($task_map[$child_key])) {
          continue;
        }

        $child_parent_uri = $this->extractTaskParentUri($task_map[$child_key]);
        if ($child_parent_uri === '') {
          $task_map[$child_key]['hasSupertaskUri'] = $parent_uri;
          $task_map[$child_key]['hasSupertask'] = $parent_uri;
        }
      }
    }

    return array_values($task_map);
  }

  /**
   * Merge task collections by canonical URI.
   */
  protected function mergeTaskCollections(array $base_tasks, array $extra_tasks): array {
    $merged = [];

    foreach (array_merge($base_tasks, $extra_tasks) as $task) {
      if (is_object($task)) {
        $task = (array) $task;
      }
      if (!is_array($task)) {
        continue;
      }

      $task = $this->normalizeTaskShapeForCanvas($task);
      $task_uri = $this->extractTaskUri($task);
      $task_key = $this->normalizeUriForKey($task_uri);
      if ($task_key === '') {
        continue;
      }

      $merged[$task_key] = $task;
    }

    return array_values($merged);
  }

  /**
   * Build WKF task URI prefix from process URI (../PROC/<id> -> ../TSK/<id>).
   */
  protected function buildTaskUriPrefixFromProcessUri(string $process_uri): string {
    $normalized = $this->normalizeUriForKey($process_uri);
    if ($normalized === '') {
      return '';
    }

    if (preg_match('#^(.*)/PROC/[^/]+$#i', $normalized, $matches)) {
      return rtrim((string) ($matches[1] ?? ''), '/');
    }

    return '';
  }

  /**
   * List all task entities (paged) for fallback process reconstruction.
   */
  protected function listAllTasks(int $page_size = 200, int $max_pages = 20): array {
    $all_tasks = [];

    for ($page = 0; $page < $max_pages; $page++) {
      $offset = $page * $page_size;

      try {
        $list_response = $this->listTasks($page_size, $offset);
      }
      catch (\Throwable $e) {
        break;
      }

      $list = $list_response;
      if (is_array($list_response) && array_key_exists('body', $list_response)) {
        $list = $list_response['body'];
      }

      if (!is_array($list) || count($list) === 0) {
        break;
      }

      foreach ($list as $task) {
        if (is_object($task)) {
          $task = (array) $task;
        }
        if (is_array($task)) {
          $all_tasks[] = $task;
        }
      }

      if (count($list) < $page_size) {
        break;
      }
    }

    return $all_tasks;
  }

  /**
   * Order tasks from top task through child links, then append leftovers.
   */
  protected function orderTasksByHierarchy(array $tasks, string $top_task_uri): array {
    $task_map = [];
    foreach ($tasks as $task) {
      if (is_object($task)) {
        $task = (array) $task;
      }
      if (!is_array($task)) {
        continue;
      }

      $task_uri = $this->extractTaskUri($task);
      $task_key = $this->normalizeUriForKey($task_uri);
      if ($task_key === '') {
        continue;
      }

      $task_map[$task_key] = $task;
    }

    if (empty($task_map)) {
      return [];
    }

    $ordered = [];
    $seen = [];

    $walk = function (string $candidate_uri) use (&$walk, &$ordered, &$seen, $task_map): void {
      $candidate_key = $this->normalizeUriForKey($candidate_uri);
      if ($candidate_key === '' || isset($seen[$candidate_key]) || !isset($task_map[$candidate_key])) {
        return;
      }

      $seen[$candidate_key] = TRUE;
      $task = $task_map[$candidate_key];
      $ordered[] = $task;

      foreach ($this->extractTaskSubtaskUris($task) as $subtask_uri) {
        $walk($subtask_uri);
      }
    };

    foreach ($this->taskUriVariants($top_task_uri) as $candidate_top_uri) {
      $walk($candidate_top_uri);
    }
    if (empty($ordered)) {
      $walk($top_task_uri);
    }

    foreach ($task_map as $task_key => $task) {
      if (!isset($seen[$task_key])) {
        $ordered[] = $task;
      }
    }

    return $ordered;
  }

  /**
   * Fallback task collection when top-task recursion is not enough.
   */
  protected function collectTasksByProcessFallback(string $process_uri, string $top_task_uri): array {
    $normalized_process_uri = $this->normalizeUriForKey($process_uri);
    if ($normalized_process_uri === '') {
      return [];
    }

    $task_prefix = $this->buildTaskUriPrefixFromProcessUri($process_uri);
    $task_prefix_key = strtolower($this->normalizeUriForKey($task_prefix));
    $task_prefix_marker = $task_prefix_key !== ''
      ? rtrim($task_prefix_key, '/') . '/tsk/'
      : '';

    $candidate_map = [];
    $add_candidate = function (array $task) use (&$candidate_map): void {
      $task = $this->normalizeTaskShapeForCanvas($task);
      $task_uri = $this->extractTaskUri($task);
      $task_key = $this->normalizeUriForKey($task_uri);
      if ($task_key !== '') {
        $candidate_map[$task_key] = $task;
      }
    };

    $top_task = $this->resolveTaskByUri($top_task_uri);
    if (is_array($top_task) && empty($top_task['error'])) {
      $add_candidate($top_task);
    }

    foreach ($this->listAllTasks(200, 20) as $candidate) {
      if (!is_array($candidate) || !$this->isTaskEntity($candidate)) {
        continue;
      }

      $candidate_uri = $this->extractTaskUri($candidate);
      if ($candidate_uri === '') {
        continue;
      }

      $belongs_to_process = FALSE;

      $candidate_process_uri = $this->extractTaskProcessUri($candidate);
      if ($candidate_process_uri !== '') {
        $belongs_to_process = ($this->normalizeUriForKey($candidate_process_uri) === $normalized_process_uri);
      }

      if (!$belongs_to_process && $task_prefix_marker !== '') {
        $candidate_key = strtolower($this->normalizeUriForKey($candidate_uri));
        $belongs_to_process = ($candidate_key !== '' && str_starts_with($candidate_key, $task_prefix_marker));
      }

      if (!$belongs_to_process) {
        continue;
      }

      $resolved = $this->resolveTaskByUri($candidate_uri);
      if (is_array($resolved) && empty($resolved['error']) && $this->isTaskEntity($resolved)) {
        $add_candidate($resolved);
      }
      else {
        $add_candidate($candidate);
      }
    }

    if (empty($candidate_map)) {
      return [];
    }

    $normalized = $this->normalizeTaskCollectionForCanvas(array_values($candidate_map));
    return $this->orderTasksByHierarchy($normalized, $top_task_uri);
  }

  /**
   * Generate URI variants for task lookup (#foo <-> #/foo).
   */
  protected function taskUriVariants(string $task_uri): array {
    $task_uri = trim($task_uri);
    if ($task_uri === '') {
      return [];
    }

    $variants = [$task_uri];

    if (strpos($task_uri, '#/') !== FALSE) {
      $variants[] = str_replace('#/', '#', $task_uri);
    }
    elseif (strpos($task_uri, '#') !== FALSE) {
      $variants[] = preg_replace('/#(?!\/)/', '#/', $task_uri, 1) ?? $task_uri;
    }

    $variants = array_map('trim', $variants);
    $variants = array_filter($variants, function ($value) {
      return $value !== '';
    });

    return array_values(array_unique($variants));
  }

  /**
   * Resolve a task URI with best-effort fallbacks.
   */
  protected function resolveTaskByUri(string $task_uri): ?array {
    $task_uri = trim($task_uri);
    if ($task_uri === '') {
      return NULL;
    }

    foreach ($this->taskUriVariants($task_uri) as $candidate_uri) {
      try {
        $task = $this->getByUri($candidate_uri);
      }
      catch (\Throwable $e) {
        $task = [];
      }

      if (is_array($task) && empty($task['error']) && $this->isTaskEntity($task)) {
        if (empty($task['uri']) && empty($task['hasURI'])) {
          $task['uri'] = $candidate_uri;
        }
        return $task;
      }
    }

    // Last-resort fallback: scan paginated task list and match normalized URI.
    $target_key = $this->normalizeUriForKey($task_uri);
    if ($target_key === '') {
      return NULL;
    }

    $page_size = 200;
    $max_pages = 20;

    for ($page = 0; $page < $max_pages; $page++) {
      $offset = $page * $page_size;

      try {
        $list_response = $this->listTasks($page_size, $offset);
      }
      catch (\Throwable $e) {
        break;
      }

      $list = $list_response;
      if (is_array($list_response) && array_key_exists('body', $list_response)) {
        $list = $list_response['body'];
      }

      if (!is_array($list) || count($list) === 0) {
        break;
      }

      foreach ($list as $candidate) {
        if (is_object($candidate)) {
          $candidate = (array) $candidate;
        }
        if (!is_array($candidate) || !$this->isTaskEntity($candidate)) {
          continue;
        }

        $candidate_uri = trim((string) ($candidate['uri'] ?? $candidate['hasURI'] ?? ''));
        if ($candidate_uri === '') {
          continue;
        }

        if ($this->normalizeUriForKey($candidate_uri) !== $target_key) {
          continue;
        }

        try {
          $resolved = $this->getByUri($candidate_uri);
          if (is_array($resolved) && empty($resolved['error']) && $this->isTaskEntity($resolved)) {
            return $resolved;
          }
        }
        catch (\Throwable $e) {
          // Fallback to the listed shape below.
        }

        // Keep behavior consistent with getByUri task enrichment.
        $candidate = $this->enrichTaskRequiredInstruments($candidate);
        $candidate = $this->applyTaskInstrumentOverride($candidate, $candidate_uri);
        if (empty($candidate['uri']) && empty($candidate['hasURI'])) {
          $candidate['uri'] = $candidate_uri;
        }

        return $candidate;
      }

      if (count($list) < $page_size) {
        break;
      }
    }

    return NULL;
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
    // Persist a local copy (full fidelity) to remain robust to API branch differences.
    $this->saveTaskInstrumentOverride($task_uri, $required_instrument);

    // Build a legacy-safe payload (instrument only), and a full payload (includes components).
    $api_required_instrument = $this->buildTaskRequiredInstrumentApiEntries($required_instrument, FALSE);
    $api_required_instrument_full = $this->buildTaskRequiredInstrumentApiEntries($required_instrument, TRUE);

    $has_components = FALSE;
    foreach ($api_required_instrument_full as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      $components = $entry['requiredComponents'] ?? [];
      if (is_array($components) && !empty($components)) {
        $has_components = TRUE;
        break;
      }
    }

    // Avoid calling hascoapi with an empty list (it 400s). In this case we only update
    // the local override.
    if (!empty($api_required_instrument)) {
      // Note: hascoapi Java controller expects lowercase 'taskuri'.
      // This endpoint returns plain text on success (not JSON). We treat it as best-effort
      // and then fetch the task by URI to return a consistent JSON object to the frontend.
      if ($has_components) {
        try {
          $full_response = $this->request('POST', '/hascoapi/api/task/instruments', [
            'json' => [
              'taskuri' => $task_uri,
              'requiredInstrument' => $api_required_instrument_full,
            ],
          ]);
          $this->assertOperationSuccessful($full_response, 'Task instruments (full payload)');
        }
        catch (\Throwable $e) {
          $this->logger->warning('Task instrument full payload rejected for @task. Retrying legacy payload: @error', [
            '@task' => $task_uri,
            '@error' => $e->getMessage(),
          ]);

          $legacy_response = $this->request('POST', '/hascoapi/api/task/instruments', [
            'json' => [
              'taskuri' => $task_uri,
              'requiredInstrument' => $api_required_instrument,
            ],
          ]);
          $this->assertOperationSuccessful($legacy_response, 'Task instruments (legacy payload)');
        }
      }
      else {
        $legacy_response = $this->request('POST', '/hascoapi/api/task/instruments', [
          'json' => [
            'taskuri' => $task_uri,
            'requiredInstrument' => $api_required_instrument,
          ],
        ]);
        $this->assertOperationSuccessful($legacy_response, 'Task instruments (legacy payload)');
      }
    }

    return $this->getByUri($task_uri);
  }

  // ================================================================
  // Instrument operations
  // ================================================================

  /**
   * Resolve current authenticated user e-mail (empty when unavailable).
   */
  protected function getCurrentUserEmail(): string {
    try {
      $account = \Drupal::currentUser();
      if ((string) $account->id() === '0') {
        return '';
      }

      $user = \Drupal\user\Entity\User::load($account->id());
      if ($user && is_string($user->getEmail())) {
        return trim((string) $user->getEmail());
      }
    }
    catch (\Throwable $ignored) {
      // Keep empty fallback.
    }

    return '';
  }

  /**
   * Normalize mixed list response payloads into an array of arrays.
   */
  protected function normalizeInstrumentListPayload(mixed $payload): array {
    $raw = $payload;
    if (is_array($raw) && array_key_exists('body', $raw)) {
      $raw = $raw['body'];
    }

    if (!is_array($raw)) {
      return [];
    }

    $normalized = [];
    foreach ($raw as $item) {
      if (is_array($item)) {
        $normalized[] = $item;
      }
      elseif (is_object($item)) {
        $normalized[] = (array) $item;
      }
    }

    return $normalized;
  }

  /**
   * Merge instrument lists by URI while preserving input order preference.
   */
  protected function mergeInstrumentListsByUri(array $preferred, array $secondary, int $limit = 0): array {
    $merged = [];
    $seen = [];

    foreach ([$preferred, $secondary] as $list) {
      foreach ($list as $item) {
        if (is_object($item)) {
          $item = (array) $item;
        }
        if (!is_array($item)) {
          continue;
        }

        $uri = trim((string) ($item['uri'] ?? $item['hasURI'] ?? ''));
        $key = $uri !== ''
          ? strtolower(rtrim($uri, '/'))
          : 'item:' . sha1(serialize($item));

        if (isset($seen[$key])) {
          continue;
        }

        $seen[$key] = TRUE;
        $merged[] = $item;

        if ($limit > 0 && count($merged) >= $limit) {
          return $merged;
        }
      }
    }

    return $merged;
  }

  /**
   * Fallback instrument listing through REP connector endpoints.
   */
  protected function listInstrumentsViaRepConnector(int $page_size, int $offset): array {
    if (!\Drupal::hasService('rep.api_connector')) {
      return [];
    }

    try {
      $api = \Drupal::service('rep.api_connector');

      $manager_list = [];
      $keyword_list = [];
      $manager_email = $this->getCurrentUserEmail();
      if ($manager_email !== '') {
        try {
          $raw = $api->listByManagerEmail('instrument', $manager_email, $page_size, $offset);
          $parsed = $api->parseObjectResponse($raw, 'listByManagerEmail');
          $manager_list = $this->normalizeInstrumentListPayload($parsed);
        }
        catch (\Throwable $e) {
          $this->logger->warning('Instrument managerEmail listing failed via REP: @message', [
            '@message' => $e->getMessage(),
          ]);
        }
      }

      // Compatibility fallback: list by keyword for branches where managerEmail
      // filtering or user ownership metadata is inconsistent.
      try {
        $raw = $api->listByKeyword('instrument', '_', $page_size, $offset);
        $parsed = $api->parseObjectResponse($raw, 'listByKeyword');
        $keyword_list = $this->normalizeInstrumentListPayload($parsed);
      }
      catch (\Throwable $e) {
        $this->logger->warning('Instrument keyword listing failed via REP: @message', [
          '@message' => $e->getMessage(),
        ]);
      }

      if (!empty($keyword_list) && !empty($manager_list)) {
        return $this->mergeInstrumentListsByUri($keyword_list, $manager_list, $page_size);
      }

      if (!empty($keyword_list)) {
        return $keyword_list;
      }

      return $manager_list;
    }
    catch (\Throwable $e) {
      $this->logger->warning('Instrument fallback via REP failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * List instruments with pagination.
   */
  public function listInstruments(int $page_size = 50, int $offset = 0): array {
    $endpoint = '/hascoapi/api/instrument/elements/' . $page_size . '/' . $offset;
    $primary_error = '';
    $primary_list = [];

    try {
      $response = $this->request('GET', $endpoint);
      $primary_list = $this->normalizeInstrumentListPayload($response);

      if (is_array($response) && (($response['isSuccessful'] ?? NULL) === FALSE)) {
        $primary_error = trim((string) ($response['body'] ?? ''));
      }
    }
    catch (\Throwable $e) {
      $primary_error = trim($e->getMessage());
    }

    $fallback = $this->listInstrumentsViaRepConnector($page_size, $offset);
    if (!empty($fallback)) {
      $merged = $this->mergeInstrumentListsByUri($fallback, $primary_list, $page_size);

      if (empty($primary_list) || count($merged) > count($primary_list)) {
        if ($primary_error !== '') {
          $this->logger->warning('Instrument list fallback activated after HASCOAPI failure: @message', [
            '@message' => $primary_error,
          ]);
        }
        elseif (!empty($primary_list)) {
          $this->logger->notice('Instrument list using REP superset (hascoapi=@hasco_count, rep=@rep_count).', [
            '@hasco_count' => count($primary_list),
            '@rep_count' => count($merged),
          ]);
        }

        return $merged;
      }

      if ($primary_error !== '') {
        $this->logger->warning('Instrument list fallback activated after HASCOAPI failure: @message', [
          '@message' => $primary_error,
        ]);
      }
    }

    if (!empty($primary_list)) {
      return $primary_list;
    }

    return $fallback;
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
