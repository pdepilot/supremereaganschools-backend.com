(function () {
  const page = document.body.getAttribute("data-cbt-admin-page") || "";
  if (!page) return;

  const csrfToken = function () {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : "";
  };

  const escapeHtml = function (value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  };

  const firstError = function (body) {
    if (!body) return "The CBT admin desk could not complete that request.";
    if (body.errors) {
      const keys = Object.keys(body.errors);
      if (keys.length) return body.errors[keys[0]][0];
    }
    return body.message || "The CBT admin desk could not complete that request.";
  };

  const request = function (url, options) {
    const headers = Object.assign({
      "Accept": "application/json",
      "X-Requested-With": "XMLHttpRequest",
      "X-XSRF-TOKEN": csrfToken()
    }, options && options.headers);

    if (options && options.body && !headers["Content-Type"]) {
      headers["Content-Type"] = "application/json";
    }

    return fetch(url, Object.assign({
      credentials: "same-origin"
    }, options, { headers })).then(function (response) {
      return response.json().then(function (body) {
        if (response.status === 401) {
          window.location.replace("/cbt/login");
        }
        return { ok: response.ok, status: response.status, body: body };
      }).catch(function () {
        return { ok: false, status: response.status, body: {} };
      });
    }).catch(function () {
      return { ok: false, status: 0, body: { message: "Connection problem." } };
    });
  };

  const alertBox = document.querySelector("[data-cbt-alert]");
  const showAlert = function (message, kind) {
    if (!alertBox) return;
    alertBox.textContent = message || "";
    alertBox.classList.toggle("is-visible", !!message);
    alertBox.classList.toggle("is-info", kind === "info");
  };

  document.querySelectorAll("[data-cbt-logout]").forEach(function (link) {
    link.addEventListener("click", function (event) {
      event.preventDefault();
      request("/cbt/logout", { method: "POST", body: "{}" }).finally(function () {
        window.location.href = "/cbt/login?from=logout";
      });
    });
  });

  const pathId = function (segment) {
    const parts = window.location.pathname.split("/").filter(Boolean);
    const index = parts.indexOf(segment);
    return index >= 0 ? parts[index + 1] : null;
  };

  const formatWhen = function (iso) {
    if (!iso) return "—";
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return "—";
    return date.toLocaleString();
  };

  const toLocalInput = function (iso) {
    if (!iso) return "";
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return "";
    const pad = function (n) { return String(n).padStart(2, "0"); };
    return date.getFullYear() + "-" + pad(date.getMonth() + 1) + "-" + pad(date.getDate())
      + "T" + pad(date.getHours()) + ":" + pad(date.getMinutes());
  };

  const fromLocalInput = function (value) {
    if (!value) return null;
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return null;
    return date.toISOString();
  };

  let lookups = null;
  let capabilities = { manage: false, mark: false, proctor: false };
  let confirmResolver = null;

  const openModal = function (el) { if (el) el.classList.add("is-open"); };
  const closeModal = function (el) { if (el) el.classList.remove("is-open"); };

  const confirmAction = function (title, body) {
    const modal = document.querySelector("[data-confirm-modal]");
    if (!modal) {
      return Promise.resolve(window.confirm(body || title));
    }
    modal.querySelector("[data-confirm-title]").textContent = title || "Confirm";
    modal.querySelector("[data-confirm-body]").textContent = body || "";
    openModal(modal);
    return new Promise(function (resolve) {
      confirmResolver = resolve;
    });
  };

  const confirmModal = document.querySelector("[data-confirm-modal]");
  if (confirmModal) {
    confirmModal.querySelector("[data-confirm-cancel]")?.addEventListener("click", function () {
      closeModal(confirmModal);
      if (confirmResolver) confirmResolver(false);
      confirmResolver = null;
    });
    confirmModal.querySelector("[data-confirm-ok]")?.addEventListener("click", function () {
      closeModal(confirmModal);
      if (confirmResolver) confirmResolver(true);
      confirmResolver = null;
    });
  }

  const applyCapabilityGates = function () {
    document.querySelectorAll("[data-manage-only]").forEach(function (el) {
      el.hidden = !capabilities.manage;
      if (!capabilities.manage && el.tagName === "A") el.style.display = "none";
    });
  };

  const fillSelect = function (select, items, valueKey, labelKey, includeBlank) {
    if (!select) return;
    const current = select.value;
    const blank = includeBlank !== false;
    const options = [];
    if (blank) options.push('<option value="">' + escapeHtml(select.getAttribute("data-blank") || "Select…") + "</option>");
    (items || []).forEach(function (item) {
      const value = typeof item === "string" ? item : item[valueKey];
      const label = typeof item === "string" ? item : item[labelKey];
      options.push('<option value="' + escapeHtml(value) + '">' + escapeHtml(label) + "</option>");
    });
    select.innerHTML = options.join("");
    if (current) select.value = current;
  };

  const populateLookups = function () {
    if (!lookups) return;
    document.querySelectorAll('[data-lookup="subjects"]').forEach(function (el) {
      fillSelect(el, lookups.subjects, "id", "name", el.querySelector('option[value=""]') != null || !el.required);
    });
    document.querySelectorAll('[data-lookup="classes"]').forEach(function (el) {
      fillSelect(el, lookups.classes, "id", "name", el.querySelector('option[value=""]') != null || !el.required);
    });
    document.querySelectorAll('[data-lookup="forms"]').forEach(function (el) {
      fillSelect(el, lookups.forms || [], "id", "name", el.querySelector('option[value=""]') != null || !el.required);
    });
    document.querySelectorAll('[data-lookup="offerings"]').forEach(function (el) {
      fillSelect(el, lookups.offerings, "id", "label", el.querySelector('option[value=""]') != null || !el.required);
    });
    document.querySelectorAll('[data-lookup="sessions"]').forEach(function (el) {
      fillSelect(el, lookups.academic_sessions, "id", "name", el.querySelector('option[value=""]') != null || !el.required);
    });
    document.querySelectorAll('[data-lookup="terms"]').forEach(function (el) {
      fillSelect(el, lookups.terms, "id", "name", el.querySelector('option[value=""]') != null || !el.required);
    });
    document.querySelectorAll('[data-lookup="difficulties"]').forEach(function (el) {
      fillSelect(el, lookups.difficulties, null, null, true);
    });
    document.querySelectorAll('[data-lookup="question_types"]').forEach(function (el) {
      fillSelect(el, lookups.question_types, null, null, !el.required);
    });
    document.querySelectorAll('[data-lookup="exam_statuses"]').forEach(function (el) {
      fillSelect(el, lookups.exam_statuses, null, null, true);
    });
  };

  const loadBootstrap = function () {
    return Promise.all([
      request("/api/v1/me"),
      request("/api/v1/cbt/admin"),
      capabilities.manage || page === "home" || page === "questions" || page === "exams" || page === "exam" || page === "preview"
        || page === "results" || page === "monitor" || page === "attempts" || page === "reports" || page === "result-checkers"
        ? request("/api/v1/cbt/admin/lookups")
        : Promise.resolve({ ok: true, body: { data: null } })
    ]).then(function (parts) {
      const me = parts[0];
      const entry = parts[1];
      const look = parts[2];

      if (me.ok) {
        const user = me.body.data || {};
        const label = document.querySelector("[data-cbt-admin-user]");
        if (label) label.textContent = user.name || user.email || "Staff";
      }

      if (!entry.ok) {
        showAlert(firstError(entry.body));
        return null;
      }

      const data = entry.body.data || {};
      capabilities = data.capabilities || capabilities;
      applyCapabilityGates();

      if (look.ok && look.body.data) {
        lookups = look.body.data;
        populateLookups();
      } else if (capabilities.manage) {
        return request("/api/v1/cbt/admin/lookups").then(function (result) {
          if (result.ok) {
            lookups = result.body.data;
            populateLookups();
          }
          return data;
        });
      }

      return data;
    });
  };

  const renderPager = function (el, meta, onPage) {
    if (!el || !meta) return;
    el.innerHTML = "";
    if ((meta.last_page || 1) <= 1) {
      el.textContent = (meta.total || 0) + " total";
      return;
    }
    const prev = document.createElement("button");
    prev.type = "button";
    prev.className = "cbt-btn cbt-btn-ghost";
    prev.textContent = "Prev";
    prev.disabled = meta.current_page <= 1;
    prev.addEventListener("click", function () { onPage(meta.current_page - 1); });
    const next = document.createElement("button");
    next.type = "button";
    next.className = "cbt-btn cbt-btn-ghost";
    next.textContent = "Next";
    next.disabled = meta.current_page >= meta.last_page;
    next.addEventListener("click", function () { onPage(meta.current_page + 1); });
    const info = document.createElement("span");
    info.textContent = "Page " + meta.current_page + " / " + meta.last_page + " · " + meta.total + " total";
    el.appendChild(prev);
    el.appendChild(info);
    el.appendChild(next);
  };

  const statusBadge = function (status, availability, frozen) {
    const bits = [];
    if (status) bits.push('<span class="cbt-badge is-' + escapeHtml(status) + '">' + escapeHtml(status) + "</span>");
    if (availability && availability !== status) {
      bits.push('<span class="cbt-badge is-' + escapeHtml(availability) + '">' + escapeHtml(availability) + "</span>");
    }
    if (frozen) bits.push('<span class="cbt-badge is-frozen">FROZEN</span>');
    return bits.join(" ");
  };

  /* -------- Home -------- */
  const renderHome = function (data) {
    const host = document.querySelector("[data-cbt-admin-summary]");
    if (!host) return;
    const summary = data.summary || {};
    if (!data.capabilities?.manage && !data.capabilities?.mark) {
      host.innerHTML = '<div class="cbt-empty">Proctor access is reserved for future monitoring tools.</div>';
      return;
    }
    const cards = [
      ["Total exams", summary.total_exams],
      ["Draft exams", summary.draft_exams],
      ["Published exams", summary.published_exams],
      ["Active exams", summary.active_exams],
      ["Completed attempts", summary.completed_attempts],
      ["In progress", summary.in_progress_attempts],
      ["Results generated", summary.results_generated],
      ["Locked results", summary.locked_results],
      ["Unlocked results", summary.unlocked_results]
    ];
    host.innerHTML = cards.map(function (row) {
      return '<div class="cbt-stat"><strong>' + escapeHtml(row[1] ?? 0) + "</strong><span>" + escapeHtml(row[0]) + "</span></div>";
    }).join("");

    const rcHost = document.querySelector("[data-cbt-rc-summary]");
    const rc = summary.result_checker || {};
    if (rcHost) {
      rcHost.innerHTML = [
        ["Paid payments", rc.payments_paid],
        ["Pending payments", rc.payments_pending],
        ["Failed payments", rc.payments_failed],
        ["Revenue (kobo)", rc.revenue_kobo]
      ].map(function (row) {
        return '<div class="cbt-stat"><strong>' + escapeHtml(row[1] ?? 0) + "</strong><span>" + escapeHtml(row[0]) + "</span></div>";
      }).join("");
    }
  };

  const bindMonitor = function () {
    const rows = document.querySelector("[data-monitor-rows]");
    const updated = document.querySelector("[data-monitor-updated]");
    const load = function () {
      request("/api/v1/cbt/admin/attempts/monitor").then(function (result) {
        if (!result.ok) {
          rows.innerHTML = '<tr><td colspan="10">Unable to load monitor.</td></tr>';
          return showAlert(firstError(result.body));
        }
        const exams = (result.body.data && result.body.data.exams) || [];
        if (!exams.length) {
          rows.innerHTML = '<tr><td colspan="10">No active exams right now.</td></tr>';
        } else {
          rows.innerHTML = exams.map(function (e) {
            return "<tr>"
              + "<td>" + escapeHtml(e.title) + "</td>"
              + "<td>" + escapeHtml((e.subject || "—") + " / " + (e.class || "—")) + "</td>"
              + "<td>" + escapeHtml(e.assigned_students) + "</td>"
              + "<td>" + escapeHtml(e.started_students) + "</td>"
              + "<td>" + escapeHtml(e.attempts_in_progress) + "</td>"
              + "<td>" + escapeHtml(e.attempts_submitted) + "</td>"
              + "<td>" + escapeHtml(e.attempts_expired) + "</td>"
              + "<td>" + escapeHtml(e.not_started_students) + "</td>"
              + "<td>" + escapeHtml(e.completion_percentage) + "%</td>"
              + '<td class="cbt-actions">'
              + '<a class="cbt-btn cbt-btn-ghost" href="/cbt/admin/reports?exam_id=' + e.exam_id + '">Report</a> '
              + (e.attempts_in_progress > 0
                ? '<button type="button" class="cbt-btn cbt-btn-ghost" data-extend-exam="' + e.exam_id + '">Extend</button>'
                : "")
              + "</td>"
              + "</tr>";
          }).join("");
        }
        if (updated) updated.textContent = "Updated " + new Date().toLocaleTimeString() + " · auto-refresh 30s";
      });
    };
    rows?.addEventListener("click", function (event) {
      const btn = event.target.closest("[data-extend-exam]");
      if (!btn) return;
      const minutesRaw = window.prompt("Add minutes to all in-progress attempts (or leave blank to cancel):", "15");
      if (minutesRaw === null || minutesRaw.trim() === "") return;
      const minutes = Number(minutesRaw);
      if (!Number.isFinite(minutes) || minutes < 1) return showAlert("Enter a valid number of minutes.");
      request("/api/v1/cbt/admin/exams/" + btn.getAttribute("data-extend-exam") + "/extend-timers", {
        method: "POST",
        body: JSON.stringify({ minutes: minutes })
      }).then(function (result) {
        if (!result.ok) return showAlert(firstError(result.body));
        showAlert("Extended " + ((result.body.data && result.body.data.updated) || 0) + " attempt(s).", "info");
        load();
      });
    });
    load();
    setInterval(load, 30000);
  };

  let attemptsPage = 1;
  const loadAttempts = function (pageNum) {
    attemptsPage = pageNum || 1;
    const form = document.querySelector("[data-attempts-filters]");
    const params = new URLSearchParams(new FormData(form));
    params.set("page", String(attemptsPage));
    const rows = document.querySelector("[data-attempts-rows]");
    request("/api/v1/cbt/admin/attempts?" + params.toString()).then(function (result) {
      if (!result.ok) {
        rows.innerHTML = '<tr><td colspan="9">Unable to load attempts.</td></tr>';
        return showAlert(firstError(result.body));
      }
      const items = (result.body.data && result.body.data.items) || [];
      rows.innerHTML = items.length ? items.map(function (a) {
        const canExtend = !!a.can_extend_timer;
        return "<tr>"
          + "<td>" + escapeHtml((a.student_name || "—") + " · " + (a.admission_number || "")) + "</td>"
          + "<td>" + escapeHtml(a.exam_title || ("#" + a.exam_id)) + "</td>"
          + "<td>" + escapeHtml(a.status) + "</td>"
          + "<td>" + escapeHtml(formatWhen(a.started_at)) + "</td>"
          + "<td>" + escapeHtml(formatWhen(a.ends_at)) + "</td>"
          + "<td>" + escapeHtml(formatWhen(a.submitted_at)) + "</td>"
          + "<td>" + escapeHtml(a.submission_reason || "—") + "</td>"
          + "<td>" + escapeHtml(a.score != null ? (a.score + " (" + a.percentage + "%)") : "—") + "</td>"
          + '<td class="cbt-actions">'
          + (canExtend
            ? '<button type="button" class="cbt-btn cbt-btn-ghost" data-extend-attempt="' + a.id + '">Extend</button>'
              + ' <button type="button" class="cbt-btn cbt-btn-ghost" data-reset-attempt="' + a.id + '">Reset</button>'
            : "—")
          + "</td>"
          + "</tr>";
      }).join("") : '<tr><td colspan="9">No attempts found.</td></tr>';
      renderPager(document.querySelector("[data-attempts-pager]"), result.body.data.meta, loadAttempts);
    });
  };

  const bindAttempts = function () {
    document.querySelector("[data-attempts-filters]")?.addEventListener("submit", function (event) {
      event.preventDefault();
      loadAttempts(1);
    });
    document.querySelector("[data-attempts-rows]")?.addEventListener("click", function (event) {
      const extendBtn = event.target.closest("[data-extend-attempt]");
      const resetBtn = event.target.closest("[data-reset-attempt]");
      if (extendBtn) {
        const minutesRaw = window.prompt("Minutes to add:", "15");
        if (minutesRaw === null || minutesRaw.trim() === "") return;
        const minutes = Number(minutesRaw);
        if (!Number.isFinite(minutes) || minutes < 1) return showAlert("Enter a valid number of minutes.");
        request("/api/v1/cbt/admin/attempts/" + extendBtn.getAttribute("data-extend-attempt") + "/extend", {
          method: "POST",
          body: JSON.stringify({ minutes: minutes })
        }).then(function (result) {
          if (!result.ok) return showAlert(firstError(result.body));
          showAlert("Attempt timer extended.", "info");
          loadAttempts(attemptsPage);
        });
        return;
      }
      if (resetBtn) {
        confirmAction("Reset timer", "Reset this attempt's timer to the exam duration from now?").then(function (ok) {
          if (!ok) return;
          request("/api/v1/cbt/admin/attempts/" + resetBtn.getAttribute("data-reset-attempt") + "/extend", {
            method: "POST",
            body: JSON.stringify({ reset: true })
          }).then(function (result) {
            if (!result.ok) return showAlert(firstError(result.body));
            showAlert("Attempt timer reset.", "info");
            loadAttempts(attemptsPage);
          });
        });
      }
    });
    loadAttempts(1);
  };

  const bindReports = function () {
    const params = new URLSearchParams(window.location.search);
    const form = document.querySelector("[data-exam-report-form]");
    if (form && params.get("exam_id")) form.exam_id.value = params.get("exam_id");
    const loadExamReport = function (examId) {
      request("/api/v1/cbt/admin/exams/" + examId + "/report").then(function (result) {
        if (!result.ok) return showAlert(firstError(result.body));
        const data = result.body.data || {};
        const perf = data.performance || {};
        document.querySelector("[data-exam-report-stats]").innerHTML = [
          ["Assigned", perf.assigned_students],
          ["Started", perf.started_students],
          ["Submitted", perf.attempts_submitted],
          ["Expired", perf.attempts_expired],
          ["Not started", perf.not_started_students],
          ["Avg score", perf.average_score],
          ["Avg %", perf.average_percentage],
          ["Pass rate", perf.pass_rate != null ? perf.pass_rate + "%" : "—"],
          ["Highest", perf.highest_score],
          ["Lowest", perf.lowest_score]
        ].map(function (row) {
          return '<div class="cbt-stat"><strong>' + escapeHtml(row[1] ?? "—") + "</strong><span>" + escapeHtml(row[0]) + "</span></div>";
        }).join("");
        document.querySelector("[data-exam-roster]").innerHTML = (data.roster || []).map(function (r) {
          return "<tr><td>" + escapeHtml(r.student_name) + "</td><td>" + escapeHtml(r.admission_number)
            + "</td><td>" + escapeHtml(r.status) + "</td><td>" + escapeHtml(r.score ?? "—")
            + "</td><td>" + escapeHtml(r.percentage ?? "—") + "</td><td>" + escapeHtml(r.attempts_count) + "</td></tr>";
        }).join("") || '<tr><td colspan="6">No assigned students.</td></tr>';
        document.querySelector("[data-question-analytics]").innerHTML = (data.questions || []).map(function (q) {
          return "<tr><td>" + escapeHtml(q.sort_order) + "</td><td>" + escapeHtml(q.stem_truncated)
            + "</td><td>" + escapeHtml(q.marks) + "</td><td>" + escapeHtml(q.correct)
            + "</td><td>" + escapeHtml(q.incorrect) + "</td><td>" + escapeHtml(q.unanswered)
            + "</td><td>" + escapeHtml(q.percentage_correct) + "%</td><td>" + escapeHtml(q.analytics_difficulty) + "</td></tr>";
        }).join("") || '<tr><td colspan="8">No questions.</td></tr>';
        document.querySelector("[data-export-results]").href = "/api/v1/cbt/admin/exams/" + examId + "/export/results";
        document.querySelector("[data-export-attendance]").href = "/api/v1/cbt/admin/exams/" + examId + "/export/attendance";
      });
    };
    form?.addEventListener("submit", function (event) {
      event.preventDefault();
      loadExamReport(form.exam_id.value);
    });
    if (form?.exam_id.value) loadExamReport(form.exam_id.value);

    const historyForm = document.querySelector("[data-student-history-form]");
    const loadHistory = function (pageNum) {
      const params = new URLSearchParams(new FormData(historyForm));
      params.set("page", String(pageNum || 1));
      request("/api/v1/cbt/admin/reports/student-history?" + params.toString()).then(function (result) {
        if (!result.ok) return showAlert(firstError(result.body));
        const items = (result.body.data && result.body.data.items) || [];
        document.querySelector("[data-student-history]").innerHTML = items.map(function (r) {
          return "<tr><td>" + escapeHtml(r.exam_title) + "</td><td>" + escapeHtml(r.subject)
            + "</td><td>" + escapeHtml(r.score) + "</td><td>" + escapeHtml(r.percentage)
            + "</td><td>" + escapeHtml(r.grade || "—") + "</td><td>" + escapeHtml(r.result_access)
            + "</td><td>" + escapeHtml(r.payment_status || "—") + "</td></tr>";
        }).join("") || '<tr><td colspan="7">No history.</td></tr>';
        renderPager(document.querySelector("[data-student-history-pager]"), result.body.data.meta, loadHistory);
        document.querySelector("[data-export-student]").href = "/api/v1/cbt/admin/reports/student-history/export?" + new URLSearchParams(new FormData(historyForm)).toString();
      });
    };
    historyForm?.addEventListener("submit", function (event) {
      event.preventDefault();
      loadHistory(1);
    });
  };

  /* -------- Questions -------- */
  let questionPage = 1;

  const field = function (form, name) {
    return form.elements.namedItem(name);
  };

  const fieldValue = function (form, name) {
    const el = field(form, name);
    if (!el) return "";
    if (el.type === "checkbox") return el.checked;
    return el.value;
  };

  const setFieldValue = function (form, name, value) {
    const el = field(form, name);
    if (!el) return;
    if (el.type === "checkbox") {
      el.checked = !!value;
      return;
    }
    el.value = value == null ? "" : String(value);
  };

  const optionRowHtml = function (option) {
    option = option || {};
    return '<div class="cbt-option-row" data-option-row>'
      + '<label class="cbt-check"><input type="radio" name="correct_option" ' + (option.is_correct ? "checked" : "") + "> Correct</label>"
      + '<input type="text" name="label" placeholder="A" value="' + escapeHtml(option.label || "") + '">'
      + '<input type="text" name="body" placeholder="Option text" required value="' + escapeHtml(option.body || "") + '">'
      + '<button type="button" class="cbt-btn cbt-btn-ghost" data-remove-option>Remove</button>'
      + "</div>";
  };

  const collectOptions = function () {
    return Array.from(document.querySelectorAll("[data-option-row]")).map(function (row) {
      const radio = row.querySelector('[name="correct_option"]');
      const label = row.querySelector('[name="label"]');
      const body = row.querySelector('[name="body"]');
      return {
        label: (label && label.value) || null,
        body: body ? String(body.value || "").trim() : "",
        is_correct: !!(radio && radio.checked)
      };
    }).filter(function (option) {
      return option.body.length > 0;
    });
  };

  const showQuestionFormError = function (message) {
    const form = document.querySelector("[data-q-form]");
    let box = form && form.querySelector("[data-q-form-alert]");
    if (!box && form) {
      box = document.createElement("p");
      box.className = "cbt-alert is-visible";
      box.setAttribute("data-q-form-alert", "1");
      form.insertBefore(box, form.firstChild);
    }
    if (box) {
      box.textContent = message || "";
      box.classList.toggle("is-visible", !!message);
      box.hidden = !message;
    }
    showAlert(message);
  };

  const openQuestionModal = function (question) {
    const modal = document.querySelector("[data-q-modal]");
    const form = document.querySelector("[data-q-form]");
    const title = document.querySelector("[data-q-modal-title]");
    const optionsHost = document.querySelector("[data-q-options]");
    if (!modal || !form) return;
    form.reset();
    showQuestionFormError("");
    title.textContent = question ? "Edit question #" + question.id : "Create question";
    setFieldValue(form, "id", question ? question.id : "");
    if (question) {
      setFieldValue(form, "school_class_id", question.school_class_id);
      setFieldValue(form, "subject_id", question.subject_id);
      setFieldValue(form, "topic", question.topic || "");
      setFieldValue(form, "difficulty", question.difficulty || "");
      setFieldValue(form, "type", question.type || "mcq");
      setFieldValue(form, "marks", question.marks);
      setFieldValue(form, "stem", question.stem || "");
      setFieldValue(form, "explanation", question.explanation || "");
      setFieldValue(form, "is_active", !!question.is_active);
      optionsHost.innerHTML = (question.options || []).map(optionRowHtml).join("") || optionRowHtml({ is_correct: true }) + optionRowHtml({});
    } else {
      setFieldValue(form, "type", "mcq");
      setFieldValue(form, "difficulty", "medium");
      setFieldValue(form, "marks", "1");
      setFieldValue(form, "is_active", true);
      optionsHost.innerHTML = optionRowHtml({ label: "A", is_correct: true }) + optionRowHtml({ label: "B" });
    }
    openModal(modal);
  };

  const loadQuestions = function (pageNum) {
    questionPage = pageNum || 1;
    const form = document.querySelector("[data-q-filters]");
    const params = new URLSearchParams(new FormData(form));
    params.set("page", String(questionPage));
    const rows = document.querySelector("[data-q-rows]");
    request("/api/v1/cbt/admin/questions?" + params.toString()).then(function (result) {
      if (!result.ok) {
        rows.innerHTML = '<tr><td colspan="8">Unable to load questions.</td></tr>';
        showAlert(firstError(result.body));
        return;
      }
      showAlert("");
      const data = result.body.data || {};
      const items = data.items || [];
      if (!items.length) {
        rows.innerHTML = '<tr><td colspan="8">No questions found.</td></tr>';
      } else {
        rows.innerHTML = items.map(function (q) {
          return "<tr>"
            + "<td>" + q.id + "</td>"
            + "<td>" + escapeHtml((q.stem || "").slice(0, 90)) + "</td>"
            + "<td>" + escapeHtml(q.subject || "—") + "</td>"
            + "<td>" + escapeHtml(q.school_class || "—") + "</td>"
            + "<td>" + escapeHtml(q.difficulty || "—") + "</td>"
            + "<td>" + escapeHtml(q.marks) + "</td>"
            + "<td>" + (q.is_active ? "Active" : "Inactive") + "</td>"
            + '<td class="cbt-actions">'
            + '<button type="button" class="cbt-btn cbt-btn-ghost" data-q-preview="' + q.id + '">Preview</button>'
            + '<button type="button" class="cbt-btn cbt-btn-ghost" data-q-edit="' + q.id + '">Edit</button>'
            + '<button type="button" class="cbt-btn cbt-btn-ghost" data-q-toggle="' + q.id + '" data-active="' + (q.is_active ? "1" : "0") + '">'
            + (q.is_active ? "Deactivate" : "Activate") + "</button>"
            + "</td></tr>";
        }).join("");
      }
      renderPager(document.querySelector("[data-q-pager]"), data.meta, loadQuestions);
    });
  };

  const bindQuestions = function () {
    document.querySelector("[data-q-new]")?.addEventListener("click", function () { openQuestionModal(null); });
    document.querySelector("[data-q-cancel]")?.addEventListener("click", function () {
      closeModal(document.querySelector("[data-q-modal]"));
    });
    document.querySelector("[data-q-add-option]")?.addEventListener("click", function () {
      document.querySelector("[data-q-options]").insertAdjacentHTML("beforeend", optionRowHtml({}));
    });
    document.querySelector("[data-q-options]")?.addEventListener("click", function (event) {
      if (event.target.matches("[data-remove-option]")) {
        const rows = document.querySelectorAll("[data-option-row]");
        if (rows.length <= 2) {
          showAlert("MCQ questions need at least two options.");
          return;
        }
        event.target.closest("[data-option-row]").remove();
      }
    });
    document.querySelector("[data-q-filters]")?.addEventListener("submit", function (event) {
      event.preventDefault();
      loadQuestions(1);
    });
    document.querySelector("[data-q-form]")?.addEventListener("submit", function (event) {
      event.preventDefault();
      const form = event.currentTarget;
      const id = String(fieldValue(form, "id") || "").trim();
      const classId = Number(fieldValue(form, "school_class_id"));
      const subjectId = Number(fieldValue(form, "subject_id"));
      const payload = {
        school_class_id: classId,
        subject_id: subjectId,
        topic: fieldValue(form, "topic") || null,
        difficulty: fieldValue(form, "difficulty") || "medium",
        type: fieldValue(form, "type") || "mcq",
        stem: String(fieldValue(form, "stem") || "").trim(),
        marks: Number(fieldValue(form, "marks") || 1),
        explanation: fieldValue(form, "explanation") || null,
        is_active: !!fieldValue(form, "is_active"),
        options: collectOptions()
      };
      const correct = payload.options.filter(function (o) { return o.is_correct; }).length;
      if (!classId || !subjectId) {
        showQuestionFormError("Choose a school class and subject.");
        return;
      }
      if (payload.options.length < 2 || correct !== 1 || !payload.stem) {
        showQuestionFormError("Provide a stem, at least two options, and exactly one correct answer.");
        return;
      }
      const saveBtn = form.querySelector('button[type="submit"]');
      if (saveBtn) {
        saveBtn.disabled = true;
        if (!saveBtn.dataset.label) saveBtn.dataset.label = saveBtn.textContent;
        saveBtn.textContent = "Saving…";
      }
      request(id ? "/api/v1/cbt/admin/questions/" + id : "/api/v1/cbt/admin/questions", {
        method: "POST",
        body: JSON.stringify(payload)
      }).then(function (result) {
        if (saveBtn) {
          saveBtn.disabled = false;
          saveBtn.textContent = saveBtn.dataset.label || "Save question";
        }
        if (!result.ok) {
          showQuestionFormError(firstError(result.body));
          return;
        }
        closeModal(document.querySelector("[data-q-modal]"));
        showQuestionFormError("");
        showAlert(id ? "Question updated." : "Question created.", "info");
        loadQuestions(questionPage);
      });
    });
    document.querySelector("[data-q-rows]")?.addEventListener("click", function (event) {
      const previewId = event.target.getAttribute("data-q-preview");
      const editId = event.target.getAttribute("data-q-edit");
      const toggleId = event.target.getAttribute("data-q-toggle");
      if (previewId) {
        request("/api/v1/cbt/admin/questions/" + previewId).then(function (result) {
          if (!result.ok) return showAlert(firstError(result.body));
          const q = result.body.data;
          const body = document.querySelector("[data-q-preview-body]");
          body.innerHTML = "<p class=\"cbt-stem\">" + escapeHtml(q.stem) + "</p>"
            + '<div class="cbt-options">' + (q.options || []).map(function (o) {
              return '<div class="cbt-option' + (o.is_correct ? " is-selected" : "") + '"><strong>'
                + escapeHtml(o.label || "") + "</strong> " + escapeHtml(o.body)
                + (o.is_correct ? " <em>(correct)</em>" : "") + "</div>";
            }).join("") + "</div>";
          openModal(document.querySelector("[data-q-preview-modal]"));
        });
      }
      if (editId) {
        request("/api/v1/cbt/admin/questions/" + editId).then(function (result) {
          if (!result.ok) return showAlert(firstError(result.body));
          openQuestionModal(result.body.data);
        });
      }
      if (toggleId) {
        const active = event.target.getAttribute("data-active") === "1";
        request("/api/v1/cbt/admin/questions/" + toggleId + "/active", {
          method: "POST",
          body: JSON.stringify({ is_active: !active })
        }).then(function (result) {
          if (!result.ok) return showAlert(firstError(result.body));
          loadQuestions(questionPage);
        });
      }
    });
    document.querySelector("[data-q-preview-close]")?.addEventListener("click", function () {
      closeModal(document.querySelector("[data-q-preview-modal]"));
    });
  };

  /* -------- Exams list -------- */
  let examListPage = 1;

  const loadExams = function (pageNum) {
    examListPage = pageNum || 1;
    const form = document.querySelector("[data-exam-filters]");
    const params = new URLSearchParams(new FormData(form));
    params.set("page", String(examListPage));
    const rows = document.querySelector("[data-exam-rows]");
    request("/api/v1/cbt/admin/exams?" + params.toString()).then(function (result) {
      if (!result.ok) {
        rows.innerHTML = '<tr><td colspan="11">Unable to load exams.</td></tr>';
        showAlert(firstError(result.body));
        return;
      }
      showAlert("");
      const data = result.body.data || {};
      const items = data.items || [];
      if (!items.length) {
        rows.innerHTML = '<tr><td colspan="11">No exams found.</td></tr>';
      } else {
        rows.innerHTML = items.map(function (exam) {
          return "<tr>"
            + "<td>" + escapeHtml(exam.title) + "</td>"
            + "<td>" + escapeHtml(exam.subject || "—") + "</td>"
            + "<td>" + escapeHtml(exam.class_section_offering || "—") + "</td>"
            + "<td>" + escapeHtml((exam.academic_session || "—") + " / " + (exam.term || "—")) + "</td>"
            + "<td>" + statusBadge(exam.status, null, false) + "</td>"
            + "<td>" + escapeHtml(exam.availability || "—") + "</td>"
            + "<td>" + (exam.is_frozen ? "Yes" : "No") + "</td>"
            + "<td>" + escapeHtml(exam.question_count) + "</td>"
            + "<td>" + escapeHtml(exam.assignment_count) + "</td>"
            + "<td>" + escapeHtml(exam.attempt_count) + "</td>"
            + '<td><a class="cbt-btn cbt-btn-ghost" href="/cbt/admin/exams/' + exam.id + '">Open</a></td>'
            + "</tr>";
        }).join("");
      }
      renderPager(document.querySelector("[data-exam-pager]"), data.meta, loadExams);
    });
  };

  const bindExamsList = function () {
    document.querySelector("[data-exam-filters]")?.addEventListener("submit", function (event) {
      event.preventDefault();
      loadExams(1);
    });
    document.querySelector("[data-exam-new]")?.addEventListener("click", function () {
      openModal(document.querySelector("[data-exam-create-modal]"));
    });
    document.querySelector("[data-exam-create-cancel]")?.addEventListener("click", function () {
      closeModal(document.querySelector("[data-exam-create-modal]"));
    });
    document.querySelector("[data-exam-create-form]")?.addEventListener("submit", function (event) {
      event.preventDefault();
      const form = event.currentTarget;
      const payload = {
        title: form.title.value,
        instructions: form.instructions.value || null,
        subject_id: Number(form.subject_id.value),
        class_section_offering_id: Number(form.class_section_offering_id.value),
        academic_session_id: Number(form.academic_session_id.value),
        term_id: Number(form.term_id.value),
        duration_minutes: Number(form.duration_minutes.value),
        pass_mark: form.pass_mark.value === "" ? null : Number(form.pass_mark.value),
        max_attempts: Number(form.max_attempts.value || 1),
        starts_at: fromLocalInput(form.starts_at.value),
        ends_at: fromLocalInput(form.ends_at.value),
        randomize_questions: !!form.randomize_questions.checked,
        randomize_options: !!form.randomize_options.checked,
        is_active: !!form.is_active.checked,
        write_to_assessment_score: !!form.write_to_assessment_score.checked
      };
      request("/api/v1/cbt/admin/exams", { method: "POST", body: JSON.stringify(payload) }).then(function (result) {
        if (!result.ok) {
          showAlert(firstError(result.body));
          return;
        }
        window.location.href = "/cbt/admin/exams/" + result.body.data.id;
      });
    });
  };

  /* -------- Exam builder -------- */
  let currentExam = null;

  const setDraftControls = function (exam) {
    const frozen = !!exam.is_frozen;
    const draft = exam.status === "draft";
    const published = exam.status === "published";
    const banner = document.querySelector("[data-exam-frozen-banner]");
    if (banner) banner.hidden = !frozen;
    document.querySelectorAll("[data-draft-only]").forEach(function (el) {
      el.hidden = !draft || !capabilities.manage;
    });
    const fields = document.querySelector("[data-exam-config-fields]");
    if (fields) fields.disabled = frozen || !capabilities.manage;
    const publishBtn = document.querySelector("[data-exam-publish]");
    if (publishBtn) publishBtn.hidden = !draft || !capabilities.manage;
    const archiveBtn = document.querySelector("[data-exam-archive]");
    if (archiveBtn) archiveBtn.hidden = exam.status !== "published" || !capabilities.manage;
    const schedulePanel = document.querySelector("[data-exam-schedule-panel]");
    if (schedulePanel) schedulePanel.hidden = !published || !capabilities.manage;
  };

  const renderExam = function (exam) {
    currentExam = exam;
    document.querySelector("[data-exam-title]").textContent = exam.title;
    document.querySelector("[data-exam-heading]").textContent = exam.title;
    document.querySelector("[data-exam-badges]").innerHTML = statusBadge(exam.status, exam.availability, exam.is_frozen)
      + " · " + escapeHtml(exam.subject || "")
      + " · " + formatWhen(exam.starts_at) + " → " + formatWhen(exam.ends_at);
    document.querySelector("[data-exam-preview-link]").href = "/cbt/admin/exams/" + exam.id + "/preview";
    setDraftControls(exam);

    const form = document.querySelector("[data-exam-config-form]");
    if (form) {
      form.title.value = exam.title || "";
      form.subject_id.value = exam.subject_id || "";
      form.class_section_offering_id.value = exam.class_section_offering_id || "";
      form.academic_session_id.value = exam.academic_session_id || "";
      form.term_id.value = exam.term_id || "";
      form.duration_minutes.value = exam.duration_minutes || "";
      form.pass_mark.value = exam.pass_mark || "";
      form.max_attempts.value = exam.max_attempts || 1;
      form.starts_at.value = toLocalInput(exam.starts_at);
      form.ends_at.value = toLocalInput(exam.ends_at);
      form.question_count.value = exam.question_count;
      form.max_score.value = exam.max_score;
      form.instructions.value = exam.instructions || "";
      form.randomize_questions.checked = !!exam.randomize_questions;
      form.randomize_options.checked = !!exam.randomize_options;
      form.is_active.checked = !!exam.is_active;
      form.write_to_assessment_score.checked = !!exam.write_to_assessment_score;
    }

    const scheduleForm = document.querySelector("[data-exam-schedule-form]");
    if (scheduleForm) {
      scheduleForm.duration_minutes.value = exam.duration_minutes || "";
      scheduleForm.starts_at.value = toLocalInput(exam.starts_at);
      scheduleForm.ends_at.value = toLocalInput(exam.ends_at);
      scheduleForm.is_active.checked = !!exam.is_active;
    }

    const qRows = document.querySelector("[data-exam-question-rows]");
    const questions = exam.exam_questions || [];
    if (!questions.length) {
      qRows.innerHTML = '<tr><td colspan="6">No questions attached yet.</td></tr>';
    } else {
      qRows.innerHTML = questions.map(function (q) {
        return "<tr>"
          + "<td>" + q.sort_order + "</td>"
          + "<td>" + escapeHtml((q.stem || "").slice(0, 120)) + "</td>"
          + "<td>" + escapeHtml(q.marks) + "</td>"
          + "<td>" + (q.is_frozen ? "Yes" : "No") + "</td>"
          + "<td>" + (q.source_changed ? '<span class="cbt-badge is-upcoming">Bank changed</span>' : "In sync") + "</td>"
          + '<td class="cbt-actions">'
          + (exam.status === "draft" && capabilities.manage
            ? (q.source_changed ? '<button type="button" class="cbt-btn cbt-btn-ghost" data-refresh-q="' + q.id + '">Refresh snapshot</button>' : "")
              + '<button type="button" class="cbt-btn cbt-btn-ghost" data-remove-q="' + q.id + '">Remove</button>'
            : "—")
          + "</td></tr>";
      }).join("");
    }

    const aRows = document.querySelector("[data-assign-rows]");
    const assignments = exam.assignments || [];
    if (!assignments.length) {
      aRows.innerHTML = '<tr><td colspan="4">No assignments yet.</td></tr>';
    } else {
      aRows.innerHTML = assignments.map(function (a) {
        const target = a.type === "student"
          ? (a.student || "Student") + " (" + (a.admission_number || a.student_profile_id) + ")"
          : (a.class_section_offering || ("Offering #" + a.class_section_offering_id));
        return "<tr>"
          + "<td>" + escapeHtml(a.type) + "</td>"
          + "<td>" + escapeHtml(target) + "</td>"
          + "<td>" + escapeHtml(formatWhen(a.created_at)) + "</td>"
          + '<td>' + (capabilities.manage
            ? '<button type="button" class="cbt-btn cbt-btn-ghost" data-unassign="' + a.id + '">Remove</button>'
            : "—") + "</td></tr>";
      }).join("");
    }
  };

  const loadExam = function () {
    const id = pathId("exams");
    return request("/api/v1/cbt/admin/exams/" + id).then(function (result) {
      if (!result.ok) {
        showAlert(firstError(result.body));
        return null;
      }
      renderExam(result.body.data);
      return result.body.data;
    });
  };

  const bindExamBuilder = function () {
    document.querySelector("[data-exam-config-form]")?.addEventListener("submit", function (event) {
      event.preventDefault();
      if (!currentExam || currentExam.status !== "draft") return;
      const form = event.currentTarget;
      const payload = {
        title: form.title.value,
        instructions: form.instructions.value || null,
        subject_id: Number(form.subject_id.value),
        class_section_offering_id: Number(form.class_section_offering_id.value),
        academic_session_id: Number(form.academic_session_id.value),
        term_id: Number(form.term_id.value),
        duration_minutes: Number(form.duration_minutes.value),
        pass_mark: form.pass_mark.value === "" ? null : Number(form.pass_mark.value),
        max_attempts: Number(form.max_attempts.value || 1),
        starts_at: fromLocalInput(form.starts_at.value),
        ends_at: fromLocalInput(form.ends_at.value),
        randomize_questions: !!form.randomize_questions.checked,
        randomize_options: !!form.randomize_options.checked,
        is_active: !!form.is_active.checked,
        write_to_assessment_score: !!form.write_to_assessment_score.checked
      };
      request("/api/v1/cbt/admin/exams/" + currentExam.id, {
        method: "PUT",
        body: JSON.stringify(payload)
      }).then(function (result) {
        if (!result.ok) return showAlert(firstError(result.body));
        showAlert("Draft configuration saved.", "info");
        renderExam(result.body.data);
      });
    });

    document.querySelector("[data-exam-schedule-form]")?.addEventListener("submit", function (event) {
      event.preventDefault();
      if (!currentExam || currentExam.status !== "published") return;
      const form = event.currentTarget;
      request("/api/v1/cbt/admin/exams/" + currentExam.id + "/schedule", {
        method: "POST",
        body: JSON.stringify({
          duration_minutes: Number(form.duration_minutes.value),
          starts_at: fromLocalInput(form.starts_at.value),
          ends_at: fromLocalInput(form.ends_at.value),
          is_active: !!form.is_active.checked
        })
      }).then(function (result) {
        if (!result.ok) return showAlert(firstError(result.body));
        showAlert("Schedule updated for future attempts.", "info");
        loadExam();
      });
    });

    document.querySelector("[data-exam-extend-timers-form]")?.addEventListener("submit", function (event) {
      event.preventDefault();
      if (!currentExam) return;
      const form = event.currentTarget;
      const action = event.submitter && event.submitter.value ? event.submitter.value : "extend";
      const minutes = Number(form.minutes.value || 0);
      const payload = action === "reset"
        ? { reset: true, minutes: minutes > 0 ? minutes : undefined }
        : { minutes: minutes };
      if (action !== "reset" && (!Number.isFinite(minutes) || minutes < 1)) {
        return showAlert("Enter minutes to extend.");
      }
      const label = action === "reset" ? "Reset all in-progress timers to duration from now?" : ("Extend all in-progress attempts by " + minutes + " minutes?");
      confirmAction("Adjust timers", label).then(function (ok) {
        if (!ok) return;
        request("/api/v1/cbt/admin/exams/" + currentExam.id + "/extend-timers", {
          method: "POST",
          body: JSON.stringify(payload)
        }).then(function (result) {
          if (!result.ok) return showAlert(firstError(result.body));
          showAlert("Updated " + ((result.body.data && result.body.data.updated) || 0) + " attempt(s).", "info");
        });
      });
    });

    document.querySelector("[data-attach-open]")?.addEventListener("click", function () {
      openModal(document.querySelector("[data-attach-modal]"));
      document.querySelector("[data-attach-filters]")?.dispatchEvent(new Event("submit", { cancelable: true }));
    });
    document.querySelector("[data-attach-close]")?.addEventListener("click", function () {
      closeModal(document.querySelector("[data-attach-modal]"));
    });
    document.querySelector("[data-attach-filters]")?.addEventListener("submit", function (event) {
      event.preventDefault();
      const params = new URLSearchParams(new FormData(event.currentTarget));
      params.set("is_active", "1");
      request("/api/v1/cbt/admin/questions?" + params.toString()).then(function (result) {
        const host = document.querySelector("[data-attach-list]");
        if (!result.ok) {
          host.innerHTML = "<div class=\"cbt-empty\">Unable to load bank.</div>";
          return;
        }
        const items = (result.body.data && result.body.data.items) || [];
        host.innerHTML = items.map(function (q) {
          return '<div class="cbt-attach-item"><div><strong>#' + q.id + "</strong> "
            + escapeHtml((q.stem || "").slice(0, 140))
            + '<div class="cbt-muted">' + escapeHtml(q.subject || "") + " · " + escapeHtml(q.marks) + " marks</div></div>"
            + '<button type="button" class="cbt-btn cbt-btn-primary" data-attach-id="' + q.id + '">Attach</button></div>';
        }).join("") || '<div class="cbt-empty">No matching questions.</div>';
      });
    });
    document.querySelector("[data-attach-list]")?.addEventListener("click", function (event) {
      const id = event.target.getAttribute("data-attach-id");
      if (!id || !currentExam) return;
      request("/api/v1/cbt/admin/exams/" + currentExam.id + "/questions", {
        method: "POST",
        body: JSON.stringify({ question_id: Number(id) })
      }).then(function (result) {
        if (!result.ok) return showAlert(firstError(result.body));
        showAlert("Question attached as snapshot.", "info");
        renderExam(result.body.data);
      });
    });

    document.querySelector("[data-exam-question-rows]")?.addEventListener("click", function (event) {
      const removeId = event.target.getAttribute("data-remove-q");
      const refreshId = event.target.getAttribute("data-refresh-q");
      if (removeId) {
        confirmAction("Remove question", "Remove this snapshot from the draft exam?").then(function (ok) {
          if (!ok) return;
          request("/api/v1/cbt/admin/exams/" + currentExam.id + "/questions/" + removeId, { method: "DELETE" })
            .then(function (result) {
              if (!result.ok) return showAlert(firstError(result.body));
              renderExam(result.body.data);
            });
        });
      }
      if (refreshId) {
        confirmAction("Refresh snapshot", "Replace the draft snapshot with the current bank question content?").then(function (ok) {
          if (!ok) return;
          request("/api/v1/cbt/admin/exams/" + currentExam.id + "/questions/" + refreshId + "/refresh", { method: "POST", body: "{}" })
            .then(function (result) {
              if (!result.ok) return showAlert(firstError(result.body));
              showAlert("Snapshot refreshed from bank.", "info");
              renderExam(result.body.data);
            });
        });
      }
    });

    const assignForm = document.querySelector("[data-assign-form]");
    const syncAssignType = function () {
      const type = assignForm.type.value;
      const offering = assignForm.querySelector("[data-assign-offering]");
      const studentQ = assignForm.querySelector("[data-assign-student-q]");
      const student = assignForm.querySelector("[data-assign-student]");
      offering.hidden = type !== "offering";
      studentQ.hidden = type !== "student";
      student.hidden = type !== "student";
    };
    assignForm?.querySelector('[name="type"]')?.addEventListener("change", syncAssignType);
    if (assignForm) syncAssignType();

    let studentTimer = null;
    assignForm?.querySelector("[data-assign-student-q]")?.addEventListener("input", function (event) {
      const q = event.target.value.trim();
      clearTimeout(studentTimer);
      if (q.length < 2) return;
      studentTimer = setTimeout(function () {
        request("/api/v1/cbt/admin/students?q=" + encodeURIComponent(q)).then(function (result) {
          const select = assignForm.querySelector("[data-assign-student]");
          if (!result.ok) return;
          fillSelect(select, result.body.data.items || [], "id", function () {}, true);
          select.innerHTML = '<option value="">Select student…</option>' + (result.body.data.items || []).map(function (s) {
            return '<option value="' + s.id + '">' + escapeHtml(s.name + " · " + s.admission_number) + "</option>";
          }).join("");
        });
      }, 250);
    });

    assignForm?.addEventListener("submit", function (event) {
      event.preventDefault();
      if (!currentExam) return;
      const type = assignForm.type.value;
      const payload = { type: type };
      if (type === "offering") payload.class_section_offering_id = Number(assignForm.class_section_offering_id.value);
      else payload.student_profile_id = Number(assignForm.student_profile_id.value);
      request("/api/v1/cbt/admin/exams/" + currentExam.id + "/assignments", {
        method: "POST",
        body: JSON.stringify(payload)
      }).then(function (result) {
        if (!result.ok) return showAlert(firstError(result.body));
        showAlert("Assignment saved.", "info");
        renderExam(result.body.data);
      });
    });

    document.querySelector("[data-assign-rows]")?.addEventListener("click", function (event) {
      const id = event.target.getAttribute("data-unassign");
      if (!id) return;
      confirmAction("Remove assignment", "Remove this assignment?").then(function (ok) {
        if (!ok) return;
        request("/api/v1/cbt/admin/exams/" + currentExam.id + "/assignments/" + id, { method: "DELETE" })
          .then(function (result) {
            if (!result.ok) return showAlert(firstError(result.body));
            renderExam(result.body.data);
          });
      });
    });

    document.querySelector("[data-exam-publish]")?.addEventListener("click", function () {
      if (!currentExam) return;
      const review = document.querySelector("[data-publish-review]");
      review.innerHTML = "<ul class=\"cbt-rules\">"
        + "<li><strong>Title:</strong> " + escapeHtml(currentExam.title) + "</li>"
        + "<li><strong>Subject:</strong> " + escapeHtml(currentExam.subject || "") + "</li>"
        + "<li><strong>Offering:</strong> " + escapeHtml(currentExam.class_section_offering || "") + "</li>"
        + "<li><strong>Session / Term:</strong> " + escapeHtml((currentExam.academic_session || "") + " / " + (currentExam.term || "")) + "</li>"
        + "<li><strong>Duration:</strong> " + escapeHtml(currentExam.duration_minutes) + " min</li>"
        + "<li><strong>Questions / Max marks:</strong> " + escapeHtml(currentExam.question_count) + " / " + escapeHtml(currentExam.max_score) + "</li>"
        + "<li><strong>Pass mark:</strong> " + escapeHtml(currentExam.pass_mark || "—") + "</li>"
        + "<li><strong>Assignments:</strong> " + escapeHtml(currentExam.assignment_count) + "</li>"
        + "<li><strong>Window:</strong> " + escapeHtml(formatWhen(currentExam.starts_at)) + " → " + escapeHtml(formatWhen(currentExam.ends_at)) + "</li>"
        + "<li><strong>Randomize:</strong> questions " + (currentExam.randomize_questions ? "on" : "off")
        + ", options " + (currentExam.randomize_options ? "on" : "off") + "</li>"
        + "<li><strong>Max attempts:</strong> " + escapeHtml(currentExam.max_attempts) + "</li>"
        + "</ul>";
      openModal(document.querySelector("[data-publish-modal]"));
    });
    document.querySelector("[data-publish-cancel]")?.addEventListener("click", function () {
      closeModal(document.querySelector("[data-publish-modal]"));
    });
    document.querySelector("[data-publish-confirm]")?.addEventListener("click", function () {
      request("/api/v1/cbt/admin/exams/" + currentExam.id + "/publish", { method: "POST", body: "{}" })
        .then(function (result) {
          if (!result.ok) return showAlert(firstError(result.body));
          closeModal(document.querySelector("[data-publish-modal]"));
          showAlert("Exam published and frozen.", "info");
          renderExam(result.body.data);
        });
    });

    document.querySelector("[data-exam-archive]")?.addEventListener("click", function () {
      confirmAction("Archive exam", "Archive this published exam? Students will no longer take it.").then(function (ok) {
        if (!ok) return;
        request("/api/v1/cbt/admin/exams/" + currentExam.id + "/archive", { method: "POST", body: "{}" })
          .then(function (result) {
            if (!result.ok) return showAlert(firstError(result.body));
            showAlert("Exam archived.", "info");
            renderExam(result.body.data);
          });
      });
    });
  };

  /* -------- Preview -------- */
  const loadPreview = function () {
    const id = pathId("exams");
    document.querySelector("[data-preview-back]").href = "/cbt/admin/exams/" + id;
    request("/api/v1/cbt/admin/exams/" + id + "/preview").then(function (result) {
      if (!result.ok) {
        showAlert(firstError(result.body));
        return;
      }
      const exam = result.body.data;
      document.querySelector("[data-preview-title]").textContent = exam.title;
      document.querySelector("[data-preview-meta]").innerHTML = statusBadge(exam.status, exam.availability, exam.is_frozen)
        + " · ADMIN PREVIEW · " + escapeHtml(exam.subject || "");
      document.querySelector("[data-preview-instructions]").textContent = exam.instructions || "No instructions.";
      const host = document.querySelector("[data-preview-questions]");
      host.innerHTML = (exam.exam_questions || []).map(function (q, index) {
        return '<div class="cbt-question"><div class="cbt-question-head"><span>Q' + (index + 1)
          + "</span><span>" + escapeHtml(q.marks) + " marks</span></div>"
          + '<p class="cbt-stem">' + escapeHtml(q.stem) + "</p>"
          + '<div class="cbt-options">' + (q.options || []).map(function (o) {
            return '<div class="cbt-option' + (o.is_correct ? " is-selected" : "") + '">'
              + "<strong>" + escapeHtml(o.label || "") + "</strong> " + escapeHtml(o.body)
              + (o.is_correct ? " <em>(correct)</em>" : "") + "</div>";
          }).join("") + "</div></div>";
      }).join("") || '<div class="cbt-empty">No questions.</div>';
    });
  };

  /* -------- Results -------- */
  let resultsPage = 1;
  const loadResults = function (pageNum) {
    resultsPage = pageNum || 1;
    const form = document.querySelector("[data-results-filters]");
    const params = new URLSearchParams(new FormData(form));
    params.set("page", String(resultsPage));
    const rows = document.querySelector("[data-results-rows]");
    request("/api/v1/cbt/admin/results?" + params.toString()).then(function (result) {
      if (!result.ok) {
        rows.innerHTML = '<tr><td colspan="10">Unable to load results.</td></tr>';
        showAlert(firstError(result.body));
        return;
      }
      const data = result.body.data || {};
      const items = data.items || [];
      if (!items.length) {
        rows.innerHTML = '<tr><td colspan="10">No results found.</td></tr>';
      } else {
        rows.innerHTML = items.map(function (r) {
          return "<tr>"
            + "<td>" + escapeHtml(r.exam_title || ("#" + r.exam_id)) + "</td>"
            + "<td>" + escapeHtml((r.student_name || "—") + " · " + (r.admission_number || "")) + "</td>"
            + "<td>" + escapeHtml(r.score + " / " + r.max_score) + "</td>"
            + "<td>" + escapeHtml(r.percentage) + "</td>"
            + "<td>" + escapeHtml(r.grade || "—") + "</td>"
            + "<td>" + (r.passed ? "Pass" : "Fail") + "</td>"
            + "<td>" + (r.result_checker_unlocked ? "Unlocked" : "Locked") + "</td>"
            + "<td>" + escapeHtml(r.result_checker_payment_reference || "—") + "</td>"
            + "<td>" + escapeHtml(formatWhen(r.submitted_at)) + "</td>"
            + "<td>" + escapeHtml(r.attempt_status || "—") + "</td>"
            + "</tr>";
        }).join("");
      }
      renderPager(document.querySelector("[data-results-pager]"), data.meta, loadResults);
    });
  };

  loadBootstrap().then(function (data) {
    if (!data) return;
    if (page === "home") renderHome(data);
    if (page === "questions") {
      if (!capabilities.manage) {
        showAlert("Question bank requires cbt.manage.");
        return;
      }
      bindQuestions();
      loadQuestions(1);
    }
    if (page === "exams") {
      bindExamsList();
      loadExams(1);
    }
    if (page === "exam") {
      bindExamBuilder();
      loadExam();
    }
    if (page === "preview") {
      if (!capabilities.manage) {
        showAlert("Admin preview requires cbt.manage.");
        return;
      }
      loadPreview();
    }
    if (page === "results") {
      if (!capabilities.manage && !capabilities.mark) {
        showAlert("Results require cbt.manage or cbt.mark.");
        return;
      }
      document.querySelector("[data-results-filters]")?.addEventListener("submit", function (event) {
        event.preventDefault();
        loadResults(1);
      });
      loadResults(1);
    }
    if (page === "monitor") {
      if (!capabilities.manage && !capabilities.mark) {
        showAlert("Monitor requires cbt.manage or cbt.mark.");
        return;
      }
      bindMonitor();
    }
    if (page === "attempts") {
      if (!capabilities.manage && !capabilities.mark) {
        showAlert("Attempts require cbt.manage or cbt.mark.");
        return;
      }
      bindAttempts();
    }
    if (page === "reports") {
      if (!capabilities.manage && !capabilities.mark) {
        showAlert("Reports require cbt.manage or cbt.mark.");
        return;
      }
      bindReports();
    }
    if (page === "result-checkers") {
      if (!capabilities.manage) {
        showAlert("Result Checkers require cbt.manage.");
        return;
      }
      bindResultCheckers();
    }
  });

  const bindResultCheckers = function () {
    const loadStats = function () {
      request("/api/v1/cbt/admin/result-checkers/statistics").then(function (result) {
        const host = document.querySelector("[data-rc-stats]");
        if (!result.ok || !host) return;
        const d = result.body.data || {};
        host.innerHTML = [
          ["Revenue (kobo)", d.revenue_kobo],
          ["Paid purchases", d.purchases_paid],
          ["Pending", d.purchases_pending],
          ["Active checkers", d.active_checkers],
          ["Failed payments", d.payments_failed]
        ].map(function (row) {
          return '<div class="cbt-stat"><strong>' + escapeHtml(row[1] ?? 0) + '</strong><span>' + escapeHtml(row[0]) + '</span></div>';
        }).join("");
      });
    };
    const loadProducts = function () {
      request("/api/v1/cbt/admin/result-checkers/products").then(function (result) {
        if (!result.ok) return showAlert(firstError(result.body));
        const data = result.body.data || {};
        const requirePayment = document.querySelector("[data-rc-require-payment]");
        if (requirePayment) requirePayment.checked = !!data.details_require_payment;
        const rows = document.querySelector("[data-rc-products]");
        rows.innerHTML = (data.items || []).map(function (p) {
          return "<tr><td>" + escapeHtml(p.name) + "</td><td>" + escapeHtml(p.code) + "</td><td>"
            + escapeHtml(p.amount_label) + "</td><td>" + escapeHtml(p.checks_allowed) + "</td><td>"
            + (p.is_active ? "Active" : "Inactive") + '</td><td><button type="button" class="cbt-btn cbt-btn-ghost" data-rc-edit=\''
            + escapeHtml(JSON.stringify(p)) + "'>Edit</button></td></tr>";
        }).join("") || '<tr><td colspan="6">No products</td></tr>';
      });
    };
    const loadPurchases = function () {
      request("/api/v1/cbt/admin/result-checkers/purchases").then(function (result) {
        if (!result.ok) return;
        const rows = document.querySelector("[data-rc-purchases]");
        rows.innerHTML = ((result.body.data && result.body.data.items) || []).map(function (p) {
          return "<tr><td>" + escapeHtml(p.student_name || "—") + "</td><td>" + escapeHtml(p.exam_title || "—") + "</td><td>"
            + escapeHtml(p.amount_label) + "</td><td>" + escapeHtml(p.status) + "</td><td>"
            + escapeHtml(p.paid_at || "—") + "</td><td>" + escapeHtml(p.payment_reference || "—") + "</td></tr>";
        }).join("") || '<tr><td colspan="6">No purchases</td></tr>';
      });
    };
    document.querySelector("[data-rc-require-payment]")?.addEventListener("change", function (event) {
      request("/api/v1/cbt/admin/result-checkers/policy", {
        method: "POST",
        body: JSON.stringify({ cbt_result_details_require_payment: !!event.target.checked })
      }).then(function (result) {
        if (!result.ok) showAlert(firstError(result.body));
        else showAlert("Policy saved.", "info");
      });
    });
    const modal = document.querySelector("[data-rc-modal]");
    const form = document.querySelector("[data-rc-form]");
    document.querySelector("[data-rc-new]")?.addEventListener("click", function () {
      form.reset();
      form.id.value = "";
      form.is_active.checked = true;
      openModal(modal);
    });
    document.querySelector("[data-rc-cancel]")?.addEventListener("click", function () { closeModal(modal); });
    document.querySelector("[data-rc-products]")?.addEventListener("click", function (event) {
      const raw = event.target.getAttribute("data-rc-edit");
      if (!raw) return;
      const p = JSON.parse(raw);
      form.id.value = p.id;
      form.name.value = p.name;
      form.code.value = p.code;
      form.description.value = p.description || "";
      form.amount_naira.value = Math.round((p.amount_kobo || 0) / 100);
      form.checks_allowed.value = p.checks_allowed;
      form.duration_days.value = p.duration_days || "";
      form.is_active.checked = !!p.is_active;
      openModal(modal);
    });
    form?.addEventListener("submit", function (event) {
      event.preventDefault();
      const id = form.id.value;
      const payload = {
        name: form.name.value,
        code: form.code.value,
        description: form.description.value || null,
        amount_kobo: Math.round(Number(form.amount_naira.value) * 100),
        checks_allowed: Number(form.checks_allowed.value || 1),
        duration_days: form.duration_days.value ? Number(form.duration_days.value) : null,
        is_active: !!form.is_active.checked
      };
      request(id ? "/api/v1/cbt/admin/result-checkers/products/" + id : "/api/v1/cbt/admin/result-checkers/products", {
        method: "POST",
        body: JSON.stringify(payload)
      }).then(function (result) {
        if (!result.ok) return showAlert(firstError(result.body));
        closeModal(modal);
        showAlert("Product saved.", "info");
        loadProducts();
      });
    });
    loadStats();
    loadProducts();
    loadPurchases();
  };
})();
