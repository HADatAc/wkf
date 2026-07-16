<?php

declare(strict_types=1);

namespace Drupal\Tests\ctt\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\BrowserTestBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Covers owner guard on mutable process/task API endpoints.
 *
 * @group ctt
 */
final class CttProcessTaskOwnershipGuardApiTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['block', 'ctt', 'std'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'hasco_barrio';

  /**
   * {@inheritdoc}
   */
  protected $strictConfigSchema = FALSE;

  public function testProcessAndTaskMutationsRequireWorkflowOwner(): void {
    $owner = $this->createUser(['edit ctt workflow', 'create ctt workflow']);
    $nonOwner = $this->createUser(['edit ctt workflow', 'create ctt workflow']);

    $processUri = 'http://example.org/workflow/process-owner-guard';
    $taskUri = 'http://example.org/task/process-owner-guard-task';

    \Drupal::state()->set('ctt.process_owner_email.' . sha1($processUri), (string) $owner->getEmail());
    \Drupal::state()->set('ctt.task_process_uri.' . sha1($taskUri), $processUri);

    $controller = \Drupal::service('class_resolver')->getInstanceFromDefinition('Drupal\\ctt\\Controller\\CttApiController');

    $blockedCreateProcess = $this->invokeAs($nonOwner, function () use ($controller, $owner): JsonResponse {
      $request = Request::create(
        '/workflow/api/process/create',
        'POST',
        [],
        [],
        [],
        [],
        Json::encode([
          'uri' => 'http://example.org/workflow/blocked-create',
          'label' => 'Blocked create',
          'hasSIRManagerEmail' => (string) $owner->getEmail(),
        ])
      );
      return $controller->createProcess($request);
    });
    $this->assertSame(403, $blockedCreateProcess->getStatusCode());
    $blockedCreateProcessCodes = $this->extractIssueCodes($this->decodePayload($blockedCreateProcess)['issues'] ?? []);
    $this->assertContains('workflow_owner_required', $blockedCreateProcessCodes);

    $blockedDeleteProcess = $this->invokeAs($nonOwner, function () use ($controller, $processUri): JsonResponse {
      $request = Request::create('/workflow/api/process/delete?uri=' . rawurlencode($processUri), 'DELETE');
      return $controller->deleteProcess($request);
    });
    $this->assertSame(403, $blockedDeleteProcess->getStatusCode());
    $blockedDeleteProcessCodes = $this->extractIssueCodes($this->decodePayload($blockedDeleteProcess)['issues'] ?? []);
    $this->assertContains('workflow_owner_required', $blockedDeleteProcessCodes);

    $blockedCreateVersion = $this->invokeAs($nonOwner, function () use ($controller, $processUri): JsonResponse {
      $request = Request::create(
        '/workflow/api/process/versions?uri=' . rawurlencode($processUri),
        'POST',
        [],
        [],
        [],
        [],
        Json::encode(['changelog' => 'blocked'])
      );
      return $controller->createProcessVersionQuery($request);
    });
    $this->assertSame(403, $blockedCreateVersion->getStatusCode());
    $blockedCreateVersionCodes = $this->extractIssueCodes($this->decodePayload($blockedCreateVersion)['issues'] ?? []);
    $this->assertContains('workflow_owner_required', $blockedCreateVersionCodes);

    $blockedCreateTask = $this->invokeAs($nonOwner, function () use ($controller, $processUri, $taskUri): JsonResponse {
      $request = Request::create(
        '/workflow/api/task/create',
        'POST',
        [],
        [],
        [],
        [],
        Json::encode([
          'uri' => $taskUri,
          'label' => 'Blocked task',
          'processUri' => $processUri,
        ])
      );
      return $controller->createTask($request);
    });
    $this->assertSame(403, $blockedCreateTask->getStatusCode());
    $blockedCreateTaskCodes = $this->extractIssueCodes($this->decodePayload($blockedCreateTask)['issues'] ?? []);
    $this->assertContains('workflow_owner_required', $blockedCreateTaskCodes);

    $blockedDeleteTask = $this->invokeAs($nonOwner, function () use ($controller, $taskUri): JsonResponse {
      $request = Request::create('/workflow/api/task/delete?uri=' . rawurlencode($taskUri), 'DELETE');
      return $controller->deleteTask($request);
    });
    $this->assertSame(403, $blockedDeleteTask->getStatusCode());
    $blockedDeleteTaskCodes = $this->extractIssueCodes($this->decodePayload($blockedDeleteTask)['issues'] ?? []);
    $this->assertContains('workflow_owner_required', $blockedDeleteTaskCodes);

    $blockedAssignInstruments = $this->invokeAs($nonOwner, function () use ($controller, $taskUri): JsonResponse {
      $request = Request::create(
        '/workflow/api/task/instruments?uri=' . rawurlencode($taskUri),
        'PUT',
        [],
        [],
        [],
        [],
        Json::encode([
          'instruments' => [
            [
              'instrumentUri' => 'http://example.org/instrument/blocked',
              'componentUris' => ['http://example.org/component/blocked'],
            ],
          ],
        ])
      );
      return $controller->setTaskRequiredInstrumentsQuery($request);
    });
    $this->assertSame(403, $blockedAssignInstruments->getStatusCode());
    $blockedAssignInstrumentsCodes = $this->extractIssueCodes($this->decodePayload($blockedAssignInstruments)['issues'] ?? []);
    $this->assertContains('workflow_owner_required', $blockedAssignInstrumentsCodes);

    $ownerCreateVersion = $this->invokeAs($owner, function () use ($controller, $processUri): JsonResponse {
      $request = Request::create(
        '/workflow/api/process/versions?uri=' . rawurlencode($processUri),
        'POST',
        [],
        [],
        [],
        [],
        Json::encode(['changelog' => 'owner-version'])
      );
      return $controller->createProcessVersionQuery($request);
    });
    $this->assertSame(201, $ownerCreateVersion->getStatusCode());

    $ownerCreateVersionPayload = $this->decodePayload($ownerCreateVersion);
    $this->assertSame($processUri, (string) ($ownerCreateVersionPayload['processUri'] ?? ''));
    $this->assertSame('owner-version', (string) ($ownerCreateVersionPayload['changelog'] ?? ''));
  }

  public function testTaskCreateBootstrapsOwnerAndStripsProcessUri(): void {
    $editor = $this->createUser(['edit ctt workflow']);
    $processUri = 'http://example.org/workflow#/bootstrap-owner';
    $taskUri = 'http://example.org/task/bootstrap-owner-task';

    $mockHascoClient = new class {
      /**
       * @var array<string, mixed>
       */
      public array $capturedCreateElementCall = [];

      /**
       * @return array<string, mixed>
       */
      public function getByUri(string $uri): array {
        // Deliberately omit manager/owner fields to exercise fallback owner bootstrap.
        return ['uri' => $uri];
      }

      /**
       * @param array<string, mixed> $data
       * @return array<string, mixed>
       */
      public function createElement(string $type, array $data): array {
        $this->capturedCreateElementCall = [
          'type' => $type,
          'data' => $data,
        ];

        return [
          'uri' => (string) ($data['uri'] ?? ''),
          'isSuccessful' => TRUE,
        ];
      }
    };

    $this->container->set('ctt.hasco_client', $mockHascoClient);
    $controller = \Drupal::service('class_resolver')->getInstanceFromDefinition('Drupal\\ctt\\Controller\\CttApiController');

    $response = $this->invokeAs($editor, function () use ($controller, $processUri, $taskUri): JsonResponse {
      $request = Request::create(
        '/workflow/api/task/create',
        'POST',
        [],
        [],
        [],
        [],
        Json::encode([
          'uri' => $taskUri,
          'label' => 'Bootstrap owner task',
          'processUri' => $processUri,
        ])
      );

      return $controller->createTask($request);
    });

    $this->assertSame(201, $response->getStatusCode());
    $this->assertSame('task', (string) ($mockHascoClient->capturedCreateElementCall['type'] ?? ''));

    $upstreamPayload = $mockHascoClient->capturedCreateElementCall['data'] ?? [];
    $this->assertIsArray($upstreamPayload);
    $this->assertArrayNotHasKey('processUri', $upstreamPayload);
    $this->assertSame($taskUri, (string) ($upstreamPayload['uri'] ?? ''));

    $normalizedProcessUri = str_replace('#/', '#', $processUri);
    $ownerStateKey = 'ctt.process_owner_email.' . sha1($normalizedProcessUri);
    $this->assertSame((string) $editor->getEmail(), (string) \Drupal::state()->get($ownerStateKey));

    $taskProcessKey = 'ctt.task_process_uri.' . sha1($taskUri);
    $this->assertSame($processUri, (string) \Drupal::state()->get($taskProcessKey));
  }

  private function invokeAs(AccountInterface $account, callable $callback): JsonResponse {
    $switcher = \Drupal::service('account_switcher');
    $switcher->switchTo($account);

    try {
      $response = $callback();
      $this->assertInstanceOf(JsonResponse::class, $response);
      return $response;
    }
    finally {
      $switcher->switchBack();
    }
  }

  /**
   * @return array<string, mixed>
   */
  private function decodePayload(JsonResponse $response): array {
    $payload = Json::decode((string) $response->getContent());
    return is_array($payload) ? $payload : [];
  }

  /**
   * @param array<int, mixed> $issues
   * @return array<int, string>
   */
  private function extractIssueCodes(array $issues): array {
    $codes = [];
    foreach ($issues as $issue) {
      if (is_array($issue) && isset($issue['code']) && is_string($issue['code'])) {
        $codes[] = $issue['code'];
      }
    }
    return $codes;
  }

}
