<?php

namespace Drupal\ctt\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Extracts WKF metadata directly from WKF workbooks.
 */
class WkfMetadataExtractor {

  /**
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * @var \Drupal\ctt\Service\CttHascoClient
   */
  protected $hascoClient;

  /**
   * Constructs the service.
   */
  public function __construct(
    FileSystemInterface $file_system,
    LoggerChannelFactoryInterface $logger_factory,
    CttHascoClient $hasco_client
  ) {
    $this->fileSystem = $file_system;
    $this->logger = $logger_factory->get('ctt');
    $this->hascoClient = $hasco_client;
  }

  /**
   * Extract metadata directly from one WKF workbook file.
   *
   * @return array<string, mixed>
   */
  public function extractMetadataFromFile(string $wkfFilePath): array {
    $result = [
      'uri' => '',
      'process_stem_uri' => '',
      'label' => '',
      'comment' => '',
      'version' => '',
      'principal_investigator_uri' => '',
      'principal_investigator_email' => '',
      'principal_investigator_name' => '',
      'organization_uri' => '',
      'organization_name' => '',
      'scenario_properties_with_values' => 0,
      'tasks' => 0,
      'used_component_instances' => 0,
      'source' => '',
      'wkf_file' => $wkfFilePath,
    ];

    $path = $this->resolveLocalPath($wkfFilePath);
    if ($path === '' || !is_file($path) || !is_readable($path) || !class_exists('ZipArchive')) {
      return $result;
    }

    $zip = new \ZipArchive();
    if ($zip->open($path) !== TRUE) {
      return $result;
    }

    try {
      $manifest = $this->readWorkbookManifest($zip);
      if (empty($manifest)) {
        return $result;
      }

      $sharedStrings = $this->readSharedStrings($zip);

      $sheetRows = [];
      foreach (['InfoSheet', 'Namespaces', 'STD', 'ProcessStems', 'Processes', 'Tasks'] as $sheetName) {
        if (!isset($manifest[$sheetName])) {
          continue;
        }
        $sheetRows[$sheetName] = $this->readSheetRows($zip, $manifest[$sheetName], $sharedStrings);
      }

      $namespaces = $this->buildNamespaces(isset($sheetRows['Namespaces']) ? $sheetRows['Namespaces'] : []);

      $processMeta = $this->extractProcessUris($sheetRows, $namespaces);
      $stdMeta = $this->extractStdIdentityAndCoverage(isset($sheetRows['STD']) ? $sheetRows['STD'] : [], $namespaces);
      $tasksMeta = $this->extractTaskCounts(isset($sheetRows['Tasks']) ? $sheetRows['Tasks'] : []);

      $result['uri'] = (string) ($processMeta['uri'] ?? '');
      $result['process_stem_uri'] = (string) ($processMeta['process_stem_uri'] ?? '');
      $result['label'] = (string) ($processMeta['label'] ?? '');
      $result['comment'] = (string) ($processMeta['comment'] ?? '');
      $result['version'] = (string) ($processMeta['version'] ?? '');
      $result['principal_investigator_uri'] = (string) ($stdMeta['principal_investigator_uri'] ?? '');
      $result['principal_investigator_email'] = (string) ($stdMeta['principal_investigator_email'] ?? '');
      $result['organization_uri'] = (string) ($stdMeta['organization_uri'] ?? '');
      $result['principal_investigator_name'] = (string) ($stdMeta['principal_investigator_name'] ?? '');
      $result['organization_name'] = (string) ($stdMeta['organization_name'] ?? '');
      $result['scenario_properties_with_values'] = (int) ($stdMeta['scenario_properties_with_values'] ?? 0);
      $result['tasks'] = (int) ($tasksMeta['tasks'] ?? 0);
      $result['used_component_instances'] = (int) ($tasksMeta['used_component_instances'] ?? 0);
      $result['source'] = $this->extractSourceValue($sheetRows);

      if ($result['principal_investigator_name'] === '' && $result['principal_investigator_uri'] !== '') {
        $result['principal_investigator_name'] = $this->resolveLabelByUri($result['principal_investigator_uri']);
      }
      if ($result['organization_name'] === '' && $result['organization_uri'] !== '') {
        $result['organization_name'] = $this->resolveLabelByUri($result['organization_uri']);
      }

      $result['principal_investigator_email'] = $this->normalizePrincipalInvestigatorEmail(
        $result['principal_investigator_email'],
        $result['principal_investigator_uri']
      );
    }
    catch (\Throwable $e) {
      $this->logger->warning('WKF metadata extraction failed for {path}: {message}', [
        'path' => $path,
        'message' => $e->getMessage(),
      ]);
    }
    finally {
      $zip->close();
    }

    return $result;
  }

  /**
   * Normalize WKF PI email to canonical curator mailbox when applicable.
   */
  protected function normalizePrincipalInvestigatorEmail(string $email, string $piUri): string {
    $normalizedEmail = strtolower(trim($email));
    $normalizedPiUri = strtolower(trim($piUri));

    if ($normalizedEmail === 'equipa@pmsr.net' || $normalizedPiUri === 'https://pmsr.net/ont/per/pi-001') {
      return 'curator@reitoria.ucp.pt';
    }

    return trim($email);
  }

  /**
   * Extract metadata and return it as a JSON string.
   */
  public function extractMetadataJsonFromFile(string $wkfFilePath): string {
    $metadata = $this->extractMetadataFromFile($wkfFilePath);

    $json = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($json)) {
      return '{}';
    }
    return $json;
  }

  /**
   * Resolve stream-wrapper or local path to absolute file-system path.
   */
  protected function resolveLocalPath(string $path): string {
    $candidate = trim($path);
    if ($candidate === '') {
      return '';
    }

    if (strpos($candidate, '://') === FALSE) {
      return $candidate;
    }

    try {
      $real = $this->fileSystem->realpath($candidate);
      return is_string($real) ? $real : '';
    }
    catch (\Throwable $e) {
      return '';
    }
  }

  /**
   * Parse workbook/relationship XML and map sheet name to sheet XML path.
   *
   * @return array<string, string>
   */
  protected function readWorkbookManifest(\ZipArchive $zip): array {
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if (!is_string($workbookXml) || trim($workbookXml) === '' || !is_string($relsXml) || trim($relsXml) === '') {
      return [];
    }

    $ridToTarget = [];
    $relsMatches = [];
    if (preg_match_all('/<Relationship[^>]*\bId="([^"]+)"[^>]*\bTarget="([^"]+)"/i', $relsXml, $relsMatches, PREG_SET_ORDER) > 0) {
      foreach ($relsMatches as $m) {
        $ridToTarget[$m[1]] = html_entity_decode($m[2], ENT_QUOTES | ENT_XML1);
      }
    }

    $sheetTargets = [];
    $sheetMatches = [];
    if (preg_match_all('/<sheet[^>]*\bname="([^"]+)"[^>]*\br:id="([^"]+)"/i', $workbookXml, $sheetMatches, PREG_SET_ORDER) > 0) {
      foreach ($sheetMatches as $m) {
        $name = html_entity_decode($m[1], ENT_QUOTES | ENT_XML1);
        $rid = $m[2];
        if (isset($ridToTarget[$rid])) {
          $target = ltrim((string) $ridToTarget[$rid], '/');
          $sheetTargets[$name] = str_starts_with($target, 'xl/') ? $target : ('xl/' . $target);
        }
      }
    }

    return $sheetTargets;
  }

  /**
   * Read shared strings table from workbook.
   *
   * @return array<int, string>
   */
  protected function readSharedStrings(\ZipArchive $zip): array {
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if (!is_string($xml) || trim($xml) === '') {
      return [];
    }

    $strings = [];
    if (preg_match_all('/<si\b[^>]*>(.*?)<\/si>/is', $xml, $siMatches, PREG_SET_ORDER) > 0 || !empty($siMatches)) {
      foreach ($siMatches as $si) {
        $text = '';
        if (preg_match_all('/<t(?:\s[^>]*)?>(.*?)<\/t>/is', $si[1], $tMatches, PREG_SET_ORDER) > 0 || !empty($tMatches)) {
          foreach ($tMatches as $part) {
            $text .= html_entity_decode($part[1], ENT_QUOTES | ENT_XML1);
          }
        }
        $strings[] = $text;
      }
    }

    return $strings;
  }

  /**
   * Read one worksheet and return row values by column order.
   *
   * @return array<int, array<int, string>>
   */
  protected function readSheetRows(\ZipArchive $zip, string $sheetPath, array $sharedStrings): array {
    $sheetXml = $zip->getFromName($sheetPath);
    if (!is_string($sheetXml) || trim($sheetXml) === '') {
      return [];
    }

    $rows = [];
    $rowMatches = [];
    if (preg_match_all('/<row\b[^>]*>(.*?)<\/row>/is', $sheetXml, $rowMatches, PREG_SET_ORDER) > 0) {
      foreach ($rowMatches as $rowMatch) {
        $cells = [];
        $cellMatches = [];
        if (preg_match_all('/<c\b([^>]*)>(.*?)<\/c>|<c\b([^>]*)\/>/is', $rowMatch[1], $cellMatches, PREG_SET_ORDER) > 0) {
          foreach ($cellMatches as $cellMatch) {
            $attrs = $cellMatch[1] !== '' ? $cellMatch[1] : $cellMatch[3];
            $inner = $cellMatch[2] ?? '';

            $colRef = '';
            if (preg_match('/\br="([A-Z]+)[0-9]+"/i', $attrs, $refMatch) === 1) {
              $colRef = strtoupper($refMatch[1]);
            }
            $colIndex = $this->columnToIndex($colRef);

            $type = '';
            if (preg_match('/\bt="([^"]+)"/i', $attrs, $typeMatch) === 1) {
              $type = strtolower(trim($typeMatch[1]));
            }

            $value = '';
            if ($type === 's') {
              if (preg_match('/<v>(.*?)<\/v>/is', $inner, $vMatch) === 1) {
                $sharedIndex = (int) trim($vMatch[1]);
                $value = $sharedStrings[$sharedIndex] ?? trim($vMatch[1]);
              }
            }
            elseif ($type === 'inlinestr') {
              if (preg_match_all('/<t(?:\s[^>]*)?>(.*?)<\/t>/is', $inner, $tMatches, PREG_SET_ORDER) > 0 || !empty($tMatches)) {
                $parts = '';
                foreach ($tMatches as $part) {
                  $parts .= html_entity_decode($part[1], ENT_QUOTES | ENT_XML1);
                }
                $value = $parts;
              }
            }
            else {
              if (preg_match('/<v>(.*?)<\/v>/is', $inner, $vMatch) === 1) {
                $value = html_entity_decode(trim($vMatch[1]), ENT_QUOTES | ENT_XML1);
              }
            }

            if ($colIndex >= 0) {
              $cells[$colIndex] = $value;
            }
            else {
              $cells[] = $value;
            }
          }
        }

        if (!empty($cells)) {
          ksort($cells);
          $maxIndex = max(array_keys($cells));
          $dense = array_fill(0, $maxIndex + 1, '');
          foreach ($cells as $idx => $value) {
            $dense[(int) $idx] = $value;
          }
          $rows[] = $dense;
        }
      }
    }

    return $rows;
  }

  /**
   * Convert XLSX column letters (A, B, AA...) to 0-based index.
   */
  protected function columnToIndex(string $colRef): int {
    $colRef = strtoupper(trim($colRef));
    if ($colRef === '') {
      return -1;
    }

    $index = 0;
    $length = strlen($colRef);
    for ($i = 0; $i < $length; $i++) {
      $ord = ord($colRef[$i]);
      if ($ord < 65 || $ord > 90) {
        return -1;
      }
      $index = ($index * 26) + ($ord - 64);
    }

    return $index - 1;
  }

  /**
   * Build namespace map from Namespaces sheet rows.
   *
   * @return array<string, string>
   */
  protected function buildNamespaces(array $rows): array {
    $map = [];
    if (count($rows) < 1) {
      return $map;
    }

    $map['pmsr'] = 'https://pmsr.net/ont/';

    $headers = $rows[0];
    $prefixIdx = -1;
    $uriIdx = -1;

    foreach ($headers as $idx => $header) {
      $token = $this->normalizeHeaderToken((string) $header);
      if ($token === 'prefix' || $token === 'namespace prefix' || $token === 'abbreviation') {
        $prefixIdx = (int) $idx;
      }
      if ($token === 'namespace uri' || $token === 'uri' || $token === 'namespace') {
        $uriIdx = (int) $idx;
      }
    }

    if ($prefixIdx < 0 || $uriIdx < 0) {
      return $map;
    }

    for ($i = 1; $i < count($rows); $i++) {
      $row = $rows[$i];
      if (!is_array($row)) {
        continue;
      }

      $prefix = isset($row[$prefixIdx]) ? strtolower(trim((string) $row[$prefixIdx])) : '';
      $uri = isset($row[$uriIdx]) ? trim((string) $row[$uriIdx]) : '';
      if ($prefix === '' || $uri === '') {
        continue;
      }

      if (!str_ends_with($uri, '/') && !str_ends_with($uri, '#')) {
        $uri .= '/';
      }
      $map[$prefix] = $uri;
    }

    return $map;
  }

  /**
   * Extract URI/process-stem URI from Processes/ProcessStems/InfoSheet.
   *
   * @param array<string, array<int, array<int, string>>> $sheetRows
   *
   * @return array<string, string>
   */
  protected function extractProcessUris(array $sheetRows, array $namespaces): array {
    $result = [
      'uri' => '',
      'process_stem_uri' => '',
      'label' => '',
      'comment' => '',
      'version' => '',
    ];

    if (isset($sheetRows['Processes'])) {
      [$headerMap, $firstData] = $this->findHeaderAndFirstDataRow($sheetRows['Processes']);
      if (!empty($headerMap) && !empty($firstData)) {
        $uriRaw = $this->pickByAliases($firstData, $headerMap, ['hasuri', 'uri']);
        $stemRaw = $this->pickByAliases($firstData, $headerMap, ['prov:wasderivedfrom', 'processstemuri']);
        $result['label'] = $this->pickByAliases($firstData, $headerMap, ['rdfs:label', 'label']);
        $result['comment'] = $this->pickByAliases($firstData, $headerMap, ['rdfs:comment', 'comment']);
        $result['version'] = $this->pickByAliases($firstData, $headerMap, ['vstoi:hasversion', 'hasversion']);
        $result['uri'] = $this->expandUri($uriRaw, $namespaces);
        $result['process_stem_uri'] = $this->expandUri($stemRaw, $namespaces);
      }
    }

    if ($result['process_stem_uri'] === '' && isset($sheetRows['ProcessStems'])) {
      [$headerMap, $firstData] = $this->findHeaderAndFirstDataRow($sheetRows['ProcessStems']);
      if (!empty($headerMap) && !empty($firstData)) {
        if ($result['label'] === '') {
          $result['label'] = $this->pickByAliases($firstData, $headerMap, ['rdfs:label', 'label']);
        }
        if ($result['comment'] === '') {
          $result['comment'] = $this->pickByAliases($firstData, $headerMap, ['rdfs:comment', 'comment']);
        }
        if ($result['version'] === '') {
          $result['version'] = $this->pickByAliases($firstData, $headerMap, ['vstoi:hasversion', 'hasversion']);
        }
        $result['process_stem_uri'] = $this->expandUri(
          $this->pickByAliases($firstData, $headerMap, ['hasuri', 'uri']),
          $namespaces
        );
      }
    }

    if ($result['uri'] === '' && isset($sheetRows['InfoSheet'])) {
      [$headerMap, $firstData] = $this->findHeaderAndFirstDataRow($sheetRows['InfoSheet']);
      if (!empty($headerMap) && !empty($firstData)) {
        $result['uri'] = $this->expandUri(
          $this->pickByAliases($firstData, $headerMap, ['hasuri', 'uri']),
          $namespaces
        );
      }
    }

    return $result;
  }

  /**
   * Extract PI/org identity and scenario-property coverage from STD.
   *
   * @return array<string, mixed>
   */
  protected function extractStdIdentityAndCoverage(array $rows, array $namespaces): array {
    $result = [
      'principal_investigator_uri' => '',
      'principal_investigator_email' => '',
      'principal_investigator_name' => '',
      'organization_uri' => '',
      'organization_name' => '',
      'scenario_properties_with_values' => 0,
    ];

    if (count($rows) < 2) {
      return $result;
    }

    [$headerMap, $headerRowIdx] = $this->findStdHeader($rows);
    if (empty($headerMap) || $headerRowIdx < 0) {
      return $result;
    }

    $dataRow = $this->pickBestStdDataRow($rows, $headerMap, $headerRowIdx);
    if (empty($dataRow)) {
      return $result;
    }

    $piRaw = $this->pickByAliases($dataRow, $headerMap, [
      'principal investigator', 'principal investigator uri', 'hasco:haspi', 'haspi', 'pi',
    ]);
    $orgRaw = $this->pickByAliases($dataRow, $headerMap, [
      'institution', 'hasco:hasinstitution', 'hasinstitution', 'organization', 'organization uri', 'hasorganizationuri',
    ]);

    $result['principal_investigator_uri'] = $this->expandUri($piRaw, $namespaces);
    $result['organization_uri'] = $this->expandUri($orgRaw, $namespaces);
    $result['principal_investigator_email'] = $this->pickByAliases($dataRow, $headerMap, [
      'email', 'principal investigator email', 'pi email', 'hasemail',
    ]);

    $result['principal_investigator_name'] = $this->pickByAliases($dataRow, $headerMap, [
      'principal investigator name', 'pi name', 'principal investigator label', 'pi label', 'name',
    ]);
    $result['organization_name'] = $this->pickByAliases($dataRow, $headerMap, [
      'institution name', 'organization name', 'institution label', 'organization label',
    ]);

    $filledProps = [];
    foreach ($headerMap as $token => $idx) {
      if ($token === 'hasuri') {
        continue;
      }
      for ($r = $headerRowIdx + 1; $r < count($rows); $r++) {
        if (!isset($rows[$r]) || !is_array($rows[$r])) {
          continue;
        }
        $value = isset($rows[$r][$idx]) ? trim((string) $rows[$r][$idx]) : '';
        if ($value !== '') {
          $filledProps[$token] = TRUE;
          break;
        }
      }
    }

    $result['scenario_properties_with_values'] = count($filledProps);
    return $result;
  }

  /**
   * Extract task and used-component counts from Tasks sheet rows.
   *
   * @return array{tasks:int,used_component_instances:int}
   */
  protected function extractTaskCounts(array $rows): array {
    $result = ['tasks' => 0, 'used_component_instances' => 0];
    [$headerMap, $dataStart] = $this->headerMapWithDataStart($rows);
    if (empty($headerMap)) {
      return $result;
    }

    $result['tasks'] = $this->countTasksRows($rows, $dataStart);
    $result['used_component_instances'] = $this->countUsedComponentInstanceRows($rows, $headerMap, $dataStart);
    return $result;
  }

  /**
   * Count non-empty task rows after header.
   */
  protected function countTasksRows(array $rows, int $dataStart): int {
    $taskUriIdx = $this->findTaskUriColumnIndex($rows);
    $count = 0;
    for ($i = $dataStart; $i < count($rows); $i++) {
      if (!isset($rows[$i]) || !is_array($rows[$i])) {
        continue;
      }

      if ($taskUriIdx >= 0) {
        $taskUri = isset($rows[$i][$taskUriIdx]) ? trim((string) $rows[$i][$taskUriIdx]) : '';
        if ($taskUri === '') {
          continue;
        }
      }
      elseif (!$this->rowHasAnyValue($rows[$i])) {
        continue;
      }

        $count++;
    }
    return $count;
  }

  /**
   * Count tasks with at least one URI in usedComponentInstance.
   */
  protected function countUsedComponentInstanceRows(array $rows, array $headerMap, int $dataStart): int {
    $idx = isset($headerMap['vstoi:usescomponentinstance'])
      ? (int) $headerMap['vstoi:usescomponentinstance']
      : -1;
    if ($idx < 0) {
      return 0;
    }

    $taskUriIdx = $this->findTaskUriColumnIndex($rows);

    $count = 0;
    for ($i = $dataStart; $i < count($rows); $i++) {
      $row = $rows[$i] ?? [];
      if (!is_array($row)) {
        continue;
      }

      if ($taskUriIdx >= 0) {
        $taskUri = isset($row[$taskUriIdx]) ? trim((string) $row[$taskUriIdx]) : '';
        if ($taskUri === '') {
          continue;
        }
      }
      elseif (!$this->rowHasAnyValue($row)) {
        continue;
      }

      if (!isset($row[$idx])) {
        continue;
      }

      $rawCell = trim((string) $row[$idx]);
      if ($rawCell === '') {
        continue;
      }

      $parts = preg_split('/\s*[;,|]\s*/', $rawCell);
      if (!is_array($parts) || empty($parts)) {
        $parts = [$rawCell];
      }

      $hasUri = false;
      foreach ($parts as $part) {
        $token = trim((string) $part);
        if ($token !== '' && $this->looksLikeUriToken($token)) {
          $hasUri = true;
          break;
        }
      }

      if ($hasUri) {
        $count++;
      }
    }

    return $count;
  }

  /**
   * Find task URI column index from Tasks header aliases.
   */
  protected function findTaskUriColumnIndex(array $rows): int {
    if (empty($rows) || !isset($rows[0]) || !is_array($rows[0])) {
      return -1;
    }

    foreach ($rows[0] as $idx => $header) {
      $token = $this->normalizeHeaderToken((string) $header);
      if ($token === 'hasuri' || $token === 'hasco:hasuri' || $token === 'uri') {
        return (int) $idx;
      }
    }

    return -1;
  }

  /**
   * Determine whether a token looks like a URI (absolute or compact CURIE).
   */
  protected function looksLikeUriToken(string $value): bool {
    $candidate = trim($value);
    if ($candidate === '') {
      return false;
    }

    if (preg_match('#^https?://#i', $candidate) === 1) {
      return true;
    }

    return preg_match('/^[A-Za-z][A-Za-z0-9_\-]*:\S+$/', $candidate) === 1;
  }

  /**
   * Extract source filename/value from workbook sheets.
   */
  protected function extractSourceValue(array $sheetRows): string {
    $sources = [];

    foreach (['InfoSheet', 'STD'] as $sheetName) {
      if (!isset($sheetRows[$sheetName])) {
        continue;
      }

      [$headerMap, $dataRow] = $this->findHeaderAndFirstDataRow($sheetRows[$sheetName]);
      if (empty($headerMap) || empty($dataRow)) {
        continue;
      }

      foreach (['source', 'source file', 'source document', 'supporting document filename', 'sourcefilename', 'sourcedocumentname'] as $token) {
        $value = $this->pickByAliases($dataRow, $headerMap, [$token]);
        if ($value !== '') {
          $sources[] = $value;
        }
      }
    }

    return !empty($sources) ? $sources[0] : '';
  }

  /**
   * Resolve a label by URI through hascoapi.
   */
  protected function resolveLabelByUri(string $uri): string {
    $candidate = trim($uri);
    if ($candidate === '') {
      return '';
    }

    try {
      $obj = $this->hascoClient->getByUri($candidate);
      if (!is_array($obj) || isset($obj['error'])) {
        return '';
      }

      foreach (['label', 'name'] as $field) {
        if (isset($obj[$field]) && is_string($obj[$field]) && trim((string) $obj[$field]) !== '') {
          return trim((string) $obj[$field]);
        }
      }

      $given = isset($obj['givenName']) && is_string($obj['givenName']) ? trim((string) $obj['givenName']) : '';
      $family = isset($obj['familyName']) && is_string($obj['familyName']) ? trim((string) $obj['familyName']) : '';
      $full = trim($given . ' ' . $family);
      return $full;
    }
    catch (\Throwable $e) {
      return '';
    }
  }

  /**
   * Normalize header token.
   */
  protected function normalizeHeaderToken(string $header): string {
    $token = trim(strtolower($header));
    if ($token === '') {
      return '';
    }
    $token = preg_replace('/\s+/', ' ', $token) ?? $token;
    return trim($token);
  }

  /**
   * Pick first non-empty value for known token aliases.
   */
  protected function pickByAliases(array $row, array $headerMap, array $tokens): string {
    foreach ($tokens as $token) {
      $normalized = $this->normalizeHeaderToken((string) $token);
      if ($normalized === '' || !isset($headerMap[$normalized])) {
        continue;
      }
      $idx = (int) $headerMap[$normalized];
      $value = isset($row[$idx]) ? trim((string) $row[$idx]) : '';
      if ($value !== '') {
        return $value;
      }
    }
    return '';
  }

  /**
   * Expand compact value (e.g., pmsr:PER123) using namespace map.
   */
  protected function expandUri(string $value, array $namespaceMap): string {
    $value = trim($value);
    if ($value === '') {
      return '';
    }

    if (preg_match('#^https?://#i', $value) === 1) {
      return $value;
    }

    if (preg_match('/^([A-Za-z][A-Za-z0-9_\-]*):(\S+)$/', $value, $m) === 1) {
      $prefix = strtolower((string) $m[1]);
      $local = (string) $m[2];
      if (isset($namespaceMap[$prefix]) && $namespaceMap[$prefix] !== '') {
        return $namespaceMap[$prefix] . ltrim($local, '/');
      }
      if ($prefix === 'pmsr') {
        return 'https://pmsr.net/ont/' . ltrim($local, '/');
      }
    }

    return $value;
  }

  /**
   * Find STD header row index and map.
   *
   * @return array{0:array<string,int>,1:int}
   */
  protected function findStdHeader(array $rows): array {
    for ($i = 0; $i < count($rows); $i++) {
      if (!isset($rows[$i]) || !is_array($rows[$i]) || count($rows[$i]) < 2) {
        continue;
      }

      $map = [];
      $tokenSet = [];
      foreach ($rows[$i] as $idx => $header) {
        $token = $this->normalizeHeaderToken((string) $header);
        if ($token === '') {
          continue;
        }
        if (!isset($map[$token])) {
          $map[$token] = (int) $idx;
        }
        $tokenSet[$token] = TRUE;
      }

      $hasUri = isset($tokenSet['hasuri']);
      $hasOrg = isset($tokenSet['institution']) || isset($tokenSet['hasco:hasinstitution']) || isset($tokenSet['organization']) || isset($tokenSet['organization uri']) || isset($tokenSet['hasorganizationuri']);
      $hasPi = isset($tokenSet['principal investigator']) || isset($tokenSet['principal investigator uri']) || isset($tokenSet['hasco:haspi']) || isset($tokenSet['haspi']) || isset($tokenSet['pi']);

      if ($hasUri && $hasOrg && $hasPi) {
        return [$map, $i];
      }
    }

    return [[], -1];
  }

  /**
   * Pick best STD data row after STD header.
   */
  protected function pickBestStdDataRow(array $rows, array $headerMap, int $headerRowIdx): array {
    $bestScore = -999;
    $bestRow = [];

    for ($i = $headerRowIdx + 1; $i < min(count($rows), $headerRowIdx + 40); $i++) {
      if (!isset($rows[$i]) || !is_array($rows[$i])) {
        continue;
      }

      $row = $rows[$i];
      $hasUri = $this->pickByAliases($row, $headerMap, ['hasuri']) !== '';
      $org = $this->pickByAliases($row, $headerMap, ['institution', 'hasco:hasinstitution', 'organization', 'organization uri', 'hasorganizationuri']);
      $pi = $this->pickByAliases($row, $headerMap, ['principal investigator', 'principal investigator uri', 'hasco:haspi', 'haspi', 'pi']);

      if (!$hasUri && $org === '' && $pi === '') {
        continue;
      }

      $score = 0;
      if ($hasUri) {
        $score += 1;
      }
      if ($org !== '') {
        $score += 1;
      }
      if ($pi !== '') {
        $score += 1;
      }

      if (!$this->isPlaceholderIdentity($org) && !$this->isPlaceholderIdentity($pi)) {
        $score += 3;
      }

      if ($score > $bestScore) {
        $bestScore = $score;
        $bestRow = $row;
      }
    }

    return $bestScore < 0 ? [] : $bestRow;
  }

  /**
   * Detect known template placeholder identity values.
   */
  protected function isPlaceholderIdentity(string $value): bool {
    $candidate = strtolower(trim($value));
    if ($candidate === '') {
      return false;
    }

    if (str_starts_with($candidate, 'https://pmsr.net/ont/')) {
      $candidate = 'pmsr:' . substr($candidate, strlen('https://pmsr.net/ont/'));
    }

    return in_array($candidate, [
      'pmsr:org/ess',
      'pmsr:per/pi-001',
    ], TRUE);
  }

  /**
   * Return first row interpreted as header and first non-empty data row.
   *
   * @return array{0:array<string,int>,1:array<int,string>}
   */
  protected function findHeaderAndFirstDataRow(array $rows): array {
    [$headerMap, $dataStart] = $this->headerMapWithDataStart($rows);
    if (empty($headerMap)) {
      return [[], []];
    }

    for ($i = $dataStart; $i < count($rows); $i++) {
      if (!isset($rows[$i]) || !is_array($rows[$i])) {
        continue;
      }
      if ($this->rowHasAnyValue($rows[$i])) {
        return [$headerMap, $rows[$i]];
      }
    }

    return [$headerMap, []];
  }

  /**
   * Build header map and data start index from first row with tokens.
   *
   * @return array{0:array<string,int>,1:int}
   */
  protected function headerMapWithDataStart(array $rows): array {
    if (empty($rows) || !isset($rows[0]) || !is_array($rows[0])) {
      return [[], 1];
    }

    $headerMap = [];
    foreach ($rows[0] as $idx => $header) {
      $token = $this->normalizeHeaderToken((string) $header);
      if ($token !== '' && !isset($headerMap[$token])) {
        $headerMap[$token] = (int) $idx;
      }
    }

    return [empty($headerMap) ? [] : $headerMap, 1];
  }

  /**
   * Check if row has at least one non-empty cell.
   */
  protected function rowHasAnyValue(array $row): bool {
    foreach ($row as $cell) {
      if (trim((string) $cell) !== '') {
        return TRUE;
      }
    }
    return FALSE;
  }

}
