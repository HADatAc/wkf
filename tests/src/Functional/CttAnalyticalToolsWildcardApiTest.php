<?php

declare(strict_types=1);

namespace Drupal\Tests\ctt\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Tests\BrowserTestBase;

/**
 * Covers wildcard process filtering for analytical tools endpoint.
 *
 * @group ctt
 */
final class CttAnalyticalToolsWildcardApiTest extends BrowserTestBase {

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

  public function testAnyProcessWildcardIsReturnedForProcessFilter(): void {
    $submitter = $this->createUser(['submit ctt workflow']);
    $this->drupalLogin($submitter);

    $catalog = [
      'http://example.org/tool/anyprocess' => [
        'toolUri' => 'http://example.org/tool/anyprocess',
        'name' => 'Any Process Tool',
        'language' => 'r',
        'status' => 'current',
        'processUri' => 'http://hadatac.org/ont/hasco/AnyProcess',
        'ownerUserEmail' => 'admin@pmsr.com',
        'createdBy' => 'admin@pmsr.com',
        'createdAt' => gmdate('c'),
        'updatedAt' => gmdate('c'),
      ],
      'http://example.org/tool/specific' => [
        'toolUri' => 'http://example.org/tool/specific',
        'name' => 'Specific Tool',
        'language' => 'r',
        'status' => 'current',
        'processUri' => 'http://example.org/process/current',
        'ownerUserEmail' => 'owner@example.org',
        'createdBy' => 'owner@example.org',
        'createdAt' => gmdate('c'),
        'updatedAt' => gmdate('c'),
      ],
      'http://example.org/tool/other' => [
        'toolUri' => 'http://example.org/tool/other',
        'name' => 'Other Process Tool',
        'language' => 'r',
        'status' => 'current',
        'processUri' => 'http://example.org/process/other',
        'ownerUserEmail' => 'owner@example.org',
        'createdBy' => 'owner@example.org',
        'createdAt' => gmdate('c'),
        'updatedAt' => gmdate('c'),
      ],
    ];

    \Drupal::state()->set('ctt.analytical_tools.catalog.v1', $catalog);

    $this->drupalGet('/workflow/api/repo/analytical-tools', [
      'query' => [
        'processUri' => 'http://example.org/process/current',
      ],
    ]);
    $this->assertSession()->statusCodeEquals(200);

    $payload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($payload);
    $this->assertTrue((bool) ($payload['isSuccessful'] ?? FALSE));

    $rows = $payload['body'] ?? [];
    $this->assertIsArray($rows);

    $uris = [];
    foreach ($rows as $row) {
      if (is_array($row) && isset($row['toolUri']) && is_string($row['toolUri'])) {
        $uris[] = $row['toolUri'];
      }
    }

    $this->assertContains('http://example.org/tool/anyprocess', $uris);
    $this->assertContains('http://example.org/tool/specific', $uris);
    $this->assertNotContains('http://example.org/tool/other', $uris);
  }

}
