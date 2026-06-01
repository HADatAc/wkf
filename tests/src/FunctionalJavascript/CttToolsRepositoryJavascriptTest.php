<?php

declare(strict_types=1);

namespace Drupal\Tests\ctt\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;

/**
 * Validates Tools Repository interactive flow in the browser.
 *
 * @group ctt
 */
final class CttToolsRepositoryJavascriptTest extends WebDriverTestBase {

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

  public function testToolsRepositorySaveAndAssociationActions(): void {
    $studyUri = 'http://example.org/study/tools-repository-js';
    $toolName = 'JS Tools Repository Validator';

    $account = $this->createUser(['submit ctt workflow']);
    $this->drupalLogin($account);

    $this->drupalGet('/workflow/tools-repository', [
      'query' => [
        'studyUri' => $studyUri,
      ],
    ]);

    $this->assertSession()->elementExists('css', '#ctt-tools-repository-page');
    $this->assertSession()->pageTextContains('Metadata-only registry: scripts are never executed in Drupal from this repository.');
    $this->assertSession()->pageTextContains('Create / Edit Tool');
    $this->assertSession()->elementExists('css', '#ctt-tools-filter-form');
    $this->assertSession()->elementExists('css', '#ctt-tools-filter-q');
    $this->assertSession()->elementExists('css', '#ctt-tools-editor-form');
    $this->assertSession()->elementExists('css', '#ctt-tool-name');
    $this->assertSession()->elementExists('css', '#ctt-tool-language');
    $this->assertSession()->elementExists('css', '#ctt-tool-description');

    $studyJs = json_encode($studyUri, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $settingsCondition = "typeof drupalSettings !== 'undefined'"
      . " && drupalSettings.cttToolsRepository"
      . " && drupalSettings.cttToolsRepository.endpoint"
      . " && drupalSettings.cttToolsRepository.endpoint.indexOf('/workflow/api/repo/analytical-tools') !== -1"
      . " && drupalSettings.cttToolsRepository.initialStudyUri === {$studyJs}";

    $this->assertTrue(
      (bool) $this->getSession()->wait(10000, $settingsCondition),
      'Timed out waiting for drupalSettings.cttToolsRepository contract.'
    );

    $toolNameJs = json_encode($toolName, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $flowScript = <<<'JS'
(function () {
  if (window.__cttToolsRepoFlowStarted === true) {
    return false;
  }

  window.__cttToolsRepoFlowStarted = true;

  if (typeof drupalSettings === 'undefined' || !drupalSettings.cttToolsRepository || !drupalSettings.cttToolsRepository.endpoint) {
    window.__cttToolsRepoFlowError = 'Missing cttToolsRepository endpoint.';
    return false;
  }

  const endpoint = String(drupalSettings.cttToolsRepository.endpoint || '').trim();
  const studyUri = __STUDY_URI__;
  const toolName = __TOOL_NAME__;

  const postJson = function (payload) {
    return fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify(payload)
    }).then(function (response) {
      return response.json().then(function (body) {
        return {
          ok: response.ok,
          body: body
        };
      });
    });
  };

  const listJson = function (query) {
    const params = new URLSearchParams(query);
    const separator = endpoint.indexOf('?') === -1 ? '?' : '&';
    return fetch(endpoint + separator + params.toString(), {
      method: 'GET',
      credentials: 'same-origin'
    }).then(function (response) {
      return response.json().then(function (body) {
        return {
          ok: response.ok,
          body: body
        };
      });
    });
  };

  postJson({
    action: 'upsert',
    studyUri: studyUri,
    tool: {
      name: toolName,
      version: '1.0.0',
      language: 'R',
      status: 'current',
      author: 'QA Bot',
      institution: 'PMSR',
      scenarioUri: 'http://example.org/scenario/js',
      datasetUri: 'http://example.org/dataset/js',
      artifactFilename: 'validator.R'
    }
  })
    .then(function (upsertResult) {
      if (!upsertResult.ok || !upsertResult.body || upsertResult.body.isValid === false) {
        throw new Error('Upsert failed for tools repository flow.');
      }

      const toolUri = String(upsertResult.body.tool && upsertResult.body.tool.toolUri || '').trim();
      if (!toolUri) {
        throw new Error('Missing tool URI after upsert.');
      }

      return postJson({
        action: 'dissociate',
        studyUri: studyUri,
        toolUri: toolUri
      }).then(function (dissociateResult) {
        if (!dissociateResult.ok || !dissociateResult.body || dissociateResult.body.isSuccessful === false) {
          throw new Error('Dissociate action failed for tools repository flow.');
        }
        return toolUri;
      });
    })
    .then(function (toolUri) {
      return postJson({
        action: 'associate',
        studyUri: studyUri,
        toolUri: toolUri
      }).then(function (associateResult) {
        if (!associateResult.ok || !associateResult.body || associateResult.body.isSuccessful === false) {
          throw new Error('Associate action failed for tools repository flow.');
        }
        return toolUri;
      });
    })
    .then(function (toolUri) {
      return listJson({
        studyUri: studyUri,
        q: toolName,
        limit: '200',
        offset: '0'
      }).then(function (listResult) {
        if (!listResult.ok || !listResult.body || !Array.isArray(listResult.body.body)) {
          throw new Error('List action failed for tools repository flow.');
        }

        const entry = listResult.body.body.find(function (item) {
          return item && String(item.toolUri || '') === toolUri;
        });

        if (!entry) {
          throw new Error('Saved tool was not found in repository list.');
        }

        if (entry.isAssociated !== true) {
          throw new Error('Saved tool is not associated after re-associate action.');
        }

        window.__cttToolsRepoFlowResult = 'ok';
      });
    })
    .catch(function (error) {
      window.__cttToolsRepoFlowError = String(error && error.message ? error.message : error);
    });

  return false;
})()
JS;

    $flowScript = str_replace('__STUDY_URI__', $studyJs, $flowScript);
    $flowScript = str_replace('__TOOL_NAME__', $toolNameJs, $flowScript);
    $this->getSession()->evaluateScript($flowScript);

    $flowCondition = "window.__cttToolsRepoFlowResult === 'ok'";
    $flowSucceeded = (bool) $this->getSession()->wait(20000, $flowCondition);

    if (!$flowSucceeded) {
      $flowError = (string) ($this->getSession()->evaluateScript('window.__cttToolsRepoFlowError || ""') ?? '');
      $message = 'Timed out waiting for tools repository browser flow result.';
      if ($flowError !== '') {
        $message .= ' Error: ' . $flowError;
      }
      $this->fail($message);
    }
  }

}
