<?php

declare(strict_types=1);

namespace Drupal\Tests\ctt\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;

/**
 * Validates Save to API success feedback in the workflow editor UI.
 *
 * @group ctt
 */
final class CttEditorSaveToApiJavascriptTest extends WebDriverTestBase {

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

  public function testSaveToApiShowsSuccessMessageInEditorUi(): void {
    $account = $this->createUser(['access ctt editor', 'submit ctt workflow']);
    $this->drupalLogin($account);

    $this->drupalGet('/ctt/editor', [
      'query' => [
        'adapter' => 'mock',
        'processUri' => 'pmsr:/PROCESS001',
      ],
    ]);

    $this->assertSession()->elementExists('css', '#ctt-workflow-app');

    $readyCondition = "Array.from(document.querySelectorAll('button')).some(function (btn) {"
      . " var label = (btn.textContent || '').replace(/\\s+/g, ' ').trim();"
      . " return label === 'Save to API' && !btn.disabled;"
      . " })"
      . " && document.body && document.body.textContent.indexOf('Tasks • 3') !== -1";

    $this->assertTrue(
      (bool) $this->getSession()->wait(15000, $readyCondition),
      'Timed out waiting for editor mock workflow to load with an enabled Save to API button.'
    );

    $openedModal = (bool) $this->getSession()->evaluateScript(<<<'JS'
(function () {
  var button = Array.from(document.querySelectorAll('button')).find(function (btn) {
    var label = (btn.textContent || '').replace(/\s+/g, ' ').trim();
    return label === 'Save to API' && !btn.disabled;
  });

  if (!button) {
    return false;
  }

  button.click();
  return true;
})()
JS
    );
    $this->assertTrue($openedModal, 'Unable to trigger Save to API action in editor toolbar.');

    $this->assertTrue(
      (bool) $this->getSession()->wait(10000, "document.body && document.body.textContent.indexOf('Save Workflow to API') !== -1"),
      'Timed out waiting for Save to API confirmation modal.'
    );

    $confirmedModal = (bool) $this->getSession()->evaluateScript(<<<'JS'
(function () {
  var heading = Array.from(document.querySelectorAll('h2')).find(function (node) {
    return (node.textContent || '').replace(/\s+/g, ' ').trim() === 'Save Workflow to API';
  });

  if (!heading) {
    return false;
  }

  var current = heading;
  for (var depth = 0; depth < 8 && current; depth++) {
    var confirm = Array.from(current.querySelectorAll('button')).find(function (btn) {
      return (btn.textContent || '').replace(/\s+/g, ' ').trim() === 'Save to API' && !btn.disabled;
    });

    if (confirm) {
      confirm.click();
      return true;
    }

    current = current.parentElement;
  }

  return false;
})()
JS
    );
    $this->assertTrue($confirmedModal, 'Unable to confirm Save to API action in modal.');

    $toastCondition = "document.body"
      . " && document.body.textContent.indexOf('Workflow saved') !== -1"
      . " && document.body.textContent.indexOf('tasks synchronized successfully') !== -1";

    $this->assertTrue(
      (bool) $this->getSession()->wait(15000, $toastCondition),
      'Timed out waiting for explicit Save to API success feedback in UI.'
    );
  }

}
