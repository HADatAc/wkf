(function (Drupal, once, drupalSettings) {
  "use strict";

  const escapeHtml = function (value) {
    return String(value || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/\"/g, "&quot;")
      .replace(/'/g, "&#39;");
  };

  const isHttpUri = function (value) {
    return /^https?:\/\//i.test(String(value || "").trim());
  };

  const parseResponsePayload = async function (response) {
    const contentType = String(response.headers.get("content-type") || "").toLowerCase();
    if (contentType.indexOf("application/json") !== -1) {
      return response.json();
    }

    const text = await response.text();
    try {
      return JSON.parse(text);
    } catch {
      return { raw: text };
    }
  };

  const firstIssueMessage = function (payload, fallback) {
    if (payload && Array.isArray(payload.issues) && payload.issues.length > 0) {
      const first = payload.issues[0];
      if (first && typeof first.message === "string" && first.message.trim() !== "") {
        return first.message.trim();
      }
    }
    return fallback;
  };

  const setFeedback = function (state, type, message) {
    if (!state.feedback) {
      return;
    }

    if (!message) {
      state.feedback.className = "alert d-none";
      state.feedback.textContent = "";
      return;
    }

    let alertClass = "alert-info";
    if (type === "success") {
      alertClass = "alert-success";
    } else if (type === "error") {
      alertClass = "alert-danger";
    } else if (type === "warning") {
      alertClass = "alert-warning";
    }

    state.feedback.className = "alert " + alertClass;
    state.feedback.textContent = message;
  };

  const summarizeUris = function (label, values, key) {
    const list = Array.isArray(values) ? values : [];
    if (list.length === 0) {
      return "<li><strong>" + escapeHtml(label) + ":</strong> 0</li>";
    }

    const preview = list.slice(0, 3).map(function (item) {
      if (item && typeof item === "object") {
        return String(item[key] || item.uri || item.filename || "").trim();
      }
      return String(item || "").trim();
    }).filter(function (value) {
      return value !== "";
    });

    let suffix = "";
    if (list.length > preview.length) {
      suffix = " (+" + (list.length - preview.length) + " more)";
    }

    return "<li><strong>" + escapeHtml(label) + ":</strong> "
      + list.length
      + (preview.length > 0 ? "<div class=\"ctt-r-uri-preview\">" + escapeHtml(preview.join(" | ")) + suffix + "</div>" : "")
      + "</li>";
  };

  const renderAssociationsSummary = function (state, associations) {
    if (!state.contextSummary) {
      return;
    }

    if (!associations || typeof associations !== "object") {
      state.contextSummary.className = "ctt-r-context-summary text-warning";
      state.contextSummary.textContent = "Unable to resolve association context for this study.";
      return;
    }

    const datasets = Array.isArray(associations.datasets) ? associations.datasets : [];
    const variables = Array.isArray(associations.variables) ? associations.variables : [];
    const images = Array.isArray(associations.images) ? associations.images : [];

    const html = ""
      + "<ul class=\"ctt-r-context-list\">"
      + summarizeUris("Datasets", datasets, "uri")
      + summarizeUris("Variables", variables, "uri")
      + summarizeUris("Medical Images", images, "filename")
      + "</ul>";

    state.contextSummary.className = "ctt-r-context-summary";
    state.contextSummary.innerHTML = html;
  };

  const populateToolOptions = function (state, tools) {
    if (!state.toolUri) {
      return;
    }

    const normalized = Array.isArray(tools) ? tools.filter(function (tool) {
      const language = String(tool && tool.language || "").trim().toLowerCase();
      return language === "r";
    }) : [];

    normalized.sort(function (a, b) {
      const aAssociated = a && a.isAssociated ? 1 : 0;
      const bAssociated = b && b.isAssociated ? 1 : 0;
      if (aAssociated !== bAssociated) {
        return bAssociated - aAssociated;
      }
      const aName = String(a && a.name || "");
      const bName = String(b && b.name || "");
      return aName.localeCompare(bName);
    });

    state.toolsByUri = {};
    let options = '<option value="">Select one R tool</option>';
    normalized.forEach(function (tool) {
      const uri = String(tool.toolUri || "").trim();
      if (uri === "") {
        return;
      }
      state.toolsByUri[uri] = tool;

      const labelParts = [String(tool.name || "Unnamed tool").trim()];
      const version = String(tool.version || "").trim();
      if (version !== "") {
        labelParts.push("v" + version);
      }
      if (tool.isAssociated) {
        labelParts.push("associated");
      }

      options += '<option value="' + escapeHtml(uri) + '">' + escapeHtml(labelParts.join(" | ")) + "</option>";
    });

    state.toolUri.innerHTML = options;
  };

  const parseArguments = function (rawValue) {
    const source = String(rawValue || "").trim();
    if (source === "") {
      return { ok: true, value: {} };
    }

    try {
      const parsed = JSON.parse(source);
      if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) {
        return {
          ok: false,
          message: "Arguments JSON must be an object (not an array).",
        };
      }
      return { ok: true, value: parsed };
    } catch {
      return {
        ok: false,
        message: "Arguments JSON is invalid.",
      };
    }
  };

  const setOutputPayload = function (state, payload) {
    if (!state.output) {
      return;
    }

    try {
      state.output.textContent = JSON.stringify(payload, null, 2);
    } catch {
      state.output.textContent = String(payload || "");
    }
  };

  const loadRealContext = async function (state) {
    const studyUri = String(state.studyUri && state.studyUri.value || "").trim();
    if (!isHttpUri(studyUri)) {
      setFeedback(state, "warning", "Provide a valid Study URI before loading context.");
      return;
    }

    if (!state.toolsEndpoint || !state.associationsEndpoint) {
      setFeedback(state, "error", "R analysis endpoints are not configured in drupalSettings.");
      return;
    }

    setFeedback(state, "", "");

    try {
      const toolsParams = new URLSearchParams();
      toolsParams.set("language", "R");
      toolsParams.set("studyUri", studyUri);
      toolsParams.set("limit", "200");
      toolsParams.set("offset", "0");

      const toolsUrl = state.toolsEndpoint + (state.toolsEndpoint.indexOf("?") === -1 ? "?" : "&") + toolsParams.toString();
      const toolsResponse = await fetch(toolsUrl, {
        method: "GET",
        credentials: "same-origin",
      });
      const toolsPayload = await parseResponsePayload(toolsResponse);
      if (!toolsResponse.ok || !toolsPayload || toolsPayload.isSuccessful === false) {
        const message = firstIssueMessage(toolsPayload, "Unable to load R tools from repository.");
        setFeedback(state, "error", message);
        return;
      }

      const tools = Array.isArray(toolsPayload.body) ? toolsPayload.body : [];
      populateToolOptions(state, tools);

      const associationsParams = new URLSearchParams();
      associationsParams.set("studyUri", studyUri);
      const associationsUrl = state.associationsEndpoint
        + (state.associationsEndpoint.indexOf("?") === -1 ? "?" : "&")
        + associationsParams.toString();

      const associationsResponse = await fetch(associationsUrl, {
        method: "GET",
        credentials: "same-origin",
      });
      const associationsPayload = await parseResponsePayload(associationsResponse);
      if (!associationsResponse.ok || !associationsPayload || associationsPayload.isValid === false) {
        const message = firstIssueMessage(associationsPayload, "Unable to load study associations.");
        setFeedback(state, "error", message);
        return;
      }

      state.currentAssociations = associationsPayload.associations || {};
      renderAssociationsSummary(state, state.currentAssociations);

      if (Object.keys(state.toolsByUri).length === 0) {
        setFeedback(state, "warning", "No R tools were found for the selected study context.");
      } else {
        setFeedback(state, "success", "Real context loaded: R tools and study associations are ready.");
      }
    } catch {
      setFeedback(state, "error", "Failed to load real context data.");
    }
  };

  const runAnalysis = async function (state) {
    const studyUri = String(state.studyUri && state.studyUri.value || "").trim();
    const processUri = String(state.processUri && state.processUri.value || "").trim();
    const toolUri = String(state.toolUri && state.toolUri.value || "").trim();
    const entrypoint = String(state.entrypoint && state.entrypoint.value || "").trim();

    if (!isHttpUri(studyUri)) {
      setFeedback(state, "warning", "Provide a valid Study URI.");
      return;
    }
    if (!isHttpUri(processUri)) {
      setFeedback(state, "warning", "Provide a valid Process URI.");
      return;
    }
    if (!isHttpUri(toolUri)) {
      setFeedback(state, "warning", "Select a valid R tool URI from repository context.");
      return;
    }
    if (!state.executeEndpoint) {
      setFeedback(state, "error", "Execution endpoint is not configured.");
      return;
    }

    const parsedArguments = parseArguments(state.argumentsJson && state.argumentsJson.value || "");
    if (!parsedArguments.ok) {
      setFeedback(state, "warning", parsedArguments.message || "Invalid arguments JSON.");
      return;
    }

    const requestPayload = {
      studyUri: studyUri,
      processUri: processUri,
      toolUri: toolUri,
      arguments: parsedArguments.value,
    };
    if (entrypoint !== "") {
      requestPayload.entrypoint = entrypoint;
    }

    if (state.runButton) {
      state.runButton.disabled = true;
    }

    try {
      const response = await fetch(state.executeEndpoint, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
        },
        body: JSON.stringify(requestPayload),
      });

      const payload = await parseResponsePayload(response);
      setOutputPayload(state, payload);

      if (response.ok && payload && payload.isSuccessful === true) {
        setFeedback(state, "success", "R analysis executed with real backend response.");
      } else {
        const message = firstIssueMessage(payload, "R analysis execution failed.");
        setFeedback(state, "error", message);
      }
    } catch {
      setFeedback(state, "error", "Failed to execute R analysis request.");
    } finally {
      if (state.runButton) {
        state.runButton.disabled = false;
      }
    }
  };

  Drupal.behaviors.cttRAnalysis = {
    attach: function (context) {
      once("ctt-r-analysis", "#ctt-r-analysis-page", context).forEach(function (root) {
        const settings = (drupalSettings && drupalSettings.cttRAnalysis) ? drupalSettings.cttRAnalysis : {};

        const state = {
          root: root,
          toolsEndpoint: String(settings.toolsEndpoint || "").trim(),
          associationsEndpoint: String(settings.associationsEndpoint || "").trim(),
          executeEndpoint: String(settings.executeEndpoint || "").trim(),
          feedback: root.querySelector("#ctt-r-feedback"),
          form: root.querySelector("#ctt-r-analysis-form"),
          studyUri: root.querySelector("#ctt-r-study-uri"),
          processUri: root.querySelector("#ctt-r-process-uri"),
          toolUri: root.querySelector("#ctt-r-tool-uri"),
          entrypoint: root.querySelector("#ctt-r-entrypoint"),
          argumentsJson: root.querySelector("#ctt-r-arguments-json"),
          loadContextButton: root.querySelector("#ctt-r-load-context"),
          runButton: root.querySelector("#ctt-r-run-analysis"),
          contextSummary: root.querySelector("#ctt-r-context-summary"),
          output: root.querySelector("#ctt-r-response-output"),
          toolsByUri: {},
          currentAssociations: {},
        };

        if (state.studyUri && settings.initialStudyUri) {
          state.studyUri.value = String(settings.initialStudyUri);
        }
        if (state.processUri && settings.initialProcessUri) {
          state.processUri.value = String(settings.initialProcessUri);
        }

        if (state.loadContextButton) {
          state.loadContextButton.addEventListener("click", function (event) {
            event.preventDefault();
            loadRealContext(state);
          });
        }

        if (state.form) {
          state.form.addEventListener("submit", function (event) {
            event.preventDefault();
            runAnalysis(state);
          });
        }

        if (state.studyUri && String(state.studyUri.value || "").trim() !== "") {
          loadRealContext(state);
        }
      });
    },
  };
})(Drupal, once, typeof drupalSettings === "undefined" ? {} : drupalSettings);
