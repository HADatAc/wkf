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

  const normalizeUriFieldValue = function (element) {
    if (!element) {
      return "";
    }

    const normalized = String(element.value || "").trim();
    element.value = normalized;
    return normalized;
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

  const updateLanguageFilter = function (state, tools) {
    if (!state.filterLanguage) {
      return;
    }

    const previous = String(state.filterLanguage.value || "").trim();
    const languages = new Set();

    tools.forEach(function (tool) {
      const language = String(tool.language || "").trim();
      if (language !== "") {
        languages.add(language);
      }
    });

    const sorted = Array.from(languages).sort(function (a, b) {
      return a.localeCompare(b);
    });

    let options = '<option value="">All languages</option>';
    sorted.forEach(function (language) {
      const selected = previous.toLowerCase() === language.toLowerCase() ? ' selected="selected"' : "";
      options += '<option value="' + escapeHtml(language) + '"' + selected + '>' + escapeHtml(language) + "</option>";
    });

    state.filterLanguage.innerHTML = options;
  };

  const getStudyUri = function (state) {
    if (!state.filterStudyUri) {
      return "";
    }

    return String(state.filterStudyUri.value || "").trim();
  };

  const renderTable = function (state, tools) {
    if (!state.tableBody) {
      return;
    }

    state.toolsByUri = {};

    if (!Array.isArray(tools) || tools.length === 0) {
      state.tableBody.innerHTML = '<tr><td colspan="13" class="text-center text-muted">No analytical tools were found for the selected filters.</td></tr>';
      return;
    }

    const studyUri = getStudyUri(state);
    const actionsEnabled = studyUri !== "";

    const rows = tools.map(function (tool) {
      const toolUri = String(tool.toolUri || "").trim();
      const tags = toTagArray(tool.tags).join(", ");
      const author = String(tool.author || "").trim();
      const institution = String(tool.institution || "").trim();
      const scenarioUri = String(tool.scenarioUri || "").trim();
      const datasetUri = String(tool.datasetUri || "").trim();
      const releaseDate = String(tool.releaseDate || "").trim();
      state.toolsByUri[toolUri] = tool;

      let actionButtons = ""
        + '<button type="button" class="btn btn-sm btn-outline-primary" data-action="edit" data-tool-uri="' + escapeHtml(toolUri) + '">Edit</button>'
        + '<button type="button" class="btn btn-sm btn-outline-danger" data-action="remove" data-tool-uri="' + escapeHtml(toolUri) + '">Remove</button>';

      if (actionsEnabled) {
        const isAssociated = Boolean(tool.isAssociated);
        actionButtons += '<button type="button" class="btn btn-sm ' + (isAssociated ? "btn-outline-secondary" : "btn-outline-success") + '" data-action="' + (isAssociated ? "dissociate" : "associate") + '" data-tool-uri="' + escapeHtml(toolUri) + '">' + (isAssociated ? "Dissociate" : "Associate") + "</button>";
      }

      return ""
        + "<tr>"
        + "<td>" + escapeHtml(tool.name || "") + "</td>"
        + "<td>" + escapeHtml(tool.version || "") + "</td>"
        + "<td>" + escapeHtml(tool.language || "") + "</td>"
        + "<td>" + escapeHtml(tool.status || "") + "</td>"
        + "<td>" + escapeHtml(author) + "</td>"
        + "<td>" + escapeHtml(institution) + "</td>"
        + "<td class=\"ctt-tools-uri\">" + escapeHtml(scenarioUri) + "</td>"
        + "<td class=\"ctt-tools-uri\">" + escapeHtml(datasetUri) + "</td>"
        + "<td>" + escapeHtml(releaseDate) + "</td>"
        + "<td class=\"ctt-tools-uri\">" + escapeHtml(toolUri) + "</td>"
        + "<td>" + escapeHtml(tags) + "</td>"
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
      state.tableBody.innerHTML = '<tr><td colspan="13" class="text-center text-muted">Loading tools catalog...</td></tr>';
    }

    const params = new URLSearchParams();

    const q = String(state.filterQ && state.filterQ.value || "").trim();
    const status = String(state.filterStatus && state.filterStatus.value || "").trim();
    const language = String(state.filterLanguage && state.filterLanguage.value || "").trim();
    const author = String(state.filterAuthor && state.filterAuthor.value || "").trim();
    const institution = String(state.filterInstitution && state.filterInstitution.value || "").trim();
    const scenarioUri = String(state.filterScenarioUri && state.filterScenarioUri.value || "").trim();
    const datasetUri = String(state.filterDatasetUri && state.filterDatasetUri.value || "").trim();
    const dateFrom = String(state.filterDateFrom && state.filterDateFrom.value || "").trim();
    const dateTo = String(state.filterDateTo && state.filterDateTo.value || "").trim();
    const studyUri = getStudyUri(state);

    if (q !== "") {
      params.set("q", q);
    }
    if (status !== "") {
      params.set("status", status);
    }
    if (language !== "") {
      params.set("language", language);
    }
    if (author !== "") {
      params.set("author", author);
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
    if (dateFrom !== "") {
      params.set("dateFrom", dateFrom);
    }
    if (dateTo !== "") {
      params.set("dateTo", dateTo);
    }
    if (studyUri !== "") {
      params.set("studyUri", studyUri);
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
        const message = getIssueMessage(payload, "Unable to load analytical tools catalog.");
        setFeedback(state, "error", message);
        renderTable(state, []);
        return;
      }

      const tools = Array.isArray(payload.body) ? payload.body : [];
      updateLanguageFilter(state, tools);
      renderTable(state, tools);

      const total = Number(payload.pagination && payload.pagination.total || tools.length || 0);
      setFeedback(state, "success", "Loaded " + total + " analytical tool(s).");
    } catch (error) {
      setFeedback(state, "error", "Failed to load analytical tools catalog.");
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
    if (state.toolStatus && state.statuses.length > 0) {
      state.toolStatus.value = state.statuses[0];
    }
  };

  const fillEditorForm = function (state, tool) {
    if (!tool) {
      return;
    }

    if (state.toolUri) {
      state.toolUri.value = String(tool.toolUri || "");
    }
    if (state.toolName) {
      state.toolName.value = String(tool.name || "");
    }
    if (state.toolVersion) {
      state.toolVersion.value = String(tool.version || "");
    }
    if (state.toolLanguage) {
      state.toolLanguage.value = String(tool.language || "");
    }
    if (state.toolStatus) {
      state.toolStatus.value = String(tool.status || "draft");
    }
    if (state.toolReleaseDate) {
      state.toolReleaseDate.value = String(tool.releaseDate || "");
    }
    if (state.toolAuthor) {
      state.toolAuthor.value = String(tool.author || "");
    }
    if (state.toolInstitution) {
      state.toolInstitution.value = String(tool.institution || "");
    }
    if (state.toolScenarioUri) {
      state.toolScenarioUri.value = String(tool.scenarioUri || "");
    }
    if (state.toolDatasetUri) {
      state.toolDatasetUri.value = String(tool.datasetUri || "");
    }
    if (state.toolSourceUri) {
      state.toolSourceUri.value = String(tool.sourceRepositoryUri || "");
    }
    if (state.toolArtifactFilename) {
      state.toolArtifactFilename.value = String(tool.artifactFilename || "");
    }
    if (state.toolArtifactUri) {
      state.toolArtifactUri.value = String(tool.artifactUri || "");
    }
    if (state.toolTags) {
      state.toolTags.value = toTagArray(tool.tags).join(", ");
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
    const name = String(state.toolName && state.toolName.value || "").trim();
    if (name === "") {
      setFeedback(state, "warning", "Tool name is required.");
      return;
    }

    const studyUri = normalizeUriFieldValue(state.filterStudyUri);
    const scenarioUri = normalizeUriFieldValue(state.toolScenarioUri);
    const datasetUri = normalizeUriFieldValue(state.toolDatasetUri);
    const sourceRepositoryUri = normalizeUriFieldValue(state.toolSourceUri);
    const artifactUri = normalizeUriFieldValue(state.toolArtifactUri);

    const uriChecks = [
      validateOptionalUriField(studyUri, "Study URI"),
      validateOptionalUriField(scenarioUri, "Scenario URI"),
      validateOptionalUriField(datasetUri, "Dataset URI"),
      validateOptionalUriField(sourceRepositoryUri, "Source Repository URI"),
      validateOptionalUriField(artifactUri, "Artifact URI")
    ];

    for (let i = 0; i < uriChecks.length; i += 1) {
      if (uriChecks[i] && uriChecks[i].ok === false) {
        setFeedback(state, "warning", String(uriChecks[i].message || "One URI field is invalid."));
        return;
      }
    }

    const payload = {
      action: "upsert",
      studyUri: studyUri || undefined,
      tool: {
        toolUri: String(state.toolUri && state.toolUri.value || "").trim(),
        name: name,
        version: String(state.toolVersion && state.toolVersion.value || "").trim(),
        language: String(state.toolLanguage && state.toolLanguage.value || "").trim(),
        status: String(state.toolStatus && state.toolStatus.value || "draft").trim().toLowerCase(),
        releaseDate: String(state.toolReleaseDate && state.toolReleaseDate.value || "").trim(),
        author: String(state.toolAuthor && state.toolAuthor.value || "").trim(),
        institution: String(state.toolInstitution && state.toolInstitution.value || "").trim(),
        scenarioUri: scenarioUri,
        datasetUri: datasetUri,
        sourceRepositoryUri: sourceRepositoryUri,
        artifactFilename: String(state.toolArtifactFilename && state.toolArtifactFilename.value || "").trim(),
        artifactUri: artifactUri,
        tags: toTagArray(state.toolTags && state.toolTags.value || ""),
        description: String(state.toolDescription && state.toolDescription.value || "").trim(),
      },
    };

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
      fillEditorForm(state, state.toolsByUri[normalizedToolUri] || null);
      setFeedback(state, "success", "Editing selected tool metadata.");
      return;
    }

    if (normalizedAction === "remove") {
      if (!window.confirm("Remove this analytical tool from the catalog?")) {
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

    if (normalizedAction === "associate" || normalizedAction === "dissociate") {
      const studyUri = getStudyUri(state);
      if (studyUri === "") {
        setFeedback(state, "warning", "Set a Study URI in filters before associating tools.");
        return;
      }

      const associationResult = await postAction(state, {
        action: normalizedAction,
        toolUri: normalizedToolUri,
        studyUri: studyUri,
      });

      if (!associationResult.ok || !associationResult.payload || associationResult.payload.isSuccessful === false) {
        const message = getIssueMessage(associationResult.payload, "Unable to update study association.");
        setFeedback(state, "error", message);
        return;
      }

      setFeedback(
        state,
        "success",
        normalizedAction === "associate"
          ? "Tool associated with current study."
          : "Tool dissociated from current study."
      );
      await loadTools(state);
    }
  };

  Drupal.behaviors.cttToolsRepository = {
    attach: function (context) {
      once("ctt-tools-repository", "#ctt-tools-repository-page", context).forEach(function (root) {
        const settings = (drupalSettings && drupalSettings.cttToolsRepository) ? drupalSettings.cttToolsRepository : {};
        const endpoint = String(settings.endpoint || "").trim();
        const statuses = Array.isArray(settings.statuses) ? settings.statuses : [];

        const state = {
          root: root,
          endpoint: endpoint,
          statuses: statuses,
          toolsByUri: {},
          feedback: root.querySelector("#ctt-tools-feedback"),
          filterForm: root.querySelector("#ctt-tools-filter-form"),
          filterQ: root.querySelector("#ctt-tools-filter-q"),
          filterStudyUri: root.querySelector("#ctt-tools-filter-study-uri"),
          filterLanguage: root.querySelector("#ctt-tools-filter-language"),
          filterStatus: root.querySelector("#ctt-tools-filter-status"),
          filterAuthor: root.querySelector("#ctt-tools-filter-author"),
          filterInstitution: root.querySelector("#ctt-tools-filter-institution"),
          filterScenarioUri: root.querySelector("#ctt-tools-filter-scenario-uri"),
          filterDatasetUri: root.querySelector("#ctt-tools-filter-dataset-uri"),
          filterDateFrom: root.querySelector("#ctt-tools-filter-date-from"),
          filterDateTo: root.querySelector("#ctt-tools-filter-date-to"),
          editorForm: root.querySelector("#ctt-tools-editor-form"),
          toolUri: root.querySelector("#ctt-tool-uri"),
          toolName: root.querySelector("#ctt-tool-name"),
          toolVersion: root.querySelector("#ctt-tool-version"),
          toolLanguage: root.querySelector("#ctt-tool-language"),
          toolStatus: root.querySelector("#ctt-tool-status"),
          toolReleaseDate: root.querySelector("#ctt-tool-release-date"),
          toolAuthor: root.querySelector("#ctt-tool-author"),
          toolInstitution: root.querySelector("#ctt-tool-institution"),
          toolScenarioUri: root.querySelector("#ctt-tool-scenario-uri"),
          toolDatasetUri: root.querySelector("#ctt-tool-dataset-uri"),
          toolSourceUri: root.querySelector("#ctt-tool-source-uri"),
          toolArtifactFilename: root.querySelector("#ctt-tool-artifact-filename"),
          toolArtifactUri: root.querySelector("#ctt-tool-artifact-uri"),
          toolTags: root.querySelector("#ctt-tool-tags"),
          toolDescription: root.querySelector("#ctt-tool-description"),
          resetButton: root.querySelector("#ctt-tool-reset"),
          tableBody: root.querySelector("#ctt-tools-repository-body"),
        };

        if (state.filterStudyUri && settings.initialStudyUri) {
          state.filterStudyUri.value = String(settings.initialStudyUri);
        }

        if (state.toolStatus && statuses.length > 0 && String(state.toolStatus.value || "").trim() === "") {
          state.toolStatus.value = String(statuses[0]);
        }

        if (state.filterForm) {
          state.filterForm.addEventListener("submit", function (event) {
            event.preventDefault();
            loadTools(state);
          });
        }

        if (state.editorForm) {
          state.editorForm.addEventListener("submit", function (event) {
            event.preventDefault();
            saveTool(state);
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

        loadTools(state);
      });
    },
  };
})(Drupal, once, typeof drupalSettings === "undefined" ? {} : drupalSettings);
