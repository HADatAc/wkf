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
        settings.hascoApiUrl = '/workflow';
        drupalSettings.ctt = drupalSettings.ctt || {};
        drupalSettings.ctt.hascoApiUrl = '/workflow';
        var maxAttempts = 50;
        var attempt = 0;

        // Force full usable viewport for embedded mode.
        container.style.width = '100%';
        container.style.maxWidth = '100%';
        container.style.minHeight = 'calc(100vh - 150px)';
        container.style.height = 'calc(100vh - 150px)';

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

    // Ensure React root wrapper also fills the editor area.
    container.style.overflow = 'hidden';

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

    if (window.HASCOWorkflowEditor && typeof window.HASCOWorkflowEditor.mountWorkflowEditor === 'function') {
      window.HASCOWorkflowEditor.mountWorkflowEditor(container, {
        apiClient: null,
        processUri: settings.processUri || null,
        collaboration: {
          enabled: false,
        },
      });
    } else {
      container.innerHTML = '<p style="color:orange;">CTT Editor loaded. Initializing…</p>';
      console.warn('[CTT Editor] mountWorkflowEditor not found in UMD bundle.');
    }
  }

})(Drupal, drupalSettings, once);
