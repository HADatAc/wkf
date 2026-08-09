<?php

namespace Drupal\ctt\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Component\Utility\Xss;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\ctt\Service\CttHascoClient;
use Drupal\ctt\Service\WorkflowLayoutExporter;
use Drupal\rep\Utils;

/**
 * API proxy controller - sits between the CTT React editor and hascoapi.
 *
 * All endpoints return JsonResponse so the frontend DrupalAdapter can
 * consume them via fetch().  Each method delegates to CttHascoClient
 * which handles the HTTP request to hascoapi.
 */
class CttApiController extends ControllerBase {

  private const PMSR_ADMIN_OWNER_EMAIL = 'admin@pmsr.com';
  private const ANY_PROCESS_URI = 'http://hadatac.org/ont/hasco/AnyProcess';

  /**
   * @var \Drupal\ctt\Service\CttHascoClient
   */
  protected $hascoClient;

  /**
   * @var \Drupal\ctt\Service\WorkflowLayoutExporter
   */
  protected $workflowLayoutExporter;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = new static();
    $instance->hascoClient = $container->get('ctt.hasco_client');
    $instance->workflowLayoutExporter = $container->get('ctt.workflow_layout_exporter');
    return $instance;
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
   * Checks if a string looks like an HTTP(S) URI.
   */
  protected function isUri(string $value): bool {
    $normalized = trim($value);
    return $normalized !== '' && (str_starts_with($normalized, 'http://') || str_starts_with($normalized, 'https://'));
  }

  /**
   * Checks if host points to the local machine loopback aliases.
   */
  protected function isLocalhostHost(string $host): bool {
    $normalized = strtolower(trim($host));
    return $normalized === 'localhost' || $normalized === '127.0.0.1';
  }

  /**
   * Rebuild URI from parsed URL parts.
   */
  protected function buildUriFromParts(array $parts): string {
    if (empty($parts['scheme']) || empty($parts['host'])) {
      return '';
    }

    $uri = (string) $parts['scheme'] . '://';

    if (!empty($parts['user'])) {
      $uri .= (string) $parts['user'];
      if (array_key_exists('pass', $parts) && $parts['pass'] !== NULL && $parts['pass'] !== '') {
        $uri .= ':' . (string) $parts['pass'];
      }
      $uri .= '@';
    }

    $uri .= (string) $parts['host'];

    if (isset($parts['port']) && is_numeric($parts['port'])) {
      $uri .= ':' . (string) $parts['port'];
    }

    $uri .= (string) ($parts['path'] ?? '');

    if (isset($parts['query']) && $parts['query'] !== '') {
      $uri .= '?' . (string) $parts['query'];
    }

    if (isset($parts['fragment']) && $parts['fragment'] !== '') {
      $uri .= '#' . (string) $parts['fragment'];
    }

    return $uri;
  }

  /**
   * Replace localhost/127.0.0.1 with host.docker.internal for container reachability.
   */
  protected function normalizeContainerReachableUri(string $value): string {
    $uri = trim($value);
    if (!$this->isUri($uri)) {
      return $uri;
    }

    $parts = parse_url($uri);
    if (!is_array($parts) || empty($parts['host'])) {
      return $uri;
    }

    if (!$this->isLocalhostHost((string) $parts['host'])) {
      return $uri;
    }

    $parts['host'] = 'host.docker.internal';
    $normalized = $this->buildUriFromParts($parts);
    return $normalized !== '' ? $normalized : $uri;
  }

  /**
   * Normalize legacy element types in /{elementType}/keywordtype/... proxy paths.
   *
   * Some HASCOAPI deployments reject *instance elementType values in this
   * endpoint family (e.g. platforminstance), while canonical singular types
   * work consistently.
   */
  protected function normalizeLegacyKeywordTypeProxyPath(string $path): string {
    $normalized = ltrim(trim($path), '/');
    if ($normalized === '') {
      return $normalized;
    }

    if (!preg_match('#^([^/]+)/keywordtype/(.+)$#i', $normalized, $matches)) {
      return $normalized;
    }

    $element_type = strtolower(trim((string) ($matches[1] ?? '')));
    $suffix = (string) ($matches[2] ?? '');

    $map = [
      'workflow' => 'process',
      'workflowstem' => 'processstem',
      'instrumentinstance' => 'instrument',
      'componentinstance' => 'component',
      'platforminstance' => 'platform',
    ];

    if (!isset($map[$element_type])) {
      return $normalized;
    }

    return $map[$element_type] . '/keywordtype/' . $suffix;
  }

  /**
   * Extract study code token (e.g. STD123...) from study URI.
   */
  protected function extractStudyCodeFromUri(string $studyUri): string {
    if (!preg_match('/STD[0-9A-Za-z_-]+/', $studyUri, $matches)) {
      return '';
    }

    return trim((string) ($matches[0] ?? ''));
  }

  /**
   * Score CSV dataset filename candidates (higher score = better default).
   */
  protected function scoreDatasetFilenameCandidate(string $filename): int {
    $name = strtoupper(trim($filename));
    if ($name === '' || !str_ends_with($name, '.CSV')) {
      return -1000;
    }

    $score = 0;
    if (str_starts_with($name, 'DA_')) {
      $score += 20;
    }
    if (str_contains($name, 'SIMULADA')) {
      $score += 120;
    }
    if (str_contains($name, 'DATASET')) {
      $score += 10;
    }
    if (str_contains($name, 'QUESTIONARIO') || str_contains($name, 'VARIAVEIS')) {
      $score -= 30;
    }

    return $score;
  }

  /**
   * Resolve a dataset CSV filename for study-bound R execution fallback.
   */
  protected function resolveStudyDatasetFilename(string $studyUri): string {
    $normalizedStudyUri = trim($studyUri);
    if (!$this->isUri($normalizedStudyUri)) {
      return '';
    }

    $latest = \Drupal::state()->get('tmp.auto.e2e.latest');
    if (is_array($latest)) {
      $latestStudy = trim((string) (($latest['entities']['study'] ?? '')));
      if ($latestStudy !== '' && strcasecmp($latestStudy, $normalizedStudyUri) === 0) {
        $files = is_array($latest['files'] ?? NULL) ? $latest['files'] : [];
        foreach (['simulated_dataset_csv', 'questionnaire_csv', 'variable_dictionary_csv'] as $key) {
          $candidateUri = trim((string) ($files[$key] ?? ''));
          if ($candidateUri === '') {
            continue;
          }

          $candidateName = trim((string) basename($candidateUri));
          if ($this->scoreDatasetFilenameCandidate($candidateName) > -1000) {
            return $candidateName;
          }
        }
      }
    }

    $studyCode = $this->extractStudyCodeFromUri($normalizedStudyUri);
    if ($studyCode === '' || !\Drupal::hasService('file_system')) {
      return '';
    }

    $fileSystem = \Drupal::service('file_system');
    $realDir = $fileSystem->realpath('private://std/' . $studyCode . '/da');
    if (!is_string($realDir) || $realDir === '' || !is_dir($realDir)) {
      return '';
    }

    $csvFiles = glob(rtrim($realDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.csv');
    if (!is_array($csvFiles) || empty($csvFiles)) {
      return '';
    }

    $bestName = '';
    $bestScore = -1000;
    foreach ($csvFiles as $csvPath) {
      $candidateName = trim((string) basename((string) $csvPath));
      $score = $this->scoreDatasetFilenameCandidate($candidateName);
      if ($score > $bestScore) {
        $bestScore = $score;
        $bestName = $candidateName;
      }
    }

    return $bestName;
  }

  /**
   * List local Scenario DA CSV files as selectable R input candidates.
   *
   * @return array<int, array<string, string>>
   */
  protected function listStudyDataAcquisitionFiles(string $studyUri, Request $request): array {
    $normalizedStudyUri = trim($studyUri);
    if (!$this->isUri($normalizedStudyUri) || !\Drupal::hasService('file_system')) {
      return [];
    }

    $studyCode = $this->extractStudyCodeFromUri($normalizedStudyUri);
    if ($studyCode === '') {
      return [];
    }

    $fileSystem = \Drupal::service('file_system');
    $realDir = $fileSystem->realpath('private://std/' . $studyCode . '/da');
    if (!is_string($realDir) || $realDir === '' || !is_dir($realDir)) {
      return [];
    }

    $csvFiles = glob(rtrim($realDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.csv');
    if (!is_array($csvFiles) || empty($csvFiles)) {
      return [];
    }

    sort($csvFiles);

    $entries = [];
    foreach ($csvFiles as $csvPath) {
      $filename = trim((string) basename((string) $csvPath));
      if ($filename === '') {
        continue;
      }

      $downloadUrl = $this->buildDatasetDownloadUrlForStudy($normalizedStudyUri, $filename, $request, []);
      if (!$this->isUri($downloadUrl)) {
        continue;
      }

      $entries[] = [
        'uri' => $downloadUrl,
        'label' => $filename,
        'filename' => $filename,
        'source' => 'study-da-file',
      ];
    }

    return $entries;
  }

  /**
   * Extract CSV filename candidate from URI/path/text.
   */
  protected function extractCsvFilenameCandidate(string $value): string {
    $raw = trim($value);
    if ($raw === '') {
      return '';
    }

    $path = $raw;
    if ($this->isUri($raw)) {
      $parsedPath = (string) (parse_url($raw, PHP_URL_PATH) ?? '');
      if ($parsedPath !== '') {
        $path = $parsedPath;
      }
    }

    $candidate = trim((string) basename($path));
    if ($candidate === '') {
      return '';
    }

    return str_ends_with(strtolower($candidate), '.csv') ? $candidate : '';
  }

  /**
   * Resolve CSV filename from a dataset URI metadata record.
   */
  protected function resolveDatasetFilenameFromUri(string $datasetUri): string {
    $normalizedDatasetUri = trim($datasetUri);
    if (!$this->isUri($normalizedDatasetUri)) {
      return '';
    }

    $fromUriPath = $this->extractCsvFilenameCandidate($normalizedDatasetUri);
    if ($fromUriPath !== '') {
      return $fromUriPath;
    }

    try {
      $dataset = $this->hascoClient->getByUri($normalizedDatasetUri);
      $candidates = [];

      if (is_array($dataset)) {
        foreach (['filename', 'fileName', 'hasFileName', 'name', 'label', 'title', 'downloadFilename', 'downloadFileName', 'hasDownloadFileName', 'hasDataFileName', 'sourceFileName'] as $field) {
          if (isset($dataset[$field])) {
            $candidates[] = (string) $dataset[$field];
          }
        }
      }
      elseif (is_object($dataset)) {
        foreach (['filename', 'fileName', 'hasFileName', 'name', 'label', 'title', 'downloadFilename', 'downloadFileName', 'hasDownloadFileName', 'hasDataFileName', 'sourceFileName'] as $field) {
          if (isset($dataset->{$field})) {
            $candidates[] = (string) $dataset->{$field};
          }
        }
      }

      foreach ($candidates as $candidate) {
        $filename = $this->extractCsvFilenameCandidate($candidate);
        if ($filename !== '') {
          return $filename;
        }
      }
    }
    catch (\Throwable $ignored) {
      return '';
    }

    return '';
  }

  /**
   * Resolve execution dataset filename, prioritizing explicit dataset selection.
   */
  protected function resolveExecutionDatasetFilename(string $studyUri, string $selectedDatasetUri = '', string $selectedDatasetLabel = '', string $selectedDatasetFilename = ''): string {
    $normalizedSelectedUri = trim($selectedDatasetUri);
    $normalizedSelectedLabel = trim($selectedDatasetLabel);
    $normalizedSelectedFilename = trim($selectedDatasetFilename);

    if ($normalizedSelectedFilename !== '') {
      $fromFilename = $this->extractCsvFilenameCandidate($normalizedSelectedFilename);
      if ($fromFilename !== '') {
        return $fromFilename;
      }
      return '';
    }

    if ($normalizedSelectedUri !== '' || $normalizedSelectedLabel !== '') {
      $fromSelection = $this->resolveDatasetFilenameFromUri($normalizedSelectedUri);
      if ($fromSelection !== '') {
        return $fromSelection;
      }

      $fromLabel = $this->extractCsvFilenameCandidate($normalizedSelectedLabel);
      if ($fromLabel !== '') {
        return $fromLabel;
      }

      return '';
    }

    return $this->resolveStudyDatasetFilename($studyUri);
  }

  /**
   * Resolve public Drupal base URL suitable for HASCOAPI container downloads.
   */
  protected function resolvePublicBaseUrlForRAnalysis(Request $request, array $tool = []): string {
    $envBase = trim((string) getenv('PMSR_BASE_URL'));
    if ($this->isUri($envBase)) {
      return rtrim($this->normalizeContainerReachableUri($envBase), '/');
    }

    $artifactUri = trim((string) ($tool['artifactUri'] ?? ''));
    if ($this->isUri($artifactUri)) {
      $artifactUri = $this->normalizeContainerReachableUri($artifactUri);
      $parts = parse_url($artifactUri);
      if (is_array($parts) && !empty($parts['scheme']) && !empty($parts['host'])) {
        $origin = (string) $parts['scheme'] . '://' . (string) $parts['host'];
        if (isset($parts['port']) && is_numeric($parts['port'])) {
          $origin .= ':' . (string) $parts['port'];
        }

        $path = (string) ($parts['path'] ?? '');
        $stdPos = strpos($path, '/std/');
        if ($stdPos !== FALSE) {
          return rtrim($origin . substr($path, 0, $stdPos), '/');
        }

        return rtrim($origin, '/');
      }
    }

    $scheme = trim((string) $request->getScheme()) ?: 'http';
    $host = trim((string) $request->getHost());
    if ($host === '') {
      $host = 'host.docker.internal';
    }
    if ($this->isLocalhostHost($host)) {
      $host = 'host.docker.internal';
    }

    $port = (int) $request->getPort();
    $basePath = rtrim((string) $request->getBasePath(), '/');

    $base = $scheme . '://' . $host;
    if (($scheme === 'http' && $port > 0 && $port !== 80) || ($scheme === 'https' && $port > 0 && $port !== 443)) {
      $base .= ':' . $port;
    }

    if ($basePath !== '') {
      $base .= $basePath;
    }

    return rtrim($base, '/');
  }

  /**
   * Build dataset download URL for std/download-file endpoint.
   */
  protected function buildDatasetDownloadUrlForStudy(string $studyUri, string $datasetFilename, Request $request, array $tool = []): string {
    if (!$this->isUri($studyUri) || trim($datasetFilename) === '') {
      return '';
    }

    $baseUrl = $this->resolvePublicBaseUrlForRAnalysis($request, $tool);
    if (!$this->isUri($baseUrl)) {
      return '';
    }

    return rtrim($baseUrl, '/')
      . '/std/download-file/' . rawurlencode(base64_encode($datasetFilename))
      . '/' . rawurlencode(base64_encode($studyUri))
      . '/da';
  }

  /**
   * Resolve local filesystem path for a study dataset CSV.
   */
  protected function buildDatasetLocalPathForStudy(string $studyUri, string $datasetFilename): string {
    if (!$this->isUri($studyUri) || trim($datasetFilename) === '' || !\Drupal::hasService('file_system')) {
      return '';
    }

    $studyCode = $this->extractStudyCodeFromUri($studyUri);
    if ($studyCode === '') {
      return '';
    }

    $fileSystem = \Drupal::service('file_system');
    $realDir = $fileSystem->realpath('private://std/' . $studyCode . '/da');
    if (!is_string($realDir) || $realDir === '' || !is_dir($realDir)) {
      return '';
    }

    $safeFilename = trim((string) basename($datasetFilename));
    if ($safeFilename === '') {
      return '';
    }

    $csvPath = rtrim($realDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $safeFilename;
    if (!is_file($csvPath) || !is_readable($csvPath)) {
      return '';
    }

    return $csvPath;
  }

  /**
   * Read selected study dataset CSV and encode it for R engine input payload.
   */
  protected function buildEngineInputCsvBase64(string $studyUri, array $arguments): string {
    $selectedDatasetUri = trim((string) ($arguments['selectedDatasetUri'] ?? ($arguments['datasetUri'] ?? '')));
    $selectedDatasetLabel = trim((string) ($arguments['selectedDatasetLabel'] ?? ''));
    $selectedDatasetFilename = trim((string) ($arguments['selectedDatasetFilename'] ?? ''));

    $datasetFilename = $this->resolveExecutionDatasetFilename($studyUri, $selectedDatasetUri, $selectedDatasetLabel, $selectedDatasetFilename);
    if ($datasetFilename === '') {
      return '';
    }

    if (!$this->isUri($studyUri) || !\Drupal::hasService('file_system')) {
      return '';
    }

    $studyCode = $this->extractStudyCodeFromUri($studyUri);
    if ($studyCode === '') {
      return '';
    }

    $safeFilename = trim((string) basename($datasetFilename));
    if ($safeFilename === '') {
      return '';
    }

    $fileSystem = \Drupal::service('file_system');
    $realDir = $fileSystem->realpath('private://std/' . $studyCode . '/da');
    if (!is_string($realDir) || $realDir === '' || !is_dir($realDir)) {
      return '';
    }

    $csvPath = rtrim($realDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $safeFilename;
    if (!is_file($csvPath) || !is_readable($csvPath)) {
      return '';
    }

    $raw = file_get_contents($csvPath);
    if ($raw === FALSE) {
      return '';
    }

    return base64_encode($raw);
  }

  /**
   * Normalize rscriptArgs values and rewrite localhost URLs when needed.
   *
   * @param mixed $rawArgs
   * @return array<int, string>
   */
  protected function normalizeRscriptArgs($rawArgs): array {
    if (is_string($rawArgs)) {
      $rawArgs = trim($rawArgs);
      $rawArgs = ($rawArgs !== '') ? [$rawArgs] : [];
    }

    if (!is_array($rawArgs)) {
      return [];
    }

    $normalized = [];
    foreach ($rawArgs as $arg) {
      if (!is_scalar($arg)) {
        continue;
      }

      $value = trim((string) $arg);
      if ($value === '') {
        continue;
      }

      if ($this->isUri($value)) {
        $value = $this->normalizeContainerReachableUri($value);
      }

      $normalized[] = $value;
    }

    return $normalized;
  }

  /**
   * Ensure execute payload satisfies updated HASCOAPI requirement for rscriptArgs.
   */
  protected function ensureExecuteRscriptArgs(array $arguments, string $studyUri, array $tool, Request $request, array &$issues): array {
    $normalizedArgs = $arguments;

    $selectedDatasetUri = trim((string) ($normalizedArgs['selectedDatasetUri'] ?? ($normalizedArgs['datasetUri'] ?? '')));
    $selectedDatasetLabel = trim((string) ($normalizedArgs['selectedDatasetLabel'] ?? ''));
    $selectedDatasetFilename = trim((string) ($normalizedArgs['selectedDatasetFilename'] ?? ''));

    $currentRscriptArgs = $this->normalizeRscriptArgs($normalizedArgs['rscriptArgs'] ?? []);
    if (!empty($currentRscriptArgs)) {
      $normalizedArgs['rscriptArgs'] = $currentRscriptArgs;
      return $normalizedArgs;
    }

    $datasetFilename = $this->resolveExecutionDatasetFilename($studyUri, $selectedDatasetUri, $selectedDatasetLabel, $selectedDatasetFilename);
    if ($datasetFilename === '') {
      if ($selectedDatasetUri !== '' || $selectedDatasetLabel !== '' || $selectedDatasetFilename !== '') {
        $issues[] = $this->buildValidationIssue(
          'arguments.selectedDatasetUri',
          'selected_dataset_unresolved',
          'Selected dataset could not be resolved to a CSV file for R execution.'
        );
        return $normalizedArgs;
      }

      $issues[] = $this->buildValidationIssue(
        'arguments.rscriptArgs',
        'missing_rscript_args',
        'R execute requires arguments.rscriptArgs and no dataset CSV could be inferred automatically for this study.'
      );
      return $normalizedArgs;
    }

    $datasetLocalPath = $this->buildDatasetLocalPathForStudy($studyUri, $datasetFilename);
    if ($datasetLocalPath !== '') {
      $normalizedArgs['rscriptArgs'] = [$datasetLocalPath];
      return $normalizedArgs;
    }

    $datasetUrl = $this->buildDatasetDownloadUrlForStudy($studyUri, $datasetFilename, $request, $tool);
    if ($datasetUrl === '') {
      $issues[] = $this->buildValidationIssue(
        'arguments.rscriptArgs',
        'unable_to_build_dataset_url',
        'Unable to build dataset download URL for automatic rscriptArgs fallback.'
      );
      return $normalizedArgs;
    }

    $normalizedArgs['rscriptArgs'] = [$datasetUrl];

    return $normalizedArgs;
  }

  /**
   * Build a normalized validation issue payload.
   */
  protected function buildValidationIssue(string $field, string $code, string $message, string $severity = 'error'): array {
    return [
      'field' => $field,
      'code' => $code,
      'message' => $message,
      'severity' => $severity,
    ];
  }

  /**
   * Validate WKF task hierarchy semantics required by WKF-SPEC-V2.
   *
   * Rules enforced:
   * - Exactly one top-level task (no supertask) in the process task collection.
   * - Every non-top task is a direct or indirect descendant of that top task.
   * - Process hasTopTask matches the unique top-level task.
   */
  protected function validateProcessTaskHierarchy(string $processUri): array {
    $issues = [];
    if (!$this->isUri($processUri)) {
      return $issues;
    }

    try {
      $processEntity = $this->hascoClient->getByUri($processUri);
      $tasks = $this->hascoClient->getTasksByProcess($processUri);

      if (!is_array($tasks) || empty($tasks)) {
        $issues[] = $this->buildValidationIssue(
          'tasks',
          'missing_tasks_for_process',
          'Process has no retrievable task collection for hierarchy validation.'
        );
        return $issues;
      }

      $taskUris = [];
      $parentByChild = [];
      $childrenByParent = [];

      foreach ($tasks as $task) {
        if (!is_array($task)) {
          continue;
        }

        $taskUri = trim((string) ($task['uri'] ?? $task['hasURI'] ?? ''));
        if (!$this->isUri($taskUri)) {
          continue;
        }
        $taskUris[$taskUri] = TRUE;

        $supertaskUri = trim((string) ($task['hasSupertaskUri'] ?? $task['supertaskUri'] ?? $task['hasSupertask'] ?? ''));
        if ($this->isUri($supertaskUri)) {
          $parentByChild[$taskUri] = $supertaskUri;
          if (!isset($childrenByParent[$supertaskUri])) {
            $childrenByParent[$supertaskUri] = [];
          }
          $childrenByParent[$supertaskUri][$taskUri] = TRUE;
        }

        $subtaskRefs = $task['hasSubtaskUris'] ?? $task['subtaskUris'] ?? $task['hasSubtask'] ?? [];
        if (is_string($subtaskRefs)) {
          $subtaskRefs = [$subtaskRefs];
        }
        if (is_array($subtaskRefs)) {
          foreach ($subtaskRefs as $subtaskRef) {
            $subtaskUri = '';
            if (is_string($subtaskRef)) {
              $subtaskUri = trim($subtaskRef);
            }
            elseif (is_array($subtaskRef)) {
              $subtaskUri = trim((string) ($subtaskRef['uri'] ?? $subtaskRef['hasURI'] ?? $subtaskRef['taskUri'] ?? ''));
            }
            elseif (is_object($subtaskRef)) {
              $subtaskUri = trim((string) ($subtaskRef->uri ?? $subtaskRef->hasURI ?? $subtaskRef->taskUri ?? ''));
            }

            if ($this->isUri($subtaskUri)) {
              $taskUris[$subtaskUri] = TRUE;
              if (!isset($childrenByParent[$taskUri])) {
                $childrenByParent[$taskUri] = [];
              }
              $childrenByParent[$taskUri][$subtaskUri] = TRUE;
              if (!isset($parentByChild[$subtaskUri])) {
                $parentByChild[$subtaskUri] = $taskUri;
              }
            }
          }
        }
      }

      if (empty($taskUris)) {
        $issues[] = $this->buildValidationIssue(
          'tasks',
          'missing_task_uris',
          'Process task collection did not contain valid task URIs.'
        );
        return $issues;
      }

      $roots = [];
      foreach (array_keys($taskUris) as $taskUri) {
        $parentUri = trim((string) ($parentByChild[$taskUri] ?? ''));
        if ($parentUri === '' || !isset($taskUris[$parentUri])) {
          $roots[] = $taskUri;
        }
      }

      if (count($roots) !== 1) {
        $issues[] = $this->buildValidationIssue(
          'tasks',
          'invalid_top_level_task_count',
          sprintf('Expected exactly 1 top-level task, found %d.', count($roots))
        );
      }

      $topTaskUri = count($roots) === 1 ? $roots[0] : '';

      $processTopTaskUri = '';
      if (is_array($processEntity)) {
        $processTopTaskUri = trim((string) ($processEntity['hasTopTaskUri'] ?? $processEntity['hasTopTask'] ?? ''));
      }
      elseif (is_object($processEntity)) {
        $processTopTaskUri = trim((string) ($processEntity->hasTopTaskUri ?? $processEntity->hasTopTask ?? ''));
      }

      if ($topTaskUri !== '' && $this->isUri($processTopTaskUri) && $processTopTaskUri !== $topTaskUri) {
        $issues[] = $this->buildValidationIssue(
          'processUri',
          'process_top_task_mismatch',
          'Process hasTopTask does not match the unique top-level task in task collection.'
        );
      }

      if ($topTaskUri !== '') {
        $visited = [];
        $stack = [$topTaskUri];
        while (!empty($stack)) {
          $current = array_pop($stack);
          if (isset($visited[$current])) {
            continue;
          }
          $visited[$current] = TRUE;
          $children = array_keys($childrenByParent[$current] ?? []);
          foreach ($children as $childUri) {
            if (!isset($visited[$childUri])) {
              $stack[] = $childUri;
            }
          }
        }

        $orphans = [];
        foreach (array_keys($taskUris) as $taskUri) {
          if (!isset($visited[$taskUri])) {
            $orphans[] = $taskUri;
          }
        }

        if (!empty($orphans)) {
          $issues[] = $this->buildValidationIssue(
            'tasks',
            'task_hierarchy_disconnected',
            'Found tasks that are not direct or indirect descendants of the unique top-level task.'
          );
        }
      }
    }
    catch (\Throwable $e) {
      $issues[] = $this->buildValidationIssue(
        'tasks',
        'task_hierarchy_validation_error',
        'Unable to validate process task hierarchy: ' . $e->getMessage(),
        'warning'
      );
    }

    return $issues;
  }

  /**
   * Resolve current authenticated user email (empty for anonymous/CLI contexts).
   */
  protected function getCurrentUserEmail(): string {
    $account = $this->currentUser();
    if ((string) $account->id() === '0') {
      return '';
    }

    try {
      $user = \Drupal\user\Entity\User::load($account->id());
      if ($user && is_string($user->getEmail())) {
        return trim((string) $user->getEmail());
      }
    }
    catch (\Throwable $ignored) {
      // Keep empty fallback when user lookup fails.
    }

    return '';
  }

  /**
   * Normalize manager-email-like values for statistics refresh filters.
   */
  protected function normalizeManagerEmailForStatistics(string $value): string {
    $email = strtolower(trim($value));
    if ($email === '') {
      return '';
    }

    if (strpos($email, 'mailto:') === 0) {
      $email = substr($email, 7);
    }

    if (preg_match('/(?:^|[?&])manageremail=([^&\s]+)/i', $email, $m)) {
      $email = trim((string) $m[1]);
    }

    $qPos = strpos($email, '?');
    if ($qPos !== FALSE) {
      $email = substr($email, 0, $qPos);
    }

    if (preg_match('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $email, $m)) {
      return strtolower(trim((string) $m[0]));
    }

    return '';
  }

  /**
   * Fire-and-forget refresh for PMSR members statistics by manager email.
   */
  protected function triggerPmsrMembersStatisticsRefreshByManagerEmail(?string $managerEmail): void {
    if (!\Drupal::moduleHandler()->moduleExists('pmsr')) {
      return;
    }

    $normalized = $this->normalizeManagerEmailForStatistics((string) $managerEmail);
    if ($normalized === '') {
      return;
    }

    $baseUrl = \Drupal::request()->getSchemeAndHttpHost();
    $url = rtrim($baseUrl, '/') . '/pmsr/api/statistics/refresh/members?manager_email=' . rawurlencode($normalized);

    try {
      $response = \Drupal::httpClient()->request('POST', $url, [
        'timeout' => 25,
        'connect_timeout' => 2,
        'http_errors' => FALSE,
      ]);

      $status = (int) $response->getStatusCode();
      $body = (string) $response->getBody();
      $decoded = json_decode($body, TRUE);
      $ok = ($status >= 200 && $status < 300) && (!is_array($decoded) || !array_key_exists('success', $decoded) || !empty($decoded['success']));

      // Fallback to full members refresh when scoped refresh fails.
      if (!$ok) {
        $fallbackUrl = rtrim($baseUrl, '/') . '/pmsr/api/statistics/refresh/members';
        \Drupal::httpClient()->request('POST', $fallbackUrl, [
          'timeout' => 30,
          'connect_timeout' => 2,
          'http_errors' => FALSE,
        ]);
      }
    }
    catch (\Throwable $e) {
      \Drupal::logger('ctt')->notice('Could not trigger PMSR members statistics refresh: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Trigger PMSR members statistics refresh for a process-related mutation.
   */
  protected function triggerPmsrMembersStatisticsRefreshForProcess(string $processUri, ?string $ownerHint = NULL): void {
    $ownerEmail = '';
    if ($this->isUri($processUri)) {
      $ownerEmail = $this->resolveProcessOwnerEmail($processUri);
    }

    if ($ownerEmail === '' && is_string($ownerHint) && trim($ownerHint) !== '') {
      $ownerEmail = trim($ownerHint);
    }

    if ($ownerEmail === '') {
      $ownerEmail = $this->getCurrentUserEmail();
    }

    $this->triggerPmsrMembersStatisticsRefreshByManagerEmail($ownerEmail);
  }

  /**
   * Resolve and cache study owner/manager email for ownership checks.
   */
  protected function resolveStudyOwnerEmail(string $studyUri): string {
    $normalizedStudyUri = trim($studyUri);
    if ($normalizedStudyUri === '' || !$this->isUri($normalizedStudyUri)) {
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
        $ownerEmail = $this->normalizeEmailCandidate((string) ($studyObj->hasSIRManagerEmail ?? $studyObj->managerEmail ?? ''));
      }
      elseif (is_array($studyObj)) {
        $ownerEmail = $this->normalizeEmailCandidate((string) ($studyObj['hasSIRManagerEmail'] ?? $studyObj['managerEmail'] ?? ''));
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
   * Build a consistent owner-required response for blocked study mutations.
   */
  protected function buildStudyOwnerRequiredResponse(string $studyUri, string $reasonCode): JsonResponse {
    $message = 'Only the authenticated workflow owner can modify workflow state for this study.';
    if ($reasonCode === 'study_owner_unresolved') {
      $message = 'Study owner could not be resolved. Workflow mutations are blocked for safety.';
    }

    $issue = $this->buildValidationIssue('studyUri', $reasonCode, $message);

    return new JsonResponse([
      'isValid' => FALSE,
      'updated' => FALSE,
      'issues' => [$issue],
      'summary' => [
        'errorCount' => 1,
        'warningCount' => 0,
      ],
      'studyUri' => $studyUri,
    ], 403);
  }

  /**
   * Enforce study owner for mutation operations in study-bound flows.
   */
  protected function enforceStudyOwnerForMutation(string $studyUri): ?JsonResponse {
    $normalizedStudyUri = trim($studyUri);
    if ($normalizedStudyUri === '' || !$this->isUri($normalizedStudyUri)) {
      return NULL;
    }

    // CLI/drush flows (uid 0) can exercise API contracts without user session.
    if ((string) $this->currentUser()->id() === '0') {
      return NULL;
    }

    $ownerEmail = $this->resolveStudyOwnerEmail($normalizedStudyUri);
    if ($ownerEmail === '') {
      return $this->buildStudyOwnerRequiredResponse($normalizedStudyUri, 'study_owner_unresolved');
    }

    $currentUserEmail = $this->getCurrentUserEmail();
    if ($currentUserEmail === '' || strcasecmp($ownerEmail, $currentUserEmail) !== 0) {
      return $this->buildStudyOwnerRequiredResponse($normalizedStudyUri, 'workflow_owner_required');
    }

    return NULL;
  }

  /**
   * Normalize a potential owner identifier value.
   */
  protected function normalizeEmailCandidate(string $value): string {
    $normalized = trim($value);
    if ($normalized === '') {
      return '';
    }

    // Accept values like "Name <user@example.org>".
    if (preg_match('/<([^>]+)>/', $normalized, $matches) && isset($matches[1])) {
      $normalized = trim((string) $matches[1]);
    }

    $lower = strtolower($normalized);
    if (strpos($lower, 'mailto:') === 0) {
      $normalized = trim(substr($normalized, 7));
    }

    // Remove query-string contamination such as
    // "user@example.org?manageremail=user@example.org".
    $queryPos = strpos($normalized, '?');
    if ($queryPos !== FALSE) {
      $normalized = trim(substr($normalized, 0, $queryPos));
    }

    $ampPos = strpos($normalized, '&');
    if ($ampPos !== FALSE) {
      $normalized = trim(substr($normalized, 0, $ampPos));
    }

    return filter_var($normalized, FILTER_VALIDATE_EMAIL) ? $normalized : '';
  }

  /**
   * Check if a resolved owner identifier matches the authenticated user.
   */
  protected function ownerIdentifierMatchesCurrentUser(string $ownerIdentifier): bool {
    $normalizedOwner = strtolower(trim($ownerIdentifier));
    if ($normalizedOwner === '') {
      return FALSE;
    }

    // Site administrators are allowed to maintain workflows regardless of
    // original owner identifier, which prevents save deadlocks in shared
    // editorial environments.
    if ($this->currentUser()->hasPermission('administer site configuration')) {
      return TRUE;
    }

    $candidates = [];

    $currentEmail = $this->getCurrentUserEmail();
    if ($currentEmail !== '') {
      $candidates[] = strtolower(trim($currentEmail));
    }

    $account = $this->currentUser();
    $accountName = trim((string) $account->getAccountName());
    if ($accountName !== '') {
      $candidates[] = strtolower($accountName);
    }

    $displayName = trim((string) $account->getDisplayName());
    if ($displayName !== '') {
      $candidates[] = strtolower($displayName);
    }

    foreach (array_unique($candidates) as $candidate) {
      if ($candidate === $normalizedOwner) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Check if current user has PMSR administration role.
   */
  protected function currentUserHasPmsrAdministrationRole(): bool {
    $account = $this->currentUser();
    if (!method_exists($account, 'getRoles')) {
      return FALSE;
    }

    $roles = $account->getRoles();
    if (!is_array($roles)) {
      return FALSE;
    }

    foreach ($roles as $roleId) {
      $normalizedRole = strtolower(trim((string) $roleId));
      if ($normalizedRole === 'pmsr_administration' || $normalizedRole === 'pmsr_admin') {
        return TRUE;
      }

      try {
        $roleEntity = \Drupal\user\Entity\Role::load((string) $roleId);
        if ($roleEntity) {
          $roleLabel = strtolower(trim((string) $roleEntity->label()));
          if ($roleLabel === 'pmsr administration' || str_contains($roleLabel, 'pmsr admin')) {
            return TRUE;
          }
        }
      }
      catch (\Throwable $ignored) {
        // Keep role-id based checks as fallback.
      }
    }

    return FALSE;
  }

  /**
   * Extract an owner email from process/study/task payloads.
   */
  protected function extractOwnerEmailFromEntity(mixed $entity): string {
    $candidates = [];

    if (is_array($entity)) {
      $candidates = [
        $entity['hasSIRManagerEmail'] ?? NULL,
        $entity['managerEmail'] ?? NULL,
        $entity['managedBy'] ?? NULL,
        $entity['createdBy'] ?? NULL,
      ];
    }
    elseif (is_object($entity)) {
      $candidates = [
        $entity->hasSIRManagerEmail ?? NULL,
        $entity->managerEmail ?? NULL,
        $entity->managedBy ?? NULL,
        $entity->createdBy ?? NULL,
      ];
    }

    foreach ($candidates as $candidate) {
      if (!is_scalar($candidate)) {
        continue;
      }
      $email = $this->normalizeEmailCandidate((string) $candidate);
      if ($email !== '') {
        return $email;
      }
    }

    return '';
  }

  /**
   * Canonical process URI representation for cache keys.
   */
  protected function normalizeProcessUriForCacheKey(string $processUri): string {
    $normalized = trim($processUri);
    if ($normalized === '') {
      return '';
    }

    // Keep process owner cache resilient across '#/X' and '#X' variants.
    return str_replace('#/', '#', $normalized);
  }

  /**
   * Build equivalent URI variants used by different API clients.
   */
  protected function buildProcessUriVariants(string $processUri): array {
    $uri = trim($processUri);
    if ($uri === '' || !$this->isUri($uri)) {
      return [];
    }

    $variants = [$uri];
    if (strpos($uri, '#/') !== FALSE) {
      $variants[] = str_replace('#/', '#', $uri);
    }
    elseif (strpos($uri, '#') !== FALSE) {
      $variants[] = preg_replace('/#(?!\/)/', '#/', $uri, 1) ?? $uri;
    }

    $unique = [];
    foreach ($variants as $candidate) {
      $candidate = trim((string) $candidate);
      if ($candidate !== '' && $this->isUri($candidate)) {
        $unique[$candidate] = TRUE;
      }
    }

    return array_keys($unique);
  }

  /**
   * Resolve a process URI variant that exists in HASCO (best effort).
   */
  protected function resolveExistingProcessUriVariant(string $processUri): string {
    $variants = $this->buildProcessUriVariants($processUri);
    if (empty($variants)) {
      return trim($processUri);
    }

    foreach ($variants as $variantUri) {
      try {
        $processObj = $this->hascoClient->getByUri($variantUri);
        if (is_array($processObj) && empty($processObj['error'])) {
          return $variantUri;
        }
      }
      catch (\Throwable $ignored) {
        // Try next variant.
      }
    }

    return trim($processUri);
  }

  /**
   * State key for cached process owner e-mail.
   */
  protected function getProcessOwnerStateKey(string $processUri): string {
    return 'ctt.process_owner_email.' . sha1($this->normalizeProcessUriForCacheKey($processUri));
  }

  /**
   * State key for cached task-to-process mapping.
   */
  protected function getTaskProcessStateKey(string $taskUri): string {
    return 'ctt.task_process_uri.' . sha1($taskUri);
  }

  /**
   * Cache process ownership metadata and root task mapping when available.
   */
  protected function cacheProcessOwnershipContext(string $processUri, mixed $processEntity): void {
    $normalizedProcessUri = trim($processUri);
    if ($normalizedProcessUri === '' || !$this->isUri($normalizedProcessUri)) {
      return;
    }

    $ownerEmail = $this->extractOwnerEmailFromEntity($processEntity);
    if ($ownerEmail !== '') {
      $this->setCachedProcessOwnerEmail($normalizedProcessUri, $ownerEmail);
    }

    $topTaskUri = '';
    if (is_array($processEntity)) {
      $topTaskUri = trim((string) ($processEntity['hasTopTaskUri'] ?? $processEntity['hasTopTask'] ?? ''));
    }
    elseif (is_object($processEntity)) {
      $topTaskUri = trim((string) ($processEntity->hasTopTaskUri ?? $processEntity->hasTopTask ?? ''));
    }

    if ($this->isUri($topTaskUri)) {
      $this->setCachedTaskProcessUri($topTaskUri, $normalizedProcessUri);
    }
  }

  /**
   * Cache task-to-process mappings from task list payloads.
   */
  protected function cacheTaskProcessMappings(mixed $tasks, string $processUri): void {
    $normalizedProcessUri = trim($processUri);
    if ($normalizedProcessUri === '' || !$this->isUri($normalizedProcessUri) || !is_array($tasks)) {
      return;
    }

    foreach ($tasks as $task) {
      $taskUri = '';
      if (is_array($task)) {
        $taskUri = trim((string) ($task['uri'] ?? $task['hasURI'] ?? ''));
      }
      elseif (is_object($task)) {
        $taskUri = trim((string) ($task->uri ?? $task->hasURI ?? ''));
      }

      if ($this->isUri($taskUri)) {
        $this->setCachedTaskProcessUri($taskUri, $normalizedProcessUri);
      }
    }
  }

  /**
   * Backward-compatible alias for task-to-process cache writes.
   */
  protected function cacheTaskProcessUri(string $taskUri, string $processUri): void {
    $this->setCachedTaskProcessUri($taskUri, $processUri);
  }

  /**
   * Persist process owner e-mail cache when available.
   */
  protected function setCachedProcessOwnerEmail(string $processUri, string $ownerEmail): void {
    $normalizedOwnerEmail = trim($ownerEmail);
    if ($normalizedOwnerEmail === '') {
      return;
    }

    $variants = $this->buildProcessUriVariants($processUri);
    if (empty($variants)) {
      return;
    }

    foreach ($variants as $variantUri) {
      \Drupal::state()->set($this->getProcessOwnerStateKey($variantUri), $normalizedOwnerEmail);
    }
  }

  /**
   * Backward-compatible alias for process owner cache writes.
   */
  protected function cacheProcessOwnerIdentifier(string $processUri, string $ownerIdentifier): void {
    $this->setCachedProcessOwnerEmail($processUri, $ownerIdentifier);
  }

  /**
   * Persist task-to-process mapping cache when available.
   */
  protected function setCachedTaskProcessUri(string $taskUri, string $processUri): void {
    $normalizedTaskUri = trim($taskUri);
    $normalizedProcessUri = trim($processUri);
    if ($normalizedTaskUri === '' || !$this->isUri($normalizedTaskUri) || $normalizedProcessUri === '' || !$this->isUri($normalizedProcessUri)) {
      return;
    }
    \Drupal::state()->set($this->getTaskProcessStateKey($normalizedTaskUri), $normalizedProcessUri);
  }

  /**
   * Extract process URI from known task payload/entity shapes.
   */
  protected function extractProcessUriFromTaskEntity(mixed $taskEntity): string {
    $candidates = [];

    if (is_array($taskEntity)) {
      $candidates = [
        $taskEntity['processUri'] ?? NULL,
        $taskEntity['workflowUri'] ?? NULL,
        $taskEntity['workflowuri'] ?? NULL,
        $taskEntity['hasWorkflowUri'] ?? NULL,
        $taskEntity['hasProcessUri'] ?? NULL,
        $taskEntity['partOfProcessUri'] ?? NULL,
        $taskEntity['partOfProcess'] ?? NULL,
        $taskEntity['partOf'] ?? NULL,
        $taskEntity['hasSIRPartOf'] ?? NULL,
        $taskEntity['hasProcess'] ?? NULL,
        $taskEntity['process'] ?? NULL,
      ];
    }
    elseif (is_object($taskEntity)) {
      $candidates = [
        $taskEntity->processUri ?? NULL,
        $taskEntity->workflowUri ?? NULL,
        $taskEntity->workflowuri ?? NULL,
        $taskEntity->hasWorkflowUri ?? NULL,
        $taskEntity->hasProcessUri ?? NULL,
        $taskEntity->partOfProcessUri ?? NULL,
        $taskEntity->partOfProcess ?? NULL,
        $taskEntity->partOf ?? NULL,
        $taskEntity->hasSIRPartOf ?? NULL,
        $taskEntity->hasProcess ?? NULL,
        $taskEntity->process ?? NULL,
      ];
    }

    foreach ($candidates as $candidate) {
      if (is_string($candidate) && $this->isUri($candidate)) {
        return trim($candidate);
      }

      if (is_array($candidate)) {
        $nestedUri = trim((string) ($candidate['uri'] ?? $candidate['processUri'] ?? ''));
        if ($this->isUri($nestedUri)) {
          return $nestedUri;
        }
      }
      elseif (is_object($candidate)) {
        $nestedUri = trim((string) ($candidate->uri ?? $candidate->processUri ?? ''));
        if ($this->isUri($nestedUri)) {
          return $nestedUri;
        }
      }
    }

    return '';
  }

  /**
   * Extract a canonical HTTP(S) entity URI from mixed API payload shapes.
   */
  protected function extractEntityUri(mixed $entity): string {
    $candidates = [];

    if (is_array($entity)) {
      $candidates = [
        $entity['uri'] ?? NULL,
        $entity['hasURI'] ?? NULL,
        $entity['id'] ?? NULL,
      ];
    }
    elseif (is_object($entity)) {
      $candidates = [
        $entity->uri ?? NULL,
        $entity->hasURI ?? NULL,
        $entity->id ?? NULL,
      ];
    }

    foreach ($candidates as $candidate) {
      if (!is_scalar($candidate)) {
        continue;
      }

      $uri = trim((string) $candidate);
      if ($this->isUri($uri)) {
        return $uri;
      }
    }

    return '';
  }

  /**
   * Decode URI values received through route placeholders.
   */
  protected function decodeRouteEntityUri(string $value): string {
    $uri = trim($value);
    if ($uri === '') {
      return '';
    }

    if (strpos($uri, '%') !== FALSE) {
      $decoded = rawurldecode($uri);
      if (is_string($decoded) && trim($decoded) !== '') {
        $uri = trim($decoded);
      }
    }

    return $uri;
  }

  /**
   * Determine whether a payload field carries a meaningful value.
   */
  protected function isFilledPayloadValue(mixed $value): bool {
    if ($value === NULL) {
      return FALSE;
    }

    if (is_string($value)) {
      return trim($value) !== '';
    }

    if (is_array($value)) {
      foreach ($value as $item) {
        if ($this->isFilledPayloadValue($item)) {
          return TRUE;
        }
      }
      return FALSE;
    }

    if (is_object($value)) {
      return $this->isFilledPayloadValue((array) $value);
    }

    return TRUE;
  }

  /**
   * Read the first matching value from an entity map/object.
   */
  protected function extractEntityFieldValue(mixed $entity, array $fieldCandidates): mixed {
    if (is_object($entity)) {
      $entity = (array) $entity;
    }

    if (!is_array($entity)) {
      return NULL;
    }

    foreach ($fieldCandidates as $fieldName) {
      if (!is_string($fieldName) || $fieldName === '') {
        continue;
      }

      if (!array_key_exists($fieldName, $entity)) {
        continue;
      }

      $value = $entity[$fieldName];
      if ($this->isFilledPayloadValue($value)) {
        return $value;
      }
    }

    return NULL;
  }

  /**
   * Fill missing payload fields from a pre-existing entity snapshot.
   */
  protected function applyEntityFieldFallbacks(array $payload, mixed $existingEntity, array $fallbackMap): array {
    if (is_object($existingEntity)) {
      $existingEntity = (array) $existingEntity;
    }

    if (!is_array($existingEntity) || empty($fallbackMap)) {
      return $payload;
    }

    foreach ($fallbackMap as $targetField => $candidates) {
      if (!is_string($targetField) || $targetField === '') {
        continue;
      }

      if (!is_array($candidates) || empty($candidates)) {
        continue;
      }

      if (array_key_exists($targetField, $payload) && $this->isFilledPayloadValue($payload[$targetField])) {
        continue;
      }

      $resolved = $this->extractEntityFieldValue($existingEntity, $candidates);
      if (!$this->isFilledPayloadValue($resolved)) {
        continue;
      }

      if (is_object($resolved)) {
        $resolved = (array) $resolved;
      }

      $payload[$targetField] = $resolved;
    }

    return $payload;
  }

  /**
   * Parse URI lists from mixed payload values.
   */
  protected function extractUriListFromMixedValue(mixed $value): array {
    if (is_string($value)) {
      $split = preg_split('/[;,|]+/', $value);
      if (!is_array($split)) {
        $split = [$value];
      }

      $result = [];
      foreach ($split as $piece) {
        $candidate = trim((string) $piece);
        if ($candidate !== '') {
          $result[] = $candidate;
        }
      }

      return array_values(array_unique($result));
    }

    if (is_object($value)) {
      $value = (array) $value;
    }

    if (!is_array($value)) {
      return [];
    }

    $resolved = [];
    foreach ($value as $item) {
      if (is_string($item)) {
        $candidate = trim($item);
        if ($candidate !== '') {
          $resolved[] = $candidate;
        }
        continue;
      }

      if (is_object($item)) {
        $item = (array) $item;
      }

      if (!is_array($item)) {
        continue;
      }

      $directUri = trim((string) ($item['uri'] ?? $item['hasURI'] ?? $item['taskUri'] ?? $item['hasTaskUri'] ?? ''));
      if ($directUri !== '') {
        $resolved[] = $directUri;
      }

      foreach ($this->extractUriListFromMixedValue($item) as $nestedUri) {
        $resolved[] = $nestedUri;
      }
    }

    return array_values(array_unique(array_filter(array_map(function ($entry) {
      return trim((string) $entry);
    }, $resolved), function ($entry) {
      return $entry !== '';
    })));
  }

  /**
   * Normalize user-facing temporal operator values to VSTOI URIs.
   */
  protected function normalizeTemporalDependencyOperator(string $operator): string {
    $raw = trim($operator);
    if ($raw === '') {
      return '';
    }

    if ($this->isUri($raw)) {
      return $raw;
    }

    $compacted = strtolower((string) preg_replace('/[^a-z]/', '', $raw));
    $map = [
      'enabling' => 'EnablingOperator',
      'enablinginformation' => 'EnablingInformationOperator',
      'enablinginformationexchange' => 'EnablingInformationOperator',
      'choice' => 'ChoiceOperator',
      'parallel' => 'ConcurrencyOperator',
      'concurrency' => 'ConcurrencyOperator',
      'independent' => 'OrderIndependencyOperator',
      'orderindependent' => 'OrderIndependencyOperator',
      'orderindependency' => 'OrderIndependencyOperator',
      'interrupts' => 'SuspendResumeOperator',
      'interruption' => 'SuspendResumeOperator',
      'suspendresume' => 'SuspendResumeOperator',
      'iteration' => 'IterationOperator',
      'loop' => 'IterationOperator',
    ];

    if (isset($map[$compacted])) {
      return 'http://hadatac.org/ont/vstoi#' . $map[$compacted];
    }

    if (str_ends_with($raw, 'Operator')) {
      return 'http://hadatac.org/ont/vstoi#' . ltrim($raw, '#/');
    }

    if (preg_match('/^[A-Za-z][A-Za-z0-9]+$/', $raw) === 1) {
      return 'http://hadatac.org/ont/vstoi#' . $raw . 'Operator';
    }

    return $raw;
  }

  /**
   * Infer process URI from request query/referrer context (best effort).
   */
  protected function extractProcessUriFromRequestContext(Request $request): string {
    $candidates = [
      (string) $request->query->get('processUri', ''),
      (string) $request->query->get('workflowUri', ''),
      (string) $request->query->get('workflowuri', ''),
    ];

    $referer = trim((string) $request->headers->get('referer', ''));
    if ($referer !== '') {
      $parts = parse_url($referer);
      if (is_array($parts) && isset($parts['query'])) {
        $query = [];
        parse_str((string) $parts['query'], $query);
        foreach (['processUri', 'workflowUri', 'workflowuri'] as $key) {
          if (isset($query[$key]) && !is_array($query[$key])) {
            $candidates[] = (string) $query[$key];
          }
        }
      }
    }

    foreach ($candidates as $candidate) {
      $value = trim((string) $candidate);
      if ($value === '') {
        continue;
      }

      if (strpos($value, '%') !== FALSE) {
        $decoded = rawurldecode($value);
        if (is_string($decoded) && trim($decoded) !== '') {
          $value = trim($decoded);
        }
      }

      if ($this->isUri($value)) {
        return $this->resolveExistingProcessUriVariant($value);
      }
    }

    return '';
  }

  /**
   * Resolve and cache process owner e-mail.
   */
  protected function resolveProcessOwnerEmail(string $processUri): string {
    $normalizedProcessUri = trim($processUri);
    if ($normalizedProcessUri === '' || !$this->isUri($normalizedProcessUri)) {
      return '';
    }

    $variants = $this->buildProcessUriVariants($normalizedProcessUri);
    if (empty($variants)) {
      return '';
    }

    foreach ($variants as $variantUri) {
      $ownerStateKey = $this->getProcessOwnerStateKey($variantUri);
      $cachedOwner = \Drupal::state()->get($ownerStateKey);
      if (is_string($cachedOwner) && trim($cachedOwner) !== '') {
        return trim($cachedOwner);
      }
    }

    foreach ($variants as $variantUri) {
      try {
        $processObj = $this->hascoClient->getByUri($variantUri);
        $ownerEmail = $this->extractOwnerEmailFromEntity($processObj);
        if ($ownerEmail !== '') {
          $this->setCachedProcessOwnerEmail($variantUri, $ownerEmail);
          return $ownerEmail;
        }
      }
      catch (\Throwable $ignored) {
        // Try alternate URI variants.
      }
    }

    return '';
  }

  /**
   * Build a consistent process-owner-required response.
   */
  protected function buildProcessOwnerRequiredResponse(string $processUri, string $reasonCode): JsonResponse {
    $message = 'Only the authenticated workflow owner can modify this workflow process.';
    if ($reasonCode === 'process_owner_unresolved') {
      $message = 'Process owner could not be resolved. Workflow mutations are blocked for safety.';
    }

    $issue = $this->buildValidationIssue('processUri', $reasonCode, $message);

    return new JsonResponse([
      'isValid' => FALSE,
      'updated' => FALSE,
      'issues' => [$issue],
      'summary' => [
        'errorCount' => 1,
        'warningCount' => 0,
      ],
      'processUri' => $processUri,
    ], 403);
  }

  /**
   * Enforce process owner for process-bound mutation operations.
   */
  protected function enforceProcessOwnerForMutation(string $processUri): ?JsonResponse {
    $normalizedProcessUri = trim($processUri);
    if ($normalizedProcessUri === '' || !$this->isUri($normalizedProcessUri)) {
      return $this->buildProcessOwnerRequiredResponse($normalizedProcessUri, 'process_owner_unresolved');
    }

    // CLI/drush flows (uid 0) can exercise API contracts without user session.
    if ((string) $this->currentUser()->id() === '0') {
      return NULL;
    }

    $ownerIdentifier = $this->resolveProcessOwnerEmail($normalizedProcessUri);
    if ($ownerIdentifier === '') {
      $fallbackOwner = $this->getCurrentUserEmail();
      $canBootstrapOwner = $this->currentUser()->hasPermission('create ctt workflow')
        || $this->currentUser()->hasPermission('edit ctt workflow')
        || $this->currentUser()->hasPermission('administer ctt')
        || $this->currentUser()->hasPermission('administer site configuration');

      if ($fallbackOwner !== '' && $canBootstrapOwner) {
        $this->setCachedProcessOwnerEmail($normalizedProcessUri, $fallbackOwner);
        $ownerIdentifier = $fallbackOwner;
      }
      else {
        return $this->buildProcessOwnerRequiredResponse($normalizedProcessUri, 'process_owner_unresolved');
      }
    }

    if (!$this->ownerIdentifierMatchesCurrentUser($ownerIdentifier)) {
      return $this->buildProcessOwnerRequiredResponse($normalizedProcessUri, 'workflow_owner_required');
    }

    return NULL;
  }

  /**
   * Resolve process URI from a task URI (directly or through task ancestry).
   */
  protected function resolveTaskProcessUri(string $taskUri, array $visitedTaskUris = []): string {
    $normalizedTaskUri = trim($taskUri);
    if ($normalizedTaskUri === '' || !$this->isUri($normalizedTaskUri)) {
      return '';
    }

    if (in_array($normalizedTaskUri, $visitedTaskUris, TRUE)) {
      return '';
    }

    $taskProcessStateKey = $this->getTaskProcessStateKey($normalizedTaskUri);
    $cachedProcessUri = \Drupal::state()->get($taskProcessStateKey);
    if (is_string($cachedProcessUri) && $this->isUri(trim($cachedProcessUri))) {
      return trim($cachedProcessUri);
    }

    try {
      $taskObj = $this->hascoClient->getByUri($normalizedTaskUri);
    }
    catch (\Throwable $ignored) {
      return '';
    }

    $processUri = $this->extractProcessUriFromTaskEntity($taskObj);
    if ($processUri !== '') {
      $this->setCachedTaskProcessUri($normalizedTaskUri, $processUri);
      return $processUri;
    }

    $parentTaskUri = '';
    if (is_array($taskObj)) {
      $parentTaskUri = trim((string) ($taskObj['hasSupertaskUri'] ?? $taskObj['supertaskUri'] ?? ''));
    }
    elseif (is_object($taskObj)) {
      $parentTaskUri = trim((string) ($taskObj->hasSupertaskUri ?? $taskObj->supertaskUri ?? ''));
    }

    if (!$this->isUri($parentTaskUri)) {
      return '';
    }

    $visitedTaskUris[] = $normalizedTaskUri;
    $resolvedFromParent = $this->resolveTaskProcessUri($parentTaskUri, $visitedTaskUris);
    if ($resolvedFromParent !== '') {
      $this->setCachedTaskProcessUri($normalizedTaskUri, $resolvedFromParent);
    }

    return $resolvedFromParent;
  }

  /**
   * Build a consistent task-owner-required response.
   */
  protected function buildTaskOwnerRequiredResponse(string $taskUri, string $reasonCode): JsonResponse {
    $message = 'Only the authenticated workflow owner can modify this task.';
    if ($reasonCode === 'task_process_unresolved') {
      $message = 'Task process context could not be resolved. Workflow mutations are blocked for safety.';
    }

    $issue = $this->buildValidationIssue('taskUri', $reasonCode, $message);

    return new JsonResponse([
      'isValid' => FALSE,
      'updated' => FALSE,
      'issues' => [$issue],
      'summary' => [
        'errorCount' => 1,
        'warningCount' => 0,
      ],
      'taskUri' => $taskUri,
    ], 403);
  }

  /**
   * Enforce process owner for task mutation operations.
   */
  protected function enforceTaskOwnerForMutation(string $taskUri): ?JsonResponse {
    $normalizedTaskUri = trim($taskUri);
    if ($normalizedTaskUri === '' || !$this->isUri($normalizedTaskUri)) {
      return $this->buildTaskOwnerRequiredResponse($normalizedTaskUri, 'task_process_unresolved');
    }

    if ((string) $this->currentUser()->id() === '0') {
      return NULL;
    }

    $processUri = $this->resolveTaskProcessUri($normalizedTaskUri);
    if ($processUri === '') {
      return $this->buildTaskOwnerRequiredResponse($normalizedTaskUri, 'task_process_unresolved');
    }

    return $this->enforceProcessOwnerForMutation($processUri);
  }

  /**
   * State key for persisted study-level submission associations.
   */
  protected function getStudyAssociationsKey(string $studyUri): string {
    return 'ctt.study_associations.' . sha1($studyUri);
  }

  /**
   * Canonical empty associations payload.
   */
  protected function getEmptyAssociations(): array {
    return [
      'datasets' => [],
      'variables' => [],
      'images' => [],
      'counts' => [
        'datasets' => 0,
        'variables' => 0,
        'images' => 0,
      ],
    ];
  }

  /**
   * Extract raw association inputs from payload/query.
   *
   * Accepted forms:
   *  - associations: { datasets, variables, images }
   *  - datasetUris, variableUris, medicalImageFiles
   */
  protected function extractAssociationInput(array $payload, Request $request): array {
    $query = $request->query->all();
    $associationsPayload = (isset($payload['associations']) && is_array($payload['associations']))
      ? $payload['associations']
      : [];

    $datasetsRaw = NULL;
    if (array_key_exists('datasets', $associationsPayload)) {
      $datasetsRaw = $associationsPayload['datasets'];
    }
    elseif (array_key_exists('datasetUris', $payload)) {
      $datasetsRaw = $payload['datasetUris'];
    }
    elseif (array_key_exists('datasetUris', $query)) {
      $datasetsRaw = $query['datasetUris'];
    }
    elseif (array_key_exists('datasets', $query)) {
      $datasetsRaw = $query['datasets'];
    }

    $variablesRaw = NULL;
    if (array_key_exists('variables', $associationsPayload)) {
      $variablesRaw = $associationsPayload['variables'];
    }
    elseif (array_key_exists('variableUris', $payload)) {
      $variablesRaw = $payload['variableUris'];
    }
    elseif (array_key_exists('variableUris', $query)) {
      $variablesRaw = $query['variableUris'];
    }
    elseif (array_key_exists('variables', $query)) {
      $variablesRaw = $query['variables'];
    }

    $imagesRaw = NULL;
    if (array_key_exists('images', $associationsPayload)) {
      $imagesRaw = $associationsPayload['images'];
    }
    elseif (array_key_exists('medicalImageFiles', $payload)) {
      $imagesRaw = $payload['medicalImageFiles'];
    }
    elseif (array_key_exists('medicalImageFiles', $query)) {
      $imagesRaw = $query['medicalImageFiles'];
    }
    elseif (array_key_exists('images', $query)) {
      $imagesRaw = $query['images'];
    }

    return [
      'provided' => ($datasetsRaw !== NULL || $variablesRaw !== NULL || $imagesRaw !== NULL),
      'datasetsRaw' => $datasetsRaw,
      'variablesRaw' => $variablesRaw,
      'imagesRaw' => $imagesRaw,
    ];
  }

  /**
   * Normalize one association collection and collect validation issues.
   *
   * @param mixed $rawCollection
   * @param string $kind dataset|variable|image
   * @return array{entries: array<int, array<string, string>>, issues: array<int, array<string, string>>}
   */
  protected function normalizeAssociationCollection($rawCollection, string $kind): array {
    $entries = [];
    $issues = [];
    $seen = [];

    if ($rawCollection === NULL) {
      $rawCollection = [];
    }

    if (is_string($rawCollection)) {
      $rawCollection = array_values(array_filter(array_map('trim', explode(',', $rawCollection)), function ($value) {
        return $value !== '';
      }));
    }

    if (!is_array($rawCollection)) {
      $field = $kind === 'dataset' ? 'associations.datasets' : ($kind === 'variable' ? 'associations.variables' : 'associations.images');
      $issues[] = $this->buildValidationIssue($field, 'invalid_' . $kind . '_collection', 'Association collection must be an array or comma-separated list.');
      return [
        'entries' => [],
        'issues' => $issues,
      ];
    }

    foreach ($rawCollection as $item) {
      if ($kind === 'image') {
        $filename = '';
        $label = '';

        if (is_string($item)) {
          $filename = trim($item);
        }
        elseif (is_array($item)) {
          $filename = trim((string) ($item['filename'] ?? $item['name'] ?? ''));
          $label = trim((string) ($item['label'] ?? ''));
        }
        else {
          $issues[] = $this->buildValidationIssue('associations.images', 'invalid_image_entry', 'Each image association must be a filename string or object.');
          continue;
        }

        if ($filename === '') {
          continue;
        }

        if ($filename !== basename($filename) || preg_match('/[\\\\\/]/', $filename)) {
          $issues[] = $this->buildValidationIssue('associations.images', 'invalid_image_filename', 'Image association filenames must not include path separators.');
          continue;
        }

        $dedupeKey = strtolower($filename);
        if (isset($seen[$dedupeKey])) {
          continue;
        }
        $seen[$dedupeKey] = TRUE;

        $entry = ['filename' => $filename];
        if ($label !== '') {
          $entry['label'] = $label;
        }
        $entries[] = $entry;
        continue;
      }

      $uri = '';
      $label = '';

      if (is_string($item)) {
        $uri = trim($item);
      }
      elseif (is_array($item)) {
        $uri = trim((string) ($item['uri'] ?? ''));
        $label = trim((string) ($item['label'] ?? ''));
      }
      else {
        $field = $kind === 'dataset' ? 'associations.datasets' : 'associations.variables';
        $issues[] = $this->buildValidationIssue($field, 'invalid_' . $kind . '_entry', 'Each association must be a URI string or object with uri.');
        continue;
      }

      if ($uri === '') {
        continue;
      }

      if (!$this->isUri($uri)) {
        $field = $kind === 'dataset' ? 'associations.datasets' : 'associations.variables';
        $issues[] = $this->buildValidationIssue($field, 'invalid_' . $kind . '_uri', 'Association URI must be a valid HTTP(S) URI.');
        continue;
      }

      $dedupeKey = strtolower($uri);
      if (isset($seen[$dedupeKey])) {
        continue;
      }
      $seen[$dedupeKey] = TRUE;

      $entry = ['uri' => $uri];
      if ($label !== '') {
        $entry['label'] = $label;
      }
      $entries[] = $entry;
    }

    return [
      'entries' => $entries,
      'issues' => $issues,
    ];
  }

  /**
   * Normalize full association payload and aggregate issues.
   *
   * @param mixed $datasetsRaw
   * @param mixed $variablesRaw
   * @param mixed $imagesRaw
   * @return array{associations: array<string, mixed>, issues: array<int, array<string, string>>}
   */
  protected function normalizeAssociationPayload($datasetsRaw, $variablesRaw, $imagesRaw): array {
    $datasets = $this->normalizeAssociationCollection($datasetsRaw, 'dataset');
    $variables = $this->normalizeAssociationCollection($variablesRaw, 'variable');
    $images = $this->normalizeAssociationCollection($imagesRaw, 'image');

    $associations = [
      'datasets' => $datasets['entries'],
      'variables' => $variables['entries'],
      'images' => $images['entries'],
      'counts' => [
        'datasets' => count($datasets['entries']),
        'variables' => count($variables['entries']),
        'images' => count($images['entries']),
      ],
    ];

    return [
      'associations' => $associations,
      'issues' => array_merge($datasets['issues'], $variables['issues'], $images['issues']),
    ];
  }

  /**
   * Load persisted associations for a study.
   */
  protected function loadStudyAssociations(string $studyUri): array {
    $raw = \Drupal::state()->get($this->getStudyAssociationsKey($studyUri));
    if (!is_array($raw)) {
      return $this->getEmptyAssociations();
    }

    $normalized = $this->normalizeAssociationPayload(
      $raw['datasets'] ?? [],
      $raw['variables'] ?? [],
      $raw['images'] ?? []
    );

    return $normalized['associations'];
  }

  /**
   * Persist normalized associations for a study.
   */
  protected function saveStudyAssociations(string $studyUri, array $associations, ?string $processUri = NULL): array {
    $record = [
      'datasets' => $associations['datasets'] ?? [],
      'variables' => $associations['variables'] ?? [],
      'images' => $associations['images'] ?? [],
      'counts' => $associations['counts'] ?? [
        'datasets' => 0,
        'variables' => 0,
        'images' => 0,
      ],
      'updatedAt' => gmdate('c'),
    ];

    $account = $this->currentUser();
    $record['updatedByUid'] = (string) $account->id();
    $record['updatedBy'] = (string) $account->getDisplayName();

    try {
      $user = \Drupal\user\Entity\User::load($account->id());
      if ($user && is_string($user->getEmail()) && trim($user->getEmail()) !== '') {
        $record['updatedBy'] = trim((string) $user->getEmail());
      }
    }
    catch (\Throwable $ignored) {
      // Keep fallback display name when user lookup fails.
    }

    if (is_string($processUri) && $this->isUri($processUri)) {
      $record['processUri'] = $processUri;
    }

    \Drupal::state()->set($this->getStudyAssociationsKey($studyUri), $record);
    return $record;
  }

  /**
   * Remove one issue code from the issue list.
   *
   * @param array<int, array<string, mixed>> $issues
   * @return array<int, array<string, mixed>>
   */
  protected function removeIssueByCode(array $issues, string $code): array {
    if ($code === '') {
      return $issues;
    }

    return array_values(array_filter($issues, function ($issue) use ($code) {
      if (!is_array($issue)) {
        return TRUE;
      }
      return trim((string) ($issue['code'] ?? '')) !== $code;
    }));
  }

  /**
   * Extract output file paths reported by upstream execution logs.
   *
   * @param mixed $upstream
   * @return array<int, string>
   */
  protected function extractGeneratedOutputPathsFromUpstreamPayload($upstream): array {
    if (!is_array($upstream)) {
      return [];
    }

    $sources = [];

    $logs = $upstream['body']['logs'] ?? [];
    if (is_array($logs)) {
      foreach ($logs as $entry) {
        if (is_string($entry) && trim($entry) !== '') {
          $sources[] = $entry;
        }
      }
    }

    $engineStdout = $upstream['body']['engine']['stdout'] ?? [];
    if (is_array($engineStdout)) {
      foreach ($engineStdout as $entry) {
        if (is_string($entry) && trim($entry) !== '') {
          $sources[] = $entry;
        }
      }
    }

    $engineStderr = $upstream['body']['engine']['stderr'] ?? [];
    if (is_array($engineStderr)) {
      foreach ($engineStderr as $entry) {
        if (is_string($entry) && trim($entry) !== '') {
          $sources[] = $entry;
        }
      }
    }

    $paths = [];
    $seen = [];
    foreach ($sources as $sourceText) {
      if (!preg_match_all("#(/Users/Shared/drupal_private/std/[^\\s\"'<>]+)#", $sourceText, $matches) || empty($matches[1])) {
        continue;
      }

      foreach ($matches[1] as $rawPath) {
        $normalized = rtrim(trim((string) $rawPath), ".,;:)]}");
        if ($normalized === '' || isset($seen[$normalized])) {
          continue;
        }

        $seen[$normalized] = TRUE;
        $paths[] = $normalized;
      }
    }

    return $paths;
  }

  /**
   * Discover recently generated study DA outputs by file modification time.
   *
   * @return array<int, string>
   */
  protected function discoverRecentStudyDaOutputPaths(string $studyUri, string $startedAtIso): array {
    $studyCode = $this->extractStudyCodeFromUri($studyUri);
    if ($studyCode === '' || !\Drupal::hasService('file_system')) {
      return [];
    }

    $startTs = strtotime($startedAtIso);
    if (!is_int($startTs) || $startTs <= 0) {
      $startTs = time() - 120;
    }

    $windowStart = $startTs - 3;
    $windowEnd = time() + 3;

    $fileSystem = \Drupal::service('file_system');
    $daDirReal = $fileSystem->realpath('private://std/' . $studyCode . '/da/');
    if (!$daDirReal || !is_dir($daDirReal)) {
      return [];
    }

    $entries = scandir($daDirReal) ?: [];
    $paths = [];
    foreach ($entries as $entry) {
      $filename = trim((string) $entry);
      if ($filename === '' || $filename === '.' || $filename === '..') {
        continue;
      }

      $fullPath = rtrim($daDirReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
      if (!is_file($fullPath)) {
        continue;
      }

      $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
      if (!in_array($extension, ['csv', 'xlsx', 'png'], TRUE)) {
        continue;
      }

      $mtime = filemtime($fullPath);
      if (!is_int($mtime) || $mtime < $windowStart || $mtime > $windowEnd) {
        continue;
      }

      $paths[] = $fullPath;
    }

    return array_values(array_unique($paths));
  }

  /**
   * Build compact timestamp tag for output filenames.
   */
  protected function buildRunTimestampTag(string $startedAtIso): string {
    $ts = strtotime($startedAtIso);
    if (!is_int($ts) || $ts <= 0) {
      $ts = time();
    }
    return gmdate('Ymd-His', $ts);
  }

  /**
   * Append timestamp suffix before extension.
   */
  protected function appendTimestampToFilename(string $filename, string $timestampTag): string {
    $normalized = trim((string) basename($filename));
    if ($normalized === '' || $timestampTag === '') {
      return $normalized;
    }

    $ext = (string) pathinfo($normalized, PATHINFO_EXTENSION);
    $base = (string) pathinfo($normalized, PATHINFO_FILENAME);
    if ($ext === '') {
      return $base . '-' . $timestampTag;
    }

    return $base . '-' . $timestampTag . '.' . $ext;
  }

  /**
   * Move generated image outputs into Scenario Media panel and keep data files in DA.
   *
   * @param array<int, string> $paths
   * @return array<string, mixed>
   */
  protected function routeGeneratedOutputFilesToScenarioContent(string $studyUri, array $paths, string $startedAtIso = ''): array {
    $summary = [
      'movedMedia' => [],
      'keptDataFiles' => [],
      'skipped' => [],
      'errors' => [],
    ];

    $studyCode = $this->extractStudyCodeFromUri($studyUri);
    if ($studyCode === '' || !\Drupal::hasService('file_system')) {
      return $summary;
    }

    $fileSystem = \Drupal::service('file_system');
    $mediaDirUri = 'private://std/' . $studyCode . '/media/';
    if (!$fileSystem->prepareDirectory($mediaDirUri, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY)) {
      $summary['errors'][] = 'Unable to prepare Scenario media directory.';
      return $summary;
    }

    $timestampTag = $this->buildRunTimestampTag($startedAtIso);

    foreach ($paths as $path) {
      $filename = basename((string) $path);
      if ($filename === '' || $filename === '.' || $filename === '..') {
        continue;
      }

      $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
      $sourceUri = 'private://std/' . $studyCode . '/da/' . $filename;
      $sourceRealPath = $fileSystem->realpath($sourceUri);
      if (!$sourceRealPath || !is_file($sourceRealPath)) {
        $summary['skipped'][] = [
          'filename' => $filename,
          'reason' => 'source_not_found_in_da',
        ];
        continue;
      }

      if (in_array($extension, ['csv', 'xlsx'], TRUE)) {
        $targetFilename = $this->appendTimestampToFilename($filename, $timestampTag);
        $targetUri = 'private://std/' . $studyCode . '/da/' . $targetFilename;
        $movedUri = $fileSystem->move($sourceUri, $targetUri, \Drupal\Core\File\FileSystemInterface::EXISTS_RENAME);
        if (!$movedUri) {
          $summary['errors'][] = 'Unable to timestamp generated data file: ' . $filename;
          continue;
        }

        $summary['keptDataFiles'][] = basename((string) $movedUri);
        continue;
      }

      if (!in_array($extension, ['png'], TRUE)) {
        $summary['skipped'][] = [
          'filename' => $filename,
          'reason' => 'unsupported_extension',
        ];
        continue;
      }

      $targetFilename = $this->appendTimestampToFilename($filename, $timestampTag);
      $targetUri = $mediaDirUri . $targetFilename;
      $movedUri = $fileSystem->move($sourceUri, $targetUri, \Drupal\Core\File\FileSystemInterface::EXISTS_RENAME);
      if (!$movedUri) {
        $summary['errors'][] = 'Unable to move generated image to media: ' . $filename;
        continue;
      }

      $summary['movedMedia'][] = [
        'filename' => basename((string) $movedUri),
        'from' => 'da',
        'to' => 'media',
      ];
    }

    $summary['keptDataFiles'] = array_values(array_unique($summary['keptDataFiles']));
    return $summary;
  }

  /**
   * Persist generated CSV files as Scenario dataset associations.
   *
   * @param array<int, string> $csvFilenames
   * @return array<string, int>
   */
  protected function persistGeneratedDatasetAssociations(string $studyUri, array $csvFilenames, Request $request): array {
    $associations = $this->loadStudyAssociations($studyUri);
    $datasets = is_array($associations['datasets'] ?? NULL) ? $associations['datasets'] : [];
    $seenUris = [];
    $added = 0;

    foreach ($datasets as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      $uri = trim((string) ($entry['uri'] ?? ''));
      if ($uri !== '') {
        $seenUris[strtolower($uri)] = TRUE;
      }
    }

    foreach ($csvFilenames as $filename) {
      $normalizedFilename = trim((string) basename((string) $filename));
      if ($normalizedFilename === '') {
        continue;
      }

      $downloadUrl = $this->buildDatasetDownloadUrlForStudy($studyUri, $normalizedFilename, $request, []);
      if (!$this->isUri($downloadUrl)) {
        continue;
      }

      $key = strtolower($downloadUrl);
      if (isset($seenUris[$key])) {
        continue;
      }

      $seenUris[$key] = TRUE;
      $datasets[] = [
        'uri' => $downloadUrl,
        'label' => $normalizedFilename,
      ];
      $added++;
    }

    $associations['datasets'] = $datasets;
    $associations['counts']['datasets'] = count($datasets);
    $this->saveStudyAssociations($studyUri, $associations);

    return [
      'addedDatasets' => $added,
      'totalDatasets' => count($datasets),
    ];
  }

  /**
   * Build normalized created files list for UI consumption.
   *
   * @param array<string, mixed> $outputRouting
   * @return array<int, array<string, string>>
   */
  protected function buildCreatedFilesFromOutputRouting(array $outputRouting): array {
    $created = [];

    $movedMedia = is_array($outputRouting['movedMedia'] ?? NULL)
      ? $outputRouting['movedMedia']
      : [];
    foreach ($movedMedia as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      $filename = trim((string) ($entry['filename'] ?? ''));
      if ($filename === '') {
        continue;
      }

      $created[] = [
        'filename' => $filename,
        'fileType' => 'png',
        'contentsPanel' => 'Media',
        'contentsPath' => 'Contents > Media',
      ];
    }

    $keptDataFiles = is_array($outputRouting['keptDataFiles'] ?? NULL)
      ? $outputRouting['keptDataFiles']
      : [];
    foreach ($keptDataFiles as $entry) {
      $filename = trim((string) $entry);
      if ($filename === '') {
        continue;
      }

      $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
      $created[] = [
        'filename' => $filename,
        'fileType' => $extension !== '' ? $extension : 'data',
        'contentsPanel' => 'Data Files',
        'contentsPath' => 'Contents > Unassociated Data Files',
      ];
    }

    usort($created, function (array $a, array $b): int {
      return strcmp((string) ($a['filename'] ?? ''), (string) ($b['filename'] ?? ''));
    });

    return $created;
  }

  /**
   * State key for the analytical tools repository catalog.
   */
  protected function getAnalyticalToolsCatalogKey(): string {
    return 'ctt.analytical_tools.catalog.v1';
  }

  /**
   * State key for analytical tools linked to one study.
   */
  protected function getStudyToolsKey(string $studyUri): string {
    return 'ctt.study_tools.' . sha1($studyUri);
  }

  /**
   * Canonical statuses for analytical tools lifecycle.
   */
  protected function getAnalyticalToolStatuses(): array {
    return ['draft', 'current', 'deprecated', 'validated', 'published'];
  }

  /**
   * Determine whether a process URI value is the global wildcard.
   */
  protected function isGlobalProcessWildcard(string $value): bool {
    $normalized = trim($value);
    return $normalized === '*' || strcasecmp($normalized, self::ANY_PROCESS_URI) === 0;
  }

  /**
   * Allowed script artifact extensions for metadata validation.
   *
   * @return array<int, string>
   */
  protected function getAllowedToolArtifactExtensions(): array {
    return ['r', 'rmd', 'py', 'ipynb', 'sql', 'sh'];
  }

  /**
   * Validate YYYY-MM-DD dates.
   */
  protected function isIsoDate(string $value): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
      return FALSE;
    }

    [$year, $month, $day] = array_map('intval', explode('-', $value));
    return checkdate($month, $day, $year);
  }

  /**
   * Validate metadata artifact filename extension.
   */
  protected function isAllowedToolArtifactFilename(string $filename): bool {
    $name = trim($filename);
    if ($name === '') {
      return TRUE;
    }

    if ($name !== basename($name) || preg_match('/[\\\\\/]/', $name)) {
      return FALSE;
    }

    $parts = explode('.', $name);
    if (count($parts) < 2) {
      return FALSE;
    }

    $ext = strtolower((string) end($parts));
    return in_array($ext, $this->getAllowedToolArtifactExtensions(), TRUE);
  }

  /**
   * Load the analytical tools catalog from Drupal state.
   *
   * @return array<string, array<string, mixed>>
   */
  protected function loadAnalyticalToolsCatalog(): array {
    $raw = \Drupal::state()->get($this->getAnalyticalToolsCatalogKey());
    if (!is_array($raw)) {
      return [];
    }

    $catalog = [];
    foreach ($raw as $key => $entry) {
      if (!is_array($entry)) {
        continue;
      }
      $toolUri = trim((string) ($entry['toolUri'] ?? (is_string($key) ? $key : '')));
      if ($toolUri === '') {
        continue;
      }
      $entry['toolUri'] = $toolUri;
      $catalog[$toolUri] = $entry;
    }

    return $catalog;
  }

  /**
   * Persist the analytical tools catalog.
   *
   * @param array<string, array<string, mixed>> $catalog
   */
  protected function saveAnalyticalToolsCatalog(array $catalog): void {
    ksort($catalog);
    \Drupal::state()->set($this->getAnalyticalToolsCatalogKey(), $catalog);
  }

  /**
   * State key for analytical tool execution usage snapshots.
   */
  protected function getAnalyticalToolUsageKey(): string {
    return 'ctt.analytical_tools.usage.v1';
  }

  /**
   * Load analytical tool usage registry.
   *
   * @return array<string, array<string, mixed>>
   */
  protected function loadAnalyticalToolUsageRegistry(): array {
    $raw = \Drupal::state()->get($this->getAnalyticalToolUsageKey());
    if (!is_array($raw)) {
      return [];
    }

    $normalized = [];
    foreach ($raw as $toolUri => $snapshot) {
      $normalizedToolUri = trim((string) $toolUri);
      if ($normalizedToolUri === '' || !$this->isUri($normalizedToolUri) || !is_array($snapshot)) {
        continue;
      }

      $normalized[$normalizedToolUri] = $snapshot;
    }

    return $normalized;
  }

  /**
   * Persist analytical tool usage registry.
   *
   * @param array<string, array<string, mixed>> $registry
   */
  protected function saveAnalyticalToolUsageRegistry(array $registry): void {
    ksort($registry);
    \Drupal::state()->set($this->getAnalyticalToolUsageKey(), $registry);
  }

  /**
   * Normalize a list of URI candidates.
   *
   * @param array<int, mixed> $values
   * @return array<int, string>
   */
  protected function normalizeUriList(array $values): array {
    $normalized = [];
    foreach ($values as $value) {
      $candidate = trim((string) $value);
      if ($this->isUri($candidate)) {
        $normalized[$candidate] = $candidate;
      }
    }

    return array_values($normalized);
  }

  /**
   * Recursively collect URI values from nested arrays/objects.
   *
   * @param mixed $value
   * @param array<int, string> $fieldNames
   * @return array<int, string>
   */
  protected function collectUrisFromStructure($value, array $fieldNames): array {
    $collected = [];

    if (is_string($value)) {
      $candidate = trim($value);
      if ($this->isUri($candidate)) {
        $collected[] = $candidate;
      }
      return $collected;
    }

    if (is_object($value)) {
      $value = (array) $value;
    }

    if (!is_array($value)) {
      return [];
    }

    foreach ($fieldNames as $fieldName) {
      if (!array_key_exists($fieldName, $value)) {
        continue;
      }

      $fieldValue = $value[$fieldName];
      if (is_string($fieldValue)) {
        $candidate = trim($fieldValue);
        if ($this->isUri($candidate)) {
          $collected[] = $candidate;
        }
      }
      elseif (is_array($fieldValue) || is_object($fieldValue)) {
        foreach ($this->collectUrisFromStructure($fieldValue, $fieldNames) as $uri) {
          $collected[] = $uri;
        }
      }
    }

    foreach ($value as $item) {
      if (is_array($item) || is_object($item)) {
        foreach ($this->collectUrisFromStructure($item, $fieldNames) as $uri) {
          $collected[] = $uri;
        }
      }
    }

    return $this->normalizeUriList($collected);
  }

  /**
   * Record successful analytical tool usage and derived dataset references.
   */
  protected function recordAnalyticalToolExecutionUsage(string $toolUri, string $studyUri, string $processUri, array $upstreamPayload = []): void {
    $normalizedToolUri = trim($toolUri);
    if (!$this->isUri($normalizedToolUri)) {
      return;
    }

    $registry = $this->loadAnalyticalToolUsageRegistry();
    $existing = isset($registry[$normalizedToolUri]) && is_array($registry[$normalizedToolUri])
      ? $registry[$normalizedToolUri]
      : [];

    $derivedDatasetUris = [];
    if (!empty($upstreamPayload)) {
      $derivedDatasetUris = $this->collectUrisFromStructure($upstreamPayload, [
        'derivedDatasetUri',
        'derivedDatasetUris',
        'outputDatasetUri',
        'outputDatasetUris',
        'datasetUri',
        'datasetUris',
      ]);
    }

    $mergedDerived = $this->normalizeUriList(array_merge(
      is_array($existing['derivedDatasetUris'] ?? NULL) ? $existing['derivedDatasetUris'] : [],
      $derivedDatasetUris
    ));

    $existingRunCount = (int) ($existing['runCount'] ?? 0);

    $registry[$normalizedToolUri] = [
      'toolUri' => $normalizedToolUri,
      'runCount' => $existingRunCount + 1,
      'lastRunAt' => gmdate('c'),
      'lastStudyUri' => $this->isUri($studyUri) ? $studyUri : (string) ($existing['lastStudyUri'] ?? ''),
      'lastProcessUri' => $this->isUri($processUri) ? $processUri : (string) ($existing['lastProcessUri'] ?? ''),
      'derivedDatasetUris' => $mergedDerived,
      'derivedDatasetCount' => count($mergedDerived),
    ];

    $this->saveAnalyticalToolUsageRegistry($registry);
  }

  /**
   * Resolve usage snapshot for one analytical tool.
   */
  protected function getAnalyticalToolUsageSnapshot(string $toolUri): array {
    $normalizedToolUri = trim($toolUri);
    if (!$this->isUri($normalizedToolUri)) {
      return [
        'runCount' => 0,
        'derivedDatasetUris' => [],
        'derivedDatasetCount' => 0,
      ];
    }

    $registry = $this->loadAnalyticalToolUsageRegistry();
    $snapshot = isset($registry[$normalizedToolUri]) && is_array($registry[$normalizedToolUri])
      ? $registry[$normalizedToolUri]
      : [];

    $derivedDatasetUris = $this->normalizeUriList(is_array($snapshot['derivedDatasetUris'] ?? NULL) ? $snapshot['derivedDatasetUris'] : []);

    return [
      'runCount' => (int) ($snapshot['runCount'] ?? 0),
      'lastRunAt' => (string) ($snapshot['lastRunAt'] ?? ''),
      'lastStudyUri' => (string) ($snapshot['lastStudyUri'] ?? ''),
      'lastProcessUri' => (string) ($snapshot['lastProcessUri'] ?? ''),
      'derivedDatasetUris' => $derivedDatasetUris,
      'derivedDatasetCount' => count($derivedDatasetUris),
    ];
  }

  /**
   * Determine whether current user can mutate one analytical tool entry.
   */
  protected function canCurrentUserMutateTool(array $tool): bool {
    if ((string) $this->currentUser()->id() === '0') {
      return TRUE;
    }

    $ownerIdentifier = trim((string) ($tool['ownerUserEmail'] ?? ''));
    if ($ownerIdentifier === '') {
      $ownerIdentifier = trim((string) ($tool['createdBy'] ?? ''));
    }

    if ($ownerIdentifier === '') {
      return FALSE;
    }

    if (strtolower($ownerIdentifier) === self::PMSR_ADMIN_OWNER_EMAIL
      && $this->currentUserHasPmsrAdministrationRole()
    ) {
      return TRUE;
    }

    return $this->ownerIdentifierMatchesCurrentUser($ownerIdentifier);
  }

  /**
   * Build a consistent owner-required response for analytical tool mutations.
   */
  protected function buildToolOwnerRequiredResponse(string $toolUri, string $reasonCode): JsonResponse {
    $message = 'Only the analytical tool owner can update or remove this tool.';
    if ($reasonCode === 'tool_owner_unresolved') {
      $message = 'Analytical tool owner could not be resolved. Mutation is blocked for safety.';
    }

    $issue = $this->buildValidationIssue('toolUri', $reasonCode, $message);

    return new JsonResponse([
      'isValid' => FALSE,
      'updated' => FALSE,
      'issues' => [$issue],
      'summary' => [
        'errorCount' => 1,
        'warningCount' => 0,
      ],
      'toolUri' => $toolUri,
    ], 403);
  }

  /**
   * Enforce tool owner for mutation operations.
   */
  protected function enforceToolOwnerForMutation(array $tool): ?JsonResponse {
    $toolUri = trim((string) ($tool['toolUri'] ?? ''));
    $ownerIdentifier = trim((string) ($tool['ownerUserEmail'] ?? $tool['createdBy'] ?? ''));

    if ($ownerIdentifier === '') {
      return $this->buildToolOwnerRequiredResponse($toolUri, 'tool_owner_unresolved');
    }

    if (!$this->canCurrentUserMutateTool($tool)) {
      return $this->buildToolOwnerRequiredResponse($toolUri, 'workflow_owner_required');
    }

    return NULL;
  }

  /**
   * Load analytical tool URIs associated with one study.
   *
   * @return array<int, string>
   */
  protected function loadStudyToolUris(string $studyUri): array {
    $raw = \Drupal::state()->get($this->getStudyToolsKey($studyUri));
    if (!is_array($raw)) {
      return [];
    }

    $values = [];
    foreach ($raw as $uri) {
      $normalized = trim((string) $uri);
      if ($normalized !== '') {
        $values[] = $normalized;
      }
    }

    return array_values(array_unique($values));
  }

  /**
   * Persist analytical tool associations for one study.
   *
   * @param array<int, string> $toolUris
   */
  protected function saveStudyToolUris(string $studyUri, array $toolUris): void {
    $normalized = [];
    foreach ($toolUris as $uri) {
      $candidate = trim((string) $uri);
      if ($candidate !== '') {
        $normalized[] = $candidate;
      }
    }

    \Drupal::state()->set($this->getStudyToolsKey($studyUri), array_values(array_unique($normalized)));
  }

  /**
   * Remove one tool URI from all persisted study-tool association buckets.
   */
  protected function removeToolFromAllStudyToolAssociations(string $toolUri): int {
    $needle = trim($toolUri);
    if ($needle === '') {
      return 0;
    }

    $updatedStates = 0;

    try {
      $names = \Drupal::database()->select('key_value', 'kv')
        ->fields('kv', ['name'])
        ->condition('collection', 'state')
        ->condition('name', 'ctt.study_tools.%', 'LIKE')
        ->execute()
        ->fetchCol();

      if (!is_array($names) || empty($names)) {
        return 0;
      }

      $state = \Drupal::state();
      $records = $state->getMultiple($names);

      foreach ($names as $stateKey) {
        $raw = $records[$stateKey] ?? [];
        if (!is_array($raw)) {
          continue;
        }

        $normalized = [];
        $removed = FALSE;
        foreach ($raw as $uri) {
          $candidate = trim((string) $uri);
          if ($candidate === '') {
            continue;
          }
          if (strcasecmp($candidate, $needle) === 0) {
            $removed = TRUE;
            continue;
          }
          $normalized[] = $candidate;
        }

        if ($removed) {
          $state->set($stateKey, array_values(array_unique($normalized)));
          $updatedStates++;
        }
      }
    }
    catch (\Throwable $ignored) {
      // Best-effort cleanup: do not fail deletion if association scan fails.
    }

    return $updatedStates;
  }

  /**
   * Collect persisted process URIs from study-process state associations.
   *
   * @return array<int, string>
   */
  protected function loadPersistedStudyProcessUris(): array {
    try {
      $names = \Drupal::database()->select('key_value', 'kv')
        ->fields('kv', ['name'])
        ->condition('collection', 'state')
        ->condition('name', 'ctt.study_process.%', 'LIKE')
        ->execute()
        ->fetchCol();

      if (!is_array($names) || empty($names)) {
        return [];
      }

      $state = \Drupal::state();
      $records = $state->getMultiple($names);
      $uris = [];
      $seen = [];

      foreach ($names as $stateKey) {
        $raw = $records[$stateKey] ?? NULL;
        $uri = trim((string) $raw);
        if (!$this->isUri($uri)) {
          continue;
        }

        $cacheKey = $this->normalizeProcessUriForCacheKey($uri);
        if (isset($seen[$cacheKey])) {
          continue;
        }

        $seen[$cacheKey] = TRUE;
        $uris[] = $uri;
      }

      return $uris;
    }
    catch (\Throwable $ignored) {
      return [];
    }
  }

  /**
   * Best-effort process label resolution from process URI variants.
   */
  protected function resolveProcessLabelFromUri(string $processUri): string {
    foreach ($this->buildProcessUriVariants($processUri) as $variantUri) {
      try {
        $entity = $this->hascoClient->getByUri($variantUri);
      }
      catch (\Throwable $ignored) {
        continue;
      }

      if (is_array($entity)) {
        $label = trim((string) ($entity['label'] ?? $entity['hasContent'] ?? ''));
        if ($label !== '') {
          return $label;
        }
      }
      elseif (is_object($entity)) {
        $label = trim((string) ($entity->label ?? $entity->hasContent ?? ''));
        if ($label !== '') {
          return $label;
        }
      }
    }

    return '';
  }

  /**
   * Normalize tags to a unique string list.
   *
   * @param mixed $rawTags
   * @return array<int, string>
   */
  protected function normalizeToolTags($rawTags): array {
    $tags = [];

    if (is_string($rawTags)) {
      $rawTags = explode(',', $rawTags);
    }

    if (!is_array($rawTags)) {
      return [];
    }

    foreach ($rawTags as $rawTag) {
      $tag = trim((string) $rawTag);
      if ($tag === '') {
        continue;
      }
      $tags[] = $tag;
    }

    return array_values(array_unique($tags));
  }

  /**
   * Resolve current user institution URI or label for tool ownership metadata.
   */
  protected function resolveCurrentUserInstitution(): string {
    if (!\Drupal::hasService('rep.api_connector')) {
      return '';
    }

    $userEmail = $this->getCurrentUserEmail();
    if ($userEmail === '') {
      return '';
    }

    try {
      $api = \Drupal::service('rep.api_connector');
      $orgRaw = $api->listByManagerEmail('organization', $userEmail, 50, 0);
      $orgParsed = $api->parseObjectResponse($orgRaw, 'listByManagerEmail');
      $orgRows = [];

      if (is_array($orgParsed)) {
        $orgRows = $orgParsed;
      }
      elseif (is_object($orgParsed)) {
        $orgRows = [$orgParsed];
      }

      foreach ($orgRows as $orgRow) {
        if (is_object($orgRow)) {
          $orgUri = trim((string) ($orgRow->uri ?? $orgRow->hasURI ?? ''));
          if ($this->isUri($orgUri)) {
            return $orgUri;
          }

          $orgLabel = trim((string) ($orgRow->label ?? $orgRow->name ?? ''));
          if ($orgLabel !== '') {
            return $orgLabel;
          }
        }
      }
    }
    catch (\Throwable $ignored) {
      return '';
    }

    return '';
  }

  /**
   * Resolve current user Person URI for ownership defaults.
   */
  protected function resolveCurrentUserPersonUri(): string {
    if (!\Drupal::hasService('rep.api_connector')) {
      return '';
    }

    $userEmail = $this->getCurrentUserEmail();
    if ($userEmail === '') {
      return '';
    }

    try {
      $api = \Drupal::service('rep.api_connector');
      $peopleRaw = $api->listByManagerEmail('person', $userEmail, 50, 0);
      $peopleParsed = $api->parseObjectResponse($peopleRaw, 'listByManagerEmail');
      $peopleRows = [];

      if (is_array($peopleParsed)) {
        $peopleRows = $peopleParsed;
      }
      elseif (is_object($peopleParsed)) {
        $peopleRows = [$peopleParsed];
      }

      foreach ($peopleRows as $personRow) {
        if (!is_object($personRow)) {
          continue;
        }

        $personUri = trim((string) ($personRow->uri ?? $personRow->hasURI ?? ''));
        if ($this->isUri($personUri)) {
          return $personUri;
        }
      }
    }
    catch (\Throwable $ignored) {
      return '';
    }

    return '';
  }

  /**
   * Infer tool language from source filename suffix.
   */
  protected function inferToolLanguageFromFilename(string $filename): string {
    $value = strtolower(trim($filename));
    if ($value === '') {
      return '';
    }

    if (str_ends_with($value, '.r')) {
      return 'R';
    }
    if (str_ends_with($value, '.py')) {
      return 'Python';
    }
    if (str_ends_with($value, '.sql')) {
      return 'SQL';
    }
    if (str_ends_with($value, '.js')) {
      return 'JavaScript';
    }
    if (str_ends_with($value, '.ts')) {
      return 'TypeScript';
    }

    return '';
  }

  /**
   * Generate a deterministic HTTP URI for a new analytical tool entry.
   */
  protected function generateAnalyticalToolUri(string $name): string {
    $namespace = trim((string) \Drupal::config('ctt.settings')->get('default_namespace_url'));
    if (!$this->isUri($namespace)) {
      $namespace = 'http://example.org/ctt';
    }

    $suffix = strtoupper(substr(sha1($name . '|' . microtime(TRUE) . '|' . mt_rand()), 0, 12));
    return rtrim($namespace, '/#') . '#AT' . $suffix;
  }

  /**
   * Build normalized analytical tool payload and collect validation issues.
   *
   * @param array<string, mixed> $payload
   * @param array<string, mixed> $existing
   * @return array{tool: array<string, mixed>, issues: array<int, array<string, string>>}
   */
  protected function normalizeAnalyticalToolPayload(array $payload, array $existing = []): array {
    $issues = [];

    $toolUri = trim((string) ($payload['toolUri'] ?? ($existing['toolUri'] ?? '')));
    $processUri = trim((string) ($payload['processUri'] ?? ($existing['processUri'] ?? '')));
    $ownerPersonUri = trim((string) ($payload['ownerPersonUri'] ?? ($existing['ownerPersonUri'] ?? '')));
    $name = trim((string) ($payload['name'] ?? ($existing['name'] ?? '')));
    $version = trim((string) ($payload['version'] ?? ($existing['version'] ?? '')));
    $language = trim((string) ($payload['language'] ?? ($existing['language'] ?? '')));
    $description = trim((string) ($payload['description'] ?? ($existing['description'] ?? '')));
    $containerImage = trim((string) ($payload['containerImage'] ?? ($existing['containerImage'] ?? '')));
    $entrypoint = trim((string) ($payload['entrypoint'] ?? ($existing['entrypoint'] ?? '')));
    $sourceRepositoryUri = trim((string) ($payload['sourceRepositoryUri'] ?? ($existing['sourceRepositoryUri'] ?? '')));
    $artifactFilename = trim((string) ($payload['artifactFilename'] ?? ($existing['artifactFilename'] ?? '')));
    $artifactUri = trim((string) ($payload['artifactUri'] ?? ($existing['artifactUri'] ?? '')));
    $author = trim((string) ($payload['author'] ?? ($existing['author'] ?? '')));
    $institution = trim((string) ($payload['institution'] ?? ($existing['institution'] ?? '')));
    $sourceFilename = trim((string) ($payload['sourceFilename'] ?? ($existing['sourceFilename'] ?? '')));
    $sourceCode = (string) ($payload['sourceCode'] ?? ($existing['sourceCode'] ?? ''));
    $sourceCodeEncoding = trim((string) ($payload['sourceCodeEncoding'] ?? ($existing['sourceCodeEncoding'] ?? 'text/plain')));
    $scenarioUri = trim((string) ($payload['scenarioUri'] ?? ($existing['scenarioUri'] ?? '')));
    $datasetUri = trim((string) ($payload['datasetUri'] ?? ($existing['datasetUri'] ?? '')));
    $releaseDate = trim((string) ($payload['releaseDate'] ?? ($existing['releaseDate'] ?? '')));
    $lineageUri = trim((string) ($payload['lineageUri'] ?? ($existing['lineageUri'] ?? '')));
    $status = strtolower(trim((string) ($payload['status'] ?? ($existing['status'] ?? 'draft'))));
    $tags = $this->normalizeToolTags($payload['tags'] ?? ($existing['tags'] ?? []));

    if ($sourceFilename !== '') {
      $name = $sourceFilename;
      if ($artifactFilename === '') {
        $artifactFilename = $sourceFilename;
      }

      $inferredLanguage = $this->inferToolLanguageFromFilename($sourceFilename);
      if ($inferredLanguage !== '') {
        $language = $inferredLanguage;
      }
    }

    if ($sourceCode !== '' && strlen($sourceCode) > 1024 * 1024) {
      $issues[] = $this->buildValidationIssue('sourceCode', 'source_code_too_large', 'Source code file content exceeds 1 MB.');
    }

    $executeRequested = filter_var(($payload['execute'] ?? $payload['runNow'] ?? FALSE), FILTER_VALIDATE_BOOLEAN);
    if ($executeRequested) {
      $issues[] = $this->buildValidationIssue(
        'execute',
        'script_execution_not_allowed',
        'Scripts are metadata-only in this repository and cannot be executed in Drupal.'
      );
    }

    if ($name === '') {
      $issues[] = $this->buildValidationIssue('name', 'missing_tool_name', 'Analytical tool name is required.');
    }

    $isWildcardProcess = $this->isGlobalProcessWildcard($processUri);

    if ($processUri === '') {
      $issues[] = $this->buildValidationIssue('processUri', 'missing_process_uri', 'Process URI is required for analytical tools collection entries.');
    }
    elseif (!$isWildcardProcess && !$this->isUri($processUri)) {
      $issues[] = $this->buildValidationIssue('processUri', 'invalid_process_uri', 'Process URI must be a valid HTTP(S) URI or "*" for global tools.');
    }

    if ($ownerPersonUri !== '' && !$this->isUri($ownerPersonUri)) {
      $issues[] = $this->buildValidationIssue('ownerPersonUri', 'invalid_owner_person_uri', 'Owner Person URI must be a valid HTTP(S) URI.');
    }

    if ($toolUri === '') {
      $toolUri = $this->generateAnalyticalToolUri($name !== '' ? $name : 'tool');
    }
    elseif (!$this->isUri($toolUri)) {
      $issues[] = $this->buildValidationIssue('toolUri', 'invalid_tool_uri', 'Tool URI must be a valid HTTP(S) URI.');
    }

    if ($lineageUri === '') {
      $lineageUri = (string) ($existing['lineageUri'] ?? $toolUri);
    }
    if ($lineageUri !== '' && !$this->isUri($lineageUri)) {
      $issues[] = $this->buildValidationIssue('lineageUri', 'invalid_lineage_uri', 'Lineage URI must be a valid HTTP(S) URI.');
    }

    $allowedStatuses = $this->getAnalyticalToolStatuses();
    if (!in_array($status, $allowedStatuses, TRUE)) {
      $issues[] = $this->buildValidationIssue('status', 'invalid_tool_status', 'Tool status must be one of: ' . implode(', ', $allowedStatuses) . '.');
    }

    if ($sourceRepositoryUri !== '' && !$this->isUri($sourceRepositoryUri)) {
      $issues[] = $this->buildValidationIssue('sourceRepositoryUri', 'invalid_source_repository_uri', 'Source repository URI must be a valid HTTP(S) URI.');
    }

    if ($artifactUri !== '' && !$this->isUri($artifactUri)) {
      $issues[] = $this->buildValidationIssue('artifactUri', 'invalid_artifact_uri', 'Artifact URI must be a valid HTTP(S) URI.');
    }

    if ($scenarioUri !== '' && !$this->isUri($scenarioUri)) {
      $issues[] = $this->buildValidationIssue('scenarioUri', 'invalid_scenario_uri', 'Scenario URI must be a valid HTTP(S) URI.');
    }

    if ($datasetUri !== '' && !$this->isUri($datasetUri)) {
      $issues[] = $this->buildValidationIssue('datasetUri', 'invalid_dataset_uri', 'Dataset URI must be a valid HTTP(S) URI.');
    }

    if ($releaseDate !== '' && !$this->isIsoDate($releaseDate)) {
      $issues[] = $this->buildValidationIssue('releaseDate', 'invalid_release_date', 'Release date must use YYYY-MM-DD format.');
    }

    if ($artifactFilename !== '' && !$this->isAllowedToolArtifactFilename($artifactFilename)) {
      $issues[] = $this->buildValidationIssue(
        'artifactFilename',
        'invalid_artifact_extension',
        'Artifact filename must use one of these extensions: .' . implode(', .', $this->getAllowedToolArtifactExtensions()) . '.'
      );
    }

    $account = $this->currentUser();
    $updatedBy = (string) $account->getDisplayName();
    $ownerUserEmail = trim((string) ($existing['ownerUserEmail'] ?? ''));
    if ($ownerUserEmail === '') {
      $ownerUserEmail = $this->getCurrentUserEmail();
    }
    try {
      $user = \Drupal\user\Entity\User::load($account->id());
      if ($user && is_string($user->getEmail()) && trim($user->getEmail()) !== '') {
        $updatedBy = trim((string) $user->getEmail());
        if ($ownerUserEmail === '') {
          $ownerUserEmail = $updatedBy;
        }
      }
    }
    catch (\Throwable $ignored) {
      // Keep current display name as fallback.
    }

    $isCreate = empty($existing);
    if ($isCreate) {
      $institutionFromUser = $this->resolveCurrentUserInstitution();
      if ($institutionFromUser !== '') {
        $institution = $institutionFromUser;
      }

      if ($ownerPersonUri === '') {
        $ownerPersonUri = $this->resolveCurrentUserPersonUri();
      }

      $releaseDate = gmdate('Y-m-d');
    }
    elseif ($releaseDate === '') {
      $releaseDate = trim((string) ($existing['releaseDate'] ?? ''));
    }

    $tool = [
      'toolUri' => $toolUri,
      'processUri' => $processUri,
      'processScope' => $isWildcardProcess ? 'all' : 'specific',
      'isProcessWildcard' => $isWildcardProcess,
      'ownerUserEmail' => $ownerUserEmail,
      'ownerPersonUri' => $ownerPersonUri,
      'name' => $name,
      'version' => $version,
      'language' => $language,
      'description' => $description,
      'containerImage' => $containerImage,
      'entrypoint' => $entrypoint,
      'sourceRepositoryUri' => $sourceRepositoryUri,
      'artifactFilename' => $artifactFilename,
      'artifactUri' => $artifactUri,
      'author' => $author,
      'institution' => $institution,
      'sourceFilename' => $sourceFilename,
      'sourceCode' => $sourceCode,
      'sourceCodeEncoding' => $sourceCodeEncoding,
      'sourceCodeBytes' => strlen($sourceCode),
      'scenarioUri' => $scenarioUri,
      'datasetUri' => $datasetUri,
      'releaseDate' => $releaseDate,
      'lineageUri' => $lineageUri,
      'status' => $status,
      'tags' => $tags,
      'isLatestVersion' => (bool) ($existing['isLatestVersion'] ?? TRUE),
      'createdAt' => (string) ($existing['createdAt'] ?? gmdate('c')),
      'createdByUid' => (string) ($existing['createdByUid'] ?? $account->id()),
      'createdBy' => (string) ($existing['createdBy'] ?? $updatedBy),
      'updatedAt' => gmdate('c'),
      'updatedByUid' => (string) $account->id(),
      'updatedBy' => $updatedBy,
    ];

    return [
      'tool' => $tool,
      'issues' => $issues,
    ];
  }

  /**
   * Resolve configured hascoapi endpoint path for R analysis execution.
   */
  protected function getRAnalysisEndpointPath(): string {
    $path = trim((string) \Drupal::config('ctt.settings')->get('r_analysis_endpoint_path'));
    if ($path === '') {
      $path = '/hascoapi/api/r-analysis/execute';
    }

    if (!str_starts_with($path, '/')) {
      $path = '/' . ltrim($path, '/');
    }
    return $path;
  }

  /**
   * Resolve configured timeout for backend R execution calls.
   */
  protected function getRAnalysisTimeoutSeconds(): int {
    $timeout = (int) \Drupal::config('ctt.settings')->get('r_analysis_timeout_seconds');
    if ($timeout < 5 || $timeout > 300) {
      return 60;
    }
    return $timeout;
  }

  /**
   * Resolve local state key used to persist R analysis run history for one study.
   */
  protected function getRAnalysisRunHistoryKey(string $studyUri): string {
    return 'ctt.r_analysis_runs.' . sha1($studyUri);
  }

  /**
   * Load persisted R analysis run history for one study.
   *
   * @return array<int, array<string, mixed>>
   */
  protected function loadRAnalysisRunHistory(string $studyUri): array {
    $history = \Drupal::state()->get($this->getRAnalysisRunHistoryKey($studyUri), []);
    return is_array($history) ? $history : [];
  }

  /**
   * Persist R analysis run history with a bounded list size.
   *
   * @param array<int, array<string, mixed>> $history
   */
  protected function saveRAnalysisRunHistory(string $studyUri, array $history): void {
    if (count($history) > 100) {
      $history = array_slice($history, 0, 100);
    }
    \Drupal::state()->set($this->getRAnalysisRunHistoryKey($studyUri), array_values($history));
  }

  /**
   * Extract first URI value from known result fields in upstream payload.
   */
  protected function extractResultUriFromUpstreamPayload($upstreamPayload): string {
    if (!is_array($upstreamPayload) && !is_object($upstreamPayload)) {
      return '';
    }

    $uris = $this->collectUrisFromStructure($upstreamPayload, [
      'resultUri',
      'resultUris',
      'derivedDatasetUri',
      'derivedDatasetUris',
      'outputDatasetUri',
      'outputDatasetUris',
      'datasetUri',
      'datasetUris',
      'dataFileUri',
      'datafileUri',
      'uri',
    ]);

    return !empty($uris) ? (string) reset($uris) : '';
  }

  /**
   * Find one run index in history by runId, or by latest match for study/process/tool.
   *
   * @param array<int, array<string, mixed>> $history
   */
  protected function findRunHistoryIndex(array $history, string $runId, string $processUri, string $toolUri): int {
    if ($runId !== '') {
      foreach ($history as $index => $entry) {
        if (!is_array($entry)) {
          continue;
        }
        if (trim((string) ($entry['runId'] ?? '')) === $runId) {
          return (int) $index;
        }
      }
      return -1;
    }

    foreach ($history as $index => $entry) {
      if (!is_array($entry)) {
        continue;
      }

      $entryProcess = trim((string) ($entry['processUri'] ?? ''));
      $entryTool = trim((string) ($entry['toolUri'] ?? ''));
      $entryStatus = strtolower(trim((string) ($entry['status'] ?? '')));

      if ($processUri !== '' && $entryProcess !== $processUri) {
        continue;
      }
      if ($toolUri !== '' && $entryTool !== $toolUri) {
        continue;
      }

      if (in_array($entryStatus, ['running', 'queued'], TRUE)) {
        return (int) $index;
      }
    }

    return -1;
  }

  /**
   * Resolve current user identifier (prefers email when available).
   */
  protected function getCurrentUserIdentifier(): string {
    $account = $this->currentUser();
    $identifier = (string) $account->getDisplayName();

    try {
      $user = \Drupal\user\Entity\User::load($account->id());
      if ($user && is_string($user->getEmail()) && trim($user->getEmail()) !== '') {
        $identifier = trim((string) $user->getEmail());
      }
    }
    catch (\Throwable $ignored) {
      // Keep display name fallback when user lookup fails.
    }

    return $identifier;
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
      if (is_array($result) && $this->isUri(trim((string) $uri))) {
        $this->cacheProcessOwnershipContext(trim((string) $uri), $result);
      }
      return new JsonResponse($result);
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => $e->getMessage()], 404);
    }
  }

  /**
   * GET /workflow/api/process/{process_uri}/tasks
   */
  public function getProcessTasks(Request $request, $process_uri) {
    try {
      $uri = $this->decodeRouteEntityUri(is_string($process_uri) ? $process_uri : '');
      if (!$this->isUri($uri)) {
        return new JsonResponse(['error' => 'Missing or invalid process URI.'], 400);
      }

      $result = $this->hascoClient->getTasksByProcess($uri);
      if (is_array($result)) {
        $this->cacheTaskProcessMappings($result, $uri);
      }

      return new JsonResponse($result);
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => $e->getMessage()], 500);
    }
  }

  /**
   * GET /workflow/api/process/export-layout?uri=...
   */
  public function exportProcessLayout(Request $request): JsonResponse {
    try {
      $uri = $this->decodeRouteEntityUri((string) $request->query->get('uri', ''));
      if (!$this->isUri($uri)) {
        return new JsonResponse(['error' => 'Missing or invalid process URI.'], 400);
      }

      $result = $this->workflowLayoutExporter->exportProcessLayout($uri);
      $status = !empty($result['isSuccessful']) ? 200 : 422;
      return new JsonResponse($result, $status);
    }
    catch (\Throwable $e) {
      return new JsonResponse(['error' => $e->getMessage()], 500);
    }
  }

  /**
   * PUT /workflow/api/process/{process_uri}
   */
  public function updateProcess(Request $request, $process_uri) {
    $uri = $this->decodeRouteEntityUri(is_string($process_uri) ? $process_uri : '');
    if (!$this->isUri($uri)) {
      return new JsonResponse(['error' => 'Missing or invalid process URI.'], 400);
    }

    $ownerGuard = $this->enforceProcessOwnerForMutation($uri);
    if ($ownerGuard instanceof JsonResponse) {
      return $ownerGuard;
    }

    $data = json_decode($request->getContent(), TRUE);
    if (!is_array($data)) {
      $data = [];
    }
    $data['uri'] = $uri;

    $proxyRequest = Request::create('/workflow/api/process/create', 'POST', [], [], [], [], json_encode($data));
    $response = $this->createProcess($proxyRequest);
    if ($response->getStatusCode() === 201) {
      $response->setStatusCode(200);
    }

    return $response;
  }

  /**
   * POST /workflow/api/process/create
   */
  public function createProcess(Request $request) {
    $data = json_decode($request->getContent(), TRUE);
    if (empty($data)) {
      return new JsonResponse(['error' => 'Invalid JSON body'], 400);
    }

    $requestedProcessUri = $this->decodeRouteEntityUri((string) ($data['uri'] ?? ''));
    if ($this->isUri($requestedProcessUri)) {
      $data['uri'] = $requestedProcessUri;
    }

    $existingProcess = NULL;
    $existingProcessUri = '';
    if ($this->isUri((string) ($data['uri'] ?? ''))) {
      $existingProcessUri = $this->resolveExistingProcessUriVariant((string) $data['uri']);
      try {
        $candidate = $this->hascoClient->getByUri($existingProcessUri);
        if (is_array($candidate) && empty($candidate['error'])) {
          $existingProcess = $candidate;
        }
      }
      catch (\Throwable $ignored) {
        $existingProcess = NULL;
      }
    }

    if (is_array($existingProcess) && $this->isUri($existingProcessUri) && (string) $this->currentUser()->id() !== '0') {
      $ownerGuard = $this->enforceProcessOwnerForMutation($existingProcessUri);
      if ($ownerGuard instanceof JsonResponse) {
        return $ownerGuard;
      }
    }

    $data = $this->applyEntityFieldFallbacks($data, $existingProcess, [
      'hasLearningObjectives' => ['hasLearningObjectives'],
      'hasCriticalActions' => ['hasCriticalActions'],
      'hasDebriefingFocus' => ['hasDebriefingFocus'],
      'hasTopTaskUri' => ['hasTopTaskUri', 'hasTopTask'],
      'hasLanguage' => ['hasLanguage', 'language'],
      'hasVersion' => ['hasVersion', 'version'],
      'hasStatus' => ['hasStatus', 'status'],
      'hascoType' => ['hascoType', 'hascoTypeUri'],
      'typeUri' => ['typeUri'],
      'label' => ['label', 'hasContent'],
      'comment' => ['comment', 'description'],
      'hasSIRManagerEmail' => ['hasSIRManagerEmail', 'managerEmail', 'managedBy'],
    ]);

    $currentOwnerIdentifier = $this->getCurrentUserEmail();
    $requestedOwnerIdentifier = trim((string) ($data['hasSIRManagerEmail'] ?? $data['managerEmail'] ?? ''));
    if ((string) $this->currentUser()->id() !== '0' && $requestedOwnerIdentifier !== '' && $currentOwnerIdentifier !== '' && strcasecmp($requestedOwnerIdentifier, $currentOwnerIdentifier) !== 0) {
      return $this->buildProcessOwnerRequiredResponse(trim((string) ($data['uri'] ?? '')), 'workflow_owner_required');
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

      $createdProcessUri = trim((string) ($data['uri'] ?? ''));
      if (is_array($result)) {
        $resolvedResultUri = $this->extractEntityUri($result);
        if ($this->isUri($resolvedResultUri)) {
          $createdProcessUri = $resolvedResultUri;
        }
        elseif (isset($result['body']) && is_array($result['body'])) {
          $resolvedBodyUri = $this->extractEntityUri($result['body']);
          if ($this->isUri($resolvedBodyUri)) {
            $createdProcessUri = $resolvedBodyUri;
          }
        }
      }

      if ($this->isUri($createdProcessUri)) {
        $ownerIdentifier = trim((string) ($data['hasSIRManagerEmail'] ?? $currentOwnerIdentifier));
        if ($ownerIdentifier !== '') {
          $this->cacheProcessOwnerIdentifier($createdProcessUri, $ownerIdentifier);
        }

        $this->triggerPmsrMembersStatisticsRefreshForProcess($createdProcessUri, $ownerIdentifier);
      }
      else {
        $ownerIdentifier = trim((string) ($data['hasSIRManagerEmail'] ?? $currentOwnerIdentifier));
        $this->triggerPmsrMembersStatisticsRefreshByManagerEmail($ownerIdentifier);
      }

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

      $resolvedUri = $this->resolveExistingProcessUriVariant((string) $uri);
      if ($this->isUri($resolvedUri)) {
        $uri = $resolvedUri;
      }

      $ownerIdentifier = $this->resolveProcessOwnerEmail((string) $uri);

      $ownerGuard = $this->enforceProcessOwnerForMutation((string) $uri);
      if ($ownerGuard instanceof JsonResponse) {
        return $ownerGuard;
      }

      $this->hascoClient->deleteElement('process', $uri);
      $this->triggerPmsrMembersStatisticsRefreshForProcess((string) $uri, $ownerIdentifier);
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
      $uri = trim((string) $request->query->get('uri', ''));
      if (!$this->isUri($uri)) {
        return new JsonResponse([
          'error' => 'Missing or invalid process URI.',
          'requestedUri' => $uri,
        ], 400);
      }

      $process = $this->hascoClient->getByUri($uri);
      if (is_array($process) && !empty($process['error'])) {
        return new JsonResponse([
          'error' => 'Workflow process was not found in HASCOAPI.',
          'details' => (string) $process['error'],
          'requestedUri' => $uri,
        ], 404);
      }

      $tasks = $this->hascoClient->getTasksByProcess($uri);

      if (is_array($process) && $this->isUri(trim((string) $uri))) {
        $normalizedProcessUri = trim((string) $uri);
        $this->cacheProcessOwnershipContext($normalizedProcessUri, $process);
        $this->cacheTaskProcessMappings($tasks, $normalizedProcessUri);
      }

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

      $ownerGuard = $this->enforceProcessOwnerForMutation($uri);
      if ($ownerGuard instanceof JsonResponse) {
        return $ownerGuard;
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

    $ownerGuard = $this->enforceProcessOwnerForMutation($uri);
    if ($ownerGuard instanceof JsonResponse) {
      return $ownerGuard;
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
        if (is_array($result) && $this->isUri(trim((string) $processUri))) {
          $this->cacheTaskProcessMappings($result, trim((string) $processUri));
        }
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
   * PUT /workflow/api/task/{task_uri}
   */
  public function updateTask(Request $request, $task_uri) {
    $uri = $this->decodeRouteEntityUri(is_string($task_uri) ? $task_uri : '');
    if (!$this->isUri($uri)) {
      return new JsonResponse(['error' => 'Missing or invalid task URI.'], 400);
    }

    $ownerGuard = $this->enforceTaskOwnerForMutation($uri);
    if ($ownerGuard instanceof JsonResponse) {
      return $ownerGuard;
    }

    $data = json_decode($request->getContent(), TRUE);
    if (!is_array($data)) {
      $data = [];
    }

    $data['uri'] = $uri;
    if (!$this->isUri(trim((string) ($data['processUri'] ?? '')))) {
      $resolvedTaskProcessUri = $this->resolveTaskProcessUri($uri);
      if ($this->isUri($resolvedTaskProcessUri)) {
        $data['processUri'] = $resolvedTaskProcessUri;
      }
    }

    $proxyRequest = Request::create('/workflow/api/task/create', 'POST', [], [], [], [], json_encode($data));
    $response = $this->createTask($proxyRequest);
    if ($response->getStatusCode() === 201) {
      $response->setStatusCode(200);
    }

    return $response;
  }

  /**
   * GET /workflow/api/task/{task_uri}/children
   */
  public function getTaskChildren(Request $request, $task_uri) {
    try {
      $uri = $this->decodeRouteEntityUri(is_string($task_uri) ? $task_uri : '');
      if (!$this->isUri($uri)) {
        return new JsonResponse(['error' => 'Missing or invalid task URI.'], 400);
      }

      $task = $this->hascoClient->getByUri($uri);
      if (!is_array($task) || !empty($task['error'])) {
        return new JsonResponse([], 200);
      }

      $childUris = [];
      foreach (['hasSubtaskUris', 'hasSubtask', 'subtaskUris'] as $fieldName) {
        if (!array_key_exists($fieldName, $task)) {
          continue;
        }

        foreach ($this->extractUriListFromMixedValue($task[$fieldName]) as $candidateUri) {
          $candidateUri = $this->decodeRouteEntityUri($candidateUri);
          if ($candidateUri !== '') {
            $childUris[$candidateUri] = $candidateUri;
          }
        }
      }

      $children = [];
      foreach (array_values($childUris) as $childUri) {
        try {
          $child = $this->hascoClient->getByUri($childUri);
          if (is_array($child) && empty($child['error'])) {
            $children[] = $child;
          }
        }
        catch (\Throwable $ignored) {
          // Skip unresolved children and keep best-effort response.
        }
      }

      return new JsonResponse($children);
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => $e->getMessage()], 500);
    }
  }

  /**
   * PUT /workflow/api/task/{task_uri}/dependency
   */
  public function setTaskDependency(Request $request, $task_uri) {
    $uri = $this->decodeRouteEntityUri(is_string($task_uri) ? $task_uri : '');
    if (!$this->isUri($uri)) {
      return new JsonResponse(['error' => 'Missing or invalid task URI.'], 400);
    }

    $ownerGuard = $this->enforceTaskOwnerForMutation($uri);
    if ($ownerGuard instanceof JsonResponse) {
      return $ownerGuard;
    }

    $data = json_decode($request->getContent(), TRUE);
    if (!is_array($data)) {
      return new JsonResponse(['error' => 'Invalid JSON body'], 400);
    }

    $operatorRaw = trim((string) ($data['operator'] ?? $data['hasTemporalDependency'] ?? ''));
    if ($operatorRaw === '') {
      return new JsonResponse(['error' => 'Missing dependency operator'], 400);
    }

    $normalizedDependency = $this->normalizeTemporalDependencyOperator($operatorRaw);
    $updatePayload = [
      'uri' => $uri,
      'hasTemporalDependency' => $normalizedDependency,
    ];

    $resolvedTaskProcessUri = $this->resolveTaskProcessUri($uri);
    if ($this->isUri($resolvedTaskProcessUri)) {
      $updatePayload['processUri'] = $resolvedTaskProcessUri;
    }

    $proxyRequest = Request::create('/workflow/api/task/create', 'POST', [], [], [], [], json_encode($updatePayload));
    $response = $this->createTask($proxyRequest);
    if ($response->getStatusCode() === 201) {
      $response->setStatusCode(200);
    }

    return $response;
  }

  /**
   * POST /workflow/api/task/create
   */
  public function createTask(Request $request) {
    $data = json_decode($request->getContent(), TRUE);
    if (empty($data)) {
      return new JsonResponse(['error' => 'Invalid JSON body'], 400);
    }

    $requestedTaskUri = $this->decodeRouteEntityUri((string) ($data['uri'] ?? ''));
    if ($this->isUri($requestedTaskUri)) {
      $data['uri'] = $requestedTaskUri;
    }

    $existingTask = NULL;
    $existingTaskUri = trim((string) ($data['uri'] ?? ''));
    if ($this->isUri($existingTaskUri)) {
      try {
        $candidate = $this->hascoClient->getByUri($existingTaskUri);
        if (is_array($candidate) && empty($candidate['error'])) {
          $existingTask = $candidate;
        }
      }
      catch (\Throwable $ignored) {
        $existingTask = NULL;
      }
    }

    $data = $this->applyEntityFieldFallbacks($data, $existingTask, [
      'label' => ['label', 'hasContent'],
      'comment' => ['comment', 'description'],
      'hasLanguage' => ['hasLanguage', 'language'],
      'hasVersion' => ['hasVersion', 'version'],
      'hasStatus' => ['hasStatus', 'status'],
      'typeUri' => ['typeUri'],
      'hascoType' => ['hascoType', 'hascoTypeUri'],
      'hasSupertaskUri' => ['hasSupertaskUri', 'supertaskUri', 'hasSupertask'],
      'hasSubtaskUris' => ['hasSubtaskUris', 'subtaskUris', 'hasSubtask'],
      'hasTemporalDependency' => ['hasTemporalDependency'],
      'hasIterationConstraint' => ['hasIterationConstraint'],
      'supportsObjective' => ['supportsObjective'],
      'hasRequiredInstrumentUris' => ['hasRequiredInstrumentUris'],
      'processUri' => [
        'processUri',
        'workflowUri',
        'workflowuri',
        'hasWorkflowUri',
        'hasProcessUri',
        'partOfProcessUri',
        'partOfProcess',
        'partOf',
        'hasSIRPartOf',
        'hasProcess',
        'process',
      ],
    ]);

    $taskProcessUri = $this->extractProcessUriFromTaskEntity($data);
    if (!$this->isUri($taskProcessUri) && is_array($existingTask)) {
      $taskProcessUri = $this->extractProcessUriFromTaskEntity($existingTask);
    }
    if (!$this->isUri($taskProcessUri)) {
      $parentTaskUri = $this->decodeRouteEntityUri(trim((string) ($data['hasSupertaskUri'] ?? $data['supertaskUri'] ?? '')));
      if ($this->isUri($parentTaskUri)) {
        $taskProcessUri = $this->resolveTaskProcessUri($parentTaskUri);
      }
    }

    if (!$this->isUri($taskProcessUri)) {
      $contextProcessUri = $this->extractProcessUriFromRequestContext($request);
      if ($this->isUri($contextProcessUri)) {
        $taskProcessUri = $contextProcessUri;
      }
    }

    if (!$this->isUri($taskProcessUri)) {
      return $this->buildTaskOwnerRequiredResponse(trim((string) ($data['uri'] ?? '')), 'task_process_unresolved');
    }

    $resolvedTaskProcessUri = $this->resolveExistingProcessUriVariant($taskProcessUri);
    if ($this->isUri($resolvedTaskProcessUri)) {
      $taskProcessUri = $resolvedTaskProcessUri;
      $data['processUri'] = $resolvedTaskProcessUri;
    }

    $ownerGuard = $this->enforceProcessOwnerForMutation($taskProcessUri);
    if ($ownerGuard instanceof JsonResponse) {
      return $ownerGuard;
    }

    try {
      $payload = $data;
      // processUri is internal workflow context used for ownership/mapping only.
      // HASCO Task POJO rejects unknown fields, so do not forward it upstream.
      unset($payload['processUri']);

      $result = $this->hascoClient->createElement('task', $payload);

      $createdTaskUri = trim((string) ($data['uri'] ?? ''));
      if (is_array($result)) {
        $resolvedTaskUri = $this->extractEntityUri($result);
        if ($this->isUri($resolvedTaskUri)) {
          $createdTaskUri = $resolvedTaskUri;
        }
        elseif (isset($result['body']) && is_array($result['body'])) {
          $resolvedBodyTaskUri = $this->extractEntityUri($result['body']);
          if ($this->isUri($resolvedBodyTaskUri)) {
            $createdTaskUri = $resolvedBodyTaskUri;
          }
        }
      }

      if ($this->isUri($createdTaskUri)) {
        $this->cacheTaskProcessUri($createdTaskUri, $taskProcessUri);
      }

      $this->triggerPmsrMembersStatisticsRefreshForProcess($taskProcessUri, $this->getCurrentUserEmail());

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

      $ownerGuard = $this->enforceTaskOwnerForMutation((string) $uri);
      if ($ownerGuard instanceof JsonResponse) {
        return $ownerGuard;
      }

      $taskProcessUri = $this->resolveTaskProcessUri((string) $uri);

      $this->hascoClient->deleteElement('task', $uri);
      $this->triggerPmsrMembersStatisticsRefreshForProcess($taskProcessUri, $this->getCurrentUserEmail());
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

      $ownerGuard = $this->enforceTaskOwnerForMutation((string) $task_uri);
      if ($ownerGuard instanceof JsonResponse) {
        return $ownerGuard;
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
   * Normalize plain/encoded/base64 URI-like query values.
   */
  protected function decodeMaybeEncodedUri(string $value): ?string {
    $candidate = trim($value);
    if ($candidate === '') {
      return NULL;
    }

    $decoded = base64_decode($candidate, TRUE);
    if (is_string($decoded) && $decoded !== '' && $this->isUri($decoded)) {
      return $decoded;
    }

    return rawurldecode($candidate);
  }

  /**
   * Normalize URI into a stable comparison key.
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
   * Extract URI string from mixed scalar/object payloads.
   */
  protected function extractUriFromValue(mixed $value): string {
    if (is_string($value)) {
      $candidate = trim($value);
      return $this->isUri($candidate) ? $candidate : '';
    }

    if (is_object($value)) {
      foreach (['uri', 'hasURI', 'typeUri', 'componentUri', 'instrumentUri'] as $key) {
        if (isset($value->{$key}) && is_string($value->{$key})) {
          $candidate = trim((string) $value->{$key});
          if ($this->isUri($candidate)) {
            return $candidate;
          }
        }
      }
    }

    if (is_array($value)) {
      foreach (['uri', 'hasURI', 'typeUri', 'componentUri', 'instrumentUri'] as $key) {
        if (!empty($value[$key]) && is_string($value[$key])) {
          $candidate = trim((string) $value[$key]);
          if ($this->isUri($candidate)) {
            return $candidate;
          }
        }
      }
    }

    return '';
  }

  /**
   * Normalize list/object API responses into a plain object list.
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
    }
    catch (\Throwable $ignored) {
      return $context;
    }

    return $context;
  }

  /**
   * Resolve current user organization context with study fallback.
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
        $user = \Drupal\user\Entity\User::load($this->currentUser()->id());
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
   * Resolve direct/indirect organization scope for filtering.
   *
   * @return array<int, string>
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
   * Resolve platform instances belonging to organization scope.
   *
   * @return array<string, array<string, string>>
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
      $maxPages = 8;

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

    return $options;
  }

  /**
   * Normalize component payload shape into compact list for frontend modal.
   *
   * @return array<int, array<string, mixed>>
   */
  protected function normalizeInstrumentComponentsPayload(mixed $payload): array {
    $source = $payload;
    if (is_array($payload) && array_key_exists('body', $payload)) {
      $source = $payload['body'];
    }

    $rows = [];
    if (is_object($source)) {
      if (isset($source->elements) && is_array($source->elements)) {
        $rows = $source->elements;
      }
      else {
        $rows = [$source];
      }
    }
    elseif (is_array($source)) {
      if (isset($source['elements']) && is_array($source['elements'])) {
        $rows = $source['elements'];
      }
      else {
        $rows = $source;
      }
    }

    $result = [];
    $seen = [];
    foreach ($rows as $row) {
      if (is_object($row)) {
        $row = (array) $row;
      }
      if (!is_array($row)) {
        continue;
      }

      $componentUri = '';
      foreach (['componentUri', 'hasComponent', 'uri', 'hasURI'] as $key) {
        if (empty($row[$key])) {
          continue;
        }
        $componentUri = $this->extractUriFromValue($row[$key]);
        if ($componentUri !== '') {
          break;
        }
      }
      if (!$this->isUri($componentUri)) {
        continue;
      }

      $componentKey = $this->normalizeUriKey($componentUri);
      if ($componentKey === '' || isset($seen[$componentKey])) {
        continue;
      }
      $seen[$componentKey] = TRUE;

      $label = trim((string) ($row['label'] ?? $row['name'] ?? $row['title'] ?? $componentUri));
      $status = trim((string) ($row['hasStatus'] ?? $row['status'] ?? ''));

      $result[] = [
        'uri' => $componentUri,
        'hasURI' => $componentUri,
        'label' => $label !== '' ? $label : $componentUri,
        'hasStatus' => $status,
      ];
    }

    return $result;
  }

  /**
   * Build organization-scoped instrument payload with associated components.
   */
  protected function buildOrganizationScopedInstrumentsPayload(?string $studyUri, ?string $processUri): array {
    $context = $this->resolveCurrentUserOrganizationContext($studyUri);
    $organizationUri = trim((string) ($context['organizationUri'] ?? ''));
    $organizationLabel = trim((string) ($context['organizationLabel'] ?? ''));

    $cacheKey = 'ctt.instrument.prefilter.v1.' . sha1(json_encode([
      'studyUri' => (string) $studyUri,
      'processUri' => (string) $processUri,
      'organizationUri' => $organizationUri,
      'user' => (string) $this->currentUser()->id(),
    ]));

    $cached = \Drupal::state()->get($cacheKey, []);
    if (is_array($cached)) {
      $cachedAt = (int) ($cached['cached_at'] ?? 0);
      $cachedData = $cached['data'] ?? NULL;
      if ($cachedAt > 0 && (time() - $cachedAt) <= 300 && is_array($cachedData)) {
        return $cachedData;
      }
    }

    $payload = [
      'organizationUri' => $organizationUri,
      'organizationLabel' => $organizationLabel,
      'studyUri' => (string) $studyUri,
      'processUri' => (string) $processUri,
      'instruments' => [],
    ];

    if (!$this->isUri($organizationUri) || !\Drupal::hasService('rep.api_connector')) {
      return $payload;
    }

    try {
      $api = \Drupal::service('rep.api_connector');
      $scopeUris = $this->resolveOrganizationScopeUris($organizationUri);
      $platformDetails = $this->resolveOrganizationPlatformInstances($scopeUris);
      if (empty($platformDetails)) {
        return $payload;
      }

      $platformKeys = [];
      foreach (array_keys($platformDetails) as $platformUri) {
        $key = $this->normalizeUriKey((string) $platformUri);
        if ($key !== '') {
          $platformKeys[$key] = $platformUri;
        }
      }

      $deadline = microtime(TRUE) + 8.0;
      $pageSize = 100;
      $maxPages = 8;
      $maxInstanceLookups = 180;
      $instanceLookups = 0;
      $instrumentInstanceCache = [];
      $instrumentRows = [];

      for ($page = 0; $page < $maxPages; $page++) {
        if (microtime(TRUE) >= $deadline) {
          break;
        }

        $offset = $page * $pageSize;
        $raw = $api->listByKeyword('deployment', '_', $pageSize, $offset);
        $parsed = $api->parseObjectResponse($raw, 'listByKeyword');
        $chunk = $this->normalizeApiListPayload($parsed);
        if (empty($chunk)) {
          break;
        }

        foreach ($chunk as $deployment) {
          if (microtime(TRUE) >= $deadline) {
            break 2;
          }
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
          $platformKey = $this->normalizeUriKey($platformUri);
          if ($platformKey === '' || !isset($platformKeys[$platformKey])) {
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
            if ($instanceLookups >= $maxInstanceLookups) {
              break 2;
            }

            $instanceLookups++;
            $instrumentUri = '';
            $instrumentLabel = '';
            $instrumentStatus = '';

            try {
              $instrumentInstance = $api->parseObjectResponse($api->getUri($instrumentInstanceUri), 'getUri');
              if (is_object($instrumentInstance)) {
                if (isset($instrumentInstance->typeUri) && is_string($instrumentInstance->typeUri)) {
                  $instrumentUri = trim((string) $instrumentInstance->typeUri);
                }
                if (!$this->isUri($instrumentUri) && isset($instrumentInstance->type)) {
                  $instrumentUri = $this->extractUriFromValue($instrumentInstance->type);
                }
                if (!$this->isUri($instrumentUri) && isset($instrumentInstance->instrument)) {
                  $instrumentUri = $this->extractUriFromValue($instrumentInstance->instrument);
                }

                if (isset($instrumentInstance->type) && is_object($instrumentInstance->type)) {
                  $instrumentLabel = trim((string) ($instrumentInstance->type->label ?? $instrumentInstance->type->name ?? ''));
                  $instrumentStatus = trim((string) ($instrumentInstance->type->hasStatus ?? ''));
                }
              }
            }
            catch (\Throwable $ignored) {
              $instrumentUri = '';
            }

            $instrumentInstanceCache[$instrumentInstanceUri] = [
              'instrumentUri' => $this->isUri($instrumentUri) ? $instrumentUri : '',
              'label' => $instrumentLabel,
              'status' => $instrumentStatus,
            ];
          }

          $resolved = $instrumentInstanceCache[$instrumentInstanceUri] ?? [];
          $instrumentUri = trim((string) ($resolved['instrumentUri'] ?? ''));
          if (!$this->isUri($instrumentUri)) {
            continue;
          }

          if (!isset($instrumentRows[$instrumentUri])) {
            $instrumentRows[$instrumentUri] = [
              'uri' => $instrumentUri,
              'hasURI' => $instrumentUri,
              'label' => trim((string) ($resolved['label'] ?? '')),
              'hasStatus' => trim((string) ($resolved['status'] ?? '')),
              'instanceUris' => [],
              'platforms' => [],
              'components' => [],
            ];
          }

          $instrumentRows[$instrumentUri]['instanceUris'][$instrumentInstanceUri] = $instrumentInstanceUri;
          $instrumentRows[$instrumentUri]['platforms'][$platformUri] = [
            'uri' => $platformUri,
            'label' => (string) ($platformDetails[$platformUri]['label'] ?? $platformUri),
          ];
        }

        if (count($chunk) < $pageSize) {
          break;
        }
      }

      $componentDeadline = microtime(TRUE) + 7.0;
      $maxComponentLookups = 60;
      $componentLookups = 0;

      foreach ($instrumentRows as $instrumentUri => &$row) {
        if (microtime(TRUE) >= $componentDeadline) {
          break;
        }
        if ($componentLookups >= $maxComponentLookups) {
          break;
        }

        $componentLookups++;
        try {
          $componentsRaw = $this->hascoClient->getInstrumentComponents($instrumentUri);
          $row['components'] = $this->normalizeInstrumentComponentsPayload($componentsRaw);
        }
        catch (\Throwable $ignored) {
          $row['components'] = [];
        }

        if (trim((string) $row['label']) === '') {
          $row['label'] = $instrumentUri;
        }

        $row['instanceUris'] = array_values($row['instanceUris']);
        $row['platforms'] = array_values($row['platforms']);
      }
      unset($row);

      $payload['instruments'] = array_values($instrumentRows);

      \Drupal::state()->set($cacheKey, [
        'cached_at' => time(),
        'data' => $payload,
      ]);
    }
    catch (\Throwable $e) {
      $this->getLogger('ctt')->warning('Instrument prefilter build failed: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return $payload;
  }

  /**
   * GET /workflow/api/instrument/prefilter
   * Returns organization-scoped instruments with associated components.
   */
  public function listOrganizationScopedInstruments(Request $request) {
    try {
      $studyRaw = (string) $request->query->get('studyUri', '');
      $processRaw = (string) $request->query->get('processUri', '');

      $studyUri = $studyRaw !== '' ? $this->decodeMaybeEncodedUri($studyRaw) : NULL;
      $processUri = $processRaw !== '' ? $this->decodeMaybeEncodedUri($processRaw) : NULL;

      $organizationUri = trim((string) $request->query->get('organizationUri', ''));
      if (!$this->isUri($organizationUri)) {
        $context = $this->resolveCurrentUserOrganizationContext($studyUri);
        $organizationUri = trim((string) ($context['organizationUri'] ?? ''));
      }

      $scopeUris = [];
      $rawScopeUris = $request->query->all('organizationScopeUris');
      if (is_array($rawScopeUris)) {
        foreach ($rawScopeUris as $candidate) {
          if (!is_scalar($candidate)) {
            continue;
          }
          $value = trim((string) $candidate);
          if ($this->isUri($value)) {
            $scopeUris[$value] = TRUE;
          }
        }
      }

      $scopeCsv = trim((string) $request->query->get('organizationScopeUris', ''));
      if ($scopeCsv !== '') {
        foreach (explode(',', $scopeCsv) as $candidate) {
          $value = trim((string) $candidate);
          if ($this->isUri($value)) {
            $scopeUris[$value] = TRUE;
          }
        }
      }

      if (empty($scopeUris) && $this->isUri($organizationUri)) {
        foreach ($this->resolveOrganizationScopeUris($organizationUri) as $scopeUri) {
          $value = trim((string) $scopeUri);
          if ($this->isUri($value)) {
            $scopeUris[$value] = TRUE;
          }
        }
      }

      $result = $this->hascoClient->getInstrumentPrefilter(
        $studyUri,
        $processUri,
        $organizationUri,
        array_values(array_keys($scopeUris))
      );

      return new JsonResponse($result);
    }
    catch (\Throwable $e) {
      return new JsonResponse([
        'ok' => FALSE,
        'error' => $e->getMessage(),
      ], 500);
    }
  }

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
   * GET /workflow/api/r-analysis/autocomplete/study?q=keyword
   */
  public function rAnalysisStudyAutocomplete(Request $request) {
    $results = [];
    $input = $request->query->get('q', '');
    if (!is_string($input) || trim($input) === '') {
      return new JsonResponse($results);
    }

    try {
      $keyword = Xss::filter($input);
      $api = \Drupal::service('rep.api_connector');
      $studyList = $api->listByKeyword('study', $keyword, 10, 0);
      $obj = json_decode($studyList);
      $studies = [];
      if ($obj && !empty($obj->isSuccessful) && isset($obj->body) && is_array($obj->body)) {
        $studies = $obj->body;
      }

      foreach ($studies as $study) {
        $uri = trim((string) ($study->uri ?? ''));
        if (!$this->isUri($uri)) {
          continue;
        }

        $label = trim((string) ($study->label ?? $uri));
        $value = Utils::trimPreserveBracket(Utils::fieldToAutocomplete($uri, $label), 127);
        $label = Utils::trimPreserveBracket($label, 127);

        $results[] = [
          'value' => $value,
          'label' => $label,
          'uri' => $uri,
        ];
      }
    }
    catch (\Throwable $ignored) {
      return new JsonResponse([]);
    }

    return new JsonResponse($results);
  }

  /**
   * GET /workflow/api/r-analysis/autocomplete/process?q=keyword
   */
  public function rAnalysisProcessAutocomplete(Request $request) {
    $results = [];
    $input = $request->query->get('q', '');
    if (!is_string($input) || trim($input) === '') {
      return new JsonResponse($results);
    }

    try {
      $keyword = Xss::filter($input);
      $keywordLower = strtolower(trim((string) $keyword));
      $api = \Drupal::service('rep.api_connector');
      $workflowList = $api->listByKeyword('workflow', $keyword, 10, 0);
      $obj = json_decode($workflowList);
      $workflows = [];
      if ($obj && !empty($obj->isSuccessful) && isset($obj->body) && is_array($obj->body)) {
        $workflows = $obj->body;
      }

      if (empty($workflows)) {
        $processList = $api->listByKeyword('process', $keyword, 10, 0);
        $processObj = json_decode($processList);
        if ($processObj && !empty($processObj->isSuccessful) && isset($processObj->body) && is_array($processObj->body)) {
          $workflows = $processObj->body;
        }
      }

      $candidatesByKey = [];

      foreach ($workflows as $workflow) {
        $uri = trim((string) ($workflow->uri ?? ''));
        if (!$this->isUri($uri)) {
          continue;
        }

        $label = trim((string) ($workflow->label ?? $uri));
        $candidateKey = $this->normalizeProcessUriForCacheKey($uri);
        $candidatesByKey[$candidateKey] = [
          'uri' => $uri,
          'label' => $label,
        ];
      }

      if (count($candidatesByKey) < 10) {
        $persistedUris = $this->loadPersistedStudyProcessUris();
        foreach ($persistedUris as $persistedUri) {
          if (count($candidatesByKey) >= 10) {
            break;
          }

          $candidateKey = $this->normalizeProcessUriForCacheKey($persistedUri);
          if (isset($candidatesByKey[$candidateKey])) {
            continue;
          }

          $uriSearchBlob = strtolower($persistedUri . ' ' . $candidateKey);
          $label = '';

          if ($keywordLower === '' || strpos($uriSearchBlob, $keywordLower) === FALSE) {
            $label = $this->resolveProcessLabelFromUri($persistedUri);
            if ($keywordLower !== '') {
              $labelSearchBlob = strtolower($label);
              if ($labelSearchBlob === '' || strpos($labelSearchBlob, $keywordLower) === FALSE) {
                continue;
              }
            }
          }

          if ($label === '') {
            $label = $this->resolveProcessLabelFromUri($persistedUri);
          }
          if ($label === '') {
            $label = $persistedUri;
          }

          $candidatesByKey[$candidateKey] = [
            'uri' => $persistedUri,
            'label' => $label,
          ];
        }
      }

      foreach ($candidatesByKey as $candidate) {
        $uri = trim((string) ($candidate['uri'] ?? ''));
        if (!$this->isUri($uri)) {
          continue;
        }

        $label = trim((string) ($candidate['label'] ?? $uri));
        $value = Utils::trimPreserveBracket(Utils::fieldToAutocomplete($uri, $label), 127);
        $label = Utils::trimPreserveBracket($label, 127);

        $results[] = [
          'value' => $value,
          'label' => $label,
          'uri' => $uri,
        ];
      }
    }
    catch (\Throwable $ignored) {
      return new JsonResponse([]);
    }

    return new JsonResponse($results);
  }

  /**
   * Execute R analysis using real catalog metadata and real backend calls.
   *
   * Optional validateOnly=1 performs payload validation without upstream call.
   */
  public function executeRAnalysis(Request $request) {
    $payload = [];
    $query = $request->query->all();
    $rawBody = trim((string) $request->getContent());
    if ($rawBody !== '') {
      $decoded = json_decode($rawBody, TRUE);
      if (!is_array($decoded)) {
        return new JsonResponse([
          'isValid' => FALSE,
          'isSuccessful' => FALSE,
          'issues' => [
            $this->buildValidationIssue('body', 'invalid_json', 'Invalid JSON body.'),
          ],
          'summary' => [
            'errorCount' => 1,
            'warningCount' => 0,
          ],
        ], 400);
      }
      $payload = $decoded;
    }

    $studyUri = trim((string) ($payload['studyUri'] ?? ($query['studyUri'] ?? '')));
    $processUri = trim((string) ($payload['processUri'] ?? ($query['processUri'] ?? '')));
    $toolUri = trim((string) ($payload['toolUri'] ?? ($query['toolUri'] ?? '')));
    $entrypoint = trim((string) ($payload['entrypoint'] ?? ($query['entrypoint'] ?? '')));

    $validateOnlyRaw = $payload['validateOnly'] ?? ($query['validateOnly'] ?? FALSE);
    $validateOnly = filter_var($validateOnlyRaw, FILTER_VALIDATE_BOOLEAN);

    $issues = [];

    if (!$this->isUri($studyUri)) {
      $issues[] = $this->buildValidationIssue('studyUri', 'missing_or_invalid_study_uri', 'A valid study URI is required.');
    }

    if (!$this->isUri($processUri)) {
      $issues[] = $this->buildValidationIssue('processUri', 'missing_or_invalid_process_uri', 'A valid process URI is required.');
    }

    if (!$this->isUri($toolUri)) {
      $issues[] = $this->buildValidationIssue('toolUri', 'missing_or_invalid_tool_uri', 'A valid analytical tool URI is required.');
    }

    if ($entrypoint !== '' && strlen($entrypoint) > 120) {
      $issues[] = $this->buildValidationIssue('entrypoint', 'invalid_entrypoint', 'Entrypoint must be 120 characters or fewer.');
    }

    $arguments = [];
    $argumentsRaw = $payload['arguments'] ?? ($query['arguments'] ?? '');
    if (is_string($argumentsRaw)) {
      $argumentsRaw = trim($argumentsRaw);
      if ($argumentsRaw !== '') {
        $decodedArguments = json_decode($argumentsRaw, TRUE);
        if (!is_array($decodedArguments)) {
          $issues[] = $this->buildValidationIssue('arguments', 'invalid_arguments_json', 'Arguments must be a valid JSON object.');
        }
        else {
          $arguments = $decodedArguments;
        }
      }
    }
    elseif (is_array($argumentsRaw)) {
      $arguments = $argumentsRaw;
    }
    else {
      $issues[] = $this->buildValidationIssue('arguments', 'invalid_arguments_type', 'Arguments must be an object or JSON string.');
    }

    $catalog = $this->loadAnalyticalToolsCatalog();
    $tool = [];
    if ($toolUri !== '' && isset($catalog[$toolUri]) && is_array($catalog[$toolUri])) {
      $tool = $catalog[$toolUri];
    }
    elseif ($toolUri !== '') {
      $issues[] = $this->buildValidationIssue('toolUri', 'tool_not_found', 'Tool URI was not found in the analytical tools catalog.');
    }

    if (!empty($tool)) {
      $language = strtolower(trim((string) ($tool['language'] ?? '')));
      if ($language !== 'r') {
        $issues[] = $this->buildValidationIssue('toolUri', 'invalid_tool_language', 'Selected tool must have language "R".');
      }
    }

    $associations = $this->getEmptyAssociations();
    $storedProcessUri = NULL;
    if ($this->isUri($studyUri)) {
      $associations = $this->loadStudyAssociations($studyUri);

      $storedProcess = \Drupal::state()->get('ctt.study_process.' . sha1($studyUri));
      if (is_string($storedProcess) && trim($storedProcess) !== '') {
        $storedProcessUri = trim($storedProcess);
      }
      if ($storedProcessUri !== NULL && $this->isUri($processUri) && $storedProcessUri !== $processUri) {
        $issues[] = $this->buildValidationIssue('processUri', 'study_process_mismatch', 'Provided process URI differs from the process currently associated with this study.', 'warning');
      }

      $associationCount = (int) ($associations['counts']['datasets'] ?? 0)
        + (int) ($associations['counts']['variables'] ?? 0)
        + (int) ($associations['counts']['images'] ?? 0);
      if ($associationCount === 0) {
        $issues[] = $this->buildValidationIssue('associations', 'no_study_associations', 'No persisted dataset, variable, or image associations were found for this study.', 'warning');
      }
    }

    if (!$validateOnly) {
      $arguments = $this->ensureExecuteRscriptArgs($arguments, $studyUri, $tool, $request, $issues);
    }

    $endpointPath = $this->getRAnalysisEndpointPath();
    $isEngineRunEndpoint = preg_match('#/hascoapi/api/r-analysis/engine/run$#', $endpointPath) === 1;

    $engineInputCsvBase64 = '';
    if (!$validateOnly && $isEngineRunEndpoint) {
      $engineInputCsvBase64 = $this->buildEngineInputCsvBase64($studyUri, $arguments);
      if ($engineInputCsvBase64 === '') {
        $issues[] = $this->buildValidationIssue(
          'arguments.selectedDatasetUri',
          'engine_input_dataset_unavailable',
          'Unable to load selected Scenario dataset CSV for R engine execution.'
        );
      }
    }

    $errorCount = 0;
    foreach ($issues as $issue) {
      if (($issue['severity'] ?? 'error') === 'error') {
        $errorCount++;
      }
    }

    if ($errorCount > 0) {
      return new JsonResponse([
        'isValid' => FALSE,
        'isSuccessful' => FALSE,
        'issues' => $issues,
        'summary' => [
          'errorCount' => $errorCount,
          'warningCount' => count($issues) - $errorCount,
        ],
      ], 400);
    }

    $resolvedEntrypoint = $entrypoint;
    if ($resolvedEntrypoint === '' && !empty($tool)) {
      $resolvedEntrypoint = trim((string) ($tool['entrypoint'] ?? ''));
    }

    if ($resolvedEntrypoint === '') {
      $resolvedEntrypoint = trim((string) ($tool['sourceFilename'] ?? ''));
    }

    if ($resolvedEntrypoint === '') {
      $resolvedEntrypoint = trim((string) ($tool['artifactFilename'] ?? ''));
    }

    if ($resolvedEntrypoint === '') {
      $resolvedEntrypoint = 'inline.R';
    }

    $requestPayload = [
      'studyUri' => $studyUri,
      'processUri' => $processUri,
      'tool' => [
        'toolUri' => $this->normalizeContainerReachableUri((string) ($tool['toolUri'] ?? $toolUri)),
        'name' => (string) ($tool['name'] ?? ''),
        'version' => (string) ($tool['version'] ?? ''),
        'language' => (string) ($tool['language'] ?? ''),
        'artifactUri' => $this->normalizeContainerReachableUri((string) ($tool['artifactUri'] ?? '')),
        'artifactFilename' => (string) ($tool['artifactFilename'] ?? ''),
        'sourceFilename' => (string) ($tool['sourceFilename'] ?? ''),
        'sourceCodeEncoding' => (string) ($tool['sourceCodeEncoding'] ?? 'text/plain'),
        'inlineCode' => (string) ($tool['sourceCode'] ?? ''),
        'sourceRepositoryUri' => (string) ($tool['sourceRepositoryUri'] ?? ''),
        'entrypoint' => $resolvedEntrypoint,
      ],
      'associations' => $associations,
      'arguments' => $arguments,
      'requestedAt' => gmdate('c'),
      'requestedBy' => [
        'uid' => (string) $this->currentUser()->id(),
        'identifier' => $this->getCurrentUserIdentifier(),
      ],
    ];

    if ($isEngineRunEndpoint && $engineInputCsvBase64 !== '') {
      $requestPayload['inputCsvBase64'] = $engineInputCsvBase64;
    }

    $timeoutSeconds = $this->getRAnalysisTimeoutSeconds();

    if ($validateOnly) {
      return new JsonResponse([
        'isValid' => TRUE,
        'isSuccessful' => TRUE,
        'executed' => FALSE,
        'issues' => $issues,
        'summary' => [
          'errorCount' => 0,
          'warningCount' => count($issues),
        ],
        'execution' => [
          'backendEndpoint' => $endpointPath,
          'timeoutSeconds' => $timeoutSeconds,
          'validateOnly' => TRUE,
        ],
        'preparedRequest' => $requestPayload,
      ]);
    }

    $runId = 'RA' . strtoupper(substr(sha1($studyUri . '|' . $processUri . '|' . $toolUri . '|' . microtime(TRUE)), 0, 12));
    $startedAt = gmdate('c');

    $history = $this->loadRAnalysisRunHistory($studyUri);
    array_unshift($history, [
      'runId' => $runId,
      'studyUri' => $studyUri,
      'processUri' => $processUri,
      'toolUri' => $toolUri,
      'requestedAt' => $startedAt,
      'startedAt' => $startedAt,
      'finishedAt' => '',
      'status' => 'running',
      'requestedBy' => $this->getCurrentUserIdentifier(),
      'backendEndpoint' => $endpointPath,
      'resultUri' => '',
    ]);
    $this->saveRAnalysisRunHistory($studyUri, $history);

    try {
      $upstream = $this->hascoClient->proxyRequest('POST', $endpointPath, [
        'json' => $requestPayload,
        'timeout' => $timeoutSeconds,
        'connect_timeout' => min(10, $timeoutSeconds),
      ]);

      $outputPaths = $this->extractGeneratedOutputPathsFromUpstreamPayload($upstream);
      $recentPaths = $this->discoverRecentStudyDaOutputPaths($studyUri, $startedAt);
      $outputPaths = array_values(array_unique(array_merge($outputPaths, $recentPaths)));
      $outputRouting = $this->routeGeneratedOutputFilesToScenarioContent($studyUri, $outputPaths, $startedAt);
      $associationUpdate = $this->persistGeneratedDatasetAssociations($studyUri, $outputRouting['keptDataFiles'] ?? [], $request);
      $createdFiles = $this->buildCreatedFilesFromOutputRouting($outputRouting);
      $effectiveAssociations = $this->loadStudyAssociations($studyUri);
      $effectiveAssociationCount = (int) ($effectiveAssociations['counts']['datasets'] ?? 0)
        + (int) ($effectiveAssociations['counts']['variables'] ?? 0)
        + (int) ($effectiveAssociations['counts']['images'] ?? 0);
      if ($effectiveAssociationCount > 0) {
        $issues = $this->removeIssueByCode($issues, 'no_study_associations');
      }

      $history = $this->loadRAnalysisRunHistory($studyUri);
      $historyIndex = $this->findRunHistoryIndex($history, $runId, $processUri, $toolUri);
      if ($historyIndex >= 0) {
        $resultUri = $this->extractResultUriFromUpstreamPayload($upstream);
        $history[$historyIndex]['status'] = 'completed';
        $history[$historyIndex]['finishedAt'] = gmdate('c');
        $history[$historyIndex]['resultUri'] = $resultUri;
        $history[$historyIndex]['executionPayload'] = is_array($upstream) ? $upstream : [];
        $this->saveRAnalysisRunHistory($studyUri, $history);
      }

      $this->recordAnalyticalToolExecutionUsage($toolUri, $studyUri, $processUri, is_array($upstream) ? $upstream : []);

      $resultUri = $this->extractResultUriFromUpstreamPayload($upstream);

      return new JsonResponse([
        'isValid' => TRUE,
        'isSuccessful' => TRUE,
        'executed' => TRUE,
        'issues' => $issues,
        'summary' => [
          'errorCount' => 0,
          'warningCount' => count($issues),
        ],
        'execution' => [
          'runId' => $runId,
          'startedAt' => $startedAt,
          'finishedAt' => gmdate('c'),
          'status' => 'completed',
          'resultUri' => $resultUri,
          'backendEndpoint' => $endpointPath,
          'timeoutSeconds' => $timeoutSeconds,
        ],
        'createdFiles' => $createdFiles,
        'contentsInstructions' => [
          'media' => 'Generated PNG charts are listed under Contents > Media.',
          'dataFiles' => 'Generated CSV statistics are listed under Contents > Unassociated Data Files.',
        ],
        'outputRouting' => $outputRouting,
        'associationsUpdate' => $associationUpdate,
        'upstream' => $upstream,
      ]);
    }
    catch (\Throwable $e) {
      $lowerMessage = strtolower((string) $e->getMessage());
      $upstreamStatus = (int) $e->getCode();
      $backendUnavailable = strpos($lowerMessage, 'not configured') !== FALSE
        || strpos($lowerMessage, 'missing/invalid rep.settings.api_url') !== FALSE;
      $endpointNotFound = $upstreamStatus === 404;

      $issueCode = 'upstream_execution_failed';
      if ($backendUnavailable) {
        $issueCode = 'r_backend_unavailable';
      }
      elseif ($endpointNotFound) {
        $issueCode = 'upstream_endpoint_not_found';
      }

      $issues[] = $this->buildValidationIssue(
        'upstream',
        $issueCode,
        'R execution backend failed: ' . $e->getMessage()
      );

      $warningCount = 0;
      foreach ($issues as $issue) {
        if (($issue['severity'] ?? 'error') !== 'error') {
          $warningCount++;
        }
      }

      $history = $this->loadRAnalysisRunHistory($studyUri);
      $historyIndex = $this->findRunHistoryIndex($history, $runId, $processUri, $toolUri);
      if ($historyIndex >= 0) {
        $history[$historyIndex]['status'] = 'failed';
        $history[$historyIndex]['finishedAt'] = gmdate('c');
        $history[$historyIndex]['errorMessage'] = (string) $e->getMessage();
        $this->saveRAnalysisRunHistory($studyUri, $history);
      }

      return new JsonResponse([
        'isValid' => TRUE,
        'isSuccessful' => FALSE,
        'executed' => TRUE,
        'issues' => $issues,
        'summary' => [
          'errorCount' => count($issues) - $warningCount,
          'warningCount' => $warningCount,
        ],
        'execution' => [
          'backendEndpoint' => $endpointPath,
          'timeoutSeconds' => $timeoutSeconds,
          'runId' => $runId,
          'startedAt' => $startedAt,
          'finishedAt' => gmdate('c'),
          'status' => 'failed',
          'upstreamHttpStatus' => $upstreamStatus > 0 ? $upstreamStatus : NULL,
        ],
      ], $backendUnavailable ? 503 : 502);
    }
  }

  /**
   * Abort one R analysis run by runId or latest running run for process/tool.
   */
  public function abortRAnalysisRun(Request $request) {
    $payload = [];
    $rawBody = trim((string) $request->getContent());
    if ($rawBody !== '') {
      $decoded = json_decode($rawBody, TRUE);
      if (is_array($decoded)) {
        $payload = $decoded;
      }
    }

    $query = $request->query->all();
    $studyUri = trim((string) ($payload['studyUri'] ?? ($query['studyUri'] ?? '')));
    $processUri = trim((string) ($payload['processUri'] ?? ($query['processUri'] ?? '')));
    $toolUri = trim((string) ($payload['toolUri'] ?? ($query['toolUri'] ?? '')));
    $runId = trim((string) ($payload['runId'] ?? ($query['runId'] ?? '')));

    if (!$this->isUri($studyUri)) {
      return new JsonResponse([
        'isSuccessful' => FALSE,
        'issues' => [
          $this->buildValidationIssue('studyUri', 'missing_or_invalid_study_uri', 'A valid study URI is required.'),
        ],
      ], 400);
    }

    $history = $this->loadRAnalysisRunHistory($studyUri);
    if (empty($history)) {
      return new JsonResponse([
        'isSuccessful' => FALSE,
        'issues' => [
          $this->buildValidationIssue('run', 'run_not_found', 'No execution runs were found for this study.'),
        ],
      ], 404);
    }

    $historyIndex = $this->findRunHistoryIndex($history, $runId, $processUri, $toolUri);
    if ($historyIndex < 0 || !isset($history[$historyIndex]) || !is_array($history[$historyIndex])) {
      return new JsonResponse([
        'isSuccessful' => FALSE,
        'issues' => [
          $this->buildValidationIssue('run', 'run_not_found', 'No matching running execution was found.'),
        ],
      ], 404);
    }

    $run = $history[$historyIndex];
    $status = strtolower(trim((string) ($run['status'] ?? '')));
    if (!in_array($status, ['running', 'queued'], TRUE)) {
      return new JsonResponse([
        'isSuccessful' => FALSE,
        'issues' => [
          $this->buildValidationIssue('run', 'run_not_abortable', 'Run is not in an abortable state.'),
        ],
        'execution' => [
          'runId' => (string) ($run['runId'] ?? ''),
          'status' => (string) ($run['status'] ?? ''),
        ],
      ], 409);
    }

    $history[$historyIndex]['status'] = 'aborted';
    $history[$historyIndex]['finishedAt'] = gmdate('c');
    $history[$historyIndex]['abortedAt'] = (string) $history[$historyIndex]['finishedAt'];
    $history[$historyIndex]['abortedBy'] = $this->getCurrentUserIdentifier();
    $this->saveRAnalysisRunHistory($studyUri, $history);

    return new JsonResponse([
      'isSuccessful' => TRUE,
      'execution' => [
        'runId' => (string) ($history[$historyIndex]['runId'] ?? ''),
        'status' => 'aborted',
        'abortedAt' => (string) ($history[$historyIndex]['abortedAt'] ?? ''),
      ],
    ]);
  }

  /**
   * Read/update the analytical tools repository catalog.
   *
   * Read mode (default):
   *  - GET /workflow/api/repo/analytical-tools
   *
   * Upsert mode:
   *  - POST JSON body, or
   *  - GET with action=upsert and tool fields in query params.
  *
  * Remove mode:
    *  - POST/GET with action=remove and toolUri.
   */
  public function analyticalToolsRepository(Request $request) {
    $payload = [];
    $rawBody = trim((string) $request->getContent());
    if ($rawBody !== '') {
      $decoded = json_decode($rawBody, TRUE);
      if (!is_array($decoded)) {
        return new JsonResponse([
          'isValid' => FALSE,
          'issues' => [
            $this->buildValidationIssue('body', 'invalid_json', 'Invalid JSON body.'),
          ],
        ], 400);
      }
      $payload = $decoded;
    }

    $query = $request->query->all();
    $action = strtolower(trim((string) ($payload['action'] ?? ($query['action'] ?? ''))));

    $catalog = $this->loadAnalyticalToolsCatalog();

    $writeAction = '';
    if (in_array($action, ['upsert', 'remove'], TRUE)) {
      $writeAction = $action;
    }
    elseif ($request->isMethod('POST')) {
      // Backward compatibility: POST without explicit action still means upsert.
      $writeAction = 'upsert';
    }

    if ($action === 'associate' || $action === 'dissociate') {
      return new JsonResponse([
        'isSuccessful' => FALSE,
        'updated' => FALSE,
        'issues' => [
          $this->buildValidationIssue('action', 'deprecated_association_action', 'associate/dissociate actions were removed. Tools are now process-scoped.'),
        ],
      ], 400);
    }

    if ($writeAction === 'remove') {
      $toolUri = trim((string) ($payload['toolUri'] ?? ($query['toolUri'] ?? '')));
      if ($toolUri === '' || !$this->isUri($toolUri)) {
        return new JsonResponse([
          'isSuccessful' => FALSE,
          'issues' => [
            $this->buildValidationIssue('toolUri', 'missing_or_invalid_tool_uri', 'Tool URI must be a valid HTTP(S) URI.'),
          ],
        ], 400);
      }

      if (!isset($catalog[$toolUri])) {
        return new JsonResponse([
          'isSuccessful' => FALSE,
          'issues' => [
            $this->buildValidationIssue('toolUri', 'tool_not_found', 'Tool URI was not found in the analytical tools catalog.'),
          ],
        ], 404);
      }

      $ownerGuard = $this->enforceToolOwnerForMutation($catalog[$toolUri]);
      if ($ownerGuard instanceof JsonResponse) {
        return $ownerGuard;
      }

      $usageSnapshot = $this->getAnalyticalToolUsageSnapshot($toolUri);
      $runCount = (int) ($usageSnapshot['runCount'] ?? 0);
      $derivedDatasetCount = (int) ($usageSnapshot['derivedDatasetCount'] ?? 0);

      if ($runCount > 0 && $derivedDatasetCount > 0) {
        return new JsonResponse([
          'isSuccessful' => FALSE,
          'updated' => FALSE,
          'issues' => [
            $this->buildValidationIssue(
              'toolUri',
              'tool_in_use_with_derived_datasets',
              'Tool cannot be deleted because it has execution usage and derived datasets exist.'
            ),
          ],
          'usage' => $usageSnapshot,
        ], 409);
      }

      $removedTool = $catalog[$toolUri];
      unset($catalog[$toolUri]);
      $this->saveAnalyticalToolsCatalog($catalog);

      $usageRegistry = $this->loadAnalyticalToolUsageRegistry();
      if (isset($usageRegistry[$toolUri])) {
        unset($usageRegistry[$toolUri]);
        $this->saveAnalyticalToolUsageRegistry($usageRegistry);
      }

      $associationStatesUpdated = $this->removeToolFromAllStudyToolAssociations($toolUri);

      return new JsonResponse([
        'isSuccessful' => TRUE,
        'updated' => TRUE,
        'action' => 'remove',
        'removedToolUri' => $toolUri,
        'removedTool' => $removedTool,
        'catalogSize' => count($catalog),
        'associationStatesUpdated' => $associationStatesUpdated,
        'usage' => $usageSnapshot,
        'issues' => [],
      ]);
    }

    $isUpsert = $writeAction === 'upsert';

    if (!$isUpsert) {
      $q = strtolower(trim((string) ($query['q'] ?? '')));
      $statusFilter = strtolower(trim((string) ($query['status'] ?? '')));
      $languageFilter = strtolower(trim((string) ($query['language'] ?? '')));
      $ownerFilter = strtolower(trim((string) ($query['owner'] ?? '')));
      $processUriFilter = trim((string) ($query['processUri'] ?? ''));
      $authorFilter = strtolower(trim((string) ($query['author'] ?? '')));
      $institutionFilter = strtolower(trim((string) ($query['institution'] ?? '')));
      $scenarioUriFilter = strtolower(trim((string) ($query['scenarioUri'] ?? '')));
      $datasetUriFilter = strtolower(trim((string) ($query['datasetUri'] ?? '')));
      $dateFromFilter = trim((string) ($query['dateFrom'] ?? ''));
      $dateToFilter = trim((string) ($query['dateTo'] ?? ''));
      $tagFilter = strtolower(trim((string) ($query['tag'] ?? '')));

      if ($processUriFilter !== '' && !$this->isUri($processUriFilter)) {
        return new JsonResponse([
          'isSuccessful' => FALSE,
          'issues' => [
            $this->buildValidationIssue('processUri', 'missing_or_invalid_process_uri', 'Process URI must be a valid HTTP(S) URI.'),
          ],
        ], 400);
      }

      if ($dateFromFilter !== '' && !$this->isIsoDate($dateFromFilter)) {
        return new JsonResponse([
          'isSuccessful' => FALSE,
          'issues' => [
            $this->buildValidationIssue('dateFrom', 'invalid_date_from', 'dateFrom must use YYYY-MM-DD format.'),
          ],
        ], 400);
      }

      if ($dateToFilter !== '' && !$this->isIsoDate($dateToFilter)) {
        return new JsonResponse([
          'isSuccessful' => FALSE,
          'issues' => [
            $this->buildValidationIssue('dateTo', 'invalid_date_to', 'dateTo must use YYYY-MM-DD format.'),
          ],
        ], 400);
      }

      $usageRegistry = $this->loadAnalyticalToolUsageRegistry();

      $tools = array_values($catalog);
      usort($tools, function (array $a, array $b): int {
        $left = (string) ($a['updatedAt'] ?? $a['createdAt'] ?? '');
        $right = (string) ($b['updatedAt'] ?? $b['createdAt'] ?? '');
        if ($left === $right) {
          return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        }
        return strcmp($right, $left);
      });

      $filtered = [];
      foreach ($tools as $tool) {
        $name = (string) ($tool['name'] ?? '');
        $description = (string) ($tool['description'] ?? '');
        $language = strtolower((string) ($tool['language'] ?? ''));
        $status = strtolower((string) ($tool['status'] ?? ''));
        $ownerEmail = strtolower(trim((string) ($tool['ownerUserEmail'] ?? $tool['createdBy'] ?? '')));
        $processUri = trim((string) ($tool['processUri'] ?? ''));
        $author = strtolower(trim((string) ($tool['author'] ?? '')));
        $institution = strtolower(trim((string) ($tool['institution'] ?? '')));
        $scenarioUri = strtolower(trim((string) ($tool['scenarioUri'] ?? '')));
        $datasetUri = strtolower(trim((string) ($tool['datasetUri'] ?? '')));
        $releaseDate = trim((string) ($tool['releaseDate'] ?? ''));
        $tags = $this->normalizeToolTags($tool['tags'] ?? []);

        if ($statusFilter !== '' && $status !== $statusFilter) {
          continue;
        }
        if ($languageFilter !== '' && $language !== $languageFilter) {
          continue;
        }
        if ($ownerFilter !== '' && strpos($ownerEmail, $ownerFilter) === FALSE) {
          continue;
        }
        if ($processUriFilter !== ''
          && strcasecmp($processUri, $processUriFilter) !== 0
          && !$this->isGlobalProcessWildcard($processUri)) {
          continue;
        }
        if ($authorFilter !== '' && strpos($author, $authorFilter) === FALSE) {
          continue;
        }
        if ($institutionFilter !== '' && strpos($institution, $institutionFilter) === FALSE) {
          continue;
        }
        // Treat tools without scenarioUri as globally scoped to any scenario.
        if ($scenarioUriFilter !== '' && $scenarioUri !== '' && $scenarioUri !== $scenarioUriFilter) {
          continue;
        }
        if ($datasetUriFilter !== '' && $datasetUri !== $datasetUriFilter) {
          continue;
        }

        $effectiveDate = $releaseDate;
        if ($effectiveDate === '') {
          $fallbackDateSource = (string) ($tool['updatedAt'] ?? $tool['createdAt'] ?? '');
          $effectiveDate = $fallbackDateSource !== '' ? substr($fallbackDateSource, 0, 10) : '';
        }

        if ($dateFromFilter !== '' && ($effectiveDate === '' || $effectiveDate < $dateFromFilter)) {
          continue;
        }

        if ($dateToFilter !== '' && ($effectiveDate === '' || $effectiveDate > $dateToFilter)) {
          continue;
        }

        if ($tagFilter !== '') {
          $hasTag = FALSE;
          foreach ($tags as $tag) {
            if (strtolower($tag) === $tagFilter) {
              $hasTag = TRUE;
              break;
            }
          }
          if (!$hasTag) {
            continue;
          }
        }

        if ($q !== '') {
          $searchBlob = strtolower(implode(' ', [
            (string) ($tool['toolUri'] ?? ''),
            (string) ($tool['processUri'] ?? ''),
            (string) ($tool['ownerUserEmail'] ?? ''),
            $name,
            $description,
            $language,
            (string) ($tool['author'] ?? ''),
            (string) ($tool['institution'] ?? ''),
            (string) ($tool['scenarioUri'] ?? ''),
            (string) ($tool['datasetUri'] ?? ''),
            (string) ($tool['version'] ?? ''),
            (string) ($tool['artifactFilename'] ?? ''),
            implode(' ', $tags),
          ]));
          if (strpos($searchBlob, $q) === FALSE) {
            continue;
          }
        }

        $tool['tags'] = $tags;
        $toolUri = trim((string) ($tool['toolUri'] ?? ''));
        $usage = [];
        if ($toolUri !== '' && isset($usageRegistry[$toolUri]) && is_array($usageRegistry[$toolUri])) {
          $usage = $usageRegistry[$toolUri];
        }

        $derivedDatasetUris = $this->normalizeUriList(is_array($usage['derivedDatasetUris'] ?? NULL) ? $usage['derivedDatasetUris'] : []);
        $runCount = (int) ($usage['runCount'] ?? 0);
        $deletionBlocked = $runCount > 0 && count($derivedDatasetUris) > 0;

        $tool['canUpdate'] = $this->canCurrentUserMutateTool($tool);
        $tool['canDelete'] = $tool['canUpdate'] && !$deletionBlocked;
        $tool['deletionBlocked'] = $deletionBlocked;
        $tool['deletionBlockReason'] = $deletionBlocked
          ? 'Tool has execution usage and derived datasets.'
          : '';
        $tool['usage'] = [
          'runCount' => $runCount,
          'lastRunAt' => (string) ($usage['lastRunAt'] ?? ''),
          'lastStudyUri' => (string) ($usage['lastStudyUri'] ?? ''),
          'lastProcessUri' => (string) ($usage['lastProcessUri'] ?? ''),
          'derivedDatasetUris' => $derivedDatasetUris,
          'derivedDatasetCount' => count($derivedDatasetUris),
        ];

        $toolProcessUri = trim((string) ($tool['processUri'] ?? ''));
        $tool['processLabel'] = $toolProcessUri !== ''
          ? $this->resolveProcessLabelFromUri($toolProcessUri)
          : '';

        $filtered[] = $tool;
      }

      $statusCounts = [];
      $languageCounts = [];
      foreach ($filtered as $tool) {
        $status = strtolower((string) ($tool['status'] ?? 'unknown'));
        $language = strtolower(trim((string) ($tool['language'] ?? '')));
        if ($language === '') {
          $language = 'unspecified';
        }

        if (!isset($statusCounts[$status])) {
          $statusCounts[$status] = 0;
        }
        if (!isset($languageCounts[$language])) {
          $languageCounts[$language] = 0;
        }
        $statusCounts[$status]++;
        $languageCounts[$language]++;
      }

      $limit = (int) ($query['limit'] ?? 50);
      $offset = (int) ($query['offset'] ?? 0);
      if ($limit <= 0) {
        $limit = 50;
      }
      $limit = min($limit, 200);
      if ($offset < 0) {
        $offset = 0;
      }

      $total = count($filtered);
      $paged = array_slice($filtered, $offset, $limit);

      return new JsonResponse([
        'isSuccessful' => TRUE,
        'body' => array_values($paged),
        'pagination' => [
          'total' => $total,
          'offset' => $offset,
          'limit' => $limit,
          'returned' => count($paged),
        ],
        'summary' => [
          'statusCounts' => $statusCounts,
          'languageCounts' => $languageCounts,
        ],
        'collection' => [
          'processUri' => $processUriFilter !== '' ? $processUriFilter : NULL,
          'owner' => $ownerFilter !== '' ? $ownerFilter : NULL,
        ],
      ]);
    }

    $input = [];
    if (isset($payload['tool']) && is_array($payload['tool'])) {
      $input = $payload['tool'];
    }
    elseif (!empty($payload)) {
      $input = $payload;
    }
    else {
      $input = $query;
    }

    $candidateToolUri = trim((string) ($input['toolUri'] ?? ''));
    $existingTool = ($candidateToolUri !== '' && isset($catalog[$candidateToolUri]) && is_array($catalog[$candidateToolUri]))
      ? $catalog[$candidateToolUri]
      : [];

    if (!empty($existingTool)) {
      $ownerGuard = $this->enforceToolOwnerForMutation($existingTool);
      if ($ownerGuard instanceof JsonResponse) {
        return $ownerGuard;
      }
    }

    $normalized = $this->normalizeAnalyticalToolPayload($input, $existingTool);
    $issues = $normalized['issues'];
    if (!empty($issues)) {
      return new JsonResponse([
        'isValid' => FALSE,
        'updated' => FALSE,
        'issues' => $issues,
        'summary' => [
          'errorCount' => count($issues),
          'warningCount' => 0,
        ],
      ], 400);
    }

    $tool = $normalized['tool'];
    $toolUri = (string) ($tool['toolUri'] ?? '');
    $isNew = !isset($catalog[$toolUri]);

    $lineageUri = trim((string) ($tool['lineageUri'] ?? ''));
    $autoDeprecatedUris = [];

    if ($lineageUri !== '' && strtolower((string) ($tool['status'] ?? '')) === 'current') {
      foreach ($catalog as $existingUri => $existingEntry) {
        if ($existingUri === $toolUri || !is_array($existingEntry)) {
          continue;
        }
        if (trim((string) ($existingEntry['lineageUri'] ?? '')) !== $lineageUri) {
          continue;
        }

        $existingStatus = strtolower(trim((string) ($existingEntry['status'] ?? '')));
        if ($existingStatus === 'current') {
          $catalog[$existingUri]['status'] = 'deprecated';
          $catalog[$existingUri]['updatedAt'] = gmdate('c');
          $catalog[$existingUri]['updatedByUid'] = (string) $this->currentUser()->id();
          $catalog[$existingUri]['updatedBy'] = (string) ($tool['updatedBy'] ?? $this->currentUser()->getDisplayName());
          $autoDeprecatedUris[] = $existingUri;
        }
      }
    }

    $catalog[$toolUri] = $tool;

    if ($lineageUri !== '') {
      $latestUri = $toolUri;
      $latestScore = '';

      foreach ($catalog as $existingUri => $existingEntry) {
        if (!is_array($existingEntry) || trim((string) ($existingEntry['lineageUri'] ?? '')) !== $lineageUri) {
          continue;
        }

        $score = trim((string) ($existingEntry['releaseDate'] ?? ''));
        if ($score === '') {
          $score = (string) ($existingEntry['updatedAt'] ?? $existingEntry['createdAt'] ?? '');
        }

        if ($score >= $latestScore) {
          $latestScore = $score;
          $latestUri = $existingUri;
        }
      }

      foreach ($catalog as $existingUri => $existingEntry) {
        if (!is_array($existingEntry) || trim((string) ($existingEntry['lineageUri'] ?? '')) !== $lineageUri) {
          continue;
        }

        $catalog[$existingUri]['isLatestVersion'] = ($existingUri === $latestUri);
      }
    }

    $this->saveAnalyticalToolsCatalog($catalog);

    $tool = $catalog[$toolUri];
    $toolProcessUri = trim((string) ($tool['processUri'] ?? ''));
    $tool['processLabel'] = $toolProcessUri !== ''
      ? $this->resolveProcessLabelFromUri($toolProcessUri)
      : '';

    return new JsonResponse([
      'isValid' => TRUE,
      'updated' => TRUE,
      'created' => $isNew,
      'tool' => $tool,
      'catalogSize' => count($catalog),
      'versioning' => [
        'lineageUri' => $lineageUri !== '' ? $lineageUri : NULL,
        'autoDeprecatedToolUris' => $autoDeprecatedUris,
      ],
      'issues' => [],
      'summary' => [
        'errorCount' => 0,
        'warningCount' => 0,
      ],
    ]);
  }

  /**
   * Read/update structured submission resource associations for a study.
   */
  public function submissionAssociations(Request $request) {
    $payload = [];
    $rawBody = trim((string) $request->getContent());
    if ($rawBody !== '') {
      $decoded = json_decode($rawBody, TRUE);
      if (!is_array($decoded)) {
        return new JsonResponse([
          'isValid' => FALSE,
          'issues' => [
            $this->buildValidationIssue('body', 'invalid_json', 'Invalid JSON body.'),
          ],
        ], 400);
      }
      $payload = $decoded;
    }

    $studyUri = trim((string) ($payload['studyUri'] ?? $request->query->get('studyUri', '')));
    if (!$this->isUri($studyUri)) {
      return new JsonResponse([
        'isValid' => FALSE,
        'issues' => [
          $this->buildValidationIssue('studyUri', 'missing_or_invalid_study_uri', 'A valid study URI is required.'),
        ],
      ], 400);
    }

    $processUri = trim((string) ($payload['processUri'] ?? $request->query->get('processUri', '')));
    $storedAssociations = $this->loadStudyAssociations($studyUri);

    $associationInput = $this->extractAssociationInput($payload, $request);
    if ($associationInput['provided']) {
      $ownerGuard = $this->enforceStudyOwnerForMutation($studyUri);
      if ($ownerGuard instanceof JsonResponse) {
        return $ownerGuard;
      }
    }

    $studyDataAcquisitionFiles = $this->listStudyDataAcquisitionFiles($studyUri, $request);

    if (!$associationInput['provided']) {
      return new JsonResponse([
        'isValid' => TRUE,
        'updated' => FALSE,
        'issues' => [],
        'summary' => [
          'errorCount' => 0,
          'warningCount' => 0,
        ],
        'studyUri' => $studyUri,
        'processUri' => $processUri !== '' ? $processUri : NULL,
        'associations' => $storedAssociations,
        'studyDataAcquisitionFiles' => $studyDataAcquisitionFiles,
        'source' => 'stored',
      ]);
    }

    $normalizedAssociations = $this->normalizeAssociationPayload(
      $associationInput['datasetsRaw'],
      $associationInput['variablesRaw'],
      $associationInput['imagesRaw']
    );

    $issues = $normalizedAssociations['issues'];
    $errorCount = count($issues);
    $updated = FALSE;
    $effectiveAssociations = $storedAssociations;

    if ($errorCount === 0) {
      $this->saveStudyAssociations($studyUri, $normalizedAssociations['associations'], $processUri !== '' ? $processUri : NULL);
      $effectiveAssociations = $normalizedAssociations['associations'];
      $updated = TRUE;
    }

    return new JsonResponse([
      'isValid' => $errorCount === 0,
      'updated' => $updated,
      'issues' => $issues,
      'summary' => [
        'errorCount' => $errorCount,
        'warningCount' => 0,
      ],
      'studyUri' => $studyUri,
      'processUri' => $processUri !== '' ? $processUri : NULL,
      'associations' => $effectiveAssociations,
      'studyDataAcquisitionFiles' => $studyDataAcquisitionFiles,
      'source' => $updated ? 'request' : 'stored',
    ]);
  }

  /**
   * Read/update structured submission editorial status for a study.
   *
   * Supports:
   *  - GET query params
   *  - POST JSON body
   *
   * Read mode:
   *  - Provide only studyUri.
   *
   * Update mode:
   *  - Provide studyUri + requestedStatus.
   */
  public function submissionStatus(Request $request) {
    $payload = [];
    $rawBody = trim((string) $request->getContent());
    if ($rawBody !== '') {
      $decoded = json_decode($rawBody, TRUE);
      if (!is_array($decoded)) {
        return new JsonResponse([
          'isValid' => FALSE,
          'issues' => [
            $this->buildValidationIssue('body', 'invalid_json', 'Invalid JSON body.'),
          ],
        ], 400);
      }
      $payload = $decoded;
    }

    $studyUri = trim((string) ($payload['studyUri'] ?? $request->query->get('studyUri', '')));
    if (!$this->isUri($studyUri)) {
      return new JsonResponse([
        'isValid' => FALSE,
        'issues' => [
          $this->buildValidationIssue('studyUri', 'missing_or_invalid_study_uri', 'A valid study URI is required.'),
        ],
      ], 400);
    }

    $states = $this->getEditorialStates();
    $editorialTransitions = $this->getEditorialTransitions();

    $studyHash = sha1($studyUri);
    $statusKey = 'ctt.study_status.' . $studyHash;
    $metaKey = 'ctt.study_status_meta.' . $studyHash;
    $state = \Drupal::state();

    $storedStatusRaw = $state->get($statusKey);
    $storedStatus = is_string($storedStatusRaw) ? strtolower(trim($storedStatusRaw)) : '';
    if (!in_array($storedStatus, $states, TRUE)) {
      $storedStatus = 'draft';
      $state->set($statusKey, $storedStatus);
    }

    $storedProcessRaw = $state->get('ctt.study_process.' . $studyHash);
    $storedProcessUri = is_string($storedProcessRaw) && trim($storedProcessRaw) !== '' ? trim($storedProcessRaw) : NULL;
    $storedAssociations = $this->loadStudyAssociations($studyUri);

    $meta = $state->get($metaKey);
    if (!is_array($meta)) {
      $meta = [];
    }

    $associationInput = $this->extractAssociationInput($payload, $request);

    $requestedStatus = strtolower(trim((string) ($payload['requestedStatus'] ?? $request->query->get('requestedStatus', ''))));
    $providedProcessUri = trim((string) ($payload['processUri'] ?? $request->query->get('processUri', '')));

    if ($requestedStatus !== '') {
      $ownerGuard = $this->enforceStudyOwnerForMutation($studyUri);
      if ($ownerGuard instanceof JsonResponse) {
        return $ownerGuard;
      }
    }

    if ($requestedStatus === '') {
      return new JsonResponse([
        'isValid' => TRUE,
        'updated' => FALSE,
        'issues' => [],
        'summary' => [
          'errorCount' => 0,
          'warningCount' => 0,
        ],
        'studyUri' => $studyUri,
        'status' => $storedStatus,
        'editorial' => [
          'states' => $states,
          'currentStatus' => $storedStatus,
          'allowedTransitions' => $editorialTransitions,
          'allowedNextStatuses' => $editorialTransitions[$storedStatus] ?? [],
        ],
        'association' => [
          'studyProcess' => [
            'storedProcessUri' => $storedProcessUri,
            'providedProcessUri' => NULL,
            'matches' => NULL,
          ],
          'resources' => $storedAssociations,
          'resourcesSource' => 'stored',
        ],
        'meta' => $meta,
      ]);
    }

    $currentStatus = strtolower(trim((string) ($payload['currentStatus'] ?? $request->query->get('currentStatus', $storedStatus))));
    if ($currentStatus === '') {
      $currentStatus = $storedStatus;
    }

    $issues = [];

    if (!in_array($currentStatus, $states, TRUE)) {
      $issues[] = $this->buildValidationIssue('currentStatus', 'invalid_current_editorial_state', 'Current editorial state must be one of: ' . implode(', ', $states) . '.');
    }

    if (!in_array($requestedStatus, $states, TRUE)) {
      $issues[] = $this->buildValidationIssue('requestedStatus', 'invalid_editorial_state', 'Editorial state must be one of: ' . implode(', ', $states) . '.');
    }

    if ($providedProcessUri !== '' && !$this->isUri($providedProcessUri)) {
      $issues[] = $this->buildValidationIssue('processUri', 'invalid_process_uri', 'Process URI must be a valid HTTP(S) URI.');
    }

    if ($providedProcessUri !== '' && $storedProcessUri !== NULL && $storedProcessUri !== $providedProcessUri) {
      $issues[] = $this->buildValidationIssue('processUri', 'study_process_mismatch', 'Provided process URI differs from the process associated with this study.', 'warning');
    }

    if ($requestedStatus === 'current' && !$this->currentUser()->hasPermission('administer ctt')) {
      $issues[] = $this->buildValidationIssue('requestedStatus', 'current_requires_admin', 'Only administrators can set the editorial state to current.');
    }

    if (in_array($currentStatus, $states, TRUE) && in_array($requestedStatus, $states, TRUE) && $currentStatus !== $requestedStatus) {
      $allowedTransitions = $editorialTransitions[$currentStatus] ?? [];
      if (!in_array($requestedStatus, $allowedTransitions, TRUE)) {
        $issues[] = $this->buildValidationIssue(
          'requestedStatus',
          'invalid_editorial_transition',
          sprintf('Transition from "%s" to "%s" is not allowed.', $currentStatus, $requestedStatus)
        );
      }
    }

    if ($currentStatus !== $storedStatus) {
      $issues[] = $this->buildValidationIssue('currentStatus', 'study_status_mismatch', 'Provided currentStatus differs from the persisted study status.', 'warning');
    }

    $resolvedAssociations = $storedAssociations;
    if ($associationInput['provided']) {
      $normalizedAssociations = $this->normalizeAssociationPayload(
        $associationInput['datasetsRaw'],
        $associationInput['variablesRaw'],
        $associationInput['imagesRaw']
      );
      $issues = array_merge($issues, $normalizedAssociations['issues']);
      $resolvedAssociations = $normalizedAssociations['associations'];
    }

    $errorCount = 0;
    foreach ($issues as $issue) {
      if (($issue['severity'] ?? 'error') === 'error') {
        $errorCount++;
      }
    }

    $updated = FALSE;
    if ($errorCount === 0) {
      $state->set($statusKey, $requestedStatus);

      $account = $this->currentUser();
      $email = '';
      try {
        $user = \Drupal\user\Entity\User::load($account->id());
        if ($user) {
          $email = (string) $user->getEmail();
        }
      }
      catch (\Throwable $ignored) {
        // Ignore user lookup failures and fallback to display name/uid.
      }

      $meta = [
        'updatedAt' => gmdate('c'),
        'updatedByUid' => (string) $account->id(),
        'updatedBy' => $email !== '' ? $email : (string) $account->getDisplayName(),
        'previousStatus' => $storedStatus,
        'status' => $requestedStatus,
        'processUri' => $providedProcessUri !== '' ? $providedProcessUri : $storedProcessUri,
      ];
      $state->set($metaKey, $meta);

      $storedStatus = $requestedStatus;
      $updated = TRUE;

      if ($associationInput['provided']) {
        $persistedProcess = $providedProcessUri !== '' ? $providedProcessUri : $storedProcessUri;
        $this->saveStudyAssociations($studyUri, $resolvedAssociations, $persistedProcess);
        $storedAssociations = $resolvedAssociations;
      }
    }

    return new JsonResponse([
      'isValid' => $errorCount === 0,
      'updated' => $updated,
      'issues' => $issues,
      'summary' => [
        'errorCount' => $errorCount,
        'warningCount' => count($issues) - $errorCount,
      ],
      'studyUri' => $studyUri,
      'status' => $storedStatus,
      'editorial' => [
        'states' => $states,
        'currentStatus' => $storedStatus,
        'allowedTransitions' => $editorialTransitions,
        'allowedNextStatuses' => $editorialTransitions[$storedStatus] ?? [],
      ],
      'association' => [
        'studyProcess' => [
          'storedProcessUri' => $storedProcessUri,
          'providedProcessUri' => $providedProcessUri !== '' ? $providedProcessUri : NULL,
          'matches' => ($storedProcessUri !== NULL && $providedProcessUri !== '') ? $storedProcessUri === $providedProcessUri : NULL,
        ],
        'resources' => $storedAssociations,
        'resourcesSource' => ($associationInput['provided'] && $errorCount === 0) ? 'request' : 'stored',
      ],
      'meta' => $meta,
    ]);
  }

  /**
   * Validate structured submission consistency before workflow submission.
   *
   * Supports:
   *  - POST JSON body
   *  - GET query params
   */
  public function validateSubmission(Request $request) {
    $payload = [];
    $rawBody = trim((string) $request->getContent());
    if ($rawBody !== '') {
      $decoded = json_decode($rawBody, TRUE);
      if (!is_array($decoded)) {
        return new JsonResponse([
          'isValid' => FALSE,
          'issues' => [
            $this->buildValidationIssue('body', 'invalid_json', 'Invalid JSON body.'),
          ],
        ], 400);
      }
      $payload = $decoded;
    }

    $studyUri = trim((string) ($payload['studyUri'] ?? $request->query->get('studyUri', '')));
    $processUri = trim((string) ($payload['processUri'] ?? $request->query->get('processUri', '')));
    $requestedStatus = strtolower(trim((string) ($payload['requestedStatus'] ?? $request->query->get('requestedStatus', 'under review'))));
    $currentStatus = strtolower(trim((string) ($payload['currentStatus'] ?? $request->query->get('currentStatus', ''))));
    $mode = strtolower(trim((string) ($payload['mode'] ?? $request->query->get('mode', 'submission'))));
    $daUri = trim((string) ($payload['daUri'] ?? $request->query->get('daUri', '')));
    $dataFileUri = trim((string) ($payload['dataFileUri'] ?? $request->query->get('dataFileUri', '')));

    if ($requestedStatus === '') {
      $requestedStatus = 'under review';
    }
    if ($mode === '') {
      $mode = 'submission';
    }

    if ($mode !== 'create') {
      $ownerGuard = $this->enforceStudyOwnerForMutation($studyUri);
      if ($ownerGuard instanceof JsonResponse) {
        return $ownerGuard;
      }
    }

    if ($currentStatus === '' && $this->isUri($studyUri)) {
      $storedStudyStatus = \Drupal::state()->get('ctt.study_status.' . sha1($studyUri));
      if (is_string($storedStudyStatus) && trim($storedStudyStatus) !== '') {
        $currentStatus = strtolower(trim($storedStudyStatus));
      }
    }

    if ($currentStatus === '' && $mode === 'submission') {
      $currentStatus = 'draft';
    }

    $issues = [];
    $editorialTransitions = $this->getEditorialTransitions();
    $storedProcess = NULL;
    $storedAssociations = $this->getEmptyAssociations();
    if ($this->isUri($studyUri)) {
      $storedProcess = \Drupal::state()->get('ctt.study_process.' . sha1($studyUri));
      $storedAssociations = $this->loadStudyAssociations($studyUri);
    }

    $associationInput = $this->extractAssociationInput($payload, $request);
    $resolvedAssociations = $storedAssociations;
    if ($associationInput['provided']) {
      $normalizedAssociations = $this->normalizeAssociationPayload(
        $associationInput['datasetsRaw'],
        $associationInput['variablesRaw'],
        $associationInput['imagesRaw']
      );
      $issues = array_merge($issues, $normalizedAssociations['issues']);
      $resolvedAssociations = $normalizedAssociations['associations'];
    }

    if (!$this->isUri($studyUri)) {
      $issues[] = $this->buildValidationIssue('studyUri', 'missing_or_invalid_study_uri', 'A valid study URI is required.');
    }

    if (!$this->isUri($processUri)) {
      $issues[] = $this->buildValidationIssue('processUri', 'missing_or_invalid_process_uri', 'A valid process URI is required.');
    }

    $validModes = ['create', 'edit', 'submission', 'execution'];
    if (!in_array($mode, $validModes, TRUE)) {
      $issues[] = $this->buildValidationIssue('mode', 'invalid_mode', 'Mode must be one of: ' . implode(', ', $validModes) . '.');
    }

    $states = $this->getEditorialStates();
    if (!in_array($requestedStatus, $states, TRUE)) {
      $issues[] = $this->buildValidationIssue('requestedStatus', 'invalid_editorial_state', 'Editorial state must be one of: ' . implode(', ', $states) . '.');
    }

    if ($currentStatus !== '' && !in_array($currentStatus, $states, TRUE)) {
      $issues[] = $this->buildValidationIssue('currentStatus', 'invalid_current_editorial_state', 'Current editorial state must be one of: ' . implode(', ', $states) . '.');
    }

    if ($currentStatus !== '' && in_array($currentStatus, $states, TRUE) && in_array($requestedStatus, $states, TRUE) && $currentStatus !== $requestedStatus) {
      $allowedTransitions = $editorialTransitions[$currentStatus] ?? [];
      if (!in_array($requestedStatus, $allowedTransitions, TRUE)) {
        $issues[] = $this->buildValidationIssue(
          'requestedStatus',
          'invalid_editorial_transition',
          sprintf('Transition from "%s" to "%s" is not allowed.', $currentStatus, $requestedStatus)
        );
      }
    }

    if ($requestedStatus === 'current' && !$this->currentUser()->hasPermission('administer ctt')) {
      $issues[] = $this->buildValidationIssue('requestedStatus', 'current_requires_admin', 'Only administrators can set the editorial state to current.');
    }

    if ($mode === 'submission' && $daUri === '' && $dataFileUri === '') {
      $issues[] = $this->buildValidationIssue('output', 'missing_submission_output', 'Submission mode requires daUri or dataFileUri evidence.');
    }

    if ($mode === 'submission') {
      $associationCount = (int) ($resolvedAssociations['counts']['datasets'] ?? 0)
        + (int) ($resolvedAssociations['counts']['variables'] ?? 0)
        + (int) ($resolvedAssociations['counts']['images'] ?? 0);
      if ($associationCount === 0) {
        $issues[] = $this->buildValidationIssue('associations', 'missing_submission_associations', 'Submission should include dataset, variable, or medical image associations.', 'warning');
      }
    }

    if ($this->isUri($studyUri) && $this->isUri($processUri)) {
      if (is_string($storedProcess) && trim($storedProcess) !== '' && trim($storedProcess) !== $processUri) {
        $issues[] = $this->buildValidationIssue('processUri', 'study_process_mismatch', 'Provided process URI differs from the process associated with this study.', 'warning');
      }
      if ($mode === 'submission' && (!is_string($storedProcess) || trim($storedProcess) === '')) {
        $issues[] = $this->buildValidationIssue('processUri', 'study_process_not_associated', 'No process is currently associated with this study.', 'warning');
      }

      // WKF-SPEC-V2 hierarchy checks for process/task coherence.
      $issues = array_merge($issues, $this->validateProcessTaskHierarchy($processUri));
    }

    $storedProcessUri = NULL;
    if (is_string($storedProcess) && trim($storedProcess) !== '') {
      $storedProcessUri = trim($storedProcess);
    }

    $currentStatusNormalized = $currentStatus !== '' ? $currentStatus : NULL;
    $allowedNextStatuses = [];
    if ($currentStatusNormalized !== NULL && array_key_exists($currentStatusNormalized, $editorialTransitions)) {
      $allowedNextStatuses = $editorialTransitions[$currentStatusNormalized];
    }

    $errorCount = 0;
    foreach ($issues as $issue) {
      if (($issue['severity'] ?? 'error') === 'error') {
        $errorCount++;
      }
    }

    return new JsonResponse([
      'isValid' => $errorCount === 0,
      'issues' => $issues,
      'summary' => [
        'errorCount' => $errorCount,
        'warningCount' => count($issues) - $errorCount,
      ],
      'normalized' => [
        'studyUri' => $studyUri !== '' ? $studyUri : NULL,
        'processUri' => $processUri !== '' ? $processUri : NULL,
        'currentStatus' => $currentStatusNormalized,
        'requestedStatus' => $requestedStatus,
        'mode' => $mode,
        'daUri' => $daUri !== '' ? $daUri : NULL,
        'dataFileUri' => $dataFileUri !== '' ? $dataFileUri : NULL,
        'associations' => $resolvedAssociations,
      ],
      'editorial' => [
        'states' => $states,
        'allowedTransitions' => $editorialTransitions,
        'currentStatus' => $currentStatusNormalized,
        'allowedNextStatuses' => $allowedNextStatuses,
      ],
      'association' => [
        'studyProcess' => [
          'storedProcessUri' => $storedProcessUri,
          'providedProcessUri' => $processUri !== '' ? $processUri : NULL,
          'matches' => ($storedProcessUri !== NULL && $processUri !== '') ? $storedProcessUri === $processUri : NULL,
        ],
        'resources' => $resolvedAssociations,
        'resourcesSource' => $associationInput['provided'] ? 'request' : 'stored',
      ],
    ]);
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
    $path = $this->normalizeLegacyKeywordTypeProxyPath($path);
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
