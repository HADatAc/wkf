/**
 * @file
 * CTT Editor initialization — Drupal behavior that bootstraps the React app.
 *
 * Reads configuration from drupalSettings.ctt and mounts the
 * HASCOWorkflowEditor component into #ctt-workflow-app.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.cttEditorInit = {
    attach: function (context) {
      once('ctt-editor-init', '#ctt-workflow-app', context).forEach(function (container) {
        var settings = drupalSettings.ctt || {};
        var maxAttempts = 50;
        var attempt = 0;

        /**
         * Wait for the UMD bundle to expose the global HASCOWorkflowEditor.
         */
        function waitForEditor() {
          attempt++;
          if (typeof window.HASCOWorkflowEditor !== 'undefined' && window.HASCOWorkflowEditor.WorkflowEditor) {
            mountEditor(container, settings);
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
   * Mount the React workflow editor into the container.
   */
  function mountEditor(container, settings) {
    // Remove loading indicator
    container.innerHTML = '';

    // Build configuration from drupalSettings
    var config = {
      apiBaseUrl: settings.apiBaseUrl || '/workflow/api',
      hascoApiUrl: settings.hascoApiUrl || 'http://localhost:9000',
      csrfToken: settings.csrfToken || '',
      currentUser: settings.currentUser || { id: '0', name: 'Anonymous', email: '' },
      processUri: settings.processUri || null,
      drupalLinks: {
        createInstrument: settings.drupalLinks ? settings.drupalLinks.createInstrument : '/sir/manage/addinstrument',
        createWorkflow: settings.drupalLinks ? settings.drupalLinks.createWorkflow : '/std/manage/addworkflow/active',
        manageInstruments: settings.drupalLinks ? settings.drupalLinks.manageInstruments : '/sir/manage/instruments',
      }
    };

    // Log configuration for debugging
    if (drupalSettings.path && drupalSettings.path.isFront !== undefined) {
      console.log('[CTT Editor] Drupal mode detected. Config:', config);
    }

    // React 18+ createRoot API
    if (window.React && window.ReactDOM && window.ReactDOM.createRoot) {
      var root = window.ReactDOM.createRoot(container);
      var EditorComponent = window.HASCOWorkflowEditor.WorkflowEditor;

      root.render(
        window.React.createElement(EditorComponent, {
          apiClient: null, // Will auto-detect Drupal and use DrupalAdapter
          collaboration: {
            enabled: false
          }
        })
      );
    } else {
      // Fallback: the UMD bundle should have its own React bundled
      container.innerHTML = '<p style="color:orange;">CTT Editor loaded. Initializing…</p>';
      console.warn('[CTT Editor] React/ReactDOM not found globally. The UMD bundle should include them.');
    }
  }

})(Drupal, drupalSettings, once);
