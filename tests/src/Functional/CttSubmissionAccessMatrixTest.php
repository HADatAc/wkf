<?php

declare(strict_types=1);

namespace Drupal\Tests\ctt\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Covers access matrix and unified submission entrypoint routing for CTT.
 *
 * @group ctt
 */
final class CttSubmissionAccessMatrixTest extends BrowserTestBase {

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

  public function testCreateEditSubmitPermissionMatrix(): void {
    $studyUri = 'http://example.org/study/structured-submission';
    $studyParam = rawurlencode(base64_encode($studyUri));

    $accessOnly = $this->createUser(['access ctt editor']);
    $creator = $this->createUser(['access ctt editor', 'create ctt workflow']);
    $editor = $this->createUser(['access ctt editor', 'edit ctt workflow']);
    $submitter = $this->createUser(['access ctt editor', 'submit ctt workflow']);

    $this->drupalLogin($accessOnly);
    $this->drupalGet('/ctt/workflow/create');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($creator);
    $this->drupalGet('/ctt/workflow/create');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', '#ctt-workflow-app');

    $this->drupalLogin($creator);
    $this->drupalGet('/ctt/editor/workflow-alpha');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($editor);
    $this->drupalGet('/ctt/editor/workflow-alpha');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', '#ctt-workflow-app');

    $this->drupalLogin($editor);
    $this->drupalGet('/ctt/submission/' . $studyParam);
    $this->assertSession()->statusCodeEquals(403);

    // Submission entrypoint should infer process by study when association exists.
    \Drupal::state()->set('ctt.study_process.' . sha1($studyUri), 'http://example.org/workflow/inferred');

    $this->drupalLogin($submitter);
    $this->drupalGet('/ctt/submission/' . $studyParam);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', '#ctt-workflow-app');

    $url = $this->getSession()->getCurrentUrl();
    $this->assertStringContainsString('/ctt/editor', $url);
    $this->assertStringContainsString('submission=1', $url);
    $this->assertStringContainsString('studyUri=', $url);

    $persistedStatus = \Drupal::state()->get('ctt.study_status.' . sha1($studyUri));
    $this->assertSame('draft', $persistedStatus);
  }

}
