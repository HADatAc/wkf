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

  function installExecutionSaveTraceBridge(settings) {
    if (!settings || typeof window.fetch !== 'function') {
      return;
    }
    if (window.__cttExecutionSaveTraceBridgeInstalled) {
      return;
    }

    var savePath = normalizePathname('/ctt/execution/save');
    var specialExecution = settings && settings.specialExecution ? settings.specialExecution : {};
    var testMode = toBooleanFlag(specialExecution.testMode);
    var simulationType = String(specialExecution.simulationType || 'individual').trim().toLowerCase();
    var cohortStudentIds = Array.isArray(specialExecution.studentIds)
      ? specialExecution.studentIds.map(function (value) { return String(value || '').trim(); }).filter(Boolean)
      : [];
    var cohortProgressKey = String(specialExecution.cohortProgressKey || '').trim();
    if (!cohortProgressKey) {
      var studyUri = String(settings && settings.studyUri || '').trim();
      var processUri = String(settings && settings.processUri || '').trim();
      if (studyUri && processUri) {
        cohortProgressKey = 'ctt.cohort.progress.fallback.' + encodeURIComponent(studyUri + '|' + processUri);
      }
    }

    function readCohortProgress() {
      var progress = {
        completed: [],
        lastEndedStudentId: '',
      };
      if (!cohortProgressKey || !window.localStorage) {
        return progress;
      }
      try {
        var raw = window.localStorage.getItem(cohortProgressKey);
        if (!raw) {
          return progress;
        }
        var parsed = JSON.parse(raw);
        if (!parsed || typeof parsed !== 'object') {
          return progress;
        }
        if (Array.isArray(parsed.completed)) {
          progress.completed = parsed.completed.map(function (value) { return String(value || '').trim(); }).filter(Boolean);
        }
        progress.lastEndedStudentId = String(parsed.lastEndedStudentId || '').trim();
      }
      catch (e) {
      }
      return progress;
    }

    function writeCohortProgress(progress) {
      if (!cohortProgressKey || !window.localStorage || !progress || typeof progress !== 'object') {
        return;
      }
      try {
        window.localStorage.setItem(cohortProgressKey, JSON.stringify({
          completed: Array.isArray(progress.completed) ? progress.completed : [],
          lastEndedStudentId: String(progress.lastEndedStudentId || '').trim(),
        }));
      }
      catch (e) {
      }
    }

    function getCurrentCohortStudent(progress) {
      if (!Array.isArray(cohortStudentIds) || !cohortStudentIds.length) {
        return '';
      }
      var completedSet = new Set((progress && Array.isArray(progress.completed)) ? progress.completed : []);
      for (var i = 0; i < cohortStudentIds.length; i += 1) {
        var candidate = cohortStudentIds[i];
        if (!completedSet.has(candidate)) {
          return candidate;
        }
      }
      return cohortStudentIds[cohortStudentIds.length - 1] || '';
    }

    function publishCohortTurnState(progress, currentStudentId) {
      window.__cttCohortTurnContext = {
        simulationType: simulationType,
        studentIds: cohortStudentIds.slice(),
        completedStudentIds: Array.isArray(progress && progress.completed) ? progress.completed.slice() : [],
        lastEndedStudentId: String(progress && progress.lastEndedStudentId || '').trim(),
        currentStudentId: String(currentStudentId || '').trim(),
      };

      if (typeof window.CustomEvent === 'function') {
        window.dispatchEvent(new CustomEvent('ctt:cohort-turn-updated', {
          detail: window.__cttCohortTurnContext,
        }));
      }
    }

    if (simulationType === 'cohort') {
      var initialProgress = testMode
        ? { completed: [], lastEndedStudentId: '' }
        : readCohortProgress();
      if (testMode) {
        writeCohortProgress(initialProgress);
      }
      publishCohortTurnState(initialProgress, getCurrentCohortStudent(initialProgress));
    }

    window.__cttExecutionSaveTraceBridgeInstalled = true;
    var originalFetch = window.fetch.bind(window);

    window.fetch = function (resource, init) {
      var requestUrl = getRequestUrl(resource);
      var requestPath = normalizePathname(requestUrl);
      if (!requestPath || !savePath || !requestPath.endsWith(savePath) || !init || typeof init.body !== 'string') {
        return originalFetch(resource, init);
      }

      var requestInit = Object.assign({}, init);
      var subjectCandidate = '';
      try {
        var payload = JSON.parse(init.body);
        if (!payload || typeof payload !== 'object') {
          return originalFetch(resource, init);
        }

        if (!Object.prototype.hasOwnProperty.call(payload, 'testMode') && testMode) {
          payload.testMode = true;
        }

        if (simulationType === 'cohort') {
          var cohortState = window.__cttCohortTurnContext || {};
          var inferredStudent = String(cohortState.currentStudentId || '').trim();
          if (!payload.subjectUri && !payload.participantUri && inferredStudent) {
            payload.subjectUri = inferredStudent;
          }
          subjectCandidate = String(payload.subjectUri || payload.participantUri || inferredStudent || '').trim();
        }

        var skipTraceBridge = toBooleanFlag(payload.skipTraceBridge);
        if (skipTraceBridge) {
          delete payload.skipTraceBridge;
        }

        var trace = window.__cttExecutionTrace || {};
        var traceByUri = trace.byUri && typeof trace.byUri === 'object' ? trace.byUri : {};
        if (!skipTraceBridge) {
          if (!payload.snapshot || typeof payload.snapshot !== 'object') {
            payload.snapshot = {};
          }
          if (!Array.isArray(payload.snapshot.events)) {
            payload.snapshot.events = [];
          }
          if (!Array.isArray(payload.snapshot.answers)) {
            payload.snapshot.answers = [];
          }

          Object.keys(traceByUri).forEach(function (taskUri) {
            var entry = traceByUri[taskUri] || {};
            var startTs = String(entry.startedAt || '').trim();
            var endTs = String(entry.endedAt || '').trim();
            if (startTs) {
              payload.snapshot.events.push({
                ts: startTs,
                nodeId: taskUri,
                type: 'ctt-panel-start',
                message: 'Task started in execution panel',
              });
            }
            if (endTs) {
              payload.snapshot.events.push({
                ts: endTs,
                nodeId: taskUri,
                type: 'ctt-panel-end',
                message: 'Task ended in execution panel',
              });
            }

            if (entry.response || entry.note) {
              payload.snapshot.answers.push({
                ts: endTs || startTs || new Date().toISOString(),
                nodeId: taskUri,
                nodeLabel: String(entry.label || ''),
                kind: 'ctt-panel',
                value: String(entry.response || ''),
                displayValue: String(entry.response || ''),
                note: String(entry.note || ''),
                prompt: 'Execution panel response',
              });
            }
          });
        }

        requestInit.body = JSON.stringify(payload);
        var responsePromise = originalFetch(resource, requestInit);
        if (simulationType !== 'cohort') {
          return responsePromise;
        }

        return responsePromise.then(function (response) {
          var cloned = null;
          try {
            cloned = response.clone();
          }
          catch (e) {
            return response;
          }

          cloned.json().then(function (resultPayload) {
            if (!resultPayload || resultPayload.ok !== true) {
              return;
            }

            var progress = readCohortProgress();
            var endedStudent = String(subjectCandidate || '').trim();
            if (endedStudent) {
              var completedSet = new Set(Array.isArray(progress.completed) ? progress.completed : []);
              completedSet.add(endedStudent);
              progress.completed = Array.from(completedSet);
              progress.lastEndedStudentId = endedStudent;
              writeCohortProgress(progress);
            }

            publishCohortTurnState(progress, getCurrentCohortStudent(progress));
          }).catch(function () {
          });

          return response;
        });
      }
      catch (e) {
        return originalFetch(resource, init);
      }
    };
  }

  function normalizeUriKey(value) {
    var text = String(value || '').trim();
    if (text === '') {
      return '';
    }
    text = text.replace('#/', '#');
    return text.replace(/\/+$/, '').toLowerCase();
  }

  function installInstrumentSelectionContextBridge(settings) {
    if (!settings || !settings.instrumentSelection) {
      return;
    }
    if (window.__cttInstrumentSelectionBridgeInstalled) {
      return;
    }

    var context = settings.instrumentSelection || {};
    var preferredInstrument = String(context.preferredInstrumentLabel || 'Instrument').trim() || 'Instrument';
    var preferredPlatform = String(context.preferredPlatformLabel || 'Platform').trim() || 'Platform';
    var organizationLabel = String(context.organizationLabel || '').trim();
    var organizationUri = String(context.organizationUri || '').trim();
    var organizationText = organizationLabel || organizationUri || 'Organization';
    var platformOptions = (context.platformOptions && typeof context.platformOptions === 'object')
      ? context.platformOptions
      : {};
    var platformInstrumentUris = (context.platformInstrumentUris && typeof context.platformInstrumentUris === 'object')
      ? context.platformInstrumentUris
      : {};

    var allowSet = new Set(
      Array.isArray(context.allowedInstrumentUris)
        ? context.allowedInstrumentUris.map(normalizeUriKey).filter(Boolean)
        : []
    );

    var platformSets = {};
    Object.keys(platformInstrumentUris).forEach(function (platformUri) {
      var platformKey = normalizeUriKey(platformUri);
      if (!platformKey) {
        return;
      }
      var values = Array.isArray(platformInstrumentUris[platformUri])
        ? platformInstrumentUris[platformUri]
        : [];
      platformSets[platformKey] = new Set(values.map(normalizeUriKey).filter(Boolean));
    });

    var activePlatformKey = 'all';
    var currentListItems = [];
    var listEndpointPath = normalizePathname(String(settings.apiBaseUrl || '') + '/instrument/list');
    var componentsEndpointPath = normalizePathname(String(settings.apiBaseUrl || '') + '/instrument/components');
    var prefilterEndpoint = String(context.prefilterEndpoint || '').trim();
    var prefilterEndpointPath = normalizePathname(prefilterEndpoint);
    var prefilteredInstruments = null;
    var prefilteredComponentsByInstrument = {};
    var prefilterRequestPromise = null;
    var modalRefreshScheduled = false;
    var modalRefreshInProgress = false;
    var instrumentRerenderPending = false;

    function scheduleModalRefresh(delayMs) {
      if (modalRefreshScheduled) {
        return;
      }

      modalRefreshScheduled = true;
      window.setTimeout(function () {
        modalRefreshScheduled = false;
        if (modalRefreshInProgress) {
          return;
        }

        modalRefreshInProgress = true;
        try {
          refreshModalEnhancements();
        }
        finally {
          modalRefreshInProgress = false;
        }
      }, typeof delayMs === 'number' ? delayMs : 0);
    }

    function filterInstrumentArray(items) {
      if (!Array.isArray(items)) {
        return [];
      }

      var selectedPlatformSet = null;
      if (activePlatformKey !== 'all' && platformSets[activePlatformKey]) {
        selectedPlatformSet = platformSets[activePlatformKey];
      }

      return items.filter(function (item) {
        if (!item || typeof item !== 'object') {
          return false;
        }
        var uri = '';
        if (typeof item.uri === 'string') {
          uri = item.uri;
        }
        else if (typeof item.hasURI === 'string') {
          uri = item.hasURI;
        }

        var key = normalizeUriKey(uri);
        if (!key) {
          return false;
        }

        if (allowSet.size > 0 && !allowSet.has(key)) {
          return false;
        }

        if (selectedPlatformSet && !selectedPlatformSet.has(key)) {
          return false;
        }

        return true;
      });
    }

    function extractPayloadItems(payload) {
      if (Array.isArray(payload)) {
        return payload;
      }
      if (!payload || typeof payload !== 'object') {
        return [];
      }
      if (Array.isArray(payload.body)) {
        return payload.body;
      }
      if (payload.body && typeof payload.body === 'object' && Array.isArray(payload.body.elements)) {
        return payload.body.elements;
      }
      if (Array.isArray(payload.elements)) {
        return payload.elements;
      }
      return [];
    }

    function filterInstrumentPayload(payload) {
      var filteredItems = filterInstrumentArray(extractPayloadItems(payload));
      currentListItems = filteredItems.slice();

      if (Array.isArray(payload)) {
        return filteredItems;
      }
      if (!payload || typeof payload !== 'object') {
        return payload;
      }

      var cloned = Object.assign({}, payload);
      if (Array.isArray(payload.body)) {
        cloned.body = filteredItems;
      }
      else if (payload.body && typeof payload.body === 'object' && Array.isArray(payload.body.elements)) {
        cloned.body = Object.assign({}, payload.body, {
          elements: filteredItems
        });
      }
      else if (Array.isArray(payload.elements)) {
        cloned.elements = filteredItems;
      }

      return cloned;
    }

    function normalizePrefilterInstrumentPayload(payload) {
      var root = payload && typeof payload === 'object' ? payload : {};
      var packed = root.payload && typeof root.payload === 'object' ? root.payload : root;
      var rows = Array.isArray(packed.instruments) ? packed.instruments : [];
      var normalized = [];
      var componentMap = {};

      rows.forEach(function (row) {
        if (!row || typeof row !== 'object') {
          return;
        }

        var uri = String(row.uri || row.hasURI || row.instrumentUri || '').trim();
        if (!uri) {
          return;
        }

        var key = normalizeUriKey(uri);
        if (!key) {
          return;
        }

        var label = String(row.label || row.name || uri).trim();
        var status = String(row.hasStatus || row.status || '').trim();

        var components = Array.isArray(row.components)
          ? row.components
              .map(function (component) {
                if (!component || typeof component !== 'object') {
                  return null;
                }

                var componentUri = String(component.uri || component.hasURI || component.componentUri || '').trim();
                if (!componentUri) {
                  return null;
                }

                return {
                  uri: componentUri,
                  hasURI: componentUri,
                  label: String(component.label || component.name || componentUri).trim(),
                  hasStatus: String(component.hasStatus || component.status || '').trim()
                };
              })
              .filter(Boolean)
          : [];

        componentMap[key] = components;

        normalized.push({
          uri: uri,
          hasURI: uri,
          label: label || uri,
          hasStatus: status
        });
      });

      prefilteredComponentsByInstrument = componentMap;
      return normalized;
    }

    function loadPrefilterInstruments(originalFetch) {
      if (!prefilterEndpointPath || typeof originalFetch !== 'function') {
        return Promise.resolve(null);
      }
      if (Array.isArray(prefilteredInstruments)) {
        return Promise.resolve(prefilteredInstruments);
      }
      if (prefilterRequestPromise) {
        return prefilterRequestPromise;
      }

      var endpointUrl = prefilterEndpoint;
      var separator = endpointUrl.indexOf('?') === -1 ? '?' : '&';
      var studyUri = String(settings.studyUri || '').trim();
      var processUri = String(settings.processUri || '').trim();
      if (studyUri) {
        endpointUrl += separator + 'studyUri=' + encodeURIComponent(studyUri);
        separator = '&';
      }
      if (processUri) {
        endpointUrl += separator + 'processUri=' + encodeURIComponent(processUri);
        separator = '&';
      }
      if (organizationUri) {
        endpointUrl += separator + 'organizationUri=' + encodeURIComponent(organizationUri);
        separator = '&';
      }
      if (Array.isArray(context.organizationScopeUris) && context.organizationScopeUris.length) {
        endpointUrl += separator + 'organizationScopeUris=' + encodeURIComponent(context.organizationScopeUris.join(','));
      }

      prefilterRequestPromise = originalFetch(endpointUrl, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json'
        }
      }).then(function (response) {
        if (!response || !response.ok) {
          return null;
        }
        return response.json().catch(function () {
          return null;
        });
      }).then(function (payload) {
        if (!payload || payload.ok !== true) {
          return null;
        }

        var normalized = normalizePrefilterInstrumentPayload(payload);
        prefilteredInstruments = normalized;
        return normalized;
      }).catch(function () {
        return null;
      }).finally(function () {
        prefilterRequestPromise = null;
      });

      return prefilterRequestPromise;
    }

    function renameModalHeadingAndSummary() {
      if (!document || !document.querySelectorAll) {
        return;
      }

      var headings = document.querySelectorAll('h2');
      for (var i = 0; i < headings.length; i++) {
        var heading = headings[i];
        if (!heading) {
          continue;
        }

        var headingText = String(heading.textContent || '').trim().toLowerCase();
        if (headingText !== 'select instrument' && heading.getAttribute('data-ctt-renamed-select-instrument') !== '1') {
          continue;
        }

        heading.textContent = 'Select ' + preferredInstrument + ' at ' + organizationText;
        heading.setAttribute('data-ctt-renamed-select-instrument', '1');

        var container = heading.parentElement;
        if (!container) {
          continue;
        }

        var existing = container.querySelector('[data-ctt-org-context="1"]');
        if (existing) {
          existing.textContent = 'Organization scope active. Showing ' + preferredInstrument + ' linked to ' + preferredPlatform + 's in the organization hierarchy.';
          continue;
        }

        var summary = document.createElement('div');
        summary.setAttribute('data-ctt-org-context', '1');
        summary.className = 'mt-1 text-xs text-gray-600';
        summary.textContent = 'Organization scope active. Showing ' + preferredInstrument + ' linked to ' + preferredPlatform + 's in the organization hierarchy.';
        container.appendChild(summary);
      }
    }

    function resolveModalLeftPanel() {
      var heading = document.querySelector('h2[data-ctt-renamed-select-instrument="1"]');
      if (!heading) {
        return null;
      }

      var modalRoot = heading.closest('[class*="fixed"]');
      if (!modalRoot || !modalRoot.querySelector) {
        return null;
      }

      return modalRoot.querySelector('[class*="w-1/3"]');
    }

    function ensureModalActionButtonsVisible() {
      var heading = document.querySelector('h2[data-ctt-renamed-select-instrument="1"]');
      if (!heading) {
        return;
      }

      var overlay = heading.closest('[class*="fixed"]');
      if (!overlay) {
        return;
      }

      // Keep the dialog fully usable on short viewports/zoomed layouts.
      overlay.style.alignItems = 'center';
      overlay.style.overflowY = 'auto';
      overlay.style.padding = '16px';

      var dialog = heading.closest('[class*="bg-white"], [class*="rounded"], [role="dialog"]');
      if (dialog && dialog.style) {
        dialog.style.maxHeight = 'calc(100vh - 32px)';
        dialog.style.overflow = 'auto';
      }

      var buttons = Array.prototype.slice.call((dialog || overlay).querySelectorAll('button'));
      var actionButtons = buttons.filter(function (button) {
        var text = String(button.textContent || '').trim().toLowerCase();
        return text === 'confirm' || text === 'cancel' || text === 'assign' || text === 'save';
      });

      if (!actionButtons.length) {
        return;
      }

      var footer = actionButtons[0].closest('div');
      if (!footer || !footer.style) {
        return;
      }

      // Pin action row while preserving existing layout.
      footer.style.position = 'sticky';
      footer.style.bottom = '0';
      footer.style.zIndex = '4';
      footer.style.background = '#fff';
      footer.style.paddingTop = '8px';
    }

    function resolveInstrumentCards() {
      var leftPanel = resolveModalLeftPanel();
      if (!leftPanel || !leftPanel.querySelectorAll) {
        return [];
      }

      return Array.prototype.slice.call(leftPanel.querySelectorAll('div.p-3.border.rounded.cursor-pointer'));
    }

    function resolveHierarchyRows() {
      var leftPanel = resolveModalLeftPanel();
      if (!leftPanel || !leftPanel.querySelectorAll) {
        return [];
      }

      return Array.prototype.slice.call(leftPanel.querySelectorAll('div.ml-8.flex.cursor-pointer'));
    }

    function buildLabelStatusToUriMaps() {
      var byLabelStatus = {};
      var byLabel = {};

      currentListItems.forEach(function (item) {
        if (!item || typeof item !== 'object') {
          return;
        }

        var uri = String(item.uri || item.hasURI || '').trim();
        if (!uri) {
          return;
        }

        var key = normalizeUriKey(uri);
        if (!key) {
          return;
        }

        var label = String(item.label || item['rdfs:label'] || '').trim();
        var status = String(item.hasStatus || '').trim();
        if (!label) {
          return;
        }

        var lsKey = (label + '||' + status).toLowerCase();
        if (!byLabelStatus[lsKey]) {
          byLabelStatus[lsKey] = [];
        }
        byLabelStatus[lsKey].push(key);

        var lKey = label.toLowerCase();
        if (!byLabel[lKey]) {
          byLabel[lKey] = [];
        }
        byLabel[lKey].push(key);
      });

      return {
        byLabelStatus: byLabelStatus,
        byLabel: byLabel
      };
    }

    function cardMatchesCurrentPlatform(card, maps) {
      if (activePlatformKey === 'all') {
        return true;
      }

      var targetSet = platformSets[activePlatformKey];
      if (!targetSet || targetSet.size === 0) {
        return false;
      }

      var labelNode = card.querySelector('.font-medium');
      var statusNode = card.querySelector('.text-sm.text-gray-500');
      var label = labelNode ? String(labelNode.textContent || '').trim() : '';
      var status = statusNode ? String(statusNode.textContent || '').trim() : '';

      if (!label) {
        return false;
      }

      var labelStatusKey = (label + '||' + status).toLowerCase();
      var labelKey = label.toLowerCase();
      var candidates = maps.byLabelStatus[labelStatusKey] || maps.byLabel[labelKey] || [];

      for (var i = 0; i < candidates.length; i++) {
        if (targetSet.has(candidates[i])) {
          return true;
        }
      }

      return false;
    }

    function hierarchyRowMatchesCurrentPlatform(row, maps) {
      if (activePlatformKey === 'all') {
        return true;
      }

      var targetSet = platformSets[activePlatformKey];
      if (!targetSet || targetSet.size === 0) {
        return false;
      }

      var labelNode = row.querySelector('span.truncate');
      var label = labelNode ? String(labelNode.textContent || '').trim() : '';
      if (!label) {
        return false;
      }

      var labelKey = label.toLowerCase();
      var candidates = maps.byLabel[labelKey] || [];
      for (var i = 0; i < candidates.length; i++) {
        if (targetSet.has(candidates[i])) {
          return true;
        }
      }

      return false;
    }

    function applyPlatformFilterToRenderedList() {
      var cards = resolveInstrumentCards();
      var hierarchyRows = resolveHierarchyRows();
      if (!cards.length && !hierarchyRows.length) {
        return;
      }

      var maps = buildLabelStatusToUriMaps();
      var visibleCount = 0;

      cards.forEach(function (card) {
        var keep = cardMatchesCurrentPlatform(card, maps);
        card.style.display = keep ? '' : 'none';
        if (keep) {
          visibleCount += 1;
        }
      });

      hierarchyRows.forEach(function (row) {
        var keep = hierarchyRowMatchesCurrentPlatform(row, maps);
        row.style.display = keep ? '' : 'none';
        if (keep) {
          visibleCount += 1;
        }
      });

      var leftPanel = resolveModalLeftPanel();
      if (!leftPanel) {
        return;
      }

      var emptyHint = leftPanel.querySelector('[data-ctt-platform-empty="1"]');
      if (!emptyHint) {
        emptyHint = document.createElement('div');
        emptyHint.setAttribute('data-ctt-platform-empty', '1');
        emptyHint.className = 'mt-2 text-xs text-gray-500';
        leftPanel.appendChild(emptyHint);
      }

      if (visibleCount === 0) {
        emptyHint.textContent = 'No ' + preferredInstrument.toLowerCase() + ' available for the selected ' + preferredPlatform.toLowerCase() + '.';
      }
      else {
        emptyHint.textContent = '';
      }
    }

    function triggerInstrumentListRerender() {
      if (instrumentRerenderPending) {
        return;
      }

      var leftPanel = resolveModalLeftPanel();
      if (!leftPanel) {
        return;
      }

      var searchInput = leftPanel.querySelector('input[type="text"]');
      if (!searchInput) {
        applyPlatformFilterToRenderedList();
        return;
      }

      instrumentRerenderPending = true;
      var original = String(searchInput.value || '');
      searchInput.value = original + ' ';
      searchInput.dispatchEvent(new Event('input', { bubbles: true }));

      window.setTimeout(function () {
        searchInput.value = original;
        searchInput.dispatchEvent(new Event('input', { bubbles: true }));
        window.setTimeout(function () {
          applyPlatformFilterToRenderedList();
          instrumentRerenderPending = false;
        }, 30);
      }, 0);
    }

    function ensurePlatformFilterControl() {
      var leftPanel = resolveModalLeftPanel();
      if (!leftPanel || !leftPanel.querySelector) {
        return;
      }

      var searchWrapper = leftPanel.querySelector('.mb-4');
      if (!searchWrapper) {
        return;
      }

      var wrapper = leftPanel.querySelector('[data-ctt-platform-filter="1"]');
      if (!wrapper) {
        wrapper = document.createElement('div');
        wrapper.setAttribute('data-ctt-platform-filter', '1');
        wrapper.className = 'mb-3';
        searchWrapper.insertAdjacentElement('afterend', wrapper);
      }

      var platformEntries = Object.keys(platformOptions).map(function (platformUri) {
        return {
          uri: platformUri,
          label: String(platformOptions[platformUri] || platformUri || '').trim()
        };
      }).sort(function (a, b) {
        return a.label.localeCompare(b.label);
      });

      var html = ''
        + '<label class="mb-1 block text-xs font-semibold text-gray-700">Filter by ' + escapeHtml(preferredPlatform) + '</label>'
        + '<select class="w-full rounded-md border border-gray-300 px-2 py-1.5 text-xs" data-ctt-platform-filter-select="1">'
        + '<option value="all">All ' + escapeHtml(preferredPlatform) + 's</option>';

      platformEntries.forEach(function (entry) {
        var value = normalizeUriKey(entry.uri);
        var selected = (activePlatformKey === value) ? ' selected="selected"' : '';
        html += '<option value="' + escapeHtml(value) + '"' + selected + '>' + escapeHtml(entry.label) + '</option>';
      });

      html += '</select>';
      if (wrapper.getAttribute('data-ctt-platform-filter-html') !== html) {
        wrapper.innerHTML = html;
        wrapper.setAttribute('data-ctt-platform-filter-html', html);
      }

      var select = wrapper.querySelector('[data-ctt-platform-filter-select="1"]');
      if (!select || select.getAttribute('data-ctt-platform-filter-bound') === '1') {
        return;
      }

      select.setAttribute('data-ctt-platform-filter-bound', '1');
      select.addEventListener('change', function () {
        var selected = normalizeUriKey(select.value);
        activePlatformKey = selected || 'all';
        triggerInstrumentListRerender();
      });
    }

    function refreshModalEnhancements() {
      renameModalHeadingAndSummary();
      ensurePlatformFilterControl();
      applyPlatformFilterToRenderedList();
      ensureModalActionButtonsVisible();
    }

    if (typeof MutationObserver === 'function' && document && document.body) {
      var observer = new MutationObserver(function () {
        scheduleModalRefresh(30);
      });
      observer.observe(document.body, { childList: true, subtree: true });
    }
    scheduleModalRefresh(0);

    if (typeof window.fetch !== 'function' || !listEndpointPath) {
      window.__cttInstrumentSelectionBridgeInstalled = true;
      return;
    }

    var originalFetch = window.fetch.bind(window);

    loadPrefilterInstruments(originalFetch).then(function () {
      scheduleModalRefresh(0);
    });

    window.fetch = function (resource, init) {
      var requestUrl = getRequestUrl(resource);
      var requestPath = normalizePathname(requestUrl);

      if (requestPath && requestPath === componentsEndpointPath && prefilterEndpointPath) {
        var parsedComponentsUrl = parseUrl(requestUrl);
        var componentsInstrumentUri = parsedComponentsUrl ? String(parsedComponentsUrl.searchParams.get('uri') || '').trim() : '';
        var componentsKey = normalizeUriKey(componentsInstrumentUri);
        if (componentsKey && Array.isArray(prefilteredComponentsByInstrument[componentsKey])) {
          return Promise.resolve(new Response(JSON.stringify(prefilteredComponentsByInstrument[componentsKey]), {
            status: 200,
            statusText: 'OK',
            headers: {
              'content-type': 'application/json'
            }
          }));
        }

        return Promise.resolve(new Response(JSON.stringify([]), {
          status: 200,
          statusText: 'OK',
          headers: {
            'content-type': 'application/json'
          }
        }));
      }

      if (requestPath && requestPath === listEndpointPath && prefilterEndpointPath) {
        return loadPrefilterInstruments(originalFetch).then(function (prefilteredRows) {
          if (!Array.isArray(prefilteredRows)) {
            prefilteredRows = [];
          }

          var filteredPrefilterPayload = filterInstrumentPayload(prefilteredRows);
          window.setTimeout(scheduleModalRefresh, 20);

          return new Response(JSON.stringify(filteredPrefilterPayload), {
            status: 200,
            statusText: 'OK',
            headers: {
              'content-type': 'application/json'
            }
          });
        });
      }

      var responsePromise = originalFetch(resource, init);
      if (!requestPath || requestPath !== listEndpointPath) {
        return responsePromise;
      }

      return responsePromise.then(function (response) {
        var cloned = null;
        try {
          cloned = response.clone();
        }
        catch (e) {
          return response;
        }

        return cloned.json().then(function (payload) {
          var filteredPayload = filterInstrumentPayload(payload);
          var bodyText = JSON.stringify(filteredPayload);
          var headers = new Headers(response.headers || {});
          if (!headers.has('content-type')) {
            headers.set('content-type', 'application/json');
          }

          scheduleModalRefresh(20);

          return new Response(bodyText, {
            status: response.status,
            statusText: response.statusText,
            headers: headers
          });
        }).catch(function () {
          return response;
        });
      });
    };

    window.__cttInstrumentSelectionBridgeInstalled = true;
  }

  function installEditModeSimulatorAssignmentBridge(container, settings) {
    if (!container || container.__cttEditModeSimulatorBridgeInstalled) {
      return;
    }
    if (isExecutionView(settings) || toBooleanFlag(settings.readOnlyPreview)) {
      return;
    }

    container.__cttEditModeSimulatorBridgeInstalled = true;

    var apiBaseUrl = String(settings && settings.apiBaseUrl || '').trim().replace(/\/+$/, '');
    var processUri = String(settings && settings.processUri || '').trim();
    var studyUri = String(settings && settings.studyUri || '').trim();
    var assignmentByTask = {};
    var authoritativeMetadata = null;
    var metadataLoading = false;
    var activeModal = null;

    if (!apiBaseUrl || !processUri || typeof window.fetch !== 'function') {
      return;
    }

    function ensureStyles() {
      if (document.getElementById('ctt-assign-simulator-style')) {
        return;
      }

      var style = document.createElement('style');
      style.id = 'ctt-assign-simulator-style';
      style.textContent = ''
        + '.ctt-assign-simulator-btn{margin-left:8px;}'
        + '.ctt-sim-chip{display:block;margin-top:4px;padding:2px 6px;border-radius:10px;background:#e8f5e9;color:#1b5e20;font-size:10px;line-height:1.2;max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}'
        + '.ctt-sim-modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:10050;display:flex;align-items:center;justify-content:center;padding:16px;}'
        + '.ctt-sim-modal{width:min(720px,95vw);background:#fff;border-radius:10px;box-shadow:0 16px 40px rgba(0,0,0,.25);padding:16px;}'
        + '.ctt-sim-modal h3{margin:0 0 10px 0;font-size:18px;}'
        + '.ctt-sim-modal .ctt-sim-grid{display:grid;grid-template-columns:1fr;gap:12px;}'
        + '.ctt-sim-modal label{display:block;font-size:12px;font-weight:600;margin-bottom:4px;}'
        + '.ctt-sim-modal select{width:100%;padding:8px;border:1px solid #cfd8dc;border-radius:6px;background:#fff;}'
        + '.ctt-sim-modal .ctt-sim-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:14px;}'
        + '.ctt-sim-modal .ctt-sim-status{font-size:12px;color:#455a64;min-height:18px;}'
        + '.ctt-sim-modal .ctt-sim-summary{margin-top:8px;padding:8px;border-radius:6px;background:#f5f7f8;font-size:12px;color:#37474f;}';
      document.head.appendChild(style);
    }

    function isVisibleElement(element) {
      if (!element) {
        return false;
      }
      return !!(element.offsetParent || element.getClientRects().length);
    }

    function getMergedMetadata() {
      var visual = parseTaskNodeMetadata(container);
      return mergeTaskMetadata(visual, authoritativeMetadata || { byUri: {}, byLabel: {}, childrenByParent: {}, parentByChild: {} });
    }

    function loadAuthoritativeMetadata() {
      if (metadataLoading || authoritativeMetadata) {
        return;
      }

      metadataLoading = true;
      var endpoint = apiBaseUrl + '/process/tree?uri=' + encodeURIComponent(processUri);
      window.fetch(endpoint, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      }).then(function (response) {
        if (!response || !response.ok) {
          throw new Error('Failed to load process tree');
        }
        return response.json();
      }).then(function (payload) {
        authoritativeMetadata = parseAuthoritativeTaskMetadata(payload);
        refreshActionBarButton();
      }).catch(function () {
        authoritativeMetadata = null;
      }).finally(function () {
        metadataLoading = false;
      });
    }

    function loadAssignments() {
      var endpoint = apiBaseUrl + '/task/simulator-assignment?processUri=' + encodeURIComponent(processUri);
      return window.fetch(endpoint, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      }).then(function (response) {
        if (!response || !response.ok) {
          return null;
        }
        return response.json().catch(function () { return null; });
      }).then(function (payload) {
        assignmentByTask = {};
        if (!payload || payload.ok !== true || !Array.isArray(payload.assignments)) {
          return;
        }

        payload.assignments.forEach(function (row) {
          if (!row || typeof row !== 'object') {
            return;
          }
          var taskUri = normalizeTaskUri(row.taskUri || '');
          if (!taskUri) {
            return;
          }
          assignmentByTask[taskUri] = row;
        });
      }).finally(function () {
        renderTaskAssignmentBadges();
        refreshActionBarButton();
      });
    }

    function findSelectedTaskNode() {
      return container.querySelector(
        '.react-flow__node.selected[data-id], '
        + '.react-flow__node[aria-selected="true"][data-id], '
        + '[class*="react-flow__node"].selected[data-id], '
        + '[class*="react-flow__node"][aria-selected="true"][data-id], '
        + '[data-ctt-current-task-node="1"][data-id]'
      );
    }

    function getSelectedTaskUriAndType() {
      var merged = getMergedMetadata();
      var selectedUri = getSelectedCanvasTaskUri(container, merged);
      var taskType = '';

      if (selectedUri && merged.byUri && merged.byUri[selectedUri]) {
        taskType = String(merged.byUri[selectedUri].taskType || '').trim().toLowerCase();
      }

      if (!selectedUri) {
        var node = findSelectedTaskNode();
        if (node) {
          selectedUri = normalizeTaskUri(node.getAttribute('data-id') || '');
          if (!taskType) {
            var inferred = inferTaskMetaFromLabel(node.textContent || '');
            taskType = String(inferred.taskType || '').trim().toLowerCase();
          }
        }
      }

      return {
        taskUri: selectedUri,
        taskType: taskType,
      };
    }

    function isEligibleTaskType(taskType) {
      var normalized = String(taskType || '').trim().toLowerCase();
      return normalized === 'automated' || normalized === 'interactive';
    }

    function findActionBarHost() {
      var controls = container.querySelectorAll('button, [role="button"], a[role="button"], a');
      for (var i = 0; i < controls.length; i += 1) {
        var control = controls[i];
        if (!isVisibleElement(control)) {
          continue;
        }
        var label = normalizeControlText(control);
        if (!/(assign instrument|assign component instance|edit instrument|edit component instance|new task|new subtask|delete|remove|change parent|set root|auto order tasks)/.test(label)) {
          continue;
        }

        var host = control.closest('div');
        var depth = 0;
        while (host && depth < 5) {
          var buttonCount = host.querySelectorAll ? host.querySelectorAll('button, [role="button"], a[role="button"], a').length : 0;
          if (buttonCount >= 2) {
            return host;
          }
          host = host.parentElement;
          depth += 1;
        }
      }
      return null;
    }

    function buildAssignmentSummary(row) {
      if (!row || typeof row !== 'object') {
        return '';
      }
      var platform = String(row.platformInstanceLabel || row.platformInstanceUri || '').trim();
      var instrument = String(row.instrumentInstanceLabel || row.instrumentInstanceUri || '').trim();
      var component = String(row.componentInstanceLabel || row.componentInstanceUri || '').trim();
      if (!platform && !instrument && !component) {
        return '';
      }
      return [platform, instrument, component].filter(Boolean).join(' | ');
    }

    function renderTaskAssignmentBadges() {
      var nodes = container.querySelectorAll('.react-flow__node[data-id], [class*="react-flow__node"][data-id]');
      nodes.forEach(function (node) {
        var taskUri = normalizeTaskUri(node.getAttribute('data-id') || '');
        var existing = node.querySelector('[data-ctt-sim-chip="1"]');
        if (existing) {
          existing.remove();
        }

        if (!taskUri || !assignmentByTask[taskUri]) {
          return;
        }

        var summary = buildAssignmentSummary(assignmentByTask[taskUri]);
        if (!summary) {
          return;
        }

        if (node.style.position === '') {
          node.style.position = 'relative';
        }

        var chip = document.createElement('div');
        chip.className = 'ctt-sim-chip';
        chip.setAttribute('data-ctt-sim-chip', '1');
        chip.setAttribute('title', summary);
        chip.textContent = 'Simulator: ' + summary;
        node.appendChild(chip);
      });
    }

    function closeAssignModal() {
      if (activeModal && activeModal.parentNode) {
        activeModal.parentNode.removeChild(activeModal);
      }
      activeModal = null;
    }

    function openAssignModal(taskUri) {
      closeAssignModal();
      ensureStyles();

      var endpoint = apiBaseUrl + '/simulator-assignment/options'
        + '?studyUri=' + encodeURIComponent(studyUri)
        + '&processUri=' + encodeURIComponent(processUri)
        + '&taskUri=' + encodeURIComponent(taskUri);

      var backdrop = document.createElement('div');
      backdrop.className = 'ctt-sim-modal-backdrop';
      backdrop.innerHTML = ''
        + '<div class="ctt-sim-modal" role="dialog" aria-modal="true" aria-label="Assign Component Instance">'
        + '  <h3>Assign Component Instance</h3>'
        + '  <div class="ctt-sim-grid">'
        + '    <div><label>Platform Instance</label><select data-ctt-sim-platform><option value="">Loading...</option></select></div>'
        + '    <div><label>Instrument Instance</label><select data-ctt-sim-instrument disabled="disabled"><option value="">Select a platform instance first</option></select></div>'
        + '    <div><label>Component Instance</label><select data-ctt-sim-component disabled="disabled"><option value="">Select an instrument instance first</option></select></div>'
        + '  </div>'
        + '  <div class="ctt-sim-summary" data-ctt-sim-summary>Choose a platform, instrument, and component instance.</div>'
        + '  <div class="ctt-sim-status" data-ctt-sim-status></div>'
        + '  <div class="ctt-sim-actions">'
        + '    <button type="button" class="btn btn-sm btn-secondary" data-ctt-sim-cancel>Cancel</button>'
        + '    <button type="button" class="btn btn-sm btn-primary" data-ctt-sim-confirm disabled="disabled">Confirm</button>'
        + '  </div>'
        + '</div>';

      document.body.appendChild(backdrop);
      activeModal = backdrop;

      var platformSelect = backdrop.querySelector('[data-ctt-sim-platform]');
      var instrumentSelect = backdrop.querySelector('[data-ctt-sim-instrument]');
      var componentSelect = backdrop.querySelector('[data-ctt-sim-component]');
      var statusNode = backdrop.querySelector('[data-ctt-sim-status]');
      var summaryNode = backdrop.querySelector('[data-ctt-sim-summary]');
      var cancelBtn = backdrop.querySelector('[data-ctt-sim-cancel]');
      var confirmBtn = backdrop.querySelector('[data-ctt-sim-confirm]');

      var optionsPayload = null;

      function setStatus(message) {
        if (statusNode) {
          statusNode.textContent = String(message || '');
        }
      }

      function setSummary(message) {
        if (summaryNode) {
          summaryNode.textContent = String(message || '');
        }
      }

      function optionHtml(value, label, selected) {
        return '<option value="' + escapeHtml(value) + '"' + (selected ? ' selected="selected"' : '') + '>' + escapeHtml(label) + '</option>';
      }

      function getDefaultSelection() {
        var fromOptions = optionsPayload && optionsPayload.assignment && typeof optionsPayload.assignment === 'object'
          ? optionsPayload.assignment
          : null;
        var fromCache = assignmentByTask[taskUri] || null;
        return fromOptions || fromCache || null;
      }

      function filterInstrumentOptions(platformUri) {
        if (!optionsPayload || !Array.isArray(optionsPayload.instrumentInstances)) {
          return [];
        }
        return optionsPayload.instrumentInstances.filter(function (row) {
          return String(row.platformInstanceUri || '') === String(platformUri || '');
        });
      }

      function filterComponentOptions(instrumentInstanceUri) {
        if (!optionsPayload || !Array.isArray(optionsPayload.componentInstances)) {
          return [];
        }
        return optionsPayload.componentInstances.filter(function (row) {
          return String(row.instrumentInstanceUri || '') === String(instrumentInstanceUri || '');
        });
      }

      function refreshConfirmState() {
        var ready = !!(platformSelect && platformSelect.value && instrumentSelect && instrumentSelect.value && componentSelect && componentSelect.value);
        if (confirmBtn) {
          confirmBtn.disabled = !ready;
        }

        if (!ready) {
          setSummary('Choose a platform, instrument, and component instance.');
          return;
        }

        var platformLabel = platformSelect.options[platformSelect.selectedIndex] ? platformSelect.options[platformSelect.selectedIndex].text : '';
        var instrumentLabel = instrumentSelect.options[instrumentSelect.selectedIndex] ? instrumentSelect.options[instrumentSelect.selectedIndex].text : '';
        var componentLabel = componentSelect.options[componentSelect.selectedIndex] ? componentSelect.options[componentSelect.selectedIndex].text : '';
        setSummary('Selected mapping: ' + platformLabel + ' | ' + instrumentLabel + ' | ' + componentLabel);
      }

      function fillComponentSelect(defaultComponentUri) {
        if (!instrumentSelect || !componentSelect) {
          return;
        }
        var rows = filterComponentOptions(instrumentSelect.value);
        componentSelect.disabled = rows.length === 0;
        var html = optionHtml('', rows.length ? 'Select component instance' : 'No component instances for selected instrument', !defaultComponentUri);
        rows.forEach(function (row) {
          var uri = String(row.uri || '');
          var label = String(row.label || uri);
          html += optionHtml(uri, label, defaultComponentUri && uri === defaultComponentUri);
        });
        componentSelect.innerHTML = html;
        refreshConfirmState();
      }

      function fillInstrumentSelect(defaultInstrumentUri, defaultComponentUri) {
        if (!platformSelect || !instrumentSelect) {
          return;
        }
        var rows = filterInstrumentOptions(platformSelect.value);
        instrumentSelect.disabled = rows.length === 0;
        var html = optionHtml('', rows.length ? 'Select instrument instance' : 'No instrument instances for selected platform', !defaultInstrumentUri);
        rows.forEach(function (row) {
          var uri = String(row.uri || '');
          var label = String(row.label || uri);
          html += optionHtml(uri, label, defaultInstrumentUri && uri === defaultInstrumentUri);
        });
        instrumentSelect.innerHTML = html;
        fillComponentSelect(defaultComponentUri);
      }

      function fillPlatformSelect(defaultPlatformUri, defaultInstrumentUri, defaultComponentUri) {
        var rows = optionsPayload && Array.isArray(optionsPayload.platformInstances) ? optionsPayload.platformInstances : [];
        platformSelect.disabled = rows.length === 0;
        var html = optionHtml('', rows.length ? 'Select platform instance' : 'No platform instances available', !defaultPlatformUri);
        rows.forEach(function (row) {
          var uri = String(row.uri || '');
          var label = String(row.label || uri);
          html += optionHtml(uri, label, defaultPlatformUri && uri === defaultPlatformUri);
        });
        platformSelect.innerHTML = html;
        fillInstrumentSelect(defaultInstrumentUri, defaultComponentUri);
      }

      platformSelect.addEventListener('change', function () {
        fillInstrumentSelect('', '');
      });

      instrumentSelect.addEventListener('change', function () {
        fillComponentSelect('');
      });

      componentSelect.addEventListener('change', function () {
        refreshConfirmState();
      });

      cancelBtn.addEventListener('click', function () {
        closeAssignModal();
      });

      backdrop.addEventListener('click', function (event) {
        if (event.target === backdrop) {
          closeAssignModal();
        }
      });

      confirmBtn.addEventListener('click', function () {
        if (confirmBtn.disabled) {
          return;
        }

        confirmBtn.disabled = true;
        setStatus('Saving component instance assignment...');

        var payload = {
          platformInstanceUri: String(platformSelect.value || '').trim(),
          instrumentInstanceUri: String(instrumentSelect.value || '').trim(),
          componentInstanceUri: String(componentSelect.value || '').trim(),
        };

        window.fetch(apiBaseUrl + '/task/simulator-assignment?uri=' + encodeURIComponent(taskUri), {
          method: 'PUT',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify(payload)
        }).then(function (response) {
          return response.json().catch(function () {
            return { ok: false, error: 'Unexpected server response' };
          });
        }).then(function (result) {
          if (!result || result.ok !== true || !result.assignment) {
            throw new Error(String(result && result.error || 'Unable to save mapping'));
          }

          assignmentByTask[taskUri] = result.assignment;
          renderTaskAssignmentBadges();
          refreshActionBarButton();
          closeAssignModal();
        }).catch(function (error) {
          setStatus(String(error && error.message || error || 'Unable to save mapping'));
          refreshConfirmState();
        });
      });

      setStatus('Loading component instance options...');
      window.fetch(endpoint, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      }).then(function (response) {
        if (!response || !response.ok) {
          throw new Error('Failed to load options');
        }
        return response.json();
      }).then(function (responsePayload) {
        if (!responsePayload || responsePayload.ok !== true || !responsePayload.payload) {
          throw new Error(String(responsePayload && responsePayload.error || 'Invalid options payload'));
        }
        optionsPayload = responsePayload.payload;

        var defaults = getDefaultSelection();
        var defaultPlatformUri = defaults ? String(defaults.platformInstanceUri || '') : '';
        var defaultInstrumentUri = defaults ? String(defaults.instrumentInstanceUri || '') : '';
        var defaultComponentUri = defaults ? String(defaults.componentInstanceUri || '') : '';
        fillPlatformSelect(defaultPlatformUri, defaultInstrumentUri, defaultComponentUri);
        setStatus('');
      }).catch(function (error) {
        setStatus(String(error && error.message || error || 'Unable to load options'));
      });
    }

    function refreshActionBarButton() {
      var host = findActionBarHost();
      if (!host) {
        return;
      }

      var button = host.querySelector('[data-ctt-assign-simulator="1"]');
      if (!button) {
        button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-sm btn-outline-primary ctt-assign-simulator-btn';
        button.setAttribute('data-ctt-assign-simulator', '1');
        button.textContent = 'Assign Component Instance';
        host.appendChild(button);

        button.addEventListener('click', function () {
          var info = getSelectedTaskUriAndType();
          if (!info.taskUri || !isEligibleTaskType(info.taskType)) {
            return;
          }
          openAssignModal(info.taskUri);
        });
      }

      var info = getSelectedTaskUriAndType();
      var eligible = !!(info.taskUri && isEligibleTaskType(info.taskType));

      button.style.display = eligible ? '' : 'none';
      button.disabled = !eligible;
      button.setAttribute('data-ctt-task-uri', info.taskUri || '');

      if (eligible && assignmentByTask[info.taskUri]) {
        button.setAttribute('title', buildAssignmentSummary(assignmentByTask[info.taskUri]));
      }
      else {
        button.removeAttribute('title');
      }
    }

    function relabelLegacyAssignInstrumentControls() {
      var controls = container.querySelectorAll('button, [role="button"], a[role="button"], a');
      controls.forEach(function (control) {
        var label = normalizeControlText(control);
        if (label === 'assign instrument') {
          control.style.display = 'none';
          control.setAttribute('data-ctt-legacy-assign-hidden', '1');
        }
        else if (label === 'edit instrument') {
          control.style.display = 'none';
          control.setAttribute('data-ctt-legacy-assign-hidden', '1');
        }
      });
    }

    var observer = new MutationObserver(function () {
      relabelLegacyAssignInstrumentControls();
      refreshActionBarButton();
      renderTaskAssignmentBadges();
    });
    observer.observe(container, {
      childList: true,
      subtree: true,
      attributes: true,
      attributeFilter: ['class', 'aria-selected', 'data-id'],
    });

    ensureStyles();
    loadAuthoritativeMetadata();
    loadAssignments();
    relabelLegacyAssignInstrumentControls();
    refreshActionBarButton();
    renderTaskAssignmentBadges();
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
      + '<div class="ctt-special-execution-panel__status" data-ctt-special-status="1">Preparing execution...</div>'
      + '<div class="ctt-special-execution-panel__turn" data-ctt-special-turn="1"></div>';
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

  function setSpecialExecutionTurn(panel, message, state) {
    if (!panel) {
      return;
    }
    var node = panel.querySelector('[data-ctt-special-turn="1"]');
    if (!node) {
      return;
    }

    node.textContent = String(message || '').trim();
    node.setAttribute('data-ctt-turn-state', String(state || 'neutral').trim());
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
      + '<div class="ctt-special-task-nav__hint">Execution controls adapt to each task type.</div>'
      + '<div class="ctt-special-task-nav__current" data-ctt-task-current="1">Waiting for task list...</div>'
      + '<div class="ctt-special-task-nav__action-panel" data-ctt-action-panel="1">'
      + '  <div class="ctt-special-task-nav__action-title">Action Panel</div>'
      + '  <div class="ctt-special-task-nav__action-hint" data-ctt-action-hint="1">Your turn: choose one action below.</div>'
      + '  <div class="ctt-special-task-nav__controls" data-ctt-task-controls="1"></div>'
      + '  <div class="ctt-special-task-nav__actions" data-ctt-task-actions="1">'
      + '    <button type="button" class="btn btn-sm btn-outline-primary" data-ctt-task-prev="1">Previous</button>'
      + '    <button type="button" class="btn btn-sm btn-primary" data-ctt-task-next="1">Continue</button>'
      + '  </div>'
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

  function normalizeTaskLabel(value) {
    return String(value || '')
      .replace(/https?:\/\/\S+/gi, '')
      .replace(/⚠️\s*\d+\s*error/gi, '')
      .replace(/\s+/g, ' ')
      .trim()
      .toLowerCase();
  }

  function parseTaskNodeMetadata(container) {
    var metadata = {
      byUri: {},
      byLabel: {},
      childrenByParent: {},
      parentByChild: {},
    };

    if (!container || !container.querySelectorAll) {
      return metadata;
    }

    var allNodes = container.querySelectorAll('.react-flow__node[data-id], [class*="react-flow__node"][data-id]');
    allNodes.forEach(function (node) {
      var uri = String(node.getAttribute('data-id') || '').trim();
      if (!uri) {
        return;
      }

      var text = String(node.textContent || '').replace(/\s+/g, ' ').trim();
      var raw = text.replace(/⚠️\s*\d+\s*error/gi, '').trim();
      var taskType = 'manual';
      if (/^abs/.test(raw)) {
        taskType = 'abstract';
      }
      else if (/^🖥/.test(raw)) {
        taskType = 'interactive';
      }
      else if (/^🤖|^⚙|^🛠|^🔧/.test(raw)) {
        taskType = 'automated';
      }
      else if (/^👤/.test(raw)) {
        taskType = 'manual';
      }

      var operator = 'sequential';
      if (raw.indexOf('|||') !== -1 || raw.indexOf('|[.]|') !== -1) {
        operator = 'parallel';
      }
      else if (raw.indexOf('|=|') !== -1) {
        operator = 'independent';
      }
      else if (raw.indexOf('[]') !== -1) {
        operator = 'choice';
      }

      var label = raw
        .replace(/^abs/, '')
        .replace(/^(👤|🖥⌨|🖥|🤖|⚙️|⚙|🛠️|🛠|🔧)\s*/, '')
        .replace(/(>>>|>>|\[\]|\|\|\||\|\[\.\]\||\|=\|)\s*/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
      var normalizedLabel = normalizeTaskLabel(label);

      var entry = {
        uri: uri,
        label: label,
        taskType: taskType,
        operator: operator,
      };
      metadata.byUri[uri] = entry;
      if (normalizedLabel && !metadata.byLabel[normalizedLabel]) {
        metadata.byLabel[normalizedLabel] = entry;
      }
    });

    var edges = container.querySelectorAll('g[aria-label*="Edge from "]');
    edges.forEach(function (edge) {
      var label = String(edge.getAttribute('aria-label') || '').trim();
      var match = label.match(/Edge from\s+(https?:\/\/\S+)\s+to\s+(https?:\/\/\S+)/i);
      if (!match || !match[1] || !match[2]) {
        return;
      }
      var parentUri = String(match[1]).trim();
      var childUri = String(match[2]).trim();
      if (!metadata.childrenByParent[parentUri]) {
        metadata.childrenByParent[parentUri] = [];
      }
      if (metadata.childrenByParent[parentUri].indexOf(childUri) === -1) {
        metadata.childrenByParent[parentUri].push(childUri);
      }
      if (!metadata.parentByChild[childUri]) {
        metadata.parentByChild[childUri] = parentUri;
      }
    });

    return metadata;
  }

  function parseOperatorFromDependency(value) {
    var text = String(value || '').trim().toLowerCase();
    if (!text) {
      return 'sequential';
    }
    var token = text.split(/\s+/, 1)[0] || '';
    if (token === 'choice') {
      return 'choice';
    }
    if (token === 'parallel' || token === 'concurrent' || token === 'concurrency') {
      return 'parallel';
    }
    if (token === 'independent' || token === 'orderindependent' || token === 'orderindependency') {
      return 'independent';
    }
    return 'sequential';
  }

  function parseTaskTypeFromRecord(record) {
    if (!record || typeof record !== 'object') {
      return '';
    }
    var candidates = [
      record.taskType,
      record.hasTaskType,
      record['vstoi:hasTaskType'],
      record.type,
      record.kind,
    ];
    for (var i = 0; i < candidates.length; i += 1) {
      var value = String(candidates[i] || '').trim().toLowerCase();
      if (!value) {
        continue;
      }
      if (value.indexOf('abstract') !== -1 || value === 'abs') {
        return 'abstract';
      }
      if (value.indexOf('interactive') !== -1) {
        return 'interactive';
      }
      if (value.indexOf('automatic') !== -1 || value.indexOf('automated') !== -1 || value.indexOf('system') !== -1) {
        return 'automated';
      }
      if (value.indexOf('manual') !== -1 || value.indexOf('user') !== -1) {
        return 'manual';
      }
    }
    return '';
  }

  function normalizeTaskUri(value) {
    var uri = String(value || '').trim();
    return /^https?:\/\//i.test(uri) ? uri : '';
  }

  function toArray(value) {
    if (Array.isArray(value)) {
      return value;
    }
    if (typeof value === 'string') {
      return value.split(/[;,]/);
    }
    return [];
  }

  function parseAuthoritativeTaskMetadata(payload) {
    var metadata = {
      byUri: {},
      byLabel: {},
      childrenByParent: {},
      parentByChild: {},
    };

    if (!payload || typeof payload !== 'object') {
      return metadata;
    }

    var records = [];
    if (Array.isArray(payload)) {
      records = payload;
    }
    else if (payload.body && Array.isArray(payload.body)) {
      records = payload.body;
    }
    else if (payload.body && Array.isArray(payload.body.elements)) {
      records = payload.body.elements;
    }
    else if (Array.isArray(payload.elements)) {
      records = payload.elements;
    }
    else if (Array.isArray(payload.tasks)) {
      records = payload.tasks;
    }
    else if (payload.data && Array.isArray(payload.data.tasks)) {
      records = payload.data.tasks;
    }

    records.forEach(function (record) {
      if (!record || typeof record !== 'object') {
        return;
      }

      var uri = normalizeTaskUri(record.uri || record.hasURI || record.id || record.taskUri);
      if (!uri || uri.indexOf('/TSK/') === -1) {
        return;
      }

      var label = String(record.label || record.name || record.hasLabel || '').trim();
      var normalizedLabel = normalizeTaskLabel(label);
      var dependency = String(record.hasTemporalDependency || record['vstoi:hasTemporalDependency'] || record.temporalDependency || '').trim();
      var operator = parseOperatorFromDependency(dependency);
      var taskType = parseTaskTypeFromRecord(record);
      var componentUri = normalizeTaskUri(record.componentUri || record.hasComponentUri || record['vstoi:hasComponent'] || record.component);

      metadata.byUri[uri] = {
        uri: uri,
        label: label,
        operator: operator,
        taskType: taskType || 'manual',
        componentUri: componentUri,
        componentLabel: String(record.componentLabel || record.hasComponentLabel || record['vstoi:hasComponentLabel'] || '').trim(),
        componentSpec: record.componentSpec && typeof record.componentSpec === 'object' ? record.componentSpec : null,
      };
      if (normalizedLabel && !metadata.byLabel[normalizedLabel]) {
        metadata.byLabel[normalizedLabel] = metadata.byUri[uri];
      }
    });

    records.forEach(function (record) {
      if (!record || typeof record !== 'object') {
        return;
      }
      var parentUri = normalizeTaskUri(record.uri || record.hasURI || record.id || record.taskUri);
      if (!parentUri || !metadata.byUri[parentUri]) {
        return;
      }
      var children = toArray(record.hasSubtaskUri || record.hasSubtask || record['vstoi:hasSubtask'] || record.subtasks);
      children.forEach(function (childValue) {
        var childUri = normalizeTaskUri(childValue);
        if (!childUri || !metadata.byUri[childUri]) {
          return;
        }
        if (!metadata.childrenByParent[parentUri]) {
          metadata.childrenByParent[parentUri] = [];
        }
        if (metadata.childrenByParent[parentUri].indexOf(childUri) === -1) {
          metadata.childrenByParent[parentUri].push(childUri);
        }
        if (!metadata.parentByChild[childUri]) {
          metadata.parentByChild[childUri] = parentUri;
        }
      });

      var supertask = normalizeTaskUri(record.hasSupertaskUri || record.hasSupertask || record['vstoi:isSubtaskOf'] || record.parentUri || record.parent);
      if (supertask && metadata.byUri[supertask] && !metadata.parentByChild[parentUri]) {
        metadata.parentByChild[parentUri] = supertask;
        if (!metadata.childrenByParent[supertask]) {
          metadata.childrenByParent[supertask] = [];
        }
        if (metadata.childrenByParent[supertask].indexOf(parentUri) === -1) {
          metadata.childrenByParent[supertask].push(parentUri);
        }
      }
    });

    return metadata;
  }

  function mergeTaskMetadata(visualMetadata, authoritativeMetadata) {
    if (!authoritativeMetadata || !authoritativeMetadata.byUri || !Object.keys(authoritativeMetadata.byUri).length) {
      return visualMetadata;
    }

    var merged = {
      byUri: {},
      byLabel: {},
      childrenByParent: {},
      parentByChild: {},
    };

    Object.keys(visualMetadata.byUri || {}).forEach(function (uri) {
      merged.byUri[uri] = Object.assign({}, visualMetadata.byUri[uri]);
    });
    Object.keys(authoritativeMetadata.byUri || {}).forEach(function (uri) {
      var current = merged.byUri[uri] || {};
      merged.byUri[uri] = Object.assign({}, current, authoritativeMetadata.byUri[uri]);
      if (!merged.byUri[uri].label && current.label) {
        merged.byUri[uri].label = current.label;
      }
      if (!merged.byUri[uri].taskType && current.taskType) {
        merged.byUri[uri].taskType = current.taskType;
      }
      if (!merged.byUri[uri].operator && current.operator) {
        merged.byUri[uri].operator = current.operator;
      }
    });

    Object.keys(visualMetadata.byLabel || {}).forEach(function (label) {
      merged.byLabel[label] = visualMetadata.byLabel[label];
    });
    Object.keys(authoritativeMetadata.byLabel || {}).forEach(function (label) {
      merged.byLabel[label] = authoritativeMetadata.byLabel[label];
    });

    Object.keys(visualMetadata.childrenByParent || {}).forEach(function (parentUri) {
      merged.childrenByParent[parentUri] = (visualMetadata.childrenByParent[parentUri] || []).slice();
    });
    Object.keys(authoritativeMetadata.childrenByParent || {}).forEach(function (parentUri) {
      merged.childrenByParent[parentUri] = (authoritativeMetadata.childrenByParent[parentUri] || []).slice();
    });

    Object.keys(visualMetadata.parentByChild || {}).forEach(function (childUri) {
      merged.parentByChild[childUri] = visualMetadata.parentByChild[childUri];
    });
    Object.keys(authoritativeMetadata.parentByChild || {}).forEach(function (childUri) {
      merged.parentByChild[childUri] = authoritativeMetadata.parentByChild[childUri];
    });

    return merged;
  }

  function mapTaskButtonToUri(taskButton, metadata) {
    var taskUri = extractTaskUri(taskButton);
    if (taskUri && metadata.byUri[taskUri]) {
      return taskUri;
    }

    var normalizedLabel = normalizeTaskLabel(getTaskLabelWithoutUri(taskButton));
    if (normalizedLabel && metadata.byLabel[normalizedLabel]) {
      return metadata.byLabel[normalizedLabel].uri;
    }

    return '';
  }

  function inferTaskMetaFromLabel(labelText) {
    var raw = String(labelText || '').replace(/\s+/g, ' ').trim();
    var normalized = raw.toLowerCase();

    var taskType = 'manual';
    if (/^abs\b/.test(normalized) || /\babstract\b/.test(normalized)) {
      taskType = 'abstract';
    }
    else if (/^(🖥⌨|🖥)/.test(raw) || /\binteractive\b/.test(normalized)) {
      taskType = 'interactive';
    }
    else if (/^(🤖|⚙️|⚙|🛠️|🛠|🔧)/.test(raw) || /\b(automated|automatic|system)\b/.test(normalized)) {
      taskType = 'automated';
    }
    else if (/^(👤)/.test(raw) || /\bmanual\b/.test(normalized)) {
      taskType = 'manual';
    }

    var operator = 'sequential';
    if (raw.indexOf('[]') !== -1 || /\bchoice\b/.test(normalized)) {
      operator = 'choice';
    }
    else if (raw.indexOf('|||') !== -1 || raw.indexOf('|[.]|') !== -1 || /\b(parallel|concurrent)\b/.test(normalized)) {
      operator = 'parallel';
    }
    else if (raw.indexOf('|=|') !== -1 || /\b(independent|order-independent|order independent)\b/.test(normalized)) {
      operator = 'independent';
    }

    var cleanLabel = raw
      .replace(/^abs\s*/i, '')
      .replace(/^(👤|🖥⌨|🖥|🤖|⚙️|⚙|🛠️|🛠|🔧)\s*/i, '')
      .replace(/(>>>|>>|\[\]|\|\|\||\|\[\.\]\||\|=\|)\s*/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();

    return {
      uri: '',
      label: cleanLabel || raw || 'Task',
      taskType: taskType,
      operator: operator,
    };
  }

  function getSelectedCanvasTaskUri(container, metadata) {
    if (!container || !container.querySelector) {
      return '';
    }

    var selected = container.querySelector(
      '.react-flow__node.selected[data-id], '
      + '.react-flow__node[aria-selected="true"][data-id], '
      + '[class*="react-flow__node"].selected[data-id], '
      + '[class*="react-flow__node"][aria-selected="true"][data-id], '
      + '[data-ctt-current-task-node="1"][data-id]'
    );
    if (!selected) {
      return '';
    }

    var uri = String(selected.getAttribute('data-id') || '').trim();
    if (!uri) {
      return '';
    }

    if (metadata && metadata.byUri && metadata.byUri[uri]) {
      return uri;
    }
    if (/^https?:\/\//i.test(uri)) {
      return uri;
    }

    return '';
  }

  function isDescendantUri(childUri, ancestorUri, metadata) {
    if (!childUri || !ancestorUri || !metadata || !metadata.parentByChild) {
      return false;
    }

    var guard = {};
    var cursor = childUri;
    while (cursor && !guard[cursor]) {
      guard[cursor] = true;
      if (cursor === ancestorUri) {
        return true;
      }
      cursor = metadata.parentByChild[cursor] || '';
    }

    return false;
  }

  function branchHasActiveDescendant(taskUri, state, metadata) {
    if (!taskUri || !state || !metadata) {
      return false;
    }

    var activeBranchRoots = [];
    Object.keys(state.activeParallelBranches || {}).forEach(function (branchUri) {
      if (!state.activeParallelBranches[branchUri]) {
        return;
      }
      activeBranchRoots.push(branchUri);
    });

    for (var i = 0; i < activeBranchRoots.length; i += 1) {
      if (isDescendantUri(taskUri, activeBranchRoots[i], metadata)) {
        return true;
      }
    }

    return false;
  }

  function clearTaskControls(nav) {
    if (!nav) {
      return;
    }
    var controls = nav.querySelector('[data-ctt-task-controls="1"]');
    if (!controls) {
      return;
    }
    controls.innerHTML = '';
  }

  function ensureTaskControlsHost(nav) {
    if (!nav) {
      return null;
    }
    var host = nav.querySelector('[data-ctt-task-controls="1"]');
    if (host) {
      return host;
    }
    host = document.createElement('div');
    host.className = 'ctt-special-task-nav__controls';
    host.setAttribute('data-ctt-task-controls', '1');
    nav.appendChild(host);
    return host;
  }

  function setTaskActionHint(nav, message, state) {
    if (!nav) {
      return;
    }
    var node = nav.querySelector('[data-ctt-action-hint="1"]');
    if (!node) {
      return;
    }

    node.textContent = String(message || '').trim();
    node.setAttribute('data-ctt-action-state', String(state || 'neutral').trim());
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
    var controlsHost = ensureTaskControlsHost(nav);
    var executionState = {
      manualResultByUri: {},
      manualNoteByUri: {},
      testManualPlanByUri: {},
      skippedByChoice: {},
      activeParallelBranches: {},
      activeIndependentParent: '',
      independentStartedBranches: {},
      autoTimersByUri: {},
      completedByUri: {},
      choiceSelectionByParent: {},
      parallelByParent: {},
      independentByParent: {},
      activeBranch: null,
      authoritativeMetadata: null,
      authoritativeMetadataLoading: false,
      authoritativeMetadataLoaded: false,
      traceByUri: {},
      testMode: !!(hooks && hooks.testMode),
      testManualFailureRate: Number(hooks && hooks.testManualFailureRate),
      testManualDelayMinMs: Number(hooks && hooks.testManualDelayMinMs),
      testManualDelayMaxMs: Number(hooks && hooks.testManualDelayMaxMs),
    };

    function normalizeTestManualFailureRate(value) {
      var numeric = Number(value);
      if (!isFinite(numeric)) {
        return 0.25;
      }
      if (numeric < 0) {
        return 0;
      }
      if (numeric > 0.95) {
        return 0.95;
      }
      return numeric;
    }

    function normalizeTestManualDelay(value, fallback) {
      var numeric = Number(value);
      if (!isFinite(numeric)) {
        return fallback;
      }
      return Math.max(120, Math.round(numeric));
    }

    function ensureTestManualPlan(uri) {
      var taskUri = String(uri || '').trim();
      if (!taskUri) {
        return {
          result: 'done fully',
          delayMs: 1000,
        };
      }

      if (executionState.testManualPlanByUri[taskUri]) {
        return executionState.testManualPlanByUri[taskUri];
      }

      var failureRate = normalizeTestManualFailureRate(executionState.testManualFailureRate);
      var minDelayMs = normalizeTestManualDelay(executionState.testManualDelayMinMs, 650);
      var maxDelayMs = normalizeTestManualDelay(executionState.testManualDelayMaxMs, 3800);
      if (maxDelayMs < minDelayMs) {
        var tmp = maxDelayMs;
        maxDelayMs = minDelayMs;
        minDelayMs = tmp;
      }

      var result = 'done fully';
      var outcomeRoll = Math.random();
      if (outcomeRoll < failureRate) {
        result = 'fail';
      }
      else if (outcomeRoll < failureRate + ((1 - failureRate) * 0.2)) {
        result = 'done partially';
      }

      var delayMs = minDelayMs;
      if (maxDelayMs > minDelayMs) {
        delayMs = minDelayMs + Math.floor(Math.random() * (maxDelayMs - minDelayMs + 1));
      }

      executionState.testManualPlanByUri[taskUri] = {
        result: result,
        delayMs: delayMs,
      };
      return executionState.testManualPlanByUri[taskUri];
    }

    function ensureTraceEntry(uri, label) {
      if (!uri) {
        return null;
      }

      if (!executionState.traceByUri[uri]) {
        executionState.traceByUri[uri] = {
          uri: uri,
          label: String(label || ''),
          startedAt: '',
          endedAt: '',
          response: '',
          note: '',
        };
      }
      else if (label && !executionState.traceByUri[uri].label) {
        executionState.traceByUri[uri].label = String(label || '');
      }

      window.__cttExecutionTrace = window.__cttExecutionTrace || {};
      window.__cttExecutionTrace.byUri = executionState.traceByUri;
      return executionState.traceByUri[uri];
    }

    function ensureAuthoritativeMetadataLoaded() {
      if (executionState.authoritativeMetadataLoaded || executionState.authoritativeMetadataLoading) {
        return;
      }

      var executionSettings = hooks && hooks.executionSettings ? hooks.executionSettings : null;
      var processUri = String(executionSettings && executionSettings.processUri || '').trim();
      var apiBaseUrl = String(executionSettings && executionSettings.apiBaseUrl || '').trim().replace(/\/+$/, '');
      if (!processUri || !apiBaseUrl || typeof window.fetch !== 'function') {
        executionState.authoritativeMetadataLoaded = true;
        return;
      }

      var endpoint = apiBaseUrl + '/process/tree?uri=' + encodeURIComponent(processUri);
      executionState.authoritativeMetadataLoading = true;

      window.fetch(endpoint, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json'
        }
      }).then(function (response) {
        if (!response || !response.ok) {
          throw new Error('Process tree request failed');
        }
        return response.json();
      }).then(function (payload) {
        executionState.authoritativeMetadata = parseAuthoritativeTaskMetadata(payload);
      }).catch(function () {
        executionState.authoritativeMetadata = null;
      }).finally(function () {
        executionState.authoritativeMetadataLoading = false;
        executionState.authoritativeMetadataLoaded = true;
        updateNavigatorState();
      });
    }

    function appendBranchController(card, options) {
      if (!card || !options) {
        return;
      }

      var mode = String(options.mode || '').trim();
      var parentUri = String(options.parentUri || '').trim();
      var activeUri = String(options.activeUri || '').trim();
      var children = Array.isArray(options.children) ? options.children.slice() : [];
      var metadata = options.metadata || { byUri: {} };
      var startedMap = options.started || {};
      var completedMap = options.completed || {};

      if (!mode || !parentUri || children.length === 0) {
        return;
      }

      var doneCount = children.filter(function (childUri) {
        return !!completedMap[childUri];
      }).length;
      var readyToJoin = doneCount === children.length;

      var wrap = document.createElement('div');
      wrap.className = 'ctt-branch-controller';
      wrap.innerHTML = ''
        + '<div class="ctt-branch-controller__title">'
        + (mode === 'parallel' ? 'Parallel Branch Controller' : (mode === 'independent' ? 'Order-Independent Branch Controller' : 'Choice Branch Controller'))
        + '</div>'
        + '<div class="ctt-branch-controller__summary">'
        + doneCount + ' / ' + children.length + ' branches completed' + (readyToJoin ? ' • ready to join' : ' • join pending')
        + '</div>';

      var list = document.createElement('div');
      list.className = 'ctt-branch-controller__list';
      children.forEach(function (childUri) {
        var childMeta = metadata.byUri && metadata.byUri[childUri] ? metadata.byUri[childUri] : null;
        var label = childMeta && childMeta.label ? childMeta.label : childUri;
        var status = 'waiting';
        if (completedMap[childUri]) {
          status = 'completed';
        }
        else if (startedMap[childUri]) {
          status = (activeUri && (activeUri === childUri || isDescendantUri(activeUri, childUri, metadata))) ? 'active' : 'in-progress';
        }

        var row = document.createElement('div');
        row.className = 'ctt-branch-controller__row';
        row.innerHTML = ''
          + '<span class="ctt-branch-controller__label">' + label + '</span>'
          + '<span class="ctt-branch-controller__status is-' + status + '">' + status.replace('-', ' ') + '</span>';
        list.appendChild(row);
      });
      wrap.appendChild(list);
      card.appendChild(wrap);
    }

    function renderManualControls(activeUri, metadata, moveNext) {
      clearTaskControls(nav);
      if (!controlsHost) {
        return;
      }
      setTaskActionHint(nav, 'Your turn: select one manual outcome, optionally add a note, then click Next.', 'required');

      var selected = executionState.manualResultByUri[activeUri] || '';
      var note = executionState.manualNoteByUri[activeUri] || '';
      var traceEntry = ensureTraceEntry(activeUri, (metadata && metadata.byUri && metadata.byUri[activeUri] && metadata.byUri[activeUri].label) || '');
      if (traceEntry && !traceEntry.startedAt) {
        traceEntry.startedAt = new Date().toISOString();
      }
      var card = document.createElement('div');
      card.className = 'ctt-task-control-card';
      card.innerHTML = ''
        + '<div class="ctt-task-control-card__title">Manual task outcome</div>'
        + '<label class="ctt-task-radio"><input type="radio" name="ctt-manual-' + encodeURIComponent(activeUri) + '" value="done fully"> Done fully</label>'
        + '<label class="ctt-task-radio"><input type="radio" name="ctt-manual-' + encodeURIComponent(activeUri) + '" value="done partially"> Done partially</label>'
        + '<label class="ctt-task-radio"><input type="radio" name="ctt-manual-' + encodeURIComponent(activeUri) + '" value="fail"> Fail</label>'
        + '<div class="ctt-task-note-wrap">'
        + '  <label class="ctt-task-note-label" for="ctt-manual-note-' + encodeURIComponent(activeUri) + '">Optional note (included in execution output)</label>'
        + '  <textarea id="ctt-manual-note-' + encodeURIComponent(activeUri) + '" class="ctt-task-note" rows="2" placeholder="Type an optional note here"></textarea>'
        + '</div>';

      controlsHost.appendChild(card);

      var radios = card.querySelectorAll('input[type="radio"]');
      radios.forEach(function (radio) {
        if (radio.value === selected) {
          radio.checked = true;
        }
        radio.addEventListener('change', function () {
          executionState.manualResultByUri[activeUri] = String(radio.value || '').trim();
          var trace = ensureTraceEntry(activeUri, '');
          if (trace) {
            trace.response = executionState.manualResultByUri[activeUri];
          }
          if (nextButton) {
            nextButton.disabled = !executionState.manualResultByUri[activeUri];
          }
        });
      });

      var noteField = card.querySelector('textarea');
      if (noteField) {
        noteField.value = note;
        noteField.addEventListener('input', function () {
          executionState.manualNoteByUri[activeUri] = String(noteField.value || '');
          var trace = ensureTraceEntry(activeUri, '');
          if (trace) {
            trace.note = executionState.manualNoteByUri[activeUri];
          }
        });
      }

      if (nextButton) {
        nextButton.textContent = 'Next';
        nextButton.disabled = !executionState.manualResultByUri[activeUri];
      }

      if (executionState.testMode) {
        var manualPlan = ensureTestManualPlan(activeUri);
        if (!executionState.manualResultByUri[activeUri]) {
          var preferredRadio = card.querySelector('input[type="radio"][value="' + manualPlan.result + '"]');
          var firstRadio = card.querySelector('input[type="radio"]');
          var selectedRadio = preferredRadio || firstRadio;
          if (selectedRadio) {
            selectedRadio.checked = true;
            selectedRadio.dispatchEvent(new Event('change', { bubbles: true }));
          }
        }
        var manualTimerKey = 'test-manual:' + String(activeUri || '');
        if (!executionState.autoTimersByUri[manualTimerKey] && typeof moveNext === 'function') {
          executionState.autoTimersByUri[manualTimerKey] = window.setTimeout(function () {
            executionState.autoTimersByUri[manualTimerKey] = 0;
            moveNext(1);
          }, manualPlan.delayMs);
        }
      }

      if (executionState.activeBranch && executionState.activeBranch.parentUri) {
        var parentUri = executionState.activeBranch.parentUri;
        if (executionState.activeBranch.mode === 'parallel' && executionState.parallelByParent[parentUri]) {
          var ps = executionState.parallelByParent[parentUri];
          appendBranchController(card, {
            mode: 'parallel',
            parentUri: parentUri,
            activeUri: activeUri,
            children: ps.children,
            started: ps.started,
            completed: ps.completed,
            metadata: metadata,
          });
        }
        if (executionState.activeBranch.mode === 'independent' && executionState.independentByParent[parentUri]) {
          var is = executionState.independentByParent[parentUri];
          appendBranchController(card, {
            mode: 'independent',
            parentUri: parentUri,
            activeUri: activeUri,
            children: is.children,
            started: is.started,
            completed: is.completed,
            metadata: metadata,
          });
        }
      }
    }

    function renderInteractiveControls(metadataEntry, activeUri, metadata, moveNext) {
      clearTaskControls(nav);
      if (!controlsHost) {
        return;
      }
      setTaskActionHint(nav, 'Your turn: perform the interactive step in the component, then click Next.', 'required');

      var card = document.createElement('div');
      card.className = 'ctt-task-control-card';
      var componentLine = metadataEntry && metadataEntry.componentLabel
        ? ('<div class="ctt-task-control-card__meta">Component: ' + String(metadataEntry.componentLabel) + '</div>')
        : (metadataEntry && metadataEntry.componentUri
          ? ('<div class="ctt-task-control-card__meta">Component URI: ' + String(metadataEntry.componentUri) + '</div>')
          : '<div class="ctt-task-control-card__meta">Component details are loaded from associated task metadata.</div>');
      card.innerHTML = ''
        + '<div class="ctt-task-control-card__title">Interactive task</div>'
        + '<div class="ctt-task-control-card__text">Open the associated component inputs for this task and complete them before continuing.</div>'
        + '<div class="ctt-task-control-card__meta">Task: ' + String(metadataEntry && metadataEntry.label || 'Interactive task') + '</div>'
        + componentLine;
      controlsHost.appendChild(card);
      var interactiveTrace = ensureTraceEntry(activeUri, metadataEntry && metadataEntry.label ? metadataEntry.label : '');
      if (interactiveTrace && !interactiveTrace.startedAt) {
        interactiveTrace.startedAt = new Date().toISOString();
      }

      if (nextButton) {
        nextButton.textContent = 'Next';
        nextButton.disabled = false;
      }

      if (executionState.testMode && typeof moveNext === 'function') {
        var interactiveTimerKey = 'test-interactive:' + String(activeUri || '');
        if (!executionState.autoTimersByUri[interactiveTimerKey]) {
          executionState.autoTimersByUri[interactiveTimerKey] = window.setTimeout(function () {
            executionState.autoTimersByUri[interactiveTimerKey] = 0;
            moveNext(1);
          }, 220);
        }
      }

      if (executionState.activeBranch && executionState.activeBranch.parentUri) {
        var parentUri = executionState.activeBranch.parentUri;
        if (executionState.activeBranch.mode === 'parallel' && executionState.parallelByParent[parentUri]) {
          var ps = executionState.parallelByParent[parentUri];
          appendBranchController(card, {
            mode: 'parallel',
            parentUri: parentUri,
            activeUri: activeUri,
            children: ps.children,
            started: ps.started,
            completed: ps.completed,
            metadata: metadata,
          });
        }
        if (executionState.activeBranch.mode === 'independent' && executionState.independentByParent[parentUri]) {
          var is = executionState.independentByParent[parentUri];
          appendBranchController(card, {
            mode: 'independent',
            parentUri: parentUri,
            activeUri: activeUri,
            children: is.children,
            started: is.started,
            completed: is.completed,
            metadata: metadata,
          });
        }
      }
    }

    function renderAutomatedControls(activeUri, moveNext, metadata) {
      clearTaskControls(nav);
      if (!controlsHost) {
        return;
      }
      setTaskActionHint(nav, 'Automatic step in progress. No button press required.', 'auto');

      var card = document.createElement('div');
      card.className = 'ctt-task-control-card';
      card.innerHTML = ''
        + '<div class="ctt-task-control-card__title">Automated task</div>'
        + '<div class="ctt-task-control-card__spinner" aria-hidden="true"></div>'
        + '<div class="ctt-task-control-card__text">Executing automatically. Moving to next task in 5 seconds...</div>';
      controlsHost.appendChild(card);
      var automatedTrace = ensureTraceEntry(activeUri, (metadata && metadata.byUri && metadata.byUri[activeUri] && metadata.byUri[activeUri].label) || '');
      if (automatedTrace) {
        if (!automatedTrace.startedAt) {
          automatedTrace.startedAt = new Date().toISOString();
        }
        automatedTrace.response = 'automated';
      }

      if (nextButton) {
        nextButton.textContent = 'Auto-running...';
        nextButton.disabled = true;
      }

      if (!executionState.autoTimersByUri[activeUri]) {
        executionState.autoTimersByUri[activeUri] = window.setTimeout(function () {
          var doneTrace = ensureTraceEntry(activeUri, '');
          if (doneTrace && !doneTrace.endedAt) {
            doneTrace.endedAt = new Date().toISOString();
          }
          executionState.autoTimersByUri[activeUri] = 0;
          moveNext(1);
        }, 5000);
      }

      if (executionState.activeBranch && executionState.activeBranch.parentUri) {
        var parentUri = executionState.activeBranch.parentUri;
        if (executionState.activeBranch.mode === 'parallel' && executionState.parallelByParent[parentUri]) {
          var ps = executionState.parallelByParent[parentUri];
          appendBranchController(card, {
            mode: 'parallel',
            parentUri: parentUri,
            activeUri: activeUri,
            children: ps.children,
            started: ps.started,
            completed: ps.completed,
            metadata: metadata,
          });
        }
        if (executionState.activeBranch.mode === 'independent' && executionState.independentByParent[parentUri]) {
          var is = executionState.independentByParent[parentUri];
          appendBranchController(card, {
            mode: 'independent',
            parentUri: parentUri,
            activeUri: activeUri,
            children: is.children,
            started: is.started,
            completed: is.completed,
            metadata: metadata,
          });
        }
      }
    }

    function renderChoiceControls(activeUri, metadata, moveToChild, resolveFallbackChildren, moveNext) {
      clearTaskControls(nav);
      if (!controlsHost) {
        return;
      }
      setTaskActionHint(nav, 'Your turn: select exactly one branch button below.', 'required');

      var children = metadata.childrenByParent[activeUri] || [];
      if (!children.length && typeof resolveFallbackChildren === 'function') {
        var fallbackChildren = resolveFallbackChildren(activeUri, metadata);
        if (Array.isArray(fallbackChildren) && fallbackChildren.length) {
          children = fallbackChildren.slice();
        }
      }
      var card = document.createElement('div');
      card.className = 'ctt-task-control-card';
      card.innerHTML = '<div class="ctt-task-control-card__title">Choice task</div><div class="ctt-task-control-card__text">Select exactly one subtask branch.</div>';
      controlsHost.appendChild(card);

      var actions = document.createElement('div');
      actions.className = 'ctt-task-branch-actions';
      children.forEach(function (childUri) {
        var childMeta = metadata.byUri[childUri] || { label: childUri };
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-sm btn-outline-primary';
        button.textContent = childMeta.label || childUri;
        button.addEventListener('click', function () {
          executionState.choiceSelectionByParent[activeUri] = childUri;
          executionState.activeBranch = {
            mode: 'choice',
            parentUri: activeUri,
            childUri: childUri,
          };
          executionState.completedByUri[activeUri] = true;
          var choiceTrace = ensureTraceEntry(activeUri, childMeta.label || childUri);
          if (choiceTrace) {
            if (!choiceTrace.startedAt) {
              choiceTrace.startedAt = new Date().toISOString();
            }
            choiceTrace.response = 'choice:' + (childMeta.label || childUri);
            choiceTrace.endedAt = new Date().toISOString();
          }
          children.forEach(function (siblingUri) {
            if (siblingUri === childUri) {
              return;
            }
            executionState.skippedByChoice[siblingUri] = true;
          });
          var moved = moveToChild(childUri);
          if (!moved) {
            setTaskActionHint(nav, 'Branch selected but direct mapping failed. Advancing using fallback path.', 'required');
          }
        });
        actions.appendChild(button);
      });
      card.appendChild(actions);

      if (!children.length) {
        var empty = document.createElement('div');
        empty.className = 'ctt-task-control-card__meta';
        empty.textContent = 'No explicit branch mapping found. Use Next to continue to the next valid task.';
        card.appendChild(empty);
      }

      var choiceStarted = {};
      var choiceCompleted = {};
      if (executionState.choiceSelectionByParent[activeUri]) {
        choiceStarted[executionState.choiceSelectionByParent[activeUri]] = true;
        choiceCompleted[executionState.choiceSelectionByParent[activeUri]] = true;
      }

      appendBranchController(card, {
        mode: 'choice',
        parentUri: activeUri,
        activeUri: activeUri,
        children: children,
        started: choiceStarted,
        completed: choiceCompleted,
        metadata: metadata,
      });

      if (nextButton) {
        if (children.length) {
          nextButton.textContent = 'Select branch';
          nextButton.disabled = true;
        }
        else {
          nextButton.textContent = 'Next';
          nextButton.disabled = false;
        }
      }

      if (executionState.testMode) {
        var choiceTimerKey = 'test-choice:' + String(activeUri || '');
        if (!executionState.autoTimersByUri[choiceTimerKey]) {
          executionState.autoTimersByUri[choiceTimerKey] = window.setTimeout(function () {
            executionState.autoTimersByUri[choiceTimerKey] = 0;
            if (children.length) {
              var firstChoiceButton = actions.querySelector('button');
              if (firstChoiceButton && !firstChoiceButton.disabled) {
                firstChoiceButton.click();
                return;
              }
            }
            if (typeof moveNext === 'function') {
              moveNext(1);
            }
          }, 180);
        }
      }
    }

    function renderIndependentControls(activeUri, metadata, moveToChild, moveNext) {
      clearTaskControls(nav);
      if (!controlsHost) {
        return;
      }
      setTaskActionHint(nav, 'Your turn: run each branch in any order until all are completed.', 'required');

      var children = metadata.childrenByParent[activeUri] || [];
      if (!executionState.independentByParent[activeUri]) {
        executionState.independentByParent[activeUri] = {
          children: children.slice(),
          started: {},
          completed: {},
        };
      }
      var independentState = executionState.independentByParent[activeUri];

      var card = document.createElement('div');
      card.className = 'ctt-task-control-card';
      card.innerHTML = '<div class="ctt-task-control-card__title">Order-independent task</div><div class="ctt-task-control-card__text">Run all subtasks in any order.</div>';
      controlsHost.appendChild(card);

      var actions = document.createElement('div');
      actions.className = 'ctt-task-branch-actions';
      children.forEach(function (childUri) {
        var childMeta = metadata.byUri[childUri] || { label: childUri };
        var button = document.createElement('button');
        var done = !!independentState.completed[childUri];
        var started = !!independentState.started[childUri];
        button.type = 'button';
        button.className = 'btn btn-sm ' + (done ? 'btn-success' : (started ? 'btn-warning' : 'btn-outline-primary'));
        button.textContent = (done ? 'Done: ' : (started ? 'In progress: ' : 'Run: ')) + (childMeta.label || childUri);
        button.addEventListener('click', function () {
          independentState.started[childUri] = true;
          executionState.activeIndependentParent = activeUri;
          executionState.activeBranch = {
            mode: 'independent',
            parentUri: activeUri,
            childUri: childUri,
          };
          moveToChild(childUri);
        });
        actions.appendChild(button);
      });
      card.appendChild(actions);

      appendBranchController(card, {
        mode: 'independent',
        parentUri: activeUri,
        activeUri: activeUri,
        children: children,
        started: independentState.started,
        completed: independentState.completed,
        metadata: metadata,
      });

      var allCompleted = children.length > 0 && children.every(function (childUri) {
        return !!independentState.completed[childUri];
      });

      if (nextButton) {
        nextButton.textContent = allCompleted ? 'Merge and continue' : 'Run remaining branches';
        nextButton.disabled = !allCompleted;
      }

      if (executionState.testMode) {
        var independentTimerKey = 'test-independent:' + String(activeUri || '');
        if (!executionState.autoTimersByUri[independentTimerKey]) {
          executionState.autoTimersByUri[independentTimerKey] = window.setTimeout(function () {
            executionState.autoTimersByUri[independentTimerKey] = 0;
            if (allCompleted && typeof moveNext === 'function') {
              moveNext(1);
              return;
            }
            for (var i = 0; i < children.length; i += 1) {
              var candidateChild = children[i];
              if (!!independentState.completed[candidateChild]) {
                continue;
              }
              independentState.started[candidateChild] = true;
              executionState.activeIndependentParent = activeUri;
              executionState.activeBranch = {
                mode: 'independent',
                parentUri: activeUri,
                childUri: candidateChild,
              };
              moveToChild(candidateChild);
              return;
            }
          }, 180);
        }
      }
    }

    function renderParallelControls(activeUri, metadata, moveToChild, moveNext) {
      clearTaskControls(nav);
      if (!controlsHost) {
        return;
      }
      setTaskActionHint(nav, 'Your turn: start or continue each parallel thread until all are done.', 'required');

      var children = metadata.childrenByParent[activeUri] || [];
      if (!executionState.parallelByParent[activeUri]) {
        executionState.parallelByParent[activeUri] = {
          children: children.slice(),
          started: {},
          completed: {},
        };
      }
      var parallelState = executionState.parallelByParent[activeUri];

      var card = document.createElement('div');
      card.className = 'ctt-task-control-card';
      card.innerHTML = '<div class="ctt-task-control-card__title">Parallel/concurrent task</div><div class="ctt-task-control-card__text">Parallel thread split created. Run each branch until it ends.</div>';
      controlsHost.appendChild(card);

      var actions = document.createElement('div');
      actions.className = 'ctt-task-branch-actions';
      children.forEach(function (childUri) {
        var childMeta = metadata.byUri[childUri] || { label: childUri };
        var isCompleted = !!parallelState.completed[childUri];
        var isStarted = !!parallelState.started[childUri];
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-sm ' + (isCompleted ? 'btn-success' : (isStarted ? 'btn-warning' : 'btn-outline-primary'));
        button.textContent = (isCompleted ? 'Done thread: ' : (isStarted ? 'Continue thread: ' : 'Start thread: ')) + (childMeta.label || childUri);
        button.addEventListener('click', function () {
          parallelState.started[childUri] = true;
          executionState.activeParallelBranches[childUri] = true;
          executionState.activeBranch = {
            mode: 'parallel',
            parentUri: activeUri,
            childUri: childUri,
          };
          moveToChild(childUri);
        });
        actions.appendChild(button);
      });
      card.appendChild(actions);

      appendBranchController(card, {
        mode: 'parallel',
        parentUri: activeUri,
        activeUri: activeUri,
        children: children,
        started: parallelState.started,
        completed: parallelState.completed,
        metadata: metadata,
      });

      var allCompleted = children.length > 0 && children.every(function (childUri) {
        return !!parallelState.completed[childUri];
      });

      if (nextButton) {
        nextButton.textContent = allCompleted ? 'Merge and continue' : 'Manage branches';
        nextButton.disabled = !allCompleted;
      }

      if (executionState.testMode) {
        var parallelTimerKey = 'test-parallel:' + String(activeUri || '');
        if (!executionState.autoTimersByUri[parallelTimerKey]) {
          executionState.autoTimersByUri[parallelTimerKey] = window.setTimeout(function () {
            executionState.autoTimersByUri[parallelTimerKey] = 0;
            if (allCompleted && typeof moveNext === 'function') {
              moveNext(1);
              return;
            }
            for (var i = 0; i < children.length; i += 1) {
              var candidateChild = children[i];
              if (!!parallelState.completed[candidateChild]) {
                continue;
              }
              parallelState.started[candidateChild] = true;
              executionState.activeParallelBranches[candidateChild] = true;
              executionState.activeBranch = {
                mode: 'parallel',
                parentUri: activeUri,
                childUri: candidateChild,
              };
              moveToChild(candidateChild);
              return;
            }
          }, 180);
        }
      }
    }

    function renderSequentialAbstractAutoControls(activeUri, moveNext, metadata) {
      clearTaskControls(nav);
      if (!controlsHost) {
        return;
      }

      setTaskActionHint(nav, 'Abstract sequential step in progress. Advancing automatically in 5 seconds.', 'auto');

      var card = document.createElement('div');
      card.className = 'ctt-task-control-card';
      card.innerHTML = ''
        + '<div class="ctt-task-control-card__title">Abstract sequential task</div>'
        + '<div class="ctt-task-control-card__spinner" aria-hidden="true"></div>'
        + '<div class="ctt-task-control-card__text">No user decision required. Moving to next task in 5 seconds...</div>';
      controlsHost.appendChild(card);

      var abstractTrace = ensureTraceEntry(activeUri, (metadata && metadata.byUri && metadata.byUri[activeUri] && metadata.byUri[activeUri].label) || '');
      if (abstractTrace) {
        if (!abstractTrace.startedAt) {
          abstractTrace.startedAt = new Date().toISOString();
        }
        abstractTrace.response = 'abstract-sequential-auto';
      }

      if (nextButton) {
        nextButton.textContent = 'Auto-running...';
        nextButton.disabled = true;
      }

      var timerKey = 'abs-seq:' + String(activeUri || '');
      if (!executionState.autoTimersByUri[timerKey]) {
        executionState.autoTimersByUri[timerKey] = window.setTimeout(function () {
          var doneTrace = ensureTraceEntry(activeUri, '');
          if (doneTrace && !doneTrace.endedAt) {
            doneTrace.endedAt = new Date().toISOString();
          }
          executionState.autoTimersByUri[timerKey] = 0;
          moveNext(1);
        }, 5000);
      }
    }

    function updateNavigatorState() {
      ensureAuthoritativeMetadataLoaded();
      var taskPanel = getTaskPanelScope(container);
      var tasks = getTaskProgressButtons(taskPanel);
      var visualMetadata = parseTaskNodeMetadata(container);
      var metadata = mergeTaskMetadata(visualMetadata, executionState.authoritativeMetadata);
      var activeIndex = getActiveTaskIndex(tasks);

      if (!tasks.length || activeIndex < 0) {
        if (currentNode) {
          currentNode.textContent = 'Waiting for task list...';
        }
        setTaskActionHint(nav, 'Waiting for tasks to become available...', 'neutral');
        clearTaskControls(nav);
        if (prevButton) {
          prevButton.disabled = true;
        }
        if (nextButton) {
          nextButton.textContent = 'Continue';
          nextButton.disabled = true;
        }
        return;
      }

      var activeTask = tasks[activeIndex];
      var activeUri = mapTaskButtonToUri(activeTask, metadata);
      if (!activeUri) {
        activeUri = getSelectedCanvasTaskUri(container, metadata);
      }
      var activeMeta = activeUri && metadata.byUri[activeUri] ? metadata.byUri[activeUri] : null;
      if (!activeMeta) {
        var inferred = inferTaskMetaFromLabel(getTaskButtonLabel(activeTask));
        if (activeUri) {
          inferred.uri = activeUri;
        }
        activeMeta = inferred;
      }
      if (activeUri) {
        var activeTrace = ensureTraceEntry(activeUri, activeMeta && activeMeta.label ? activeMeta.label : getTaskButtonLabel(activeTask));
        if (activeTrace && !activeTrace.startedAt) {
          activeTrace.startedAt = new Date().toISOString();
        }
      }

      function findTaskIndexByUri(targetUri) {
        if (!targetUri) {
          return -1;
        }
        for (var i = 0; i < tasks.length; i += 1) {
          var uri = mapTaskButtonToUri(tasks[i], metadata);
          if (uri === targetUri) {
            return i;
          }
        }
        return -1;
      }

      function getChildren(uri) {
        return metadata.childrenByParent && metadata.childrenByParent[uri]
          ? metadata.childrenByParent[uri].slice()
          : [];
      }

      function getDescendants(rootUri) {
        var out = {};
        var queue = getChildren(rootUri);
        while (queue.length) {
          var current = queue.shift();
          if (!current || out[current]) {
            continue;
          }
          out[current] = true;
          var next = getChildren(current);
          for (var i = 0; i < next.length; i += 1) {
            queue.push(next[i]);
          }
        }
        return out;
      }

      function isChoiceBlocked(uri) {
        if (!uri) {
          return false;
        }
        var parents = Object.keys(executionState.choiceSelectionByParent || {});
        for (var p = 0; p < parents.length; p += 1) {
          var parentUri = parents[p];
          var selected = executionState.choiceSelectionByParent[parentUri];
          var children = getChildren(parentUri);
          for (var c = 0; c < children.length; c += 1) {
            var childUri = children[c];
            if (childUri === selected) {
              continue;
            }
            if (uri === childUri || isDescendantUri(uri, childUri, metadata)) {
              return true;
            }
          }
        }
        return false;
      }

      function findLinearNextIndex(index) {
        for (var i = index + 1; i < tasks.length; i += 1) {
          var uri = mapTaskButtonToUri(tasks[i], metadata);
          if (uri && isChoiceBlocked(uri)) {
            continue;
          }
          return i;
        }
        return -1;
      }

      function findPostParentIndex(parentUri) {
        var parentIndex = findTaskIndexByUri(parentUri);
        if (parentIndex < 0) {
          return -1;
        }
        for (var i = parentIndex + 1; i < tasks.length; i += 1) {
          var uri = mapTaskButtonToUri(tasks[i], metadata);
          if (!uri) {
            return i;
          }
          if (uri !== parentUri && !isDescendantUri(uri, parentUri, metadata) && !isChoiceBlocked(uri)) {
            return i;
          }
        }
        return -1;
      }

      function markTaskCompleted(uri) {
        if (!uri) {
          return;
        }
        executionState.completedByUri[uri] = true;
      }
      markTaskAsActive(tasks, activeTask);
      syncCanvasWithTask(container, activeTask);

      if (currentNode) {
        var label = getTaskButtonLabel(activeTask) || 'Current task';
        var taskFlavor = activeMeta ? (activeMeta.taskType + ', ' + activeMeta.operator) : 'unknown type';
        currentNode.textContent = 'Task ' + (activeIndex + 1) + ' of ' + tasks.length + ': ' + label + ' (' + taskFlavor + ')';
      }

      if (prevButton) {
        prevButton.disabled = activeIndex <= 0;
      }

      function moveToTaskByUri(targetUri) {
        if (!targetUri) {
          return false;
        }
        for (var i = 0; i < tasks.length; i += 1) {
          var uri = mapTaskButtonToUri(tasks[i], metadata);
          if (uri === targetUri) {
            markTaskAsActive(tasks, tasks[i]);
            tasks[i].click();
            syncCanvasWithTask(container, tasks[i]);
            updateNavigatorState();
            return true;
          }
        }

        var targetMeta = metadata.byUri && metadata.byUri[targetUri] ? metadata.byUri[targetUri] : null;
        var targetLabel = normalizeTaskLabel(targetMeta && targetMeta.label ? targetMeta.label : '');
        if (targetLabel) {
          for (var j = 0; j < tasks.length; j += 1) {
            var label = normalizeTaskLabel(getTaskLabelWithoutUri(tasks[j]));
            if (label && label === targetLabel) {
              markTaskAsActive(tasks, tasks[j]);
              tasks[j].click();
              syncCanvasWithTask(container, tasks[j]);
              updateNavigatorState();
              return true;
            }
          }
        }

        return false;
      }

      function moveToFallbackChoiceChild(parentUri) {
        var fallbackChildren = [];
        for (var i = activeIndex + 1; i < tasks.length; i += 1) {
          var candidateUri = mapTaskButtonToUri(tasks[i], metadata);
          if (!candidateUri || isChoiceBlocked(candidateUri)) {
            continue;
          }
          fallbackChildren.push(candidateUri);
          break;
        }

        if (!fallbackChildren.length) {
          return false;
        }

        return moveToTaskByUri(fallbackChildren[0]);
      }

      function moveTask(offset) {
        var currentUri = activeUri;
        if (offset > 0 && currentUri) {
          markTaskCompleted(currentUri);
          var doneTrace = ensureTraceEntry(currentUri, activeMeta && activeMeta.label ? activeMeta.label : '');
          if (doneTrace) {
            if (!doneTrace.startedAt) {
              doneTrace.startedAt = new Date().toISOString();
            }
            if (!doneTrace.endedAt) {
              doneTrace.endedAt = new Date().toISOString();
            }
          }
        }

        if (offset > 0 && executionState.activeBranch) {
          var activeBranch = executionState.activeBranch;
          var branchMode = activeBranch.mode;
          var branchParentUri = activeBranch.parentUri;
          var branchChildUri = activeBranch.childUri;

          var nextBranchIndex = findLinearNextIndex(activeIndex);
          var nextBranchUri = nextBranchIndex >= 0 ? mapTaskButtonToUri(tasks[nextBranchIndex], metadata) : '';
          var stillInsideBranch = nextBranchUri && (nextBranchUri === branchChildUri || isDescendantUri(nextBranchUri, branchChildUri, metadata));

          if (stillInsideBranch) {
            var nextBranchTask = tasks[nextBranchIndex];
            markTaskAsActive(tasks, nextBranchTask);
            nextBranchTask.click();
            syncCanvasWithTask(container, nextBranchTask);
            updateNavigatorState();
            return;
          }

          if (branchMode === 'parallel' && executionState.parallelByParent[branchParentUri]) {
            executionState.parallelByParent[branchParentUri].completed[branchChildUri] = true;
          }
          if (branchMode === 'independent' && executionState.independentByParent[branchParentUri]) {
            executionState.independentByParent[branchParentUri].started[branchChildUri] = true;
            executionState.independentByParent[branchParentUri].completed[branchChildUri] = true;
          }

          if (branchMode === 'choice') {
            markTaskCompleted(branchParentUri);
            executionState.activeBranch = null;
            var postChoiceIndex = findPostParentIndex(branchParentUri);
            if (postChoiceIndex >= 0) {
              var postChoiceTask = tasks[postChoiceIndex];
              markTaskAsActive(tasks, postChoiceTask);
              postChoiceTask.click();
              syncCanvasWithTask(container, postChoiceTask);
              updateNavigatorState();
              return;
            }
            if (hooks && typeof hooks.onLastTaskAdvance === 'function') {
              hooks.onLastTaskAdvance();
            }
            updateNavigatorState();
            return;
          }

          executionState.activeBranch = null;
          moveToTaskByUri(branchParentUri);
          return;
        }

        if (offset > 0 && activeMeta && activeUri) {
          var isParallelParent = activeMeta.operator === 'parallel' && executionState.parallelByParent[activeUri];
          if (isParallelParent) {
            var parallelState = executionState.parallelByParent[activeUri];
            var parallelChildren = parallelState.children || [];
            var parallelDone = parallelChildren.length > 0 && parallelChildren.every(function (childUri) {
              return !!parallelState.completed[childUri];
            });
            if (parallelDone) {
              var postParallelIndex = findPostParentIndex(activeUri);
              if (postParallelIndex >= 0) {
                var postParallelTask = tasks[postParallelIndex];
                markTaskAsActive(tasks, postParallelTask);
                postParallelTask.click();
                syncCanvasWithTask(container, postParallelTask);
                updateNavigatorState();
                return;
              }
            }
          }

          var isIndependentParent = activeMeta.operator === 'independent' && executionState.independentByParent[activeUri];
          if (isIndependentParent) {
            var independentState = executionState.independentByParent[activeUri];
            var independentChildren = independentState.children || [];
            var independentDone = independentChildren.length > 0 && independentChildren.every(function (childUri) {
              return !!independentState.completed[childUri];
            });
            if (independentDone) {
              var postIndependentIndex = findPostParentIndex(activeUri);
              if (postIndependentIndex >= 0) {
                var postIndependentTask = tasks[postIndependentIndex];
                markTaskAsActive(tasks, postIndependentTask);
                postIndependentTask.click();
                syncCanvasWithTask(container, postIndependentTask);
                updateNavigatorState();
                return;
              }
            }
          }

          var isCompletedChoiceParent = activeMeta.operator === 'choice'
            && !!executionState.choiceSelectionByParent[activeUri]
            && !!executionState.completedByUri[activeUri];
          if (isCompletedChoiceParent) {
            var postChoiceParentIndex = findPostParentIndex(activeUri);
            if (postChoiceParentIndex >= 0) {
              var postChoiceParentTask = tasks[postChoiceParentIndex];
              markTaskAsActive(tasks, postChoiceParentTask);
              postChoiceParentTask.click();
              syncCanvasWithTask(container, postChoiceParentTask);
              updateNavigatorState();
              return;
            }
          }
        }

        var targetIndex = activeIndex + offset;
        if (offset > 0) {
          targetIndex = findLinearNextIndex(activeIndex);
        }
        if (targetIndex < 0 || targetIndex >= tasks.length) {
          if (offset > 0 && hooks && typeof hooks.onLastTaskAdvance === 'function') {
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

      var effectiveOperator = activeMeta ? activeMeta.operator : 'sequential';
      if (activeMeta && activeMeta.taskType === 'abstract' && effectiveOperator === 'sequential') {
        effectiveOperator = activeMeta.operator;
      }

      if (!nextButton) {
        return;
      }

      if (nav.__cttNextHandler) {
        nextButton.removeEventListener('click', nav.__cttNextHandler);
      }
      nav.__cttNextHandler = function () {
        moveTask(1);
      };
      nextButton.addEventListener('click', nav.__cttNextHandler);

      if (activeMeta && activeMeta.taskType === 'manual') {
        renderManualControls(activeUri, metadata, moveTask);
      }
      else if (activeMeta && activeMeta.taskType === 'interactive') {
        renderInteractiveControls(activeMeta, activeUri, metadata, moveTask);
      }
      else if (activeMeta && activeMeta.taskType === 'automated') {
        renderAutomatedControls(activeUri, moveTask, metadata);
      }
      else if (activeMeta && activeMeta.taskType === 'abstract' && effectiveOperator === 'sequential') {
        renderSequentialAbstractAutoControls(activeUri, moveTask, metadata);
      }
      else if (activeMeta && effectiveOperator === 'choice') {
        renderChoiceControls(activeUri, metadata, function (childUri) {
          if (moveToTaskByUri(childUri)) {
            return true;
          }
          return moveToFallbackChoiceChild(activeUri);
        }, function () {
          var resolved = [];
          for (var i = activeIndex + 1; i < tasks.length; i += 1) {
            var candidateUri = mapTaskButtonToUri(tasks[i], metadata);
            if (!candidateUri || isChoiceBlocked(candidateUri)) {
              continue;
            }
            resolved.push(candidateUri);
            break;
          }
          return resolved;
        }, moveTask);
      }
      else if (activeMeta && (effectiveOperator === 'parallel')) {
        renderParallelControls(activeUri, metadata, function (childUri) {
          moveToTaskByUri(childUri);
        }, moveTask);
      }
      else if (activeMeta && effectiveOperator === 'independent') {
        renderIndependentControls(activeUri, metadata, function (childUri) {
          moveToTaskByUri(childUri);
        }, moveTask);
      }
      else {
        clearTaskControls(nav);
        setTaskActionHint(nav, 'Your turn: click Next to continue.', 'required');
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

    if (prevButton) {
      prevButton.addEventListener('click', function () {
        var taskPanel = getTaskPanelScope(container);
        var tasks = getTaskProgressButtons(taskPanel);
        var activeIndex = getActiveTaskIndex(tasks);
        if (!tasks.length || activeIndex <= 0) {
          updateNavigatorState();
          return;
        }
        var target = tasks[activeIndex - 1];
        markTaskAsActive(tasks, target);
        target.click();
        syncCanvasWithTask(container, target);
        updateNavigatorState();
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

    panel.__cttTaskNavReset = function () {
      Object.keys(executionState.autoTimersByUri || {}).forEach(function (timerKey) {
        var timerId = Number(executionState.autoTimersByUri[timerKey] || 0);
        if (timerId) {
          window.clearTimeout(timerId);
        }
      });

      executionState.manualResultByUri = {};
      executionState.manualNoteByUri = {};
      executionState.testManualPlanByUri = {};
      executionState.skippedByChoice = {};
      executionState.activeParallelBranches = {};
      executionState.activeIndependentParent = '';
      executionState.independentStartedBranches = {};
      executionState.autoTimersByUri = {};
      executionState.completedByUri = {};
      executionState.choiceSelectionByParent = {};
      executionState.parallelByParent = {};
      executionState.independentByParent = {};
      executionState.activeBranch = null;
      executionState.traceByUri = {};

      window.__cttExecutionTrace = window.__cttExecutionTrace || {};
      window.__cttExecutionTrace.byUri = executionState.traceByUri;

      clearTaskControls(nav);

      var taskPanel = getTaskPanelScope(container);
      var tasks = getTaskProgressButtons(taskPanel);
      if (tasks.length) {
        markTaskAsActive(tasks, tasks[0]);
        if (typeof tasks[0].click === 'function') {
          tasks[0].click();
        }
        syncCanvasWithTask(container, tasks[0]);
      }

      if (nextButton) {
        nextButton.textContent = 'Next';
        nextButton.disabled = false;
      }
      updateNavigatorState();
    };

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
      var isEditingControl = /\b(save to api|save|tasks palette|task palette|create sub-?task|new sub-?task|new task|add child|add subtask|assign instrument|assign component instance|edit instrument|edit component instance|delete|remove|change parent|set root|auto order)\b/.test(label);
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
    var testMode = toBooleanFlag(special.testMode);
    var shouldRedirect = toBooleanFlag(special.redirectOnCompletion) && returnTo !== '';
    var persistInFlight = false;
    var persistCompleted = false;
    var simulationType = String(special.simulationType || 'individual').trim().toLowerCase();
    var cohortStudents = Array.isArray(special.studentIds)
      ? special.studentIds.map(function (value) { return String(value || '').trim(); }).filter(Boolean)
      : [];
    var executionHost = document.getElementById('ctt-execution-interaction-host');
    var started = false;
    var completed = false;
    var redirected = false;
    var interruptionReported = false;
    var cohortTestMode = testMode && simulationType === 'cohort' && cohortStudents.length > 0;
    var cohortTurnIndex = 0;
    var cohortTurnFinalizing = false;
    var cohortDaUri = String(settings && settings.execution && settings.execution.daUri || '').trim();

    function reportInterruptedExecution(reason) {
      if (interruptionReported || completed || !started) {
        return;
      }

      var studyUri = String(settings && settings.studyUri || (settings && settings.execution && settings.execution.studyUri) || '').trim();
      var processUri = String(settings && settings.processUri || (settings && settings.execution && settings.execution.processUri) || '').trim();
      if (!studyUri || !processUri) {
        return;
      }

      interruptionReported = true;

      var baseUrl = String(settings && settings.drupalBaseUrl || '/');
      var endpoint = baseUrl.replace(/\/?$/, '/') + 'ctt/execution/interrupted';
      var payload = {
        studyUri: studyUri,
        processUri: processUri,
        simulationType: simulationType,
        testMode: !!testMode,
        reason: String(reason || 'Editor closed before execution completion.'),
      };
      var body = JSON.stringify(payload);

      try {
        if (navigator && typeof navigator.sendBeacon === 'function') {
          var blob = new Blob([body], { type: 'application/json' });
          if (navigator.sendBeacon(endpoint, blob)) {
            return;
          }
        }
      }
      catch (e) {
      }

      if (typeof window.fetch === 'function') {
        try {
          window.fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
            headers: {
              'Content-Type': 'application/json',
              'Accept': 'application/json',
            },
            body: body,
          }).catch(function () {
          });
        }
        catch (e) {
        }
      }
    }

    function buildCohortTurnMessage(context, status) {
      if (simulationType !== 'cohort') {
        return {
          text: '',
          state: 'neutral',
        };
      }

      var studentIds = (context && Array.isArray(context.studentIds) && context.studentIds.length)
        ? context.studentIds
        : cohortStudents;
      var completedIds = (context && Array.isArray(context.completedStudentIds))
        ? context.completedStudentIds
        : [];
      var currentStudent = String(context && context.currentStudentId || '').trim();
      var lastEndedStudent = String(context && context.lastEndedStudentId || '').trim();

      var doneCount = completedIds.length;
      var totalCount = studentIds.length;
      var baseCounter = totalCount > 0 ? ('(' + doneCount + '/' + totalCount + ' completed)') : '';

      if (status === 'ended' && (lastEndedStudent || currentStudent)) {
        var endedStudent = lastEndedStudent || currentStudent;
        return {
          text: 'Student ' + endedStudent + ' has just ended this simulator turn. ' + baseCounter,
          state: 'ended',
        };
      }

      if (currentStudent) {
        return {
          text: 'It is student ' + currentStudent + '\'s turn to use the simulator. ' + baseCounter,
          state: 'active',
        };
      }

      if (totalCount > 0 && doneCount >= totalCount) {
        return {
          text: 'All cohort student turns are completed. ' + baseCounter,
          state: 'completed',
        };
      }

      return {
        text: 'Preparing cohort simulator turns... ' + baseCounter,
        state: 'neutral',
      };
    }

    function refreshCohortTurnInstruction(status) {
      if (simulationType !== 'cohort') {
        setSpecialExecutionTurn(panel, '', 'neutral');
        return;
      }

      var context = window.__cttCohortTurnContext || {
        simulationType: simulationType,
        studentIds: cohortStudents,
        completedStudentIds: [],
        currentStudentId: cohortStudents[0] || '',
        lastEndedStudentId: '',
      };

      var message = buildCohortTurnMessage(context, status);
      setSpecialExecutionTurn(panel, message.text, message.state);
    }

    function publishCohortTurnContext(endedStudentId) {
      if (simulationType !== 'cohort') {
        return;
      }

      var completedIds = cohortStudents.slice(0, Math.max(0, cohortTurnIndex));
      var currentStudent = cohortTurnIndex < cohortStudents.length
        ? String(cohortStudents[cohortTurnIndex] || '').trim()
        : '';

      window.__cttCohortTurnContext = {
        simulationType: simulationType,
        studentIds: cohortStudents.slice(),
        completedStudentIds: completedIds,
        currentStudentId: currentStudent,
        lastEndedStudentId: String(endedStudentId || '').trim(),
      };

      if (typeof window.CustomEvent === 'function') {
        window.dispatchEvent(new CustomEvent('ctt:cohort-turn-updated', {
          detail: window.__cttCohortTurnContext,
        }));
      }
    }

    function buildSnapshotFromTrace() {
      var trace = window.__cttExecutionTrace || {};
      var byUri = trace.byUri && typeof trace.byUri === 'object' ? trace.byUri : {};
      var events = [];
      var answers = [];

      Object.keys(byUri).forEach(function (taskUri) {
        var entry = byUri[taskUri] || {};
        var startTs = String(entry.startedAt || '').trim();
        var endTs = String(entry.endedAt || '').trim();
        if (startTs) {
          events.push({
            ts: startTs,
            nodeId: taskUri,
            type: 'ctt-panel-start',
            message: 'Task started in execution panel',
          });
        }
        if (endTs) {
          events.push({
            ts: endTs,
            nodeId: taskUri,
            type: 'ctt-panel-end',
            message: 'Task ended in execution panel',
          });
        }
        if (entry.response || entry.note) {
          answers.push({
            ts: endTs || startTs || new Date().toISOString(),
            nodeId: taskUri,
            nodeLabel: String(entry.label || ''),
            kind: 'ctt-panel',
            value: String(entry.response || ''),
            displayValue: String(entry.response || ''),
            note: String(entry.note || ''),
            prompt: 'Execution panel response',
          });
        }
      });

      return {
        events: events,
        answers: answers,
      };
    }

    function persistTestExecutionSnapshot(subjectUri, snapshot) {
      if (!testMode || persistCompleted || typeof window.fetch !== 'function') {
        return Promise.resolve();
      }

      var baseUrl = String(settings && settings.drupalBaseUrl || '/');
      var saveUrl = baseUrl.replace(/\/?$/, '/') + 'ctt/execution/save';
      var studyUri = String(settings && settings.studyUri || (settings && settings.execution && settings.execution.studyUri) || '').trim();
      var processUri = String(settings && settings.processUri || (settings && settings.execution && settings.execution.processUri) || '').trim();
      if (!studyUri) {
        return Promise.resolve();
      }

      var headers = {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      };
      var csrfToken = String(settings && settings.csrfToken || '').trim();
      if (csrfToken) {
        headers['X-CSRF-Token'] = csrfToken;
      }

      var payload = {
        studyUri: studyUri,
        processUri: processUri,
        simulationType: simulationType,
        testMode: true,
        snapshot: snapshot,
        skipTraceBridge: true,
      };
      if (cohortDaUri) {
        payload.daUri = cohortDaUri;
      }
      var normalizedSubject = String(subjectUri || '').trim();
      if (normalizedSubject) {
        payload.subjectUri = normalizedSubject;
      }

      persistInFlight = true;
      return window.fetch(saveUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: headers,
        body: JSON.stringify(payload),
      }).then(function (response) {
        return response.json().catch(function () {
          return null;
        });
      }).then(function (resultPayload) {
        if (resultPayload && resultPayload.ok === true && resultPayload.daUri) {
          cohortDaUri = String(resultPayload.daUri || '').trim();
          settings.execution = settings.execution || {};
          settings.execution.daUri = cohortDaUri;
        }
      }).catch(function () {
      }).finally(function () {
        persistInFlight = false;
      });
    }

    function resetPanelForNextCohortStudent() {
      if (typeof panel.__cttTaskNavReset === 'function') {
        panel.__cttTaskNavReset();
      }
      else {
        window.__cttExecutionTrace = {
          byUri: {},
        };
      }
    }

    function finalizeExecutionFlow() {
      if (completed) {
        return;
      }

      completed = true;
      persistCompleted = true;
      setSpecialExecutionStatus(panel, 'Execution completed. Returning to Process Executions...', 'success');
      refreshCohortTurnInstruction('ended');

      container.style.display = 'none';
      container.setAttribute('aria-hidden', 'true');

      if (!shouldRedirect || redirected) {
        return;
      }

      redirected = true;
      window.setTimeout(function () {
        window.location.assign(returnTo);
      }, 1300);
    }

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
      testMode: testMode,
      testManualFailureRate: special.testManualFailureRate,
      testManualDelayMinMs: special.testManualDelayMinMs,
      testManualDelayMaxMs: special.testManualDelayMaxMs,
    });
    if (panel.__cttTaskNavUpdate) {
      panel.__cttTaskNavUpdate();
    }

    function markCompleted() {
      if (completed || cohortTurnFinalizing) {
        return;
      }

      if (cohortTestMode) {
        var currentStudentId = String(cohortStudents[cohortTurnIndex] || '').trim();
        var nextStudentId = String(cohortStudents[cohortTurnIndex + 1] || '').trim();
        var snapshot = buildSnapshotFromTrace();

        var hasTurnActivity = (Array.isArray(snapshot.events) && snapshot.events.length > 0)
          || (Array.isArray(snapshot.answers) && snapshot.answers.length > 0);
        if (!hasTurnActivity) {
          setSpecialExecutionStatus(panel, 'Waiting for real task activity before finalizing ' + (currentStudentId || ('student ' + (cohortTurnIndex + 1))) + '...', 'running');
          return;
        }

        cohortTurnFinalizing = true;
        setSpecialExecutionStatus(panel, 'Finalizing cohort turn for ' + (currentStudentId || ('student ' + (cohortTurnIndex + 1))) + '...', 'running');

        persistTestExecutionSnapshot(currentStudentId, snapshot).finally(function () {
          cohortTurnIndex += 1;
          publishCohortTurnContext(currentStudentId);
          refreshCohortTurnInstruction('ended');

          if (cohortTurnIndex < cohortStudents.length) {
            var label = nextStudentId || ('student ' + (cohortTurnIndex + 1));
            setSpecialExecutionStatus(panel, 'Starting cohort turn for ' + label + '...', 'running');

            resetPanelForNextCohortStudent();
            started = false;
            cohortTurnFinalizing = false;

            var resetButton = findButtonByLabels(executionHost || container, ['reset test', 'clear']);
            if (resetButton && !resetButton.disabled && resetButton.getAttribute('aria-disabled') !== 'true') {
              resetButton.click();
            }

            window.setTimeout(function () {
              tryAutoStart();
              monitorExecutionState();
            }, 320);
            return;
          }

          finalizeExecutionFlow();
        });
        return;
      }

      var singleSnapshot = buildSnapshotFromTrace();
      persistTestExecutionSnapshot('', singleSnapshot).finally(function () {
        finalizeExecutionFlow();
      });
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
        testMode: testMode,
        testManualFailureRate: special.testManualFailureRate,
        testManualDelayMinMs: special.testManualDelayMinMs,
        testManualDelayMaxMs: special.testManualDelayMaxMs,
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
    refreshCohortTurnInstruction('active');

    if (simulationType === 'cohort') {
      window.addEventListener('ctt:cohort-turn-updated', function () {
        refreshCohortTurnInstruction('active');
      });
    }

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
      reportInterruptedExecution('Editor unload detected before completion.');
      observer.disconnect();
      window.clearInterval(intervalId);
    }, { once: true });

    window.addEventListener('pagehide', function () {
      reportInterruptedExecution('Editor page hidden before completion.');
    }, { once: true });
  }

  /**
   * Bridge for global tooltip toggles in the React editor.
   *
   * Some controls request "show all tooltips", but tooltip panels are rendered
   * with hover-only utility classes (hidden + group-hover:block). This helper
   * force-shows those panels when the toggle is active and restores default
   * hover behavior when disabled.
   */
  function installTooltipVisibilityBridge(container) {
    if (!container || container.__cttTooltipBridgeInstalled) {
      return;
    }
    container.__cttTooltipBridgeInstalled = true;

    var showAll = false;
    var debugEnabled = false;
    var debugBadge = null;

    try {
      var params = new URLSearchParams(String(window.location.search || ''));
      var dbg = String(params.get('tooltip_debug') || '').toLowerCase();
      debugEnabled = (dbg === '1' || dbg === 'true' || dbg === 'yes');
    }
    catch (e) {
      debugEnabled = false;
    }

    function ensureDebugBadge() {
      if (!debugEnabled) {
        return null;
      }
      if (debugBadge && debugBadge.parentNode) {
        return debugBadge;
      }

      debugBadge = document.createElement('div');
      debugBadge.setAttribute('id', 'ctt-tooltip-debug-badge');
      debugBadge.style.cssText = [
        'position:fixed',
        'right:12px',
        'bottom:12px',
        'z-index:2147483647',
        'max-width:360px',
        'padding:8px 10px',
        'border:1px solid #f59e0b',
        'background:#fffbeb',
        'color:#7c2d12',
        'font:12px/1.3 monospace',
        'border-radius:8px',
        'box-shadow:0 4px 12px rgba(0,0,0,.15)',
        'white-space:pre-wrap'
      ].join(';') + ';';

      var host = document.body || container;
      host.appendChild(debugBadge);
      return debugBadge;
    }

    function setDebugText(reason) {
      if (!debugEnabled) {
        return;
      }
      var badge = ensureDebugBadge();
      if (!badge) {
        return;
      }
      var panelCount = getTooltipPanels().length;
      badge.textContent = [
        '[tooltip-debug]',
        'reason=' + String(reason || 'n/a'),
        'showAll=' + (showAll ? 'true' : 'false'),
        'panels=' + panelCount,
        'time=' + new Date().toLocaleTimeString()
      ].join('\n');
    }

    function getTooltipPanels() {
      var root = (document && document.body) ? document.body : container;
      return Array.prototype.slice.call(
        root.querySelectorAll(
          [
            'div[class*="group-hover:block"]',
            'div[class*="group-focus-within:block"]',
            'div[class*="group-hover:opacity-"]',
            'div[class*="group-focus-within:opacity-"]',
            'div[class*="group-hover:visible"]',
            'div[class*="group-focus-within:visible"]',
            '[role="tooltip"]',
            '[class*="tooltip"]',
            '[data-tooltip]',
            '[data-tip]'
          ].join(',')
        )
      );
    }

    function applyVisibility() {
      var panels = getTooltipPanels();
      panels.forEach(function (panel) {
        if (!(panel instanceof HTMLElement)) {
          return;
        }

        if (showAll) {
          if (!panel.hasAttribute('data-ctt-tooltip-prev-display')) {
            panel.setAttribute('data-ctt-tooltip-prev-display', panel.style.display || '');
          }
          if (!panel.hasAttribute('data-ctt-tooltip-prev-opacity')) {
            panel.setAttribute('data-ctt-tooltip-prev-opacity', panel.style.opacity || '');
          }
          if (!panel.hasAttribute('data-ctt-tooltip-prev-visibility')) {
            panel.setAttribute('data-ctt-tooltip-prev-visibility', panel.style.visibility || '');
          }
          if (!panel.hasAttribute('data-ctt-tooltip-prev-pointer-events')) {
            panel.setAttribute('data-ctt-tooltip-prev-pointer-events', panel.style.pointerEvents || '');
          }
          if (!panel.hasAttribute('data-ctt-tooltip-had-hidden')) {
            panel.setAttribute('data-ctt-tooltip-had-hidden', panel.classList.contains('hidden') ? '1' : '0');
          }
          if (!panel.hasAttribute('data-ctt-tooltip-had-invisible')) {
            panel.setAttribute('data-ctt-tooltip-had-invisible', panel.classList.contains('invisible') ? '1' : '0');
          }

          panel.classList.remove('hidden');
          panel.classList.remove('invisible');
          panel.style.display = 'block';
          panel.style.pointerEvents = 'auto';
          panel.style.opacity = '1';
          panel.style.visibility = 'visible';
        }
        else {
          var hadHidden = panel.getAttribute('data-ctt-tooltip-had-hidden') === '1';
          var hadInvisible = panel.getAttribute('data-ctt-tooltip-had-invisible') === '1';
          var prevDisplay = panel.getAttribute('data-ctt-tooltip-prev-display');
          var prevOpacity = panel.getAttribute('data-ctt-tooltip-prev-opacity');
          var prevVisibility = panel.getAttribute('data-ctt-tooltip-prev-visibility');
          var prevPointerEvents = panel.getAttribute('data-ctt-tooltip-prev-pointer-events');

          if (hadHidden) {
            panel.classList.add('hidden');
          }
          if (hadInvisible) {
            panel.classList.add('invisible');
          }
          panel.style.display = (typeof prevDisplay === 'string') ? prevDisplay : '';
          panel.style.opacity = (typeof prevOpacity === 'string') ? prevOpacity : '';
          panel.style.visibility = (typeof prevVisibility === 'string') ? prevVisibility : '';
          panel.style.pointerEvents = (typeof prevPointerEvents === 'string') ? prevPointerEvents : '';

          panel.removeAttribute('data-ctt-tooltip-prev-display');
          panel.removeAttribute('data-ctt-tooltip-prev-opacity');
          panel.removeAttribute('data-ctt-tooltip-prev-visibility');
          panel.removeAttribute('data-ctt-tooltip-prev-pointer-events');
          panel.removeAttribute('data-ctt-tooltip-had-hidden');
          panel.removeAttribute('data-ctt-tooltip-had-invisible');
        }
      });

      setDebugText('applyVisibility');
    }

    function classifyTooltipIntent(button) {
      if (!button) {
        return null;
      }
      var txt = String(button.textContent || '').trim().toLowerCase();
      var title = String(button.getAttribute('title') || '').trim().toLowerCase();
      var label = String(button.getAttribute('aria-label') || '').trim().toLowerCase();
      var merged = (txt + ' ' + title + ' ' + label).replace(/\s+/g, ' ').trim();

      if (!merged) {
        return null;
      }

      var hasTooltipWord = merged.indexOf('tooltip') !== -1 || merged.indexOf('tips') !== -1 || merged.indexOf('help') !== -1;
      var hasShowAllWord = merged.indexOf('show all') !== -1;
      var hasHideAllWord = merged.indexOf('hide all') !== -1;

      // Accept generic "show all" / "hide all" controls in task panels,
      // even when the label does not include the word "tooltip".
      if (hasShowAllWord) {
        return 'show';
      }
      if (hasHideAllWord) {
        return 'hide';
      }

      if (!hasTooltipWord) {
        return null;
      }

      if (merged.indexOf('show') !== -1) {
        return 'show';
      }
      if (merged.indexOf('hide') !== -1) {
        return 'hide';
      }
      return 'toggle';
    }

    function inferIntentFromInputControl(inputEl) {
      if (!(inputEl instanceof HTMLElement)) {
        return null;
      }

      var aria = String(inputEl.getAttribute('aria-label') || '').toLowerCase();
      var title = String(inputEl.getAttribute('title') || '').toLowerCase();
      var id = String(inputEl.id || '').trim();
      var labelText = '';

      if (id) {
        var labelEl = container.querySelector('label[for="' + id.replace(/"/g, '\\"') + '"]');
        if (labelEl) {
          labelText = String(labelEl.textContent || '').toLowerCase();
        }
      }

      var merged = [aria, title, labelText, String(inputEl.textContent || '').toLowerCase()].join(' ').trim();
      if (merged.indexOf('tooltip') === -1 && merged.indexOf('tips') === -1 && merged.indexOf('help') === -1) {
        return null;
      }

      if (inputEl instanceof HTMLInputElement && inputEl.type === 'checkbox') {
        return inputEl.checked ? 'show' : 'hide';
      }

      var role = String(inputEl.getAttribute('role') || '').toLowerCase();
      if (role === 'switch') {
        var checkedAttr = String(inputEl.getAttribute('aria-checked') || '').toLowerCase();
        return checkedAttr === 'true' ? 'show' : 'hide';
      }

      return 'toggle';
    }

    function findSiblingTooltipPanel(button) {
      if (!button || !(button instanceof Element)) {
        return null;
      }

      var group = button.closest('.group');
      if (!group) {
        return null;
      }

      var panel = group.querySelector('div[class*="group-hover:block"], div[class*="group-focus-within:block"]');
      return panel instanceof HTMLElement ? panel : null;
    }

    function setPanelVisible(panel, visible) {
      if (!panel) {
        return;
      }

      if (visible) {
        if (!panel.hasAttribute('data-ctt-tooltip-prev-display')) {
          panel.setAttribute('data-ctt-tooltip-prev-display', panel.style.display || '');
        }
        if (!panel.hasAttribute('data-ctt-tooltip-prev-opacity')) {
          panel.setAttribute('data-ctt-tooltip-prev-opacity', panel.style.opacity || '');
        }
        if (!panel.hasAttribute('data-ctt-tooltip-prev-visibility')) {
          panel.setAttribute('data-ctt-tooltip-prev-visibility', panel.style.visibility || '');
        }
        if (!panel.hasAttribute('data-ctt-tooltip-prev-pointer-events')) {
          panel.setAttribute('data-ctt-tooltip-prev-pointer-events', panel.style.pointerEvents || '');
        }
        if (!panel.hasAttribute('data-ctt-tooltip-had-hidden')) {
          panel.setAttribute('data-ctt-tooltip-had-hidden', panel.classList.contains('hidden') ? '1' : '0');
        }
        if (!panel.hasAttribute('data-ctt-tooltip-had-invisible')) {
          panel.setAttribute('data-ctt-tooltip-had-invisible', panel.classList.contains('invisible') ? '1' : '0');
        }
        panel.classList.remove('hidden');
        panel.classList.remove('invisible');
        panel.style.display = 'block';
        panel.style.pointerEvents = 'auto';
        panel.style.opacity = '1';
        panel.style.visibility = 'visible';
      }
      else {
        var hadHidden = panel.getAttribute('data-ctt-tooltip-had-hidden') === '1';
        var hadInvisible = panel.getAttribute('data-ctt-tooltip-had-invisible') === '1';
        var prevDisplay = panel.getAttribute('data-ctt-tooltip-prev-display');
        var prevOpacity = panel.getAttribute('data-ctt-tooltip-prev-opacity');
        var prevVisibility = panel.getAttribute('data-ctt-tooltip-prev-visibility');
        var prevPointerEvents = panel.getAttribute('data-ctt-tooltip-prev-pointer-events');
        if (hadHidden) {
          panel.classList.add('hidden');
        }
        if (hadInvisible) {
          panel.classList.add('invisible');
        }
        panel.style.display = (typeof prevDisplay === 'string') ? prevDisplay : '';
        panel.style.opacity = (typeof prevOpacity === 'string') ? prevOpacity : '';
        panel.style.visibility = (typeof prevVisibility === 'string') ? prevVisibility : '';
        panel.style.pointerEvents = (typeof prevPointerEvents === 'string') ? prevPointerEvents : '';
        panel.removeAttribute('data-ctt-tooltip-prev-display');
        panel.removeAttribute('data-ctt-tooltip-prev-opacity');
        panel.removeAttribute('data-ctt-tooltip-prev-visibility');
        panel.removeAttribute('data-ctt-tooltip-prev-pointer-events');
        panel.removeAttribute('data-ctt-tooltip-had-hidden');
        panel.removeAttribute('data-ctt-tooltip-had-invisible');
      }
    }

    container.addEventListener('click', function (event) {
      var target = event.target;
      if (!(target instanceof Element)) {
        return;
      }
      var button = target.closest('button');
      if (!button) {
        return;
      }

      // Direct click behavior for small info/guide buttons.
      var ariaLabel = String(button.getAttribute('aria-label') || '').trim().toLowerCase();
      var title = String(button.getAttribute('title') || '').trim().toLowerCase();
      var iconText = String(button.textContent || '').trim().toLowerCase();
      var isGuideButton = ariaLabel.indexOf('guide') !== -1
        || ariaLabel.indexOf('rule') !== -1
        || ariaLabel.indexOf('tooltip') !== -1
        || title.indexOf('guide') !== -1
        || title.indexOf('rule') !== -1
        || title.indexOf('tooltip') !== -1
        || iconText === 'i';

      if (isGuideButton) {
        var panel = findSiblingTooltipPanel(button);
        if (panel) {
          var currentlyVisible = getComputedStyle(panel).display !== 'none' && !panel.classList.contains('hidden');
          setPanelVisible(panel, !currentlyVisible);
          setDebugText('guide-button:' + (currentlyVisible ? 'hide' : 'show'));
          event.preventDefault();
          event.stopPropagation();
          return;
        }
      }

      var intent = classifyTooltipIntent(button);
      if (!intent) {
        return;
      }

      if (intent === 'show') {
        showAll = true;
      }
      else if (intent === 'hide') {
        showAll = false;
      }
      else {
        showAll = !showAll;
      }

      setDebugText('click-intent:' + intent);

      // Let React update labels first, then enforce visibility.
      window.setTimeout(applyVisibility, 0);
    }, true);

    container.addEventListener('change', function (event) {
      var target = event.target;
      if (!(target instanceof Element)) {
        return;
      }

      var inputControl = target.closest('input, [role="switch"]');
      if (!inputControl) {
        return;
      }

      var intent = inferIntentFromInputControl(inputControl);
      if (!intent) {
        return;
      }

      if (intent === 'show') {
        showAll = true;
      }
      else if (intent === 'hide') {
        showAll = false;
      }
      else {
        showAll = !showAll;
      }

      setDebugText('change-intent:' + intent);

      window.setTimeout(applyVisibility, 0);
    }, true);

    var observerTarget = (document && document.body) ? document.body : container;
    var observer = new MutationObserver(function () {
      if (showAll) {
        applyVisibility();
      }
    });
    observer.observe(observerTarget, {
      childList: true,
      subtree: true,
    });

    window.addEventListener('beforeunload', function () {
      observer.disconnect();
    }, { once: true });

    setDebugText('bridge-installed');
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
        installExecutionSaveTraceBridge(settings);
        installInstrumentSelectionContextBridge(settings);
        installEditModeSimulatorAssignmentBridge(container, settings);
        installSpecialExecutionMode(container, settings);
        installTooltipVisibilityBridge(container);
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
