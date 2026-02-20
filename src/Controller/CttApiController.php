<?php

namespace Drupal\ctt\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\ctt\Service\CttHascoClient;

/**
 * API proxy controller — sits between the CTT React editor and hascoapi.
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
      $result = $this->hascoClient->getByUri($uri);
      return new JsonResponse($result);
    }
    catch (\Exception $e) {
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
  public function proxyHasco($proxy_path, Request $request) {
    $endpoint = '/hascoapi/api/' . ltrim(rawurldecode($proxy_path), '/');
    $options = [];

    $query = $request->query->all();
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

}
