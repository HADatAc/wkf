<?php

namespace Drupal\ctt\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\file\Entity\File;
use Drupal\rep\Constant;
use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\HASCO;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CttExecutionController extends ControllerBase {

  /**
   * Build execution dataset filename: DA-<ScenarioName>-<Date>-<Time>[ -TESTING].csv
   */
  protected function buildSimulationDatasetFilename(string $studyUri, bool $testMode = FALSE): string {
    $scenarioName = basename(trim($studyUri));
    if (\Drupal::hasService('rep.api_connector')) {
      try {
        $api = \Drupal::service('rep.api_connector');
        $studyObj = $api->parseObjectResponse($api->getUri($studyUri), 'getUri');
        if (is_object($studyObj)) {
          $label = trim((string) ($studyObj->label ?? $studyObj->name ?? ''));
          if ($label !== '') {
            $scenarioName = $label;
          }
        }
      }
      catch (\Throwable $ignored) {
        // Keep URI-based fallback when catalog lookup is unavailable.
      }
    }

    $scenarioName = preg_replace('/[^A-Za-z0-9]+/', '_', (string) $scenarioName) ?: 'Scenario';
    $scenarioName = trim((string) $scenarioName, '_');
    if ($scenarioName === '') {
      $scenarioName = 'Scenario';
    }

    $date = (new \DateTimeImmutable('now'))->format('Ymd');
    $time = (new \DateTimeImmutable('now'))->format('His');
    $suffix = $testMode ? '-TESTING' : '';
    return 'DA-' . $scenarioName . '-' . $date . '-' . $time . $suffix . '.csv';
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
   * Resolve current user identifier (prefers email when available).
   */
  protected function getCurrentUserIdentifier(): string {
    $identifier = trim((string) $this->currentUser()->getDisplayName());
    try {
      $user = \Drupal\user\Entity\User::load($this->currentUser()->id());
      if ($user && is_string($user->getEmail()) && trim($user->getEmail()) !== '') {
        $identifier = trim((string) $user->getEmail());
      }
    }
    catch (\Throwable $ignored) {
      // Keep fallback.
    }
    return $identifier;
  }

  /**
   * Mark the matching process execution run as completed and attach dataset info.
   */
  protected function finalizeProcessExecutionRun(string $studyUri, string $processUri, string $dataFileUri, string $daUri, string $filename, string $simulationType = 'individual', array $studentIds = []): void {
    $studyUri = trim($studyUri);
    $processUri = trim($processUri);
    if ($studyUri === '') {
      return;
    }

    $history = $this->loadProcessExecutionHistory($studyUri);
    if (empty($history)) {
      $history = [];
    }

    $userIdentifier = $this->getCurrentUserIdentifier();
    $activeKey = $this->getProcessExecutionActiveKey($studyUri, $processUri, $userIdentifier);
    $activeRunId = trim((string) \Drupal::state()->get($activeKey, ''));

    $targetIndex = -1;
    if ($activeRunId !== '') {
      foreach ($history as $index => $entry) {
        if (!is_array($entry)) {
          continue;
        }
        if (trim((string) ($entry['runId'] ?? '')) === $activeRunId) {
          $targetIndex = (int) $index;
          break;
        }
      }
    }

    if ($targetIndex < 0) {
      foreach ($history as $index => $entry) {
        if (!is_array($entry)) {
          continue;
        }
        $entryProcess = trim((string) ($entry['processUri'] ?? ''));
        $status = strtolower(trim((string) ($entry['status'] ?? '')));
        if (($processUri === '' || $entryProcess === $processUri) && in_array($status, ['running', 'queued'], TRUE)) {
          $targetIndex = (int) $index;
          break;
        }
      }
    }

    $finishedAt = gmdate('c');
    $normalizedSimulationType = in_array(strtolower(trim($simulationType)), ['individual', 'cohort'], TRUE)
      ? strtolower(trim($simulationType))
      : 'individual';
    $normalizedStudentIds = [];
    foreach ($studentIds as $candidateStudentId) {
      $normalizedStudentId = trim((string) $candidateStudentId);
      if ($normalizedStudentId !== '') {
        $normalizedStudentIds[$normalizedStudentId] = $normalizedStudentId;
      }
    }

    if ($targetIndex >= 0 && isset($history[$targetIndex]) && is_array($history[$targetIndex])) {
      $history[$targetIndex]['status'] = 'completed';
      $history[$targetIndex]['finishedAt'] = $finishedAt;
      $history[$targetIndex]['resultUri'] = $dataFileUri !== '' ? $dataFileUri : $daUri;
      $history[$targetIndex]['datasetUri'] = $dataFileUri;
      $history[$targetIndex]['dataFileUri'] = $dataFileUri;
      $history[$targetIndex]['daUri'] = $daUri;
      $history[$targetIndex]['filename'] = $filename;
      $history[$targetIndex]['recordedBy'] = $userIdentifier;
      if (trim((string) ($history[$targetIndex]['studyUri'] ?? '')) === '') {
        $history[$targetIndex]['studyUri'] = $studyUri;
      }
      if (trim((string) ($history[$targetIndex]['processUri'] ?? '')) === '') {
        $history[$targetIndex]['processUri'] = $processUri;
      }
      if (trim((string) ($history[$targetIndex]['toolLabel'] ?? '')) === '') {
        $history[$targetIndex]['toolLabel'] = 'CTT Process Execution';
      }
      $history[$targetIndex]['simulationType'] = $normalizedSimulationType;
      if (!empty($normalizedStudentIds)) {
        $history[$targetIndex]['studentIds'] = array_values($normalizedStudentIds);
      }
    }
    else {
      $runId = 'PX' . strtoupper(substr(sha1($studyUri . '|' . $processUri . '|' . microtime(TRUE)), 0, 12));
      array_unshift($history, [
        'runId' => $runId,
        'studyUri' => $studyUri,
        'processUri' => $processUri,
        'toolUri' => 'ctt://process-execution',
        'toolLabel' => 'CTT Process Execution',
        'requestedAt' => $finishedAt,
        'startedAt' => $finishedAt,
        'finishedAt' => $finishedAt,
        'status' => 'completed',
        'requestedBy' => $userIdentifier,
        'recordedBy' => $userIdentifier,
        'resultUri' => $dataFileUri !== '' ? $dataFileUri : $daUri,
        'datasetUri' => $dataFileUri,
        'dataFileUri' => $dataFileUri,
        'daUri' => $daUri,
        'filename' => $filename,
        'simulationType' => $normalizedSimulationType,
        'studentIds' => array_values($normalizedStudentIds),
        'mode' => 'execution-save',
      ]);
    }

    $this->saveProcessExecutionHistory($studyUri, $history);
    // Keep cohort run active across student turns so subsequent saves update
    // the same history entry/file instead of creating a new run per student.
    if ($processUri !== '' && $normalizedSimulationType !== 'cohort') {
      \Drupal::state()->delete($activeKey);
    }
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

  protected static function isUri(string $value): bool {
    $v = trim($value);
    return $v !== '' && (str_starts_with($v, 'http://') || str_starts_with($v, 'https://'));
  }

  protected static function csvEscape(string $value): string {
    $needs_quotes = strpbrk($value, ",\n\r\t") !== FALSE;
    $escaped = str_replace('"', '""', $value);
    return $needs_quotes ? '"' . $escaped . '"' : $escaped;
  }

  protected function buildExecutionCsvLayout(array $snapshot, array $meta = []): array {
    $metaProcess = trim((string) ($meta['processUri'] ?? ''));
    $subjectUri = trim((string) ($meta['subjectUri'] ?? ''));

    if ($subjectUri === '') {
      $studentIds = is_array($meta['studentIds'] ?? NULL) ? $meta['studentIds'] : [];
      foreach ($studentIds as $candidateStudentId) {
        $normalizedStudentId = trim((string) $candidateStudentId);
        if ($normalizedStudentId !== '') {
          $subjectUri = $normalizedStudentId;
          break;
        }
      }
    }

    if ($subjectUri === '') {
      $subjectUri = 'urn:subject:unknown';
    }

    $taskCatalog = $this->buildProcessTaskCatalog($metaProcess);
    $perTaskExecution = $this->buildPerTaskExecutionSnapshot($snapshot);
    $header = ['student_uri'];
    $row = [$subjectUri];
    $taskIndex = 0;

    // From the second column onward, each task contributes fixed execution
    // values in task-model order: datetime, result, note.
    foreach ($taskCatalog as $taskRow) {
      $taskIndex++;
      $taskPrefix = 'task_' . str_pad((string) $taskIndex, 3, '0', STR_PAD_LEFT);
      $header[] = $taskPrefix . '_datetime';
      $header[] = $taskPrefix . '_result';
      $header[] = $taskPrefix . '_note';

      $taskUri = (string) ($taskRow['task_uri'] ?? '');
      $run = $perTaskExecution[$taskUri] ?? [
        'executed' => FALSE,
        'start_ts' => '',
        'end_ts' => '',
        'duration_ms' => '',
        'response' => '',
        'note' => '',
        'raw_answer_json' => '',
        'raw_event_json' => '',
      ];

      $taskDatetime = trim((string) ($run['end_ts'] ?? ''));
      if ($taskDatetime === '') {
        $taskDatetime = trim((string) ($run['start_ts'] ?? ''));
      }
      $taskResult = trim((string) ($run['response'] ?? ''));
      if ($taskResult === '' && !empty($run['executed'])) {
        $taskResult = 'executed';
      }
      $taskNote = trim((string) ($run['note'] ?? ''));

      $row[] = $taskDatetime;
      $row[] = $taskResult;
      $row[] = $taskNote;
    }

    // Fallback: if process task catalog cannot be resolved, still preserve
    // captured execution entries as additional columns with the same triple
    // shape (datetime, result, note).
    if (count($row) === 1 && !empty($perTaskExecution)) {
      $uris = array_keys($perTaskExecution);
      sort($uris);
      $fallbackIndex = 0;
      foreach ($uris as $taskUri) {
        $fallbackIndex++;
        $taskPrefix = 'task_' . str_pad((string) $fallbackIndex, 3, '0', STR_PAD_LEFT);
        $header[] = $taskPrefix . '_datetime';
        $header[] = $taskPrefix . '_result';
        $header[] = $taskPrefix . '_note';

        $run = $perTaskExecution[$taskUri];
        $taskDatetime = trim((string) ($run['end_ts'] ?? ''));
        if ($taskDatetime === '') {
          $taskDatetime = trim((string) ($run['start_ts'] ?? ''));
        }
        $taskResult = trim((string) ($run['response'] ?? ''));
        if ($taskResult === '' && !empty($run['executed'])) {
          $taskResult = 'executed';
        }
        $taskNote = trim((string) ($run['note'] ?? ''));

        $row[] = $taskDatetime;
        $row[] = $taskResult;
        $row[] = $taskNote;
      }
    }

    return [
      'header' => $this->csvLineFromFields($header),
      'row' => $this->csvLineFromFields($row),
      'subjectUri' => $subjectUri,
    ];
  }

  protected function csvLineFromFields(array $fields): string {
    $normalized = [];
    foreach ($fields as $field) {
      $normalized[] = (string) $field;
    }
    return implode(',', array_map([static::class, 'csvEscape'], $normalized));
  }

  protected function parseCsvLines(string $csv): array {
    if ($csv === '') {
      return [];
    }

    $lines = preg_split('/\r\n|\n|\r/', $csv) ?: [];
    $out = [];
    foreach ($lines as $line) {
      $trimmed = trim((string) $line);
      if ($trimmed !== '') {
        $out[] = $trimmed;
      }
    }
    return $out;
  }

  protected function getCsvFirstColumn(string $line): string {
    $cols = str_getcsv($line);
    if (!is_array($cols) || !isset($cols[0])) {
      return '';
    }
    return trim((string) $cols[0]);
  }

  protected function replaceCsvFirstColumn(string $line, string $value): string {
    $cols = str_getcsv($line);
    if (!is_array($cols) || empty($cols)) {
      $cols = [''];
    }
    $cols[0] = $value;
    return $this->csvLineFromFields($cols);
  }

  protected function mergeCohortCsv(string $existingCsv, string $headerLine, string $rowLine, string $subjectUri, array $preferredOrder = []): string {
    $headerLine = trim($headerLine);
    $rowLine = trim($rowLine);

    $rowsBySubject = [];
    $existingLines = $this->parseCsvLines($existingCsv);
    if (!empty($existingLines)) {
      $existingHeader = array_shift($existingLines);
      if ($existingHeader === $headerLine) {
        foreach ($existingLines as $existingLine) {
          $existingSubject = $this->getCsvFirstColumn($existingLine);
          if ($existingSubject === '') {
            continue;
          }
          $rowsBySubject[$existingSubject] = $existingLine;
        }
      }
    }

    if ($subjectUri !== '') {
      $rowsBySubject[$subjectUri] = $rowLine;
    }

    $orderedRows = [];
    $seenSubjects = [];
    foreach ($preferredOrder as $candidate) {
      $candidateSubject = trim((string) $candidate);
      if ($candidateSubject === '' || !isset($rowsBySubject[$candidateSubject])) {
        continue;
      }
      $orderedRows[] = $rowsBySubject[$candidateSubject];
      $seenSubjects[$candidateSubject] = TRUE;
    }

    $remainingSubjects = array_keys($rowsBySubject);
    sort($remainingSubjects);
    foreach ($remainingSubjects as $remainingSubject) {
      if (isset($seenSubjects[$remainingSubject])) {
        continue;
      }
      $orderedRows[] = $rowsBySubject[$remainingSubject];
    }

    return $headerLine . "\n" . implode("\n", $orderedRows) . "\n";
  }

  protected function pickNextCohortSubject(string $existingCsv, array $preferredOrder = []): string {
    $used = [];
    $lines = $this->parseCsvLines($existingCsv);
    if (!empty($lines)) {
      array_shift($lines);
      foreach ($lines as $line) {
        $subject = $this->getCsvFirstColumn($line);
        if ($subject !== '') {
          $used[$subject] = TRUE;
        }
      }
    }

    foreach ($preferredOrder as $candidate) {
      $subject = trim((string) $candidate);
      if ($subject !== '' && !isset($used[$subject])) {
        return $subject;
      }
    }

    return '';
  }

  protected function buildProcessTaskCatalog(string $processUri): array {
    $processUri = trim($processUri);
    if ($processUri === '') {
      return [];
    }

    $tasks = [];
    try {
      /** @var \Drupal\ctt\Service\CttHascoClient $hasco */
      $hasco = \Drupal::service('ctt.hasco_client');
      $tasks = $hasco->getTasksByProcess($processUri);
    }
    catch (\Throwable $e) {
      \Drupal::logger('ctt.execution')->warning('Could not resolve task model for process @p: @err', [
        '@p' => $processUri,
        '@err' => $e->getMessage(),
      ]);
      return [];
    }

    if (empty($tasks) || !is_array($tasks)) {
      return [];
    }

    $byUri = [];
    foreach ($tasks as $task) {
      if (!is_array($task) && !is_object($task)) {
        continue;
      }
      $taskArr = (array) $task;
      $uri = trim((string) ($taskArr['uri'] ?? $taskArr['hasURI'] ?? ''));
      if (!static::isUri($uri)) {
        continue;
      }

      $subsRaw = $taskArr['hasSubtaskUris'] ?? $taskArr['hasSubtask'] ?? [];
      if (is_string($subsRaw)) {
        $subsRaw = preg_split('/[;,]/', $subsRaw) ?: [];
      }
      if (!is_array($subsRaw)) {
        $subsRaw = [];
      }

      $subs = [];
      foreach ($subsRaw as $subCandidate) {
        $subUri = trim((string) $subCandidate);
        if (static::isUri($subUri)) {
          $subs[] = $subUri;
        }
      }

      $parentUri = trim((string) ($taskArr['hasSupertaskUri'] ?? $taskArr['hasSupertask'] ?? $taskArr['vstoi:isSubtaskOf'] ?? ''));
      $dep = trim((string) ($taskArr['hasTemporalDependency'] ?? $taskArr['vstoi:hasTemporalDependency'] ?? ''));
      $operator = 'sequential';
      if ($dep !== '') {
        $token = strtolower((string) preg_split('/\s+/', $dep, 2)[0]);
        if (in_array($token, ['choice', 'parallel', 'independent', 'concurrent', 'concurrency', 'orderindependent', 'orderindependency'], TRUE)) {
          if ($token === 'concurrent' || $token === 'concurrency') {
            $operator = 'parallel';
          }
          elseif ($token === 'orderindependent' || $token === 'orderindependency') {
            $operator = 'independent';
          }
          else {
            $operator = $token;
          }
        }
      }

      $byUri[$uri] = [
        'task_uri' => $uri,
        'task_label' => trim((string) ($taskArr['label'] ?? $taskArr['name'] ?? '')),
        'parent_task_uri' => static::isUri($parentUri) ? $parentUri : '',
        'operator' => $operator,
        'subtasks' => array_values(array_unique($subs)),
      ];
    }

    if (empty($byUri)) {
      return [];
    }

    foreach ($byUri as $parentUri => $taskRow) {
      foreach ($taskRow['subtasks'] as $childUri) {
        if (!isset($byUri[$childUri])) {
          continue;
        }
        if ($byUri[$childUri]['parent_task_uri'] === '') {
          $byUri[$childUri]['parent_task_uri'] = $parentUri;
        }
      }
    }

    $roots = [];
    foreach ($byUri as $uri => $taskRow) {
      $parentUri = (string) ($taskRow['parent_task_uri'] ?? '');
      if ($parentUri === '' || !isset($byUri[$parentUri])) {
        $roots[] = $uri;
      }
    }
    sort($roots);

    $catalog = [];
    $seq = 0;
    $visited = [];

    $walk = function (string $uri, string $branchPath, string $branchParent, int $branchOptionIndex) use (&$walk, &$catalog, &$seq, &$visited, $byUri): void {
      if ($uri === '' || !isset($byUri[$uri]) || isset($visited[$uri])) {
        return;
      }
      $visited[$uri] = TRUE;
      $seq++;

      $row = $byUri[$uri];
      $row['task_sequence'] = $seq;
      $row['branch_parent_uri'] = $branchParent;
      $row['branch_option_index'] = $branchOptionIndex > 0 ? $branchOptionIndex : '';
      $row['branch_path'] = $branchPath;
      unset($row['subtasks']);
      $catalog[] = $row;

      $children = $byUri[$uri]['subtasks'];
      sort($children);
      $isBranchParent = in_array($byUri[$uri]['operator'], ['choice', 'parallel', 'independent'], TRUE);
      $index = 0;
      foreach ($children as $childUri) {
        $index++;
        $nextBranchParent = $isBranchParent ? $uri : $branchParent;
        $nextBranchIndex = $isBranchParent ? $index : $branchOptionIndex;
        $nextPath = $branchPath;
        if ($isBranchParent) {
          $suffix = substr($childUri, strrpos($childUri, '/') + 1);
          $nextPath = $branchPath === '' ? (string) $index . ':' . $suffix : $branchPath . '|' . (string) $index . ':' . $suffix;
        }
        $walk($childUri, $nextPath, $nextBranchParent, $nextBranchIndex);
      }
    };

    foreach ($roots as $rootUri) {
      $walk($rootUri, '', '', 0);
    }

    foreach (array_keys($byUri) as $uri) {
      if (!isset($visited[$uri])) {
        $walk($uri, '', '', 0);
      }
    }

    return $catalog;
  }

  protected function buildPerTaskExecutionSnapshot(array $snapshot): array {
    $result = [];

    $normalizeTs = static function ($value): array {
      $raw = trim((string) $value);
      if ($raw === '') {
        return ['', NULL];
      }
      if (is_numeric($raw)) {
        $n = (float) $raw;
        if ($n > 1000000000000) {
          return [$raw, (int) round($n)];
        }
        if ($n > 1000000000) {
          return [$raw, (int) round($n * 1000)];
        }
      }
      $ts = strtotime($raw);
      if ($ts === FALSE) {
        return [$raw, NULL];
      }
      return [$raw, $ts * 1000];
    };

    $events = is_array($snapshot['events'] ?? NULL) ? $snapshot['events'] : [];
    foreach ($events as $event) {
      if (!is_array($event)) {
        continue;
      }
      $uri = trim((string) ($event['nodeId'] ?? ''));
      if (!static::isUri($uri)) {
        continue;
      }

      if (!isset($result[$uri])) {
        $result[$uri] = [
          'executed' => TRUE,
          'start_ts' => '',
          'end_ts' => '',
          'duration_ms' => '',
          'response' => '',
          'note' => '',
          'raw_answer_json' => '',
          'raw_event_json' => '',
          '_start_ms' => NULL,
          '_end_ms' => NULL,
          '_raw_events' => [],
          '_raw_answers' => [],
        ];
      }

      [$rawTs, $tsMs] = $normalizeTs($event['ts'] ?? '');
      if ($result[$uri]['start_ts'] === '' && $rawTs !== '') {
        $result[$uri]['start_ts'] = $rawTs;
        $result[$uri]['_start_ms'] = $tsMs;
      }
      if ($rawTs !== '') {
        $result[$uri]['end_ts'] = $rawTs;
        $result[$uri]['_end_ms'] = $tsMs;
      }

      $type = strtolower(trim((string) ($event['type'] ?? '')));
      if ($type !== '' && $tsMs !== NULL) {
        if (str_contains($type, 'start') || str_contains($type, 'enter') || str_contains($type, 'begin') || str_contains($type, 'active')) {
          $result[$uri]['start_ts'] = $rawTs;
          $result[$uri]['_start_ms'] = $tsMs;
        }
        if (str_contains($type, 'end') || str_contains($type, 'exit') || str_contains($type, 'complete') || str_contains($type, 'done') || str_contains($type, 'finish')) {
          $result[$uri]['end_ts'] = $rawTs;
          $result[$uri]['_end_ms'] = $tsMs;
        }
      }

      $result[$uri]['_raw_events'][] = $event;
    }

    $answers = is_array($snapshot['answers'] ?? NULL) ? $snapshot['answers'] : [];
    foreach ($answers as $answer) {
      if (!is_array($answer)) {
        continue;
      }
      $uri = trim((string) ($answer['nodeId'] ?? ''));
      if (!static::isUri($uri)) {
        continue;
      }

      if (!isset($result[$uri])) {
        $result[$uri] = [
          'executed' => TRUE,
          'start_ts' => '',
          'end_ts' => '',
          'duration_ms' => '',
          'response' => '',
          'note' => '',
          'raw_answer_json' => '',
          'raw_event_json' => '',
          '_start_ms' => NULL,
          '_end_ms' => NULL,
          '_raw_events' => [],
          '_raw_answers' => [],
        ];
      }

      $response = '';
      $kind = strtolower(trim((string) ($answer['kind'] ?? '')));
      if ($kind === 'choice') {
        $response = trim((string) ($answer['selectionLabel'] ?? $answer['selectionId'] ?? ''));
      }
      elseif ($kind === 'confirm') {
        $response = !empty($answer['value']) ? 'true' : 'false';
      }
      else {
        $response = trim((string) ($answer['displayValue'] ?? $answer['value'] ?? ''));
      }
      if ($response !== '') {
        $result[$uri]['response'] = $response;
      }

      $note = trim((string) ($answer['note'] ?? $answer['message'] ?? $answer['prompt'] ?? ''));
      if ($note !== '') {
        $result[$uri]['note'] = $note;
      }

      $result[$uri]['_raw_answers'][] = $answer;
    }

    foreach ($result as $uri => $row) {
      if ($row['_start_ms'] !== NULL && $row['_end_ms'] !== NULL && $row['_end_ms'] >= $row['_start_ms']) {
        $result[$uri]['duration_ms'] = (string) ($row['_end_ms'] - $row['_start_ms']);
      }

      try {
        $result[$uri]['raw_event_json'] = json_encode($row['_raw_events'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
      }
      catch (\Throwable $ignored) {
        $result[$uri]['raw_event_json'] = '';
      }
      try {
        $result[$uri]['raw_answer_json'] = json_encode($row['_raw_answers'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
      }
      catch (\Throwable $ignored) {
        $result[$uri]['raw_answer_json'] = '';
      }

      unset($result[$uri]['_start_ms'], $result[$uri]['_end_ms'], $result[$uri]['_raw_events'], $result[$uri]['_raw_answers']);
    }

    return $result;
  }

  /**
   * Build state key for simulator execution context.
   */
  protected function getSimulationContextKey(string $studyUri, string $processUri, string $userIdentifier): string {
    return 'ctt.simulation_context.' . sha1(trim($studyUri) . '|' . trim($processUri) . '|' . trim($userIdentifier));
  }

  /**
   * Resolve and optionally consume stored simulator context.
   */
  protected function loadSimulationContext(string $studyUri, string $processUri, bool $consume = FALSE): array {
    $studyUri = trim($studyUri);
    $processUri = trim($processUri);
    $userIdentifier = $this->getCurrentUserIdentifier();
    if ($studyUri === '' || $processUri === '' || $userIdentifier === '') {
      return [];
    }

    $key = $this->getSimulationContextKey($studyUri, $processUri, $userIdentifier);
    $ctx = \Drupal::state()->get($key, []);
    if (!is_array($ctx)) {
      $ctx = [];
    }

    if ($consume) {
      \Drupal::state()->delete($key);
    }

    return $ctx;
  }

  /**
   * Resolve SOC-STUDENT IDs for a study.
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
   * Create a stub "execution output" and register it as DataFile + DA.
   *
   * This is a Drupal-side helper (hascoapi is off-limits).
   */
  protected function createSimulationRun(string $decodedStudyUri, ?string $processUri = NULL, string $simulationType = 'individual', array $studentIds = [], bool $testMode = FALSE): array {
    $logger = \Drupal::logger('ctt.execution');
    $api = \Drupal::service('rep.api_connector');
    $fs = \Drupal::service('file_system');

    $userEmail = $this->currentUser()->getEmail();
    $studyBasename = basename($decodedStudyUri);
    if (empty($studyBasename)) {
      $studyBasename = 'study';
    }

    $filename = $this->buildSimulationDatasetFilename($decodedStudyUri, $testMode);

    $directory = 'private://std/' . $studyBasename . '/da';
    try {
      $fs->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    }
    catch (\Throwable $e) {
      $logger->error('Failed to prepare directory @dir: @err', ['@dir' => $directory, '@err' => $e->getMessage()]);
      return [
        'ok' => FALSE,
        'filename' => $filename,
        'error' => 'Failed to prepare storage directory.',
      ];
    }

    $csv = "da_id,created_at,study\n" . $filename . "," . (new \DateTimeImmutable('now'))->format(DATE_ATOM) . "," . $decodedStudyUri . "\n";
    $destination = $directory . '/' . $filename;
    $savedUri = $fs->saveData($csv, $destination, FileSystemInterface::EXISTS_RENAME);

    if (empty($savedUri)) {
      $logger->error('Failed to save placeholder CSV to @dest', ['@dest' => $destination]);
      return [
        'ok' => FALSE,
        'filename' => $filename,
        'error' => 'Failed to generate execution output file.',
      ];
    }

    $fileEntity = File::create([
      'uri' => $savedUri,
      'filename' => basename($savedUri),
      'status' => FileInterface::STATUS_PERMANENT,
    ]);
    $fileEntity->setMimeType('text/csv');
    $fileEntity->save();

    $newDataFileUri = Utils::uriGen('datafile');
    $datafileArr = [
      'uri' => $newDataFileUri,
      'typeUri' => HASCO::DATAFILE,
      'hascoTypeUri' => HASCO::DATAFILE,
      'label' => $filename,
      'filename' => $filename,
      'id' => $fileEntity->id(),
      'studyUri' => $decodedStudyUri,
      // Keep streamUri empty so it shows up under "Unassociated Data Files".
      'streamUri' => NULL,
      'fileStatus' => Constant::FILE_STATUS_UNPROCESSED,
      'hasSIRManagerEmail' => $userEmail,
    ];
    if (!empty($processUri)) {
      $datafileArr['wasDerivedFrom'] = [$processUri];
    }

    $newDaUri = str_replace(Constant::PREFIX_DATAFILE, Utils::elementPrefix('da'), $newDataFileUri);
    $daArr = [
      'uri' => $newDaUri,
      'typeUri' => HASCO::DATA_ACQUISITION,
      'hascoTypeUri' => HASCO::DATA_ACQUISITION,
      'isMemberOfUri' => $decodedStudyUri,
      'label' => $filename,
      'hasDataFileUri' => $newDataFileUri,
      'hasVersion' => '',
      'comment' => 'Simulation run output (stub).',
      'hasSIRManagerEmail' => $userEmail,
    ];
    if (!empty($processUri)) {
      $daArr['comment'] = 'Simulation run output (stub). Process: ' . $processUri;
    }

    try {
      $msg1 = $api->parseObjectResponse($api->datafileAdd(json_encode($datafileArr)), 'datafileAdd');
      $msg2 = $api->parseObjectResponse($api->elementAdd('da', json_encode($daArr)), 'elementAdd');

      if ($msg1 && $msg2) {
        // Persist execution context so the editor UI can later save its run output
        // back into the same DA/DataFile.
        \Drupal::state()->set('ctt.execution.' . sha1($newDaUri), [
          'studyUri' => $decodedStudyUri,
          'processUri' => $processUri,
          'simulationType' => $simulationType,
          'testMode' => $testMode,
          'studentIds' => array_values($studentIds),
          'dataFileUri' => $newDataFileUri,
          'daUri' => $newDaUri,
          'fileId' => $fileEntity->id(),
          'filename' => $filename,
          'fileUri' => $savedUri,
        ]);

        return [
          'ok' => TRUE,
          'filename' => $filename,
          'dataFileUri' => $newDataFileUri,
          'daUri' => $newDaUri,
          'fileId' => $fileEntity->id(),
        ];
      }

      $logger->warning('Simulation creation returned empty response(s) datafileAdd=@d1 elementAdd=@d2', [
        '@d1' => var_export($msg1, TRUE),
        '@d2' => var_export($msg2, TRUE),
      ]);
      return [
        'ok' => FALSE,
        'filename' => $filename,
        'error' => 'Semantic registration returned empty response(s).',
      ];
    }
    catch (\Throwable $e) {
      $logger->error('Exception creating simulation execution: @err', ['@err' => $e->getMessage()]);
      return [
        'ok' => FALSE,
        'filename' => $filename,
        'error' => 'Semantic registration failed with exception.',
      ];
    }
  }

  protected function redirectToManageStudyWithError(string $studyuri, string $message): RedirectResponse {
    $this->messenger()->addError($this->t($message));
    return $this->redirect('std.manage_study_elements', [
      'studyuri' => $studyuri,
    ]);
  }

  public function createExecution(string $studyuri) {
    $decodedStudyUri = base64_decode($studyuri, TRUE);
    if (empty($decodedStudyUri)) {
      $this->messenger()->addError($this->t('Unable to start execution: invalid study identifier.'));
      return $this->redirect('std.search_studies_variables');
    }

    // Security rule: only the study owner/manager can create executions.
    $currentUserEmail = trim((string) $this->currentUser()->getEmail());
    if ($currentUserEmail === '') {
      return $this->redirectToManageStudyWithError($studyuri, 'You do not have permission to create executions for this study.');
    }

    try {
      $api = \Drupal::service('rep.api_connector');
      $studyObj = $api->parseObjectResponse($api->getUri($decodedStudyUri), 'getUri');
      $ownerEmail = is_object($studyObj) ? trim((string) ($studyObj->hasSIRManagerEmail ?? '')) : '';

      if ($ownerEmail === '' || strcasecmp($ownerEmail, $currentUserEmail) !== 0) {
        return $this->redirectToManageStudyWithError($studyuri, 'You are not allowed to create executions for this study.');
      }
    }
    catch (\Throwable $e) {
      return $this->redirectToManageStudyWithError($studyuri, 'Unable to validate permission to create this execution.');
    }

    // Reset the stored association for this study (useful for testing).
    $reset = (string) \Drupal::request()->query->get('reset');
    $reset = ($reset === '1' || strtolower($reset) === 'true');
    if ($reset) {
      \Drupal::state()->delete('ctt.study_process.' . sha1($decodedStudyUri));
    }

    // If the selection form submitted a processUri, it will come in query param.
    $processUri = NULL;
    $processB64 = \Drupal::request()->query->get('processUri');
    if (!empty($processB64)) {
      $candidate = (string) $processB64;
      $decoded = base64_decode($candidate, TRUE);
      if (is_string($decoded) && $decoded !== '' && (str_starts_with($decoded, 'http://') || str_starts_with($decoded, 'https://'))) {
        $processUri = $decoded;
      }
      else {
        // Treat as raw/percent-encoded URI.
        $processUri = rawurldecode($candidate);
      }
    }

    // Allow forcing the picker even if we have a stored association.
    $forcePick = (string) \Drupal::request()->query->get('pick');
    $forcePick = ($forcePick === '1' || strtolower($forcePick) === 'true');
    if ($reset) {
      $forcePick = TRUE;
    }

    // Drupal-local (no hascoapi changes) inference: remember last selected workflow per study.
    // If none is stored yet, we must prompt the user to select one.
    if (!$forcePick && empty($processUri)) {
      $storedProcessUri = \Drupal::state()->get('ctt.study_process.' . sha1($decodedStudyUri));
      if (!empty($storedProcessUri)) {
        // Validate it still exists.
        $api = \Drupal::service('rep.api_connector');
        $obj = $api->parseObjectResponse($api->getUri($storedProcessUri), 'getUri');
        if (!empty($obj) && is_object($obj) && !empty($obj->uri)) {
          $processUri = $storedProcessUri;
        }
        // If invalid, drop it and continue with discovery/picker.
        else {
          \Drupal::state()->delete('ctt.study_process.' . sha1($decodedStudyUri));
        }
      }
    }

    // If we still don't have a workflow/process for this study, always prompt selection.
    if (empty($processUri)) {
      return \Drupal::formBuilder()->getForm('Drupal\\ctt\\Form\\CttExecutionSelectForm', $studyuri);
    }

    // Persist chosen association for future inference.
    $this->persistStudyProcessAssociation($decodedStudyUri, $processUri);

    return $this->redirect('ctt.editor', [], [
      'query' => [
        // Pass the raw URI (will be URL-encoded in the query string) so the React app can read it.
        'processUri' => $processUri,
        'studyUri' => $studyuri,
        // Execution mode: workflow is read-only and DA is created only at the end.
        'execution' => '1',
      ],
    ]);
  }

  /**
   * Persist the simulation run summary into the execution's DA/DataFile.
   *
   * Called by the embedded CTT UI (Run Summary modal).
   */
  public function saveRunToDaFile(Request $request): JsonResponse {
    $logger = \Drupal::logger('ctt.execution');

    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload)) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'Invalid JSON body.'], 400);
    }

    $snapshot = $payload['snapshot'] ?? NULL;
    if (!is_array($snapshot)) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'Missing/invalid parameter: snapshot'], 400);
    }

    // If daUri is provided and we have stored context, overwrite existing.
    $daUri = trim((string) ($payload['daUri'] ?? ''));
    $ctx = NULL;
    if ($daUri !== '') {
      $candidate = \Drupal::state()->get('ctt.execution.' . sha1($daUri));
      if (!empty($candidate) && is_array($candidate)) {
        $ctx = $candidate;
      }
    }

    // Otherwise, create DA/DataFile now (end of simulation).
    if (!$ctx) {
      $studyUri = trim((string) ($payload['studyUri'] ?? ''));
      if (!static::isUri($studyUri)) {
        return new JsonResponse(['ok' => FALSE, 'error' => 'Missing/invalid parameter: studyUri'], 400);
      }

      $processUri = trim((string) ($payload['processUri'] ?? ''));
      if ($processUri !== '' && !static::isUri($processUri)) {
        $processUri = '';
      }

      $simulationType = strtolower(trim((string) ($payload['simulationType'] ?? '')));
      if (!in_array($simulationType, ['individual', 'cohort'], TRUE)) {
        $simulationType = 'individual';
      }

      $testMode = filter_var(($payload['testMode'] ?? FALSE), FILTER_VALIDATE_BOOLEAN);

      $simulationContext = [];
      if ($processUri !== '') {
        $simulationContext = $this->loadSimulationContext($studyUri, $processUri, FALSE);
      }
      if ($simulationType === 'individual' && !empty($simulationContext['simulationType'])) {
        $contextType = strtolower(trim((string) $simulationContext['simulationType']));
        if (in_array($contextType, ['individual', 'cohort'], TRUE)) {
          $simulationType = $contextType;
        }
      }

      if (!empty($simulationContext) && array_key_exists('testMode', $simulationContext)) {
        $testMode = filter_var($simulationContext['testMode'], FILTER_VALIDATE_BOOLEAN);
      }

      $studentIds = [];
      if (isset($simulationContext['studentIds']) && is_array($simulationContext['studentIds'])) {
        foreach ($simulationContext['studentIds'] as $candidateStudentId) {
          $normalizedStudentId = trim((string) $candidateStudentId);
          if ($normalizedStudentId !== '') {
            $studentIds[$normalizedStudentId] = $normalizedStudentId;
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

      if ($simulationType === 'cohort' && empty($studentIds)) {
        return new JsonResponse(['ok' => FALSE, 'error' => 'Cohort CTT Simulator requires SOC-STUDENT to include at least one student object.'], 400);
      }

      // Do not silently reuse a prior cohort DA when this request did not
      // explicitly provide one. A fresh execution should produce a fresh record.

      if (!$ctx) {
        $create = $this->createSimulationRun($studyUri, $processUri !== '' ? $processUri : NULL, $simulationType, array_values($studentIds), $testMode);
        if (empty($create['ok']) || empty($create['daUri'])) {
          return new JsonResponse(['ok' => FALSE, 'error' => 'Failed to create DA/DataFile for this execution.'], 500);
        }

        $daUri = (string) $create['daUri'];
        $ctx = \Drupal::state()->get('ctt.execution.' . sha1($daUri));
        if (empty($ctx) || !is_array($ctx)) {
          return new JsonResponse(['ok' => FALSE, 'error' => 'Execution context could not be loaded after creation.'], 500);
        }

        if ($processUri !== '') {
          $userIdentifier = $this->getCurrentUserIdentifier();
          if ($userIdentifier !== '') {
            \Drupal::state()->set($this->getSimulationContextKey($studyUri, $processUri, $userIdentifier), [
              'simulationType' => $simulationType,
              'testMode' => $testMode,
              'studentIds' => array_values($studentIds),
              'daUri' => $daUri,
              'recordedAt' => gmdate('c'),
            ]);
          }
        }
      }
    }

    $fid = (int) ($ctx['fileId'] ?? 0);
    $dataFileUri = trim((string) ($ctx['dataFileUri'] ?? ''));
    $filename = trim((string) ($ctx['filename'] ?? ''));
    if ($fid <= 0 || $dataFileUri === '' || $filename === '') {
      return new JsonResponse(['ok' => FALSE, 'error' => 'Execution context is incomplete (missing fileId/dataFileUri/filename).'], 500);
    }

    $fileEntity = File::load($fid);
    if (!$fileEntity) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'Drupal file entity not found.'], 404);
    }

    $simulationType = strtolower(trim((string) ($ctx['simulationType'] ?? 'individual')));
    if (!in_array($simulationType, ['individual', 'cohort'], TRUE)) {
      $simulationType = 'individual';
    }

    $csvLayout = $this->buildExecutionCsvLayout($snapshot, [
      'studyUri' => (string) ($ctx['studyUri'] ?? ''),
      'processUri' => (string) ($ctx['processUri'] ?? ''),
      'daUri' => (string) ($ctx['daUri'] ?? ''),
      'dataFileUri' => $dataFileUri,
      'subjectUri' => trim((string) ($payload['subjectUri'] ?? $payload['participantUri'] ?? '')),
      'simulationType' => $simulationType,
      'studentIds' => (array) ($ctx['studentIds'] ?? []),
      'generatedAt' => (new \DateTimeImmutable('now'))->format(DATE_ATOM),
    ]);

    $headerLine = trim((string) ($csvLayout['header'] ?? ''));
    $rowLine = trim((string) ($csvLayout['row'] ?? ''));
    $subjectUri = trim((string) ($csvLayout['subjectUri'] ?? ''));
    if ($headerLine === '' || $rowLine === '') {
      return new JsonResponse(['ok' => FALSE, 'error' => 'Failed to build execution CSV output.'], 500);
    }

    // 1) Overwrite the Drupal-local placeholder file (private://...)
    try {
      $fs = \Drupal::service('file_system');
      $real = $fs->realpath($fileEntity->getFileUri());
      if (!$real) {
        throw new \RuntimeException('Could not resolve file path.');
      }
      $csv = $headerLine . "\n" . $rowLine . "\n";
      if ($simulationType === 'cohort') {
        $existingCsv = '';
        $existing = @file_get_contents($real);
        if (is_string($existing)) {
          $existingCsv = $existing;
        }

        if ($subjectUri === '' || $subjectUri === 'urn:subject:unknown') {
          $fallbackSubjectUri = $this->pickNextCohortSubject($existingCsv, (array) ($ctx['studentIds'] ?? []));
          if ($fallbackSubjectUri !== '') {
            $subjectUri = $fallbackSubjectUri;
            $rowLine = $this->replaceCsvFirstColumn($rowLine, $subjectUri);
          }
        }

        if ($subjectUri === '' || $subjectUri === 'urn:subject:unknown') {
          return new JsonResponse(['ok' => FALSE, 'error' => 'Cohort CTT Simulator requires a valid subject identifier for each execution row.'], 400);
        }

        $csv = $this->mergeCohortCsv($existingCsv, $headerLine, $rowLine, $subjectUri, (array) ($ctx['studentIds'] ?? []));
      }

      if (@file_put_contents($real, $csv) === FALSE) {
        throw new \RuntimeException('Failed to write file contents.');
      }
    }
    catch (\Throwable $e) {
      $logger->error('Failed to write DA file (fid=@fid): @err', ['@fid' => $fid, '@err' => $e->getMessage()]);
      return new JsonResponse(['ok' => FALSE, 'error' => 'Failed to write DA file.'], 500);
    }

    // 2) Upload into hascoapi resources folder (optional but useful).
    $uploaded = FALSE;
    $upload_result = NULL;
    try {
      $hasco = \Drupal::service('ctt.hasco_client');
      $endpoint = '/hascoapi/api/uploadFile/' . rawurlencode($dataFileUri) . '/' . rawurlencode($filename);
      $upload_result = $hasco->proxyRequest('POST', $endpoint, [
        'headers' => [
          'Content-Type' => 'text/csv; charset=utf-8',
          'Accept' => 'application/json',
        ],
        'body' => $csv,
        'timeout' => 30,
        'connect_timeout' => 10,
      ]);
      $uploaded = TRUE;
    }
    catch (\Throwable $e) {
      $logger->warning('hascoapi uploadFile failed for @df (@fn): @err', [
        '@df' => $dataFileUri,
        '@fn' => $filename,
        '@err' => $e->getMessage(),
      ]);
    }

    $this->finalizeProcessExecutionRun(
      (string) ($ctx['studyUri'] ?? ''),
      (string) ($ctx['processUri'] ?? ''),
      $dataFileUri,
      $daUri,
      $filename,
      (string) ($ctx['simulationType'] ?? 'individual'),
      (array) ($ctx['studentIds'] ?? [])
    );

    return new JsonResponse([
      'ok' => TRUE,
      'daUri' => $daUri,
      'dataFileUri' => $dataFileUri,
      'filename' => $filename,
      'downloadUrl' => Url::fromRoute('ctt.execution_download', [], ['query' => ['daUri' => $daUri]])->toString(),
      'uploaded' => $uploaded,
      'uploadResult' => $upload_result,
    ]);
  }

  /**
   * Mark the currently running execution as interrupted.
   *
   * Used by the editor unload/crash safeguard so unfinished runs are explicit.
   */
  public function markExecutionInterrupted(Request $request): JsonResponse {
    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload)) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'Invalid JSON body.'], 400);
    }

    $studyUri = trim((string) ($payload['studyUri'] ?? ''));
    $processUri = trim((string) ($payload['processUri'] ?? ''));
    if (!static::isUri($studyUri)) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'Missing/invalid parameter: studyUri'], 400);
    }
    if ($processUri !== '' && !static::isUri($processUri)) {
      $processUri = '';
    }

    $history = $this->loadProcessExecutionHistory($studyUri);
    if (empty($history)) {
      return new JsonResponse(['ok' => TRUE, 'interrupted' => FALSE, 'reason' => 'No history entries found.']);
    }

    $userIdentifier = $this->getCurrentUserIdentifier();
    $activeKey = $this->getProcessExecutionActiveKey($studyUri, $processUri, $userIdentifier);
    $activeRunId = trim((string) \Drupal::state()->get($activeKey, ''));

    $targetIndex = -1;
    if ($activeRunId !== '') {
      foreach ($history as $index => $entry) {
        if (!is_array($entry)) {
          continue;
        }
        if (trim((string) ($entry['runId'] ?? '')) === $activeRunId) {
          $targetIndex = (int) $index;
          break;
        }
      }
    }

    if ($targetIndex < 0) {
      foreach ($history as $index => $entry) {
        if (!is_array($entry)) {
          continue;
        }

        $entryStatus = strtolower(trim((string) ($entry['status'] ?? '')));
        if (!in_array($entryStatus, ['running', 'queued'], TRUE)) {
          continue;
        }

        $entryProcessUri = trim((string) ($entry['processUri'] ?? ''));
        if ($processUri !== '' && $entryProcessUri !== $processUri) {
          continue;
        }

        $entryRequestedBy = trim((string) ($entry['requestedBy'] ?? ''));
        if ($entryRequestedBy !== '' && $userIdentifier !== '' && strcasecmp($entryRequestedBy, $userIdentifier) !== 0) {
          continue;
        }

        $targetIndex = (int) $index;
        break;
      }
    }

    if ($targetIndex < 0 || !isset($history[$targetIndex]) || !is_array($history[$targetIndex])) {
      return new JsonResponse(['ok' => TRUE, 'interrupted' => FALSE, 'reason' => 'No matching running execution found.']);
    }

    $status = strtolower(trim((string) ($history[$targetIndex]['status'] ?? '')));
    if (!in_array($status, ['running', 'queued'], TRUE)) {
      return new JsonResponse(['ok' => TRUE, 'interrupted' => FALSE, 'reason' => 'Execution already finalized.']);
    }

    $reason = trim((string) ($payload['reason'] ?? ''));
    if ($reason === '') {
      $reason = 'Editor closed before execution completion.';
    }

    $history[$targetIndex]['status'] = 'interrupted';
    $history[$targetIndex]['finishedAt'] = gmdate('c');
    $history[$targetIndex]['note'] = $reason;
    if (trim((string) ($history[$targetIndex]['recordedBy'] ?? '')) === '') {
      $history[$targetIndex]['recordedBy'] = $userIdentifier;
    }

    $this->saveProcessExecutionHistory($studyUri, $history);

    $runId = trim((string) ($history[$targetIndex]['runId'] ?? ''));
    if ($activeRunId !== '' && $runId !== '' && $activeRunId === $runId) {
      \Drupal::state()->delete($activeKey);
    }

    return new JsonResponse([
      'ok' => TRUE,
      'interrupted' => TRUE,
      'runId' => $runId,
      'status' => 'interrupted',
    ]);
  }

  /**
   * Download the generated DA CSV (private file) for a given execution.
   */
  public function downloadDaFile(Request $request): Response {
    $daUri = trim((string) $request->query->get('daUri'));
    if ($daUri === '') {
      return new Response('Missing parameter: daUri', 400);
    }

    $ctx = \Drupal::state()->get('ctt.execution.' . sha1($daUri));
    if (empty($ctx) || !is_array($ctx)) {
      return new Response('Execution context not found.', 404);
    }

    $fid = (int) ($ctx['fileId'] ?? 0);
    $filename = trim((string) ($ctx['filename'] ?? ''));
    if ($fid <= 0) {
      return new Response('Execution context is incomplete.', 500);
    }

    $fileEntity = File::load($fid);
    if (!$fileEntity) {
      return new Response('Drupal file entity not found.', 404);
    }

    $fs = \Drupal::service('file_system');
    $real = $fs->realpath($fileEntity->getFileUri());
    if (!$real || !is_file($real)) {
      return new Response('File not found on disk.', 404);
    }

    $downloadName = $filename !== '' ? $filename : basename($real);
    $resp = new BinaryFileResponse($real);
    $resp->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $resp->setContentDisposition('attachment', $downloadName);
    return $resp;
  }

  /**
   * Delete one execution entry from history and related local DA context.
   */
  public function deleteExecution(Request $request): RedirectResponse {
    $studyUri = trim((string) $request->query->get('studyUri'));
    $runId = trim((string) $request->query->get('runId'));
    $historyType = strtolower(trim((string) $request->query->get('historyType')));
    $daUri = trim((string) $request->query->get('daUri'));
    $returnTo = trim((string) $request->query->get('returnTo'));

    if ($studyUri === '' || $runId === '') {
      $this->messenger()->addError($this->t('Unable to delete execution: missing study or run identifier.'));
      return $this->redirect('std.search_studies_variables');
    }

    $removed = 0;

    if ($historyType === 'analysis' || $historyType === '') {
      $analysisKey = 'ctt.r_analysis_runs.' . sha1($studyUri);
      $analysisHistory = \Drupal::state()->get($analysisKey, []);
      if (is_array($analysisHistory)) {
        $filtered = [];
        foreach ($analysisHistory as $entry) {
          if (!is_array($entry)) {
            continue;
          }
          $entryRunId = trim((string) ($entry['runId'] ?? ''));
          if ($entryRunId !== '' && $entryRunId === $runId) {
            $removed++;
            continue;
          }
          $filtered[] = $entry;
        }
        \Drupal::state()->set($analysisKey, array_values($filtered));
      }
    }

    if ($historyType === 'process' || $historyType === '') {
      $processKey = $this->getProcessExecutionHistoryKey($studyUri);
      $processHistory = \Drupal::state()->get($processKey, []);
      if (is_array($processHistory)) {
        $filtered = [];
        foreach ($processHistory as $entry) {
          if (!is_array($entry)) {
            continue;
          }
          $entryRunId = trim((string) ($entry['runId'] ?? ''));
          if ($entryRunId !== '' && $entryRunId === $runId) {
            $removed++;

            $entryDaUri = trim((string) ($entry['daUri'] ?? ''));
            if ($entryDaUri === '' && $daUri !== '') {
              $entryDaUri = $daUri;
            }

            if ($entryDaUri !== '') {
              $ctxKey = 'ctt.execution.' . sha1($entryDaUri);
              $ctx = \Drupal::state()->get($ctxKey, []);
              if (is_array($ctx)) {
                $fid = (int) ($ctx['fileId'] ?? 0);
                if ($fid > 0) {
                  $file = File::load($fid);
                  if ($file) {
                    try {
                      $file->delete();
                    }
                    catch (\Throwable $ignored) {
                      // Best effort cleanup; history deletion still succeeds.
                    }
                  }
                }
              }
              \Drupal::state()->delete($ctxKey);
            }
            continue;
          }
          $filtered[] = $entry;
        }
        \Drupal::state()->set($processKey, array_values($filtered));
      }
    }

    if ($removed > 0) {
      $this->messenger()->addStatus($this->t('Execution deleted successfully.'));
    }
    else {
      $this->messenger()->addWarning($this->t('Execution was not found or was already removed.'));
    }

    if ($returnTo !== '' && str_starts_with($returnTo, '/')) {
      return new RedirectResponse($returnTo);
    }

    return $this->redirect('std.manage_study_elements', [
      'studyuri' => base64_encode($studyUri),
    ], [
      'query' => [
        'openPanel' => 'process-executions',
      ],
      'fragment' => 'process-executions',
    ]);
  }

  public function simulateExecution(string $studyuri): RedirectResponse|Response {
    $decodedStudyUri = base64_decode($studyuri);
    if (empty($decodedStudyUri)) {
      return new Response('Invalid study URI.', 400);
    }

    $processUri = NULL;
    $processB64 = \Drupal::request()->query->get('processUri');
    if (!empty($processB64)) {
      $decoded = base64_decode($processB64);
      if (!empty($decoded)) {
        $processUri = $decoded;
      }
    }

    $result = $this->createSimulationRun($decodedStudyUri, $processUri);
    if (!empty($result['ok'])) {
      $this->messenger()->addStatus($this->t('Simulation execution created: @filename', ['@filename' => $result['filename']]));
    }
    else {
      $this->messenger()->addWarning($this->t('Execution file was created locally, but semantic registration may have failed.'));
    }

    return $this->redirect('std.manage_study_elements', ['studyuri' => $studyuri]);
  }

}
