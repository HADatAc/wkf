/**
 * @file
 * CTT Editor initialization - Drupal behavior that bootstraps the full React App.
 *
 * Reads configuration from drupalSettings.ctt and mounts the
 * complete CTT application (same canvas as standalone) into #ctt-workflow-app.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  function toBooleanFlag(value) {
    if (value === true || value === 1) {
      return true;
    }
    if (typeof value === 'string') {
      var normalized = value.trim().toLowerCase();
      return normalized === '1' || normalized === 'true' || normalized === 'yes';
    }
    return false;
  }

  function parseUrl(urlValue) {
    if (!urlValue || typeof URL !== 'function') {
      return null;
    }
    try {
      return new URL(String(urlValue), window.location.origin);
    }
    catch (e) {
      return null;
    }
  }

  function normalizePathname(urlValue) {
    var parsed = parseUrl(urlValue);
    if (!parsed) {
      return '';
    }
    return String(parsed.pathname || '').replace(/\/+$/, '').toLowerCase();
  }

  function getRequestUrl(resource) {
    if (typeof resource === 'string') {
      return resource;
    }
    if (resource && typeof resource.url === 'string') {
      return resource.url;
    }
    return '';
  }

  function parseRequestContext(requestUrl, init) {
    var context = {
      studyUri: '',
      processUri: '',
      requestedStatus: '',
      currentStatus: '',
      mode: ''
    };

    var parsed = parseUrl(requestUrl);
    if (parsed) {
      context.studyUri = String(parsed.searchParams.get('studyUri') || '').trim();
      context.processUri = String(parsed.searchParams.get('processUri') || '').trim();
      context.requestedStatus = String(parsed.searchParams.get('requestedStatus') || '').trim();
      context.currentStatus = String(parsed.searchParams.get('currentStatus') || '').trim();
      context.mode = String(parsed.searchParams.get('mode') || '').trim();
    }

    if (!init || typeof init.body !== 'string' || init.body.trim() === '') {
      return context;
    }

    try {
      var body = JSON.parse(init.body);
      if (body && typeof body === 'object') {
        context.studyUri = context.studyUri || String(body.studyUri || '').trim();
        context.processUri = context.processUri || String(body.processUri || '').trim();
        context.requestedStatus = context.requestedStatus || String(body.requestedStatus || '').trim();
        context.currentStatus = context.currentStatus || String(body.currentStatus || '').trim();
        context.mode = context.mode || String(body.mode || '').trim();
      }
    }
    catch (e) {
      // Ignore malformed payloads and fall back to query params.
    }

    return context;
  }

  function installApiBootstrapProbeTimeoutGuard(settings) {
    if (typeof window.fetch !== 'function') {
      return;
    }
    if (window.__cttApiBootstrapProbeTimeoutGuardInstalled) {
      return;
    }

    var hascoApiBase = String(settings && settings.hascoApiUrl ? settings.hascoApiUrl : '').trim();
    var probePath = normalizePathname((hascoApiBase || '/workflow') + '/hascoapi/api/repo');
    if (!probePath) {
      return;
    }

    var requestedTimeout = parseInt(settings && settings.connectionTimeoutMs, 10);
    var timeoutMs = (!isNaN(requestedTimeout) && requestedTimeout >= 3000)
      ? Math.min(requestedTimeout, 12000)
      : 8000;

    window.__cttApiBootstrapProbeTimeoutGuardInstalled = true;

    var originalFetch = window.fetch.bind(window);

    function shouldGuardRequest(resource, init) {
      var requestUrl = getRequestUrl(resource);
      var requestPath = normalizePathname(requestUrl);
      if (!requestPath || requestPath !== probePath) {
        return false;
      }

      var method = 'GET';
      if (init && typeof init.method === 'string' && init.method.trim() !== '') {
        method = init.method;
      }
      else if (resource && typeof resource.method === 'string' && resource.method.trim() !== '') {
        method = resource.method;
      }

      return String(method).trim().toUpperCase() === 'GET';
    }

    window.fetch = function (resource, init) {
      if (!shouldGuardRequest(resource, init) || typeof AbortController !== 'function') {
        return originalFetch(resource, init);
      }

      var requestInit = init ? Object.assign({}, init) : {};
      var timeoutController = new AbortController();
      var timeoutId = window.setTimeout(function () {
        try {
          timeoutController.abort();
        }
        catch (e) {
          // Ignore abort errors.
        }
      }, timeoutMs);

      var upstreamSignal = requestInit.signal || (resource && resource.signal ? resource.signal : null);
      if (upstreamSignal && typeof upstreamSignal.addEventListener === 'function') {
        if (upstreamSignal.aborted) {
          timeoutController.abort();
        }
        else {
          upstreamSignal.addEventListener('abort', function () {
            try {
              timeoutController.abort();
            }
            catch (e) {
              // Ignore abort errors.
            }
          }, { once: true });
        }
      }

      requestInit.signal = timeoutController.signal;

      return originalFetch(resource, requestInit)
        .finally(function () {
          window.clearTimeout(timeoutId);
        })
        .catch(function (error) {
          var name = error && error.name ? String(error.name) : '';
          if (name === 'AbortError') {
            window.__cttApiBootstrapProbeTimedOut = true;
          }
          throw error;
        });
    };
  }

  function installSubmissionStatusBridge(settings) {
    if (!settings || !settings.submission || !toBooleanFlag(settings.submission.enabled)) {
      return;
    }
    if (typeof window.fetch !== 'function') {
      return;
    }
    if (window.__cttSubmissionStatusBridgeInstalled) {
      return;
    }

    var validationEndpoint = String(settings.submission.validationEndpoint || '').trim();
    var statusEndpoint = String(settings.submission.statusEndpoint || '').trim();
    var validationPath = normalizePathname(validationEndpoint);
    if (!validationPath || !statusEndpoint) {
      return;
    }

    window.__cttSubmissionStatusBridgeInstalled = true;

    var originalFetch = window.fetch.bind(window);

    function persistEditorialStatus(context, validationPayload) {
      var normalized = validationPayload && validationPayload.normalized && typeof validationPayload.normalized === 'object'
        ? validationPayload.normalized
        : {};

      var studyUri = context.studyUri || String(normalized.studyUri || settings.studyUri || '').trim();
      var processUri = context.processUri || String(normalized.processUri || settings.processUri || '').trim();
      var requestedStatus = String(context.requestedStatus || normalized.requestedStatus || settings.editorial && settings.editorial.defaultState || '').trim().toLowerCase();
      var currentStatus = String(context.currentStatus || normalized.currentStatus || settings.editorial && settings.editorial.currentStatus || 'draft').trim().toLowerCase();
      var mode = String(context.mode || normalized.mode || settings.submission && settings.submission.mode || '').trim().toLowerCase();

      if (!studyUri || !processUri || !requestedStatus) {
        return;
      }
      if (mode && mode !== 'submission' && mode !== 'structured') {
        return;
      }

      var persistKey = [studyUri, processUri, currentStatus, requestedStatus].join('|');
      if (window.__cttSubmissionStatusLastKey === persistKey) {
        return;
      }
      window.__cttSubmissionStatusLastKey = persistKey;

      originalFetch(statusEndpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        credentials: 'same-origin',
        body: JSON.stringify({
          studyUri: studyUri,
          processUri: processUri,
          currentStatus: currentStatus,
          requestedStatus: requestedStatus
        })
      }).then(function (response) {
        return response.json().catch(function () {
          return null;
        });
      }).then(function (payload) {
        if (!payload || payload.isValid !== true) {
          return;
        }

        var status = String(payload.status || requestedStatus || '').trim().toLowerCase();
        if (status) {
          settings.editorial = settings.editorial || {};
          settings.editorial.currentStatus = status;
          drupalSettings.ctt = drupalSettings.ctt || {};
          drupalSettings.ctt.editorial = drupalSettings.ctt.editorial || {};
          drupalSettings.ctt.editorial.currentStatus = status;
        }

        window.__cttSubmissionStatusLastPersist = {
          key: persistKey,
          status: status,
          updated: payload.updated === true,
          issues: Array.isArray(payload.issues) ? payload.issues : []
        };

        if (typeof window.CustomEvent === 'function') {
          window.dispatchEvent(new CustomEvent('ctt:submission-status-persisted', {
            detail: window.__cttSubmissionStatusLastPersist
          }));
        }
      }).catch(function (error) {
        window.__cttSubmissionStatusLastPersistError = String(error && error.message ? error.message : error);
      });
    }

    window.fetch = function (resource, init) {
      var requestUrl = getRequestUrl(resource);
      var requestPath = normalizePathname(requestUrl);
      var context = parseRequestContext(requestUrl, init);

      var requestPromise = originalFetch(resource, init);
      if (!requestPath || requestPath !== validationPath) {
        return requestPromise;
      }

      return requestPromise.then(function (response) {
        var cloned = null;
        try {
          cloned = response.clone();
        }
        catch (e) {
          return response;
        }

        cloned.json().then(function (payload) {
          if (!payload || payload.isValid !== true) {
            return;
          }
          persistEditorialStatus(context, payload);
        }).catch(function () {
          // Ignore non-JSON responses.
        });

        return response;
      });
    };
  }

  function escapeHtml(value) {
    var text = String(value || '');
    return text
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function hasProgressIndicator(scope) {
    if (!scope || !scope.querySelector) {
      return false;
    }

    return Boolean(scope.querySelector('.MuiCircularProgress-root, [role="progressbar"], .ctt-loading-indicator, .ajax-progress-throbber .throbber'));
  }

  function hasEditorSurfaceMarker(container) {
    if (!container || !container.querySelector) {
      return false;
    }

    return Boolean(container.querySelector('.react-flow, .react-flow__viewport, .react-flow__renderer, [class*="react-flow"]'));
  }

  function isConnectingToApi(container) {
    if (!container) {
      return false;
    }

    var text = String(container.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
    if (text.indexOf('connecting to api') !== -1) {
      return true;
    }

    if (hasProgressIndicator(container) && !hasEditorSurfaceMarker(container)) {
      return true;
    }

    return false;
  }

  function pageHasConnectingHint() {
    if (!document || !document.body) {
      return false;
    }

    var bodyText = '';
    if (typeof document.body.innerText === 'string' && document.body.innerText.trim() !== '') {
      bodyText = document.body.innerText;
    }
    else {
      bodyText = document.body.textContent || '';
    }

    var text = String(bodyText).replace(/\s+/g, ' ').trim().toLowerCase();
    if (text.indexOf('connecting to api') !== -1) {
      return true;
    }

    return hasProgressIndicator(document.body);
  }

  function installApiConnectionTimeoutGuard(container, settings) {
    if (!container || container.__cttApiTimeoutGuardInstalled) {
      return;
    }

    container.__cttApiTimeoutGuardInstalled = true;

    var requestedTimeout = parseInt(settings && settings.connectionTimeoutMs, 10);
    var timeoutMs = (!isNaN(requestedTimeout) && requestedTimeout >= 5000)
      ? requestedTimeout
      : 10000;

    var startedAt = Date.now();
    var finished = false;
    var observer = null;
    var intervalId = 0;
    var hardTimeoutId = 0;

    function cleanup() {
      if (intervalId) {
        clearInterval(intervalId);
        intervalId = 0;
      }
      if (hardTimeoutId) {
        clearTimeout(hardTimeoutId);
        hardTimeoutId = 0;
      }
      if (observer) {
        observer.disconnect();
        observer = null;
      }
    }

    function renderTimeoutState() {
      if (finished) {
        return;
      }

      var elapsedSeconds = Math.max(1, Math.round((Date.now() - startedAt) / 1000));
      var processUri = settings && settings.processUri ? String(settings.processUri) : '';
      var apiBase = settings && settings.apiBaseUrl ? String(settings.apiBaseUrl).replace(/\/+$/, '') : '';
      var processTreeUrl = apiBase ? (apiBase + '/process/tree') : '';
      if (processTreeUrl && processUri) {
        processTreeUrl += '?uri=' + encodeURIComponent(processUri);
      }

      var readOnlyPreview = Boolean(settings && settings.readOnlyPreview);
      var readOnlyHint = readOnlyPreview
        ? '<p class="mb-2"><small>Read-only preview is active for this study/process context.</small></p>'
        : '';

      container.innerHTML = ''
        + '<div class="alert alert-warning ctt-api-timeout" role="alert">'
        + '  <h4 class="alert-heading mb-2">API connection timeout</h4>'
        + '  <p class="mb-2">The editor stayed in <strong>Connecting to API...</strong> for ' + elapsedSeconds + 's and was stopped to avoid an infinite loop.</p>'
        + readOnlyHint
        + '  <div class="mb-2"><small>Process URI: ' + escapeHtml(processUri || 'n/a') + '</small></div>'
        + (processTreeUrl ? ('  <div class="mb-3"><small>Diagnostic endpoint: ' + escapeHtml(processTreeUrl) + '</small></div>') : '')
        + '  <button type="button" class="btn btn-sm btn-primary" data-ctt-retry-connection="1">Retry connection</button>'
        + '</div>';

      var retryButton = container.querySelector('[data-ctt-retry-connection="1"]');
      if (retryButton) {
        retryButton.addEventListener('click', function () {
          window.location.reload();
        });
      }

      finished = true;
      cleanup();
    }

    function hasLoadedEditorSurface() {
      if (!container) {
        return false;
      }

      if (container.querySelector && container.querySelector('.ctt-api-timeout')) {
        return true;
      }

      if (hasEditorSurfaceMarker(container)) {
        return true;
      }

      var text = String(container.innerText || container.textContent || '')
        .replace(/\s+/g, ' ')
        .trim()
        .toLowerCase();

      if (text.indexOf('connecting to api') !== -1) {
        return false;
      }

      if (text.indexOf('loading ctt workflow editor') !== -1) {
        return false;
      }

      var nodeCount = container.querySelectorAll ? container.querySelectorAll('*').length : 0;
      var interactiveCount = container.querySelectorAll
        ? container.querySelectorAll('button, [role="button"], input, select, textarea, svg').length
        : 0;

      if (nodeCount >= 40 && interactiveCount >= 6) {
        return true;
      }

      return text.length >= 240 && interactiveCount >= 4;
    }

    function pageLooksStuckConnecting() {
      return pageHasConnectingHint();
    }

    function evaluateState() {
      if (finished) {
        return;
      }

      var connecting = isConnectingToApi(container);
      var pageConnecting = pageLooksStuckConnecting();
      var elapsed = Date.now() - startedAt;

      // Avoid early completion: only decide pass/fail when timeout window is reached.
      if (elapsed < timeoutMs) {
        return;
      }

      if (connecting || pageConnecting) {
        renderTimeoutState();
        return;
      }

      if (hasLoadedEditorSurface()) {
        finished = true;
        cleanup();
        return;
      }

      // Keep guard alive for a short grace window to catch late-rendered
      // loading states that move outside the observed container subtree.
      if (elapsed >= (timeoutMs + 3000)) {
        renderTimeoutState();
      }
    }

    intervalId = window.setInterval(evaluateState, 500);
    observer = new MutationObserver(evaluateState);
    observer.observe(container, {
      childList: true,
      subtree: true,
      characterData: true,
      attributes: true,
    });

    // Absolute fallback for cases where the loading text is rendered outside
    // the observed subtree or the app swaps roots unexpectedly.
    hardTimeoutId = window.setTimeout(function () {
      if (finished) {
        return;
      }

      var pageStillConnecting = pageLooksStuckConnecting();
      var containerStillConnecting = isConnectingToApi(container);

      if (pageStillConnecting || containerStillConnecting || !hasLoadedEditorSurface()) {
        renderTimeoutState();
        return;
      }

      finished = true;
      cleanup();
    }, timeoutMs + 50);

    window.setTimeout(evaluateState, 1200);
  }

  function installAbsoluteApiLoopBreaker(container, settings) {
    if (!container || container.__cttAbsoluteLoopBreakerInstalled) {
      return;
    }

    container.__cttAbsoluteLoopBreakerInstalled = true;

    var requestedTimeout = parseInt(settings && settings.connectionTimeoutMs, 10);
    var timeoutMs = (!isNaN(requestedTimeout) && requestedTimeout >= 5000)
      ? (requestedTimeout + 4000)
      : 14000;

    var startedAt = Date.now();
    var deadlineId = 0;
    var intervalId = 0;
    var resolved = false;

    function cleanup() {
      if (deadlineId) {
        clearTimeout(deadlineId);
        deadlineId = 0;
      }
      if (intervalId) {
        clearInterval(intervalId);
        intervalId = 0;
      }
    }

    function hasExistingTimeoutMessage() {
      return Boolean(document.querySelector('.ctt-api-timeout') || document.querySelector('.workflow-preview-timeout'));
    }

    function isLikelyLoaded() {
      if (!container) {
        return false;
      }

      if (hasEditorSurfaceMarker(container)) {
        return true;
      }

      if (isConnectingToApi(container) || pageHasConnectingHint()) {
        return false;
      }

      var nodeCount = container.querySelectorAll ? container.querySelectorAll('*').length : 0;
      var interactiveCount = container.querySelectorAll
        ? container.querySelectorAll('button, [role="button"], input, select, textarea, svg').length
        : 0;

      return nodeCount >= 60 && interactiveCount >= 8;
    }

    function renderForcedTimeout() {
      if (resolved) {
        return;
      }

      if (hasExistingTimeoutMessage() || isLikelyLoaded()) {
        resolved = true;
        cleanup();
        return;
      }

      var elapsedSeconds = Math.max(1, Math.round((Date.now() - startedAt) / 1000));
      var processUri = settings && settings.processUri ? String(settings.processUri) : '';
      var apiBase = settings && settings.apiBaseUrl ? String(settings.apiBaseUrl).replace(/\/+$/, '') : '';
      var processTreeUrl = apiBase ? (apiBase + '/process/tree') : '';
      if (processTreeUrl && processUri) {
        processTreeUrl += '?uri=' + encodeURIComponent(processUri);
      }

      container.innerHTML = ''
        + '<div class="alert alert-danger ctt-api-timeout ctt-api-timeout-hard" role="alert">'
        + '  <h4 class="alert-heading mb-2">Editor bootstrap timeout</h4>'
        + '  <p class="mb-2">The workflow editor remained in loading/connecting state for ' + elapsedSeconds + 's. The loop was interrupted to expose diagnostics.</p>'
        + '  <div class="mb-2"><small>Process URI: ' + escapeHtml(processUri || 'n/a') + '</small></div>'
        + (processTreeUrl ? ('  <div class="mb-3"><small>Diagnostic endpoint: ' + escapeHtml(processTreeUrl) + '</small></div>') : '')
        + '  <button type="button" class="btn btn-sm btn-primary" data-ctt-retry-connection="1">Retry connection</button>'
        + '</div>';

      var retryButton = container.querySelector('[data-ctt-retry-connection="1"]');
      if (retryButton) {
        retryButton.addEventListener('click', function () {
          window.location.reload();
        });
      }

      resolved = true;
      cleanup();
    }

    intervalId = window.setInterval(function () {
      if (resolved) {
        cleanup();
        return;
      }

      if (hasExistingTimeoutMessage() || isLikelyLoaded()) {
        resolved = true;
        cleanup();
      }
    }, 1000);

    deadlineId = window.setTimeout(renderForcedTimeout, timeoutMs);
  }

  function normalizeControlText(element) {
    if (!element) {
      return '';
    }
    var text = [
      element.textContent || '',
      element.getAttribute && element.getAttribute('aria-label') || '',
      element.getAttribute && element.getAttribute('title') || ''
    ].join(' ');
    return text.replace(/\s+/g, ' ').trim().toLowerCase();
  }

  function isExecutionActionLabel(label) {
    if (!label) {
      return false;
    }
    return /\b(start simulation|start execution|run simulation|run workflow|run|resume|pause|stop|abort|step|execute|play)\b/.test(label);
  }

  function isMutatingActionLabel(label) {
    if (!label) {
      return false;
    }

    return /\b(save|submit|create|delete|remove|new task|new subtask|add task|add subtask|duplicate|publish|approve|reject|archive|import|upload)\b/.test(label);
  }

  function shouldDisableReadOnlyControl(control) {
    if (!control) {
      return false;
    }

    var label = normalizeControlText(control);
    if (isExecutionActionLabel(label) || isMutatingActionLabel(label)) {
      return true;
    }

    var actionHint = '';
    if (control.getAttribute) {
      actionHint = String(control.getAttribute('data-action') || control.getAttribute('data-testid') || '').trim().toLowerCase();
    }

    if (actionHint !== '' && /(save|submit|create|delete|remove|run|start|stop|resume|pause|abort|execute)/.test(actionHint)) {
      return true;
    }

    return false;
  }

  function hideStartOverlayNearControl(control) {
    var current = control ? control.parentElement : null;
    var depth = 0;
    while (current && depth < 6) {
      var text = (current.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
      var buttonCount = current.querySelectorAll ? current.querySelectorAll('button').length : 0;
      if (text.indexOf('start simulation') !== -1 && buttonCount > 0 && buttonCount <= 6) {
        current.setAttribute('data-ctt-hidden-action', '1');
        current.style.display = 'none';
        return;
      }
      current = current.parentElement;
      depth++;
    }
  }

  function hideExecutionActionControls(root) {
    if (!root || !root.querySelectorAll) {
      return;
    }
    var controls = root.querySelectorAll('button, [role="button"], a');
    controls.forEach(function (control) {
      if (control.getAttribute && control.getAttribute('data-ctt-hidden-action') === '1') {
        return;
      }
      var label = normalizeControlText(control);
      if (!isExecutionActionLabel(label)) {
        return;
      }
      control.setAttribute('data-ctt-hidden-action', '1');
      control.setAttribute('aria-hidden', 'true');
      control.setAttribute('tabindex', '-1');
      control.style.pointerEvents = 'none';
      control.style.display = 'none';
      if (control.tagName === 'BUTTON') {
        control.setAttribute('disabled', 'disabled');
      }
      hideStartOverlayNearControl(control);
    });
  }

  function disableInteractiveControls(root) {
    if (!root || !root.querySelectorAll) {
      return;
    }

    // Only disable mutating controls; keep navigation/inspection interactions active.
    var controls = root.querySelectorAll('button, [role="button"], input[type="button"], input[type="submit"], input[type="reset"], [contenteditable="true"]');
    controls.forEach(function (control) {
      if (!shouldDisableReadOnlyControl(control)) {
        return;
      }

      if (control.getAttribute && control.getAttribute('data-ctt-readonly-control') === '1') {
        return;
      }

      control.setAttribute('data-ctt-readonly-control', '1');
      control.style.pointerEvents = 'none';
      control.setAttribute('aria-disabled', 'true');

      if (control.tagName === 'BUTTON' || control.tagName === 'INPUT' || control.tagName === 'SELECT' || control.tagName === 'TEXTAREA') {
        control.setAttribute('disabled', 'disabled');
      }
    });
  }

  function getReadOnlyMessage(settings) {
    var workflowAccess = settings && settings.workflowAccess ? settings.workflowAccess : {};
    var message = String(workflowAccess.message || '').trim();
    if (message !== '') {
      return message;
    }
    return 'Read-only workflow preview: only the authenticated workflow owner can edit, save, or start/stop actions for this study.';
  }

  function ensureReadOnlyNotice(container, settings) {
    if (!container || !container.parentNode) {
      return;
    }

    var existing = container.parentNode.querySelector('.ctt-readonly-banner');
    if (existing) {
      return;
    }

    var banner = document.createElement('div');
    banner.className = 'ctt-readonly-banner';
    banner.setAttribute('role', 'status');
    banner.textContent = getReadOnlyMessage(settings);
    container.parentNode.insertBefore(banner, container);
  }

  function enableReadOnlyPreview(container, settings) {
    if (!container || container.__cttReadOnlyPreviewBound) {
      return;
    }

    container.__cttReadOnlyPreviewBound = true;
    container.setAttribute('data-ctt-readonly-preview', '1');
    container.setAttribute('aria-readonly', 'true');

    var workflowAccess = settings && settings.workflowAccess ? settings.workflowAccess : {};
    var reasonCode = String(workflowAccess.reasonCode || '').trim();
    if (reasonCode !== '') {
      container.setAttribute('data-ctt-readonly-reason', reasonCode);
    }

    ensureReadOnlyNotice(container, settings);

    hideExecutionActionControls(container);
    disableInteractiveControls(container);

    var rescanScheduled = false;
    function scheduleReadOnlyRescan() {
      if (rescanScheduled) {
        return;
      }
      rescanScheduled = true;
      window.setTimeout(function () {
        rescanScheduled = false;
        hideExecutionActionControls(container);
        disableInteractiveControls(container);
      }, 50);
    }

    var observer = new MutationObserver(function () {
      scheduleReadOnlyRescan();
    });
    observer.observe(container, {
      childList: true,
      subtree: true
    });

    [120, 350, 900, 1600].forEach(function (delay) {
      setTimeout(function () {
        hideExecutionActionControls(container);
        disableInteractiveControls(container);
      }, delay);
    });
  }

  Drupal.behaviors.cttEditorInit = {
    attach: function (context) {
      once('ctt-editor-init', '#ctt-workflow-app', context).forEach(function (container) {
        var settings = drupalSettings.ctt || {};
        var workflowAccess = settings.workflowAccess || {};
        var readOnlyPreview = toBooleanFlag(settings.readOnlyPreview)
          || toBooleanFlag(settings.execution && settings.execution.readOnlyPreview)
          || (toBooleanFlag(workflowAccess.isStudyContext) && !toBooleanFlag(workflowAccess.isWorkflowOwnerAuthenticated));

        settings.workflowAccess = workflowAccess;
        settings.workflowAccess.readOnlyPreview = readOnlyPreview;
        var baseUrl = (drupalSettings.path && drupalSettings.path.baseUrl) || settings.drupalBaseUrl || '/';
        if (!baseUrl.endsWith('/')) {
          baseUrl += '/';
        }
        // Ensure API URLs are same-origin and respect Drupal base path.
        settings.drupalBaseUrl = baseUrl;
        settings.hascoApiUrl = baseUrl + 'workflow';
        settings.apiBaseUrl = baseUrl + 'workflow/api';
        drupalSettings.ctt = drupalSettings.ctt || {};
        drupalSettings.ctt.drupalBaseUrl = settings.drupalBaseUrl;
        drupalSettings.ctt.hascoApiUrl = settings.hascoApiUrl;
        drupalSettings.ctt.apiBaseUrl = settings.apiBaseUrl;
        drupalSettings.ctt.execution = drupalSettings.ctt.execution || settings.execution || {};
        drupalSettings.ctt.execution.readOnlyPreview = readOnlyPreview;
        drupalSettings.ctt.readOnlyPreview = readOnlyPreview;
        drupalSettings.ctt.workflowAccess = drupalSettings.ctt.workflowAccess || settings.workflowAccess || {};
        drupalSettings.ctt.workflowAccess.readOnlyPreview = readOnlyPreview;

        installApiBootstrapProbeTimeoutGuard(settings);
        installSubmissionStatusBridge(settings);

        var maxAttempts = 50;
        var attempt = 0;
        var configuredMinHeight = parseInt(container.getAttribute('data-ctt-min-height'), 10);
        var minHeight = (!isNaN(configuredMinHeight) && configuredMinHeight >= 200)
          ? configuredMinHeight
          : 420;

        // Force full usable viewport for embedded mode.
        container.style.width = '100%';
        container.style.maxWidth = '100%';
        container.style.display = 'block';
        container.style.position = 'relative';
        container.style.overflow = 'hidden';
        // Let the Drupal page layout control width/position.
        container.style.flex = '1 1 auto';
        container.style.minHeight = '0';

        function applyContainerSize() {
          var rect = container.getBoundingClientRect();
          var containerTop = Math.max(0, rect.top);
          var viewportHeight = window.innerHeight || document.documentElement.clientHeight || 800;
          var footer = document.querySelector('footer.site-footer');
          var footerTop = viewportHeight;

          if (footer && typeof footer.getBoundingClientRect === 'function') {
            var footerRect = footer.getBoundingClientRect();
            if (footerRect && footerRect.top > 0) {
              footerTop = Math.min(viewportHeight, footerRect.top);
            }
          }

          var availableHeight = Math.max(minHeight, Math.floor(footerTop - containerTop));
          container.style.height = availableHeight + 'px';
          container.style.minHeight = availableHeight + 'px';
        }

        applyContainerSize();
        window.addEventListener('resize', applyContainerSize, { passive: true });
        setTimeout(applyContainerSize, 50);
        setTimeout(applyContainerSize, 250);

        // Backup watchdog: if initialization never completes, replace spinner with diagnostics.
        installAbsoluteApiLoopBreaker(container, settings);

        /**
         * Wait for the UMD bundle to expose the global HASCOWorkflowEditor.
         */
        function waitForEditor() {
          attempt++;
          // Support older/newer UMD global names.
          var umdGlobal = window.HASCOWorkflowEditor || window.HascoWorkflowEditor || window.hascoWorkflowEditor;
          if (typeof umdGlobal !== 'undefined' &&
              (umdGlobal.mountApp || umdGlobal.mountWorkflowEditor)) {
            mountEditor(container, readOnlyPreview, settings);
          } else if (attempt < maxAttempts) {
            setTimeout(waitForEditor, 200);
          } else {
            try {
              var umdScript = document.querySelector('script[src*="hasco-workflow-editor.umd.js"]');
              var aggregatedScript = document.querySelector('script[src*="/sites/default/files/js/"]');
              var baseUrlGuess = (drupalSettings.path && drupalSettings.path.baseUrl) || settings.drupalBaseUrl || '/';
              console.error('[CTT Editor] UMD global not detected after ' + maxAttempts + ' attempts.', {
                hasGlobal: typeof window.HASCOWorkflowEditor !== 'undefined' || typeof window.HascoWorkflowEditor !== 'undefined' || typeof window.hascoWorkflowEditor !== 'undefined',
                baseUrl: baseUrlGuess,
                umdScriptSrc: umdScript ? umdScript.getAttribute('src') : null,
                hasAggregatedJs: Boolean(aggregatedScript),
                settings: drupalSettings.ctt || settings || {},
              });
              if (!umdScript) {
                console.error('[CTT Editor] Script tag for hasco-workflow-editor.umd.js not found. If JS aggregation is enabled, the UMD may be bundled into /sites/default/files/js/js_*.js. Otherwise, check that the library is attached and that the file exists in the deployed build.');
              }
            } catch (e) {
              // Ignore logging errors.
            }
            var debugHint = '';
            try {
              var umdScript2 = document.querySelector('script[src*="hasco-workflow-editor.umd.js"]');
              var aggregated2 = document.querySelector('script[src*="/sites/default/files/js/"]');
              debugHint = '<br/><small style="color:#666;">' +
                'UMD script tag: ' + (umdScript2 ? 'found' : (aggregated2 ? 'not found (JS aggregated)' : 'not found')) +
                '</small>';
            } catch (e) {}
            container.innerHTML = '<p style="color:red;">Failed to load CTT editor. Check the browser console for errors.</p>' + debugHint;
          }
        }

        waitForEditor();
      });
    }
  };

  /**
   * Mount the full React CTT application into the container.
   *
   * The App component auto-detects Drupal mode, picks DrupalAdapter,
   * reads processUri from drupalSettings and URL params, handles auth, etc.
   * This is the exact same editor that runs in standalone mode.
   */
  function mountEditor(container, readOnlyPreview, settings) {
    // Remove loading indicator.
    container.innerHTML = '';
    container.style.overflow = 'hidden';

    var lib = window.HASCOWorkflowEditor || window.HascoWorkflowEditor || window.hascoWorkflowEditor;

    if (typeof lib.mountApp === 'function') {
      lib.mountApp(container);
      installApiConnectionTimeoutGuard(container, settings);
      if (readOnlyPreview) {
        enableReadOnlyPreview(container, settings);
      }
    } else if (typeof lib.mountWorkflowEditor === 'function') {
      lib.mountWorkflowEditor(container, {});
      installApiConnectionTimeoutGuard(container, settings);
      if (readOnlyPreview) {
        enableReadOnlyPreview(container, settings);
      }
    } else {
      container.innerHTML = '<p style="color:orange;">CTT Editor UMD loaded but no mount function found.</p>';
      console.error('[CTT Editor] No mount function found in UMD bundle.');
    }
  }

})(Drupal, drupalSettings, once);
