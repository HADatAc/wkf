<?php

declare(strict_types=1);

namespace Drupal\Tests\ctt\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;

/**
 * Verifies frontend-triggered submission status persistence bridge.
 *
 * @group ctt
 */
final class CttSubmissionStatusPersistenceJavascriptTest extends WebDriverTestBase {

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

  public function testFrontendValidationTriggersStatusPersistence(): void {
    $studyUri = 'http://example.org/study/submission-status-js';
    $studyParam = rawurlencode(base64_encode($studyUri));
    $processUri = 'http://example.org/workflow/submission-status-js-process';

    \Drupal::state()->set('ctt.study_process.' . sha1($studyUri), $processUri);
    \Drupal::state()->set('ctt.study_status.' . sha1($studyUri), 'draft');

    $account = $this->createUser(['access ctt editor', 'submit ctt workflow']);
    $this->drupalLogin($account);

    $this->drupalGet('/ctt/submission/' . $studyParam);
    $this->assertSession()->elementExists('css', '#ctt-workflow-app');

    $studyJs = json_encode($studyUri, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $processJs = json_encode($processUri, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $persistCondition = <<<'JS'
(function () {
  if (typeof drupalSettings === 'undefined' || !drupalSettings.ctt || !drupalSettings.ctt.submission) {
    return false;
  }

  if (window.__cttFrontendStatusPersistStarted !== true) {
    window.__cttFrontendStatusPersistStarted = true;

    var validationEndpoint = String(drupalSettings.ctt.submission.validationEndpoint || '');
    if (!validationEndpoint) {
      window.__cttFrontendStatusPersistError = 'Missing validation endpoint';
      return false;
    }

    var studyUri = __STUDY_URI__;
    var processUri = __PROCESS_URI__;
    var separator = validationEndpoint.indexOf('?') === -1 ? '?' : '&';
    var url = validationEndpoint
      + separator + 'studyUri=' + encodeURIComponent(studyUri)
      + '&processUri=' + encodeURIComponent(processUri)
      + '&currentStatus=' + encodeURIComponent('draft')
      + '&requestedStatus=' + encodeURIComponent('under review')
      + '&mode=' + encodeURIComponent('submission')
      + '&dataFileUri=' + encodeURIComponent('http://example.org/datafile/submission-status-js-output');

    fetch(url, { credentials: 'same-origin' }).then(function (response) {
      if (!response || response.ok !== true) {
        window.__cttFrontendStatusPersistError = 'Validation request failed';
      }
    }).catch(function (error) {
      window.__cttFrontendStatusPersistError = String(error && error.message ? error.message : error);
    });

    return false;
  }

  return !!window.__cttSubmissionStatusLastPersist
    && window.__cttSubmissionStatusLastPersist.status === 'under review'
    && window.__cttSubmissionStatusLastPersist.updated === true;
})()
JS;

    $persistCondition = str_replace('__STUDY_URI__', $studyJs, $persistCondition);
    $persistCondition = str_replace('__PROCESS_URI__', $processJs, $persistCondition);

    $waitSucceeded = (bool) $this->getSession()->wait(15000, $persistCondition);
    if (!$waitSucceeded) {
      $frontendError = (string) ($this->getSession()->evaluateScript('window.__cttFrontendStatusPersistError || ""') ?? '');
      $bridgeError = (string) ($this->getSession()->evaluateScript('window.__cttSubmissionStatusLastPersistError || ""') ?? '');

      $message = 'Timed out waiting for frontend status persistence bridge to persist under review status.';
      if ($frontendError !== '') {
        $message .= ' Frontend error: ' . $frontendError . '.';
      }
      if ($bridgeError !== '') {
        $message .= ' Bridge error: ' . $bridgeError . '.';
      }

      $this->fail($message);
    }

    $status = \Drupal::state()->get('ctt.study_status.' . sha1($studyUri));
    $this->assertSame('under review', $status);

    $meta = \Drupal::state()->get('ctt.study_status_meta.' . sha1($studyUri));
    $this->assertIsArray($meta);
    $this->assertSame('draft', (string) ($meta['previousStatus'] ?? ''));
    $this->assertSame('under review', (string) ($meta['status'] ?? ''));
  }

}
