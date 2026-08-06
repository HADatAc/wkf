<?php

namespace Drupal\ctt\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Markup;

/**
 * Controller that renders the CTT Workflow Editor page.
 *
 * Returns a render array that attaches the React UMD bundle and passes
 * configuration via drupalSettings.ctt.
 */
class CttEditorController extends ControllerBase {

  /**
   * Normalize URI text into a stable comparison key.
   */
  protected function normalizeUriKey(string $uri): string {
    $normalized = trim($uri);
    if ($normalized === '') {
      return '';
    }

    if (str_contains($normalized, '#/')) {
      $normalized = str_replace('#/', '#', $normalized);
    }

    return strtolower(rtrim($normalized, '/'));
  }

  /**
   * Extract URI text from mixed scalar/object shapes.
   */
  protected function extractUriFromValue(mixed $value): string {
    if (is_string($value)) {
      $candidate = trim($value);
      return $this->isUri($candidate) ? $candidate : '';
    }

    if (is_object($value)) {
      foreach (['uri', 'hasURI', 'typeUri'] as $key) {
        if (isset($value->{$key}) && is_string($value->{$key})) {
          $candidate = trim((string) $value->{$key});
          if ($this->isUri($candidate)) {
            return $candidate;
          }
        }
      }
    }

    return '';
  }

  /**
   * Convert list/object API responses into a plain list of objects.
   *
   * @return array<int, object>
   */
  protected function normalizeApiListPayload(mixed $payload): array {
    if ($payload === NULL) {
      return [];
    }

    if (is_object($payload)) {
      if (isset($payload->elements) && is_array($payload->elements)) {
        return array_values(array_filter($payload->elements, 'is_object'));
      }

      return [$payload];
    }

    if (!is_array($payload)) {
      return [];
    }

    $list = [];
    foreach ($payload as $item) {
      if (is_object($item)) {
        $list[] = $item;
      }
    }

    return $list;
  }

  /**
   * Resolve current user's primary organization context.
   */
  protected function resolveCurrentUserOrganizationContext(?string $studyUri = NULL): array {
    $context = [
      'organizationUri' => '',
      'organizationLabel' => '',
    ];

    if (!\Drupal::hasService('rep.api_connector')) {
      return $this->resolveStudyOrganizationContext($studyUri);
    }

    try {
      $api = \Drupal::service('rep.api_connector');
      $userEmail = '';

      try {
        $user = \Drupal\user\Entity\User::load($this->currentUser->id());
        if ($user && is_string($user->getEmail())) {
          $userEmail = trim((string) $user->getEmail());
        }
      }
      catch (\Throwable $ignored) {
        $userEmail = '';
      }

      if ($userEmail !== '') {
        $orgRaw = $api->listByManagerEmail('organization', $userEmail, 50, 0);
        $orgParsed = $api->parseObjectResponse($orgRaw, 'listByManagerEmail');
        $organizations = $this->normalizeApiListPayload($orgParsed);

        foreach ($organizations as $organization) {
          if (!is_object($organization)) {
            continue;
          }

          $orgUri = trim((string) ($organization->uri ?? $organization->hasURI ?? ''));
          if (!$this->isUri($orgUri)) {
            continue;
          }

          $context['organizationUri'] = $orgUri;
          $context['organizationLabel'] = trim((string) ($organization->label ?? $organization->name ?? ''));
          break;
        }

        if ($context['organizationUri'] === '') {
          $peopleRaw = $api->listByManagerEmail('person', $userEmail, 50, 0);
          $peopleParsed = $api->parseObjectResponse($peopleRaw, 'listByManagerEmail');
          $people = $this->normalizeApiListPayload($peopleParsed);

          foreach ($people as $person) {
            if (!is_object($person)) {
              continue;
            }

            $affiliationUri = '';
            if (isset($person->hasAffiliation)) {
              $affiliationUri = $this->extractUriFromValue($person->hasAffiliation);
            }
            if ($affiliationUri === '' && isset($person->hasAffiliationUri) && is_string($person->hasAffiliationUri)) {
              $affiliationUri = trim((string) $person->hasAffiliationUri);
            }

            if (!$this->isUri($affiliationUri)) {
              continue;
            }

            $context['organizationUri'] = $affiliationUri;
            $context['organizationLabel'] = trim((string) ($person->hasAffiliation->label ?? ''));
            break;
          }
        }
      }

      if ($context['organizationLabel'] === '' && $context['organizationUri'] !== '') {
        $context['organizationLabel'] = $this->resolveLabelByUri($context['organizationUri']);
      }
    }
    catch (\Throwable $ignored) {
      $context = [
        'organizationUri' => '',
        'organizationLabel' => '',
      ];
    }

    if ($context['organizationUri'] === '') {
      return $this->resolveStudyOrganizationContext($studyUri);
    }

    return $context;
  }

  /**
   * Resolve organization context from a Process-Based Study URI.
   */
  protected function resolveStudyOrganizationContext(?string $studyUri): array {
    $context = [
      'organizationUri' => '',
      'organizationLabel' => '',
    ];

    $normalizedStudyUri = trim((string) $studyUri);
    if ($normalizedStudyUri === '' || !\Drupal::hasService('rep.api_connector')) {
      return $context;
    }

    try {
      $api = \Drupal::service('rep.api_connector');
      $study = $api->parseObjectResponse($api->getUri($normalizedStudyUri), 'getUri');
      if (!is_object($study)) {
        return $context;
      }

      $institution = $study->institution ?? $study->hasInstitution ?? NULL;
      if (is_object($institution)) {
        $context['organizationUri'] = trim((string) ($institution->uri ?? $institution->hasURI ?? ''));
        $context['organizationLabel'] = trim((string) ($institution->label ?? $institution->name ?? ''));
      }
      elseif (is_string($institution)) {
        $institutionCandidate = trim($institution);
        if ($this->isUri($institutionCandidate)) {
          $context['organizationUri'] = $institutionCandidate;
        }
        else {
          $context['organizationLabel'] = $institutionCandidate;
        }
      }

      if ($context['organizationUri'] === '') {
        foreach (['hasInstitutionUri', 'institutionUri'] as $key) {
          if (!isset($study->{$key}) || !is_string($study->{$key})) {
            continue;
          }

          $candidate = trim((string) $study->{$key});
          if ($this->isUri($candidate)) {
            $context['organizationUri'] = $candidate;
            break;
          }
        }
      }

      if ($context['organizationLabel'] === '') {
        $context['organizationLabel'] = trim((string) ($study->institutionName ?? ''));
      }

      if ($context['organizationLabel'] === '' && $context['organizationUri'] !== '') {
        $context['organizationLabel'] = $this->resolveLabelByUri($context['organizationUri']);
      }
    }
    catch (\Throwable $ignored) {
      return $context;
    }

    return $context;
  }

  /**
   * Resolve direct/indirect organization scope for filtering.
   *
   * @return string[]
   */
  protected function resolveOrganizationScopeUris(string $organizationUri): array {
    $scope = [];
    $normalizedRoot = trim($organizationUri);
    if (!$this->isUri($normalizedRoot) || !\Drupal::hasService('rep.api_connector')) {
      return [];
    }

    $scope[$normalizedRoot] = TRUE;

    try {
      $api = \Drupal::service('rep.api_connector');
      $org = $api->parseObjectResponse($api->getUri($normalizedRoot), 'getUri');

      if (is_object($org)) {
        foreach (['parentOrganizationUri', 'hasParentOrganizationUri', 'parentOrganization', 'hasParentOrganization', 'isPartOf', 'partOf'] as $key) {
          if (!isset($org->{$key})) {
            continue;
          }

          $parentUri = $this->extractUriFromValue($org->{$key});
          if ($parentUri !== '' && $this->isUri($parentUri)) {
            $scope[$parentUri] = TRUE;
          }
        }
      }

      if (method_exists($api, 'sparqlQuery')) {
        $sparqlParents = 'SELECT DISTINCT ?parent WHERE {'
          . ' <' . $normalizedRoot . '> <https://schema.org/isPartOf> ?parent .'
          . '}';

        $rawParents = $api->sparqlQuery($sparqlParents);
        $parentsDecoded = json_decode((string) $rawParents, TRUE);
        $parentsBindings = $parentsDecoded['results']['bindings'] ?? [];
        if (is_array($parentsBindings)) {
          foreach ($parentsBindings as $binding) {
            if (!is_array($binding)) {
              continue;
            }
            $parentUri = trim((string) ($binding['parent']['value'] ?? ''));
            if ($this->isUri($parentUri)) {
              $scope[$parentUri] = TRUE;
            }
          }
        }

        $sparqlChildren = 'SELECT DISTINCT ?child WHERE {'
          . ' ?child <https://schema.org/isPartOf> <' . $normalizedRoot . '> .'
          . '}';

        $rawChildren = $api->sparqlQuery($sparqlChildren);
        $childrenDecoded = json_decode((string) $rawChildren, TRUE);
        $childrenBindings = $childrenDecoded['results']['bindings'] ?? [];
        if (is_array($childrenBindings)) {
          foreach ($childrenBindings as $binding) {
            if (!is_array($binding)) {
              continue;
            }
            $childUri = trim((string) ($binding['child']['value'] ?? ''));
            if ($this->isUri($childUri)) {
              $scope[$childUri] = TRUE;
            }
          }
        }
      }
    }
    catch (\Throwable $ignored) {
      // Keep best-effort scope.
    }

    return array_values(array_keys($scope));
  }

  /**
   * Resolve platform instances that belong to one organization.
   *
   * @return array<string, string>
   *   Map of platform instance URI to display label.
   */
  protected function resolveOrganizationPlatformInstances(array $organizationScopeUris): array {
    $options = [];
    if (empty($organizationScopeUris) || !\Drupal::hasService('rep.api_connector')) {
      return $options;
    }

    $scopeKeys = [];
    foreach ($organizationScopeUris as $candidateUri) {
      $candidateKey = $this->normalizeUriKey((string) $candidateUri);
      if ($candidateKey !== '') {
        $scopeKeys[$candidateKey] = TRUE;
      }
    }
    if (empty($scopeKeys)) {
      return $options;
    }

    try {
      $api = \Drupal::service('rep.api_connector');
      $pageSize = 100;
      $maxPages = 12;

      for ($page = 0; $page < $maxPages; $page++) {
        $offset = $page * $pageSize;
        $raw = $api->listByKeyword('platforminstance', '_', $pageSize, $offset);
        $parsed = $api->parseObjectResponse($raw, 'listByKeyword');
        $chunk = $this->normalizeApiListPayload($parsed);
        if (empty($chunk)) {
          break;
        }

        foreach ($chunk as $platformInstance) {
          if (!is_object($platformInstance)) {
            continue;
          }

          $platformUri = trim((string) ($platformInstance->uri ?? ''));
          if (!$this->isUri($platformUri)) {
            continue;
          }

          $partOfUri = '';
          if (isset($platformInstance->partOf)) {
            $partOfUri = $this->extractUriFromValue($platformInstance->partOf);
          }
          if ($partOfUri === '' && isset($platformInstance->partOfUri) && is_string($platformInstance->partOfUri)) {
            $partOfUri = trim((string) $platformInstance->partOfUri);
          }

          $partOfKey = $this->normalizeUriKey($partOfUri);
          if ($partOfKey === '' || !isset($scopeKeys[$partOfKey])) {
            continue;
          }

          $label = trim((string) ($platformInstance->label ?? $platformInstance->name ?? $platformUri));
          $options[$platformUri] = [
            'label' => $label !== '' ? $label : $platformUri,
            'organizationUri' => $partOfUri,
          ];
        }

        if (count($chunk) < $pageSize) {
          break;
        }
      }
    }
    catch (\Throwable $ignored) {
      return $options;
    }

    uasort($options, function ($a, $b) {
      $left = is_array($a) ? (string) ($a['label'] ?? '') : (string) $a;
      $right = is_array($b) ? (string) ($b['label'] ?? '') : (string) $b;
      return strcasecmp($left, $right);
    });
    return $options;
  }

  /**
   * Resolve instrument model URIs deployed on the selected platform instances.
   *
   * @param array<string, string> $platformOptions
   *
   * @return array<int, string>
   */
  protected function resolveAllowedInstrumentUrisByPlatforms(array $platformOptions): array {
    if (empty($platformOptions) || !\Drupal::hasService('rep.api_connector')) {
      return [
        'allowedInstrumentUris' => [],
        'platformInstrumentUris' => [],
      ];
    }

    $platformKeys = [];
    foreach (array_keys($platformOptions) as $platformUri) {
      $key = $this->normalizeUriKey((string) $platformUri);
      if ($key !== '') {
        $platformKeys[$key] = TRUE;
      }
    }
    if (empty($platformKeys)) {
      return [];
    }

    $instrumentUris = [];
    $platformInstrumentUris = [];
    $instrumentInstanceCache = [];

    try {
      $api = \Drupal::service('rep.api_connector');
      $pageSize = 100;
      $maxPages = 12;

      for ($page = 0; $page < $maxPages; $page++) {
        $offset = $page * $pageSize;
        $raw = $api->listByKeyword('deployment', '_', $pageSize, $offset);
        $parsed = $api->parseObjectResponse($raw, 'listByKeyword');
        $chunk = $this->normalizeApiListPayload($parsed);
        if (empty($chunk)) {
          break;
        }

        foreach ($chunk as $deployment) {
          if (!is_object($deployment)) {
            continue;
          }

          $platformUri = '';
          if (isset($deployment->platformInstance)) {
            $platformUri = $this->extractUriFromValue($deployment->platformInstance);
          }
          if ($platformUri === '' && isset($deployment->platformInstanceUri) && is_string($deployment->platformInstanceUri)) {
            $platformUri = trim((string) $deployment->platformInstanceUri);
          }

          if (!isset($platformKeys[$this->normalizeUriKey($platformUri)])) {
            continue;
          }

          $instrumentInstanceUri = '';
          if (isset($deployment->instrumentInstance)) {
            $instrumentInstanceUri = $this->extractUriFromValue($deployment->instrumentInstance);
          }
          if ($instrumentInstanceUri === '' && isset($deployment->instrumentInstanceUri) && is_string($deployment->instrumentInstanceUri)) {
            $instrumentInstanceUri = trim((string) $deployment->instrumentInstanceUri);
          }
          if (!$this->isUri($instrumentInstanceUri)) {
            continue;
          }

          if (!array_key_exists($instrumentInstanceUri, $instrumentInstanceCache)) {
            $resolvedInstrumentUri = '';
            try {
              $instrumentInstance = $api->parseObjectResponse($api->getUri($instrumentInstanceUri), 'getUri');
              if (is_object($instrumentInstance)) {
                if (isset($instrumentInstance->typeUri) && is_string($instrumentInstance->typeUri)) {
                  $resolvedInstrumentUri = trim((string) $instrumentInstance->typeUri);
                }
                if (!$this->isUri($resolvedInstrumentUri) && isset($instrumentInstance->type)) {
                  $resolvedInstrumentUri = $this->extractUriFromValue($instrumentInstance->type);
                }
                if (!$this->isUri($resolvedInstrumentUri) && isset($instrumentInstance->instrument)) {
                  $resolvedInstrumentUri = $this->extractUriFromValue($instrumentInstance->instrument);
                }
              }
            }
            catch (\Throwable $ignored) {
              $resolvedInstrumentUri = '';
            }

            $instrumentInstanceCache[$instrumentInstanceUri] = $this->isUri($resolvedInstrumentUri)
              ? $resolvedInstrumentUri
              : '';
          }

          $instrumentUri = trim((string) ($instrumentInstanceCache[$instrumentInstanceUri] ?? ''));
          if ($this->isUri($instrumentUri)) {
            $instrumentUris[$instrumentUri] = TRUE;
            if (!isset($platformInstrumentUris[$platformUri])) {
              $platformInstrumentUris[$platformUri] = [];
            }
            $platformInstrumentUris[$platformUri][$instrumentUri] = TRUE;
          }
        }

        if (count($chunk) < $pageSize) {
          break;
        }
      }
    }
    catch (\Throwable $ignored) {
      return [
        'allowedInstrumentUris' => [],
        'platformInstrumentUris' => [],
      ];
    }

    $platformUrisNormalized = [];
    foreach ($platformInstrumentUris as $platformUri => $bucket) {
      $platformUrisNormalized[$platformUri] = array_values(array_keys($bucket));
    }

    return [
      'allowedInstrumentUris' => array_values(array_keys($instrumentUris)),
      'platformInstrumentUris' => $platformUrisNormalized,
    ];
  }

  /**
   * Build context for organization-aware instrument selection in the canvas.
   */
  protected function buildInstrumentSelectionContext(?string $studyUri): array {
    $organization = $this->resolveCurrentUserOrganizationContext($studyUri);
    $organizationScopeUris = $this->resolveOrganizationScopeUris((string) ($organization['organizationUri'] ?? ''));
    $platformDetails = $this->resolveOrganizationPlatformInstances($organizationScopeUris);
    $availability = $this->resolveAllowedInstrumentUrisByPlatforms($platformDetails);

    $platformOptions = [];
    foreach ($platformDetails as $platformUri => $details) {
      $platformOptions[$platformUri] = is_array($details)
        ? (string) ($details['label'] ?? $platformUri)
        : (string) $details;
    }

    $preferredInstrument = \Drupal::config('rep.settings')->get('preferred_instrument') ?? 'Instrument';
    $preferredPlatform = \Drupal::config('rep.settings')->get('preferred_platform') ?? 'Platform';

    return [
      'preferredInstrumentLabel' => ucfirst((string) $preferredInstrument),
      'preferredPlatformLabel' => ucfirst((string) $preferredPlatform),
      'organizationUri' => (string) ($organization['organizationUri'] ?? ''),
      'organizationLabel' => (string) ($organization['organizationLabel'] ?? ''),
      'organizationScopeUris' => $organizationScopeUris,
      'platformOptions' => $platformOptions,
      'allowedInstrumentUris' => is_array($availability) ? (array) ($availability['allowedInstrumentUris'] ?? []) : [],
      'platformInstrumentUris' => is_array($availability) ? (array) ($availability['platformInstrumentUris'] ?? []) : [],
      'filterActive' => !empty($availability['allowedInstrumentUris']),
    ];
  }

  /**
   * Resolve a human-readable label for a URI from available APIs.
   */
  protected function resolveLabelByUri(?string $uri): string {
    $normalized = trim((string) $uri);
    if ($normalized === '') {
      return '';
    }

    if (\Drupal::hasService('rep.api_connector')) {
      try {
        $api = \Drupal::service('rep.api_connector');
        $obj = $api->parseObjectResponse($api->getUri($normalized), 'getUri');
        if (is_object($obj)) {
          $label = trim((string) ($obj->label ?? $obj->title ?? $obj->name ?? ''));
          if ($label !== '') {
            return $label;
          }
        }
      }
      catch (\Throwable $ignored) {
      }
    }

    if (\Drupal::hasService('ctt.hasco_client')) {
      try {
        $probe = \Drupal::service('ctt.hasco_client')->getByUri($normalized);
        if (is_array($probe) && empty($probe['error'])) {
          $label = trim((string) ($probe['label'] ?? $probe['title'] ?? $probe['name'] ?? ''));
          if ($label !== '') {
            return $label;
          }
        }
      }
      catch (\Throwable $ignored) {
      }
    }

    return '';
  }

  /**
   * Persist study workflow association in both latest and historical keys.
   */
  protected function persistStudyProcessAssociation(string $studyUri, string $processUri): void {
    $studyUri = trim($studyUri);
    $processUri = trim($processUri);
    if ($studyUri === '' || $processUri === '') {
      return;
    }

    $studyHash = sha1($studyUri);
    $state = \Drupal::state();
    $state->set('ctt.study_process.' . $studyHash, $processUri);

    $existing = $state->get('ctt.study_processes.' . $studyHash, []);
    if (is_string($existing) && trim($existing) !== '') {
      $decoded = json_decode($existing, TRUE);
      if (is_array($decoded)) {
        $existing = $decoded;
      }
      else {
        $existing = array_map('trim', explode(',', $existing));
      }
    }

    if (!is_array($existing)) {
      $existing = [];
    }

    $normalized = [];
    foreach ($existing as $candidate) {
      if (!is_scalar($candidate)) {
        continue;
      }

      $candidate = trim((string) $candidate);
      if ($candidate !== '') {
        $normalized[$candidate] = TRUE;
      }
    }

    $normalized[$processUri] = TRUE;
    $state->set('ctt.study_processes.' . $studyHash, array_keys($normalized));
  }

  /**
   * Resolve state key used for process execution history per study.
   */
  protected function getProcessExecutionHistoryKey(string $studyUri): string {
    return 'ctt.process_execution_runs.' . sha1(trim($studyUri));
  }

  /**
   * Resolve state key for the active execution run for one study/process/user.
   */
  protected function getProcessExecutionActiveKey(string $studyUri, string $processUri, string $userIdentifier): string {
    return 'ctt.process_execution_active.' . sha1(trim($studyUri) . '|' . trim($processUri) . '|' . trim($userIdentifier));
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  protected function loadProcessExecutionHistory(string $studyUri): array {
    $history = \Drupal::state()->get($this->getProcessExecutionHistoryKey($studyUri), []);
    return is_array($history) ? $history : [];
  }

  /**
   * @param array<int, array<string, mixed>> $history
   */
  protected function saveProcessExecutionHistory(string $studyUri, array $history): void {
    if (count($history) > 200) {
      $history = array_slice($history, 0, 200);
    }
    \Drupal::state()->set($this->getProcessExecutionHistoryKey($studyUri), array_values($history));
  }

  /**
   * Register process execution start if no active running run is open.
   */
  protected function registerProcessExecutionStart(string $studyUri, string $processUri, string $mode = 'ctt-simulator'): void {
    $studyUri = trim($studyUri);
    $processUri = trim($processUri);
    if (!$this->isUri($studyUri) || !$this->isUri($processUri)) {
      return;
    }

    $userIdentifier = trim((string) $this->currentUser->getDisplayName());
    try {
      $user = \Drupal\user\Entity\User::load($this->currentUser->id());
      if ($user && is_string($user->getEmail()) && trim($user->getEmail()) !== '') {
        $userIdentifier = trim((string) $user->getEmail());
      }
    }
    catch (\Throwable $ignored) {
      // Keep display-name fallback.
    }

    $activeKey = $this->getProcessExecutionActiveKey($studyUri, $processUri, $userIdentifier);
    $activeRunId = trim((string) \Drupal::state()->get($activeKey, ''));
    $history = $this->loadProcessExecutionHistory($studyUri);

    $simulationType = 'individual';
    $studentIds = [];
    $simulationContext = \Drupal::state()->get($this->getSimulationContextKey($studyUri, $processUri, $userIdentifier), []);
    if (is_array($simulationContext)) {
      $contextType = strtolower(trim((string) ($simulationContext['simulationType'] ?? '')));
      if (in_array($contextType, ['individual', 'cohort'], TRUE)) {
        $simulationType = $contextType;
      }

      if (isset($simulationContext['studentIds']) && is_array($simulationContext['studentIds'])) {
        foreach ($simulationContext['studentIds'] as $candidateStudentId) {
          $normalizedStudentId = trim((string) $candidateStudentId);
          if ($normalizedStudentId !== '') {
            $studentIds[$normalizedStudentId] = $normalizedStudentId;
          }
        }
      }
    }

    if ($simulationType === 'cohort' && empty($studentIds)) {
      $resolvedStudentIds = $this->resolveSocStudentIds($studyUri);
      foreach ($resolvedStudentIds as $candidateStudentId) {
        $normalizedStudentId = trim((string) $candidateStudentId);
        if ($normalizedStudentId !== '') {
          $studentIds[$normalizedStudentId] = $normalizedStudentId;
        }
      }
    }

    if ($activeRunId !== '') {
      foreach ($history as $index => $entry) {
        if (!is_array($entry)) {
          continue;
        }
        if (trim((string) ($entry['runId'] ?? '')) !== $activeRunId) {
          continue;
        }
        $status = strtolower(trim((string) ($entry['status'] ?? '')));
        if (in_array($status, ['running', 'queued'], TRUE)) {
          $history[$index]['status'] = 'aborted';
          $history[$index]['finishedAt'] = gmdate('c');
          $history[$index]['note'] = 'Automatically closed because a newer execution was started.';
        }
        break;
      }
    }

    $runId = 'PX' . strtoupper(substr(sha1($studyUri . '|' . $processUri . '|' . microtime(TRUE)), 0, 12));
    $startedAt = gmdate('c');

    array_unshift($history, [
      'runId' => $runId,
      'studyUri' => $studyUri,
      'processUri' => $processUri,
      'toolUri' => 'ctt://process-execution',
      'toolLabel' => 'CTT Process Execution',
      'requestedAt' => $startedAt,
      'startedAt' => $startedAt,
      'finishedAt' => '',
      'status' => 'running',
      'requestedBy' => $userIdentifier,
      'mode' => $mode,
      'simulationType' => $simulationType,
      'studentIds' => array_values($studentIds),
      'datasetUri' => '',
      'dataFileUri' => '',
      'daUri' => '',
    ]);

    $this->saveProcessExecutionHistory($studyUri, $history);
    \Drupal::state()->set($activeKey, $runId);
  }

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
   * Normalize plain/encoded/base64 URI-like query values.
   */
  protected function decodeQueryUri(string $value): ?string {
    $candidate = trim($value);
    if ($candidate === '') {
      return NULL;
    }

    $decoded = base64_decode($candidate, TRUE);
    if (is_string($decoded) && $decoded !== '' && (str_starts_with($decoded, 'http://') || str_starts_with($decoded, 'https://'))) {
      return $decoded;
    }

    return rawurldecode($candidate);
  }

  /**
   * Check if a value is a valid HTTP(S) URI.
   */
  protected function isUri(string $value): bool {
    $normalized = trim($value);
    return $normalized !== ''
      && (str_starts_with($normalized, 'http://') || str_starts_with($normalized, 'https://'));
  }

  /**
   * Convert common query flag styles into a boolean.
   */
  protected function isTruthyFlag(string $value): bool {
    $normalized = strtolower(trim($value));
    return $normalized === '1' || $normalized === 'true' || $normalized === 'yes';
  }

  /**
   * Resolve current user identifier for execution context keys.
   */
  protected function getCurrentUserIdentifier(): string {
    $identifier = trim((string) $this->currentUser->getDisplayName());
    try {
      $user = \Drupal\user\Entity\User::load($this->currentUser->id());
      if ($user && is_string($user->getEmail()) && trim($user->getEmail()) !== '') {
        $identifier = trim((string) $user->getEmail());
      }
    }
    catch (\Throwable $ignored) {
      // Keep display-name fallback.
    }
    return $identifier;
  }

  /**
   * Build state key for simulator execution context.
   */
  protected function getSimulationContextKey(string $studyUri, string $processUri, string $userIdentifier): string {
    return 'ctt.simulation_context.' . sha1(trim($studyUri) . '|' . trim($processUri) . '|' . trim($userIdentifier));
  }

  /**
   * Resolve SOC-STUDENT member IDs for a study.
   *
   * @return array<int, string>
   */
  protected function resolveSocStudentIds(string $studyUri): array {
    $studyUri = trim($studyUri);
    if ($studyUri === '' || !\Drupal::hasService('rep.api_connector')) {
      return [];
    }

    try {
      $api = \Drupal::service('rep.api_connector');
      $socsRaw = $api->studyObjectCollectionsByStudy($studyUri);
      $socs = $api->parseObjectResponse($socsRaw, 'studyObjectCollectionsByStudy');
      if (is_object($socs)) {
        $socs = [$socs];
      }
      if (!is_array($socs) || empty($socs)) {
        return [];
      }

      $studentSocUri = '';
      foreach ($socs as $soc) {
        if (!is_object($soc)) {
          continue;
        }

        $candidateUri = strtoupper(trim((string) ($soc->uri ?? '')));
        $candidateLabel = strtoupper(trim((string) ($soc->label ?? '')));
        if (strpos($candidateUri, 'SOC-STUDENT') !== FALSE || strpos($candidateLabel, 'SOC-STUDENT') !== FALSE) {
          $studentSocUri = trim((string) ($soc->uri ?? ''));
          break;
        }
      }

      if ($studentSocUri === '') {
        return [];
      }

      $membersRaw = $api->studyObjectsBySOCwithPage($studentSocUri, 10000, 0);
      $members = $api->parseObjectResponse($membersRaw, 'studyObjectsBySOCwithPage');
      if (is_object($members)) {
        $members = [$members];
      }
      if (!is_array($members) || empty($members)) {
        return [];
      }

      $studentIds = [];
      foreach ($members as $member) {
        if (!is_object($member)) {
          continue;
        }

        $studentId = trim((string) ($member->id ?? $member->label ?? $member->uri ?? ''));
        if ($studentId !== '') {
          $studentIds[$studentId] = $studentId;
        }
      }

      return array_values($studentIds);
    }
    catch (\Throwable $ignored) {
      return [];
    }
  }

  /**
   * Resolve study manager email for ownership checks.
   */
  protected function resolveStudyManagerEmail(string $studyUri): string {
    $normalizedStudyUri = trim($studyUri);
    if ($normalizedStudyUri === '') {
      return '';
    }

    $ownerStateKey = 'ctt.study_owner_email.' . sha1($normalizedStudyUri);
    $cachedOwner = \Drupal::state()->get($ownerStateKey);
    if (is_string($cachedOwner) && trim($cachedOwner) !== '') {
      return trim($cachedOwner);
    }

    if (!\Drupal::hasService('rep.api_connector')) {
      return '';
    }

    try {
      $api = \Drupal::service('rep.api_connector');
      $studyObj = $api->parseObjectResponse($api->getUri($normalizedStudyUri), 'getUri');

      $ownerEmail = '';
      if (is_object($studyObj)) {
        $ownerEmail = trim((string) ($studyObj->hasSIRManagerEmail ?? $studyObj->managerEmail ?? ''));
      }
      elseif (is_array($studyObj)) {
        $ownerEmail = trim((string) ($studyObj['hasSIRManagerEmail'] ?? $studyObj['managerEmail'] ?? ''));
      }

      if ($ownerEmail !== '') {
        \Drupal::state()->set($ownerStateKey, $ownerEmail);
      }

      return $ownerEmail;
    }
    catch (\Throwable $ignored) {
      return '';
    }
  }

  /**
   * Build effective workflow access context for study-linked editor views.
   */
  protected function buildWorkflowAccessContext(?string $studyUri, string $currentUserEmail, bool $isExecutionMode): array {
    $normalizedStudyUri = is_string($studyUri) ? trim($studyUri) : '';
    $normalizedCurrentUserEmail = trim($currentUserEmail);

    $isStudyContext = $normalizedStudyUri !== '';
    $ownerEmail = '';
    $hasResolvedStudyOwner = FALSE;
    $isWorkflowOwnerAuthenticated = TRUE;
    $reasonCode = 'editable';

    if ($isStudyContext) {
      $ownerEmail = $this->resolveStudyManagerEmail($normalizedStudyUri);
      $hasResolvedStudyOwner = $ownerEmail !== '';
      $isWorkflowOwnerAuthenticated = $hasResolvedStudyOwner
        && $normalizedCurrentUserEmail !== ''
        && strcasecmp($ownerEmail, $normalizedCurrentUserEmail) === 0;

      if (!$isWorkflowOwnerAuthenticated) {
        $reasonCode = $hasResolvedStudyOwner ? 'non_owner_study_context' : 'study_owner_unresolved';
      }
    }

    $readOnlyPreview = $isExecutionMode || ($isStudyContext && !$isWorkflowOwnerAuthenticated);
    if ($isExecutionMode) {
      $reasonCode = 'execution_mode';
    }

    $message = '';
    if ($reasonCode === 'execution_mode') {
      $message = (string) $this->t('Execution mode is read-only.');
    }
    elseif ($readOnlyPreview) {
      $message = (string) $this->t('Read-only workflow preview: only the authenticated workflow owner can edit, save, or start/stop actions for this study.');
    }

    return [
      'isStudyContext' => $isStudyContext,
      'hasResolvedStudyOwner' => $hasResolvedStudyOwner,
      'isWorkflowOwnerAuthenticated' => $isWorkflowOwnerAuthenticated,
      'readOnlyPreview' => $readOnlyPreview,
      'reasonCode' => $reasonCode,
      'message' => $message,
    ];
  }

  /**
   * Canonical editorial states for structured scenario workflows.
   */
  protected function getEditorialStates(): array {
    return ['draft', 'under review', 'current', 'deprecated'];
  }

  /**
   * Allowed editorial transitions by source state.
   */
  protected function getEditorialTransitions(): array {
    return [
      'draft' => ['under review', 'deprecated'],
      'under review' => ['draft', 'current', 'deprecated'],
      'current' => ['deprecated'],
      'deprecated' => [],
    ];
  }

  /**
   * Canonical statuses for analytical tools lifecycle.
   */
  protected function getAnalyticalToolStatuses(): array {
    return ['draft', 'current', 'deprecated', 'validated', 'published'];
  }

  /**
   * Entry route for creating a structured workflow/scenario.
   */
  public function createEntry(): RedirectResponse {
    return $this->redirect('ctt.editor', [], [
      'query' => [
        'create' => '1',
      ],
    ]);
  }

  /**
   * Unified submission entrypoint that carries study context into the editor.
   */
  public function submissionEntry(string $studyuri): RedirectResponse {
    $decodedStudyUri = base64_decode(rawurldecode($studyuri), TRUE);
    if (!is_string($decodedStudyUri) || trim($decodedStudyUri) === '') {
      $this->messenger()->addError($this->t('Invalid study identifier for structured submission.'));
      return $this->redirect('std.search_studies_variables');
    }

    $processUri = NULL;
    $processFromQuery = (string) \Drupal::request()->query->get('processUri', '');
    if ($processFromQuery !== '') {
      $processUri = $this->decodeQueryUri($processFromQuery);
    }

    if ($processUri === NULL || trim($processUri) === '') {
      $stored = \Drupal::state()->get('ctt.study_process.' . sha1($decodedStudyUri));
      if (is_string($stored) && trim($stored) !== '') {
        $processUri = trim($stored);
      }
    }

    if ($processUri !== NULL && trim($processUri) !== '') {
      $this->persistStudyProcessAssociation($decodedStudyUri, $processUri);
    }

    $statusKey = 'ctt.study_status.' . sha1($decodedStudyUri);
    $storedStatus = \Drupal::state()->get($statusKey);
    $normalizedStatus = is_string($storedStatus) ? strtolower(trim($storedStatus)) : '';
    if (!in_array($normalizedStatus, $this->getEditorialStates(), TRUE)) {
      \Drupal::state()->set($statusKey, 'draft');
    }

    $query = [
      'studyUri' => $studyuri,
      'submission' => '1',
    ];

    $simulationType = strtolower(trim((string) \Drupal::request()->query->get('simulationType', '')));
    if (!in_array($simulationType, ['individual', 'cohort'], TRUE)) {
      $simulationType = 'individual';
    }

    $studentIds = [];
    if ($simulationType === 'cohort') {
      $studentIds = $this->resolveSocStudentIds($decodedStudyUri);
      if (empty($studentIds)) {
        $this->messenger()->addError($this->t('Cohort CTT Simulator requires SOC-STUDENT to include at least one student object. Add students first and try again.'));
        $returnToRaw = trim((string) \Drupal::request()->query->get('returnTo', ''));
        $returnTo = rawurldecode($returnToRaw);
        if ($returnTo !== '' && str_starts_with($returnTo, '/')) {
          return new RedirectResponse($returnTo);
        }
        return $this->redirect('std.manage_study_elements', [
          'studyuri' => base64_encode($decodedStudyUri),
        ]);
      }
    }

    $query['simulationType'] = $simulationType;

    $autoExecuteFlag = (string) \Drupal::request()->query->get('autoExecute', '');
    if ($this->isTruthyFlag($autoExecuteFlag)) {
      $query['autoExecute'] = '1';
    }

    $testModeFlag = (string) \Drupal::request()->query->get('test', '');
    $testMode = $this->isTruthyFlag($testModeFlag);
    if ($this->isTruthyFlag($testModeFlag)) {
      $query['test'] = '1';
    }

    $executionPanel = strtolower(trim((string) \Drupal::request()->query->get('executionPanel', '')));
    if (in_array($executionPanel, ['top'], TRUE)) {
      $query['executionPanel'] = $executionPanel;
    }

    $returnToRaw = trim((string) \Drupal::request()->query->get('returnTo', ''));
    $returnTo = rawurldecode($returnToRaw);
    if ($returnTo !== '' && str_starts_with($returnTo, '/')) {
      $query['returnTo'] = $returnTo;
    }

    if (!empty($processUri)) {
      $query['processUri'] = $processUri;

      $userIdentifier = $this->getCurrentUserIdentifier();
      if ($userIdentifier !== '') {
        $simulationContextKey = $this->getSimulationContextKey($decodedStudyUri, $processUri, $userIdentifier);
        $newContext = [
          'simulationType' => $simulationType,
          'testMode' => $testMode,
          'studentIds' => $studentIds,
          'recordedAt' => gmdate('c'),
        ];

        \Drupal::state()->set($simulationContextKey, $newContext);
      }
    }

    return $this->redirect('ctt.editor', [], ['query' => $query]);
  }

  /**
   * Render analytical tools repository management page.
   */
  public function toolsRepositoryPage(): array {
    $basePath = rtrim(\Drupal::request()->getBasePath() ?: '/', '/');
    $drupalBaseUrl = ($basePath === '' ? '/' : $basePath . '/');
    $endpoint = $drupalBaseUrl . 'workflow/api/repo/analytical-tools';
    $processListEndpoint = $drupalBaseUrl . 'workflow/api/process/list';
    $initialStudyUri = trim((string) \Drupal::request()->query->get('studyUri', ''));
    $initialProcessUri = trim((string) \Drupal::request()->query->get('processUri', ''));
    $initialScenarioUri = trim((string) \Drupal::request()->query->get('scenarioUri', ''));
    $initialOwner = trim((string) \Drupal::request()->query->get('owner', ''));
    $initialLanguage = strtolower(trim((string) \Drupal::request()->query->get('language', '')));
    $initialInstitution = trim((string) \Drupal::request()->query->get('institution', ''));
    $returnToRaw = trim((string) \Drupal::request()->query->get('returnTo', ''));
    $returnTo = rawurldecode($returnToRaw);
    if ($returnTo !== '' && !str_starts_with($returnTo, '/')) {
      $returnTo = '';
    }

    $editorMode = $this->isTruthyFlag((string) \Drupal::request()->query->get('editor', ''));
    $editorToolUri = trim((string) \Drupal::request()->query->get('toolUri', ''));

    $baseCollectionQuery = [];
    if ($initialStudyUri !== '') {
      $baseCollectionQuery['studyUri'] = $initialStudyUri;
    }
    if ($initialScenarioUri !== '') {
      $baseCollectionQuery['scenarioUri'] = $initialScenarioUri;
    }
    if ($initialProcessUri !== '') {
      $baseCollectionQuery['processUri'] = $initialProcessUri;
    }
    if ($initialOwner !== '') {
      $baseCollectionQuery['owner'] = $initialOwner;
    }
    if ($initialLanguage !== '') {
      $baseCollectionQuery['language'] = $initialLanguage;
    }
    if ($initialInstitution !== '') {
      $baseCollectionQuery['institution'] = $initialInstitution;
    }
    if ($returnTo !== '') {
      $baseCollectionQuery['returnTo'] = $returnTo;
    }

    $collectionPageUrl = Url::fromRoute('ctt.tools_repository', [], [
      'query' => $baseCollectionQuery,
    ])->toString();
    $editorPageUrl = Url::fromRoute('ctt.tools_repository', [], [
      'query' => array_merge($baseCollectionQuery, ['editor' => '1']),
    ])->toString();

    $backToManageScenarioButton = '';
    if ($returnTo !== '') {
      $backToManageScenarioButton = '<a class="btn btn-sm btn-outline-primary" href="' . Html::escape($returnTo) . '">'
        . Html::escape((string) $this->t('Back to Manage Scenario Elements'))
        . '</a>';
    }

    $ownerOptionMap = [];
    $scenarioOptionMap = [];
    $processOptionMap = [];
    $institutionOptionMap = [];

    $pmsrStatisticsStore = \Drupal::state()->get('pmsr.statistics.data', []);
    if (is_array($pmsrStatisticsStore)
      && isset($pmsrStatisticsStore['members_data'])
      && is_array($pmsrStatisticsStore['members_data'])
      && isset($pmsrStatisticsStore['members_data']['value'])
      && is_array($pmsrStatisticsStore['members_data']['value'])
      && isset($pmsrStatisticsStore['members_data']['value']['members'])
      && is_array($pmsrStatisticsStore['members_data']['value']['members'])) {
      foreach ($pmsrStatisticsStore['members_data']['value']['members'] as $memberRow) {
        if (!is_array($memberRow)) {
          continue;
        }

        $institutionUri = trim((string) ($memberRow['uri'] ?? ''));
        if (!$this->isUri($institutionUri)) {
          continue;
        }

        $institutionLabel = trim((string) ($memberRow['fullName'] ?? $memberRow['label'] ?? $memberRow['shortName'] ?? $institutionUri));
        if ($institutionLabel === '') {
          $institutionLabel = $institutionUri;
        }

        $institutionOptionMap[$institutionUri] = [
          'value' => $institutionUri,
          'label' => $institutionLabel,
        ];
      }
    }

    $catalogRaw = \Drupal::state()->get('ctt.analytical_tools.catalog.v1');
    if (is_array($catalogRaw)) {
      foreach ($catalogRaw as $entry) {
        if (!is_array($entry)) {
          continue;
        }

        $owner = trim((string) ($entry['ownerUserEmail'] ?? $entry['createdBy'] ?? ''));
        if ($owner !== '') {
          $ownerOptionMap[$owner] = $owner;
        }

        $institution = trim((string) ($entry['institution'] ?? ''));
        if ($institution !== '' && !isset($institutionOptionMap[$institution])) {
          $institutionOptionMap[$institution] = [
            'value' => $institution,
            'label' => $institution,
          ];
        }

        $scenarioUri = trim((string) ($entry['scenarioUri'] ?? ''));
        if ($this->isUri($scenarioUri)) {
          $scenarioOptionMap[$scenarioUri] = $scenarioUri;
        }

        $processUri = trim((string) ($entry['processUri'] ?? ''));
        if ($this->isUri($processUri) && !isset($processOptionMap[$processUri])) {
          $processOptionMap[$processUri] = [
            'uri' => $processUri,
            'label' => $processUri,
            'scenarioUri' => $scenarioUri,
          ];
        }
      }
    }

    if (\Drupal::hasService('ctt.hasco_client')) {
      try {
        $processResponse = \Drupal::service('ctt.hasco_client')->listProcesses(500, 0, NULL, NULL);
        $processRows = [];

        if (is_array($processResponse)) {
          if (isset($processResponse['body']) && is_array($processResponse['body'])) {
            $processRows = $processResponse['body'];
          }
          elseif (isset($processResponse['body']['body']) && is_array($processResponse['body']['body'])) {
            $processRows = $processResponse['body']['body'];
          }
          elseif (array_is_list($processResponse)) {
            $processRows = $processResponse;
          }
        }

        foreach ($processRows as $processRow) {
          if (is_object($processRow)) {
            $processRow = (array) $processRow;
          }
          if (!is_array($processRow)) {
            continue;
          }

          $processUri = trim((string) ($processRow['uri'] ?? $processRow['hasURI'] ?? $processRow['processUri'] ?? $processRow['workflowUri'] ?? ''));
          if (!$this->isUri($processUri)) {
            continue;
          }

          $processLabel = trim((string) ($processRow['label'] ?? $processRow['hasContent'] ?? $processRow['name'] ?? $processRow['title'] ?? ''));
          if ($processLabel === '') {
            $processLabel = $processUri;
          }

          $scenarioUri = trim((string) ($processRow['scenarioUri'] ?? $processRow['hasScenarioUri'] ?? $processRow['hasScenario'] ?? $processRow['hasSIRPartOf'] ?? ''));
          if ($this->isUri($scenarioUri)) {
            $scenarioOptionMap[$scenarioUri] = $scenarioUri;
          }

          $processOptionMap[$processUri] = [
            'uri' => $processUri,
            'label' => $processLabel,
            'scenarioUri' => $scenarioUri,
          ];
        }
      }
      catch (\Throwable $ignored) {
      }
    }

    ksort($ownerOptionMap);
    ksort($scenarioOptionMap);

    if ($this->isUri($initialScenarioUri) && !isset($scenarioOptionMap[$initialScenarioUri])) {
      $scenarioOptionMap[$initialScenarioUri] = $initialScenarioUri;
    }

    if ($this->isUri($initialProcessUri) && !isset($processOptionMap[$initialProcessUri])) {
      $processOptionMap[$initialProcessUri] = [
        'uri' => $initialProcessUri,
        'label' => $initialProcessUri,
        'scenarioUri' => $initialScenarioUri,
      ];
    }

    uasort($institutionOptionMap, function (array $left, array $right): int {
      return strcasecmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
    });

    uasort($processOptionMap, function (array $left, array $right): int {
      return strcasecmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
    });

    $ownerOptions = '<option value="">Any owner</option>';
    foreach (array_keys($ownerOptionMap) as $owner) {
      $selected = ($initialOwner !== '' && strcasecmp($owner, $initialOwner) === 0) ? ' selected="selected"' : '';
      $ownerOptions .= '<option value="' . Html::escape($owner) . '"' . $selected . '>' . Html::escape($owner) . '</option>';
    }

    $scenarioOptions = '<option value="">Any scenario</option>';
    foreach (array_keys($scenarioOptionMap) as $scenarioUri) {
      $selected = ($initialScenarioUri !== '' && strcasecmp($scenarioUri, $initialScenarioUri) === 0) ? ' selected="selected"' : '';
      $scenarioOptions .= '<option value="' . Html::escape($scenarioUri) . '"' . $selected . '>' . Html::escape($scenarioUri) . '</option>';
    }

    $processOptions = '<option value="">All processes</option>';
    foreach ($processOptionMap as $processData) {
      $processUri = trim((string) ($processData['uri'] ?? ''));
      if (!$this->isUri($processUri)) {
        continue;
      }

      $processLabel = trim((string) ($processData['label'] ?? $processUri));
      $selected = ($initialProcessUri !== '' && strcasecmp($processUri, $initialProcessUri) === 0) ? ' selected="selected"' : '';
      $processOptions .= '<option value="' . Html::escape($processUri) . '"' . $selected . '>'
        . Html::escape($processLabel . ' [' . $processUri . ']')
        . '</option>';
    }

    $institutionOptions = '<option value="">Any institution</option>';
    foreach ($institutionOptionMap as $institutionData) {
      $institutionValue = trim((string) ($institutionData['value'] ?? ''));
      $institutionLabel = trim((string) ($institutionData['label'] ?? $institutionValue));
      if ($institutionValue === '') {
        continue;
      }
      $selected = ($initialInstitution !== '' && strcasecmp($institutionValue, $initialInstitution) === 0) ? ' selected="selected"' : '';
      $institutionOptions .= '<option value="' . Html::escape($institutionValue) . '"' . $selected . '>' . Html::escape($institutionLabel) . '</option>';
    }

    $languageAnySelected = ($initialLanguage === '' || $initialLanguage === 'any') ? ' selected="selected"' : '';
    $languageRSelected = ($initialLanguage === 'r') ? ' selected="selected"' : '';

    $markup = ''
      . '<div id="ctt-tools-repository-page" class="ctt-tools-repository-page">'
      . '  <p>' . Html::escape((string) $this->t('This collection stores analytical tools metadata used by structured submission workflows.')) . '</p>'
      . '  <p id="ctt-tools-safety-note" class="alert alert-info py-2">' . Html::escape((string) $this->t('Metadata-only registry: scripts are never executed in Drupal from this repository.')) . '</p>'
      . '  <div id="ctt-tools-feedback" class="alert d-none" role="alert"></div>'
      . (!$editorMode ? ''
        . '  <section class="card mb-3">'
        . '    <div class="card-header d-flex justify-content-between align-items-center">'
        . '      <strong>' . Html::escape((string) $this->t('Collection Filters')) . '</strong>'
        . '      <div style="display:flex;gap:.5rem;align-items:center;">'
        . '        <a class="btn btn-sm btn-success" href="' . Html::escape($editorPageUrl) . '">' . Html::escape((string) $this->t('Add Tool')) . '</a>'
        . '      </div>'
        . '    </div>'
        . '    <div class="card-body">'
        . '      <form id="ctt-tools-filter-form" class="row g-2 align-items-end">'
        . '        <div class="col-md-3">'
        . '          <label for="ctt-tools-filter-process-uri" class="form-label">' . Html::escape((string) $this->t('Process URI')) . '</label>'
        . '          <select id="ctt-tools-filter-process-uri" class="form-select">'
        .                $processOptions
        . '          </select>'
        . '        </div>'
        . '        <div class="col-md-2">'
        . '          <label for="ctt-tools-filter-language" class="form-label">' . Html::escape((string) $this->t('Language')) . '</label>'
        . '          <select id="ctt-tools-filter-language" class="form-select">'
        . '            <option value=""' . $languageAnySelected . '>Any</option>'
        . '            <option value="R"' . $languageRSelected . '>R</option>'
        . '          </select>'
        . '        </div>'
        . '        <div class="col-md-2">'
        . '          <label for="ctt-tools-filter-owner" class="form-label">' . Html::escape((string) $this->t('Owner')) . '</label>'
        . '          <select id="ctt-tools-filter-owner" class="form-select">'
        .                $ownerOptions
        . '          </select>'
        . '        </div>'
        . '        <div class="col-md-3">'
        . '          <label for="ctt-tools-filter-institution" class="form-label">' . Html::escape((string) $this->t('Institution')) . '</label>'
          . '          <select id="ctt-tools-filter-institution" class="form-select">'
          .                $institutionOptions
          . '          </select>'
        . '        </div>'
        . '        <div class="col-md-3">'
        . '          <label for="ctt-tools-filter-scenario-uri" class="form-label">' . Html::escape((string) $this->t('Scenario URI')) . '</label>'
        . '          <select id="ctt-tools-filter-scenario-uri" class="form-select">'
        .                $scenarioOptions
        . '          </select>'
        . '        </div>'
        . '        <div class="col-md-3">'
        . '          <label for="ctt-tools-filter-dataset-uri" class="form-label">' . Html::escape((string) $this->t('Dataset URI')) . '</label>'
        . '          <input type="url" id="ctt-tools-filter-dataset-uri" class="form-control" placeholder="http://example.org/dataset/...">'
        . '        </div>'
        . '        <div class="col-md-2 d-grid">'
        . '          <button type="submit" class="btn btn-primary btn-sm">' . Html::escape((string) $this->t('Apply')) . '</button>'
        . '        </div>'
        . '      </form>'
        . '    </div>'
        . '  </section>'
        . '  <section class="table-responsive">'
        . '    <table class="table table-striped table-bordered table-sm align-middle ctt-analytical-tools-table">'
        . '      <thead>'
        . '        <tr>'
        . '          <th>' . Html::escape((string) $this->t('Name')) . '</th>'
        . '          <th>' . Html::escape((string) $this->t('Version')) . '</th>'
        . '          <th>' . Html::escape((string) $this->t('Language')) . '</th>'
        . '          <th>' . Html::escape((string) $this->t('Status')) . '</th>'
        . '          <th>' . Html::escape((string) $this->t('Owner')) . '</th>'
        . '          <th>' . Html::escape((string) $this->t('Process URI')) . '</th>'
        . '          <th>' . Html::escape((string) $this->t('Author')) . '</th>'
        . '          <th>' . Html::escape((string) $this->t('Institution')) . '</th>'
        . '          <th>' . Html::escape((string) $this->t('Release Date')) . '</th>'
        . '          <th>' . Html::escape((string) $this->t('Tool URI')) . '</th>'
        . '          <th>' . Html::escape((string) $this->t('Updated At')) . '</th>'
        . '          <th>' . Html::escape((string) $this->t('Actions')) . '</th>'
        . '        </tr>'
        . '      </thead>'
        . '      <tbody id="ctt-tools-repository-body">'
        . '        <tr><td colspan="12" class="text-center text-muted">' . Html::escape((string) $this->t('Loading tool collection...')) . '</td></tr>'
        . '      </tbody>'
        . '    </table>'
        . '  </section>'
        : '')
      . ($editorMode ? ''
      . '  <section class="card mb-3">'
      . '    <div class="card-header d-flex justify-content-between align-items-center">'
      . '      <strong>' . Html::escape((string) $this->t('Add / Edit Analytical Tool')) . '</strong>'
      . '      <div style="display:flex;gap:.5rem;align-items:center;">'
      . '        <a class="btn btn-sm btn-outline-secondary" href="' . Html::escape($collectionPageUrl) . '">' . Html::escape((string) $this->t('Back to Collection')) . '</a>'
      . '      </div>'
      . '    </div>'
      . '    <div class="card-body">'
      . '      <form id="ctt-tools-editor-form" class="row g-2">'
      . '        <input type="hidden" id="ctt-tool-uri" value="">'
      . '        <div class="col-md-4">'
      . '          <label for="ctt-tool-name" class="form-label">' . Html::escape((string) $this->t('Name')) . '</label>'
      . '          <input type="text" id="ctt-tool-name" class="form-control" required>'
      . '        </div>'
      . '        <div class="col-md-2">'
      . '          <label for="ctt-tool-version" class="form-label">' . Html::escape((string) $this->t('Version')) . '</label>'
      . '          <input type="text" id="ctt-tool-version" class="form-control" placeholder="1.0.0">'
      . '        </div>'
      . '        <div class="col-md-2">'
      . '          <label for="ctt-tool-language" class="form-label">' . Html::escape((string) $this->t('Language')) . '</label>'
      . '          <input type="text" id="ctt-tool-language" class="form-control" placeholder="R, Python, SQL...">'
      . '        </div>'
      . '        <div class="col-md-2">'
      . '          <label for="ctt-tool-release-date" class="form-label">' . Html::escape((string) $this->t('Release Date')) . '</label>'
      . '          <input type="date" id="ctt-tool-release-date" class="form-control">'
      . '        </div>'
      . '        <div class="col-md-3">'
      . '          <label for="ctt-tool-author" class="form-label">' . Html::escape((string) $this->t('Author')) . '</label>'
      . '          <input type="text" id="ctt-tool-author" class="form-control" placeholder="name or email">'
      . '        </div>'
      . '        <div class="col-md-3">'
      . '          <label for="ctt-tool-institution" class="form-label">' . Html::escape((string) $this->t('Institution')) . '</label>'
      . '          <input type="text" id="ctt-tool-institution" class="form-control" placeholder="institution">'
      . '        </div>'
      . '        <div class="col-md-6">'
      . '          <label for="ctt-tool-process-uri" class="form-label">' . Html::escape((string) $this->t('Process URI')) . '</label>'
      . '          <input type="url" id="ctt-tool-process-uri" class="form-control" value="' . Html::escape($initialProcessUri) . '" placeholder="http://example.org/process/..." required>'
      . '        </div>'
      . '        <div class="col-md-6">'
      . '          <label for="ctt-tool-owner-person-uri" class="form-label">' . Html::escape((string) $this->t('Owner Person URI (KGR)')) . '</label>'
      . '          <input type="url" id="ctt-tool-owner-person-uri" class="form-control" placeholder="http://example.org/PER/...">'
      . '        </div>'
      . '        <div class="col-md-3">'
      . '          <label for="ctt-tool-scenario-uri" class="form-label">' . Html::escape((string) $this->t('Scenario URI')) . '</label>'
      . '          <input type="url" id="ctt-tool-scenario-uri" class="form-control" placeholder="http://example.org/scenario/...">'
      . '        </div>'
      . '        <div class="col-md-3">'
      . '          <label for="ctt-tool-dataset-uri" class="form-label">' . Html::escape((string) $this->t('Dataset URI')) . '</label>'
      . '          <input type="url" id="ctt-tool-dataset-uri" class="form-control" placeholder="http://example.org/dataset/...">'
      . '        </div>'
      . '        <div class="col-md-6">'
      . '          <label for="ctt-tool-source-uri" class="form-label">' . Html::escape((string) $this->t('Source Repository URI')) . '</label>'
      . '          <input type="url" id="ctt-tool-source-uri" class="form-control" placeholder="http://example.org/repository/tool">'
      . '        </div>'
      . '        <div class="col-md-3">'
      . '          <label for="ctt-tool-artifact-filename" class="form-label">' . Html::escape((string) $this->t('Artifact Filename')) . '</label>'
      . '          <input type="text" id="ctt-tool-artifact-filename" class="form-control" placeholder="script.R">'
      . '        </div>'
      . '        <div class="col-md-3">'
      . '          <label for="ctt-tool-artifact-uri" class="form-label">' . Html::escape((string) $this->t('Artifact URI')) . '</label>'
      . '          <input type="url" id="ctt-tool-artifact-uri" class="form-control" placeholder="http://example.org/artifacts/script.R">'
      . '        </div>'
      . '        <div class="col-md-12">'
      . '          <label for="ctt-tool-tags" class="form-label">' . Html::escape((string) $this->t('Tags (comma separated)')) . '</label>'
      . '          <input type="text" id="ctt-tool-tags" class="form-control" placeholder="regression, validation">'
      . '        </div>'
      . '        <div class="col-md-12">'
      . '          <label for="ctt-tool-description" class="form-label">' . Html::escape((string) $this->t('Description')) . '</label>'
      . '          <textarea id="ctt-tool-description" class="form-control" rows="2"></textarea>'
      . '        </div>'
      . '        <div class="col-12 d-flex gap-2">'
      . '          <button type="submit" class="btn btn-success btn-sm">' . Html::escape((string) $this->t('Save Tool')) . '</button>'
      . '          <button type="button" id="ctt-tool-reset" class="btn btn-outline-secondary btn-sm">' . Html::escape((string) $this->t('Reset Form')) . '</button>'
      . '          <small class="text-muted align-self-center">' . Html::escape((string) $this->t('Only the tool owner can update metadata or remove a tool.')) . '</small>'
      . '        </div>'
      . '      </form>'
      . '    </div>'
      . '  </section>'
      : '')
      . ($backToManageScenarioButton !== ''
        ? '  <div class="mt-2 mb-0">' . $backToManageScenarioButton . '</div>'
        : '')
      . '</div>';

    return [
      '#type' => 'markup',
      '#markup' => Markup::create($markup),
      '#attached' => [
        'library' => [
          'ctt/ctt-tools-repository',
        ],
        'drupalSettings' => [
          'cttToolsRepository' => [
            'endpoint' => $endpoint,
            'processListEndpoint' => $processListEndpoint,
            'initialStudyUri' => $initialStudyUri,
            'initialProcessUri' => $initialProcessUri,
            'initialScenarioUri' => $initialScenarioUri,
            'mode' => $editorMode ? 'editor' : 'collection',
            'editorToolUri' => $editorToolUri,
            'collectionPageUrl' => $collectionPageUrl,
            'editorPageUrl' => $editorPageUrl,
          ],
        ],
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Render the Epic 5 R analysis page.
   */
  public function rAnalysisPage(): array {
    $basePath = rtrim(
      \Drupal::request()->getBasePath() ?: '/',
      '/'
    );
    $drupalBaseUrl = ($basePath === '' ? '/' : $basePath . '/');

    $toolsEndpoint = $drupalBaseUrl . 'workflow/api/repo/analytical-tools';
    $associationsEndpoint = $drupalBaseUrl . 'workflow/api/submission/associations';
    $statusEndpoint = $drupalBaseUrl . 'workflow/api/submission/status';
    $executeEndpoint = $drupalBaseUrl . 'workflow/api/r-analysis/execute';
    $studyAutocompleteEndpoint = $drupalBaseUrl . 'workflow/api/r-analysis/autocomplete/study';
    $processAutocompleteEndpoint = $drupalBaseUrl . 'workflow/api/r-analysis/autocomplete/process';

    $initialStudyUri = trim((string) \Drupal::request()->query->get('studyUri', ''));
    $initialProcessUri = trim((string) \Drupal::request()->query->get('processUri', ''));

    $markup = ''
      . '<div id="ctt-r-analysis-page" class="ctt-r-analysis-page">'
      . '  <p class="ctt-r-intro">' . Html::escape((string) $this->t('Run R analysis with real study/process context from PMSR.')) . '</p>'
      . '  <div id="ctt-r-feedback" class="alert d-none" role="alert"></div>'
      . '  <section class="card mb-3">'
      . '    <div class="card-header"><strong>' . Html::escape((string) $this->t('Execution Context')) . '</strong></div>'
      . '    <div class="card-body">'
      . '      <form id="ctt-r-analysis-form" class="row g-2 align-items-end">'
      . '        <div class="col-md-6">'
      . '          <label for="ctt-r-study-uri" class="form-label">' . Html::escape((string) $this->t('Study (search by name)')) . '</label>'
      . '          <input type="text" id="ctt-r-study-uri" name="studyUri" class="form-control form-autocomplete" data-autocomplete-path="' . Html::escape($studyAutocompleteEndpoint) . '" required value="' . Html::escape($initialStudyUri) . '" placeholder="Type study name or paste URI" autocomplete="off">'
      . '          <div id="ctt-r-study-uri-hint" class="ctt-r-inline-hint text-muted">' . Html::escape((string) $this->t('Use autocomplete label [URI] format or paste a full http(s) URI.')) . '</div>'
      . '          <div id="ctt-r-study-uri-error" class="ctt-r-inline-error d-none" role="alert" aria-live="polite"></div>'
      . '        </div>'
      . '        <div class="col-md-6">'
      . '          <label for="ctt-r-process-uri" class="form-label">' . Html::escape((string) $this->t('Process (search by name)')) . '</label>'
      . '          <input type="text" id="ctt-r-process-uri" name="processUri" class="form-control form-autocomplete" data-autocomplete-path="' . Html::escape($processAutocompleteEndpoint) . '" required value="' . Html::escape($initialProcessUri) . '" placeholder="Type process name or paste URI" autocomplete="off">'
      . '          <div id="ctt-r-process-uri-hint" class="ctt-r-inline-hint text-muted">' . Html::escape((string) $this->t('Use autocomplete label [URI] format or paste a full http(s) URI.')) . '</div>'
      . '          <div id="ctt-r-process-uri-error" class="ctt-r-inline-error d-none" role="alert" aria-live="polite"></div>'
      . '        </div>'
      . '        <div class="col-md-8">'
      . '          <label for="ctt-r-tool-uri" class="form-label">' . Html::escape((string) $this->t('R Tool (from real repository)')) . '</label>'
      . '          <select id="ctt-r-tool-uri" class="form-select" required>'
      . '            <option value="">' . Html::escape((string) $this->t('Load context first to list R tools')) . '</option>'
      . '          </select>'
      . '        </div>'
      . '        <div class="col-md-4">'
      . '          <label for="ctt-r-entrypoint" class="form-label">' . Html::escape((string) $this->t('Entrypoint override (optional)')) . '</label>'
      . '          <input type="text" id="ctt-r-entrypoint" class="form-control" placeholder="main">'
      . '        </div>'
      . '        <div class="col-12">'
      . '          <label for="ctt-r-arguments-json" class="form-label">' . Html::escape((string) $this->t('Arguments (JSON object)')) . '</label>'
      . '          <textarea id="ctt-r-arguments-json" class="form-control" rows="5" placeholder="{&#10;  &quot;alpha&quot;: 0.05,&#10;  &quot;iterations&quot;: 1000&#10;}"></textarea>'
      . '        </div>'
      . '        <div class="col-md-8">'
      . '          <label for="ctt-r-argument-template" class="form-label">' . Html::escape((string) $this->t('Argument Template (clinical presets)')) . '</label>'
      . '          <select id="ctt-r-argument-template" class="form-select">'
      . '            <option value="">' . Html::escape((string) $this->t('Select a template')) . '</option>'
      . '            <option value="aspiration-baseline">' . Html::escape((string) $this->t('Aspiration baseline check')) . '</option>'
      . '            <option value="aspiration-high-risk">' . Html::escape((string) $this->t('Aspiration high-risk patient')) . '</option>'
      . '            <option value="aspiration-followup">' . Html::escape((string) $this->t('Aspiration follow-up reassessment')) . '</option>'
      . '          </select>'
      . '        </div>'
      . '        <div class="col-md-4 d-flex align-items-end ctt-r-template-actions">'
      . '          <button type="button" id="ctt-r-apply-template" class="btn btn-outline-info btn-sm w-100">' . Html::escape((string) $this->t('Apply Template')) . '</button>'
      . '        </div>'
      . '        <div class="col-12">'
      . '          <div class="form-check ctt-r-validate-check">'
      . '            <input class="form-check-input" type="checkbox" id="ctt-r-validate-only" checked>'
      . '            <label class="form-check-label" for="ctt-r-validate-only">' . Html::escape((string) $this->t('Validate only (skip upstream execution)')) . '</label>'
      . '          </div>'
      . '          <div class="ctt-r-persistence-note text-muted">' . Html::escape((string) $this->t('Form context is saved in this browser to speed up repeated tests.')) . '</div>'
      . '        </div>'
      . '        <div class="col-12 d-flex flex-wrap gap-2">'
      . '          <button type="button" id="ctt-r-load-context" class="btn btn-outline-primary btn-sm">' . Html::escape((string) $this->t('Load Real Context')) . '</button>'
      . '          <button type="submit" id="ctt-r-run-analysis" class="btn btn-success btn-sm">' . Html::escape((string) $this->t('Run R Analysis')) . '</button>'
      . '          <button type="button" id="ctt-r-copy-payload" class="btn btn-outline-secondary btn-sm">' . Html::escape((string) $this->t('Copy Request Payload')) . '</button>'
      . '          <button type="button" id="ctt-r-download-log" class="btn btn-outline-secondary btn-sm" disabled>' . Html::escape((string) $this->t('Download Execution Log')) . '</button>'
      . '          <button type="button" id="ctt-r-clear-saved-context" class="btn btn-outline-danger btn-sm">' . Html::escape((string) $this->t('Clear Saved Context')) . '</button>'
      . '        </div>'
      . '      </form>'
      . '    </div>'
      . '  </section>'
      . '  <section class="card mb-3">'
      . '    <div class="card-header"><strong>' . Html::escape((string) $this->t('Study Association Context (real state)')) . '</strong></div>'
      . '    <div class="card-body">'
      . '      <div id="ctt-r-context-summary" class="ctt-r-context-summary text-muted">' . Html::escape((string) $this->t('Load context to inspect datasets, variables, and medical images associated with this study.')) . '</div>'
      . '    </div>'
      . '  </section>'
      . '  <section class="card">'
      . '    <div class="card-header"><strong>' . Html::escape((string) $this->t('Execution Response')) . '</strong></div>'
      . '    <div class="card-body">'
      . '      <div id="ctt-r-exec-diagnostics" class="ctt-r-diagnostics ctt-r-diagnostics-muted">' . Html::escape((string) $this->t('Run validation or execution to view diagnostics summary.')) . '</div>'
      . '      <pre id="ctt-r-response-output" class="ctt-r-response-output">' . Html::escape((string) $this->t('No execution yet.')) . '</pre>'
      . '    </div>'
      . '  </section>'
      . '</div>';

    return [
      '#type' => 'markup',
      '#markup' => Markup::create($markup),
      '#attached' => [
        'library' => [
          'ctt/ctt-r-analysis',
        ],
        'drupalSettings' => [
          'cttRAnalysis' => [
            'toolsEndpoint' => $toolsEndpoint,
            'associationsEndpoint' => $associationsEndpoint,
            'statusEndpoint' => $statusEndpoint,
            'executeEndpoint' => $executeEndpoint,
            'studyAutocompleteEndpoint' => $studyAutocompleteEndpoint,
            'processAutocompleteEndpoint' => $processAutocompleteEndpoint,
            'initialStudyUri' => $initialStudyUri,
            'initialProcessUri' => $initialProcessUri,
          ],
        ],
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
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
        $obj = NULL;
        if (is_string($repo) && trim($repo) !== '') {
          $obj = json_decode($repo);
        }
        elseif (is_object($repo)) {
          $obj = $repo;
        }
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
    $process_from_query = (string) \Drupal::request()->query->get('processUri', '');
    if (empty($process_uri) && $process_from_query !== '') {
      $process_uri = $this->decodeQueryUri($process_from_query);
    }

    $study_from_query = (string) \Drupal::request()->query->get('studyUri', '');
    $study_uri = $study_from_query !== '' ? $this->decodeQueryUri($study_from_query) : NULL;

    // Execution context passed from the "Create Execution" flow.
    $executionFlag = (string) \Drupal::request()->query->get('execution', '');
    $isExecutionMode = $this->isTruthyFlag($executionFlag);

    $submissionFlag = (string) \Drupal::request()->query->get('submission', '');
    $isSubmissionMode = $this->isTruthyFlag($submissionFlag);

    $autoExecuteFlag = (string) \Drupal::request()->query->get('autoExecute', '');
    $autoExecute = $this->isTruthyFlag($autoExecuteFlag);
    $testMode = $this->isTruthyFlag((string) \Drupal::request()->query->get('test', ''));

    $executionPanel = strtolower(trim((string) \Drupal::request()->query->get('executionPanel', '')));
    if (!in_array($executionPanel, ['top'], TRUE)) {
      $executionPanel = 'default';
    }

    $returnToRaw = trim((string) \Drupal::request()->query->get('returnTo', ''));
    $returnTo = rawurldecode($returnToRaw);
    if ($returnTo !== '' && !str_starts_with($returnTo, '/')) {
      $returnTo = '';
    }

    $createFlag = (string) \Drupal::request()->query->get('create', '');
    $isCreateMode = $this->isTruthyFlag($createFlag);

    if ($isSubmissionMode && empty($process_uri) && !empty($study_uri)) {
      $stored = \Drupal::state()->get('ctt.study_process.' . sha1($study_uri));
      if (is_string($stored) && trim($stored) !== '') {
        $process_uri = trim($stored);
      }
    }

    $resolvedProcessUri = $process_uri ? rawurldecode((string) $process_uri) : NULL;
    if (!empty($resolvedProcessUri) && \Drupal::hasService('ctt.hasco_client')) {
      try {
        $processProbe = \Drupal::service('ctt.hasco_client')->getByUri($resolvedProcessUri);
        if (!is_array($processProbe) || !empty($processProbe['error'])) {
          $this->messenger()->addWarning($this->t('Requested workflow process was not found. Opening CTT in create mode so you can create the process first.'));
          $process_uri = NULL;
          $resolvedProcessUri = NULL;
          $isExecutionMode = FALSE;
          $isSubmissionMode = FALSE;
          $isCreateMode = TRUE;
        }
      }
      catch (\Throwable $ignored) {
        // Keep requested URI untouched when API is temporarily unavailable.
      }
    }

    if (!empty($study_uri) && !empty($resolvedProcessUri)) {
      $this->persistStudyProcessAssociation($study_uri, (string) $resolvedProcessUri);

      if (($isSubmissionMode && $autoExecute) || $isExecutionMode) {
        $this->registerProcessExecutionStart((string) $study_uri, (string) $resolvedProcessUri, $isExecutionMode ? 'execution' : 'submission-auto');
      }
    }

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

    $editorMode = 'edit';
    if ($isExecutionMode) {
      $editorMode = 'execution';
    }
    elseif ($isSubmissionMode) {
      $editorMode = 'submission';
    }
    elseif ($isCreateMode) {
      $editorMode = 'create';
    }

    $canAdminister = $account->hasPermission('administer ctt');
    $workflowAccess = $this->buildWorkflowAccessContext($study_uri, $email, $isExecutionMode);

    $permissionMatrix = [
      'canCreateWorkflow' => $canAdminister || $account->hasPermission('create ctt workflow'),
      'canEditWorkflow' => $canAdminister || $account->hasPermission('edit ctt workflow'),
      'canSubmitWorkflow' => $canAdminister || $account->hasPermission('submit ctt workflow'),
      'canAdminister' => $canAdminister,
    ];

    if (!empty($workflowAccess['readOnlyPreview'])) {
      $permissionMatrix['canCreateWorkflow'] = FALSE;
      $permissionMatrix['canEditWorkflow'] = FALSE;
      $permissionMatrix['canSubmitWorkflow'] = FALSE;
      $permissionMatrix['canAdminister'] = FALSE;
    }

    $editorialStates = $this->getEditorialStates();
    $editorialTransitions = $this->getEditorialTransitions();

    $defaultEditorialState = 'draft';
    if ($isSubmissionMode) {
      $defaultEditorialState = 'under review';
    }
    elseif ($isExecutionMode) {
      $defaultEditorialState = 'current';
    }

    $currentEditorialStatus = NULL;
    if (!empty($study_uri)) {
      $storedStatus = \Drupal::state()->get('ctt.study_status.' . sha1($study_uri));
      if (is_string($storedStatus)) {
        $normalizedStoredStatus = strtolower(trim($storedStatus));
        if (in_array($normalizedStoredStatus, $editorialStates, TRUE)) {
          $currentEditorialStatus = $normalizedStoredStatus;
        }
      }
    }

    if ($currentEditorialStatus === NULL) {
      if ($isSubmissionMode) {
        $currentEditorialStatus = 'draft';
      }
      else {
        $currentEditorialStatus = $defaultEditorialState;
      }
    }

    $simulationType = strtolower(trim((string) \Drupal::request()->query->get('simulationType', '')));
    if (!in_array($simulationType, ['individual', 'cohort'], TRUE)) {
      $simulationType = 'individual';
    }

    $simulationStudentIds = [];
    if ($isSubmissionMode && !empty($study_uri) && !empty($resolvedProcessUri)) {
      $userIdentifier = $this->getCurrentUserIdentifier();
      if ($userIdentifier !== '') {
        $simulationContext = \Drupal::state()->get($this->getSimulationContextKey((string) $study_uri, (string) $resolvedProcessUri, $userIdentifier), []);
        if (is_array($simulationContext)) {
          $contextType = strtolower(trim((string) ($simulationContext['simulationType'] ?? '')));
          if (in_array($contextType, ['individual', 'cohort'], TRUE)) {
            $simulationType = $contextType;
          }

          if (isset($simulationContext['studentIds']) && is_array($simulationContext['studentIds'])) {
            foreach ($simulationContext['studentIds'] as $candidateStudentId) {
              $normalizedStudentId = trim((string) $candidateStudentId);
              if ($normalizedStudentId !== '') {
                $simulationStudentIds[$normalizedStudentId] = $normalizedStudentId;
              }
            }
          }
        }
      }
    }

    if ($isSubmissionMode && $simulationType === 'cohort' && empty($simulationStudentIds) && !empty($study_uri)) {
      foreach ($this->resolveSocStudentIds((string) $study_uri) as $candidateStudentId) {
        $normalizedStudentId = trim((string) $candidateStudentId);
        if ($normalizedStudentId !== '') {
          $simulationStudentIds[$normalizedStudentId] = $normalizedStudentId;
        }
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
      'connectionTimeoutMs' => 10000,
      'mode' => $editorMode,
      'readOnlyPreview' => !empty($workflowAccess['readOnlyPreview']),
      'processUri' => $resolvedProcessUri,
      'studyUri' => $study_uri,
      'permissions' => $permissionMatrix,
      'workflowAccess' => $workflowAccess,
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
      'submission' => [
        'enabled' => $isSubmissionMode,
        'mode' => $isSubmissionMode ? 'structured' : 'disabled',
        'studyUri' => $study_uri,
        'processUri' => $resolvedProcessUri,
        'validationEndpoint' => $drupal_base_url . 'workflow/api/submission/validate',
        'associationsEndpoint' => $drupal_base_url . 'workflow/api/submission/associations',
        'analyticalToolsEndpoint' => $drupal_base_url . 'workflow/api/repo/analytical-tools',
        'statusEndpoint' => $drupal_base_url . 'workflow/api/submission/status',
        'requiredFields' => ['studyUri', 'processUri'],
      ],
      'toolsRepository' => [
        'endpoint' => $drupal_base_url . 'workflow/api/repo/analytical-tools',
        'page' => $drupal_base_url . 'workflow/tools-repository',
      ],
      'editorial' => [
        'states' => $editorialStates,
        'defaultState' => $defaultEditorialState,
        'currentStatus' => $currentEditorialStatus,
        'allowedTransitions' => $editorialTransitions,
      ],
      'execution' => [
        'mode' => $isExecutionMode ? 'execution' : 'edit',
        'readOnlyPreview' => !empty($workflowAccess['readOnlyPreview']),
        'daUri' => $daUri,
        'dataFileUri' => $dataFileUri,
        'studyUri' => $study_uri,
        'processUri' => $resolvedProcessUri,
      ],
      'specialExecution' => [
        'enabled' => $isSubmissionMode,
        'autoExecute' => $isSubmissionMode && $autoExecute,
        'testMode' => $isSubmissionMode && $testMode,
        'executionPanel' => $executionPanel,
        'redirectOnCompletion' => $isSubmissionMode && $autoExecute && $returnTo !== '',
        'returnTo' => $returnTo,
        'simulationType' => $simulationType,
        'studentIds' => array_values($simulationStudentIds),
        'cohortProgressKey' => (!empty($study_uri) && !empty($resolvedProcessUri))
          ? ('ctt.cohort.progress.' . sha1((string) $study_uri . '|' . (string) $resolvedProcessUri))
          : '',
      ],
    ];

    $drupal_settings['instrumentSelection'] = $this->buildInstrumentSelectionContext($study_uri);

    $scenarioLabel = $this->resolveLabelByUri($study_uri);
    $processLabel = $this->resolveLabelByUri($resolvedProcessUri);

    $backToEditProcessBasedStudyUrl = '';
    $backToManageScenarioElementsUrl = '';
    if (!empty($study_uri)) {
      $backToEditProcessBasedStudyUrl = Url::fromRoute('std.edit_processbasedstudy', [
        'studyuri' => rawurlencode(base64_encode((string) $study_uri)),
      ])->toString();

      $backToManageScenarioElementsUrl = Url::fromRoute('std.manage_study_elements', [
        'studyuri' => base64_encode((string) $study_uri),
      ])->toString();
    }

    $executionBackUrl = $backToEditProcessBasedStudyUrl;
    $isExecutionCanvas = $isExecutionMode || ($isSubmissionMode && $autoExecute);
    if ($isExecutionCanvas) {
      if ($returnTo !== '') {
        $executionBackUrl = $returnTo;
      }
      elseif ($backToManageScenarioElementsUrl !== '') {
        $executionBackUrl = $backToManageScenarioElementsUrl;
      }
    }

    $editorContext = [
      'scenarioLabel' => $scenarioLabel,
      'scenarioUri' => (string) ($study_uri ?? ''),
      'processLabel' => $processLabel,
      'processUri' => (string) ($resolvedProcessUri ?? ''),
      'backToEditProcessBasedStudyUrl' => $executionBackUrl,
    ];

    $drupal_settings['editorContext'] = $editorContext;

    return [
      '#theme' => 'ctt_editor',
      '#title' => '',
      '#process_uri' => $process_uri,
      '#api_settings' => $drupal_settings,
      '#editor_context' => $editorContext,
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
