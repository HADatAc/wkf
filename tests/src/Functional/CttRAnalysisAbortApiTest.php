<?php

declare(strict_types=1);

namespace Drupal\Tests\ctt\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Tests\BrowserTestBase;

/**
 * Covers abort endpoint lifecycle behavior for R analysis runs.
 *
 * @group ctt
 */
final class CttRAnalysisAbortApiTest extends BrowserTestBase {

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

  public function testAbortEndpointTransitionsRunningRunToAborted(): void {
    $submitter = $this->createUser(['submit ctt workflow']);
    $this->drupalLogin($submitter);

    $studyUri = 'http://example.org/study/abortable';
    $processUri = 'http://example.org/process/abortable';
    $toolUri = 'http://example.org/tool/abortable';
    $runId = 'RA-ABORT-001';

    \Drupal::state()->set('ctt.r_analysis_runs.' . sha1($studyUri), [
      [
        'runId' => $runId,
        'studyUri' => $studyUri,
        'processUri' => $processUri,
        'toolUri' => $toolUri,
        'status' => 'running',
        'requestedAt' => gmdate('c'),
        'startedAt' => gmdate('c'),
        'finishedAt' => '',
      ],
    ]);

    $this->drupalGet('/workflow/api/r-analysis/abort', [
      'query' => [
        'studyUri' => $studyUri,
        'processUri' => $processUri,
        'toolUri' => $toolUri,
      ],
    ]);
    $this->assertSession()->statusCodeEquals(200);

    $payload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($payload);
    $this->assertTrue((bool) ($payload['isSuccessful'] ?? FALSE));
    $this->assertSame('aborted', (string) ($payload['execution']['status'] ?? ''));

    $history = \Drupal::state()->get('ctt.r_analysis_runs.' . sha1($studyUri), []);
    $this->assertIsArray($history);
    $this->assertSame('aborted', (string) ($history[0]['status'] ?? ''));
    $this->assertNotSame('', (string) ($history[0]['finishedAt'] ?? ''));
    $this->assertNotSame('', (string) ($history[0]['abortedAt'] ?? ''));
  }

}
