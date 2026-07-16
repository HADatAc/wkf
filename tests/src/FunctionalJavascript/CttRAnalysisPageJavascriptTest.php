<?php

declare(strict_types=1);

namespace Drupal\Tests\ctt\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;

/**
 * Validates Epic 5 R analysis page JS settings contract.
 *
 * @group ctt
 */
final class CttRAnalysisPageJavascriptTest extends WebDriverTestBase {

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

  public function testRAnalysisPageInjectsSettings(): void {
    $studyUri = 'http://example.org/study/r-analysis-js';
    $processUri = 'http://example.org/workflow/r-analysis-js';

    $account = $this->createUser(['submit ctt workflow']);
    $this->drupalLogin($account);

    $this->drupalGet('/workflow/r-analysis', [
      'query' => [
        'studyUri' => $studyUri,
        'processUri' => $processUri,
      ],
    ]);

    $this->assertSession()->elementExists('css', '#ctt-r-analysis-page');
    $this->assertSession()->elementExists('css', '#ctt-r-analysis-form');
    $this->assertSession()->elementExists('css', '#ctt-r-study-uri');
    $this->assertSession()->elementExists('css', '#ctt-r-study-uri-hint');
    $this->assertSession()->elementExists('css', '#ctt-r-study-uri-error');
    $this->assertSession()->elementExists('css', '#ctt-r-process-uri');
    $this->assertSession()->elementExists('css', '#ctt-r-process-uri-hint');
    $this->assertSession()->elementExists('css', '#ctt-r-process-uri-error');
    $this->assertSession()->elementExists('css', '#ctt-r-tool-uri');
    $this->assertSession()->elementExists('css', '#ctt-r-entrypoint');
    $this->assertSession()->elementExists('css', '#ctt-r-validate-only');
    $this->assertSession()->elementExists('css', '#ctt-r-arguments-json');
    $this->assertSession()->elementExists('css', '#ctt-r-argument-template');
    $this->assertSession()->elementExists('css', '#ctt-r-apply-template');
    $this->assertSession()->elementExists('css', '#ctt-r-load-context');
    $this->assertSession()->elementExists('css', '#ctt-r-run-analysis');
    $this->assertSession()->elementExists('css', '#ctt-r-copy-payload');
    $this->assertSession()->elementExists('css', '#ctt-r-download-log');
    $this->assertSession()->elementExists('css', '#ctt-r-clear-saved-context');
    $this->assertSession()->elementExists('css', '#ctt-r-exec-diagnostics');

    $studyJs = json_encode($studyUri, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $processJs = json_encode($processUri, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $settingsCondition = "typeof drupalSettings !== 'undefined'"
      . " && drupalSettings.cttRAnalysis"
      . " && drupalSettings.cttRAnalysis.toolsEndpoint"
      . " && drupalSettings.cttRAnalysis.toolsEndpoint.indexOf('/workflow/api/repo/analytical-tools') !== -1"
      . " && drupalSettings.cttRAnalysis.studyAutocompleteEndpoint"
      . " && drupalSettings.cttRAnalysis.studyAutocompleteEndpoint.indexOf('/workflow/api/r-analysis/autocomplete/study') !== -1"
      . " && drupalSettings.cttRAnalysis.processAutocompleteEndpoint"
      . " && drupalSettings.cttRAnalysis.processAutocompleteEndpoint.indexOf('/workflow/api/r-analysis/autocomplete/process') !== -1"
      . " && drupalSettings.cttRAnalysis.associationsEndpoint"
      . " && drupalSettings.cttRAnalysis.associationsEndpoint.indexOf('/workflow/api/submission/associations') !== -1"
      . " && drupalSettings.cttRAnalysis.executeEndpoint"
      . " && drupalSettings.cttRAnalysis.executeEndpoint.indexOf('/workflow/api/r-analysis/execute') !== -1"
      . " && drupalSettings.cttRAnalysis.initialStudyUri === {$studyJs}"
      . " && drupalSettings.cttRAnalysis.initialProcessUri === {$processJs}";

    $this->assertTrue(
      (bool) $this->getSession()->wait(10000, $settingsCondition),
      'Timed out waiting for drupalSettings.cttRAnalysis contract.'
    );
  }

}
