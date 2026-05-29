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

  function enableReadOnlyPreview(container) {
    if (!container || container.__cttReadOnlyPreviewBound) {
      return;
    }

    container.__cttReadOnlyPreviewBound = true;
    container.setAttribute('data-ctt-readonly-preview', '1');

    container.addEventListener('click', function (event) {
      var target = event.target && event.target.closest
        ? event.target.closest('button, [role="button"], a')
        : null;
      if (!target) {
        return;
      }
      if (isExecutionActionLabel(normalizeControlText(target))) {
        event.preventDefault();
        event.stopPropagation();
        if (typeof event.stopImmediatePropagation === 'function') {
          event.stopImmediatePropagation();
        }
      }
    }, true);

    hideExecutionActionControls(container);

    var observer = new MutationObserver(function () {
      hideExecutionActionControls(container);
    });
    observer.observe(container, {
      childList: true,
      subtree: true,
      attributes: true,
      characterData: true
    });

    [120, 350, 900, 1600].forEach(function (delay) {
      setTimeout(function () {
        hideExecutionActionControls(container);
      }, delay);
    });
  }

  Drupal.behaviors.cttEditorInit = {
    attach: function (context) {
      once('ctt-editor-init', '#ctt-workflow-app', context).forEach(function (container) {
        var settings = drupalSettings.ctt || {};
        var readOnlyPreview = toBooleanFlag(settings.readOnlyPreview) || toBooleanFlag(settings.execution && settings.execution.readOnlyPreview);
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

        /**
         * Wait for the UMD bundle to expose the global HASCOWorkflowEditor.
         */
        function waitForEditor() {
          attempt++;
          // Support older/newer UMD global names.
          var umdGlobal = window.HASCOWorkflowEditor || window.HascoWorkflowEditor || window.hascoWorkflowEditor;
          if (typeof umdGlobal !== 'undefined' &&
              (umdGlobal.mountApp || umdGlobal.mountWorkflowEditor)) {
            mountEditor(container, readOnlyPreview);
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
  function mountEditor(container, readOnlyPreview) {
    // Remove loading indicator.
    container.innerHTML = '';
    container.style.overflow = 'hidden';

    var lib = window.HASCOWorkflowEditor || window.HascoWorkflowEditor || window.hascoWorkflowEditor;

    if (typeof lib.mountApp === 'function') {
      lib.mountApp(container);
      if (readOnlyPreview) {
        enableReadOnlyPreview(container);
      }
    } else if (typeof lib.mountWorkflowEditor === 'function') {
      lib.mountWorkflowEditor(container, {});
      if (readOnlyPreview) {
        enableReadOnlyPreview(container);
      }
    } else {
      container.innerHTML = '<p style="color:orange;">CTT Editor UMD loaded but no mount function found.</p>';
      console.error('[CTT Editor] No mount function found in UMD bundle.');
    }
  }

})(Drupal, drupalSettings, once);
