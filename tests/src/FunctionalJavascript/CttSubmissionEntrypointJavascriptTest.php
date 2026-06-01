<?php

declare(strict_types=1);

namespace Drupal\Tests\ctt\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;

/**
 * Validates structured submission context in drupalSettings via entrypoint.
 *
 * @group ctt
 */
final class CttSubmissionEntrypointJavascriptTest extends WebDriverTestBase {

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

  public function testSubmissionEntrypointInjectsStudyContext(): void {
    $studyUri = 'http://example.org/study/submission-js';
    $studyParam = rawurlencode(base64_encode($studyUri));
    $processUri = 'http://example.org/workflow/submission-js-process';

    \Drupal::state()->set('ctt.study_process.' . sha1($studyUri), $processUri);

    $account = $this->createUser(['access ctt editor', 'submit ctt workflow']);
    \Drupal::state()->set('ctt.study_owner_email.' . sha1($studyUri), (string) $account->getEmail());
    $this->drupalLogin($account);

    $this->drupalGet('/ctt/submission/' . $studyParam);
    $this->assertSession()->elementExists('css', '#ctt-workflow-app');

    $url = $this->getSession()->getCurrentUrl();
    $this->assertStringContainsString('/ctt/editor', $url);
    $this->assertStringContainsString('submission=1', $url);

    $studyJs = json_encode($studyUri, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $processJs = json_encode($processUri, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $settingsCondition = "typeof drupalSettings !== 'undefined'"
      . " && drupalSettings.ctt"
      . " && drupalSettings.ctt.mode === 'submission'"
      . " && drupalSettings.ctt.studyUri === {$studyJs}"
      . " && drupalSettings.ctt.processUri === {$processJs}"
      . " && drupalSettings.ctt.submission"
      . " && drupalSettings.ctt.submission.enabled === true"
      . " && drupalSettings.ctt.submission.mode === 'structured'"
      . " && drupalSettings.ctt.submission.validationEndpoint"
      . " && drupalSettings.ctt.submission.validationEndpoint.indexOf('/workflow/api/submission/validate') !== -1"
      . " && drupalSettings.ctt.submission.associationsEndpoint"
      . " && drupalSettings.ctt.submission.associationsEndpoint.indexOf('/workflow/api/submission/associations') !== -1"
      . " && drupalSettings.ctt.submission.analyticalToolsEndpoint"
      . " && drupalSettings.ctt.submission.analyticalToolsEndpoint.indexOf('/workflow/api/repo/analytical-tools') !== -1"
      . " && drupalSettings.ctt.submission.statusEndpoint"
      . " && drupalSettings.ctt.submission.statusEndpoint.indexOf('/workflow/api/submission/status') !== -1"
      . " && drupalSettings.ctt.editorial"
      . " && Array.isArray(drupalSettings.ctt.editorial.states)"
      . " && drupalSettings.ctt.editorial.states.indexOf('draft') !== -1"
      . " && drupalSettings.ctt.editorial.states.indexOf('under review') !== -1"
      . " && drupalSettings.ctt.editorial.states.indexOf('current') !== -1"
      . " && drupalSettings.ctt.editorial.states.indexOf('deprecated') !== -1"
      . " && drupalSettings.ctt.editorial.currentStatus === 'draft'"
      . " && drupalSettings.ctt.editorial.defaultState === 'under review'"
      . " && drupalSettings.ctt.permissions"
      . " && drupalSettings.ctt.permissions.canSubmitWorkflow === true"
      . " && drupalSettings.ctt.readOnlyPreview === false"
      . " && drupalSettings.ctt.workflowAccess"
      . " && drupalSettings.ctt.workflowAccess.isWorkflowOwnerAuthenticated === true";

    $this->assertTrue(
      (bool) $this->getSession()->wait(10000, $settingsCondition),
      'Timed out waiting for structured submission context in drupalSettings.ctt.'
    );
  }

  public function testSubmissionEntrypointNonOwnerIsReadOnly(): void {
    $studyUri = 'http://example.org/study/submission-js-readonly';
    $studyParam = rawurlencode(base64_encode($studyUri));
    $processUri = 'http://example.org/workflow/submission-js-readonly-process';

    \Drupal::state()->set('ctt.study_process.' . sha1($studyUri), $processUri);
    \Drupal::state()->set('ctt.study_owner_email.' . sha1($studyUri), 'owner@example.org');

    $account = $this->createUser(['access ctt editor', 'submit ctt workflow']);
    $this->drupalLogin($account);

    $this->drupalGet('/ctt/submission/' . $studyParam);
    $this->assertSession()->elementExists('css', '#ctt-workflow-app');

    $settingsCondition = "typeof drupalSettings !== 'undefined'"
      . " && drupalSettings.ctt"
      . " && drupalSettings.ctt.mode === 'submission'"
      . " && drupalSettings.ctt.readOnlyPreview === true"
      . " && drupalSettings.ctt.permissions"
      . " && drupalSettings.ctt.permissions.canSubmitWorkflow === false"
      . " && drupalSettings.ctt.permissions.canEditWorkflow === false"
      . " && drupalSettings.ctt.workflowAccess"
      . " && drupalSettings.ctt.workflowAccess.isStudyContext === true"
      . " && drupalSettings.ctt.workflowAccess.isWorkflowOwnerAuthenticated === false";

    $this->assertTrue(
      (bool) $this->getSession()->wait(10000, $settingsCondition),
      'Timed out waiting for non-owner read-only submission context in drupalSettings.ctt.'
    );
  }

}
