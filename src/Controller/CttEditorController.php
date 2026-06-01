<?php

namespace Drupal\ctt\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Markup;

/**
 * Controller that renders the CTT Workflow Editor page.
 *
 * Returns a render array that attaches the React UMD bundle and passes
 * configuration via drupalSettings.ctt.
 */
class CttEditorController extends ControllerBase {

  /**
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = new static();
    $instance->configFactory = $container->get('config.factory');
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * Normalize plain/encoded/base64 URI-like query values.
   */
  protected function decodeQueryUri(string $value): ?string {
    $candidate = trim($value);
    if ($candidate === '') {
      return NULL;
    }

    $decoded = base64_decode($candidate, TRUE);
    if (is_string($decoded) && $decoded !== '' && (str_starts_with($decoded, 'http://') || str_starts_with($decoded, 'https://'))) {
      return $decoded;
    }

    return rawurldecode($candidate);
  }

  /**
   * Convert common query flag styles into a boolean.
   */
  protected function isTruthyFlag(string $value): bool {
    $normalized = strtolower(trim($value));
    return $normalized === '1' || $normalized === 'true' || $normalized === 'yes';
  }

  /**
   * Resolve study manager email for ownership checks.
   */
  protected function resolveStudyManagerEmail(string $studyUri): string {
    $normalizedStudyUri = trim($studyUri);
    if ($normalizedStudyUri === '') {
      return '';
    }

    $ownerStateKey = 'ctt.study_owner_email.' . sha1($normalizedStudyUri);
    $cachedOwner = \Drupal::state()->get($ownerStateKey);
    if (is_string($cachedOwner) && trim($cachedOwner) !== '') {
      return trim($cachedOwner);
    }

    if (!\Drupal::hasService('rep.api_connector')) {
      return '';
    }

    try {
      $api = \Drupal::service('rep.api_connector');
      $studyObj = $api->parseObjectResponse($api->getUri($normalizedStudyUri), 'getUri');

      $ownerEmail = '';
      if (is_object($studyObj)) {
        $ownerEmail = trim((string) ($studyObj->hasSIRManagerEmail ?? $studyObj->managerEmail ?? ''));
      }
      elseif (is_array($studyObj)) {
        $ownerEmail = trim((string) ($studyObj['hasSIRManagerEmail'] ?? $studyObj['managerEmail'] ?? ''));
      }

      if ($ownerEmail !== '') {
        \Drupal::state()->set($ownerStateKey, $ownerEmail);
      }

      return $ownerEmail;
    }
    catch (\Throwable $ignored) {
      return '';
    }
  }

  /**
   * Build effective workflow access context for study-linked editor views.
   */
  protected function buildWorkflowAccessContext(?string $studyUri, string $currentUserEmail, bool $isExecutionMode): array {
    $normalizedStudyUri = is_string($studyUri) ? trim($studyUri) : '';
    $normalizedCurrentUserEmail = trim($currentUserEmail);

    $isStudyContext = $normalizedStudyUri !== '';
    $ownerEmail = '';
    $hasResolvedStudyOwner = FALSE;
    $isWorkflowOwnerAuthenticated = TRUE;
    $reasonCode = 'editable';

    if ($isStudyContext) {
      $ownerEmail = $this->resolveStudyManagerEmail($normalizedStudyUri);
      $hasResolvedStudyOwner = $ownerEmail !== '';
      $isWorkflowOwnerAuthenticated = $hasResolvedStudyOwner
        && $normalizedCurrentUserEmail !== ''
        && strcasecmp($ownerEmail, $normalizedCurrentUserEmail) === 0;

      if (!$isWorkflowOwnerAuthenticated) {
        $reasonCode = $hasResolvedStudyOwner ? 'non_owner_study_context' : 'study_owner_unresolved';
      }
    }

    $readOnlyPreview = $isExecutionMode || ($isStudyContext && !$isWorkflowOwnerAuthenticated);
    if ($isExecutionMode) {
      $reasonCode = 'execution_mode';
    }

    $message = '';
    if ($reasonCode === 'execution_mode') {
      $message = (string) $this->t('Execution mode is read-only.');
    }
    elseif ($readOnlyPreview) {
      $message = (string) $this->t('Read-only workflow preview: only the authenticated workflow owner can edit, save, or start/stop actions for this study.');
    }

    return [
      'isStudyContext' => $isStudyContext,
      'hasResolvedStudyOwner' => $hasResolvedStudyOwner,
      'isWorkflowOwnerAuthenticated' => $isWorkflowOwnerAuthenticated,
      'readOnlyPreview' => $readOnlyPreview,
      'reasonCode' => $reasonCode,
      'message' => $message,
    ];
  }

  /**
   * Canonical editorial states for structured scenario workflows.
   */
  protected function getEditorialStates(): array {
    return ['draft', 'under review', 'current', 'deprecated'];
  }

  /**
   * Allowed editorial transitions by source state.
   */
  protected function getEditorialTransitions(): array {
    return [
      'draft' => ['under review', 'deprecated'],
      'under review' => ['draft', 'current', 'deprecated'],
      'current' => ['deprecated'],
      'deprecated' => [],
    ];
  }

  /**
   * Canonical statuses for analytical tools lifecycle.
   */
  protected function getAnalyticalToolStatuses(): array {
    return ['draft', 'current', 'deprecated', 'validated', 'published'];
  }

  /**
   * Entry route for creating a structured workflow/scenario.
   */
  public function createEntry(): RedirectResponse {
    return $this->redirect('ctt.editor', [], [
      'query' => [
        'create' => '1',
      ],
    ]);
  }

  /**
   * Unified submission entrypoint that carries study context into the editor.
   */
  public function submissionEntry(string $studyuri): RedirectResponse {
    $decodedStudyUri = base64_decode(rawurldecode($studyuri), TRUE);
    if (!is_string($decodedStudyUri) || trim($decodedStudyUri) === '') {
      $this->messenger()->addError($this->t('Invalid study identifier for structured submission.'));
      return $this->redirect('std.search_studies_variables');
    }

    $processUri = NULL;
    $processFromQuery = (string) \Drupal::request()->query->get('processUri', '');
    if ($processFromQuery !== '') {
      $processUri = $this->decodeQueryUri($processFromQuery);
    }

    if ($processUri === NULL || trim($processUri) === '') {
      $stored = \Drupal::state()->get('ctt.study_process.' . sha1($decodedStudyUri));
      if (is_string($stored) && trim($stored) !== '') {
        $processUri = trim($stored);
      }
    }

    if ($processUri !== NULL && trim($processUri) !== '') {
      \Drupal::state()->set('ctt.study_process.' . sha1($decodedStudyUri), $processUri);
    }

    $statusKey = 'ctt.study_status.' . sha1($decodedStudyUri);
    $storedStatus = \Drupal::state()->get($statusKey);
    $normalizedStatus = is_string($storedStatus) ? strtolower(trim($storedStatus)) : '';
    if (!in_array($normalizedStatus, $this->getEditorialStates(), TRUE)) {
      \Drupal::state()->set($statusKey, 'draft');
    }

    $query = [
      'studyUri' => $studyuri,
      'submission' => '1',
    ];
    if (!empty($processUri)) {
      $query['processUri'] = $processUri;
    }

    return $this->redirect('ctt.editor', [], ['query' => $query]);
  }

  /**
   * Render analytical tools repository management page.
   */
  public function toolsRepositoryPage(): array {
    $basePath = rtrim(\Drupal::request()->getBasePath() ?: '/', '/');
    $drupalBaseUrl = ($basePath === '' ? '/' : $basePath . '/');
    $endpoint = $drupalBaseUrl . 'workflow/api/repo/analytical-tools';
    $initialStudyUri = trim((string) \Drupal::request()->query->get('studyUri', ''));

    $statusOptions = '<option value="">All statuses</option>';
    $editorStatusOptions = '';
    foreach ($this->getAnalyticalToolStatuses() as $status) {
      $label = ucfirst($status);
      $statusOptions .= '<option value="' . Html::escape($status) . '">' . Html::escape($label) . '</option>';
      $editorStatusOptions .= '<option value="' . Html::escape($status) . '">' . Html::escape($label) . '</option>';
    }

    $markup = ''
      . '<div id="ctt-tools-repository-page" class="ctt-tools-repository-page">'
      . '  <p>' . Html::escape((string) $this->t('This catalog stores analytical tools metadata used by structured submission workflows.')) . '</p>'
      . '  <p id="ctt-tools-safety-note" class="alert alert-info py-2">' . Html::escape((string) $this->t('Metadata-only registry: scripts are never executed in Drupal from this repository.')) . '</p>'
      . '  <div id="ctt-tools-feedback" class="alert d-none" role="alert"></div>'
      . '  <section class="card mb-3">'
      . '    <div class="card-header"><strong>' . Html::escape((string) $this->t('Repository Filters')) . '</strong></div>'
      . '    <div class="card-body">'
      . '      <form id="ctt-tools-filter-form" class="row g-2 align-items-end">'
      . '        <div class="col-md-3">'
      . '          <label for="ctt-tools-filter-q" class="form-label">' . Html::escape((string) $this->t('Search')) . '</label>'
      . '          <input type="text" id="ctt-tools-filter-q" class="form-control" placeholder="name, uri, tag, language, author">'
      . '        </div>'
      . '        <div class="col-md-3">'
      . '          <label for="ctt-tools-filter-study-uri" class="form-label">' . Html::escape((string) $this->t('Study URI (for associations)')) . '</label>'
      . '          <input type="url" id="ctt-tools-filter-study-uri" class="form-control" value="' . Html::escape($initialStudyUri) . '" placeholder="http://example.org/study/...">'
      . '        </div>'
      . '        <div class="col-md-2">'
      . '          <label for="ctt-tools-filter-language" class="form-label">' . Html::escape((string) $this->t('Language')) . '</label>'
      . '          <select id="ctt-tools-filter-language" class="form-select">'
      . '            <option value="">All languages</option>'
      . '            <option value="R">R</option>'
      . '            <option value="Python">Python</option>'
      . '            <option value="Julia">Julia</option>'
      . '            <option value="SAS">SAS</option>'
      . '            <option value="MATLAB">MATLAB</option>'
      . '          </select>'
      . '        </div>'
      . '        <div class="col-md-2">'
      . '          <label for="ctt-tools-filter-status" class="form-label">' . Html::escape((string) $this->t('Status')) . '</label>'
      . '          <select id="ctt-tools-filter-status" class="form-select">'
      .                $statusOptions
      . '          </select>'
      . '        </div>'
      . '        <div class="col-md-2">'
      . '          <label for="ctt-tools-filter-author" class="form-label">' . Html::escape((string) $this->t('Author')) . '</label>'
      . '          <input type="text" id="ctt-tools-filter-author" class="form-control" placeholder="name or email">'
      . '        </div>'
      . '        <div class="col-md-2">'
      . '          <label for="ctt-tools-filter-institution" class="form-label">' . Html::escape((string) $this->t('Institution')) . '</label>'
      . '          <input type="text" id="ctt-tools-filter-institution" class="form-control" placeholder="institution">'
      . '        </div>'
      . '        <div class="col-md-3">'
      . '          <label for="ctt-tools-filter-scenario-uri" class="form-label">' . Html::escape((string) $this->t('Scenario URI')) . '</label>'
      . '          <input type="url" id="ctt-tools-filter-scenario-uri" class="form-control" placeholder="http://example.org/scenario/...">'
      . '        </div>'
      . '        <div class="col-md-3">'
      . '          <label for="ctt-tools-filter-dataset-uri" class="form-label">' . Html::escape((string) $this->t('Dataset URI')) . '</label>'
      . '          <input type="url" id="ctt-tools-filter-dataset-uri" class="form-control" placeholder="http://example.org/dataset/...">'
      . '        </div>'
      . '        <div class="col-md-2">'
      . '          <label for="ctt-tools-filter-date-from" class="form-label">' . Html::escape((string) $this->t('Date From')) . '</label>'
      . '          <input type="date" id="ctt-tools-filter-date-from" class="form-control">'
      . '        </div>'
      . '        <div class="col-md-2">'
      . '          <label for="ctt-tools-filter-date-to" class="form-label">' . Html::escape((string) $this->t('Date To')) . '</label>'
      . '          <input type="date" id="ctt-tools-filter-date-to" class="form-control">'
      . '        </div>'
      . '        <div class="col-md-2 d-grid">'
      . '          <button type="submit" class="btn btn-primary btn-sm">' . Html::escape((string) $this->t('Apply')) . '</button>'
      . '        </div>'
      . '      </form>'
      . '    </div>'
      . '  </section>'
      . '  <section class="card mb-3">'
      . '    <div class="card-header"><strong>' . Html::escape((string) $this->t('Create / Edit Tool')) . '</strong></div>'
      . '    <div class="card-body">'
      . '      <form id="ctt-tools-editor-form" class="row g-2">'
      . '        <input type="hidden" id="ctt-tool-uri" value="">'
      . '        <div class="col-md-4">'
      . '          <label for="ctt-tool-name" class="form-label">' . Html::escape((string) $this->t('Name')) . '</label>'
      . '          <input type="text" id="ctt-tool-name" class="form-control" required>'
      . '        </div>'
      . '        <div class="col-md-2">'
      . '          <label for="ctt-tool-version" class="form-label">' . Html::escape((string) $this->t('Version')) . '</label>'
      . '          <input type="text" id="ctt-tool-version" class="form-control" placeholder="1.0.0">'
      . '        </div>'
      . '        <div class="col-md-2">'
      . '          <label for="ctt-tool-language" class="form-label">' . Html::escape((string) $this->t('Language')) . '</label>'
      . '          <input type="text" id="ctt-tool-language" class="form-control" placeholder="R, Python, SQL...">'
      . '        </div>'
      . '        <div class="col-md-2">'
      . '          <label for="ctt-tool-status" class="form-label">' . Html::escape((string) $this->t('Status')) . '</label>'
      . '          <select id="ctt-tool-status" class="form-select">'
      .                $editorStatusOptions
      . '          </select>'
      . '        </div>'
      . '        <div class="col-md-2">'
      . '          <label for="ctt-tool-release-date" class="form-label">' . Html::escape((string) $this->t('Release Date')) . '</label>'
      . '          <input type="date" id="ctt-tool-release-date" class="form-control">'
      . '        </div>'
      . '        <div class="col-md-3">'
      . '          <label for="ctt-tool-author" class="form-label">' . Html::escape((string) $this->t('Author')) . '</label>'
      . '          <input type="text" id="ctt-tool-author" class="form-control" placeholder="name or email">'
      . '        </div>'
      . '        <div class="col-md-3">'
      . '          <label for="ctt-tool-institution" class="form-label">' . Html::escape((string) $this->t('Institution')) . '</label>'
      . '          <input type="text" id="ctt-tool-institution" class="form-control" placeholder="institution">'
      . '        </div>'
      . '        <div class="col-md-3">'
      . '          <label for="ctt-tool-scenario-uri" class="form-label">' . Html::escape((string) $this->t('Scenario URI')) . '</label>'
      . '          <input type="url" id="ctt-tool-scenario-uri" class="form-control" placeholder="http://example.org/scenario/...">'
      . '        </div>'
      . '        <div class="col-md-3">'
      . '          <label for="ctt-tool-dataset-uri" class="form-label">' . Html::escape((string) $this->t('Dataset URI')) . '</label>'
      . '          <input type="url" id="ctt-tool-dataset-uri" class="form-control" placeholder="http://example.org/dataset/...">'
      . '        </div>'
      . '        <div class="col-md-6">'
      . '          <label for="ctt-tool-source-uri" class="form-label">' . Html::escape((string) $this->t('Source Repository URI')) . '</label>'
      . '          <input type="url" id="ctt-tool-source-uri" class="form-control" placeholder="http://example.org/repository/tool">'
      . '        </div>'
      . '        <div class="col-md-3">'
      . '          <label for="ctt-tool-artifact-filename" class="form-label">' . Html::escape((string) $this->t('Artifact Filename')) . '</label>'
      . '          <input type="text" id="ctt-tool-artifact-filename" class="form-control" placeholder="script.R">'
      . '        </div>'
      . '        <div class="col-md-3">'
      . '          <label for="ctt-tool-artifact-uri" class="form-label">' . Html::escape((string) $this->t('Artifact URI')) . '</label>'
      . '          <input type="url" id="ctt-tool-artifact-uri" class="form-control" placeholder="http://example.org/artifacts/script.R">'
      . '        </div>'
      . '        <div class="col-md-12">'
      . '          <label for="ctt-tool-tags" class="form-label">' . Html::escape((string) $this->t('Tags (comma separated)')) . '</label>'
      . '          <input type="text" id="ctt-tool-tags" class="form-control" placeholder="regression, validation">'
      . '        </div>'
      . '        <div class="col-md-12">'
      . '          <label for="ctt-tool-description" class="form-label">' . Html::escape((string) $this->t('Description')) . '</label>'
      . '          <textarea id="ctt-tool-description" class="form-control" rows="2"></textarea>'
      . '        </div>'
      . '        <div class="col-12 d-flex gap-2">'
      . '          <button type="submit" class="btn btn-success btn-sm">' . Html::escape((string) $this->t('Save Tool')) . '</button>'
      . '          <button type="button" id="ctt-tool-reset" class="btn btn-outline-secondary btn-sm">' . Html::escape((string) $this->t('Reset Form')) . '</button>'
      . '          <small class="text-muted align-self-center">' . Html::escape((string) $this->t('Metadata-only: tool scripts are not executed by Drupal.')) . '</small>'
      . '        </div>'
      . '      </form>'
      . '    </div>'
      . '  </section>'
      . '  <section class="table-responsive">'
      . '    <table class="table table-striped table-bordered table-sm align-middle ctt-analytical-tools-table">'
      . '      <thead>'
      . '        <tr>'
      . '          <th>' . Html::escape((string) $this->t('Name')) . '</th>'
      . '          <th>' . Html::escape((string) $this->t('Version')) . '</th>'
      . '          <th>' . Html::escape((string) $this->t('Language')) . '</th>'
      . '          <th>' . Html::escape((string) $this->t('Status')) . '</th>'
      . '          <th>' . Html::escape((string) $this->t('Author')) . '</th>'
      . '          <th>' . Html::escape((string) $this->t('Institution')) . '</th>'
      . '          <th>' . Html::escape((string) $this->t('Scenario URI')) . '</th>'
      . '          <th>' . Html::escape((string) $this->t('Dataset URI')) . '</th>'
      . '          <th>' . Html::escape((string) $this->t('Release Date')) . '</th>'
      . '          <th>' . Html::escape((string) $this->t('Tool URI')) . '</th>'
      . '          <th>' . Html::escape((string) $this->t('Tags')) . '</th>'
      . '          <th>' . Html::escape((string) $this->t('Updated At')) . '</th>'
      . '          <th>' . Html::escape((string) $this->t('Actions')) . '</th>'
      . '        </tr>'
      . '      </thead>'
      . '      <tbody id="ctt-tools-repository-body">'
      . '        <tr><td colspan="13" class="text-center text-muted">' . Html::escape((string) $this->t('Loading tools catalog...')) . '</td></tr>'
      . '      </tbody>'
      . '    </table>'
      . '  </section>'
      . '  <p class="mt-2 mb-0"><strong>' . Html::escape((string) $this->t('API endpoint')) . ':</strong> <code>' . Html::escape($endpoint) . '</code></p>'
      . '</div>';

    return [
      '#type' => 'markup',
      '#markup' => Markup::create($markup),
      '#attached' => [
        'library' => [
          'ctt/ctt-tools-repository',
        ],
        'drupalSettings' => [
          'cttToolsRepository' => [
            'endpoint' => $endpoint,
            'statuses' => $this->getAnalyticalToolStatuses(),
            'initialStudyUri' => $initialStudyUri,
          ],
        ],
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Render the Epic 5 R analysis page.
   */
  public function rAnalysisPage(): array {
    $basePath = rtrim(
      \Drupal::request()->getBasePath() ?: '/',
      '/'
    );
    $drupalBaseUrl = ($basePath === '' ? '/' : $basePath . '/');

    $toolsEndpoint = $drupalBaseUrl . 'workflow/api/repo/analytical-tools';
    $associationsEndpoint = $drupalBaseUrl . 'workflow/api/submission/associations';
    $executeEndpoint = $drupalBaseUrl . 'workflow/api/r-analysis/execute';

    $initialStudyUri = trim((string) \Drupal::request()->query->get('studyUri', ''));
    $initialProcessUri = trim((string) \Drupal::request()->query->get('processUri', ''));

    $markup = ''
      . '<div id="ctt-r-analysis-page" class="ctt-r-analysis-page">'
      . '  <p class="ctt-r-intro">' . Html::escape((string) $this->t('Run R analysis with real study/process context from PMSR.')) . '</p>'
      . '  <p id="ctt-r-no-mock-note" class="alert alert-warning py-2">' . Html::escape((string) $this->t('No mocked data: this interface only uses real catalog entries, real study associations, and real backend execution responses.')) . '</p>'
      . '  <div id="ctt-r-feedback" class="alert d-none" role="alert"></div>'
      . '  <section class="card mb-3">'
      . '    <div class="card-header"><strong>' . Html::escape((string) $this->t('Execution Context')) . '</strong></div>'
      . '    <div class="card-body">'
      . '      <form id="ctt-r-analysis-form" class="row g-2 align-items-end">'
      . '        <div class="col-md-6">'
      . '          <label for="ctt-r-study-uri" class="form-label">' . Html::escape((string) $this->t('Study URI')) . '</label>'
      . '          <input type="url" id="ctt-r-study-uri" class="form-control" required value="' . Html::escape($initialStudyUri) . '" placeholder="http://example.org/study/...">'
      . '        </div>'
      . '        <div class="col-md-6">'
      . '          <label for="ctt-r-process-uri" class="form-label">' . Html::escape((string) $this->t('Process URI')) . '</label>'
      . '          <input type="url" id="ctt-r-process-uri" class="form-control" required value="' . Html::escape($initialProcessUri) . '" placeholder="http://example.org/workflow/...">'
      . '        </div>'
      . '        <div class="col-md-8">'
      . '          <label for="ctt-r-tool-uri" class="form-label">' . Html::escape((string) $this->t('R Tool (from real repository)')) . '</label>'
      . '          <select id="ctt-r-tool-uri" class="form-select" required>'
      . '            <option value="">' . Html::escape((string) $this->t('Load context first to list R tools')) . '</option>'
      . '          </select>'
      . '        </div>'
      . '        <div class="col-md-4">'
      . '          <label for="ctt-r-entrypoint" class="form-label">' . Html::escape((string) $this->t('Entrypoint override (optional)')) . '</label>'
      . '          <input type="text" id="ctt-r-entrypoint" class="form-control" placeholder="main">'
      . '        </div>'
      . '        <div class="col-12">'
      . '          <label for="ctt-r-arguments-json" class="form-label">' . Html::escape((string) $this->t('Arguments (JSON object)')) . '</label>'
      . '          <textarea id="ctt-r-arguments-json" class="form-control" rows="5" placeholder="{&#10;  &quot;alpha&quot;: 0.05,&#10;  &quot;iterations&quot;: 1000&#10;}"></textarea>'
      . '        </div>'
      . '        <div class="col-md-8">'
      . '          <label for="ctt-r-argument-template" class="form-label">' . Html::escape((string) $this->t('Argument Template (clinical presets)')) . '</label>'
      . '          <select id="ctt-r-argument-template" class="form-select">'
      . '            <option value="">' . Html::escape((string) $this->t('Select a template')) . '</option>'
      . '            <option value="aspiration-baseline">' . Html::escape((string) $this->t('Aspiration baseline check')) . '</option>'
      . '            <option value="aspiration-high-risk">' . Html::escape((string) $this->t('Aspiration high-risk patient')) . '</option>'
      . '            <option value="aspiration-followup">' . Html::escape((string) $this->t('Aspiration follow-up reassessment')) . '</option>'
      . '          </select>'
      . '        </div>'
      . '        <div class="col-md-4 d-flex align-items-end ctt-r-template-actions">'
      . '          <button type="button" id="ctt-r-apply-template" class="btn btn-outline-info btn-sm w-100">' . Html::escape((string) $this->t('Apply Template')) . '</button>'
      . '        </div>'
      . '        <div class="col-12">'
      . '          <div class="form-check ctt-r-validate-check">'
      . '            <input class="form-check-input" type="checkbox" id="ctt-r-validate-only" checked>'
      . '            <label class="form-check-label" for="ctt-r-validate-only">' . Html::escape((string) $this->t('Validate only (skip upstream execution)')) . '</label>'
      . '          </div>'
      . '          <div class="ctt-r-persistence-note text-muted">' . Html::escape((string) $this->t('Form context is saved in this browser to speed up repeated tests.')) . '</div>'
      . '        </div>'
      . '        <div class="col-12 d-flex flex-wrap gap-2">'
      . '          <button type="button" id="ctt-r-load-context" class="btn btn-outline-primary btn-sm">' . Html::escape((string) $this->t('Load Real Context')) . '</button>'
      . '          <button type="submit" id="ctt-r-run-analysis" class="btn btn-success btn-sm">' . Html::escape((string) $this->t('Run R Analysis')) . '</button>'
      . '          <button type="button" id="ctt-r-copy-payload" class="btn btn-outline-secondary btn-sm">' . Html::escape((string) $this->t('Copy Request Payload')) . '</button>'
      . '          <button type="button" id="ctt-r-clear-saved-context" class="btn btn-outline-danger btn-sm">' . Html::escape((string) $this->t('Clear Saved Context')) . '</button>'
      . '        </div>'
      . '      </form>'
      . '    </div>'
      . '  </section>'
      . '  <section class="card mb-3">'
      . '    <div class="card-header"><strong>' . Html::escape((string) $this->t('Study Association Context (real state)')) . '</strong></div>'
      . '    <div class="card-body">'
      . '      <div id="ctt-r-context-summary" class="ctt-r-context-summary text-muted">' . Html::escape((string) $this->t('Load context to inspect datasets, variables, and medical images associated with this study.')) . '</div>'
      . '    </div>'
      . '  </section>'
      . '  <section class="card">'
      . '    <div class="card-header"><strong>' . Html::escape((string) $this->t('Execution Response')) . '</strong></div>'
      . '    <div class="card-body">'
      . '      <div id="ctt-r-exec-diagnostics" class="ctt-r-diagnostics ctt-r-diagnostics-muted">' . Html::escape((string) $this->t('Run validation or execution to view diagnostics summary.')) . '</div>'
      . '      <pre id="ctt-r-response-output" class="ctt-r-response-output">' . Html::escape((string) $this->t('No execution yet.')) . '</pre>'
      . '    </div>'
      . '  </section>'
      . '  <p class="mt-2 mb-0"><strong>' . Html::escape((string) $this->t('Execution API')) . ':</strong> <code>' . Html::escape($executeEndpoint) . '</code></p>'
      . '</div>';

    return [
      '#type' => 'markup',
      '#markup' => Markup::create($markup),
      '#attached' => [
        'library' => [
          'ctt/ctt-r-analysis',
        ],
        'drupalSettings' => [
          'cttRAnalysis' => [
            'toolsEndpoint' => $toolsEndpoint,
            'associationsEndpoint' => $associationsEndpoint,
            'executeEndpoint' => $executeEndpoint,
            'initialStudyUri' => $initialStudyUri,
            'initialProcessUri' => $initialProcessUri,
          ],
        ],
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Render the CTT editor page.
   *
   * @param string|null $process_uri
   *   Optional process URI to load.
   *
   * @return array
   *   Render array with the editor template and attached libraries.
   */
  public function editorPage($process_uri = NULL) {
    $config = $this->configFactory->get('ctt.settings');
    $default_namespace_url = $config->get('default_namespace_url') ?: NULL;

    if (!$default_namespace_url && \Drupal::moduleHandler()->moduleExists('rep')) {
      try {
        $api = \Drupal::service('rep.api_connector');
        $repo = $api->repoInfo();
        $obj = NULL;
        if (is_string($repo) && trim($repo) !== '') {
          $obj = json_decode($repo);
        }
        elseif (is_object($repo)) {
          $obj = $repo;
        }
        if ($obj && isset($obj->body) && isset($obj->body->hasDefaultNamespaceURL)) {
          $default_namespace_url = $obj->body->hasDefaultNamespaceURL;
        }
      }
      catch (\Exception $e) {
        // Keep default_namespace_url as NULL if API is unavailable.
      }
    }

    // Get current user information.
    $account = $this->currentUser;
    $user = \Drupal\user\Entity\User::load($account->id());
    $email = $user ? $user->getEmail() : '';

    // Get CSRF token for API calls.
    $csrf_token = \Drupal::csrfToken()->get('rest');

    $base_path = rtrim(
      \Drupal::request()->getBasePath() ?: '/',
      '/'
    );
    $drupal_base_url = ($base_path === '' ? '/' : $base_path . '/');

    // Support loading a process via query parameter to avoid encoded-slash issues on Apache/Windows.
    $process_from_query = (string) \Drupal::request()->query->get('processUri', '');
    if (empty($process_uri) && $process_from_query !== '') {
      $process_uri = $this->decodeQueryUri($process_from_query);
    }

    $study_from_query = (string) \Drupal::request()->query->get('studyUri', '');
    $study_uri = $study_from_query !== '' ? $this->decodeQueryUri($study_from_query) : NULL;

    // Execution context passed from the "Create Execution" flow.
    $executionFlag = (string) \Drupal::request()->query->get('execution', '');
    $isExecutionMode = $this->isTruthyFlag($executionFlag);

    $submissionFlag = (string) \Drupal::request()->query->get('submission', '');
    $isSubmissionMode = $this->isTruthyFlag($submissionFlag);

    $createFlag = (string) \Drupal::request()->query->get('create', '');
    $isCreateMode = $this->isTruthyFlag($createFlag);

    if ($isSubmissionMode && empty($process_uri) && !empty($study_uri)) {
      $stored = \Drupal::state()->get('ctt.study_process.' . sha1($study_uri));
      if (is_string($stored) && trim($stored) !== '') {
        $process_uri = trim($stored);
      }
    }

    if (!empty($study_uri) && !empty($process_uri)) {
      \Drupal::state()->set('ctt.study_process.' . sha1($study_uri), (string) $process_uri);
    }

    $resolvedProcessUri = $process_uri ? rawurldecode((string) $process_uri) : NULL;

    $encodedDaUri = \Drupal::request()->query->get('daUri');
    $encodedDataFileUri = \Drupal::request()->query->get('dataFileUri');
    $daUri = NULL;
    $dataFileUri = NULL;
    if (!empty($encodedDaUri) && is_string($encodedDaUri)) {
      $decoded = base64_decode($encodedDaUri, TRUE);
      if ($decoded !== FALSE && $decoded !== '') {
        $daUri = $decoded;
      }
    }
    if (!empty($encodedDataFileUri) && is_string($encodedDataFileUri)) {
      $decoded = base64_decode($encodedDataFileUri, TRUE);
      if ($decoded !== FALSE && $decoded !== '') {
        $dataFileUri = $decoded;
      }
    }

    $editorMode = 'edit';
    if ($isExecutionMode) {
      $editorMode = 'execution';
    }
    elseif ($isSubmissionMode) {
      $editorMode = 'submission';
    }
    elseif ($isCreateMode) {
      $editorMode = 'create';
    }

    $canAdminister = $account->hasPermission('administer ctt');
    $workflowAccess = $this->buildWorkflowAccessContext($study_uri, $email, $isExecutionMode);

    $permissionMatrix = [
      'canCreateWorkflow' => $canAdminister || $account->hasPermission('create ctt workflow'),
      'canEditWorkflow' => $canAdminister || $account->hasPermission('edit ctt workflow'),
      'canSubmitWorkflow' => $canAdminister || $account->hasPermission('submit ctt workflow'),
      'canAdminister' => $canAdminister,
    ];

    if (!empty($workflowAccess['readOnlyPreview'])) {
      $permissionMatrix['canCreateWorkflow'] = FALSE;
      $permissionMatrix['canEditWorkflow'] = FALSE;
      $permissionMatrix['canSubmitWorkflow'] = FALSE;
      $permissionMatrix['canAdminister'] = FALSE;
    }

    $editorialStates = $this->getEditorialStates();
    $editorialTransitions = $this->getEditorialTransitions();

    $defaultEditorialState = 'draft';
    if ($isSubmissionMode) {
      $defaultEditorialState = 'under review';
    }
    elseif ($isExecutionMode) {
      $defaultEditorialState = 'current';
    }

    $currentEditorialStatus = NULL;
    if (!empty($study_uri)) {
      $storedStatus = \Drupal::state()->get('ctt.study_status.' . sha1($study_uri));
      if (is_string($storedStatus)) {
        $normalizedStoredStatus = strtolower(trim($storedStatus));
        if (in_array($normalizedStoredStatus, $editorialStates, TRUE)) {
          $currentEditorialStatus = $normalizedStoredStatus;
        }
      }
    }

    if ($currentEditorialStatus === NULL) {
      if ($isSubmissionMode) {
        $currentEditorialStatus = 'draft';
      }
      else {
        $currentEditorialStatus = $defaultEditorialState;
      }
    }

    // Build drupalSettings for the React app.
    $drupal_settings = [
      'drupalBaseUrl' => $drupal_base_url,
      'apiBaseUrl' => $drupal_base_url . 'workflow/api',
      // Force same-origin proxy in embedded mode to avoid browser CORS.
      'hascoApiUrl' => $drupal_base_url . 'workflow',
      'defaultNamespaceUrl' => $default_namespace_url,
      'csrfToken' => $csrf_token,
      'mode' => $editorMode,
      'readOnlyPreview' => !empty($workflowAccess['readOnlyPreview']),
      'processUri' => $resolvedProcessUri,
      'studyUri' => $study_uri,
      'permissions' => $permissionMatrix,
      'workflowAccess' => $workflowAccess,
      'currentUser' => [
        'id' => (string) $account->id(),
        'name' => $account->getDisplayName(),
        'email' => $email,
      ],
      'drupalLinks' => [
        'createInstrument' => '/sir/manage/addinstrument',
        'createWorkflow' => '/std/manage/addworkflow/active',
        'manageInstruments' => '/sir/manage/instruments',
        'createTask' => '/std/manage/addtask/active/',
      ],
      'submission' => [
        'enabled' => $isSubmissionMode,
        'mode' => $isSubmissionMode ? 'structured' : 'disabled',
        'studyUri' => $study_uri,
        'processUri' => $resolvedProcessUri,
        'validationEndpoint' => $drupal_base_url . 'workflow/api/submission/validate',
        'associationsEndpoint' => $drupal_base_url . 'workflow/api/submission/associations',
        'analyticalToolsEndpoint' => $drupal_base_url . 'workflow/api/repo/analytical-tools',
        'statusEndpoint' => $drupal_base_url . 'workflow/api/submission/status',
        'requiredFields' => ['studyUri', 'processUri'],
      ],
      'toolsRepository' => [
        'endpoint' => $drupal_base_url . 'workflow/api/repo/analytical-tools',
        'page' => $drupal_base_url . 'workflow/tools-repository',
      ],
      'editorial' => [
        'states' => $editorialStates,
        'defaultState' => $defaultEditorialState,
        'currentStatus' => $currentEditorialStatus,
        'allowedTransitions' => $editorialTransitions,
      ],
      'execution' => [
        'mode' => $isExecutionMode ? 'execution' : 'edit',
        'readOnlyPreview' => !empty($workflowAccess['readOnlyPreview']),
        'daUri' => $daUri,
        'dataFileUri' => $dataFileUri,
        'studyUri' => $study_uri,
        'processUri' => $resolvedProcessUri,
      ],
    ];

    return [
      '#theme' => 'ctt_editor',
      '#title' => '',
      '#process_uri' => $process_uri,
      '#api_settings' => $drupal_settings,
      '#attached' => [
        'library' => [
          'ctt/ctt-editor-init',
        ],
        'drupalSettings' => [
          'ctt' => $drupal_settings,
        ],
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

}
