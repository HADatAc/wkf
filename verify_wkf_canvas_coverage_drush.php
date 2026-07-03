<?php

use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\rep\Constant;
use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\HASCO;
use Drupal\rep\Vocabulary\VSTOI;
use Symfony\Component\HttpFoundation\Request;

function wkf_cov_emit(string $key, $value): void {
  if (is_bool($value)) {
    $value = $value ? 'true' : 'false';
  }
  elseif (is_array($value) || is_object($value)) {
    $value = json_encode($value, JSON_UNESCAPED_SLASHES);
  }

  print $key . '=' . (string) $value . PHP_EOL;
}

function wkf_cov_bool_env(string $name, bool $default = FALSE): bool {
  $raw = getenv($name);
  if (!is_string($raw)) {
    return $default;
  }

  $normalized = strtolower(trim($raw));
  if ($normalized === '') {
    return $default;
  }

  return in_array($normalized, ['1', 'true', 'yes', 'on'], TRUE);
}

function wkf_cov_int_env(string $name, int $default): int {
  $raw = getenv($name);
  if (!is_string($raw)) {
    return $default;
  }

  $trimmed = trim($raw);
  if ($trimmed === '' || preg_match('/^-?\d+$/', $trimmed) !== 1) {
    return $default;
  }

  return (int) $trimmed;
}

function wkf_cov_normalize_uri(string $uri): string {
  return str_replace('#/', '#', trim($uri));
}

function wkf_cov_is_runtime_binding_uri(string $uri): bool {
  $normalized = wkf_cov_normalize_uri($uri);
  if ($normalized === '') {
    return FALSE;
  }

  return preg_match('@/rin/[0-9]+$@i', $normalized) === 1
    || stripos($normalized, 'requiredinstrument') !== FALSE;
}

function wkf_cov_parse_delimited_uris($value): array {
  if (is_array($value)) {
    $parts = [];
    foreach ($value as $item) {
      foreach (wkf_cov_parse_delimited_uris($item) as $candidate) {
        $parts[] = $candidate;
      }
    }
    return array_values(array_unique($parts));
  }

  if (is_object($value)) {
    return wkf_cov_parse_delimited_uris((array) $value);
  }

  if (!is_string($value)) {
    return [];
  }

  $raw = trim($value);
  if ($raw === '') {
    return [];
  }

  $split = preg_split('/[\r\n,;|]+/', $raw);
  if (!is_array($split)) {
    return [];
  }

  $uris = [];
  foreach ($split as $piece) {
    $candidate = trim((string) $piece);
    if ($candidate === '') {
      continue;
    }

    $normalized = wkf_cov_normalize_uri($candidate);
    if ($normalized === '') {
      continue;
    }

    $uris[$normalized] = $candidate;
  }

  return array_values($uris);
}

function wkf_cov_cell_column_index(string $cellRef): int {
  $letters = strtoupper((string) preg_replace('/[^A-Z]/i', '', $cellRef));
  if ($letters === '') {
    return -1;
  }

  $index = 0;
  $length = strlen($letters);
  for ($i = 0; $i < $length; $i++) {
    $index = ($index * 26) + (ord($letters[$i]) - 64);
  }

  return max(0, $index - 1);
}

function wkf_cov_shared_strings(ZipArchive $zip): array {
  $strings = [];
  $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
  if ($sharedXml === FALSE) {
    return $strings;
  }

  $sharedDom = new DOMDocument();
  if (!$sharedDom->loadXML($sharedXml)) {
    return $strings;
  }

  $xpath = new DOMXPath($sharedDom);
  $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

  foreach ($xpath->query('//a:si') as $siNode) {
    $parts = [];
    foreach ($xpath->query('a:t|a:r/a:t', $siNode) as $textNode) {
      $parts[] = (string) $textNode->nodeValue;
    }
    $strings[] = trim(implode('', $parts));
  }

  return $strings;
}

function wkf_cov_cell_value(DOMXPath $xpath, DOMElement $cellNode, array $sharedStrings): string {
  $type = (string) $cellNode->getAttribute('t');
  $valueNode = $xpath->query('a:v', $cellNode)->item(0);
  $rawValue = $valueNode ? (string) $valueNode->nodeValue : '';

  if ($type === 's') {
    $idx = (int) $rawValue;
    return isset($sharedStrings[$idx]) ? trim((string) $sharedStrings[$idx]) : '';
  }

  if ($type === 'inlineStr') {
    $inlineNode = $xpath->query('a:is/a:t', $cellNode)->item(0);
    return $inlineNode ? trim((string) $inlineNode->nodeValue) : '';
  }

  return trim($rawValue);
}

function wkf_cov_sheet_rows(ZipArchive $zip, string $sheetPath, array $sharedStrings): array {
  $sheetXml = $zip->getFromName($sheetPath);
  if ($sheetXml === FALSE) {
    return [];
  }

  $sheetDom = new DOMDocument();
  if (!$sheetDom->loadXML($sheetXml)) {
    return [];
  }

  $xpath = new DOMXPath($sheetDom);
  $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

  $rows = [];
  foreach ($xpath->query('//a:sheetData/a:row') as $rowNode) {
    $valuesByIndex = [];
    $nextIndex = 0;

    foreach ($xpath->query('a:c', $rowNode) as $cellNode) {
      $cellRef = (string) $cellNode->getAttribute('r');
      $index = wkf_cov_cell_column_index($cellRef);
      if ($index < 0) {
        $index = $nextIndex;
      }

      $valuesByIndex[$index] = wkf_cov_cell_value($xpath, $cellNode, $sharedStrings);
      $nextIndex = max($nextIndex, $index + 1);
    }

    if (empty($valuesByIndex)) {
      continue;
    }

    ksort($valuesByIndex);
    $maxIndex = (int) max(array_keys($valuesByIndex));
    $row = [];
    for ($i = 0; $i <= $maxIndex; $i++) {
      $row[] = isset($valuesByIndex[$i]) ? trim((string) $valuesByIndex[$i]) : '';
    }

    $rows[] = $row;
  }

  return $rows;
}

function wkf_cov_load_workbook(string $xlsxPath): array {
  $result = [
    'sheets' => [],
    'errors' => [],
  ];

  if (!is_file($xlsxPath)) {
    $result['errors'][] = 'file_not_found';
    return $result;
  }

  $zip = new ZipArchive();
  if ($zip->open($xlsxPath) !== TRUE) {
    $result['errors'][] = 'zip_open_failed';
    return $result;
  }

  $sheetTargets = [];
  $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
  if ($relsXml !== FALSE) {
    $relsDom = new DOMDocument();
    if ($relsDom->loadXML($relsXml)) {
      $relsXPath = new DOMXPath($relsDom);
      $relsXPath->registerNamespace('rel', 'http://schemas.openxmlformats.org/package/2006/relationships');
      foreach ($relsXPath->query('//rel:Relationship') as $relNode) {
        $id = trim((string) $relNode->getAttribute('Id'));
        $target = trim((string) $relNode->getAttribute('Target'));
        if ($id === '' || $target === '') {
          continue;
        }

        $target = ltrim($target, '/');
        if (!str_starts_with($target, 'xl/')) {
          $target = 'xl/' . $target;
        }
        $sheetTargets[$id] = $target;
      }
    }
  }

  $workbookXml = $zip->getFromName('xl/workbook.xml');
  if ($workbookXml === FALSE) {
    $zip->close();
    $result['errors'][] = 'workbook_xml_missing';
    return $result;
  }

  $workbookDom = new DOMDocument();
  if (!$workbookDom->loadXML($workbookXml)) {
    $zip->close();
    $result['errors'][] = 'workbook_xml_invalid';
    return $result;
  }

  $sharedStrings = wkf_cov_shared_strings($zip);
  $workbookXPath = new DOMXPath($workbookDom);
  $workbookXPath->registerNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

  foreach ($workbookXPath->query('//a:sheets/a:sheet') as $sheetNode) {
    $sheetName = trim((string) $sheetNode->getAttribute('name'));
    $rid = trim((string) $sheetNode->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id'));
    $target = $sheetTargets[$rid] ?? '';
    if ($sheetName === '' || $target === '') {
      continue;
    }

    $result['sheets'][$sheetName] = wkf_cov_sheet_rows($zip, $target, $sharedStrings);
  }

  $zip->close();
  return $result;
}

function wkf_cov_header_map(array $row): array {
  $map = [];
  foreach ($row as $idx => $header) {
    $key = trim((string) $header);
    if ($key !== '') {
      $map[$key] = (int) $idx;
    }
  }
  return $map;
}

function wkf_cov_cell(array $row, array $map, array $keys): string {
  foreach ($keys as $key) {
    if (!array_key_exists($key, $map)) {
      continue;
    }

    $idx = (int) $map[$key];
    if (!array_key_exists($idx, $row)) {
      continue;
    }

    $value = trim((string) $row[$idx]);
    if ($value !== '') {
      return $value;
    }
  }

  return '';
}

function wkf_cov_extract_expected_from_xlsx(string $xlsxPath): array {
  $workbook = wkf_cov_load_workbook($xlsxPath);

  $result = [
    'processUri' => '',
    'topTaskUri' => '',
    'taskCount' => 0,
    'tasks' => [],
    'taskAssignments' => [],
    'topSubtaskUris' => [],
    'errors' => $workbook['errors'],
  ];

  if (!empty($result['errors'])) {
    return $result;
  }

  $sheets = $workbook['sheets'];
  $requiredSheets = ['Processes', 'Tasks', 'RequiredInstruments'];
  foreach ($requiredSheets as $sheetName) {
    if (!array_key_exists($sheetName, $sheets)) {
      $result['errors'][] = 'missing_sheet:' . $sheetName;
    }
  }
  if (!empty($result['errors'])) {
    return $result;
  }

  $processRows = $sheets['Processes'];
  $taskRows = $sheets['Tasks'];
  $requiredRows = $sheets['RequiredInstruments'];

  if (count($taskRows) < 2) {
    $result['errors'][] = 'tasks_sheet_empty';
    return $result;
  }

  $processHeader = isset($processRows[0]) && is_array($processRows[0]) ? wkf_cov_header_map($processRows[0]) : [];
  if (!empty($processHeader) && isset($processRows[1]) && is_array($processRows[1])) {
    $processRow = $processRows[1];
    $result['processUri'] = wkf_cov_cell($processRow, $processHeader, ['hasURI']);
    $result['topTaskUri'] = wkf_cov_cell($processRow, $processHeader, ['vstoi:hasTopTask', 'hasTopTask', 'hasTopTaskUri']);
  }

  $taskHeader = wkf_cov_header_map($taskRows[0]);

  $taskMap = [];
  foreach (array_slice($taskRows, 1) as $taskRow) {
    if (!is_array($taskRow)) {
      continue;
    }

    $taskUri = wkf_cov_cell($taskRow, $taskHeader, ['hasURI', 'uri']);
    if ($taskUri === '') {
      continue;
    }

    $taskKey = wkf_cov_normalize_uri($taskUri);
    if ($taskKey === '') {
      continue;
    }

    $label = wkf_cov_cell($taskRow, $taskHeader, ['rdfs:label', 'label']);
    if ($label === '') {
      $label = basename($taskUri);
    }

    $parentRaw = wkf_cov_cell($taskRow, $taskHeader, [
      'vstoi:hasSupertask',
      'hasSupertask',
      'vstoi:hasSupertaskUri',
      'hasSupertaskUri',
      'vstoi:hasParentTask',
      'hasParentTask',
      'parentTaskUri',
    ]);
    $parentUris = wkf_cov_parse_delimited_uris($parentRaw);
    $parentUri = !empty($parentUris) ? (string) reset($parentUris) : '';

    $subtasksRaw = wkf_cov_cell($taskRow, $taskHeader, [
      'vstoi:hasSubtask',
      'hasSubtask',
      'vstoi:hasSubtaskUris',
      'hasSubtaskUris',
      'hasSubtasks',
    ]);
    $subtaskUris = wkf_cov_parse_delimited_uris($subtasksRaw);

    $requiredRaw = wkf_cov_cell($taskRow, $taskHeader, [
      'vstoi:hasRequiredInstrument',
      'hasRequiredInstrument',
      'vstoi:hasRequiredInstrumentUris',
      'hasRequiredInstrumentUris',
    ]);
    $requiredUris = wkf_cov_parse_delimited_uris($requiredRaw);

    $taskMap[$taskKey] = [
      'uri' => $taskUri,
      'label' => $label,
      'parentUri' => $parentUri,
      'subtaskUris' => $subtaskUris,
      'requiredInstrumentUris' => $requiredUris,
    ];
  }

  if (empty($taskMap)) {
    $result['errors'][] = 'no_tasks_resolved';
    return $result;
  }

  $requiredByTask = [];
  if (count($requiredRows) >= 2) {
    $requiredHeader = wkf_cov_header_map($requiredRows[0]);
    foreach (array_slice($requiredRows, 1) as $requiredRow) {
      if (!is_array($requiredRow)) {
        continue;
      }

      $taskUri = wkf_cov_cell($requiredRow, $requiredHeader, ['vstoi:isRelatedToTask', 'isRelatedToTask']);
      if ($taskUri === '') {
        continue;
      }

      $taskKey = wkf_cov_normalize_uri($taskUri);
      if ($taskKey === '' || !isset($taskMap[$taskKey])) {
        continue;
      }

      $instrumentRaw = wkf_cov_cell($requiredRow, $requiredHeader, ['vstoi:usesInstrument', 'usesInstrument']);
      $instrumentUris = wkf_cov_parse_delimited_uris($instrumentRaw);
      if (empty($instrumentUris)) {
        continue;
      }

      if (!isset($requiredByTask[$taskKey])) {
        $requiredByTask[$taskKey] = [];
      }

      foreach ($instrumentUris as $instrumentUri) {
        $instrumentKey = wkf_cov_normalize_uri($instrumentUri);
        if ($instrumentKey === '' || wkf_cov_is_runtime_binding_uri($instrumentKey)) {
          continue;
        }
        $requiredByTask[$taskKey][$instrumentKey] = $instrumentUri;
      }
    }
  }

  foreach ($taskMap as $taskKey => $taskData) {
    $assignment = [];

    foreach ($taskData['requiredInstrumentUris'] as $instrumentUri) {
      $instrumentKey = wkf_cov_normalize_uri($instrumentUri);
      if ($instrumentKey === '' || wkf_cov_is_runtime_binding_uri($instrumentKey)) {
        continue;
      }
      $assignment[$instrumentKey] = $instrumentUri;
    }

    if (isset($requiredByTask[$taskKey])) {
      foreach ($requiredByTask[$taskKey] as $instrumentKey => $instrumentUri) {
        $assignment[$instrumentKey] = $instrumentUri;
      }
    }

    $taskMap[$taskKey]['requiredInstrumentUris'] = array_values($assignment);
    if (!empty($assignment)) {
      $result['taskAssignments'][$taskKey] = array_keys($assignment);
    }
  }

  $topTaskKey = wkf_cov_normalize_uri($result['topTaskUri']);
  $topSubtasks = [];
  if ($topTaskKey !== '' && isset($taskMap[$topTaskKey])) {
    foreach ($taskMap[$topTaskKey]['subtaskUris'] as $subtaskUri) {
      $subtaskKey = wkf_cov_normalize_uri($subtaskUri);
      if ($subtaskKey !== '' && isset($taskMap[$subtaskKey])) {
        $topSubtasks[$subtaskKey] = $taskMap[$subtaskKey]['uri'];
      }
    }
  }

  if (empty($topSubtasks) && $topTaskKey !== '') {
    foreach ($taskMap as $taskKey => $taskData) {
      $parentKey = wkf_cov_normalize_uri($taskData['parentUri']);
      if ($parentKey !== '' && $parentKey === $topTaskKey) {
        $topSubtasks[$taskKey] = $taskData['uri'];
      }
    }
  }

  if ($result['topTaskUri'] === '') {
    $firstTask = reset($taskMap);
    if (is_array($firstTask) && isset($firstTask['uri'])) {
      $result['topTaskUri'] = (string) $firstTask['uri'];
      $topTaskKey = wkf_cov_normalize_uri($result['topTaskUri']);
    }
  }

  $result['tasks'] = $taskMap;
  $result['taskCount'] = count($taskMap);
  $result['topSubtaskUris'] = array_values($topSubtasks);

  return $result;
}

function wkf_cov_extract_instrument_uris_from_value($value, array &$bucket): void {
  if (is_string($value)) {
    foreach (wkf_cov_parse_delimited_uris($value) as $candidate) {
      $key = wkf_cov_normalize_uri($candidate);
      if ($key === '' || wkf_cov_is_runtime_binding_uri($key)) {
        continue;
      }
      $bucket[$key] = $candidate;
    }
    return;
  }

  if (is_object($value)) {
    wkf_cov_extract_instrument_uris_from_value((array) $value, $bucket);
    return;
  }

  if (!is_array($value)) {
    return;
  }

  foreach (['instrumentUri', 'usesInstrument', 'hasInstrument', 'uri'] as $keyName) {
    if (!array_key_exists($keyName, $value) || !is_string($value[$keyName])) {
      continue;
    }

    $candidate = trim((string) $value[$keyName]);
    $normalized = wkf_cov_normalize_uri($candidate);
    if ($normalized === '' || wkf_cov_is_runtime_binding_uri($normalized)) {
      continue;
    }

    if ($keyName === 'uri' && stripos($candidate, 'INS') === FALSE && stripos($candidate, '/instrument') === FALSE) {
      continue;
    }

    $bucket[$normalized] = $candidate;
  }

  foreach ($value as $child) {
    wkf_cov_extract_instrument_uris_from_value($child, $bucket);
  }
}

function wkf_cov_extract_task_uri($value): string {
  if (is_string($value)) {
    return trim($value);
  }

  if (is_array($value)) {
    return trim((string) ($value['uri'] ?? $value['hasURI'] ?? $value['taskUri'] ?? $value['hasTaskUri'] ?? ''));
  }

  if (is_object($value)) {
    return trim((string) ($value->uri ?? $value->hasURI ?? $value->taskUri ?? $value->hasTaskUri ?? ''));
  }

  return '';
}

function wkf_cov_extract_task_uris($value): array {
  if (is_string($value)) {
    return wkf_cov_parse_delimited_uris($value);
  }

  if (is_object($value)) {
    $value = (array) $value;
  }

  if (!is_array($value)) {
    return [];
  }

  $uris = [];
  $direct = wkf_cov_extract_task_uri($value);
  if ($direct !== '') {
    $uris[] = $direct;
  }

  foreach ($value as $child) {
    foreach (wkf_cov_extract_task_uris($child) as $candidate) {
      $uris[] = $candidate;
    }
  }

  $dedupe = [];
  foreach ($uris as $uri) {
    $normalized = wkf_cov_normalize_uri($uri);
    if ($normalized !== '') {
      $dedupe[$normalized] = $uri;
    }
  }

  return array_values($dedupe);
}

function wkf_cov_extract_task_parent_uri(array $task): string {
  $rawCandidates = [
    $task['hasSupertaskUri'] ?? NULL,
    $task['supertaskUri'] ?? NULL,
    $task['hasSupertask'] ?? NULL,
    $task['hasParentTaskUri'] ?? NULL,
    $task['parentTaskUri'] ?? NULL,
    $task['hasParentTask'] ?? NULL,
    $task['parentTask'] ?? NULL,
  ];

  foreach ($rawCandidates as $rawCandidate) {
    $uris = wkf_cov_extract_task_uris($rawCandidate);
    if (!empty($uris)) {
      return trim((string) reset($uris));
    }
  }

  return '';
}

function wkf_cov_extract_task_subtask_uris(array $task): array {
  $rawCandidates = [
    $task['hasSubtaskUris'] ?? NULL,
    $task['hasSubtask'] ?? NULL,
    $task['hasSubtasks'] ?? NULL,
    $task['subtaskUris'] ?? NULL,
    $task['hasSubtaskUri'] ?? NULL,
  ];

  $uris = [];
  foreach ($rawCandidates as $rawCandidate) {
    foreach (wkf_cov_extract_task_uris($rawCandidate) as $candidate) {
      $normalized = wkf_cov_normalize_uri($candidate);
      if ($normalized !== '') {
        $uris[$normalized] = $candidate;
      }
    }
  }

  return array_values($uris);
}

function wkf_cov_is_process_uri(string $uri): bool {
  $normalized = wkf_cov_normalize_uri($uri);
  if ($normalized === '') {
    return FALSE;
  }

  return stripos($normalized, '/PROC/') !== FALSE
    || preg_match('#^pmsr:/[^/]+/PROC/[^/]+$#i', $normalized) === 1;
}

function wkf_cov_process_uri_variants(string $uri): array {
  $uri = trim($uri);
  if ($uri === '') {
    return [];
  }

  $variants = [];
  $add = function (string $candidate) use (&$variants): void {
    $candidate = trim($candidate);
    if ($candidate === '') {
      return;
    }

    $variants[wkf_cov_normalize_uri($candidate)] = $candidate;
  };

  $add($uri);
  $add(str_replace('#/', '#', $uri));

  if (strpos($uri, '#') !== FALSE && strpos($uri, '#/') === FALSE) {
    $add(str_replace('#', '#/', $uri));
  }

  if (str_starts_with($uri, 'http://')) {
    $https = 'https://' . substr($uri, 7);
    $add($https);
    $add(str_replace('#/', '#', $https));
  }
  elseif (str_starts_with($uri, 'https://')) {
    $http = 'http://' . substr($uri, 8);
    $add($http);
    $add(str_replace('#/', '#', $http));
  }

  return array_values($variants);
}

function wkf_cov_add_process_uri_candidate(array &$bucket, string $uri): void {
  if (!wkf_cov_is_process_uri($uri)) {
    return;
  }

  foreach (wkf_cov_process_uri_variants($uri) as $variant) {
    $key = wkf_cov_normalize_uri($variant);
    if ($key !== '') {
      $bucket[$key] = $variant;
    }
  }
}

function wkf_cov_collect_process_uris_from_mixed($value, array &$bucket, int $depth = 0): void {
  if ($depth > 6) {
    return;
  }

  if (is_string($value)) {
    foreach (wkf_cov_parse_delimited_uris($value) as $candidate) {
      wkf_cov_add_process_uri_candidate($bucket, $candidate);
    }
    return;
  }

  if (is_object($value)) {
    $value = (array) $value;
  }

  if (!is_array($value)) {
    return;
  }

  foreach ($value as $child) {
    wkf_cov_collect_process_uris_from_mixed($child, $bucket, $depth + 1);
  }
}

function wkf_cov_extract_workflow_code_from_process_uri(string $processUri): string {
  $candidate = trim($processUri);
  if ($candidate === '') {
    return '';
  }

  $normalized = wkf_cov_normalize_uri($candidate);
  if (preg_match('~/(.+)/PROC/[^/]+$~i', $normalized, $matches) === 1) {
    $segment = trim((string) ($matches[1] ?? ''));
    if (strpos($segment, '#') !== FALSE) {
      $parts = explode('#', $segment);
      $segment = trim((string) end($parts));
    }

    return trim($segment, '/');
  }

  return '';
}

function wkf_cov_find_process_uris_by_keyword(string $keyword, array &$bucket): void {
  $keyword = trim($keyword);
  if ($keyword === '') {
    return;
  }

  $api = \Drupal::service('rep.api_connector');
  foreach (['process', 'workflow'] as $searchType) {
    try {
      $list = $api->parseObjectResponse($api->listByKeyword($searchType, $keyword, 300, 0), 'listByKeyword');
    }
    catch (Throwable $e) {
      continue;
    }

    if (!is_array($list)) {
      continue;
    }

    foreach ($list as $item) {
      wkf_cov_collect_process_uris_from_mixed($item, $bucket);

      if (is_object($item) && isset($item->uri) && is_string($item->uri)) {
        wkf_cov_add_process_uri_candidate($bucket, (string) $item->uri);
      }
      elseif (is_array($item) && isset($item['uri']) && is_string($item['uri'])) {
        wkf_cov_add_process_uri_candidate($bucket, (string) $item['uri']);
      }
    }
  }
}

function wkf_cov_resolve_generated_process_uri_candidates(array $ingest): array {
  $candidates = [];

  $ingestedWkfUri = trim((string) ($ingest['wkfUri'] ?? ''));
  if ($ingestedWkfUri === '') {
    return [];
  }

  if (preg_match('~^(.*#/?[^/#]+)$~', $ingestedWkfUri, $matches) === 1) {
    wkf_cov_add_process_uri_candidate($candidates, trim((string) ($matches[1] ?? '')) . '/PROC/0001');
  }

  $api = \Drupal::service('rep.api_connector');
  try {
    $ingestedWkf = $api->parseObjectResponse($api->getUri($ingestedWkfUri), 'getUri');
    wkf_cov_collect_process_uris_from_mixed($ingestedWkf, $candidates);
  }
  catch (Throwable $e) {
    // Keep fallback candidates.
  }

  $wkfToken = basename(str_replace(['#/', '#'], '/', $ingestedWkfUri));
  if ($wkfToken !== '') {
    wkf_cov_find_process_uris_by_keyword($wkfToken, $candidates);
  }

  return array_values($candidates);
}

function wkf_cov_resolve_process_uri_candidates(array $expected, array $ingest, array $generatedCandidates = []): array {
  $candidates = [];

  $envProcessUri = trim((string) getenv('PMSR_WKF_PROCESS_URI'));
  if ($envProcessUri !== '') {
    wkf_cov_add_process_uri_candidate($candidates, $envProcessUri);
    return array_values($candidates);
  }

  $expectedProcessUri = trim((string) ($expected['processUri'] ?? ''));

  if (empty($generatedCandidates)) {
    $generatedCandidates = wkf_cov_resolve_generated_process_uri_candidates($ingest);
  }

  foreach ($generatedCandidates as $generatedCandidate) {
    wkf_cov_add_process_uri_candidate($candidates, (string) $generatedCandidate);
  }

  $ingestedWkfUri = trim((string) ($ingest['wkfUri'] ?? ''));

  $topTaskUri = trim((string) ($expected['topTaskUri'] ?? ''));
  if ($topTaskUri !== '') {
    if (preg_match('#^(.*)/TSK/[^/]+$#i', wkf_cov_normalize_uri($topTaskUri), $matches) === 1) {
      wkf_cov_add_process_uri_candidate($candidates, trim((string) ($matches[1] ?? '')) . '/PROC/0001');
    }

    $api = \Drupal::service('rep.api_connector');
    try {
      $topTask = $api->parseObjectResponse($api->getUri($topTaskUri), 'getUri');
      wkf_cov_collect_process_uris_from_mixed($topTask, $candidates);
    }
    catch (Throwable $e) {
      // Keep fallback candidates.
    }
  }

  $keywords = [];
  $workflowCode = wkf_cov_extract_workflow_code_from_process_uri($expectedProcessUri);
  if ($workflowCode !== '') {
    $keywords[$workflowCode] = TRUE;
  }

  if ($ingestedWkfUri !== '') {
    $wkfToken = basename(str_replace(['#/', '#'], '/', $ingestedWkfUri));
    if ($wkfToken !== '') {
      $keywords[$wkfToken] = TRUE;
    }
  }

  foreach (array_keys($keywords) as $keyword) {
    wkf_cov_find_process_uris_by_keyword($keyword, $candidates);
  }

  if ($expectedProcessUri !== '') {
    wkf_cov_add_process_uri_candidate($candidates, $expectedProcessUri);
  }

  if (empty($candidates) && $expectedProcessUri !== '') {
    wkf_cov_add_process_uri_candidate($candidates, $expectedProcessUri);
  }

  return array_values($candidates);
}

function wkf_cov_empty_tree_result(): array {
  return [
    'status' => 0,
    'payload' => [],
    'process' => [],
    'tasks' => [],
    'taskAssignments' => [],
    'topTaskUri' => '',
    'topSubtaskUris' => [],
  ];
}

function wkf_cov_poll_process_tree(array $processCandidates, int $attempts, int $intervalMs): array {
  $attempts = max(1, $attempts);
  $intervalMs = max(0, $intervalMs);

  $bestTree = wkf_cov_empty_tree_result();
  $bestTreeUri = '';
  $bestScore = -1;
  $usedAttempts = 0;

  for ($attempt = 1; $attempt <= $attempts; $attempt++) {
    $usedAttempts = $attempt;

    foreach ($processCandidates as $candidateUri) {
      $candidateUri = trim((string) $candidateUri);
      if ($candidateUri === '') {
        continue;
      }

      $currentTree = wkf_cov_get_process_tree($candidateUri);
      $status = (int) ($currentTree['status'] ?? 0);
      $taskCount = is_array($currentTree['tasks'] ?? NULL) ? count($currentTree['tasks']) : 0;
      $score = ($status === 200 ? 1000000 : 0) + $taskCount;

      if ($score > $bestScore) {
        $bestScore = $score;
        $bestTree = $currentTree;
        $bestTreeUri = $candidateUri;
      }

      if ($status === 200 && $taskCount > 0) {
        return [
          'actual' => $currentTree,
          'processUri' => $candidateUri,
          'attemptsUsed' => $usedAttempts,
        ];
      }
    }

    if ($attempt < $attempts && $intervalMs > 0) {
      usleep($intervalMs * 1000);
    }
  }

  return [
    'actual' => $bestTree,
    'processUri' => $bestTreeUri,
    'attemptsUsed' => $usedAttempts,
  ];
}

function wkf_cov_get_process_tree(string $processUri): array {
  $controller = \Drupal::service('class_resolver')->getInstanceFromDefinition(\Drupal\ctt\Controller\CttApiController::class);
  $request = Request::create('/workflow/api/process/tree', 'GET', ['uri' => $processUri]);
  $response = $controller->getProcessTree($request);

  $payload = json_decode((string) $response->getContent(), TRUE);
  if (!is_array($payload)) {
    $payload = [];
  }

  $tasks = is_array($payload['tasks'] ?? NULL) ? $payload['tasks'] : [];
  $process = is_array($payload['process'] ?? NULL) ? $payload['process'] : [];

  $taskMap = [];
  $taskAssignments = [];

  foreach ($tasks as $task) {
    if (is_object($task)) {
      $task = (array) $task;
    }
    if (!is_array($task)) {
      continue;
    }

    $taskUri = wkf_cov_extract_task_uri($task);
    $taskKey = wkf_cov_normalize_uri($taskUri);
    if ($taskKey === '') {
      continue;
    }

    $label = trim((string) ($task['label'] ?? $task['hasContent'] ?? $taskUri));
    $parentUri = wkf_cov_extract_task_parent_uri($task);
    $subtaskUris = wkf_cov_extract_task_subtask_uris($task);

    $instrumentBucket = [];
    if (array_key_exists('requiredInstrument', $task)) {
      wkf_cov_extract_instrument_uris_from_value($task['requiredInstrument'], $instrumentBucket);
    }
    if (array_key_exists('hasRequiredInstrumentUris', $task)) {
      wkf_cov_extract_instrument_uris_from_value($task['hasRequiredInstrumentUris'], $instrumentBucket);
    }

    $taskMap[$taskKey] = [
      'uri' => $taskUri,
      'label' => $label,
      'parentUri' => $parentUri,
      'subtaskUris' => $subtaskUris,
      'requiredInstrumentUris' => array_values($instrumentBucket),
    ];

    if (!empty($instrumentBucket)) {
      $taskAssignments[$taskKey] = array_keys($instrumentBucket);
    }
  }

  $topTaskUri = trim((string) ($process['hasTopTaskUri'] ?? $process['hasTopTask'] ?? ''));
  $topTaskKey = wkf_cov_normalize_uri($topTaskUri);

  $topSubtasks = [];
  if ($topTaskKey !== '' && isset($taskMap[$topTaskKey])) {
    foreach ($taskMap[$topTaskKey]['subtaskUris'] as $subtaskUri) {
      $subtaskKey = wkf_cov_normalize_uri($subtaskUri);
      if ($subtaskKey !== '' && isset($taskMap[$subtaskKey])) {
        $topSubtasks[$subtaskKey] = $taskMap[$subtaskKey]['uri'];
      }
    }
  }

  if (empty($topSubtasks) && $topTaskKey !== '') {
    foreach ($taskMap as $taskKey => $taskData) {
      $parentKey = wkf_cov_normalize_uri($taskData['parentUri']);
      if ($parentKey !== '' && $parentKey === $topTaskKey) {
        $topSubtasks[$taskKey] = $taskData['uri'];
      }
    }
  }

  return [
    'status' => $response->getStatusCode(),
    'payload' => $payload,
    'process' => $process,
    'tasks' => $taskMap,
    'taskAssignments' => $taskAssignments,
    'topTaskUri' => $topTaskUri,
    'topSubtaskUris' => array_values($topSubtasks),
  ];
}

function wkf_cov_compare_expected_actual(array $expected, array $actual): array {
  $expectedTasks = is_array($expected['tasks'] ?? NULL) ? $expected['tasks'] : [];
  $actualTasks = is_array($actual['tasks'] ?? NULL) ? $actual['tasks'] : [];

  $missingTasks = [];
  foreach ($expectedTasks as $taskKey => $taskData) {
    if (!isset($actualTasks[$taskKey])) {
      $missingTasks[] = (string) ($taskData['uri'] ?? $taskKey);
    }
  }

  $extraTasks = [];
  foreach ($actualTasks as $taskKey => $taskData) {
    if (!isset($expectedTasks[$taskKey])) {
      $extraTasks[] = (string) ($taskData['uri'] ?? $taskKey);
    }
  }

  $parentMismatches = [];
  foreach ($expectedTasks as $taskKey => $taskData) {
    if (!isset($actualTasks[$taskKey])) {
      continue;
    }

    $expectedParent = wkf_cov_normalize_uri((string) ($taskData['parentUri'] ?? ''));
    if ($expectedParent === '') {
      continue;
    }

    $actualParent = wkf_cov_normalize_uri((string) ($actualTasks[$taskKey]['parentUri'] ?? ''));
    if ($actualParent !== $expectedParent) {
      $parentMismatches[] = [
        'taskUri' => (string) ($taskData['uri'] ?? $taskKey),
        'expectedParentUri' => (string) ($taskData['parentUri'] ?? ''),
        'actualParentUri' => (string) ($actualTasks[$taskKey]['parentUri'] ?? ''),
      ];
    }
  }

  $expectedTop = [];
  foreach ((array) ($expected['topSubtaskUris'] ?? []) as $uri) {
    $normalized = wkf_cov_normalize_uri((string) $uri);
    if ($normalized !== '') {
      $expectedTop[$normalized] = (string) $uri;
    }
  }

  $actualTop = [];
  foreach ((array) ($actual['topSubtaskUris'] ?? []) as $uri) {
    $normalized = wkf_cov_normalize_uri((string) $uri);
    if ($normalized !== '') {
      $actualTop[$normalized] = (string) $uri;
    }
  }

  $missingTopSubtasks = [];
  $extraTopSubtasks = [];

  if (!empty($expectedTop)) {
    foreach ($expectedTop as $key => $uri) {
      if (!isset($actualTop[$key])) {
        $missingTopSubtasks[] = $uri;
      }
    }

    foreach ($actualTop as $key => $uri) {
      if (!isset($expectedTop[$key])) {
        $extraTopSubtasks[] = $uri;
      }
    }
  }

  $expectedAssignments = is_array($expected['taskAssignments'] ?? NULL) ? $expected['taskAssignments'] : [];
  $actualAssignments = is_array($actual['taskAssignments'] ?? NULL) ? $actual['taskAssignments'] : [];

  $instrumentMismatches = [];
  foreach ($expectedAssignments as $taskKey => $expectedInstrumentKeys) {
    $expectedInstrumentKeys = array_values(array_unique(array_filter(array_map('strval', (array) $expectedInstrumentKeys))));
    sort($expectedInstrumentKeys);

    $actualInstrumentKeys = array_values(array_unique(array_filter(array_map('strval', (array) ($actualAssignments[$taskKey] ?? [])))));
    sort($actualInstrumentKeys);

    $missingInstruments = array_values(array_diff($expectedInstrumentKeys, $actualInstrumentKeys));
    $unexpectedInstruments = array_values(array_diff($actualInstrumentKeys, $expectedInstrumentKeys));

    if (!empty($missingInstruments) || !empty($unexpectedInstruments)) {
      $taskUri = isset($expectedTasks[$taskKey]['uri']) ? (string) $expectedTasks[$taskKey]['uri'] : $taskKey;
      $instrumentMismatches[] = [
        'taskUri' => $taskUri,
        'missingInstrumentUris' => $missingInstruments,
        'unexpectedInstrumentUris' => $unexpectedInstruments,
      ];
    }
  }

  $taskSetOk = empty($missingTasks) && empty($extraTasks);
  $hierarchyOk = empty($parentMismatches) && empty($missingTopSubtasks) && empty($extraTopSubtasks);
  $instrumentOk = empty($instrumentMismatches);
  $treeOk = ((int) ($actual['status'] ?? 0) === 200);

  return [
    'taskSetOk' => $taskSetOk,
    'hierarchyOk' => $hierarchyOk,
    'instrumentOk' => $instrumentOk,
    'treeOk' => $treeOk,
    'modelComplete' => $treeOk && $taskSetOk && $hierarchyOk && $instrumentOk,
    'missingTasks' => $missingTasks,
    'extraTasks' => $extraTasks,
    'parentMismatches' => $parentMismatches,
    'missingTopSubtasks' => $missingTopSubtasks,
    'extraTopSubtasks' => $extraTopSubtasks,
    'instrumentMismatches' => $instrumentMismatches,
  ];
}

function wkf_cov_ingest_wkf_from_file(string $sourceFile, string $label): array {
  $api = \Drupal::service('rep.api_connector');
  $fileSystem = \Drupal::service('file_system');

  $userEmail = trim((string) \Drupal::currentUser()->getEmail());
  if ($userEmail === '') {
    $userEmail = 'automation@local.pmsr';
  }

  $uploadDir = trim((string) getenv('PMSR_WKF_UPLOAD_DIR'));
  if ($uploadDir === '') {
    $uploadDir = 'private://wkf';
  }

  if (!$fileSystem->prepareDirectory($uploadDir, FileSystemInterface::CREATE_DIRECTORY)) {
    throw new RuntimeException('Could not prepare upload directory: ' . $uploadDir);
  }

  $sourceReal = realpath($sourceFile);
  if (!is_string($sourceReal) || $sourceReal === '' || !is_file($sourceReal)) {
    throw new RuntimeException('Source WKF file not found: ' . $sourceFile);
  }

  $baseName = basename($sourceReal);
  $nameParts = pathinfo($baseName);
  $stem = isset($nameParts['filename']) ? (string) $nameParts['filename'] : 'WKF_UPLOAD';
  $ext = isset($nameParts['extension']) ? (string) $nameParts['extension'] : 'xlsx';

  $destName = $stem . '_AUTO_' . gmdate('Ymd_His') . '.' . $ext;
  $destUri = rtrim($uploadDir, '/') . '/' . $destName;

  $targetDirReal = $fileSystem->realpath($uploadDir);
  if (!is_string($targetDirReal) || $targetDirReal === '') {
    throw new RuntimeException('Unable to resolve upload directory real path for ' . $uploadDir);
  }

  $destReal = rtrim($targetDirReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $destName;
  if (!@copy($sourceReal, $destReal)) {
    throw new RuntimeException('Failed to copy WKF source file into Drupal private storage.');
  }

  $fileEntity = File::create([
    'uri' => $destUri,
    'filename' => $destName,
    'status' => File::STATUS_PERMANENT,
    'uid' => \Drupal::currentUser()->id(),
  ]);
  $fileEntity->setPermanent();
  $fileEntity->save();

  $fileId = (int) $fileEntity->id();

  try {
    $originManager = \Drupal::service('rep.file_origin_manager');
    if ($originManager) {
      $originManager->markLocal($fileId);
    }
  }
  catch (Throwable $ignored) {
    // Best-effort marker only.
  }

  $dataFileUri = Utils::uriGen('datafile');
  $datafileJson = json_encode([
    'uri' => $dataFileUri,
    'typeUri' => HASCO::DATAFILE,
    'hascoTypeUri' => HASCO::DATAFILE,
    'label' => $label,
    'filename' => $destName,
    'fileStatus' => Constant::FILE_STATUS_UNPROCESSED,
    'hasSIRManagerEmail' => $userEmail,
    'id' => $fileId,
  ], JSON_UNESCAPED_SLASHES);

  $datafileResult = $api->parseObjectResponse($api->datafileAdd($datafileJson), 'datafileAdd');
  if ($datafileResult === NULL) {
    throw new RuntimeException('Failed to create DataFile entity for uploaded WKF.');
  }

  $wkfUri = Utils::uriGen('wkf');
  $wkfJson = json_encode([
    'uri' => $wkfUri,
    'typeUri' => HASCO::WKF,
    'hascoTypeUri' => HASCO::WKF,
    'label' => $label,
    'hasDataFileUri' => $dataFileUri,
    'hasVersion' => '1',
    'comment' => 'Automated WKF canvas coverage ingestion run',
    'hasSIRManagerEmail' => $userEmail,
  ], JSON_UNESCAPED_SLASHES);

  $wkfResult = $api->parseObjectResponse($api->elementAdd('wkf', $wkfJson), 'elementAdd');
  if ($wkfResult === NULL) {
    throw new RuntimeException('Failed to create WKF metadata template entity.');
  }

  $wkfObject = $api->parseObjectResponse($api->getUri($wkfUri), 'getUri');
  if (!is_object($wkfObject)) {
    throw new RuntimeException('Failed to fetch created WKF object by URI.');
  }

  if ((!isset($wkfObject->hasDataFile) || !is_object($wkfObject->hasDataFile)) && !empty($wkfObject->hasDataFileUri)) {
    $dataFileObj = $api->parseObjectResponse($api->getUri((string) $wkfObject->hasDataFileUri), 'getUri');
    if (is_object($dataFileObj)) {
      $wkfObject->hasDataFile = $dataFileObj;
    }
  }

  $statusRaw = trim((string) getenv('PMSR_WKF_INGEST_STATUS'));
  $ingestStatus = VSTOI::CURRENT;
  if ($statusRaw !== '') {
    $statusNorm = strtolower($statusRaw);
    if ($statusNorm === 'draft') {
      $ingestStatus = VSTOI::DRAFT;
    }
    elseif ($statusNorm === '_') {
      $ingestStatus = '_';
    }
    elseif ($statusNorm !== 'current') {
      $ingestStatus = $statusRaw;
    }
  }

  $ingestResponse = $api->uploadTemplate('wkf', $wkfObject, $ingestStatus);

  return [
    'wkfUri' => $wkfUri,
    'dataFileUri' => $dataFileUri,
    'fileUri' => $destUri,
    'fileRealPath' => $destReal,
    'fileId' => $fileId,
    'ingestSubmitted' => ($ingestResponse !== NULL && $ingestResponse !== FALSE),
    'ingestResponse' => is_scalar($ingestResponse) || is_array($ingestResponse) || is_object($ingestResponse)
      ? $ingestResponse
      : '',
    'error' => '',
  ];
}

$defaultSource = 'C:/Users/nunos/Documents/Graxiom/wkf/wkf/WKF-ASPIRACAO_SECRECOES_PSMR.xlsx';
$sourceFile = trim((string) getenv('PMSR_WKF_SOURCE_FILE'));
if ($sourceFile === '') {
  $sourceFile = $defaultSource;
}

$skipIngest = wkf_cov_bool_env('PMSR_WKF_SKIP_INGEST', FALSE);
$strict = wkf_cov_bool_env('PMSR_WKF_STRICT', FALSE);

wkf_cov_emit('VALIDATION_STATUS', 'STARTED');
wkf_cov_emit('SOURCE_FILE', $sourceFile);
wkf_cov_emit('SKIP_INGEST', $skipIngest);

$expected = wkf_cov_extract_expected_from_xlsx($sourceFile);
wkf_cov_emit('EXPECTED_ERRORS', $expected['errors']);
if (!empty($expected['errors'])) {
  wkf_cov_emit('VALIDATION_STATUS', 'FAILED_PRECHECK');
  if ($strict) {
    throw new RuntimeException('WKF coverage precheck failed.');
  }
  return;
}

wkf_cov_emit('EXPECTED_PROCESS_URI', $expected['processUri']);
wkf_cov_emit('EXPECTED_TOP_TASK_URI', $expected['topTaskUri']);
wkf_cov_emit('EXPECTED_TASK_COUNT', $expected['taskCount']);
wkf_cov_emit('EXPECTED_TASK_ASSIGNMENT_COUNT', count($expected['taskAssignments']));
wkf_cov_emit('EXPECTED_TOP_SUBTASKS', $expected['topSubtaskUris']);

$ingest = [
  'wkfUri' => '',
  'dataFileUri' => '',
  'fileUri' => '',
  'fileRealPath' => '',
  'fileId' => 0,
  'ingestSubmitted' => FALSE,
  'ingestResponse' => '',
  'error' => '',
];

if (!$skipIngest) {
  $label = 'WKF ASPIRACAO Canvas Coverage ' . gmdate('Y-m-d H:i:s');
  try {
    $ingest = wkf_cov_ingest_wkf_from_file($sourceFile, $label);
  }
  catch (Throwable $e) {
    $ingest['error'] = $e->getMessage();
  }
}

wkf_cov_emit('INGEST_WKF_URI', $ingest['wkfUri']);
wkf_cov_emit('INGEST_DATAFILE_URI', $ingest['dataFileUri']);
wkf_cov_emit('INGEST_FILE_URI', $ingest['fileUri']);
wkf_cov_emit('INGEST_FILE_REALPATH', $ingest['fileRealPath']);
wkf_cov_emit('INGEST_FILE_ID', (int) $ingest['fileId']);
wkf_cov_emit('INGEST_SUBMITTED', (bool) $ingest['ingestSubmitted']);
wkf_cov_emit('INGEST_RESPONSE', $ingest['ingestResponse']);
wkf_cov_emit('INGEST_ERROR', (string) ($ingest['error'] ?? ''));

$envProcessUri = trim((string) getenv('PMSR_WKF_PROCESS_URI'));
$generatedProcessCandidates = $envProcessUri === '' ? wkf_cov_resolve_generated_process_uri_candidates($ingest) : [];
wkf_cov_emit('GENERATED_PROCESS_URI_CANDIDATES', $generatedProcessCandidates);

$processCandidates = wkf_cov_resolve_process_uri_candidates($expected, $ingest, $generatedProcessCandidates);
wkf_cov_emit('PROCESS_URI_CANDIDATES', $processCandidates);

if (empty($processCandidates)) {
  wkf_cov_emit('VALIDATION_STATUS', 'FAILED_NO_PROCESS_URI');
  if ($strict) {
    throw new RuntimeException('No process URI available for tree validation.');
  }
  return;
}

$treePollAttempts = wkf_cov_int_env('PMSR_WKF_TREE_POLL_ATTEMPTS', $skipIngest ? 1 : 8);
$treePollIntervalMs = wkf_cov_int_env('PMSR_WKF_TREE_POLL_INTERVAL_MS', 2500);
wkf_cov_emit('TREE_POLL_ATTEMPTS', $treePollAttempts);
wkf_cov_emit('TREE_POLL_INTERVAL_MS', $treePollIntervalMs);

$polledTree = [
  'actual' => wkf_cov_empty_tree_result(),
  'processUri' => '',
  'attemptsUsed' => 0,
];

$generatedProbe = [
  'attempted' => FALSE,
  'processUri' => '',
  'status' => 0,
  'taskCount' => 0,
  'attemptsUsed' => 0,
];

if (!$skipIngest && $envProcessUri === '' && !empty($generatedProcessCandidates)) {
  $generatedProbe['attempted'] = TRUE;
  $generatedPolledTree = wkf_cov_poll_process_tree($generatedProcessCandidates, $treePollAttempts, $treePollIntervalMs);
  $generatedActual = is_array($generatedPolledTree['actual'] ?? NULL) ? $generatedPolledTree['actual'] : wkf_cov_empty_tree_result();
  $generatedProcessUri = trim((string) ($generatedPolledTree['processUri'] ?? ''));
  if ($generatedProcessUri === '' && !empty($generatedProcessCandidates)) {
    $generatedProcessUri = (string) reset($generatedProcessCandidates);
  }

  $generatedProbe['processUri'] = $generatedProcessUri;
  $generatedProbe['status'] = (int) ($generatedActual['status'] ?? 0);
  $generatedProbe['taskCount'] = is_array($generatedActual['tasks'] ?? NULL) ? count($generatedActual['tasks']) : 0;
  $generatedProbe['attemptsUsed'] = (int) ($generatedPolledTree['attemptsUsed'] ?? 0);

  wkf_cov_emit('GENERATED_PROCESS_URI_USED', $generatedProbe['processUri']);
  wkf_cov_emit('GENERATED_TREE_STATUS', $generatedProbe['status']);
  wkf_cov_emit('GENERATED_TASK_COUNT', $generatedProbe['taskCount']);
  wkf_cov_emit('GENERATED_TREE_POLL_ATTEMPTS', $generatedProbe['attemptsUsed']);

  if ($generatedProbe['status'] === 200 && $generatedProbe['taskCount'] > 0) {
    $polledTree = $generatedPolledTree;
  }
}

if ((int) ($polledTree['attemptsUsed'] ?? 0) === 0) {
  $polledTree = wkf_cov_poll_process_tree($processCandidates, $treePollAttempts, $treePollIntervalMs);
}

$actual = is_array($polledTree['actual'] ?? NULL) ? $polledTree['actual'] : wkf_cov_empty_tree_result();
$processUri = trim((string) ($polledTree['processUri'] ?? ''));
if ($processUri === '' && !empty($processCandidates)) {
  $processUri = (string) reset($processCandidates);
}
$processUriSource = ($generatedProbe['processUri'] !== '' && wkf_cov_normalize_uri($generatedProbe['processUri']) === wkf_cov_normalize_uri($processUri))
  ? 'generated'
  : 'fallback';
wkf_cov_emit('PROCESS_URI_USED', $processUri);
wkf_cov_emit('PROCESS_URI_SOURCE', $processUriSource);
wkf_cov_emit('TREE_POLL_USED_ATTEMPTS', (int) ($polledTree['attemptsUsed'] ?? 0));

$comparison = wkf_cov_compare_expected_actual($expected, $actual);

wkf_cov_emit('ACTUAL_TREE_STATUS', (int) $actual['status']);
wkf_cov_emit('ACTUAL_PROCESS_TOP_TASK_URI', (string) ($actual['topTaskUri'] ?? ''));
wkf_cov_emit('ACTUAL_TASK_COUNT', count($actual['tasks']));
wkf_cov_emit('ACTUAL_TASK_ASSIGNMENT_COUNT', count($actual['taskAssignments']));
wkf_cov_emit('ACTUAL_TOP_SUBTASKS', $actual['topSubtaskUris']);

wkf_cov_emit('MODEL_TREE_OK', (bool) $comparison['treeOk']);
wkf_cov_emit('MODEL_TASK_SET_OK', (bool) $comparison['taskSetOk']);
wkf_cov_emit('MODEL_HIERARCHY_OK', (bool) $comparison['hierarchyOk']);
wkf_cov_emit('MODEL_INSTRUMENT_OK', (bool) $comparison['instrumentOk']);
wkf_cov_emit('MODEL_COMPLETE', (bool) $comparison['modelComplete']);

wkf_cov_emit('MISSING_TASK_URIS', $comparison['missingTasks']);
wkf_cov_emit('EXTRA_TASK_URIS', $comparison['extraTasks']);
wkf_cov_emit('PARENT_MISMATCHES', $comparison['parentMismatches']);
wkf_cov_emit('MISSING_TOP_SUBTASK_URIS', $comparison['missingTopSubtasks']);
wkf_cov_emit('EXTRA_TOP_SUBTASK_URIS', $comparison['extraTopSubtasks']);
wkf_cov_emit('INSTRUMENT_MISMATCHES', $comparison['instrumentMismatches']);

$summary = [
  'sourceFile' => $sourceFile,
  'skipIngest' => $skipIngest,
  'processUri' => $processUri,
  'expectedTaskCount' => (int) $expected['taskCount'],
  'actualTaskCount' => count($actual['tasks']),
  'modelComplete' => (bool) $comparison['modelComplete'],
  'ingestSubmitted' => (bool) $ingest['ingestSubmitted'],
  'ingestError' => (string) ($ingest['error'] ?? ''),
  'generatedProcessUri' => (string) ($generatedProbe['processUri'] ?? ''),
  'generatedProcessResolved' => ((int) ($generatedProbe['status'] ?? 0) === 200 && (int) ($generatedProbe['taskCount'] ?? 0) > 0),
  'generatedProcessTaskCount' => (int) ($generatedProbe['taskCount'] ?? 0),
  'processUriSource' => $processUriSource,
  'generatedCandidateCount' => count($generatedProcessCandidates),
  'processCandidateCount' => count($processCandidates),
  'treePollAttempts' => (int) ($polledTree['attemptsUsed'] ?? 0),
];
wkf_cov_emit('SUMMARY', $summary);

if (!$comparison['modelComplete'] && $strict) {
  wkf_cov_emit('VALIDATION_STATUS', 'FAILED');
  throw new RuntimeException('WKF canvas coverage validation failed in strict mode.');
}

wkf_cov_emit('VALIDATION_STATUS', $comparison['modelComplete'] ? 'DONE_OK' : 'DONE_WITH_GAPS');
