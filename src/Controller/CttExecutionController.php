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

  protected static function isUri(string $value): bool {
    $v = trim($value);
    return $v !== '' && (str_starts_with($v, 'http://') || str_starts_with($v, 'https://'));
  }

  protected static function csvEscape(string $value): string {
    $needs_quotes = strpbrk($value, ",\n\r\t") !== FALSE;
    $escaped = str_replace('"', '""', $value);
    return $needs_quotes ? '"' . $escaped . '"' : $escaped;
  }

  protected static function snapshotToCsv(array $snapshot, array $meta = []): string {
    $lines = [];

    // Metadata block (commented) for humans.
    if (!empty($meta)) {
      foreach ($meta as $k => $v) {
        $k = trim((string) $k);
        if ($k === '') {
          continue;
        }
        $lines[] = '# ' . $k . ': ' . str_replace(["\r", "\n"], ' ', (string) $v);
      }
      $lines[] = '#';
    }

    $header = [
      'record_type',
      'ts',
      'study_uri',
      'process_uri',
      'da_uri',
      'datafile_uri',
      'node_id',
      'node_label',
      'kind_or_type',
      'instrument_uri',
      'instrument_label',
      'component_uri',
      'component_label',
      'value',
      'display_value',
      'message',
      'value_json',
    ];
    $lines[] = implode(',', $header);

    $metaStudy = (string) ($meta['studyUri'] ?? '');
    $metaProcess = (string) ($meta['processUri'] ?? '');
    $metaDa = (string) ($meta['daUri'] ?? '');
    $metaDataFile = (string) ($meta['dataFileUri'] ?? '');

    $events = $snapshot['events'] ?? [];
    if (is_array($events)) {
      foreach ($events as $e) {
        if (!is_array($e)) {
          continue;
        }
        $row = [
          'event',
          (string) ($e['ts'] ?? ''),
          $metaStudy,
          $metaProcess,
          $metaDa,
          $metaDataFile,
          (string) ($e['nodeId'] ?? ''),
          '',
          (string) ($e['type'] ?? ''),
          '',
          '',
          '',
          '',
          '',
          '',
          '',
          (string) ($e['message'] ?? ''),
          '',
        ];
        $lines[] = implode(',', array_map([static::class, 'csvEscape'], $row));
      }
    }

    $answers = $snapshot['answers'] ?? [];
    if (is_array($answers)) {
      foreach ($answers as $a) {
        if (!is_array($a)) {
          continue;
        }
        $value_json = '';
        try {
          $value_json = json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        catch (\Throwable $ignored) {
          $value_json = '';
        }

        $kind = (string) ($a['kind'] ?? '');
        if ($kind === 'instrument-form' && !empty($a['fields']) && is_array($a['fields'])) {
          foreach ($a['fields'] as $f) {
            if (!is_array($f)) {
              continue;
            }
            $row = [
              'answer',
              (string) ($a['ts'] ?? ''),
              $metaStudy,
              $metaProcess,
              $metaDa,
              $metaDataFile,
              (string) ($a['nodeId'] ?? ''),
              (string) ($a['nodeLabel'] ?? ''),
              'instrument-form-field',
              (string) ($a['instrumentUri'] ?? ''),
              (string) ($a['instrumentLabel'] ?? ''),
              (string) ($f['componentUri'] ?? ''),
              (string) ($f['label'] ?? ''),
              (string) ($f['value'] ?? ''),
              (string) ($f['displayValue'] ?? ''),
              '',
              $value_json,
            ];
            $lines[] = implode(',', array_map([static::class, 'csvEscape'], $row));
          }
          continue;
        }

        $value = '';
        $displayValue = '';
        if ($kind === 'choice') {
          $value = (string) ($a['selectionId'] ?? '');
          $displayValue = (string) ($a['selectionLabel'] ?? '');
        }
        elseif ($kind === 'number') {
          $value = (string) ($a['value'] ?? '');
          $displayValue = $value;
        }
        elseif ($kind === 'text') {
          $value = (string) ($a['value'] ?? '');
          $displayValue = $value;
        }
        elseif ($kind === 'confirm') {
          $value = ((bool) ($a['value'] ?? FALSE)) ? 'true' : 'false';
          $displayValue = $value;
        }

        $row = [
          'answer',
          (string) ($a['ts'] ?? ''),
          $metaStudy,
          $metaProcess,
          $metaDa,
          $metaDataFile,
          (string) ($a['nodeId'] ?? ''),
          (string) ($a['nodeLabel'] ?? ''),
          $kind,
          (string) ($a['instrumentUri'] ?? ''),
          (string) ($a['instrumentLabel'] ?? ''),
          '',
          '',
          $value,
          $displayValue,
          (string) ($a['prompt'] ?? ''),
          $value_json,
        ];
        $lines[] = implode(',', array_map([static::class, 'csvEscape'], $row));
      }
    }

    return implode("\n", $lines) . "\n";
  }

  /**
   * Create a stub "execution output" and register it as DataFile + DA.
   *
   * This is a Drupal-side helper (hascoapi is off-limits).
   */
  protected function createSimulationRun(string $decodedStudyUri, ?string $processUri = NULL): array {
    $logger = \Drupal::logger('ctt.execution');
    $api = \Drupal::service('rep.api_connector');
    $fs = \Drupal::service('file_system');

    $userEmail = $this->currentUser()->getEmail();
    $studyBasename = basename($decodedStudyUri);
    if (empty($studyBasename)) {
      $studyBasename = 'study';
    }

    $timestamp = (new \DateTimeImmutable('now'))->format('Ymd_His');
    // Naming convention required by org: DA-XXXXXX
    $suffix = strtoupper(bin2hex(random_bytes(3))); // 6 hex chars
    $filename = 'DA-' . $suffix . '.csv';

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

  public function createExecution(string $studyuri) {
    $decodedStudyUri = base64_decode($studyuri);
    if (empty($decodedStudyUri)) {
      return new Response('Invalid study URI.', 400);
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
    \Drupal::state()->set('ctt.study_process.' . sha1($decodedStudyUri), $processUri);

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

      $create = $this->createSimulationRun($studyUri, $processUri !== '' ? $processUri : NULL);
      if (empty($create['ok']) || empty($create['daUri'])) {
        return new JsonResponse(['ok' => FALSE, 'error' => 'Failed to create DA/DataFile for this execution.'], 500);
      }

      $daUri = (string) $create['daUri'];
      $ctx = \Drupal::state()->get('ctt.execution.' . sha1($daUri));
      if (empty($ctx) || !is_array($ctx)) {
        return new JsonResponse(['ok' => FALSE, 'error' => 'Execution context could not be loaded after creation.'], 500);
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

    $csv = static::snapshotToCsv($snapshot, [
      'studyUri' => (string) ($ctx['studyUri'] ?? ''),
      'processUri' => (string) ($ctx['processUri'] ?? ''),
      'daUri' => (string) ($ctx['daUri'] ?? ''),
      'dataFileUri' => $dataFileUri,
      'generatedAt' => (new \DateTimeImmutable('now'))->format(DATE_ATOM),
    ]);

    // 1) Overwrite the Drupal-local placeholder file (private://...)
    try {
      $fs = \Drupal::service('file_system');
      $real = $fs->realpath($fileEntity->getFileUri());
      if (!$real) {
        throw new \RuntimeException('Could not resolve file path.');
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
