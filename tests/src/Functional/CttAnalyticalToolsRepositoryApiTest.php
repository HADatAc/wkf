<?php

declare(strict_types=1);

namespace Drupal\Tests\ctt\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Tests\BrowserTestBase;

/**
 * Covers analytical tools repository API behavior (Epic 4 foundation).
 *
 * @group ctt
 */
final class CttAnalyticalToolsRepositoryApiTest extends BrowserTestBase {

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

  public function testAnalyticalToolsRepositoryContractAndStudyAssociation(): void {
    $studyUri = 'http://example.org/study/epic4-tools';
    $lineageUri = 'http://example.org/tools/lineage/regression-validator';
    $scenarioUri = 'http://example.org/scenario/respiratory-training';
    $datasetUri = 'http://example.org/dataset/respiratory-v1';

    $accessOnly = $this->createUser([]);
    $submitter = $this->createUser(['submit ctt workflow']);

    $this->drupalLogin($accessOnly);
    $this->drupalGet('/workflow/api/repo/analytical-tools');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($submitter);
    $this->drupalGet('/workflow/api/repo/analytical-tools');
    $this->assertSession()->statusCodeEquals(200);

    $initialPayload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($initialPayload);
    $this->assertTrue((bool) ($initialPayload['isSuccessful'] ?? FALSE));
    $this->assertIsArray($initialPayload['body'] ?? NULL);
    $this->assertSame(0, (int) ($initialPayload['pagination']['total'] ?? -1));

    $this->drupalGet('/workflow/api/repo/analytical-tools', [
      'query' => [
        'action' => 'upsert',
        'studyUri' => $studyUri,
        'name' => 'Regression Validator Tool',
        'version' => '1.2.0',
        'language' => 'R',
        'status' => 'current',
        'lineageUri' => $lineageUri,
        'author' => 'Dr. Alice',
        'institution' => 'PMSR Lab',
        'scenarioUri' => $scenarioUri,
        'datasetUri' => $datasetUri,
        'releaseDate' => '2026-05-20',
        'artifactFilename' => 'regression_validator.R',
        'sourceRepositoryUri' => 'http://example.org/repository/tools/regression-validator',
        'tags' => ['regression', 'validation'],
      ],
    ]);
    $this->assertSession()->statusCodeEquals(200);

    $upsertPayload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($upsertPayload);
    $this->assertTrue((bool) ($upsertPayload['isValid'] ?? FALSE));
    $this->assertTrue((bool) ($upsertPayload['updated'] ?? FALSE));
    $this->assertTrue((bool) ($upsertPayload['created'] ?? FALSE));

    $toolUri = (string) ($upsertPayload['tool']['toolUri'] ?? '');
    $this->assertNotSame('', $toolUri);
    $this->assertStringStartsWith('http://', $toolUri);
    $this->assertSame('current', (string) ($upsertPayload['tool']['status'] ?? ''));
    $this->assertSame($lineageUri, (string) ($upsertPayload['tool']['lineageUri'] ?? ''));
    $this->assertSame('Dr. Alice', (string) ($upsertPayload['tool']['author'] ?? ''));
    $this->assertSame('PMSR Lab', (string) ($upsertPayload['tool']['institution'] ?? ''));
    $this->assertSame($scenarioUri, (string) ($upsertPayload['tool']['scenarioUri'] ?? ''));
    $this->assertSame($datasetUri, (string) ($upsertPayload['tool']['datasetUri'] ?? ''));
    $this->assertSame('2026-05-20', (string) ($upsertPayload['tool']['releaseDate'] ?? ''));
    $this->assertSame('regression_validator.R', (string) ($upsertPayload['tool']['artifactFilename'] ?? ''));

    $catalog = \Drupal::state()->get('ctt.analytical_tools.catalog.v1');
    $this->assertIsArray($catalog);
    $this->assertArrayHasKey($toolUri, $catalog);

    $studyTools = \Drupal::state()->get('ctt.study_tools.' . sha1($studyUri));
    $this->assertIsArray($studyTools);
    $this->assertContains($toolUri, $studyTools);

    $this->drupalGet('/workflow/api/repo/analytical-tools', [
      'query' => [
        'studyUri' => $studyUri,
        'q' => 'regression',
      ],
    ]);
    $this->assertSession()->statusCodeEquals(200);

    $searchPayload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($searchPayload);
    $this->assertTrue((bool) ($searchPayload['isSuccessful'] ?? FALSE));
    $this->assertGreaterThanOrEqual(1, count($searchPayload['body'] ?? []));
    $firstTool = $searchPayload['body'][0] ?? [];
    $this->assertSame('Regression Validator Tool', (string) ($firstTool['name'] ?? ''));
    $this->assertTrue((bool) ($firstTool['isAssociated'] ?? FALSE));

    $this->drupalGet('/workflow/api/repo/analytical-tools', [
      'query' => [
        'author' => 'alice',
        'institution' => 'pmsr',
        'scenarioUri' => $scenarioUri,
        'datasetUri' => $datasetUri,
        'dateFrom' => '2026-05-01',
        'dateTo' => '2026-05-31',
      ],
    ]);
    $this->assertSession()->statusCodeEquals(200);
    $metadataFilterPayload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($metadataFilterPayload);
    $this->assertTrue((bool) ($metadataFilterPayload['isSuccessful'] ?? FALSE));
    $this->assertGreaterThanOrEqual(1, (int) ($metadataFilterPayload['pagination']['total'] ?? 0));

    $this->drupalGet('/workflow/api/repo/analytical-tools', [
      'query' => [
        'action' => 'upsert',
        'name' => 'Regression Validator Tool v2',
        'version' => '2.0.0',
        'language' => 'R',
        'status' => 'current',
        'lineageUri' => $lineageUri,
        'releaseDate' => '2026-05-25',
        'artifactFilename' => 'regression_validator_v2.R',
      ],
    ]);
    $this->assertSession()->statusCodeEquals(200);

    $versionPayload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($versionPayload);
    $this->assertTrue((bool) ($versionPayload['isValid'] ?? FALSE));
    $toolUriV2 = (string) ($versionPayload['tool']['toolUri'] ?? '');
    $this->assertNotSame('', $toolUriV2);
    $this->assertNotSame($toolUri, $toolUriV2);
    $autoDeprecated = $versionPayload['versioning']['autoDeprecatedToolUris'] ?? [];
    $this->assertIsArray($autoDeprecated);
    $this->assertContains($toolUri, $autoDeprecated);

    $catalogAfterVersioning = \Drupal::state()->get('ctt.analytical_tools.catalog.v1');
    $this->assertIsArray($catalogAfterVersioning);
    $this->assertSame('deprecated', (string) ($catalogAfterVersioning[$toolUri]['status'] ?? ''));
    $this->assertSame('current', (string) ($catalogAfterVersioning[$toolUriV2]['status'] ?? ''));
    $this->assertTrue((bool) ($catalogAfterVersioning[$toolUriV2]['isLatestVersion'] ?? FALSE));
    $this->assertFalse((bool) ($catalogAfterVersioning[$toolUri]['isLatestVersion'] ?? TRUE));

    $this->drupalGet('/workflow/api/repo/analytical-tools', [
      'query' => [
        'action' => 'dissociate',
        'studyUri' => $studyUri,
        'toolUri' => $toolUri,
      ],
    ]);
    $this->assertSession()->statusCodeEquals(200);

    $dissociatePayload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($dissociatePayload);
    $this->assertTrue((bool) ($dissociatePayload['isSuccessful'] ?? FALSE));
    $this->assertSame('dissociate', (string) ($dissociatePayload['action'] ?? ''));
    $this->assertSame(0, (int) ($dissociatePayload['studyAssociation']['associatedToolCount'] ?? -1));

    $this->drupalGet('/workflow/api/repo/analytical-tools', [
      'query' => [
        'studyUri' => $studyUri,
        'q' => 'regression',
      ],
    ]);
    $this->assertSession()->statusCodeEquals(200);
    $afterDissociatePayload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($afterDissociatePayload);
    $afterDissociateTool = $afterDissociatePayload['body'][0] ?? [];
    $this->assertFalse((bool) ($afterDissociateTool['isAssociated'] ?? TRUE));

    $this->drupalGet('/workflow/api/repo/analytical-tools', [
      'query' => [
        'action' => 'associate',
        'studyUri' => $studyUri,
        'toolUri' => $toolUri,
      ],
    ]);
    $this->assertSession()->statusCodeEquals(200);

    $associatePayload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($associatePayload);
    $this->assertTrue((bool) ($associatePayload['isSuccessful'] ?? FALSE));
    $this->assertSame('associate', (string) ($associatePayload['action'] ?? ''));
    $this->assertSame(1, (int) ($associatePayload['studyAssociation']['associatedToolCount'] ?? -1));

    $this->drupalGet('/workflow/api/repo/analytical-tools', [
      'query' => [
        'action' => 'remove',
        'toolUri' => $toolUri,
      ],
    ]);
    $this->assertSession()->statusCodeEquals(200);

    $removePayload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($removePayload);
    $this->assertTrue((bool) ($removePayload['isSuccessful'] ?? FALSE));
    $this->assertSame('remove', (string) ($removePayload['action'] ?? ''));

    $catalogAfterRemove = \Drupal::state()->get('ctt.analytical_tools.catalog.v1');
    $this->assertIsArray($catalogAfterRemove);
    $this->assertArrayNotHasKey($toolUri, $catalogAfterRemove);

    $this->drupalGet('/workflow/api/repo/analytical-tools', [
      'query' => [
        'studyUri' => $studyUri,
      ],
    ]);
    $this->assertSession()->statusCodeEquals(200);
    $afterRemovePayload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($afterRemovePayload);
    $this->assertGreaterThanOrEqual(1, (int) ($afterRemovePayload['pagination']['total'] ?? 0));
    $remainingEntries = $afterRemovePayload['body'] ?? [];
    $this->assertIsArray($remainingEntries);
    foreach ($remainingEntries as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      $this->assertFalse((bool) ($entry['isAssociated'] ?? TRUE));
    }

    $this->drupalGet('/workflow/api/repo/analytical-tools', [
      'query' => [
        'action' => 'upsert',
        'name' => 'Broken Tool Entry',
        'sourceRepositoryUri' => 'not-a-uri',
      ],
    ]);
    $this->assertSession()->statusCodeEquals(400);

    $invalidPayload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($invalidPayload);
    $this->assertFalse((bool) ($invalidPayload['isValid'] ?? TRUE));
    $issueCodes = $this->extractIssueCodes($invalidPayload['issues'] ?? []);
    $this->assertContains('invalid_source_repository_uri', $issueCodes);

    $this->drupalGet('/workflow/api/repo/analytical-tools', [
      'query' => [
        'action' => 'upsert',
        'name' => 'Invalid Artifact Tool',
        'artifactFilename' => 'payload.exe',
      ],
    ]);
    $this->assertSession()->statusCodeEquals(400);
    $invalidArtifactPayload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($invalidArtifactPayload);
    $this->assertFalse((bool) ($invalidArtifactPayload['isValid'] ?? TRUE));
    $artifactIssueCodes = $this->extractIssueCodes($invalidArtifactPayload['issues'] ?? []);
    $this->assertContains('invalid_artifact_extension', $artifactIssueCodes);

    $this->drupalGet('/workflow/api/repo/analytical-tools', [
      'query' => [
        'action' => 'upsert',
        'name' => 'Execution Attempt Tool',
        'execute' => '1',
      ],
    ]);
    $this->assertSession()->statusCodeEquals(400);
    $executePayload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($executePayload);
    $this->assertFalse((bool) ($executePayload['isValid'] ?? TRUE));
    $executeIssueCodes = $this->extractIssueCodes($executePayload['issues'] ?? []);
    $this->assertContains('script_execution_not_allowed', $executeIssueCodes);
  }

  public function testToolsRepositoryPageContract(): void {
    $accessOnly = $this->createUser(['access ctt editor']);
    $submitter = $this->createUser(['submit ctt workflow']);

    $this->drupalLogin($accessOnly);
    $this->drupalGet('/workflow/tools-repository');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($submitter);
    $this->drupalGet('/workflow/tools-repository');
    $this->assertSession()->statusCodeEquals(200);

    $this->assertSession()->pageTextContains('Metadata-only registry: scripts are never executed in Drupal from this repository.');
    $this->assertSession()->pageTextContains('Repository Filters');
    $this->assertSession()->pageTextContains('Scenario URI');
    $this->assertSession()->pageTextContains('Dataset URI');
    $this->assertSession()->pageTextContains('Date From');
    $this->assertSession()->pageTextContains('Date To');
    $this->assertSession()->pageTextContains('Create / Edit Tool');
    $this->assertSession()->pageTextContains('Artifact Filename');
    $this->assertSession()->pageTextContains('Metadata-only: tool scripts are not executed by Drupal.');
  }

  public function testScenarioFilterIncludesToolWithoutScenarioUri(): void {
    $submitter = $this->createUser(['submit ctt workflow']);
    $this->drupalLogin($submitter);

    $processUri = 'http://example.org/process/scenario-filter';
    $toolName = 'Global Scenario Tool';

    $this->drupalGet('/workflow/api/repo/analytical-tools', [
      'query' => [
        'action' => 'upsert',
        'name' => $toolName,
        'processUri' => $processUri,
        'language' => 'R',
        'status' => 'current',
      ],
    ]);
    $this->assertSession()->statusCodeEquals(200);

    $upsertPayload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($upsertPayload);
    $this->assertTrue((bool) ($upsertPayload['isValid'] ?? FALSE));

    $toolUri = (string) ($upsertPayload['tool']['toolUri'] ?? '');
    $this->assertNotSame('', $toolUri);

    $this->drupalGet('/workflow/api/repo/analytical-tools', [
      'query' => [
        'processUri' => $processUri,
        'scenarioUri' => 'http://example.org/scenario/selected',
      ],
    ]);
    $this->assertSession()->statusCodeEquals(200);

    $listPayload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($listPayload);
    $this->assertTrue((bool) ($listPayload['isSuccessful'] ?? FALSE));

    $toolUris = [];
    foreach (($listPayload['body'] ?? []) as $row) {
      if (is_array($row) && isset($row['toolUri']) && is_string($row['toolUri'])) {
        $toolUris[] = $row['toolUri'];
      }
    }

    $this->assertContains($toolUri, $toolUris);
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
