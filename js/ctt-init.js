/**
 * @file
 * CTT Editor initialization - Drupal behavior that bootstraps the full React App.
 *
 * Reads configuration from drupalSettings.ctt and mounts the
 * complete CTT application (same canvas as standalone) into #ctt-workflow-app.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.cttEditorInit = {
    attach: function (context) {
      once('ctt-editor-init', '#ctt-workflow-app', context).forEach(function (container) {
        var settings = drupalSettings.ctt || {};
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

        var maxAttempts = 50;
        var attempt = 0;

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

          var availableHeight = Math.max(420, Math.floor(footerTop - containerTop));
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
            mountEditor(container);
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
  function mountEditor(container) {
    // Remove loading indicator.
    container.innerHTML = '';
    container.style.overflow = 'hidden';

    var lib = window.HASCOWorkflowEditor || window.HascoWorkflowEditor || window.hascoWorkflowEditor;

    if (typeof lib.mountApp === 'function') {
      lib.mountApp(container);
    } else if (typeof lib.mountWorkflowEditor === 'function') {
      lib.mountWorkflowEditor(container, {});
    } else {
      container.innerHTML = '<p style="color:orange;">CTT Editor UMD loaded but no mount function found.</p>';
      console.error('[CTT Editor] No mount function found in UMD bundle.');
    }
  }

})(Drupal, drupalSettings, once);
