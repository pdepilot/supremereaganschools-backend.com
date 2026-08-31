(function () {
  if (document.body.getAttribute("data-page") !== "reports") return;

  const csrfToken = function () {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : "";
  };

  const loginHref = (window.srsLoginPath && window.srsLoginPath()) || "/staff/login";

  const request = function (url) {
    return fetch(url, {
      headers: {
        "Accept": "application/json",
        "X-Requested-With": "XMLHttpRequest",
        "X-XSRF-TOKEN": csrfToken()
      },
      credentials: "same-origin"
    }).then(function (response) {
      if (response.status === 401) {
        window.location.replace(loginHref);
        return { ok: false, status: 401, body: {} };
      }
      const type = response.headers.get("content-type") || "";
      if (type.indexOf("application/json") === -1) {
        return { ok: response.ok, status: response.status, body: {} };
      }
      return response.json().then(function (body) {
        return { ok: response.ok, status: response.status, body: body };
      }).catch(function () {
        return { ok: false, status: response.status, body: {} };
      });
    });
  };

  const firstError = function (body) {
    if (!body) return "The house could not complete that request.";
    if (body.errors) {
      const keys = Object.keys(body.errors);
      if (keys.length) return body.errors[keys[0]][0];
    }
    return body.message || "The house could not complete that request.";
  };

  const escapeHtml = function (value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  };

  const kindsNode = document.querySelector("[data-report-kinds]");
  const formSelect = document.querySelector("[data-report-form]");
  const subjectSelect = document.querySelector("[data-report-subject]");
  const paperSelect = document.querySelector("[data-report-paper]");
  const termSelect = document.querySelector("[data-report-term]");
  const fromInput = document.querySelector("[data-report-from]");
  const toInput = document.querySelector("[data-report-to]");
  const notice = document.querySelector("[data-report-notice]");
  const title = document.querySelector("[data-report-title]");
  const head = document.querySelector("[data-report-head]");
  const body = document.querySelector("[data-report-body]");
  const generateBtn = document.querySelector("[data-generate-report]");
  const exportBtn = document.querySelector("[data-export-report]");
  const printBtn = document.querySelector("[data-print-report]");
  let catalogue = { offerings: [], kinds: [], assessment_types: [], terms: [] };
  let kind = "roll";
  let lastQuery = "";

  const setMetric = function (key, value) {
    const node = document.querySelector('[data-report-metric="' + key + '"]');
    if (node) node.textContent = value == null || value === "" ? "—" : String(value);
  };

  const fillSelect = function (node, rows, valueKey, labelKey, placeholder, selected) {
    if (!node) return;
    node.innerHTML = '<option value="">' + placeholder + "</option>" + rows.map(function (row) {
      return '<option value="' + escapeHtml(row[valueKey]) + '">' + escapeHtml(row[labelKey]) + "</option>";
    }).join("");
    if (selected) node.value = String(selected);
  };

  const currentOffering = function () {
    const id = formSelect && formSelect.value;
    return (catalogue.offerings || []).find(function (row) { return String(row.id) === String(id); }) || null;
  };

  const syncFilters = function () {
    const needsSubject = kind === "marks" || kind === "results";
    const needsPaper = kind === "marks";
    const needsRange = kind === "attendance";
    const needsTerm = kind === "marks" || kind === "results";
    if (subjectSelect) subjectSelect.hidden = !needsSubject;
    if (paperSelect) paperSelect.hidden = !needsPaper;
    if (termSelect) termSelect.hidden = !needsTerm;
    if (fromInput) fromInput.hidden = !needsRange;
    if (toInput) toInput.hidden = !needsRange;

    const offering = currentOffering();
    fillSelect(subjectSelect, (offering && offering.subjects) || [], "id", "name", "Subject");
  };

  const queryString = function () {
    const params = new URLSearchParams();
    params.set("kind", kind);
    if (formSelect && formSelect.value) params.set("class_section_offering_id", formSelect.value);
    if (subjectSelect && !subjectSelect.hidden && subjectSelect.value) params.set("subject_id", subjectSelect.value);
    if (paperSelect && !paperSelect.hidden && paperSelect.value) params.set("assessment_type_id", paperSelect.value);
    if (termSelect && !termSelect.hidden && termSelect.value) params.set("term_id", termSelect.value);
    if (fromInput && !fromInput.hidden && fromInput.value) params.set("from", fromInput.value);
    if (toInput && !toInput.hidden && toInput.value) params.set("to", toInput.value);
    return params.toString();
  };

  const paintKinds = function () {
    if (!kindsNode) return;
    kindsNode.innerHTML = (catalogue.kinds || []).map(function (item) {
      return '<button class="report-kind' + (item.slug === kind ? " is-active" : "")
        + '" type="button" data-kind="' + escapeHtml(item.slug) + '"><strong>'
        + escapeHtml(item.label) + "</strong><span>" + escapeHtml(item.copy || "") + "</span></button>";
    }).join("");
  };

  const paintTable = function (report) {
    const columns = report.columns || [];
    const rows = report.rows || [];
    if (title) {
      title.hidden = false;
      title.textContent = report.title || "";
    }
    if (head) {
      head.innerHTML = "<tr>" + columns.map(function (column) {
        return "<th>" + escapeHtml(column.label) + "</th>";
      }).join("") + "</tr>";
    }
    if (!body) return;
    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="' + Math.max(columns.length, 1) + '">No rows on this paper yet.</td></tr>';
      return;
    }
    body.innerHTML = rows.map(function (row) {
      return "<tr>" + columns.map(function (column) {
        const value = row[column.key];
        return "<td>" + escapeHtml(value == null || value === "" ? "—" : value) + "</td>";
      }).join("") + "</tr>";
    }).join("");
  };

  const paintSummary = function (report) {
    const summary = (report && report.summary) || {};
    setMetric("pupils", summary.pupils != null ? summary.pupils : summary.total);
    setMetric("average", summary.average != null ? summary.average : null);
    setMetric("attendance", summary.percent != null ? summary.percent + "%" : null);
  };

  const generate = function () {
    if (notice) notice.textContent = "";
    if (!formSelect || !formSelect.value) {
      if (notice) notice.textContent = "Choose a form first.";
      return;
    }
    if (generateBtn) generateBtn.disabled = true;
    const query = queryString();
    request("/api/v1/staff-reports/generate?" + query).then(function (result) {
      if (generateBtn) generateBtn.disabled = false;
      if (!result.ok) {
        if (notice) notice.textContent = firstError(result.body);
        if (exportBtn) exportBtn.disabled = true;
        if (printBtn) printBtn.disabled = true;
        return;
      }
      lastQuery = query;
      paintTable(result.body.data || {});
      paintSummary(result.body.data || {});
      if (exportBtn) exportBtn.disabled = false;
      if (printBtn) printBtn.disabled = false;
    }).catch(function () {
      if (generateBtn) generateBtn.disabled = false;
      if (notice) notice.textContent = "Unable to reach the house.";
    });
  };

  if (kindsNode) {
    kindsNode.addEventListener("click", function (event) {
      const button = event.target.closest("[data-kind]");
      if (!button) return;
      kind = button.getAttribute("data-kind") || "roll";
      paintKinds();
      syncFilters();
    });
  }

  if (formSelect) formSelect.addEventListener("change", syncFilters);
  if (generateBtn) generateBtn.addEventListener("click", generate);

  if (exportBtn) {
    exportBtn.addEventListener("click", function () {
      if (!lastQuery) return;
      window.location.href = "/api/v1/staff-reports/export?" + lastQuery;
    });
  }

  if (printBtn) {
    printBtn.addEventListener("click", function () {
      window.print();
    });
  }

  request("/api/v1/staff-reports").then(function (result) {
    if (!result.ok) {
      if (body) body.innerHTML = "<tr><td>" + escapeHtml(firstError(result.body)) + "</td></tr>";
      return;
    }
    catalogue = result.body.data || catalogue;
    setMetric("forms", (catalogue.offerings || []).length);
    paintKinds();
    fillSelect(formSelect, catalogue.offerings || [], "id", "form", "Choose a form");
    fillSelect(paperSelect, catalogue.assessment_types || [], "id", "name", "Assessment");
    fillSelect(termSelect, catalogue.terms || [], "id", "name", "Term", catalogue.current_term_id);
    if (fromInput && catalogue.from) fromInput.value = catalogue.from;
    if (toInput && catalogue.to) toInput.value = catalogue.to;
    if ((catalogue.offerings || []).length === 1 && formSelect) {
      formSelect.value = String(catalogue.offerings[0].id);
    }
    syncFilters();
  });
})();
