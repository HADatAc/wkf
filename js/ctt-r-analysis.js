(function (Drupal, once, drupalSettings) {
  "use strict";

  const STORAGE_KEY = "ctt:r-analysis:context:v1";
  const HISTORY_KEY = "ctt:r-analysis:uri-history:v1";
  const MAX_URI_SUGGESTIONS = 25;
  const MAX_AUTOCOMPLETE_SUGGESTIONS = 30;
  const MAX_BACKEND_STUDY_SUGGESTIONS = 20;

  const ARGUMENT_TEMPLATES = {
    "aspiration-baseline": {
      scenarioCode: "aspiracao_de_secrecoes",
      patientGroup: "adult_icu",
      samplingWindowHours: 24,
      includeImageSignals: true,
      expectedSecretionVolumeMl: 35,
      oxygenSaturationThreshold: 92
    },
    "aspiration-high-risk": {
      scenarioCode: "aspiracao_de_secrecoes",
      patientGroup: "high_risk_post_extubation",
      samplingWindowHours: 48,
      includeImageSignals: true,
      expectedSecretionVolumeMl: 50,
      oxygenSaturationThreshold: 90,
      urgencyWeight: 0.85
    },
    "aspiration-followup": {
      scenarioCode: "aspiracao_de_secrecoes",
      patientGroup: "followup_reassessment",
      samplingWindowHours: 12,
      includeImageSignals: false,
      expectedSecretionVolumeMl: 20,
      oxygenSaturationThreshold: 93,
      compareWithPreviousRun: true
    }
  };

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

  const uriFromAutocompleteValue = function (value) {
    const normalized = String(value || "").trim();
    if (isHttpUri(normalized)) {
      return normalized;
    }

    const match = normalized.match(/\[([^\]]+)\]\s*$/);
    if (!match || match.length < 2) {
      return "";
    }

    const uri = String(match[1] || "").trim();
    return isHttpUri(uri) ? uri : "";
  };

  const formatAutocompleteValue = function (uri, label) {
    const normalizedUri = String(uri || "").trim();
    if (!isHttpUri(normalizedUri)) {
      return "";
    }

    const normalizedLabel = String(label || "").trim();
    if (normalizedLabel === "") {
      return normalizedUri;
    }

    return normalizedLabel + " [" + normalizedUri + "]";
  };

  const normalizeUriInput = function (value) {
    return uriFromAutocompleteValue(value);
  };

  const normalizeInputElementValueToUri = function (element) {
    if (!element) {
      return "";
    }

    const rawValue = String(element.value || "").trim();
    if (rawValue === "") {
      element.value = "";
      return "";
    }

    const normalizedUri = normalizeUriInput(rawValue);
    if (isHttpUri(normalizedUri)) {
      element.value = normalizedUri;
      return normalizedUri;
    }

    return "";
  };

  const setFieldValidationState = function (field, errorElement, isValid, message) {
    if (!field) {
      return;
    }

    field.classList.remove("ctt-r-field-valid", "ctt-r-field-invalid");

    if (typeof isValid !== "boolean") {
      if (errorElement) {
        errorElement.classList.add("d-none");
        errorElement.textContent = "";
      }
      return;
    }

    field.classList.add(isValid ? "ctt-r-field-valid" : "ctt-r-field-invalid");
    field.setAttribute("aria-invalid", isValid ? "false" : "true");

    if (!errorElement) {
      return;
    }

    if (isValid) {
      errorElement.classList.add("d-none");
      errorElement.textContent = "";
      return;
    }

    errorElement.classList.remove("d-none");
    errorElement.textContent = String(message || "Invalid URI value.");
  };

  const validateUriInputField = function (field, errorElement, fieldLabel) {
    if (!field) {
      return false;
    }

    const rawValue = String(field.value || "").trim();
    if (rawValue === "") {
      setFieldValidationState(field, errorElement, false, fieldLabel + " is required. Use autocomplete label [URI] or a full http(s) URI.");
      return false;
    }

    const normalizedUri = normalizeUriInput(rawValue);
    if (!isHttpUri(normalizedUri)) {
      setFieldValidationState(field, errorElement, false, "Invalid " + fieldLabel.toLowerCase() + ". Use autocomplete label [URI] or a full http(s) URI.");
      return false;
    }

    setFieldValidationState(field, errorElement, true, "");
    return true;
  };

  const validateCoreUriFields = function (state) {
    const studyOk = validateUriInputField(state.studyUri, state.studyUriError, "Study URI");
    const processOk = validateUriInputField(state.processUri, state.processUriError, "Process URI");
    return studyOk && processOk;
  };

  const normalizeSuggestionValues = function (values) {
    if (!Array.isArray(values)) {
      return [];
    }

    const unique = [];
    const seen = {};

    values.forEach(function (value) {
      const normalized = String(value || "").trim();
      if (normalized === "" || Object.prototype.hasOwnProperty.call(seen, normalized)) {
        return;
      }
      seen[normalized] = true;
      unique.push(normalized);
    });

    return unique.slice(0, MAX_AUTOCOMPLETE_SUGGESTIONS);
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

  const firstIssueCode = function (payload) {
    if (payload && Array.isArray(payload.issues) && payload.issues.length > 0) {
      const first = payload.issues[0];
      if (first && typeof first.code === "string" && first.code.trim() !== "") {
        return first.code.trim();
      }
    }
    return "";
  };

  const copyText = async function (value) {
    const text = String(value || "");
    if (text === "") {
      return false;
    }

    if (navigator && navigator.clipboard && typeof navigator.clipboard.writeText === "function") {
      await navigator.clipboard.writeText(text);
      return true;
    }

    const input = document.createElement("textarea");
    input.value = text;
    input.setAttribute("readonly", "readonly");
    input.style.position = "absolute";
    input.style.left = "-10000px";
    document.body.appendChild(input);
    input.select();
    let copied = false;
    try {
      copied = document.execCommand("copy");
    } catch {
      copied = false;
    }
    document.body.removeChild(input);
    return copied;
  };

  const triggerDownload = function (filename, contents, mimeType) {
    const blob = new Blob([
      String(contents || "")
    ], {
      type: mimeType || "application/json;charset=utf-8"
    });

    const objectUrl = window.URL.createObjectURL(blob);
    const anchor = document.createElement("a");
    anchor.href = objectUrl;
    anchor.download = String(filename || "r-analysis-log.json");
    anchor.style.display = "none";
    document.body.appendChild(anchor);
    anchor.click();
    document.body.removeChild(anchor);
    window.setTimeout(function () {
      window.URL.revokeObjectURL(objectUrl);
    }, 0);
  };

  const sanitizeFilenameSegment = function (value, fallback) {
    const normalized = String(value || "")
      .trim()
      .replace(/[^a-zA-Z0-9._-]+/g, "_")
      .replace(/^_+|_+$/g, "");

    if (normalized !== "") {
      return normalized;
    }

    return String(fallback || "value");
  };

  const buildFilenameTimestamp = function (dateValue) {
    const date = dateValue instanceof Date ? dateValue : new Date();
    const pad = function (number) {
      return String(number).padStart(2, "0");
    };

    return date.getFullYear()
      + pad(date.getMonth() + 1)
      + pad(date.getDate())
      + "-"
      + pad(date.getHours())
      + pad(date.getMinutes())
      + pad(date.getSeconds());
  };

  const extractLogLines = function (payload) {
    const lines = [];

    const appendLines = function (value) {
      if (!Array.isArray(value)) {
        return;
      }

      value.forEach(function (entry) {
        const normalized = String(entry || "").trim();
        if (normalized !== "") {
          lines.push(normalized);
        }
      });
    };

    if (!payload || typeof payload !== "object") {
      return lines;
    }

    appendLines(payload.logs);
    appendLines(payload.executionLogs);

    if (payload.upstream && typeof payload.upstream === "object" && payload.upstream.body && typeof payload.upstream.body === "object") {
      appendLines(payload.upstream.body.logs);
    }

    return lines;
  };

  const updateDownloadLogButtonState = function (state) {
    if (!state || !state.downloadLogButton) {
      return;
    }

    state.downloadLogButton.disabled = !(state.lastExecutionPayload && typeof state.lastExecutionPayload === "object");
  };

  const ensureFeedbackUi = function (state) {
    if (!state || !state.feedback) {
      return;
    }

    let messageNode = state.feedback.querySelector(".ctt-r-feedback-message");
    let closeButton = state.feedback.querySelector(".ctt-r-feedback-close");

    if (!messageNode || !closeButton) {
      state.feedback.innerHTML = "";

      messageNode = document.createElement("span");
      messageNode.className = "ctt-r-feedback-message";
      state.feedback.appendChild(messageNode);

      closeButton = document.createElement("button");
      closeButton.type = "button";
      closeButton.className = "btn-close ctt-r-feedback-close";
      closeButton.setAttribute("aria-label", "Close notification");
      state.feedback.appendChild(closeButton);
    }

    state.feedbackMessage = messageNode;
    state.feedbackClose = closeButton;
  };

  const getFeedbackClassName = function (alertClass, hidden) {
    const classes = [
      "alert",
      "ctt-r-feedback",
      "ctt-r-feedback-floating"
    ];

    if (hidden) {
      classes.push("d-none");
    } else {
      classes.push(alertClass, "alert-dismissible", "fade", "show");
    }

    return classes.join(" ");
  };

  const setFeedback = function (state, type, message) {
    if (!state.feedback) {
      return;
    }

    ensureFeedbackUi(state);

    if (!message) {
      state.feedback.className = getFeedbackClassName("", true);
      if (state.feedbackMessage) {
        state.feedbackMessage.textContent = "";
      }
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

    state.feedback.className = getFeedbackClassName(alertClass, false);
    if (state.feedbackMessage) {
      state.feedbackMessage.textContent = message;
    }
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

    const selectedBeforeRefresh = String(state.toolUri.value || "").trim();

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

    const preferred = String(state.preferredToolUri || selectedBeforeRefresh || "").trim();
    if (preferred !== "" && Object.prototype.hasOwnProperty.call(state.toolsByUri, preferred)) {
      state.toolUri.value = preferred;
    }
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
          message: "Arguments JSON must be an object (not an array)."
        };
      }
      return { ok: true, value: parsed };
    } catch {
      return {
        ok: false,
        message: "Arguments JSON is invalid."
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

  const resetDiagnostics = function (state, message) {
    if (!state.diagnostics) {
      return;
    }

    const text = String(message || "Run validation or execution to view diagnostics summary.").trim();
    state.diagnostics.className = "ctt-r-diagnostics ctt-r-diagnostics-muted";
    state.diagnostics.innerHTML = "<p class=\"ctt-r-diagnostics-note\">" + escapeHtml(text) + "</p>";
  };

  const buildRecommendation = function (issueCodes, isSuccessful) {
    const codes = Array.isArray(issueCodes) ? issueCodes : [];
    if (codes.indexOf("upstream_endpoint_not_found") !== -1) {
      return "Upstream execute endpoint is not deployed yet. Continue with validate-only and share payload evidence with API team.";
    }
    if (codes.indexOf("r_backend_unavailable") !== -1) {
      return "Backend connection is unavailable. Confirm API base URL and HASCOAPI deployment status.";
    }
    if (!isSuccessful) {
      return "Review issue details and request payload, then retry.";
    }
    return "No blocking issues detected.";
  };

  const renderDiagnostics = function (state, payload, responseStatus) {
    if (!state.diagnostics) {
      return;
    }

    if (!payload || typeof payload !== "object") {
      resetDiagnostics(state, "Diagnostics are unavailable for this response.");
      return;
    }

    const execution = payload.execution && typeof payload.execution === "object" ? payload.execution : {};
    const summary = payload.summary && typeof payload.summary === "object" ? payload.summary : {};
    const issues = Array.isArray(payload.issues) ? payload.issues : [];
    const issueCodes = issues.map(function (issue) {
      return String(issue && issue.code || "").trim();
    }).filter(function (code) {
      return code !== "";
    });

    const errorCount = Number.isFinite(Number(summary.errorCount)) ? Number(summary.errorCount) : 0;
    const warningCount = Number.isFinite(Number(summary.warningCount)) ? Number(summary.warningCount) : 0;
    const isSuccessful = payload.isSuccessful === true;
    const executed = payload.executed === true;
    const validateOnly = execution.validateOnly === true || payload.executed === false;

    const requestHttp = Number.isFinite(Number(responseStatus)) && Number(responseStatus) > 0
      ? String(responseStatus)
      : "n/a";
    const upstreamHttp = execution.upstreamHttpStatus === null || typeof execution.upstreamHttpStatus === "undefined"
      ? "n/a"
      : String(execution.upstreamHttpStatus);
    const timeoutSeconds = Number.isFinite(Number(execution.timeoutSeconds))
      ? String(execution.timeoutSeconds)
      : "n/a";
    const runId = String(execution.runId || "n/a").trim() || "n/a";
    const backendEndpoint = String(execution.backendEndpoint || "n/a").trim() || "n/a";

    const statusClass = isSuccessful ? "ctt-r-badge-success" : "ctt-r-badge-danger";
    const statusLabel = isSuccessful ? "successful" : "failed";
    const recommendation = buildRecommendation(issueCodes, isSuccessful);

    const gridRows = [
      ["Request HTTP", requestHttp],
      ["Execution", statusLabel],
      ["Executed upstream", executed ? "yes" : "no"],
      ["Validate only", validateOnly ? "yes" : "no"],
      ["Errors", String(errorCount)],
      ["Warnings", String(warningCount)],
      ["Upstream HTTP", upstreamHttp],
      ["Timeout (s)", timeoutSeconds],
      ["Run ID", runId]
    ];

    const gridHtml = gridRows.map(function (row) {
      return "<div class=\"ctt-r-diagnostics-item\">"
        + "<div class=\"ctt-r-diagnostics-key\">" + escapeHtml(row[0]) + "</div>"
        + "<div class=\"ctt-r-diagnostics-value\">" + escapeHtml(row[1]) + "</div>"
        + "</div>";
    }).join("");

    const codeBadges = issueCodes.length === 0
      ? "<span class=\"ctt-r-badge ctt-r-badge-neutral\">none</span>"
      : issueCodes.slice(0, 6).map(function (code) {
        return "<span class=\"ctt-r-badge ctt-r-badge-neutral\">" + escapeHtml(code) + "</span>";
      }).join(" ");

    const issueItems = issues.slice(0, 5).map(function (issue) {
      const severity = String(issue && issue.severity || "error").toLowerCase();
      const severityClass = severity === "warning" ? "ctt-r-badge-warning" : "ctt-r-badge-danger";
      const code = String(issue && issue.code || "unknown_issue");
      const message = String(issue && issue.message || "No details provided.");
      return "<li>"
        + "<span class=\"ctt-r-badge " + severityClass + "\">" + escapeHtml(severity) + "</span> "
        + "<strong>" + escapeHtml(code) + "</strong>: " + escapeHtml(message)
        + "</li>";
    });

    let issueHtml = "<p class=\"ctt-r-diagnostics-note\">No issue details reported.</p>";
    if (issueItems.length > 0) {
      issueHtml = "<ul class=\"ctt-r-diagnostics-issues\">" + issueItems.join("") + "</ul>";
      if (issues.length > issueItems.length) {
        issueHtml += "<p class=\"ctt-r-diagnostics-note\">+" + String(issues.length - issueItems.length) + " additional issue(s) not shown.</p>";
      }
    }

    state.diagnostics.className = "ctt-r-diagnostics";
    state.diagnostics.innerHTML = ""
      + "<div class=\"ctt-r-diagnostics-status\">"
      + "<span class=\"ctt-r-badge " + statusClass + "\">Execution " + escapeHtml(statusLabel) + "</span> "
      + "<span class=\"ctt-r-badge ctt-r-badge-neutral\">" + (validateOnly ? "validate-only" : "execute") + "</span>"
      + "</div>"
      + "<div class=\"ctt-r-diagnostics-grid\">" + gridHtml + "</div>"
      + "<div class=\"ctt-r-diagnostics-section\"><div class=\"ctt-r-diagnostics-key\">Backend endpoint</div><div class=\"ctt-r-diagnostics-value ctt-r-break-all\">" + escapeHtml(backendEndpoint) + "</div></div>"
      + "<div class=\"ctt-r-diagnostics-section\"><div class=\"ctt-r-diagnostics-key\">Issue codes</div><div class=\"ctt-r-diagnostics-codes\">" + codeBadges + "</div></div>"
      + "<div class=\"ctt-r-diagnostics-section\"><div class=\"ctt-r-diagnostics-key\">Recommendation</div><p class=\"ctt-r-diagnostics-note\">" + escapeHtml(recommendation) + "</p></div>"
      + "<div class=\"ctt-r-diagnostics-section\"><div class=\"ctt-r-diagnostics-key\">Issue details</div>" + issueHtml + "</div>";
  };

  const loadSavedContext = function () {
    try {
      if (!window.localStorage) {
        return null;
      }
      const raw = window.localStorage.getItem(STORAGE_KEY);
      if (!raw) {
        return null;
      }
      const parsed = JSON.parse(raw);
      return parsed && typeof parsed === "object" ? parsed : null;
    } catch {
      return null;
    }
  };

  const createEmptyUriHistory = function () {
    return {
      studies: [],
      processes: [],
      processByStudy: {}
    };
  };

  const normalizeUriList = function (values) {
    if (!Array.isArray(values)) {
      return [];
    }

    const unique = [];
    const seen = {};

    values.forEach(function (value) {
      const normalized = String(value || "").trim();
      if (!isHttpUri(normalized) || Object.prototype.hasOwnProperty.call(seen, normalized)) {
        return;
      }
      seen[normalized] = true;
      unique.push(normalized);
    });

    return unique.slice(0, MAX_URI_SUGGESTIONS);
  };

  const normalizeProcessByStudy = function (value) {
    if (!value || typeof value !== "object") {
      return {};
    }

    const normalized = {};
    Object.keys(value).forEach(function (studyUri) {
      const normalizedStudyUri = String(studyUri || "").trim();
      const normalizedProcessUri = String(value[studyUri] || "").trim();
      if (!isHttpUri(normalizedStudyUri) || !isHttpUri(normalizedProcessUri)) {
        return;
      }
      normalized[normalizedStudyUri] = normalizedProcessUri;
    });
    return normalized;
  };

  const loadUriHistory = function () {
    try {
      if (!window.localStorage) {
        return createEmptyUriHistory();
      }

      const raw = window.localStorage.getItem(HISTORY_KEY);
      if (!raw) {
        return createEmptyUriHistory();
      }

      const parsed = JSON.parse(raw);
      if (!parsed || typeof parsed !== "object") {
        return createEmptyUriHistory();
      }

      return {
        studies: normalizeUriList(parsed.studies),
        processes: normalizeUriList(parsed.processes),
        processByStudy: normalizeProcessByStudy(parsed.processByStudy)
      };
    } catch {
      return createEmptyUriHistory();
    }
  };

  const saveUriHistory = function (history) {
    try {
      if (!window.localStorage) {
        return;
      }

      const normalized = history && typeof history === "object"
        ? history
        : createEmptyUriHistory();

      const payload = {
        studies: normalizeUriList(normalized.studies),
        processes: normalizeUriList(normalized.processes),
        processByStudy: normalizeProcessByStudy(normalized.processByStudy)
      };

      window.localStorage.setItem(HISTORY_KEY, JSON.stringify(payload));
    } catch {
      // Ignore local storage exceptions in hardened browser contexts.
    }
  };

  const prependUriSuggestion = function (list, value) {
    const normalized = String(value || "").trim();
    const base = normalizeUriList(list);

    if (!isHttpUri(normalized)) {
      return base;
    }

    const filtered = base.filter(function (item) {
      return item !== normalized;
    });
    filtered.unshift(normalized);
    return filtered.slice(0, MAX_URI_SUGGESTIONS);
  };

  const renderSuggestionList = function (element, values) {
    if (!element) {
      return;
    }

    const normalized = normalizeSuggestionValues(values);
    const options = normalized.map(function (value) {
      return '<option value="' + escapeHtml(value) + '"></option>';
    });
    element.innerHTML = options.join("");
  };

  const resolveDisplayValueForUri = function (state, uri) {
    const normalizedUri = String(uri || "").trim();
    if (!isHttpUri(normalizedUri)) {
      return "";
    }

    const known = String(
      state
      && state.autocompleteDisplayByUri
      && state.autocompleteDisplayByUri[normalizedUri]
      || ""
    ).trim();

    if (known !== "") {
      return known;
    }

    return normalizedUri;
  };

  const uriHistoryToDisplayValues = function (state, values) {
    return normalizeUriList(values).map(function (uri) {
      return resolveDisplayValueForUri(state, uri);
    });
  };

  const rememberAutocompleteEntries = function (state, entries) {
    if (!state || !state.autocompleteDisplayByUri || !Array.isArray(entries)) {
      return;
    }

    entries.forEach(function (entry) {
      if (!entry || typeof entry !== "object") {
        return;
      }

      const entryValue = String(entry.value || "").trim();
      const entryUri = normalizeUriInput(entry.uri || entryValue);
      if (!isHttpUri(entryUri)) {
        return;
      }

      const entryLabel = String(entry.label || "").trim();
      const displayValue = entryValue !== ""
        ? entryValue
        : formatAutocompleteValue(entryUri, entryLabel);

      state.autocompleteDisplayByUri[entryUri] = displayValue !== "" ? displayValue : entryUri;
    });
  };

  const rememberDisplayValueFromInput = function (state, rawValue) {
    if (!state || !state.autocompleteDisplayByUri) {
      return;
    }

    const value = String(rawValue || "").trim();
    const uri = normalizeUriInput(value);
    if (!isHttpUri(uri)) {
      return;
    }

    state.autocompleteDisplayByUri[uri] = value !== "" ? value : uri;
  };

  const refreshUriSuggestions = function (state) {
    if (!state || !state.uriHistory) {
      return;
    }

    renderSuggestionList(state.studyUriSuggestions, uriHistoryToDisplayValues(state, state.uriHistory.studies));
    renderSuggestionList(state.processUriSuggestions, uriHistoryToDisplayValues(state, state.uriHistory.processes));
  };

  const rememberUriContext = function (state, studyUriValue, processUriValue) {
    if (!state) {
      return;
    }

    if (!state.uriHistory || typeof state.uriHistory !== "object") {
      state.uriHistory = createEmptyUriHistory();
    }

    const studyUri = normalizeUriInput(studyUriValue);
    const processUri = normalizeUriInput(processUriValue);

    state.uriHistory.studies = prependUriSuggestion(state.uriHistory.studies, studyUri);
    state.uriHistory.processes = prependUriSuggestion(state.uriHistory.processes, processUri);

    if (isHttpUri(studyUri) && isHttpUri(processUri)) {
      state.uriHistory.processByStudy[studyUri] = processUri;
    }

    saveUriHistory(state.uriHistory);
    refreshUriSuggestions(state);
  };

  const autofillProcessFromStudy = function (state) {
    if (!state || !state.studyUri || !state.processUri || !state.uriHistory) {
      return;
    }

    const studyUri = normalizeUriInput(state.studyUri.value || "");
    if (!isHttpUri(studyUri)) {
      return;
    }

    const mappedProcessUri = String(state.uriHistory.processByStudy[studyUri] || "").trim();
    if (!isHttpUri(mappedProcessUri)) {
      return;
    }

    const processUri = normalizeUriInput(state.processUri.value || "");
    if (processUri === "" || !isHttpUri(processUri)) {
      state.processUri.value = resolveDisplayValueForUri(state, mappedProcessUri);
    }
  };

  const requestRemoteAutocompleteSuggestions = async function (state, kind, query) {
    if (!state || !state.uriHistory) {
      return;
    }

    const normalizedKind = kind === "process" ? "process" : "study";
    const endpoint = normalizedKind === "study"
      ? String(state.studyAutocompleteEndpoint || "").trim()
      : String(state.processAutocompleteEndpoint || "").trim();
    const suggestionElement = normalizedKind === "study"
      ? state.studyUriSuggestions
      : state.processUriSuggestions;
    const historyValues = normalizedKind === "study"
      ? uriHistoryToDisplayValues(state, state.uriHistory.studies)
      : uriHistoryToDisplayValues(state, state.uriHistory.processes);
    const normalizedQuery = String(query || "").trim();

    if (!suggestionElement) {
      return;
    }

    if (endpoint === "" || normalizedQuery.length < 2) {
      renderSuggestionList(suggestionElement, historyValues);
      return;
    }

    const requestToken = normalizedKind + ":" + String(Date.now()) + ":" + String(Math.random());
    if (state.autocompleteRequestToken && typeof state.autocompleteRequestToken === "object") {
      state.autocompleteRequestToken[normalizedKind] = requestToken;
    }

    try {
      const params = new URLSearchParams();
      params.set("q", normalizedQuery);

      const response = await fetch(buildEndpointUrl(endpoint, params), {
        method: "GET",
        credentials: "same-origin"
      });
      const payload = await parseResponsePayload(response);

      if (state.autocompleteRequestToken && state.autocompleteRequestToken[normalizedKind] !== requestToken) {
        return;
      }

      const entries = response.ok && Array.isArray(payload) ? payload : [];
      rememberAutocompleteEntries(state, entries);

      const remoteValues = entries.map(function (entry) {
        return String(entry && entry.value || "").trim();
      }).filter(function (value) {
        return value !== "";
      });

      renderSuggestionList(suggestionElement, remoteValues.concat(historyValues));
    } catch {
      if (state.autocompleteRequestToken && state.autocompleteRequestToken[normalizedKind] !== requestToken) {
        return;
      }
      renderSuggestionList(suggestionElement, historyValues);
    }
  };

  const scheduleAutocompleteSuggestions = function (state, kind) {
    if (!state || !state.autocompleteTimers || typeof state.autocompleteTimers !== "object") {
      return;
    }

    const normalizedKind = kind === "process" ? "process" : "study";
    const input = normalizedKind === "study" ? state.studyUri : state.processUri;
    if (!input) {
      return;
    }

    if (state.autocompleteTimers[normalizedKind]) {
      window.clearTimeout(state.autocompleteTimers[normalizedKind]);
    }

    const query = String(input.value || "").trim();
    state.autocompleteTimers[normalizedKind] = window.setTimeout(function () {
      requestRemoteAutocompleteSuggestions(state, normalizedKind, query);
    }, 180);
  };

  const buildEndpointUrl = function (endpoint, params) {
    const query = params && typeof params.toString === "function" ? params.toString() : "";
    if (query === "") {
      return endpoint;
    }
    return endpoint + (endpoint.indexOf("?") === -1 ? "?" : "&") + query;
  };

  const extractStudyUriFromTool = function (tool) {
    if (!tool || typeof tool !== "object") {
      return "";
    }

    const candidates = [
      tool.studyUri,
      tool.scenarioUri,
      tool.hasStudyUri,
      tool.hasStudy
    ];

    for (let i = 0; i < candidates.length; i += 1) {
      const value = String(candidates[i] || "").trim();
      if (isHttpUri(value)) {
        return value;
      }
    }

    return "";
  };

  const fetchStoredProcessForStudy = async function (state, studyUri) {
    if (!state || !state.statusEndpoint || !isHttpUri(studyUri)) {
      return "";
    }

    try {
      const params = new URLSearchParams();
      params.set("studyUri", studyUri);

      const response = await fetch(buildEndpointUrl(state.statusEndpoint, params), {
        method: "GET",
        credentials: "same-origin"
      });
      const payload = await parseResponsePayload(response);

      if (!response.ok || !payload || payload.isValid === false) {
        return "";
      }

      const storedProcessUri = String(
        payload
        && payload.association
        && payload.association.studyProcess
        && payload.association.studyProcess.storedProcessUri
        || ""
      ).trim();

      return isHttpUri(storedProcessUri) ? storedProcessUri : "";
    } catch {
      return "";
    }
  };

  const bootstrapUriSuggestionsFromBackend = async function (state) {
    if (!state || state.bootstrapSuggestionsLoaded || !state.toolsEndpoint) {
      return;
    }

    state.bootstrapSuggestionsLoaded = true;

    try {
      const params = new URLSearchParams();
      params.set("language", "R");
      params.set("limit", "200");
      params.set("offset", "0");

      const response = await fetch(buildEndpointUrl(state.toolsEndpoint, params), {
        method: "GET",
        credentials: "same-origin"
      });
      const payload = await parseResponsePayload(response);
      if (!response.ok || !payload || payload.isSuccessful === false) {
        return;
      }

      const tools = Array.isArray(payload.body) ? payload.body : [];
      const studies = [];
      const seenStudies = {};

      tools.forEach(function (tool) {
        const studyUri = extractStudyUriFromTool(tool);
        if (studyUri === "" || Object.prototype.hasOwnProperty.call(seenStudies, studyUri)) {
          return;
        }
        seenStudies[studyUri] = true;
        studies.push(studyUri);
      });

      const limitedStudies = studies.slice(0, MAX_BACKEND_STUDY_SUGGESTIONS);
      limitedStudies.forEach(function (studyUri) {
        rememberUriContext(state, studyUri, "");
      });

      if (state.statusEndpoint && limitedStudies.length > 0) {
        const resolutions = await Promise.all(limitedStudies.map(async function (studyUri) {
          const processUri = await fetchStoredProcessForStudy(state, studyUri);
          return {
            studyUri: studyUri,
            processUri: processUri
          };
        }));

        resolutions.forEach(function (item) {
          if (item && isHttpUri(item.studyUri) && isHttpUri(item.processUri)) {
            rememberUriContext(state, item.studyUri, item.processUri);
          }
        });
      }

      autofillProcessFromStudy(state);
      saveContext(state);
    } catch {
      // Ignore bootstrap failures and keep local-only suggestions.
    }
  };

  const saveContext = function (state) {
    try {
      if (!window.localStorage) {
        return;
      }

      const snapshot = {
        studyUri: normalizeUriInput(state.studyUri && state.studyUri.value || ""),
        processUri: normalizeUriInput(state.processUri && state.processUri.value || ""),
        toolUri: String(state.toolUri && state.toolUri.value || "").trim(),
        entrypoint: String(state.entrypoint && state.entrypoint.value || "").trim(),
        argumentsJson: String(state.argumentsJson && state.argumentsJson.value || ""),
        validateOnly: Boolean(state.validateOnly && state.validateOnly.checked),
        templateKey: String(state.templateSelect && state.templateSelect.value || "").trim()
      };
      window.localStorage.setItem(STORAGE_KEY, JSON.stringify(snapshot));
    } catch {
      // Ignore local storage exceptions in hardened browser contexts.
    }
  };

  const restoreContext = function (state) {
    const saved = loadSavedContext();
    if (!saved) {
      return;
    }

    if (state.studyUri && String(state.studyUri.value || "").trim() === "" && isHttpUri(saved.studyUri)) {
      state.studyUri.value = String(saved.studyUri).trim();
    }
    if (state.processUri && String(state.processUri.value || "").trim() === "" && isHttpUri(saved.processUri)) {
      state.processUri.value = String(saved.processUri).trim();
    }
    if (state.entrypoint && String(state.entrypoint.value || "").trim() === "" && typeof saved.entrypoint === "string") {
      state.entrypoint.value = saved.entrypoint;
    }
    if (state.argumentsJson && String(state.argumentsJson.value || "").trim() === "" && typeof saved.argumentsJson === "string") {
      state.argumentsJson.value = saved.argumentsJson;
    }
    if (state.validateOnly && typeof saved.validateOnly === "boolean") {
      state.validateOnly.checked = saved.validateOnly;
    }

    const toolUri = String(saved.toolUri || "").trim();
    if (toolUri !== "") {
      state.preferredToolUri = toolUri;
    }

    if (state.templateSelect) {
      const templateKey = String(saved.templateKey || "").trim();
      if (templateKey !== "") {
        const hasTemplateOption = Array.from(state.templateSelect.options || []).some(function (option) {
          return String(option.value || "") === templateKey;
        });
        if (hasTemplateOption) {
          state.templateSelect.value = templateKey;
        }
      }
    }
  };

  const clearSavedContext = function (state) {
    try {
      if (window.localStorage) {
        window.localStorage.removeItem(STORAGE_KEY);
        window.localStorage.removeItem(HISTORY_KEY);
      }
    } catch {
      // Ignore local storage exceptions in hardened browser contexts.
    }

    state.preferredToolUri = "";
    state.lastRequestPayload = null;
    state.uriHistory = createEmptyUriHistory();
    state.autocompleteDisplayByUri = {};
    refreshUriSuggestions(state);
    if (state.templateSelect) {
      state.templateSelect.value = "";
    }

    updateDownloadLogButtonState(state);

    setFeedback(state, "success", "Saved browser context cleared for this page.");
  };

  const buildRequestPayload = function (state) {
    const studyUri = normalizeUriInput(state.studyUri && state.studyUri.value || "");
    const processUri = normalizeUriInput(state.processUri && state.processUri.value || "");
    const toolUri = String(state.toolUri && state.toolUri.value || "").trim();
    const entrypoint = String(state.entrypoint && state.entrypoint.value || "").trim();
    const validateOnly = Boolean(state.validateOnly && state.validateOnly.checked);

    if (!isHttpUri(studyUri)) {
      return { ok: false, message: "Provide a valid Study selection (name [URI] or URI)." };
    }
    if (!isHttpUri(processUri)) {
      return { ok: false, message: "Provide a valid Process selection (name [URI] or URI)." };
    }
    if (!isHttpUri(toolUri)) {
      return { ok: false, message: "Select a valid R tool URI from repository context." };
    }

    const parsedArguments = parseArguments(state.argumentsJson && state.argumentsJson.value || "");
    if (!parsedArguments.ok) {
      return {
        ok: false,
        message: parsedArguments.message || "Invalid arguments JSON."
      };
    }

    const requestPayload = {
      studyUri: studyUri,
      processUri: processUri,
      toolUri: toolUri,
      arguments: parsedArguments.value,
      validateOnly: validateOnly
    };
    if (entrypoint !== "") {
      requestPayload.entrypoint = entrypoint;
    }

    return {
      ok: true,
      payload: requestPayload
    };
  };

  const applyTemplate = function (state) {
    if (!state.templateSelect || !state.argumentsJson) {
      return;
    }

    const templateKey = String(state.templateSelect.value || "").trim();
    if (templateKey === "") {
      setFeedback(state, "warning", "Choose a template before applying it.");
      return;
    }

    const templatePayload = ARGUMENT_TEMPLATES[templateKey];
    if (!templatePayload || typeof templatePayload !== "object") {
      setFeedback(state, "error", "Selected template is not available.");
      return;
    }

    state.argumentsJson.value = JSON.stringify(templatePayload, null, 2);
    saveContext(state);
    setFeedback(state, "success", "Argument template applied to the JSON editor.");
  };

  const bindPersistenceEvents = function (state) {
    const bind = function (element, eventName, callback) {
      if (element && typeof element.addEventListener === "function") {
        element.addEventListener(eventName, callback);
      }
    };

    const saveHandler = function () {
      saveContext(state);
    };

    bind(state.studyUri, "input", function () {
      scheduleAutocompleteSuggestions(state, "study");
      validateUriInputField(state.studyUri, state.studyUriError, "Study URI");
      saveContext(state);
    });
    bind(state.processUri, "input", function () {
      scheduleAutocompleteSuggestions(state, "process");
      validateUriInputField(state.processUri, state.processUriError, "Process URI");
      saveContext(state);
    });
    bind(state.studyUri, "change", function () {
      normalizeInputElementValueToUri(state.studyUri);
      normalizeInputElementValueToUri(state.processUri);
      validateUriInputField(state.studyUri, state.studyUriError, "Study URI");
      validateUriInputField(state.processUri, state.processUriError, "Process URI");
      rememberDisplayValueFromInput(state, state.studyUri ? state.studyUri.value : "");
      rememberDisplayValueFromInput(state, state.processUri ? state.processUri.value : "");
      autofillProcessFromStudy(state);
      rememberUriContext(
        state,
        state.studyUri ? state.studyUri.value : "",
        state.processUri ? state.processUri.value : ""
      );
      scheduleAutocompleteSuggestions(state, "study");
      saveContext(state);
    });
    bind(state.processUri, "change", function () {
      normalizeInputElementValueToUri(state.processUri);
      validateUriInputField(state.processUri, state.processUriError, "Process URI");
      rememberDisplayValueFromInput(state, state.studyUri ? state.studyUri.value : "");
      rememberDisplayValueFromInput(state, state.processUri ? state.processUri.value : "");
      rememberUriContext(
        state,
        state.studyUri ? state.studyUri.value : "",
        state.processUri ? state.processUri.value : ""
      );
      scheduleAutocompleteSuggestions(state, "process");
      saveContext(state);
    });
    bind(state.entrypoint, "input", saveHandler);
    bind(state.validateOnly, "change", saveHandler);
    bind(state.argumentsJson, "input", saveHandler);
    bind(state.templateSelect, "change", saveHandler);

    bind(state.toolUri, "change", function () {
      state.preferredToolUri = String(state.toolUri && state.toolUri.value || "").trim();
      saveContext(state);
    });

    bind(state.studyUri, "blur", function () {
      normalizeInputElementValueToUri(state.studyUri);
      validateUriInputField(state.studyUri, state.studyUriError, "Study URI");
      saveContext(state);
    });

    bind(state.processUri, "blur", function () {
      normalizeInputElementValueToUri(state.processUri);
      validateUriInputField(state.processUri, state.processUriError, "Process URI");
      saveContext(state);
    });
  };

  const loadRealContext = async function (state) {
    if (!validateCoreUriFields(state)) {
      setFeedback(state, "warning", "Fix Study/Process fields before loading context.");
      return;
    }

    const studyUri = normalizeUriInput(state.studyUri && state.studyUri.value || "");
    if (!isHttpUri(studyUri)) {
      setFeedback(state, "warning", "Provide a valid Study selection (name [URI] or URI) before loading context.");
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
        credentials: "same-origin"
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
      const associationsUrl = buildEndpointUrl(state.associationsEndpoint, associationsParams);

      const associationsResponse = await fetch(associationsUrl, {
        method: "GET",
        credentials: "same-origin"
      });
      const associationsPayload = await parseResponsePayload(associationsResponse);
      if (!associationsResponse.ok || !associationsPayload || associationsPayload.isValid === false) {
        const message = firstIssueMessage(associationsPayload, "Unable to load study associations.");
        setFeedback(state, "error", message);
        return;
      }

      state.currentAssociations = associationsPayload.associations || {};
      renderAssociationsSummary(state, state.currentAssociations);

      let processUriForContext = normalizeUriInput(state.processUri && state.processUri.value || "");
      if (!isHttpUri(processUriForContext)) {
        processUriForContext = await fetchStoredProcessForStudy(state, studyUri);
        if (isHttpUri(processUriForContext) && state.processUri) {
          state.processUri.value = resolveDisplayValueForUri(state, processUriForContext);
        }
      }

      rememberUriContext(
        state,
        studyUri,
        processUriForContext
      );
      saveContext(state);

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
    if (!state.executeEndpoint) {
      setFeedback(state, "error", "Execution endpoint is not configured.");
      return;
    }

    if (!validateCoreUriFields(state)) {
      setFeedback(state, "warning", "Fix Study/Process fields before running validation or execution.");
      return;
    }

    if (state.isRunning === true) {
      setFeedback(state, "warning", "A request is already running. Wait for completion before submitting again.");
      return;
    }

    const built = buildRequestPayload(state);
    if (!built.ok) {
      setFeedback(state, "warning", built.message || "Unable to prepare request payload.");
      return;
    }

    const requestPayload = built.payload;
    const validateOnly = Boolean(requestPayload.validateOnly);
    state.lastRequestPayload = requestPayload;
    rememberUriContext(state, requestPayload.studyUri, requestPayload.processUri);
    saveContext(state);

    const originalRunButtonText = state.runButton ? state.runButton.textContent : "";
    state.isRunning = true;

    if (state.runButton) {
      state.runButton.disabled = true;
      state.runButton.textContent = validateOnly ? "Validating..." : "Running...";
    }
    if (state.downloadLogButton) {
      state.downloadLogButton.disabled = true;
    }

    try {
      const response = await fetch(state.executeEndpoint, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json"
        },
        body: JSON.stringify(requestPayload)
      });

      const payload = await parseResponsePayload(response);
      state.lastExecutionPayload = payload;
      state.lastExecutionHttpStatus = response.status;
      updateDownloadLogButtonState(state);
      setOutputPayload(state, payload);
      renderDiagnostics(state, payload, response.status);

      if (response.ok && payload && payload.isSuccessful === true) {
        if (payload.executed === false || validateOnly) {
          setFeedback(state, "success", "Validation completed. The upstream R executor was not called.");
        } else {
          setFeedback(state, "success", "R analysis executed with real backend response.");
        }
      } else {
        let message = firstIssueMessage(payload, "R analysis execution failed.");
        const issueCode = firstIssueCode(payload);
        const upstreamStatus = payload && payload.execution && payload.execution.upstreamHttpStatus
          ? String(payload.execution.upstreamHttpStatus)
          : "";

        if (issueCode === "upstream_endpoint_not_found") {
          message += " Upstream endpoint not available yet; continue with validate-only mode until API deployment.";
        } else if (issueCode === "r_backend_unavailable") {
          message += " R backend unavailable; confirm API base URL and backend service status.";
        }

        if (upstreamStatus !== "") {
          message += " Upstream HTTP status: " + upstreamStatus + ".";
        }

        setFeedback(state, "error", message);
      }
    } catch {
      resetDiagnostics(state, "Request failed before receiving a backend response.");
      setFeedback(state, "error", "Failed to execute R analysis request.");
      updateDownloadLogButtonState(state);
    } finally {
      state.isRunning = false;
      if (state.runButton) {
        state.runButton.disabled = false;
        state.runButton.textContent = originalRunButtonText;
      }
      saveContext(state);
    }
  };

  const copyRequestPayload = async function (state) {
    const built = buildRequestPayload(state);
    const payloadToCopy = built.ok ? built.payload : state.lastRequestPayload;

    if (!payloadToCopy) {
      setFeedback(state, "warning", built.message || "No request payload is available to copy.");
      return;
    }

    try {
      const copied = await copyText(JSON.stringify(payloadToCopy, null, 2));
      if (copied) {
        state.lastRequestPayload = payloadToCopy;
        setFeedback(state, "success", "Request payload copied to clipboard.");
      } else {
        setFeedback(state, "error", "Unable to copy payload automatically.");
      }
    } catch {
      setFeedback(state, "error", "Unable to copy request payload.");
    }
  };

  const downloadExecutionLog = function (state) {
    if (!state || !state.lastExecutionPayload || typeof state.lastExecutionPayload !== "object") {
      setFeedback(state, "warning", "Run R Analysis first to enable log download.");
      return;
    }

    const execution = state.lastExecutionPayload.execution && typeof state.lastExecutionPayload.execution === "object"
      ? state.lastExecutionPayload.execution
      : {};
    const runId = String(execution.runId || "no-run-id");
    const downloadedAt = new Date();

    const payloadToSave = {
      downloadedAt: downloadedAt.toISOString(),
      responseHttpStatus: state.lastExecutionHttpStatus,
      requestPayload: state.lastRequestPayload,
      responsePayload: state.lastExecutionPayload,
      extractedLogLines: extractLogLines(state.lastExecutionPayload)
    };

    const filename = "r-analysis-log-"
      + sanitizeFilenameSegment(runId, "no-run-id")
      + "-"
      + buildFilenameTimestamp(downloadedAt)
      + ".json";

    try {
      triggerDownload(filename, JSON.stringify(payloadToSave, null, 2), "application/json;charset=utf-8");
      setFeedback(state, "success", "Execution log downloaded as JSON.");
    } catch {
      setFeedback(state, "error", "Unable to download execution log file.");
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
          statusEndpoint: String(settings.statusEndpoint || "").trim(),
          executeEndpoint: String(settings.executeEndpoint || "").trim(),
          studyAutocompleteEndpoint: String(settings.studyAutocompleteEndpoint || "").trim(),
          processAutocompleteEndpoint: String(settings.processAutocompleteEndpoint || "").trim(),
          feedback: root.querySelector("#ctt-r-feedback"),
          form: root.querySelector("#ctt-r-analysis-form"),
          studyUri: root.querySelector("#ctt-r-study-uri"),
          processUri: root.querySelector("#ctt-r-process-uri"),
          studyUriError: root.querySelector("#ctt-r-study-uri-error"),
          processUriError: root.querySelector("#ctt-r-process-uri-error"),
          studyUriSuggestions: root.querySelector("#ctt-r-study-uri-suggestions"),
          processUriSuggestions: root.querySelector("#ctt-r-process-uri-suggestions"),
          toolUri: root.querySelector("#ctt-r-tool-uri"),
          entrypoint: root.querySelector("#ctt-r-entrypoint"),
          validateOnly: root.querySelector("#ctt-r-validate-only"),
          argumentsJson: root.querySelector("#ctt-r-arguments-json"),
          templateSelect: root.querySelector("#ctt-r-argument-template"),
          applyTemplateButton: root.querySelector("#ctt-r-apply-template"),
          clearSavedContextButton: root.querySelector("#ctt-r-clear-saved-context"),
          loadContextButton: root.querySelector("#ctt-r-load-context"),
          runButton: root.querySelector("#ctt-r-run-analysis"),
          copyPayloadButton: root.querySelector("#ctt-r-copy-payload"),
          downloadLogButton: root.querySelector("#ctt-r-download-log"),
          contextSummary: root.querySelector("#ctt-r-context-summary"),
          diagnostics: root.querySelector("#ctt-r-exec-diagnostics"),
          output: root.querySelector("#ctt-r-response-output"),
          uriHistory: loadUriHistory(),
          autocompleteDisplayByUri: {},
          autocompleteTimers: {
            study: null,
            process: null
          },
          autocompleteRequestToken: {
            study: "",
            process: ""
          },
          toolsByUri: {},
          currentAssociations: {},
          preferredToolUri: "",
          lastRequestPayload: null,
          lastExecutionPayload: null,
          lastExecutionHttpStatus: null,
          bootstrapSuggestionsLoaded: false,
          isRunning: false
        };

        ensureFeedbackUi(state);
        if (state.feedbackClose) {
          state.feedbackClose.addEventListener("click", function () {
            setFeedback(state, "", "");
          });
        }

        if (state.studyUri && settings.initialStudyUri) {
          state.studyUri.value = String(settings.initialStudyUri);
        }
        if (state.processUri && settings.initialProcessUri) {
          state.processUri.value = String(settings.initialProcessUri);
        }

        restoreContext(state);
        autofillProcessFromStudy(state);
        rememberUriContext(
          state,
          state.studyUri ? state.studyUri.value : "",
          state.processUri ? state.processUri.value : ""
        );
        refreshUriSuggestions(state);
        bindPersistenceEvents(state);
        validateUriInputField(state.studyUri, state.studyUriError, "Study URI");
        validateUriInputField(state.processUri, state.processUriError, "Process URI");
        resetDiagnostics(state, "Run validation or execution to view diagnostics summary.");
        bootstrapUriSuggestionsFromBackend(state);
        scheduleAutocompleteSuggestions(state, "study");
        scheduleAutocompleteSuggestions(state, "process");
        updateDownloadLogButtonState(state);

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

        if (state.applyTemplateButton) {
          state.applyTemplateButton.addEventListener("click", function (event) {
            event.preventDefault();
            applyTemplate(state);
          });
        }

        if (state.clearSavedContextButton) {
          state.clearSavedContextButton.addEventListener("click", function (event) {
            event.preventDefault();
            clearSavedContext(state);
          });
        }

        if (state.copyPayloadButton) {
          state.copyPayloadButton.addEventListener("click", function (event) {
            event.preventDefault();
            copyRequestPayload(state);
          });
        }

        if (state.downloadLogButton) {
          state.downloadLogButton.addEventListener("click", function (event) {
            event.preventDefault();
            downloadExecutionLog(state);
          });
        }

        if (state.studyUri && isHttpUri(normalizeUriInput(state.studyUri.value || ""))) {
          loadRealContext(state);
        }
      });
    }
  };
})(Drupal, once, typeof drupalSettings === "undefined" ? {} : drupalSettings);