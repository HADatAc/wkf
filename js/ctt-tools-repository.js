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

  const toTagArray = function (raw) {
    if (Array.isArray(raw)) {
      return raw
        .map(function (item) {
          return String(item || "").trim();
        })
        .filter(function (item) {
          return item !== "";
        });
    }

    return String(raw || "")
      .split(",")
      .map(function (item) {
        return item.trim();
      })
      .filter(function (item) {
        return item !== "";
      });
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

  const isHttpUri = function (value) {
    return /^https?:\/\//i.test(String(value || "").trim());
  };

  const extractUriFromAutocompleteValue = function (value) {
    const normalized = String(value || "").trim();
    if (normalized === "") {
      return "";
    }

    if (isHttpUri(normalized)) {
      return normalized;
    }

    const match = normalized.match(/\[(https?:\/\/[^\]\s]+)\]\s*$/i);
    if (match && match[1]) {
      return String(match[1]).trim();
    }

    return "";
  };

  const normalizeUriFieldValue = function (element) {
    if (!element) {
      return "";
    }

    const normalized = String(element.value || "").trim();
    element.value = normalized;
    return normalized;
  };

  const deriveLanguageFromFilename = function (filename) {
    const value = String(filename || "").trim().toLowerCase();
    if (value.endsWith(".r")) {
      return "R";
    }
    if (value.endsWith(".py")) {
      return "Python";
    }
    if (value.endsWith(".sql")) {
      return "SQL";
    }
    if (value.endsWith(".js")) {
      return "JavaScript";
    }
    if (value.endsWith(".ts")) {
      return "TypeScript";
    }
    return "";
  };

  const validateOptionalUriField = function (value, label) {
    const normalized = String(value || "").trim();
    if (normalized === "") {
      return { ok: true, value: "" };
    }

    if (!isHttpUri(normalized)) {
      return {
        ok: false,
        message: label + " must be an absolute http(s) URI."
      };
    }

    return { ok: true, value: normalized };
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

  const deriveProcessUriFromEntity = function (item) {
    if (!item || typeof item !== "object") {
      return "";
    }

    const candidates = [
      item.uri,
      item.hasURI,
      item.processUri,
      item.workflowUri,
      item.hasProcessUri,
      item.hasWorkflowUri,
    ];

    for (let i = 0; i < candidates.length; i += 1) {
      const value = String(candidates[i] || "").trim();
      if (isHttpUri(value)) {
        return value;
      }
    }

    return "";
  };

  const deriveScenarioUriFromEntity = function (item) {
    if (!item || typeof item !== "object") {
      return "";
    }

    const candidates = [
      item.scenarioUri,
      item.hasScenarioUri,
      item.hasScenario,
      item.hasSIRPartOf,
      item.partOfScenarioUri,
    ];

    for (let i = 0; i < candidates.length; i += 1) {
      const value = String(candidates[i] || "").trim();
      if (isHttpUri(value)) {
        return value;
      }
    }

    return "";
  };

  const deriveProcessLabelFromEntity = function (item, fallbackUri) {
    if (!item || typeof item !== "object") {
      return fallbackUri;
    }

    const candidates = [
      item.label,
      item.hasContent,
      item.name,
      item.title,
    ];

    for (let i = 0; i < candidates.length; i += 1) {
      const value = String(candidates[i] || "").trim();
      if (value !== "") {
        return value;
      }
    }

    return fallbackUri;
  };

  const toSelectOptionsHtml = function (values, selectedValue, emptyLabel) {
    const selected = String(selectedValue || "").trim();
    let options = '<option value="">' + escapeHtml(emptyLabel) + "</option>";

    values.forEach(function (item) {
      const value = String(item.value || "").trim();
      const label = String(item.label || value).trim();
      if (value === "") {
        return;
      }
      const isSelected = selected !== "" && selected === value;
      options += '<option value="' + escapeHtml(value) + '"' + (isSelected ? ' selected="selected"' : "") + '>' + escapeHtml(label) + "</option>";
    });

    return options;
  };

  const getActiveFacetFilters = function (state) {
    return {
      language: String(state.filterLanguage && state.filterLanguage.value || "").trim().toLowerCase(),
      owner: String(state.filterOwner && state.filterOwner.value || "").trim().toLowerCase(),
      processUri: String(state.filterProcessUri && state.filterProcessUri.value || "").trim(),
      institution: String(state.filterInstitution && state.filterInstitution.value || "").trim().toLowerCase(),
      scenarioUri: String(state.filterScenarioUri && state.filterScenarioUri.value || "").trim(),
      datasetUri: String(state.filterDatasetUri && state.filterDatasetUri.value || "").trim().toLowerCase(),
    };
  };

  const toolMatchesFacetFilters = function (tool, filters, ignoreFacet) {
    const language = String(tool.language || "").trim().toLowerCase();
    const ownerEmail = String(tool.ownerUserEmail || tool.owner || tool.createdBy || "").trim().toLowerCase();
    const processUri = String(tool.processUri || "").trim();
    const institution = String(tool.institution || "").trim().toLowerCase();
    const scenarioUri = String(tool.scenarioUri || "").trim();
    const scenarioUriLower = scenarioUri.toLowerCase();
    const datasetUri = String(tool.datasetUri || "").trim().toLowerCase();

    if (ignoreFacet !== "language" && filters.language !== "" && language !== filters.language) {
      return false;
    }
    if (ignoreFacet !== "owner" && filters.owner !== "" && ownerEmail.indexOf(filters.owner) === -1) {
      return false;
    }
    if (ignoreFacet !== "processUri" && filters.processUri !== "" && processUri !== filters.processUri) {
      return false;
    }
    if (ignoreFacet !== "institution" && filters.institution !== "" && institution.indexOf(filters.institution) === -1) {
      return false;
    }
    if (ignoreFacet !== "scenarioUri"
      && filters.scenarioUri !== ""
      && scenarioUriLower !== ""
      && scenarioUriLower !== filters.scenarioUri.toLowerCase()) {
      return false;
    }
    if (ignoreFacet !== "datasetUri" && filters.datasetUri !== "" && datasetUri !== filters.datasetUri) {
      return false;
    }

    return true;
  };

  const ensureSelectedFacetOption = function (options, selectedValue) {
    const selected = String(selectedValue || "").trim();
    if (selected === "") {
      return options;
    }

    const exists = options.some(function (option) {
      return String(option.value || "").trim() === selected;
    });
    if (!exists) {
      options.unshift({ value: selected, label: selected });
    }
    return options;
  };

  const buildProcessEntryIndex = function (state) {
    const index = {};
    (state.processEntries || []).forEach(function (entry) {
      const processUri = String(entry.processUri || "").trim();
      if (!isHttpUri(processUri)) {
        return;
      }
      if (!Object.prototype.hasOwnProperty.call(index, processUri)) {
        index[processUri] = {
          processUri: processUri,
          label: String(entry.label || processUri).trim() || processUri,
          scenarioUri: String(entry.scenarioUri || "").trim(),
        };
      }
    });
    return index;
  };

  const refreshFacetOptions = function (state, tools) {
    const allTools = Array.isArray(tools) ? tools : [];
    const filters = getActiveFacetFilters(state);
    const processIndex = buildProcessEntryIndex(state);

    const toolsForScenario = allTools.filter(function (tool) {
      return toolMatchesFacetFilters(tool, filters, "scenarioUri");
    });
    const toolsForProcess = allTools.filter(function (tool) {
      return toolMatchesFacetFilters(tool, filters, "processUri");
    });
    const toolsForOwner = allTools.filter(function (tool) {
      return toolMatchesFacetFilters(tool, filters, "owner");
    });
    const toolsForInstitution = allTools.filter(function (tool) {
      return toolMatchesFacetFilters(tool, filters, "institution");
    });

    const scenarioMap = {};
    const processMap = {};
    const ownerMap = {};
    const institutionMap = {};

    const processUrisInScenarioScope = {};
    toolsForScenario.forEach(function (tool) {
      const processUri = String(tool.processUri || "").trim();
      const scenarioUri = String(tool.scenarioUri || "").trim();
      if (isHttpUri(processUri)) {
        processUrisInScenarioScope[processUri] = true;
      }
      if (isHttpUri(scenarioUri)) {
        scenarioMap[scenarioUri] = scenarioUri;
      }
    });

    Object.keys(processIndex).forEach(function (processUri) {
      const entry = processIndex[processUri];
      const entryScenarioUri = String(entry.scenarioUri || "").trim();

      if (Object.keys(processUrisInScenarioScope).length > 0
        && !Object.prototype.hasOwnProperty.call(processUrisInScenarioScope, processUri)) {
        return;
      }

      if (isHttpUri(entryScenarioUri)) {
        scenarioMap[entryScenarioUri] = entryScenarioUri;
      }
    });

    toolsForProcess.forEach(function (tool) {
      const processUri = String(tool.processUri || "").trim();
      if (!isHttpUri(processUri)) {
        return;
      }
      if (filters.scenarioUri !== "") {
        const toolScenarioUri = String(tool.scenarioUri || "").trim();
        if (toolScenarioUri !== "" && toolScenarioUri.toLowerCase() !== filters.scenarioUri.toLowerCase()) {
          return;
        }
      }

      const indexed = processIndex[processUri];
      const label = indexed ? indexed.label : processUri;
      processMap[processUri] = {
        value: processUri,
        label: String(label || processUri).trim() || processUri,
      };
    });

    Object.keys(processIndex).forEach(function (processUri) {
      const entry = processIndex[processUri];
      const entryScenarioUri = String(entry.scenarioUri || "").trim();
      if (filters.scenarioUri !== ""
        && entryScenarioUri !== ""
        && entryScenarioUri.toLowerCase() !== filters.scenarioUri.toLowerCase()) {
        return;
      }

      if (!Object.prototype.hasOwnProperty.call(processMap, processUri)) {
        processMap[processUri] = {
          value: processUri,
          label: String(entry.label || processUri).trim() || processUri,
        };
      }
    });

    toolsForOwner.forEach(function (tool) {
      const owner = String(tool.ownerUserEmail || tool.owner || tool.createdBy || "").trim();
      if (owner !== "") {
        ownerMap[owner] = owner;
      }
    });

    toolsForInstitution.forEach(function (tool) {
      const institutionValue = String(tool.institution || "").trim();
      if (institutionValue !== "") {
        institutionMap[institutionValue] = institutionValue;
      }
    });

    if (state.filterScenarioUri) {
      const selectedScenario = String(state.filterScenarioUri.value || state.initialScenarioUri || "").trim();
      let scenarioOptions = Object.keys(scenarioMap).sort(function (a, b) {
        return a.localeCompare(b);
      }).map(function (uri) {
        return { value: uri, label: uri };
      });

      if (isHttpUri(state.initialScenarioUri) && !Object.prototype.hasOwnProperty.call(scenarioMap, state.initialScenarioUri)) {
        scenarioOptions.unshift({ value: state.initialScenarioUri, label: state.initialScenarioUri });
      }

      scenarioOptions = ensureSelectedFacetOption(scenarioOptions, selectedScenario);
      state.filterScenarioUri.innerHTML = toSelectOptionsHtml(scenarioOptions, selectedScenario, "Any scenario");
    }

    if (state.filterProcessUri) {
      const selectedProcess = String(state.filterProcessUri.value || state.initialProcessUri || "").trim();
      let processOptions = Object.values(processMap).sort(function (left, right) {
        return String(left.label || "").localeCompare(String(right.label || ""));
      });

      if (isHttpUri(state.initialProcessUri) && !Object.prototype.hasOwnProperty.call(processMap, state.initialProcessUri)) {
        processOptions.unshift({ value: state.initialProcessUri, label: state.initialProcessUri });
      }

      processOptions = ensureSelectedFacetOption(processOptions, selectedProcess);
      state.filterProcessUri.innerHTML = toSelectOptionsHtml(processOptions, selectedProcess, "All processes");
    }

    if (state.filterOwner) {
      const selectedOwner = String(state.filterOwner.value || "").trim();
      let ownerOptions = Object.keys(ownerMap).sort(function (a, b) {
        return a.localeCompare(b);
      }).map(function (owner) {
        return { value: owner, label: owner };
      });

      ownerOptions = ensureSelectedFacetOption(ownerOptions, selectedOwner);
      state.filterOwner.innerHTML = toSelectOptionsHtml(ownerOptions, selectedOwner, "Any owner");
    }

    if (state.filterInstitution) {
      const selectedInstitution = String(state.filterInstitution.value || "").trim();
      let institutionOptions = Object.keys(institutionMap).sort(function (a, b) {
        return a.localeCompare(b);
      }).map(function (value) {
        return { value: value, label: value };
      });

      institutionOptions = ensureSelectedFacetOption(institutionOptions, selectedInstitution);
      state.filterInstitution.innerHTML = toSelectOptionsHtml(institutionOptions, selectedInstitution, "Any institution");
    }
  };

  const refreshScenarioFilterOptions = function (state, tools) {
    refreshFacetOptions(state, tools);
  };

  const refreshOwnerFilterOptions = function (state, tools) {
    refreshFacetOptions(state, tools);
  };

  const refreshProcessFilterOptions = function (state) {
    refreshFacetOptions(state, state.lastTools || []);
  };

  const mergeProcessEntries = function (state, tools) {
    const entries = {};

    (state.processEntries || []).forEach(function (entry) {
      const processUri = String(entry.processUri || "").trim();
      if (!isHttpUri(processUri)) {
        return;
      }
      entries[processUri] = {
        processUri: processUri,
        label: String(entry.label || processUri).trim() || processUri,
        scenarioUri: String(entry.scenarioUri || "").trim(),
      };
    });

    (Array.isArray(tools) ? tools : []).forEach(function (tool) {
      const processUri = String(tool.processUri || "").trim();
      if (!isHttpUri(processUri)) {
        return;
      }

      const scenarioUri = String(tool.scenarioUri || "").trim();
      if (!Object.prototype.hasOwnProperty.call(entries, processUri)) {
        entries[processUri] = {
          processUri: processUri,
          label: processUri,
          scenarioUri: scenarioUri,
        };
        return;
      }

      if (entries[processUri].scenarioUri === "" && isHttpUri(scenarioUri)) {
        entries[processUri].scenarioUri = scenarioUri;
      }
    });

    state.processEntries = Object.values(entries);
  };

  const loadProcesses = async function (state) {
    state.processEntries = [];

    const endpoint = String(state.processListEndpoint || "").trim();
    if (endpoint === "") {
      return;
    }

    try {
      const params = new URLSearchParams();
      params.set("pageSize", "500");
      params.set("offset", "0");
      const url = endpoint + (endpoint.indexOf("?") === -1 ? "?" : "&") + params.toString();

      const response = await fetch(url, {
        method: "GET",
        credentials: "same-origin",
      });

      const payload = await parseResponsePayload(response);
      if (!response.ok) {
        return;
      }

      let processList = [];
      if (Array.isArray(payload)) {
        processList = payload;
      } else if (payload && Array.isArray(payload.body)) {
        processList = payload.body;
      } else if (payload && payload.body && Array.isArray(payload.body.body)) {
        processList = payload.body.body;
      } else if (payload && payload.result && Array.isArray(payload.result)) {
        processList = payload.result;
      } else if (payload && payload.data && Array.isArray(payload.data)) {
        processList = payload.data;
      }

      state.processEntries = processList.map(function (item) {
        const processUri = deriveProcessUriFromEntity(item);
        return {
          processUri: processUri,
          label: deriveProcessLabelFromEntity(item, processUri),
          scenarioUri: deriveScenarioUriFromEntity(item),
        };
      }).filter(function (entry) {
        return isHttpUri(entry.processUri);
      });
    } catch {
      state.processEntries = [];
    }
  };

  const getProcessUri = function (state) {
    if (!state.filterProcessUri) {
      return "";
    }

    return String(state.filterProcessUri.value || "").trim();
  };

  const renderTable = function (state, tools) {
    if (!state.tableBody) {
      return;
    }

    state.toolsByUri = {};

    if (!Array.isArray(tools) || tools.length === 0) {
      state.tableBody.innerHTML = '<tr><td colspan="12" class="text-center text-muted">No analytical tools were found for the selected filters.</td></tr>';
      return;
    }

    const rows = tools.map(function (tool) {
      const toolUri = String(tool.toolUri || "").trim();
      const author = String(tool.author || "").trim();
      const owner = String(tool.ownerUserEmail || tool.owner || tool.createdBy || "").trim();
      const processUri = String(tool.processUri || "").trim();
      const institution = String(tool.institution || "").trim();
      const releaseDate = String(tool.releaseDate || "").trim();
      state.toolsByUri[toolUri] = tool;

      let actionButtons = "";
      const canUpdate = Boolean(tool.canUpdate);
      const canDelete = Boolean(tool.canDelete);

      if (canUpdate) {
        actionButtons += '<button type="button" class="btn btn-sm btn-outline-primary" data-action="edit" data-tool-uri="' + escapeHtml(toolUri) + '">Edit</button>';
      }

      if (canDelete) {
        actionButtons += '<button type="button" class="btn btn-sm btn-outline-danger" data-action="remove" data-tool-uri="' + escapeHtml(toolUri) + '">Remove</button>';
      }

      if (!canUpdate && !canDelete) {
        actionButtons = '<span class="badge bg-light text-dark border">Read only</span>';
      }

      return ""
        + "<tr>"
        + "<td>" + escapeHtml(tool.name || "") + "</td>"
        + "<td>" + escapeHtml(tool.version || "") + "</td>"
        + "<td>" + escapeHtml(tool.language || "") + "</td>"
        + "<td>" + escapeHtml(tool.status || "") + "</td>"
        + "<td>" + escapeHtml(owner) + "</td>"
        + "<td class=\"ctt-tools-uri\">" + escapeHtml(processUri) + "</td>"
        + "<td>" + escapeHtml(author) + "</td>"
        + "<td>" + escapeHtml(institution) + "</td>"
        + "<td>" + escapeHtml(releaseDate) + "</td>"
        + "<td class=\"ctt-tools-uri\">" + escapeHtml(toolUri) + "</td>"
        + "<td>" + escapeHtml(tool.updatedAt || tool.createdAt || "") + "</td>"
        + "<td class=\"ctt-tools-actions\">" + actionButtons + "</td>"
        + "</tr>";
    });

    state.tableBody.innerHTML = rows.join("");
  };

  const getIssueMessage = function (payload, fallback) {
    if (payload && Array.isArray(payload.issues) && payload.issues.length > 0) {
      const firstIssue = payload.issues[0];
      if (firstIssue && typeof firstIssue.message === "string" && firstIssue.message.trim() !== "") {
        return firstIssue.message.trim();
      }
    }

    return fallback;
  };

  const loadTools = async function (state) {
    if (!state.endpoint) {
      setFeedback(state, "error", "Analytical tools endpoint is not configured.");
      return;
    }

    if (state.tableBody) {
      state.tableBody.innerHTML = '<tr><td colspan="12" class="text-center text-muted">Loading tool collection...</td></tr>';
    }

    const params = new URLSearchParams();

    const language = String(state.filterLanguage && state.filterLanguage.value || "").trim();
    const owner = String(state.filterOwner && state.filterOwner.value || "").trim();
    const processUri = String(state.filterProcessUri && state.filterProcessUri.value || "").trim();
    const institution = String(state.filterInstitution && state.filterInstitution.value || "").trim();
    const scenarioUri = String(state.filterScenarioUri && state.filterScenarioUri.value || "").trim();
    const datasetUri = String(state.filterDatasetUri && state.filterDatasetUri.value || "").trim();
    if (language !== "") {
      params.set("language", language);
    }
    if (owner !== "") {
      params.set("owner", owner);
    }
    if (processUri !== "") {
      params.set("processUri", processUri);
    }
    if (institution !== "") {
      params.set("institution", institution);
    }
    if (scenarioUri !== "") {
      params.set("scenarioUri", scenarioUri);
    }
    if (datasetUri !== "") {
      params.set("datasetUri", datasetUri);
    }
    params.set("limit", "200");
    params.set("offset", "0");

    const url = state.endpoint + (state.endpoint.indexOf("?") === -1 ? "?" : "&") + params.toString();

    try {
      const response = await fetch(url, {
        method: "GET",
        credentials: "same-origin",
      });

      const payload = await parseResponsePayload(response);
      if (!response.ok || !payload || payload.isSuccessful === false) {
        const message = getIssueMessage(payload, "Unable to load analytical tool collection.");
        setFeedback(state, "error", message);
        renderTable(state, []);
        return;
      }

      const tools = Array.isArray(payload.body) ? payload.body : [];
      state.lastTools = tools;
      mergeProcessEntries(state, tools);
      refreshFacetOptions(state, tools);
      renderTable(state, tools);

      const total = Number(payload.pagination && payload.pagination.total || tools.length || 0);
      setFeedback(state, "success", "Loaded " + total + " analytical tool(s).");
    } catch (error) {
      setFeedback(state, "error", "Failed to load analytical tool collection.");
      renderTable(state, []);
    }
  };

  const resetEditorForm = function (state) {
    if (state.editorForm) {
      state.editorForm.reset();
    }
    if (state.toolUri) {
      state.toolUri.value = "";
    }
    if (state.toolProcessName) {
      state.toolProcessName.value = String(state.initialProcessDisplayValue || "").trim();
    }
    if (state.toolProcessUri) {
      state.toolProcessUri.value = String(state.initialProcessUri || "").trim();
    }
    if (state.toolSourceFile) {
      state.toolSourceFile.value = "";
    }
    if (state.toolLanguage) {
      state.toolLanguage.value = "";
    }
    if (state.toolOwnerPersonUri) {
      state.toolOwnerPersonUri.value = String(state.currentUserPersonUri || "").trim();
    }
  };

  const applySelectedSourceFile = function (state) {
    const file = state.toolSourceFile && state.toolSourceFile.files && state.toolSourceFile.files.length > 0
      ? state.toolSourceFile.files[0]
      : null;

    if (!file) {
      return;
    }

    const fileName = String(file.name || "").trim();
    if (fileName !== "") {
      if (state.toolArtifactFilename) {
        state.toolArtifactFilename.value = fileName;
      }
      if (state.toolLanguage) {
        const inferredLanguage = deriveLanguageFromFilename(fileName);
        if (inferredLanguage !== "") {
          state.toolLanguage.value = inferredLanguage;
        }
      }
    }
  };

  const syncProcessUriFromInput = function (state) {
    const raw = String(state.toolProcessName && state.toolProcessName.value || "").trim();
    const uri = extractUriFromAutocompleteValue(raw);
    if (state.toolProcessUri) {
      state.toolProcessUri.value = uri;
    }
    return uri;
  };

  const fillEditorForm = function (state, tool) {
    if (!tool) {
      return;
    }

    if (state.toolUri) {
      state.toolUri.value = String(tool.toolUri || "");
    }
    if (state.toolVersion) {
      state.toolVersion.value = String(tool.version || "");
    }
    if (state.toolLanguage) {
      state.toolLanguage.value = String(tool.language || "");
    }
    if (state.toolProcessUri) {
      state.toolProcessUri.value = String(tool.processUri || "");
    }
    if (state.toolProcessName) {
      const processUri = String(tool.processUri || "").trim();
      const processLabel = String(tool.processLabel || "").trim();
      state.toolProcessName.value = processLabel !== "" && processUri !== ""
        ? processLabel + " [" + processUri + "]"
        : processUri;
    }
    if (state.toolOwnerPersonUri) {
      state.toolOwnerPersonUri.value = String(tool.ownerPersonUri || "");
    }
    if (state.toolArtifactFilename) {
      state.toolArtifactFilename.value = String(tool.artifactFilename || "");
    }
    if (state.toolDescription) {
      state.toolDescription.value = String(tool.description || "");
    }
  };

  const postAction = async function (state, payload) {
    const response = await fetch(state.endpoint, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
      },
      body: JSON.stringify(payload),
    });

    const parsed = await parseResponsePayload(response);
    return {
      ok: response.ok,
      payload: parsed,
    };
  };

  const saveTool = async function (state) {
    const sourceFile = state.toolSourceFile && state.toolSourceFile.files && state.toolSourceFile.files.length > 0
      ? state.toolSourceFile.files[0]
      : null;

    if (sourceFile) {
      applySelectedSourceFile(state);
    }

    const toolUri = String(state.toolUri && state.toolUri.value || "").trim();
    const isCreate = toolUri === "";

    if (isCreate && !sourceFile) {
      setFeedback(state, "warning", "A local source file is required when adding a new tool.");
      return;
    }

    const processUri = syncProcessUriFromInput(state);
    const ownerPersonUri = normalizeUriFieldValue(state.toolOwnerPersonUri);

    const uriChecks = [
      validateOptionalUriField(processUri, "Process URI"),
      validateOptionalUriField(ownerPersonUri, "Owner Person URI")
    ];

    if (processUri === "") {
      setFeedback(state, "warning", "Process Name is required. Select an autocomplete option or paste a process URI.");
      return;
    }

    for (let i = 0; i < uriChecks.length; i += 1) {
      if (uriChecks[i] && uriChecks[i].ok === false) {
        setFeedback(state, "warning", String(uriChecks[i].message || "One URI field is invalid."));
        return;
      }
    }

    let sourceCode = "";
    let sourceFilename = "";
    let inferredLanguage = "";
    if (sourceFile) {
      if (Number(sourceFile.size || 0) > 1024 * 1024) {
        setFeedback(state, "warning", "Selected source file is too large (max 1 MB).");
        return;
      }

      sourceFilename = String(sourceFile.name || "").trim();
      sourceCode = await sourceFile.text();
      inferredLanguage = deriveLanguageFromFilename(sourceFilename);
      if (state.toolLanguage) {
        state.toolLanguage.value = inferredLanguage;
      }

      if (sourceCode.indexOf("\u0000") !== -1) {
        setFeedback(state, "warning", "Selected source file appears to be binary. Please choose a text source file.");
        return;
      }
    }

    if (!sourceFile) {
      inferredLanguage = String(state.toolLanguage && state.toolLanguage.value || "").trim();
    }

    const inferredName = sourceFilename !== ""
      ? sourceFilename
      : String(state.toolArtifactFilename && state.toolArtifactFilename.value || "").trim();
    if (inferredName === "") {
      setFeedback(state, "warning", "Tool filename is required to infer the tool label.");
      return;
    }

    const releaseDateNow = new Date().toISOString().slice(0, 10);

    const institution = String(state.currentUserInstitutionUri || state.currentUserInstitutionLabel || "").trim();

    const payload = {
      action: "upsert",
      tool: {
        toolUri: toolUri,
        name: inferredName,
        processUri: processUri,
        ownerPersonUri: ownerPersonUri,
        version: String(state.toolVersion && state.toolVersion.value || "").trim(),
        language: inferredLanguage,
        status: "current",
        releaseDate: releaseDateNow,
        artifactFilename: String(state.toolArtifactFilename && state.toolArtifactFilename.value || "").trim(),
        institution: institution,
        description: String(state.toolDescription && state.toolDescription.value || "").trim(),
      },
    };

    if (sourceFilename !== "") {
      payload.tool.sourceFilename = sourceFilename;
    }

    if (sourceCode !== "") {
      payload.tool.sourceCode = sourceCode;
      payload.tool.sourceCodeEncoding = "text/plain";
    }

    const result = await postAction(state, payload);
    if (!result.ok || !result.payload || result.payload.isValid === false) {
      const message = getIssueMessage(result.payload, "Unable to save analytical tool.");
      setFeedback(state, "error", message);
      return;
    }

    const savedToolUri = String(result.payload.tool && result.payload.tool.toolUri || "").trim();
    setFeedback(state, "success", "Analytical tool saved successfully.");
    if (savedToolUri !== "" && state.toolUri) {
      state.toolUri.value = savedToolUri;
    }

    if (state.mode === "editor" && state.collectionPageUrl) {
      window.location.href = state.collectionPageUrl;
      return;
    }

    await loadTools(state);
  };

  const executeRowAction = async function (state, action, toolUri) {
    const normalizedAction = String(action || "").trim().toLowerCase();
    const normalizedToolUri = String(toolUri || "").trim();

    if (normalizedToolUri === "") {
      setFeedback(state, "warning", "Missing tool URI for this action.");
      return;
    }

    if (normalizedAction === "edit") {
      if (state.editorPageUrl) {
        const editUrl = state.editorPageUrl + (state.editorPageUrl.indexOf("?") === -1 ? "?" : "&") + "toolUri=" + encodeURIComponent(normalizedToolUri);
        window.location.href = editUrl;
        return;
      }

      fillEditorForm(state, state.toolsByUri[normalizedToolUri] || null);
      return;
    }

    if (normalizedAction === "remove") {
      if (!window.confirm("Remove this analytical tool from the collection?")) {
        return;
      }

      const removeResult = await postAction(state, {
        action: "remove",
        toolUri: normalizedToolUri,
      });

      if (!removeResult.ok || !removeResult.payload || removeResult.payload.isSuccessful === false) {
        const message = getIssueMessage(removeResult.payload, "Unable to remove analytical tool.");
        setFeedback(state, "error", message);
        return;
      }

      setFeedback(state, "success", "Analytical tool removed successfully.");
      await loadTools(state);
      return;
    }
  };

  const loadSingleToolForEditor = async function (state, toolUri) {
    const normalizedToolUri = String(toolUri || "").trim();
    if (normalizedToolUri === "") {
      return;
    }

    const params = new URLSearchParams();
    params.set("toolUri", normalizedToolUri);
    params.set("limit", "1");
    const url = state.endpoint + (state.endpoint.indexOf("?") === -1 ? "?" : "&") + params.toString();

    try {
      const response = await fetch(url, {
        method: "GET",
        credentials: "same-origin",
      });
      const payload = await parseResponsePayload(response);
      if (!response.ok || !payload || payload.isSuccessful === false) {
        setFeedback(state, "error", "Unable to load selected tool for editing.");
        return;
      }

      const tools = Array.isArray(payload.body) ? payload.body : [];
      const match = tools.find(function (tool) {
        return String(tool.toolUri || "").trim() === normalizedToolUri;
      });

      if (!match) {
        setFeedback(state, "warning", "Selected tool was not found.");
        return;
      }

      fillEditorForm(state, match);
    } catch {
      setFeedback(state, "error", "Unable to load selected tool for editing.");
    }
  };

  Drupal.behaviors.cttToolsRepository = {
    attach: function (context) {
      once("ctt-tools-repository", "#ctt-tools-repository-page", context).forEach(function (root) {
        const settings = (drupalSettings && drupalSettings.cttToolsRepository) ? drupalSettings.cttToolsRepository : {};
        const endpoint = String(settings.endpoint || "").trim();
        const state = {
          root: root,
          endpoint: endpoint,
          processListEndpoint: String(settings.processListEndpoint || "").trim(),
          processEntries: [],
          toolsByUri: {},
          feedback: root.querySelector("#ctt-tools-feedback"),
          filterForm: root.querySelector("#ctt-tools-filter-form"),
          filterOwner: root.querySelector("#ctt-tools-filter-owner"),
          filterProcessUri: root.querySelector("#ctt-tools-filter-process-uri"),
          filterLanguage: root.querySelector("#ctt-tools-filter-language"),
          filterInstitution: root.querySelector("#ctt-tools-filter-institution"),
          filterScenarioUri: root.querySelector("#ctt-tools-filter-scenario-uri"),
          filterDatasetUri: root.querySelector("#ctt-tools-filter-dataset-uri"),
          editorForm: root.querySelector("#ctt-tools-editor-form"),
          toolUri: root.querySelector("#ctt-tool-uri"),
          toolVersion: root.querySelector("#ctt-tool-version"),
          toolLanguage: root.querySelector("#ctt-tool-language"),
          toolProcessName: root.querySelector("#ctt-tool-process-name"),
          toolProcessUri: root.querySelector("#ctt-tool-process-uri"),
          toolOwnerPersonUri: root.querySelector("#ctt-tool-owner-person-uri"),
          toolSourceFile: root.querySelector("#ctt-tool-source-file"),
          toolArtifactFilename: root.querySelector("#ctt-tool-artifact-filename"),
          toolDescription: root.querySelector("#ctt-tool-description"),
          resetButton: root.querySelector("#ctt-tool-reset"),
          tableBody: root.querySelector("#ctt-tools-repository-body"),
          mode: String(settings.mode || "collection").trim().toLowerCase(),
          editorPageUrl: String(settings.editorPageUrl || "").trim(),
          collectionPageUrl: String(settings.collectionPageUrl || "").trim(),
          editorToolUri: String(settings.editorToolUri || "").trim(),
          initialProcessUri: String(settings.initialProcessUri || "").trim(),
          initialProcessDisplayValue: String(settings.initialProcessDisplayValue || "").trim(),
          initialScenarioUri: String(settings.initialScenarioUri || "").trim(),
          currentUserEmail: String(settings.currentUserEmail || "").trim(),
          currentUserPersonUri: String(settings.currentUserPersonUri || "").trim(),
          currentUserInstitutionUri: String(settings.currentUserInstitutionUri || "").trim(),
          currentUserInstitutionLabel: String(settings.currentUserInstitutionLabel || "").trim(),
          lastTools: [],
        };

        if (state.filterScenarioUri && settings.initialScenarioUri) {
          state.filterScenarioUri.value = String(settings.initialScenarioUri);
        }

        if (state.filterForm) {
          state.filterForm.addEventListener("submit", function (event) {
            event.preventDefault();
            loadTools(state);
          });
        }

        if (state.filterScenarioUri) {
          state.filterScenarioUri.addEventListener("change", function () {
            refreshFacetOptions(state, state.lastTools || []);
          });
        }

        if (state.filterProcessUri) {
          state.filterProcessUri.addEventListener("change", function () {
            refreshFacetOptions(state, state.lastTools || []);
          });
        }

        if (state.filterOwner) {
          state.filterOwner.addEventListener("change", function () {
            refreshFacetOptions(state, state.lastTools || []);
          });
        }

        if (state.filterInstitution) {
          state.filterInstitution.addEventListener("change", function () {
            refreshFacetOptions(state, state.lastTools || []);
          });
        }

        if (state.filterLanguage) {
          state.filterLanguage.addEventListener("change", function () {
            refreshFacetOptions(state, state.lastTools || []);
          });
        }

        if (state.filterDatasetUri) {
          state.filterDatasetUri.addEventListener("change", function () {
            refreshFacetOptions(state, state.lastTools || []);
          });
        }

        if (state.editorForm) {
          state.editorForm.addEventListener("submit", function (event) {
            event.preventDefault();
            saveTool(state);
          });
        }

        if (state.toolProcessName) {
          state.toolProcessName.addEventListener("change", function () {
            syncProcessUriFromInput(state);
          });
          state.toolProcessName.addEventListener("blur", function () {
            syncProcessUriFromInput(state);
          });
        }

        if (state.toolSourceFile) {
          state.toolSourceFile.addEventListener("change", function () {
            applySelectedSourceFile(state);
          });
        }

        if (state.resetButton) {
          state.resetButton.addEventListener("click", function (event) {
            event.preventDefault();
            resetEditorForm(state);
            setFeedback(state, "", "");
          });
        }

        if (state.tableBody) {
          state.tableBody.addEventListener("click", function (event) {
            const target = event.target && event.target.closest("button[data-action]");
            if (!target) {
              return;
            }

            event.preventDefault();
            const action = target.getAttribute("data-action") || "";
            const toolUri = target.getAttribute("data-tool-uri") || "";
            executeRowAction(state, action, toolUri);
          });
        }

        if (state.mode === "editor") {
          if (state.toolOwnerPersonUri && String(state.toolOwnerPersonUri.value || "").trim() === "") {
            state.toolOwnerPersonUri.value = String(state.currentUserPersonUri || "").trim();
          }
          if (state.editorToolUri !== "") {
            loadSingleToolForEditor(state, state.editorToolUri);
          }
        } else {
          loadProcesses(state).then(function () {
            refreshFacetOptions(state, []);
            loadTools(state);
          });
        }
      });
    },
  };
})(Drupal, once, typeof drupalSettings === "undefined" ? {} : drupalSettings);
