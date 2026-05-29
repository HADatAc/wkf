<?php

declare(strict_types=1);

namespace Drupal\Tests\ctt\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Tests\BrowserTestBase;

/**
 * Covers structured submission study associations endpoint and integration.
 *
 * @group ctt
 */
final class CttSubmissionAssociationsApiTest extends BrowserTestBase {

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

  public function testSubmissionAssociationsEndpointContractAndPersistence(): void {
    $studyUri = 'http://example.org/study/submission-associations';
    $processUri = 'http://example.org/workflow/submission-associations';

    \Drupal::state()->set('ctt.study_process.' . sha1($studyUri), $processUri);

    $accessOnly = $this->createUser(['access ctt editor']);
    $submitter = $this->createUser(['submit ctt workflow']);

    $this->drupalLogin($accessOnly);
    $this->drupalGet('/workflow/api/submission/associations', [
      'query' => [
        'studyUri' => $studyUri,
      ],
    ]);
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($submitter);
    $this->drupalGet('/workflow/api/submission/associations', [
      'query' => [
        'studyUri' => $studyUri,
      ],
    ]);
    $this->assertSession()->statusCodeEquals(200);

    $initialPayload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($initialPayload);
    $this->assertTrue((bool) ($initialPayload['isValid'] ?? FALSE));
    $this->assertFalse((bool) ($initialPayload['updated'] ?? TRUE));
    $this->assertSame('stored', (string) ($initialPayload['source'] ?? ''));
    $this->assertSame(0, (int) ($initialPayload['associations']['counts']['datasets'] ?? -1));
    $this->assertSame(0, (int) ($initialPayload['associations']['counts']['variables'] ?? -1));
    $this->assertSame(0, (int) ($initialPayload['associations']['counts']['images'] ?? -1));

    $this->drupalGet('/workflow/api/submission/associations', [
      'query' => [
        'studyUri' => $studyUri,
        'datasetUris' => ['not-a-uri'],
      ],
    ]);
    $this->assertSession()->statusCodeEquals(200);

    $invalidPayload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($invalidPayload);
    $this->assertFalse((bool) ($invalidPayload['isValid'] ?? TRUE));
    $this->assertFalse((bool) ($invalidPayload['updated'] ?? TRUE));
    $invalidCodes = $this->extractIssueCodes($invalidPayload['issues'] ?? []);
    $this->assertContains('invalid_dataset_uri', $invalidCodes);

    $this->drupalGet('/workflow/api/submission/associations', [
      'query' => [
        'studyUri' => $studyUri,
        'processUri' => $processUri,
        'datasetUris' => [
          'http://example.org/dataset/001',
          'http://example.org/dataset/002',
        ],
        'variableUris' => [
          'http://example.org/variable/heart-rate',
        ],
        'medicalImageFiles' => [
          'scan-001.dcm',
        ],
      ],
    ]);
    $this->assertSession()->statusCodeEquals(200);

    $updatedPayload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($updatedPayload);
    $this->assertTrue((bool) ($updatedPayload['isValid'] ?? FALSE));
    $this->assertTrue((bool) ($updatedPayload['updated'] ?? FALSE));
    $this->assertSame('request', (string) ($updatedPayload['source'] ?? ''));
    $this->assertSame(2, (int) ($updatedPayload['associations']['counts']['datasets'] ?? 0));
    $this->assertSame(1, (int) ($updatedPayload['associations']['counts']['variables'] ?? 0));
    $this->assertSame(1, (int) ($updatedPayload['associations']['counts']['images'] ?? 0));

    $storedAssociations = \Drupal::state()->get('ctt.study_associations.' . sha1($studyUri));
    $this->assertIsArray($storedAssociations);
    $this->assertSame(2, count($storedAssociations['datasets'] ?? []));
    $this->assertSame(1, count($storedAssociations['variables'] ?? []));
    $this->assertSame(1, count($storedAssociations['images'] ?? []));

    $this->drupalGet('/workflow/api/submission/validate', [
      'query' => [
        'studyUri' => $studyUri,
        'processUri' => $processUri,
        'mode' => 'submission',
        'currentStatus' => 'draft',
        'requestedStatus' => 'under review',
        'dataFileUri' => 'http://example.org/datafile/submission-associations-output',
      ],
    ]);
    $this->assertSession()->statusCodeEquals(200);

    $validationPayload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($validationPayload);
    $this->assertTrue((bool) ($validationPayload['isValid'] ?? FALSE));
    $this->assertSame('stored', (string) ($validationPayload['association']['resourcesSource'] ?? ''));
    $this->assertSame(2, (int) ($validationPayload['association']['resources']['counts']['datasets'] ?? 0));
    $this->assertSame(1, (int) ($validationPayload['association']['resources']['counts']['variables'] ?? 0));
    $this->assertSame(1, (int) ($validationPayload['association']['resources']['counts']['images'] ?? 0));

    $this->drupalGet('/workflow/api/submission/status', [
      'query' => [
        'studyUri' => $studyUri,
      ],
    ]);
    $this->assertSession()->statusCodeEquals(200);

    $statusPayload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($statusPayload);
    $this->assertTrue((bool) ($statusPayload['isValid'] ?? FALSE));
    $this->assertSame(2, (int) ($statusPayload['association']['resources']['counts']['datasets'] ?? 0));
    $this->assertSame(1, (int) ($statusPayload['association']['resources']['counts']['variables'] ?? 0));
    $this->assertSame(1, (int) ($statusPayload['association']['resources']['counts']['images'] ?? 0));
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
