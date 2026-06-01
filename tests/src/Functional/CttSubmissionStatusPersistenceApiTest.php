<?php

declare(strict_types=1);

namespace Drupal\Tests\ctt\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Tests\BrowserTestBase;

/**
 * Covers persisted editorial status API for structured submissions.
 *
 * @group ctt
 */
final class CttSubmissionStatusPersistenceApiTest extends BrowserTestBase {

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

  public function testSubmissionStatusEndpointPersistsAndEnforcesTransitions(): void {
    $studyUri = 'http://example.org/study/submission-status';
    $processUri = 'http://example.org/workflow/submission-status';

    \Drupal::state()->set('ctt.study_process.' . sha1($studyUri), $processUri);

    $accessOnly = $this->createUser(['access ctt editor']);
    $submitter = $this->createUser(['submit ctt workflow']);
    $nonOwnerSubmitter = $this->createUser(['submit ctt workflow']);
    $admin = $this->createUser(['submit ctt workflow', 'administer ctt']);
    \Drupal::state()->set('ctt.study_owner_email.' . sha1($studyUri), (string) $submitter->getEmail());

    $this->drupalLogin($accessOnly);
    $this->drupalGet('/workflow/api/submission/status', [
      'query' => [
        'studyUri' => $studyUri,
      ],
    ]);
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($submitter);
    $this->drupalGet('/workflow/api/submission/status', [
      'query' => [
        'studyUri' => $studyUri,
      ],
    ]);
    $this->assertSession()->statusCodeEquals(200);

    $initialPayload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($initialPayload);
    $this->assertTrue((bool) ($initialPayload['isValid'] ?? FALSE));
    $this->assertFalse((bool) ($initialPayload['updated'] ?? TRUE));
    $this->assertSame('draft', (string) ($initialPayload['status'] ?? ''));
    $this->assertContains('under review', $initialPayload['editorial']['allowedNextStatuses'] ?? []);

    $this->drupalLogin($nonOwnerSubmitter);
    $this->drupalGet('/workflow/api/submission/status', [
      'query' => [
        'studyUri' => $studyUri,
        'processUri' => $processUri,
        'currentStatus' => 'draft',
        'requestedStatus' => 'under review',
      ],
    ]);
    $this->assertSession()->statusCodeEquals(403);

    $blockedPayload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($blockedPayload);
    $this->assertFalse((bool) ($blockedPayload['isValid'] ?? TRUE));
    $blockedCodes = $this->extractIssueCodes($blockedPayload['issues'] ?? []);
    $this->assertContains('workflow_owner_required', $blockedCodes);

    $this->drupalLogin($submitter);

    $this->drupalGet('/workflow/api/submission/status', [
      'query' => [
        'studyUri' => $studyUri,
        'processUri' => $processUri,
        'requestedStatus' => 'current',
      ],
    ]);
    $this->assertSession()->statusCodeEquals(200);

    $invalidCurrentPayload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($invalidCurrentPayload);
    $this->assertFalse((bool) ($invalidCurrentPayload['isValid'] ?? TRUE));
    $invalidCurrentCodes = $this->extractIssueCodes($invalidCurrentPayload['issues'] ?? []);
    $this->assertContains('current_requires_admin', $invalidCurrentCodes);
    $this->assertContains('invalid_editorial_transition', $invalidCurrentCodes);
    $this->assertSame('draft', (string) ($invalidCurrentPayload['status'] ?? ''));

    $this->drupalGet('/workflow/api/submission/status', [
      'query' => [
        'studyUri' => $studyUri,
        'processUri' => $processUri,
        'currentStatus' => 'draft',
        'requestedStatus' => 'under review',
      ],
    ]);
    $this->assertSession()->statusCodeEquals(200);

    $underReviewPayload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($underReviewPayload);
    $this->assertTrue((bool) ($underReviewPayload['isValid'] ?? FALSE));
    $this->assertTrue((bool) ($underReviewPayload['updated'] ?? FALSE));
    $this->assertSame('under review', (string) ($underReviewPayload['status'] ?? ''));
    $this->assertSame($processUri, (string) ($underReviewPayload['association']['studyProcess']['storedProcessUri'] ?? ''));
    $this->assertSame($processUri, (string) ($underReviewPayload['association']['studyProcess']['providedProcessUri'] ?? ''));
    $this->assertTrue((bool) ($underReviewPayload['association']['studyProcess']['matches'] ?? FALSE));
    $this->assertContains('current', $underReviewPayload['editorial']['allowedNextStatuses'] ?? []);

    $storedStatus = \Drupal::state()->get('ctt.study_status.' . sha1($studyUri));
    $this->assertSame('under review', $storedStatus);

    $meta = \Drupal::state()->get('ctt.study_status_meta.' . sha1($studyUri));
    $this->assertIsArray($meta);
    $this->assertSame('draft', (string) ($meta['previousStatus'] ?? ''));
    $this->assertSame('under review', (string) ($meta['status'] ?? ''));

    $this->drupalGet('/workflow/api/submission/status', [
      'query' => [
        'studyUri' => $studyUri,
        'processUri' => $processUri,
        'currentStatus' => 'under review',
        'requestedStatus' => 'current',
      ],
    ]);
    $this->assertSession()->statusCodeEquals(200);

    $blockedCurrentPayload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($blockedCurrentPayload);
    $this->assertFalse((bool) ($blockedCurrentPayload['isValid'] ?? TRUE));
    $blockedCurrentCodes = $this->extractIssueCodes($blockedCurrentPayload['issues'] ?? []);
    $this->assertContains('current_requires_admin', $blockedCurrentCodes);

    \Drupal::state()->set('ctt.study_owner_email.' . sha1($studyUri), (string) $admin->getEmail());

    $this->drupalLogin($admin);
    $this->drupalGet('/workflow/api/submission/status', [
      'query' => [
        'studyUri' => $studyUri,
        'processUri' => $processUri,
        'currentStatus' => 'under review',
        'requestedStatus' => 'current',
      ],
    ]);
    $this->assertSession()->statusCodeEquals(200);

    $adminCurrentPayload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($adminCurrentPayload);
    $this->assertTrue((bool) ($adminCurrentPayload['isValid'] ?? FALSE));
    $this->assertTrue((bool) ($adminCurrentPayload['updated'] ?? FALSE));
    $this->assertSame('current', (string) ($adminCurrentPayload['status'] ?? ''));

    $this->drupalGet('/workflow/api/submission/status', [
      'query' => [
        'studyUri' => $studyUri,
        'currentStatus' => 'current',
        'requestedStatus' => 'under review',
      ],
    ]);
    $this->assertSession()->statusCodeEquals(200);

    $invalidBackTransitionPayload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($invalidBackTransitionPayload);
    $this->assertFalse((bool) ($invalidBackTransitionPayload['isValid'] ?? TRUE));
    $invalidBackTransitionCodes = $this->extractIssueCodes($invalidBackTransitionPayload['issues'] ?? []);
    $this->assertContains('invalid_editorial_transition', $invalidBackTransitionCodes);
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
