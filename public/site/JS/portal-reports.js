(function () {
  if (document.body.getAttribute("data-page") !== "reports") return;

  const csrfToken = function () {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : "";
  };

  const loginHref = (window.srsLoginPath && window.srsLoginPath()) || "/portal/login";
  const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  const REPORT_POLL_MS = 8000;

  const request = function (url) {
    return fetch(url, {
      cache: "no-store",
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
    if (!body) return "The assay could not complete that request.";
    if (body.errors) {
      const keys = Object.keys(body.errors);
      if (keys.length) return body.errors[keys[0]][0];
    }
    return body.message || "The assay could not complete that request.";
  };

  const escapeHtml = function (value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  };

  const formatCount = function (value) {
    const number = Number(value);
    if (!Number.isFinite(number)) return "—";
    if (Number.isInteger(number)) return number.toLocaleString("en-GB");
    return number.toLocaleString("en-GB", { minimumFractionDigits: 1, maximumFractionDigits: 1 });
  };

  const animateValue = function (node, target, prefix, suffix) {
    if (!node) return;
    const safePrefix = prefix || "";
    const safeSuffix = suffix || "";
    const number = Number(target);
    if (!Number.isFinite(number)) {
      node.textContent = "—";
      return;
    }
    if (reduceMotion) {
      node.textContent = safePrefix + formatCount(number) + safeSuffix;
      return;
    }
    const duration = 900;
    const start = performance.now();
    const decimals = !Number.isInteger(number);
    const step = function (now) {
      const t = Math.min(1, (now - start) / duration);
      const eased = 1 - Math.pow(1 - t, 3);
      const value = number * eased;
      node.textContent = safePrefix + (decimals ? value.toFixed(1) : Math.round(value).toLocaleString("en-GB")) + safeSuffix;
      if (t < 1) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
  };

  const setText = function (selector, value) {
    const node = document.querySelector(selector);
    if (node) node.textContent = value == null || value === "" ? "—" : String(value);
  };

  const setNotice = function (message) {
    const node = document.querySelector("[data-report-notice]");
    if (node) node.textContent = message || "";
  };

  const setDrawNotice = function (message) {
    const node = document.querySelector("[data-draw-notice]");
    if (node) node.textContent = message || "";
  };

  const clockStamp = function (value) {
    if (!value) return "";
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return "";
    return date.toLocaleString("en-GB", {
      timeZone: "Africa/Lagos",
      hour: "2-digit",
      minute: "2-digit",
      hour12: false
    });
  };

  const todayLagos = function () {
    return new Date().toLocaleDateString("en-CA", { timeZone: "Africa/Lagos" });
  };

  const sessionSelect = document.querySelector("[data-report-session]");
  const termSelect = document.querySelector("[data-report-term]");
  const formSelect = document.querySelector("[data-report-form]");
  const statusSelect = document.querySelector("[data-report-status]");
  const fromInput = document.querySelector("[data-report-from]");
  const toInput = document.querySelector("[data-report-to]");
  const title = document.querySelector("[data-report-title]");
  const head = document.querySelector("[data-report-head]");
  const body = document.querySelector("[data-report-body]");
  const generateBtn = document.querySelector("[data-generate-report]");
  const exportBtn = document.querySelector("[data-export-report]");
  const printBtn = document.querySelector("[data-print-report]");

  let catalogue = { sessions: [], offerings: [], kinds: [] };
  let kind = "roll";
  let lastQuery = "";
  let drawToken = 0;
  let assayInFlight = false;
  let firstPaint = true;
  let pollTimer = null;

  const fillSelect = function (node, rows, valueKey, labelKey, placeholder, selected) {
    if (!node) return;
    const current = selected == null ? node.value : String(selected);
    node.innerHTML = '<option value="">' + placeholder + "</option>" + (rows || []).map(function (row) {
      return '<option value="' + escapeHtml(row[valueKey]) + '">' + escapeHtml(row[labelKey]) + "</option>";
    }).join("");
    if (current) node.value = current;
  };

  const selectedSession = function () {
    const id = sessionSelect && sessionSelect.value
      ? sessionSelect.value
      : catalogue.current_academic_session_id;
    return (catalogue.sessions || []).find(function (row) {
      return String(row.id) === String(id);
    }) || null;
  };

  const offeringsForSession = function () {
    const sessionId = sessionSelect && sessionSelect.value;
    return (catalogue.offerings || []).filter(function (row) {
      return !sessionId || String(row.academic_session_id) === String(sessionId);
    });
  };

  const paintKindButtons = function () {
    document.querySelectorAll("[data-draw]").forEach(function (button) {
      button.classList.toggle("is-active", button.getAttribute("data-draw") === kind);
    });
  };

  const syncFilters = function () {
    const needsFees = kind === "fees";
    const needsRange = kind === "attendance" || kind === "staff";
    if (termSelect) termSelect.hidden = !needsFees;
    if (statusSelect) statusSelect.hidden = !needsFees;
    if (fromInput) fromInput.hidden = !needsRange;
    if (toInput) toInput.hidden = !needsRange;

    fillSelect(formSelect, offeringsForSession(), "id", "name", "All forms");
    fillSelect(termSelect, (selectedSession() && selectedSession().terms) || [], "id", "name", "All terms");

    if (needsFees && termSelect && !termSelect.value && catalogue.current_term_id) {
      termSelect.value = String(catalogue.current_term_id);
    }
    if (needsRange && fromInput && !fromInput.getAttribute("data-touched")) {
      fromInput.value = kind === "staff" ? todayLagos() : (catalogue.from || todayLagos());
    }
    if (needsRange && toInput && !toInput.getAttribute("data-touched")) {
      toInput.value = kind === "staff" ? todayLagos() : (catalogue.to || todayLagos());
    }
  };

  const queryString = function () {
    const params = new URLSearchParams();
    params.set("kind", kind);
    if (sessionSelect && sessionSelect.value) params.set("academic_session_id", sessionSelect.value);
    if (formSelect && formSelect.value) params.set("class_section_offering_id", formSelect.value);
    if (termSelect && !termSelect.hidden && termSelect.value) params.set("term_id", termSelect.value);
    if (statusSelect && !statusSelect.hidden && statusSelect.value) params.set("status", statusSelect.value);
    if (fromInput && !fromInput.hidden && fromInput.value) params.set("from", fromInput.value);
    if (toInput && !toInput.hidden && toInput.value) params.set("to", toInput.value);
    return params.toString();
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

  const generate = function (silent) {
    const token = ++drawToken;
    if (!silent) setDrawNotice("");
    if (generateBtn && !silent) generateBtn.disabled = true;
    const query = queryString();

    return request("/api/v1/portal-reports/generate?" + query).then(function (result) {
      if (token !== drawToken) return;
      if (generateBtn) generateBtn.disabled = false;
      if (!result.ok) {
        if (!silent) setDrawNotice(firstError(result.body));
        if (exportBtn) exportBtn.disabled = true;
        if (printBtn) printBtn.disabled = true;
        return;
      }
      lastQuery = query;
      setDrawNotice("");
      paintTable(result.body.data || {});
      if (exportBtn) exportBtn.disabled = false;
      if (printBtn) printBtn.disabled = false;
    }).catch(function () {
      if (token !== drawToken) return;
      if (generateBtn) generateBtn.disabled = false;
      if (!silent) setDrawNotice("The assay could not draw that paper.");
    });
  };

  const refreshDraw = function () {
    if (!lastQuery) return Promise.resolve();
    const token = ++drawToken;
    return request("/api/v1/portal-reports/generate?" + lastQuery).then(function (result) {
      if (token !== drawToken) return;
      if (!result.ok || !result.body || !result.body.data) return;
      paintTable(result.body.data);
      if (exportBtn) exportBtn.disabled = false;
      if (printBtn) printBtn.disabled = false;
    }).catch(function () {});
  };

  const paintCells = function (cells) {
    (cells || []).forEach(function (cell) {
      const valueNode = document.querySelector('[data-cell-value="' + cell.key + '"]');
      const copyNode = document.querySelector('[data-cell-copy="' + cell.key + '"]');
      if (valueNode) valueNode.textContent = cell.value == null || cell.value === "" ? "—" : String(cell.value);
      if (copyNode && cell.copy) copyNode.textContent = cell.copy;
    });
  };

  const paintWings = function (wings) {
    const list = document.querySelector("[data-report-wings]");
    if (!list) return;
    if (!wings || !wings.length) {
      list.innerHTML = "<p>No wings are sealed on the books.</p>";
      return;
    }
    list.innerHTML = wings.map(function (wing) {
      const attendance = wing.attendance_percent == null ? "No roll yet" : formatCount(wing.attendance_percent) + "% in";
      const href = "/portal/" + encodeURIComponent(wing.slug || "");
      return '<a class="ticket" href="' + href + '"><div class="ticket-code">'
        + escapeHtml((wing.name || "").slice(0, 3).toUpperCase())
        + "</div><div><h3>"
        + escapeHtml(wing.name || "Wing")
        + "</h3><p>"
        + escapeHtml((wing.pupils || 0) + " pupils · " + (wing.forms || 0) + " forms · " + attendance)
        + '</p></div><span class="badge">'
        + escapeHtml(wing.outstanding || "₦0")
        + "</span></a>";
    }).join("");
  };

  const paintPipeline = function (rows) {
    const list = document.querySelector("[data-report-pipeline]");
    if (!list) return;
    const live = (rows || []).filter(function (row) { return Number(row.count) > 0; });
    if (!live.length) {
      list.innerHTML = "<p>No admission papers on this year yet.</p>";
      return;
    }
    list.innerHTML = live.map(function (row) {
      return '<article class="ticket"><div class="ticket-code">'
        + escapeHtml(String(row.count))
        + "</div><div><h3>"
        + escapeHtml(row.label || row.status)
        + "</h3><p>On the admissions chute</p></div></article>";
    }).join("");
  };

  const paintWeek = function (days) {
    const root = document.querySelector("[data-report-week]");
    if (!root) return;
    if (!days || !days.length) {
      root.innerHTML = "<p>No attendance has been marked this week.</p>";
      return;
    }
    root.innerHTML = days.map(function (day) {
      const percent = day.percent == null ? 0 : Number(day.percent);
      const label = day.future ? "—" : (day.percent == null ? "—" : formatCount(day.percent) + "%");
      const copy = day.future ? "Ahead" : (day.marked ? day.in + " in of " + day.marked : "No roll");
      return '<div class="assay-day"><span>'
        + escapeHtml(day.label)
        + '</span><div class="assay-track" title="' + escapeHtml(copy) + '"><span class="assay-fill" style="width:'
        + Math.max(0, Math.min(100, percent))
        + '%"></span></div><strong>'
        + escapeHtml(label)
        + "</strong></div>";
    }).join("");
  };

  const paint = function (data) {
    if (!data) return;
    const metrics = data.metrics || {};
    const attendance = document.querySelector('[data-metric="attendance"]');
    const fees = document.querySelector('[data-metric="fees"]');
    const admissions = document.querySelector('[data-metric="admissions"]');
    const staff = document.querySelector('[data-metric="staff"]');

    if (firstPaint) {
      if (metrics.attendance_percent == null) setText('[data-metric="attendance"]', "—");
      else animateValue(attendance, metrics.attendance_percent, "", "%");
      if (metrics.fees_percent == null) {
        if (fees) fees.textContent = metrics.fees_label || "—";
      } else {
        animateValue(fees, metrics.fees_percent, "", "%");
      }
      animateValue(admissions, metrics.admissions, "", "");
      if (metrics.staff_percent == null) {
        if (staff) staff.textContent = metrics.staff_present == null ? "—" : String(metrics.staff_present);
      } else {
        animateValue(staff, metrics.staff_percent, "", "%");
      }
      firstPaint = false;
    } else {
      if (attendance) {
        attendance.textContent = metrics.attendance_percent == null ? "—" : formatCount(metrics.attendance_percent) + "%";
      }
      if (fees) {
        fees.textContent = metrics.fees_percent == null
          ? (metrics.fees_label || "—")
          : formatCount(metrics.fees_percent) + "%";
      }
      if (admissions) admissions.textContent = formatCount(metrics.admissions);
      if (staff) {
        staff.textContent = metrics.staff_percent == null
          ? (metrics.staff_present == null ? "—" : String(metrics.staff_present))
          : formatCount(metrics.staff_percent) + "%";
      }
    }

    setText('[data-metric-delta="attendance"]', metrics.attendance_delta);
    setText('[data-metric-delta="fees"]', metrics.fees_delta);
    setText('[data-metric-delta="admissions"]', metrics.admissions_delta);
    setText('[data-metric-delta="staff"]', metrics.staff_delta);

    const house = [data.session, data.term].filter(Boolean).join(" · ");
    const drawn = clockStamp(data.drawn_at);
    setText("[data-report-house]", house || "No session sealed yet");
    setText("[data-report-copy]", drawn
      ? "Live ledger for the office. Last drawn at " + drawn + " Lagos."
      : "Roll, fees, attendance, and the year’s pulse — drawn live from the school ledger.");

    if (data.school) {
      document.title = "Reports | " + data.school;
    }

    const ledger = data.ledger || {};
    setText('[data-ledger="invoiced"]', ledger.invoiced);
    setText('[data-ledger="collected"]', ledger.collected);
    setText('[data-ledger="outstanding"]', ledger.outstanding);
    setText('[data-ledger="percent"]', ledger.percent == null ? "—" : formatCount(ledger.percent) + "%");
    setText('[data-ledger="paid-in-full"]', ledger.paid_in_full_count == null ? "—" : formatCount(ledger.paid_in_full_count));
    setText('[data-ledger="partial"]', ledger.partially_paid_count == null ? "—" : formatCount(ledger.partially_paid_count));
    setText('[data-ledger="unpaid"]', ledger.outstanding_count == null ? "—" : formatCount(ledger.outstanding_count));

    paintCells(data.cells);
    paintWings(data.wings);
    paintPipeline(data.pipeline);
    paintWeek(data.week);
  };

  const loadAssay = function () {
    if (assayInFlight) return Promise.resolve();
    assayInFlight = true;
    return request("/api/v1/portal-reports").then(function (result) {
      if (!result.ok || !result.body || !result.body.data) {
        setNotice("The assay could not read the school ledger.");
        setText("[data-report-house]", "The ledger could not be opened.");
        return;
      }
      setNotice("");
      paint(result.body.data);
    }).catch(function () {
      setNotice("The assay could not read the school ledger.");
    }).then(function () {
      assayInFlight = false;
    });
  };

  const tick = function () {
    return loadAssay().then(refreshDraw);
  };

  const startPoll = function () {
    window.clearInterval(pollTimer);
    pollTimer = window.setInterval(tick, REPORT_POLL_MS);
  };

  const chooseKind = function (next) {
    kind = next || "roll";
    paintKindButtons();
    syncFilters();
    generate(false);
  };

  const cellsRoot = document.querySelector("[data-report-cells]");
  if (cellsRoot) {
    cellsRoot.addEventListener("click", function (event) {
      const button = event.target.closest("[data-draw]");
      if (!button) return;
      chooseKind(button.getAttribute("data-draw"));
    });
  }

  if (sessionSelect) {
    sessionSelect.addEventListener("change", function () {
      if (formSelect) formSelect.value = "";
      if (termSelect) termSelect.value = "";
      syncFilters();
    });
  }
  if (fromInput) fromInput.addEventListener("change", function () { fromInput.setAttribute("data-touched", "1"); });
  if (toInput) toInput.addEventListener("change", function () { toInput.setAttribute("data-touched", "1"); });
  if (generateBtn) generateBtn.addEventListener("click", function () { generate(false); });
  if (exportBtn) {
    exportBtn.addEventListener("click", function () {
      if (!lastQuery) return;
      window.location.href = "/api/v1/portal-reports/export?" + lastQuery;
    });
  }
  if (printBtn) {
    printBtn.addEventListener("click", function () {
      window.print();
    });
  }

  document.addEventListener("visibilitychange", function () {
    if (document.hidden) {
      window.clearInterval(pollTimer);
      return;
    }
    tick();
    startPoll();
  });

  request("/api/v1/portal-reports/catalogue").then(function (result) {
    if (result.ok && result.body && result.body.data) {
      catalogue = result.body.data;
      fillSelect(sessionSelect, catalogue.sessions || [], "id", "name", "Current session", catalogue.current_academic_session_id);
      syncFilters();
      paintKindButtons();
    }
    return tick();
  }).then(function () {
    return generate(true);
  }).then(startPoll);
})();
