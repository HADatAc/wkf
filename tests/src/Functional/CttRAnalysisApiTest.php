<?php

declare(strict_types=1);

namespace Drupal\Tests\ctt\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Tests\BrowserTestBase;

/**
 * Covers Epic 5 R analysis API and page contract.
 *
 * @group ctt
 */
final class CttRAnalysisApiTest extends BrowserTestBase {

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

  public function testRAnalysisPageAndValidateOnlyContract(): void {
    $studyUri = 'http://example.org/study/r-analysis';
    $processUri = 'http://example.org/workflow/r-analysis';

    $accessOnly = $this->createUser(['access ctt editor']);
    $submitter = $this->createUser(['submit ctt workflow']);

    $this->drupalLogin($accessOnly);
    $this->drupalGet('/workflow/r-analysis');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalGet('/workflow/api/r-analysis/execute', [
      'query' => [
        'validateOnly' => '1',
        'studyUri' => $studyUri,
        'processUri' => $processUri,
        'toolUri' => 'http://example.org/tool/missing',
      ],
    ]);
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($submitter);
    $this->drupalGet('/workflow/r-analysis');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('No mocked data: this interface only uses real catalog entries, real study associations, and real backend execution responses.');
    $this->assertSession()->pageTextContains('Run R Analysis');

    $this->drupalGet('/workflow/api/repo/analytical-tools', [
      'query' => [
        'action' => 'upsert',
        'name' => 'R Survival Tool',
        'language' => 'R',
        'status' => 'current',
        'artifactFilename' => 'survival.R',
      ],
    ]);
    $this->assertSession()->statusCodeEquals(200);
    $rToolPayload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($rToolPayload);
    $this->assertTrue((bool) ($rToolPayload['isValid'] ?? FALSE));
    $rToolUri = (string) ($rToolPayload['tool']['toolUri'] ?? '');
    $this->assertNotSame('', $rToolUri);

    $this->drupalGet('/workflow/api/repo/analytical-tools', [
      'query' => [
        'action' => 'upsert',
        'name' => 'Python Baseline Tool',
        'language' => 'Python',
        'status' => 'current',
        'artifactFilename' => 'baseline.py',
      ],
    ]);
    $this->assertSession()->statusCodeEquals(200);
    $pythonToolPayload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($pythonToolPayload);
    $this->assertTrue((bool) ($pythonToolPayload['isValid'] ?? FALSE));
    $pythonToolUri = (string) ($pythonToolPayload['tool']['toolUri'] ?? '');
    $this->assertNotSame('', $pythonToolUri);

    \Drupal::state()->set('ctt.study_process.' . sha1($studyUri), $processUri);

    $this->drupalGet('/workflow/api/submission/associations', [
      'query' => [
        'studyUri' => $studyUri,
        'processUri' => $processUri,
        'datasetUris' => ['http://example.org/dataset/analysis-v1'],
        'variableUris' => ['http://example.org/variable/heart-rate'],
      ],
    ]);
    $this->assertSession()->statusCodeEquals(200);

    $this->drupalGet('/workflow/api/r-analysis/execute', [
      'query' => [
        'validateOnly' => '1',
        'studyUri' => $studyUri,
        'processUri' => $processUri,
        'toolUri' => $rToolUri,
        'arguments' => Json::encode(['alpha' => 0.05]),
      ],
    ]);
    $this->assertSession()->statusCodeEquals(200);

    $validateOnlyPayload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($validateOnlyPayload);
    $this->assertTrue((bool) ($validateOnlyPayload['isSuccessful'] ?? FALSE));
    $this->assertFalse((bool) ($validateOnlyPayload['executed'] ?? TRUE));
    $this->assertSame($studyUri, (string) ($validateOnlyPayload['preparedRequest']['studyUri'] ?? ''));
    $this->assertSame($processUri, (string) ($validateOnlyPayload['preparedRequest']['processUri'] ?? ''));
    $this->assertSame($rToolUri, (string) ($validateOnlyPayload['preparedRequest']['tool']['toolUri'] ?? ''));
    $this->assertSame(1, (int) ($validateOnlyPayload['preparedRequest']['associations']['counts']['datasets'] ?? -1));
    $this->assertSame(1, (int) ($validateOnlyPayload['preparedRequest']['associations']['counts']['variables'] ?? -1));

    $this->drupalGet('/workflow/api/r-analysis/execute', [
      'query' => [
        'validateOnly' => '1',
        'studyUri' => $studyUri,
        'processUri' => $processUri,
        'toolUri' => $pythonToolUri,
      ],
    ]);
    $this->assertSession()->statusCodeEquals(400);

    $invalidLanguagePayload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($invalidLanguagePayload);
    $this->assertFalse((bool) ($invalidLanguagePayload['isValid'] ?? TRUE));
    $issueCodes = $this->extractIssueCodes($invalidLanguagePayload['issues'] ?? []);
    $this->assertContains('invalid_tool_language', $issueCodes);
  }

  public function testRAnalysisValidationErrors(): void {
    $submitter = $this->createUser(['submit ctt workflow']);
    $this->drupalLogin($submitter);

    $this->drupalGet('/workflow/api/r-analysis/execute', [
      'query' => [
        'validateOnly' => '1',
        'studyUri' => 'not-a-uri',
        'processUri' => '',
        'toolUri' => '',
        'arguments' => '{bad-json}',
      ],
    ]);
    $this->assertSession()->statusCodeEquals(400);

    $payload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($payload);
    $this->assertFalse((bool) ($payload['isValid'] ?? TRUE));

    $codes = $this->extractIssueCodes($payload['issues'] ?? []);
    $this->assertContains('missing_or_invalid_study_uri', $codes);
    $this->assertContains('missing_or_invalid_process_uri', $codes);
    $this->assertContains('missing_or_invalid_tool_uri', $codes);
    $this->assertContains('invalid_arguments_json', $codes);
  }

  public function testRAnalysisLiveExecutionPathUsesRealBackendResponse(): void {
    $studyUri = 'http://example.org/study/r-analysis-live';
    $processUri = 'http://example.org/workflow/r-analysis-live';

    $submitter = $this->createUser(['submit ctt workflow']);
    $this->drupalLogin($submitter);

    $this->drupalGet('/workflow/api/repo/analytical-tools', [
      'query' => [
        'action' => 'upsert',
        'name' => 'R Live Execution Tool',
        'language' => 'R',
        'status' => 'current',
        'artifactFilename' => 'live_execution.R',
      ],
    ]);
    $this->assertSession()->statusCodeEquals(200);

    $toolPayload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($toolPayload);
    $this->assertTrue((bool) ($toolPayload['isValid'] ?? FALSE));

    $toolUri = (string) ($toolPayload['tool']['toolUri'] ?? '');
    $this->assertNotSame('', $toolUri);

    \Drupal::state()->set('ctt.study_process.' . sha1($studyUri), $processUri);

    $this->drupalGet('/workflow/api/submission/associations', [
      'query' => [
        'studyUri' => $studyUri,
        'processUri' => $processUri,
        'datasetUris' => ['http://example.org/dataset/live-v1'],
      ],
    ]);
    $this->assertSession()->statusCodeEquals(200);

    $this->drupalGet('/workflow/api/r-analysis/execute', [
      'query' => [
        'studyUri' => $studyUri,
        'processUri' => $processUri,
        'toolUri' => $toolUri,
        'arguments' => Json::encode(['iterations' => 10]),
      ],
    ]);

    $statusCode = (int) $this->getSession()->getStatusCode();
    $this->assertContains($statusCode, [200, 502, 503]);

    $payload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($payload);
    $this->assertTrue((bool) ($payload['executed'] ?? FALSE));
    $this->assertNotSame('', (string) ($payload['execution']['backendEndpoint'] ?? ''));

    if ($statusCode === 200) {
      $this->assertTrue((bool) ($payload['isSuccessful'] ?? FALSE));
      $this->assertArrayHasKey('upstream', $payload);
      return;
    }

    $this->assertFalse((bool) ($payload['isSuccessful'] ?? TRUE));
    $issueCodes = $this->extractIssueCodes($payload['issues'] ?? []);
    $this->assertTrue(
      in_array('upstream_execution_failed', $issueCodes, TRUE)
      || in_array('upstream_endpoint_not_found', $issueCodes, TRUE)
      || in_array('r_backend_unavailable', $issueCodes, TRUE)
    );
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
