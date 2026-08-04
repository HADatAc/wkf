<?php

declare(strict_types=1);

namespace Drupal\Tests\ctt\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Tests\BrowserTestBase;
use Drupal\ctt\Controller\CttApiController;
use Drupal\ctt\Service\CttHascoClient;

/**
 * Covers server-side structured submission validation endpoint.
 *
 * @group ctt
 */
final class CttSubmissionValidationApiTest extends BrowserTestBase {

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

  public function testSubmissionValidationEndpointContractAndPermissions(): void {
    $studyUri = 'http://example.org/study/validation-api';
    $processUri = 'http://example.org/workflow/validation-api';

    $accessOnly = $this->createUser(['access ctt editor']);
    $submitter = $this->createUser(['submit ctt workflow']);
    $nonOwnerSubmitter = $this->createUser(['submit ctt workflow']);
    \Drupal::state()->set('ctt.study_owner_email.' . sha1($studyUri), (string) $submitter->getEmail());

    $this->drupalLogin($accessOnly);
    $this->drupalGet('/workflow/api/submission/validate', [
      'query' => [
        'studyUri' => $studyUri,
        'processUri' => $processUri,
      ],
    ]);
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($nonOwnerSubmitter);
    $this->drupalGet('/workflow/api/submission/validate', [
      'query' => [
        'studyUri' => $studyUri,
        'processUri' => $processUri,
        'requestedStatus' => 'under review',
        'mode' => 'submission',
        'dataFileUri' => 'http://example.org/datafile/validation-api-output',
      ],
    ]);
    $this->assertSession()->statusCodeEquals(403);

    $nonOwnerPayload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($nonOwnerPayload);
    $this->assertFalse((bool) ($nonOwnerPayload['isValid'] ?? TRUE));
    $nonOwnerCodes = $this->extractIssueCodes($nonOwnerPayload['issues'] ?? []);
    $this->assertContains('workflow_owner_required', $nonOwnerCodes);

    $this->drupalLogin($submitter);
    $this->drupalGet('/workflow/api/submission/validate', [
      'query' => [
        'studyUri' => $studyUri,
        'requestedStatus' => 'current',
      ],
    ]);
    $this->assertSession()->statusCodeEquals(200);

    $invalidPayload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($invalidPayload);
    $this->assertFalse((bool) ($invalidPayload['isValid'] ?? TRUE));
    $this->assertArrayHasKey('issues', $invalidPayload);

    $codes = $this->extractIssueCodes($invalidPayload['issues'] ?? []);
    $this->assertContains('missing_or_invalid_process_uri', $codes);
    $this->assertContains('missing_submission_output', $codes);
    $this->assertContains('current_requires_admin', $codes);

    \Drupal::state()->set('ctt.study_process.' . sha1($studyUri), $processUri);

    $this->drupalGet('/workflow/api/submission/validate', [
      'query' => [
        'studyUri' => $studyUri,
        'processUri' => $processUri,
        'requestedStatus' => 'under review',
        'mode' => 'submission',
        'dataFileUri' => 'http://example.org/datafile/validation-api-output',
      ],
    ]);
    $this->assertSession()->statusCodeEquals(200);

    $validPayload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($validPayload);
    $this->assertTrue((bool) ($validPayload['isValid'] ?? FALSE));
    $this->assertSame('under review', (string) ($validPayload['normalized']['requestedStatus'] ?? ''));
    $this->assertSame('submission', (string) ($validPayload['normalized']['mode'] ?? ''));
    $this->assertSame($studyUri, (string) ($validPayload['normalized']['studyUri'] ?? ''));
    $this->assertSame($processUri, (string) ($validPayload['normalized']['processUri'] ?? ''));

    $this->assertArrayHasKey('editorial', $validPayload);
    $this->assertArrayHasKey('states', $validPayload['editorial']);
    $this->assertContains('draft', $validPayload['editorial']['states']);
    $this->assertContains('under review', $validPayload['editorial']['states']);
    $this->assertContains('current', $validPayload['editorial']['states']);
    $this->assertContains('deprecated', $validPayload['editorial']['states']);
    $this->assertArrayHasKey('allowedNextStatuses', $validPayload['editorial']);

    $this->assertArrayHasKey('association', $validPayload);
    $this->assertArrayHasKey('studyProcess', $validPayload['association']);
    $this->assertArrayHasKey('storedProcessUri', $validPayload['association']['studyProcess']);
    $this->assertSame($processUri, (string) ($validPayload['association']['studyProcess']['providedProcessUri'] ?? ''));
    $this->assertArrayHasKey('matches', $validPayload['association']['studyProcess']);
  }

  public function testSubmissionValidationTransitionsAndAssociationWarnings(): void {
    $submitter = $this->createUser(['submit ctt workflow']);
    $this->drupalLogin($submitter);

    $warningStudyUri = 'http://example.org/study/validation-warning';
    $warningProcessUri = 'http://example.org/workflow/validation-warning';
    \Drupal::state()->set('ctt.study_owner_email.' . sha1($warningStudyUri), (string) $submitter->getEmail());

    $this->drupalGet('/workflow/api/submission/validate', [
      'query' => [
        'studyUri' => $warningStudyUri,
        'processUri' => $warningProcessUri,
        'mode' => 'submission',
        'currentStatus' => 'draft',
        'requestedStatus' => 'under review',
        'dataFileUri' => 'http://example.org/datafile/validation-warning-output',
      ],
    ]);
    $this->assertSession()->statusCodeEquals(200);

    $warningPayload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($warningPayload);
    $this->assertTrue((bool) ($warningPayload['isValid'] ?? FALSE));
    $warningCodes = $this->extractIssueCodes($warningPayload['issues'] ?? []);
    $this->assertContains('study_process_not_associated', $warningCodes);

    $studyUri = 'http://example.org/study/validation-transition';
    $processUri = 'http://example.org/workflow/validation-transition';
    \Drupal::state()->set('ctt.study_process.' . sha1($studyUri), $processUri);
    \Drupal::state()->set('ctt.study_owner_email.' . sha1($studyUri), (string) $submitter->getEmail());

    $this->drupalGet('/workflow/api/submission/validate', [
      'query' => [
        'studyUri' => $studyUri,
        'processUri' => $processUri,
        'mode' => 'submission',
        'currentStatus' => 'current',
        'requestedStatus' => 'under review',
        'dataFileUri' => 'http://example.org/datafile/validation-transition-output',
      ],
    ]);
    $this->assertSession()->statusCodeEquals(200);

    $invalidTransitionPayload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($invalidTransitionPayload);
    $this->assertFalse((bool) ($invalidTransitionPayload['isValid'] ?? TRUE));
    $invalidTransitionCodes = $this->extractIssueCodes($invalidTransitionPayload['issues'] ?? []);
    $this->assertContains('invalid_editorial_transition', $invalidTransitionCodes);

    $this->drupalGet('/workflow/api/submission/validate', [
      'query' => [
        'studyUri' => $studyUri,
        'processUri' => $processUri,
        'mode' => 'submission',
        'currentStatus' => 'draft',
        'requestedStatus' => 'under review',
        'dataFileUri' => 'http://example.org/datafile/validation-transition-output',
      ],
    ]);
    $this->assertSession()->statusCodeEquals(200);

    $validTransitionPayload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($validTransitionPayload);
    $this->assertTrue((bool) ($validTransitionPayload['isValid'] ?? FALSE));
    $this->assertSame('draft', (string) ($validTransitionPayload['normalized']['currentStatus'] ?? ''));
    $this->assertSame('under review', (string) ($validTransitionPayload['normalized']['requestedStatus'] ?? ''));
    $this->assertSame($processUri, (string) ($validTransitionPayload['association']['studyProcess']['providedProcessUri'] ?? ''));
    $this->assertArrayHasKey('storedProcessUri', $validTransitionPayload['association']['studyProcess']);
    $this->assertArrayHasKey('matches', $validTransitionPayload['association']['studyProcess']);
    $this->assertContains('under review', $validTransitionPayload['editorial']['allowedNextStatuses'] ?? []);
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

  public function testTaskHierarchyValidationIssuesAreReported(): void {
    $submitter = $this->createUser(['submit ctt workflow']);
    $this->drupalLogin($submitter);

    $studyUri = 'http://example.org/study/validation-hierarchy';
    $processUri = 'http://example.org/workflow/validation-hierarchy';
    \Drupal::state()->set('ctt.study_owner_email.' . sha1($studyUri), (string) $submitter->getEmail());

    $fakeProcess = [
      'uri' => $processUri,
      'hasTopTaskUri' => 'http://example.org/task/top-A',
    ];

    // Two roots: top-A and top-B. Also topTask mismatch from process metadata.
    $fakeTasks = [
      [
        'uri' => 'http://example.org/task/top-A',
        'hasSubtaskUris' => ['http://example.org/task/child-A1'],
      ],
      [
        'uri' => 'http://example.org/task/child-A1',
        'hasSupertaskUri' => 'http://example.org/task/top-A',
      ],
      [
        'uri' => 'http://example.org/task/top-B',
      ],
    ];

    $mock = $this->getMockBuilder(CttHascoClient::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getByUri', 'getTasksByProcess'])
      ->getMock();

    $mock->method('getByUri')->willReturn($fakeProcess);
    $mock->method('getTasksByProcess')->willReturn($fakeTasks);

    $controller = CttApiController::create($this->container);
    $ref = new \ReflectionProperty($controller, 'hascoClient');
    $ref->setAccessible(TRUE);
    $ref->setValue($controller, $mock);

    $request = \Symfony\Component\HttpFoundation\Request::create('/workflow/api/submission/validate', 'GET', [
      'studyUri' => $studyUri,
      'processUri' => $processUri,
      'mode' => 'submission',
      'currentStatus' => 'draft',
      'requestedStatus' => 'under review',
      'dataFileUri' => 'http://example.org/datafile/validation-hierarchy-output',
    ]);

    $response = $controller->validateSubmission($request);
    $payload = Json::decode((string) $response->getContent());
    $this->assertIsArray($payload);
    $this->assertFalse((bool) ($payload['isValid'] ?? TRUE));

    $codes = $this->extractIssueCodes($payload['issues'] ?? []);
    $this->assertContains('invalid_top_level_task_count', $codes);
  }

}
