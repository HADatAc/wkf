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

  function textIncludesAny(value, candidates) {
    var normalized = String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();
    if (!normalized) {
      return false;
    }
    return candidates.some(function (candidate) {
      return normalized.indexOf(String(candidate).toLowerCase()) !== -1;
    });
  }

  function ensureSpecialExecutionPanel(container) {
    if (!container || !container.parentNode) {
      return null;
    }

    var existing = container.parentNode.querySelector('.ctt-special-execution-panel');
    if (existing) {
      return existing;
    }

    var panel = document.createElement('section');
    panel.className = 'ctt-special-execution-panel';
    panel.innerHTML = ''
      + '<div class="ctt-special-execution-panel__title">Process Execution</div>'
      + '<div class="ctt-special-execution-panel__status" data-ctt-special-status="1">Preparing execution...</div>';
    var interactionHost = document.getElementById('ctt-execution-interaction-host');
    if (interactionHost && interactionHost.parentNode === container.parentNode) {
      container.parentNode.insertBefore(panel, interactionHost);
    }
    else {
      container.parentNode.insertBefore(panel, container);
    }
    return panel;
  }

  function setSpecialExecutionStatus(panel, message, state) {
    if (!panel) {
      return;
    }
    var statusNode = panel.querySelector('[data-ctt-special-status="1"]');
    if (!statusNode) {
      return;
    }

    statusNode.textContent = String(message || '').trim();
    statusNode.setAttribute('data-ctt-special-state', String(state || 'neutral').trim());
  }

  function getTaskPanelScope(container) {
    if (container && container.__cttRelocatedTaskTracker && container.__cttRelocatedTaskTracker.isConnected) {
      return container.__cttRelocatedTaskTracker;
    }

    if (container) {
      var candidate = findTaskTrackerCandidate(container);
      if (candidate) {
        return candidate;
      }
    }

    if (document && document.querySelector) {
      return document.querySelector('[aria-label="Tasks panel"]');
    }

    return null;
  }

  function isTaskProgressButton(button) {
    if (!button) {
      return false;
    }

    var label = normalizeControlText(button);
    if (!label || label.length < 6) {
      return false;
    }

    // Keep only meaningful task rows and ignore panel/tool controls.
    if (/(run test|reset test|auto-organize|auto order tasks|toggle test panel|collapse tasks panel|close tasks panel|workflow details|layout settings|tasks \(layers\)|collaborators|zoom in|zoom out|fit view|toggle interactivity|validation)/.test(label)) {
      return false;
    }

    return true;
  }

  function getTaskProgressButtons(taskPanel) {
    if (!taskPanel || !taskPanel.querySelectorAll) {
      return [];
    }

    var controls = taskPanel.querySelectorAll('button, [role="button"]');
    var tasks = [];
    controls.forEach(function (control) {
      if (!isTaskProgressButton(control)) {
        return;
      }
      tasks.push(control);
    });

    return tasks;
  }

  function getTaskButtonLabel(button) {
    if (!button) {
      return '';
    }
    return String(button.textContent || '')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function markTaskAsActive(tasks, activeButton) {
    tasks.forEach(function (task) {
      if (!task || !task.setAttribute) {
        return;
      }
      if (task === activeButton) {
        task.setAttribute('data-ctt-task-active', '1');
      }
      else {
        task.removeAttribute('data-ctt-task-active');
      }
    });
  }

  function getActiveTaskIndex(tasks) {
    var i;
    for (i = 0; i < tasks.length; i += 1) {
      var task = tasks[i];
      var dataActive = task.getAttribute && task.getAttribute('data-ctt-task-active') === '1';
      var ariaCurrent = task.getAttribute && task.getAttribute('aria-current') === 'true';
      var ariaPressed = task.getAttribute && task.getAttribute('aria-pressed') === 'true';
      var className = String(task.className || '').toLowerCase();
      var classActive = /(active|selected|current)/.test(className);
      if (dataActive || ariaCurrent || ariaPressed || classActive) {
        return i;
      }
    }

    if (tasks.length > 0) {
      return 0;
    }

    return -1;
  }

  function ensureTaskProgressNavigator(panel) {
    if (!panel) {
      return null;
    }

    var existing = panel.querySelector('[data-ctt-task-nav="1"]');
    if (existing) {
      return existing;
    }

    var nav = document.createElement('div');
    nav.className = 'ctt-special-task-nav';
    nav.setAttribute('data-ctt-task-nav', '1');
    nav.innerHTML = ''
      + '<div class="ctt-special-task-nav__title">Task Progression</div>'
      + '<div class="ctt-special-task-nav__hint">Use Previous/Next to move task by task.</div>'
      + '<div class="ctt-special-task-nav__current" data-ctt-task-current="1">Waiting for task list...</div>'
      + '<div class="ctt-special-task-nav__actions">'
      + '  <button type="button" class="btn btn-sm btn-outline-primary" data-ctt-task-prev="1">Previous</button>'
      + '  <button type="button" class="btn btn-sm btn-primary" data-ctt-task-next="1">Next</button>'
      + '</div>';

    panel.appendChild(nav);
    return nav;
  }

  function extractTaskUri(button) {
    if (!button) {
      return '';
    }

    var raw = [
      button.getAttribute && button.getAttribute('aria-label') || '',
      button.getAttribute && button.getAttribute('title') || '',
      button.textContent || ''
    ].join(' ');

    var match = raw.match(/https?:\/\/[^\s)]+/i);
    if (!match || !match[0]) {
      return '';
    }

    return String(match[0]).replace(/[.,;:!?]$/, '').trim();
  }

  function getTaskLabelWithoutUri(button) {
    var label = getTaskButtonLabel(button);
    if (!label) {
      return '';
    }

    return label
      .replace(/https?:\/\/\S+/gi, '')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function syncCanvasWithTask(container, taskButton) {
    if (!container || !taskButton || !container.querySelectorAll) {
      return;
    }

    var allNodes = container.querySelectorAll('.react-flow__node[data-id], [class*="react-flow__node"][data-id]');
    allNodes.forEach(function (node) {
      node.removeAttribute('data-ctt-current-task-node');
      node.classList.remove('ctt-current-task-node');
    });

    if (!allNodes.length) {
      return;
    }

    var taskUri = extractTaskUri(taskButton);
    var targetNode = null;

    if (taskUri) {
      for (var i = 0; i < allNodes.length; i += 1) {
        if (String(allNodes[i].getAttribute('data-id') || '').trim() === taskUri) {
          targetNode = allNodes[i];
          break;
        }
      }
    }

    if (!targetNode) {
      var taskText = getTaskLabelWithoutUri(taskButton).toLowerCase();
      if (taskText) {
        for (var j = 0; j < allNodes.length; j += 1) {
          var nodeText = String(allNodes[j].textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
          if (nodeText && nodeText.indexOf(taskText) !== -1) {
            targetNode = allNodes[j];
            break;
          }
        }
      }
    }

    if (!targetNode) {
      return;
    }

    targetNode.setAttribute('data-ctt-current-task-node', '1');
    targetNode.classList.add('ctt-current-task-node');

    // Trigger the editor's own selection behavior without requiring pointer interaction.
    if (typeof targetNode.click === 'function') {
      targetNode.click();
    }
  }

  function installTaskProgressNavigator(panel, container, hooks) {
    if (!panel || !container) {
      return;
    }

    var nav = ensureTaskProgressNavigator(panel);
    if (!nav) {
      return;
    }

    if (nav.__cttTaskNavBound) {
      return;
    }
    nav.__cttTaskNavBound = true;

    var currentNode = nav.querySelector('[data-ctt-task-current="1"]');
    var prevButton = nav.querySelector('[data-ctt-task-prev="1"]');
    var nextButton = nav.querySelector('[data-ctt-task-next="1"]');

    function updateNavigatorState() {
      var taskPanel = getTaskPanelScope(container);
      var tasks = getTaskProgressButtons(taskPanel);
      var activeIndex = getActiveTaskIndex(tasks);

      if (!tasks.length || activeIndex < 0) {
        if (currentNode) {
          currentNode.textContent = 'Waiting for task list...';
        }
        if (prevButton) {
          prevButton.disabled = true;
        }
        if (nextButton) {
          nextButton.textContent = 'Next';
          nextButton.disabled = true;
        }
        return;
      }

      var activeTask = tasks[activeIndex];
      markTaskAsActive(tasks, activeTask);
      syncCanvasWithTask(container, activeTask);

      if (currentNode) {
        var label = getTaskButtonLabel(activeTask) || 'Current task';
        currentNode.textContent = 'Task ' + (activeIndex + 1) + ' of ' + tasks.length + ': ' + label;
      }

      if (prevButton) {
        prevButton.disabled = activeIndex <= 0;
      }
      if (nextButton) {
        if (activeIndex >= tasks.length - 1) {
          nextButton.textContent = 'Finish';
          nextButton.disabled = false;
        }
        else {
          nextButton.textContent = 'Next';
          nextButton.disabled = false;
        }
      }
    }

    function moveTask(offset) {
      var taskPanel = getTaskPanelScope(container);
      var tasks = getTaskProgressButtons(taskPanel);
      var activeIndex = getActiveTaskIndex(tasks);
      if (!tasks.length || activeIndex < 0) {
        updateNavigatorState();
        return;
      }

      var targetIndex = activeIndex + offset;
      if (targetIndex < 0 || targetIndex >= tasks.length) {
        if (offset > 0 && activeIndex === tasks.length - 1 && hooks && typeof hooks.onLastTaskAdvance === 'function') {
          hooks.onLastTaskAdvance();
        }
        updateNavigatorState();
        return;
      }

      var target = tasks[targetIndex];
      markTaskAsActive(tasks, target);
      target.click();
      syncCanvasWithTask(container, target);
      updateNavigatorState();
    }

    if (prevButton) {
      prevButton.addEventListener('click', function () {
        moveTask(-1);
      });
    }
    if (nextButton) {
      nextButton.addEventListener('click', function () {
        moveTask(1);
      });
    }

    container.addEventListener('click', function (event) {
      var clicked = event && event.target && event.target.closest ? event.target.closest('button, [role="button"]') : null;
      if (!clicked || !isTaskProgressButton(clicked)) {
        return;
      }

      var taskPanel = getTaskPanelScope(container);
      var tasks = getTaskProgressButtons(taskPanel);
      if (!tasks.length) {
        return;
      }

      if (tasks.indexOf(clicked) === -1) {
        return;
      }

      markTaskAsActive(tasks, clicked);
      syncCanvasWithTask(container, clicked);
      updateNavigatorState();
    });

    panel.__cttTaskNavUpdate = updateNavigatorState;
    updateNavigatorState();
  }

  function isExecutionView(settings) {
    var mode = String(settings && settings.mode || '').trim().toLowerCase();
    var special = settings && settings.specialExecution ? settings.specialExecution : {};
    return mode === 'execution' || toBooleanFlag(special.enabled);
  }

  function isEditView(settings) {
    return !isExecutionView(settings);
  }

  function findButtonByLabels(scope, labels) {
    if (!scope || !scope.querySelectorAll) {
      return null;
    }

    var controls = scope.querySelectorAll('button, [role="button"], a[role="button"], a');
    for (var i = 0; i < controls.length; i += 1) {
      var label = normalizeControlText(controls[i]);
      if (textIncludesAny(label, labels)) {
        return controls[i];
      }
    }

    return null;
  }

  function findNodeByText(scope, labels) {
    if (!scope || !scope.querySelectorAll) {
      return null;
    }

    var nodes = scope.querySelectorAll('h1, h2, h3, h4, h5, p, span, div, strong');
    for (var i = 0; i < nodes.length; i += 1) {
      if (textIncludesAny(nodes[i].textContent || '', labels)) {
        return nodes[i];
      }
    }
    return null;
  }

  function findExecutionPanelCandidate(scope) {
    if (!scope || !scope.querySelectorAll) {
      return null;
    }

    var buttons = scope.querySelectorAll('button, [role="button"], a[role="button"]');
    for (var i = 0; i < buttons.length; i += 1) {
      var label = normalizeControlText(buttons[i]);
      if (!isExecutionActionLabel(label)) {
        continue;
      }

      var current = buttons[i].parentElement;
      var depth = 0;
      while (current && depth < 9) {
        var nestedButtons = current.querySelectorAll ? current.querySelectorAll('button, [role="button"], a[role="button"]') : [];
        if (nestedButtons.length >= 3) {
          return current;
        }
        current = current.parentElement;
        depth += 1;
      }
    }

    return null;
  }

  function findTaskTrackerCandidate(scope) {
    if (!scope || !scope.querySelectorAll) {
      return null;
    }

    var selectors = [
      '[aria-label="Tasks panel"]',
      '[role="complementary"][aria-label*="Tasks"]',
      '[role="complementary"][aria-label*="tasks"]'
    ];

    for (var i = 0; i < selectors.length; i += 1) {
      var matched = scope.querySelector(selectors[i]);
      if (matched) {
        return matched;
      }
    }

    return null;
  }

  function ensureExecutionPanelsStack() {
    var host = document.getElementById('ctt-execution-interaction-host');
    if (!host) {
      return null;
    }

    var stack = host.querySelector('.ctt-execution-panels-stack');
    if (!stack) {
      stack = document.createElement('div');
      stack.className = 'ctt-execution-panels-stack';
      host.appendChild(stack);
    }

    host.classList.add('is-visible');
    return stack;
  }

  function hideEditingControlsForExecution(root) {
    if (!root || !root.querySelectorAll) {
      return;
    }

    var controls = root.querySelectorAll('button, [role="button"], a, input[type="button"], input[type="submit"]');
    controls.forEach(function (control) {
      var label = normalizeControlText(control);
      var isEditingControl = /\b(save to api|save|tasks palette|task palette|create sub-?task|new sub-?task|new task|add child|add subtask|assign instrument|edit instrument|delete|remove|change parent|set root|auto order)\b/.test(label);
      if (!isEditingControl) {
        return;
      }

      control.setAttribute('data-ctt-hidden-edit-control', '1');
      control.setAttribute('aria-hidden', 'true');
      control.setAttribute('tabindex', '-1');
      control.style.pointerEvents = 'none';
      control.style.display = 'none';
      if (control.tagName === 'BUTTON' || control.tagName === 'INPUT') {
        control.setAttribute('disabled', 'disabled');
      }
    });
  }

  function hideExecutionControlsForEdit(root) {
    if (!root || !root.querySelectorAll) {
      return;
    }

    var controls = root.querySelectorAll('button, [role="button"], a, input[type="button"], input[type="submit"]');
    controls.forEach(function (control) {
      var label = normalizeControlText(control);
      if (!isExecutionActionLabel(label)) {
        return;
      }

      control.setAttribute('data-ctt-hidden-exec-control', '1');
      control.setAttribute('aria-hidden', 'true');
      control.setAttribute('tabindex', '-1');
      control.style.pointerEvents = 'none';
      control.style.display = 'none';
      if (control.tagName === 'BUTTON' || control.tagName === 'INPUT') {
        control.setAttribute('disabled', 'disabled');
      }
    });

    var panelCandidate = findExecutionPanelCandidate(root);
    if (panelCandidate) {
      panelCandidate.style.display = 'none';
      panelCandidate.setAttribute('aria-hidden', 'true');
      panelCandidate.setAttribute('data-ctt-hidden-exec-panel', '1');
    }
  }

  function relocateInteractionPanelForExecution(container, settings) {
    if (!isExecutionView(settings)) {
      return;
    }

    var panelCandidate = container.__cttRelocatedExecutionPanel;
    if (!panelCandidate || !panelCandidate.isConnected) {
      panelCandidate = findExecutionPanelCandidate(container);
    }
    if (!panelCandidate) {
      return;
    }

    container.__cttRelocatedExecutionPanel = panelCandidate;
  }

  function relocateTaskTrackerForExecution(container, settings) {
    if (!isExecutionView(settings)) {
      return;
    }

    var taskTracker = container.__cttRelocatedTaskTracker;
    if (!taskTracker || !taskTracker.isConnected) {
      taskTracker = findTaskTrackerCandidate(container);
      if (!taskTracker && document && document.querySelector) {
        taskTracker = document.querySelector('[aria-label="Tasks panel"]');
      }
    }
    if (!taskTracker) {
      return;
    }

    container.__cttRelocatedTaskTracker = taskTracker;
  }

  function enforceCanvasModeSplit(container, settings) {
    if (!container) {
      return;
    }

    var executionView = isExecutionView(settings);
    container.setAttribute('data-ctt-execution-view', executionView ? '1' : '0');
    var executionHost = document.getElementById('ctt-execution-interaction-host');

    if (executionView) {
      hideEditingControlsForExecution(container);
      if (executionHost) {
        hideEditingControlsForExecution(executionHost);
      }
      relocateInteractionPanelForExecution(container, settings);
      relocateTaskTrackerForExecution(container, settings);
      return;
    }

    if (isEditView(settings)) {
      hideExecutionControlsForEdit(container);
      if (executionHost) {
        hideExecutionControlsForEdit(executionHost);
        executionHost.classList.remove('is-visible');
        executionHost.innerHTML = '';
      }
    }
  }

  function installSpecialExecutionMode(container, settings) {
    var special = settings && settings.specialExecution ? settings.specialExecution : null;
    if (!special || !toBooleanFlag(special.enabled)) {
      return;
    }
    if (container.__cttSpecialExecutionInstalled) {
      return;
    }

    container.__cttSpecialExecutionInstalled = true;

    var panel = ensureSpecialExecutionPanel(container);
    if (!panel) {
      return;
    }

    var returnTo = String(special.returnTo || '').trim();
    var autoExecute = toBooleanFlag(special.autoExecute);
    var shouldRedirect = toBooleanFlag(special.redirectOnCompletion) && returnTo !== '';
    var executionHost = document.getElementById('ctt-execution-interaction-host');
    var started = false;
    var completed = false;
    var redirected = false;

    // Ensure progression controls are visible immediately, even before monitor ticks.
    function finalizeFromLastTaskAdvance() {
      if (completed) {
        return;
      }

      started = true;
      setSpecialExecutionStatus(panel, 'All tasks completed. Finalizing execution...', 'running');

      var stopButton = findButtonByLabels(executionHost || container, [
        'stop simulation',
        'stop execution',
        'end simulation',
        'finish simulation',
        'complete execution',
        'stop',
        'run test',
      ]);

      if (stopButton && !stopButton.disabled && stopButton.getAttribute('aria-disabled') !== 'true') {
        stopButton.click();
      }

      window.setTimeout(function () {
        monitorExecutionState();
      }, 220);

      // Fallback: if upstream completion signals do not appear, still finish user flow.
      window.setTimeout(function () {
        if (!completed) {
          markCompleted();
        }
      }, 2500);
    }

    installTaskProgressNavigator(panel, container, {
      onLastTaskAdvance: finalizeFromLastTaskAdvance,
    });
    if (panel.__cttTaskNavUpdate) {
      panel.__cttTaskNavUpdate();
    }

    function markCompleted() {
      if (completed) {
        return;
      }
      completed = true;
      setSpecialExecutionStatus(panel, 'Execution completed. Returning to Process Executions...', 'success');

      // Keep completion UX outside the canvas and then navigate back.
      container.style.display = 'none';
      container.setAttribute('aria-hidden', 'true');

      if (!shouldRedirect || redirected) {
        return;
      }

      redirected = true;
      window.setTimeout(function () {
        window.location.assign(returnTo);
      }, 900);
    }

    function tryAutoStart() {
      if (!autoExecute || started) {
        return;
      }

      var startButton = findButtonByLabels(container, [
        'start simulation',
        'start execution',
        'run simulation',
        'run workflow',
        'run test',
        'run',
      ]);

      if (!startButton && executionHost) {
        startButton = findButtonByLabels(executionHost, [
          'start simulation',
          'start execution',
          'run simulation',
          'run workflow',
          'run test',
          'run',
        ]);
      }

      if (!startButton) {
        setSpecialExecutionStatus(panel, 'Waiting for execution controls...', 'neutral');
        return;
      }

      if (startButton.disabled || startButton.getAttribute('aria-disabled') === 'true') {
        setSpecialExecutionStatus(panel, 'Execution controls loaded. Waiting for workflow readiness...', 'neutral');
        return;
      }

      started = true;
      setSpecialExecutionStatus(panel, 'Execution started...', 'running');
      hideStartOverlayNearControl(startButton);
      startButton.click();
    }

    function monitorExecutionState() {
      if (completed) {
        return;
      }

      installTaskProgressNavigator(panel, container, {
        onLastTaskAdvance: finalizeFromLastTaskAdvance,
      });
      if (panel.__cttTaskNavUpdate) {
        panel.__cttTaskNavUpdate();
      }

      tryAutoStart();

      var runningNode = findNodeByText(container, ['running', 'starting simulation']);
      if (runningNode && started) {
        setSpecialExecutionStatus(panel, 'Execution running...', 'running');
      }

      var runSummaryNode = findNodeByText(document.body, ['run summary']);
      if (runSummaryNode) {
        var summaryOverlay = runSummaryNode.closest('div');
        if (summaryOverlay && summaryOverlay !== panel && summaryOverlay.style) {
          summaryOverlay.style.display = 'none';
          summaryOverlay.setAttribute('aria-hidden', 'true');
        }
        markCompleted();
        return;
      }

      var completionScope = executionHost || container;
      var completionText = String(completionScope && completionScope.textContent || '')
        .replace(/\s+/g, ' ')
        .trim()
        .toLowerCase();

      var hasExplicitCompletedStatus = /(execution|status)\s*[:\-]?\s*(done|completed)\b/.test(completionText);
      var hasStableCompletionCounters = /\bcompleted\b\s*[:\-]?\s*[1-9]\d*\b/.test(completionText)
        && /\bactive\b\s*[:\-]?\s*0\b/.test(completionText)
        && !/\b(paused|running)\b/.test(completionText);

      if (started && (hasExplicitCompletedStatus || hasStableCompletionCounters)) {
        markCompleted();
        return;
      }

      var doneNode = findNodeByText(container, [' completed ', ' done ']);
      if (doneNode && started) {
        markCompleted();
      }
    }

    setSpecialExecutionStatus(panel, autoExecute ? 'Waiting to start execution...' : 'Execution panel ready.', 'neutral');

    var monitorScheduled = false;
    function scheduleMonitorExecutionState() {
      if (monitorScheduled) {
        return;
      }
      monitorScheduled = true;
      window.setTimeout(function () {
        monitorScheduled = false;
        monitorExecutionState();
      }, 120);
    }

    var observer = new MutationObserver(function () {
      scheduleMonitorExecutionState();
    });
    observer.observe(container, {
      childList: true,
      subtree: true,
      characterData: false,
    });

    var intervalId = window.setInterval(monitorExecutionState, 500);
    window.setTimeout(function () {
      monitorExecutionState();
    }, 200);

    window.addEventListener('beforeunload', function () {
      observer.disconnect();
      window.clearInterval(intervalId);
    }, { once: true });
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
        installSpecialExecutionMode(container, settings);
        enforceCanvasModeSplit(container, settings);

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

        var splitScheduled = false;
        function scheduleSplitEnforcement() {
          if (splitScheduled) {
            return;
          }
          splitScheduled = true;
          window.setTimeout(function () {
            splitScheduled = false;
            enforceCanvasModeSplit(container, settings);
          }, 140);
        }

        var splitObserver = new MutationObserver(function () {
          scheduleSplitEnforcement();
        });
        splitObserver.observe(container, {
          childList: true,
          subtree: true,
          characterData: false,
        });

        var executionHost = document.getElementById('ctt-execution-interaction-host');
        if (executionHost) {
          splitObserver.observe(executionHost, {
            childList: true,
            subtree: true,
            characterData: false,
          });
        }

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
