/**
 * @file
 * CTT Editor initialization — Drupal behavior that bootstraps the full React App.
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

        function applyContainerSize() {
          var rect = container.getBoundingClientRect();
          var top = Math.max(0, rect.top);
          var viewportHeight = window.innerHeight || document.documentElement.clientHeight || 800;
          var nextHeight = Math.max(420, Math.floor(viewportHeight - top));
          container.style.height = nextHeight + 'px';
          container.style.minHeight = nextHeight + 'px';
        }

        applyContainerSize();
        window.addEventListener('resize', applyContainerSize, { passive: true });
        // Re-apply after toolbar/admin bars settle.
        setTimeout(applyContainerSize, 50);
        setTimeout(applyContainerSize, 250);

        /**
         * Wait for the UMD bundle to expose the global HASCOWorkflowEditor.
         */
        function waitForEditor() {
          attempt++;
          if (typeof window.HASCOWorkflowEditor !== 'undefined' &&
              (window.HASCOWorkflowEditor.mountApp || window.HASCOWorkflowEditor.mountWorkflowEditor)) {
            try {
              console.log('[CTT Editor] UMD global present:', {
                hasGlobal: typeof window.HASCOWorkflowEditor !== 'undefined',
                hasMountApp: typeof window.HASCOWorkflowEditor.mountApp === 'function',
                hasMountWorkflowEditor: typeof window.HASCOWorkflowEditor.mountWorkflowEditor === 'function'
              });
            } catch (e) {}
            mountEditor(container);
          } else if (attempt < maxAttempts) {
            setTimeout(waitForEditor, 200);
          } else {
            container.innerHTML = '<p style="color:red;">Failed to load CTT editor. Check the browser console for errors.</p>';
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

    var lib = window.HASCOWorkflowEditor;

    if (typeof lib.mountApp === 'function') {
      console.log('[CTT Editor] Mounting full App (same as standalone).');
      lib.mountApp(container);
    } else if (typeof lib.mountWorkflowEditor === 'function') {
      console.warn('[CTT Editor] mountApp not found, falling back to mountWorkflowEditor.');
      lib.mountWorkflowEditor(container, {});
    } else {
      container.innerHTML = '<p style="color:orange;">CTT Editor UMD loaded but no mount function found.</p>';
      console.error('[CTT Editor] No mount function found in UMD bundle.');
    }
  }

})(Drupal, drupalSettings, once);
