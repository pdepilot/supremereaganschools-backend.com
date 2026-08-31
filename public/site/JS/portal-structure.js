(function () {
  const csrfToken = function () {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : "";
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
          window.location.replace((window.srsLoginPath && window.srsLoginPath()) || "/portal/login");
        }
        return { ok: response.ok, status: response.status, body: body };
      });
    });
  };

  const firstError = function (body) {
    if (!body) return "The office could not complete that request.";
    if (body.errors) {
      const keys = Object.keys(body.errors);
      if (keys.length) return body.errors[keys[0]][0];
    }
    return body.message || "The office could not complete that request.";
  };

  const setButtonState = function (button, busy, label) {
    if (!button) return;
    if (!button.dataset.label) button.dataset.label = button.textContent;
    button.disabled = !!busy;
    button.classList.toggle("is-busy", !!busy);
    if (label) button.textContent = label;
    else if (!busy) button.textContent = button.dataset.label;
  };

  const statusLabel = function (status) {
    if (status === "active") return "Live";
    if (status === "archived") return "Archived";
    return "Planned";
  };

  const badgeClass = function (status) {
    return status === "active" ? "ok" : "warn";
  };

  const roman = function (number) {
    return ["", "I", "II", "III"][Number(number)] || String(number);
  };

  const mark = function (value) {
    if (!value) return "—";
    return String(value).replace(/[^A-Za-z0-9]/g, "").slice(0, 3).toUpperCase() || "—";
  };

  const escapeHtml = function (value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  };

  const confirmDesk = function (options) {
    const root = document.querySelector("[data-desk-alert]");
    if (!root) return Promise.resolve(false);

    const card = root.querySelector(".desk-alert-card");
    const title = root.querySelector("[data-desk-alert-title]");
    const copy = root.querySelector("[data-desk-alert-copy]");
    const confirmBtn = root.querySelector("[data-desk-alert-confirm]");
    const cancelBtn = root.querySelector(".desk-alert-actions [data-desk-alert-dismiss]");
    const assignField = root.querySelector("[data-desk-assign-field]");
    const assignSelect = root.querySelector("[data-desk-assign-staff]");
    const danger = !!(options && options.danger);
    const assign = !!(options && options.assign);
    const previous = document.activeElement;

    if (title) title.textContent = (options && options.title) || "Seal this action?";
    if (copy) copy.textContent = (options && options.copy) || "";
    if (confirmBtn) {
      confirmBtn.textContent = (options && options.confirmLabel) || "Confirm";
      confirmBtn.classList.toggle("is-danger", danger);
    }
    if (cancelBtn) cancelBtn.textContent = (options && options.cancelLabel) || "Keep them";
    if (card) card.classList.toggle("is-danger", danger);
    if (assignField) assignField.hidden = !assign;
    if (assignSelect) assignSelect.value = "";

    return new Promise(function (resolve) {
      let settled = false;
      const finish = function (value) {
        if (settled) return;
        settled = true;
        root.hidden = true;
        document.body.classList.remove("desk-alert-open");
        document.removeEventListener("keydown", onKey);
        root.removeEventListener("click", onClick);
        if (previous && typeof previous.focus === "function") previous.focus();
        resolve(value);
      };
      const onKey = function (event) {
        if (event.key === "Escape") finish(false);
      };
      const onClick = function (event) {
        if (event.target.closest("[data-desk-alert-confirm]")) {
          event.preventDefault();
          if (assign && assignSelect && !assignSelect.value) {
            assignSelect.focus();
            return;
          }
          finish(assign ? assignSelect.value : true);
          return;
        }
        if (event.target.closest("[data-desk-alert-dismiss]")) {
          event.preventDefault();
          finish(false);
        }
      };

      root.hidden = false;
      document.body.classList.add("desk-alert-open");
      document.addEventListener("keydown", onKey);
      root.addEventListener("click", onClick);
      const focusTarget = assign ? assignSelect : (danger ? cancelBtn : confirmBtn);
      if (focusTarget) focusTarget.focus();
    });
  };

  const setMetric = function (label, value, delta) {
    document.querySelectorAll(".metric").forEach(function (metric) {
      const name = metric.querySelector(".label");
      if (!name || name.textContent.trim() !== label) return;
      const valueNode = metric.querySelector(".value");
      const deltaNode = metric.querySelector(".delta");
      if (valueNode) valueNode.textContent = value;
      if (delta && deltaNode) deltaNode.textContent = delta;
    });
  };

  const fieldValue = function (id) {
    const node = document.getElementById(id);
    return node ? node.value.trim() : "";
  };

  const setField = function (id, value) {
    const node = document.getElementById(id);
    if (node) node.value = value || "";
  };

  const hourLabel = function (value) {
    if (!value) return "";
    const hour = Number(String(value).split(":")[0]);
    if (!Number.isFinite(hour)) return "";
    return String(hour % 12 || 12);
  };

  const fillTerms = function (sessions, sessionId, selectedTermId) {
    const term = document.getElementById("currentTerm");
    if (!term) return;
    const session = sessions.find(function (row) { return String(row.id) === String(sessionId); });
    const terms = (session && session.terms) || [];
    term.innerHTML = '<option value="">Not set</option>' + terms.map(function (row) {
      return '<option value="' + row.id + '">' + row.name + "</option>";
    }).join("");
    if (selectedTermId) term.value = String(selectedTermId);
  };

  const applyOfficeMetric = function (opens, closes) {
    if (!opens && !closes) return;
    const start = hourLabel(opens);
    const end = hourLabel(closes);
    const range = start && end ? start + "–" + end : (start || end);
    setMetric("Office hours", range || "8–4", "Monday to Friday");
  };

  const wireSettings = function () {
    const form = document.querySelector("[data-school-form]");
    if (!form) return;

    const button = form.querySelector(".solid-btn");
    let sessions = [];

    const sessionSelect = document.getElementById("currentSession");
    if (sessionSelect) {
      sessionSelect.addEventListener("change", function () {
        fillTerms(sessions, sessionSelect.value, "");
      });
    }

    request("/api/v1/school-settings").then(function (result) {
      if (!result.ok) return;
      const data = result.body.data || {};
      setField("schoolName", data.name);
      setField("shortName", data.short_name);
      setField("motto", data.motto);
      setField("address", data.address);
      setField("phone", data.phone);
      setField("officeEmail", data.email);
      setField("whatsapp", data.whatsapp);
      setField("website", data.website);
      setField("officeOpens", data.office_opens_at);
      setField("officeCloses", data.office_closes_at);
      applyOfficeMetric(data.office_opens_at, data.office_closes_at);
      if (data.founded_on) {
        setMetric("Founded", data.founded_on.slice(0, 4), "13 September");
      }
      return request("/api/v1/academic-sessions").then(function (sessionResult) {
        if (!sessionResult.ok) return;
        sessions = sessionResult.body.data || [];
        if (sessionSelect) {
          sessionSelect.innerHTML = '<option value="">Not set</option>' + sessions.map(function (row) {
            return '<option value="' + row.id + '">' + row.name + "</option>";
          }).join("");
          if (data.current_academic_session_id) {
            sessionSelect.value = String(data.current_academic_session_id);
          }
        }
        fillTerms(sessions, data.current_academic_session_id, data.current_term_id);
      });
    });

    request("/api/v1/campuses").then(function (result) {
      if (!result.ok) return;
      const rows = result.body.data || [];
      setMetric("Campus", String(rows.length), rows[0] ? rows[0].name : "Spibat Road");
    });

    request("/api/v1/levels").then(function (result) {
      if (!result.ok) return;
      const rows = result.body.data || [];
      setMetric("Levels", String(rows.length), rows.map(function (row) { return row.name; }).join(" · "));
    });

    form.addEventListener("submit", function (event) {
      event.preventDefault();
      setButtonState(button, true, "Sealing…");
      const sessionId = fieldValue("currentSession");
      const termId = fieldValue("currentTerm");
      request("/api/v1/school-settings", {
        method: "PUT",
        body: JSON.stringify({
          name: fieldValue("schoolName"),
          short_name: fieldValue("shortName"),
          motto: fieldValue("motto"),
          address: fieldValue("address"),
          phone: fieldValue("phone"),
          email: fieldValue("officeEmail"),
          whatsapp: fieldValue("whatsapp"),
          website: fieldValue("website"),
          office_opens_at: fieldValue("officeOpens"),
          office_closes_at: fieldValue("officeCloses"),
          current_academic_session_id: sessionId ? Number(sessionId) : null,
          current_term_id: termId ? Number(termId) : null
        })
      }).then(function (result) {
        setButtonState(button, false, result.ok ? "Sealed." : firstError(result.body));
        if (result.ok) {
          const data = result.body.data || {};
          applyOfficeMetric(data.office_opens_at, data.office_closes_at);
        }
        window.setTimeout(function () { setButtonState(button, false); }, 1800);
      }).catch(function () {
        setButtonState(button, false, "Unable to reach the office.");
        window.setTimeout(function () { setButtonState(button, false); }, 1800);
      });
    });
  };

  const roleCopy = function (roles) {
    if ((roles || []).indexOf("super_admin") !== -1) return "Sovereign · whole school";
    if ((roles || []).indexOf("school_admin") !== -1) return "Administrator · daily ledger";
    return "Desk";
  };

  const roleCode = function (roles) {
    if ((roles || []).indexOf("super_admin") !== -1) return "Ω";
    if ((roles || []).indexOf("school_admin") !== -1) return "ADM";
    return "DESK";
  };

  const wireDesks = function () {
    const list = document.querySelector("[data-desk-list]");
    if (!list) return;

    request("/api/v1/desk-access").then(function (result) {
      if (!result.ok) {
        list.innerHTML = "<p>" + firstError(result.body) + "</p>";
        return;
      }
      const data = result.body.data || {};
      const admins = data.admins || [];
      const staffCount = data.staff_count || 0;
      const cards = admins.map(function (admin) {
        return '<article class="ticket">'
          + '<div class="ticket-code">' + roleCode(admin.roles) + "</div>"
          + "<div><h3>" + admin.name + "</h3><p>" + roleCopy(admin.roles) + "<br>" + (admin.email || "") + "</p></div>"
          + '<span class="badge ' + (admin.status === "active" ? "ok" : "warn") + '">'
          + (admin.status === "active" ? "Live" : "Held") + "</span>"
          + "</article>";
      });
      cards.push(
        '<article class="ticket">'
        + '<div class="ticket-code">STAFF</div>'
        + "<div><h3>Masters’ portal</h3><p>Classroom desks only · " + staffCount + " staff records</p></div>"
        + '<span class="badge warn">Limited</span>'
        + "</article>"
      );
      list.innerHTML = cards.join("");
    });
  };

  const wireAccount = function () {
    const form = document.querySelector("[data-account-form]");
    if (!form) return;
    const button = form.querySelector(".solid-btn");

    request("/api/v1/me").then(function (result) {
      if (!result.ok) return;
      const data = result.body.data || {};
      setField("accountName", data.name);
      setField("accountEmail", data.email);
    });

    form.addEventListener("submit", function (event) {
      event.preventDefault();
      setButtonState(button, true, "Sealing…");
      request("/api/v1/me", {
        method: "PUT",
        body: JSON.stringify({
          name: fieldValue("accountName"),
          email: fieldValue("accountEmail")
        })
      }).then(function (result) {
        setButtonState(button, false, result.ok ? "Sealed." : firstError(result.body));
        window.setTimeout(function () { setButtonState(button, false); }, 1800);
      }).catch(function () {
        setButtonState(button, false, "Unable to reach the office.");
        window.setTimeout(function () { setButtonState(button, false); }, 1800);
      });
    });
  };

  const wirePassword = function () {
    const form = document.querySelector("[data-password-form]");
    if (!form) return;
    const button = form.querySelector(".solid-btn");

    form.addEventListener("submit", function (event) {
      event.preventDefault();
      setButtonState(button, true, "Resetting…");
      request("/api/v1/me/password", {
        method: "PUT",
        body: JSON.stringify({
          current_password: fieldValue("currentPassword"),
          password: fieldValue("newPassword"),
          password_confirmation: fieldValue("confirmPassword")
        })
      }).then(function (result) {
        if (result.ok) form.reset();
        setButtonState(button, false, result.ok ? "Passphrase reset." : firstError(result.body));
        window.setTimeout(function () { setButtonState(button, false); }, 2200);
      }).catch(function () {
        setButtonState(button, false, "Unable to reach the office.");
        window.setTimeout(function () { setButtonState(button, false); }, 1800);
      });
    });
  };

  const wireSessions = function () {
    const list = document.querySelector("[data-session-list]");
    const form = document.querySelector("[data-session-form]");
    if (!list && !form) return;

    const copy = document.querySelector("[data-session-copy]");
    const notice = document.querySelector("[data-session-notice]");
    const formNotice = document.querySelector("[data-session-form-notice]");
    const root = document.querySelector("[data-session-root]") || list;
    let rows = [];
    let settings = {};

    const setMetricValue = function (key, value) {
      const node = document.querySelector('[data-metric="' + key + '"]');
      if (node) node.textContent = value;
    };

    const setMetricDelta = function (key, value) {
      const node = document.querySelector('[data-metric-delta="' + key + '"]');
      if (node) node.textContent = value;
    };

    const shortYear = function (name) {
      return String(name || "").replace(/^20/, "").replace(/\/20/, "/") || "—";
    };

    const weeksRun = function (from, to) {
      if (!from) return null;
      const start = new Date(from + "T00:00:00");
      const end = to ? new Date(to + "T00:00:00") : new Date();
      const ms = end.getTime() - start.getTime();
      if (!Number.isFinite(ms)) return null;
      if (ms < 0) return 0;
      return Math.max(1, Math.floor(ms / (7 * 24 * 60 * 60 * 1000)) + 1);
    };

    const isoDate = function (date) {
      const year = date.getFullYear();
      const month = String(date.getMonth() + 1).padStart(2, "0");
      const day = String(date.getDate()).padStart(2, "0");
      return year + "-" + month + "-" + day;
    };

    const shiftYear = function (value) {
      if (!value) return "";
      const date = new Date(value + "T00:00:00");
      if (!Number.isFinite(date.getTime())) return "";
      date.setFullYear(date.getFullYear() + 1);
      return isoDate(date);
    };

    const suggestYear = function () {
      const nameInput = document.getElementById("yearName");
      const start = document.getElementById("startDate");
      const end = document.getElementById("endDate");
      const live = document.getElementById("yearLive");
      const latest = rows.slice().sort(function (a, b) {
        return String(b.starts_on || "").localeCompare(String(a.starts_on || ""));
      })[0];
      const hasLive = !!settings.current_academic_session_id
        || rows.some(function (row) { return row.status === "active"; });

      if (!latest) {
        if (nameInput && !nameInput.value) nameInput.value = "2026/2027";
        if (start && !start.value) start.value = "2026-09-07";
        if (end && !end.value) end.value = "2027-07-23";
        if (live) live.checked = true;
        return;
      }

      const match = String(latest.name || "").match(/(\d{4})\s*\/\s*(\d{4})/);
      if (nameInput && !nameInput.value && match) {
        nameInput.value = (Number(match[1]) + 1) + "/" + (Number(match[2]) + 1);
      }
      if (start && !start.value) start.value = shiftYear(latest.starts_on);
      if (end && !end.value) end.value = shiftYear(latest.ends_on);
      if (live) live.checked = !hasLive;
    };

    const applyMetrics = function () {
      const currentId = settings.current_academic_session_id;
      const current = rows.find(function (row) { return Number(row.id) === Number(currentId); })
        || rows.find(function (row) { return row.status === "active"; });
      const currentTerm = current && (current.terms || []).find(function (term) {
        return Number(term.id) === Number(settings.current_term_id);
      }) || (current && (current.terms || []).find(function (term) { return term.status === "active"; }));

      if (current) {
        setMetricValue("year", shortYear(current.name));
        setMetricDelta("year", current.status === "active" ? "Live session" : statusLabel(current.status));
      } else {
        setMetricValue("year", "—");
        setMetricDelta("year", "No year sealed");
      }

      if (currentTerm) {
        setMetricValue("term", roman(currentTerm.term_number));
        setMetricDelta("term", currentTerm.name);
        const run = weeksRun(currentTerm.starts_on, currentTerm.ends_on && new Date(currentTerm.ends_on) < new Date() ? currentTerm.ends_on : null);
        const span = weeksRun(currentTerm.starts_on, currentTerm.ends_on);
        if (run == null) {
          setMetricValue("weeks", "—");
          setMetricDelta("weeks", "Dates not sealed");
        } else {
          setMetricValue("weeks", String(run));
          setMetricDelta("weeks", span ? "Of " + span : "This term");
        }
      } else {
        setMetricValue("term", "—");
        setMetricDelta("term", "No term sealed");
        setMetricValue("weeks", "—");
        setMetricDelta("weeks", "This term");
      }

      setMetricValue("years", String(rows.length));
      const founded = settings.founded_on ? settings.founded_on.slice(0, 4) : "";
      setMetricDelta("years", founded ? "Since " + founded : "On the ledger");
      if (copy) {
        copy.textContent = rows.length
          ? rows.length + " year" + (rows.length === 1 ? "" : "s") + " on the ledger"
          : "No years sealed yet";
      }
    };

    const render = function () {
      applyMetrics();
      if (!list) return;
      if (!rows.length) {
        list.innerHTML = "<p>No academic sessions have been sealed yet.</p>";
        return;
      }

      list.innerHTML = rows.map(function (row) {
        const liveTerm = (row.terms || []).find(function (item) { return item.status === "active"; });
        const isCurrent = Number(row.id) === Number(settings.current_academic_session_id);
        const title = row.status === "active" || isCurrent ? "Current session" : "Session";
        const copyLine = row.status === "active"
          ? ((liveTerm && liveTerm.name) || "In session") + " · " + row.starts_on + " – " + row.ends_on
          : (row.status === "archived" ? "Closed " + (row.ends_on || "") : "Planned") + " · " + row.term_count + " terms";
        const actions = [];
        const previous = rows.slice()
          .filter(function (item) { return String(item.starts_on || "") < String(row.starts_on || ""); })
          .sort(function (a, b) { return String(b.starts_on || "").localeCompare(String(a.starts_on || "")); })[0];
        if (previous && row.status !== "archived") {
          actions.push('<button class="ghost-btn" type="button" data-promote-session="' + row.id
            + '" data-source-session="' + previous.id
            + '" data-source-name="' + escapeHtml(previous.name)
            + '" data-name="' + escapeHtml(row.name) + '">Copy from '
            + escapeHtml(previous.name) + "</button>");
        }
        if (row.status !== "active") {
          actions.push('<button class="ghost-btn" type="button" data-activate-session="' + row.id
            + '" data-name="' + escapeHtml(row.name) + '">Make live</button>');
        }
        if (row.status !== "archived") {
          actions.push('<button class="ghost-btn" type="button" data-archive-session="' + row.id
            + '" data-name="' + escapeHtml(row.name) + '">Archive</button>');
        }
        (row.terms || []).forEach(function (term) {
          const sealed = Number(term.id) === Number(settings.current_term_id) && term.status === "active";
          if (sealed) return;
          actions.push('<button class="ghost-btn" type="button" data-seal-term="' + term.id
            + '" data-name="' + escapeHtml(term.name) + '" data-year="' + escapeHtml(row.name) + '">Seal '
            + escapeHtml(term.name) + "</button>");
        });
        return '<article class="ticket">'
          + '<div class="ticket-code">' + escapeHtml(row.name) + "</div>"
          + "<div><h3>" + title + "</h3><p>" + escapeHtml(copyLine) + "</p></div>"
          + '<span class="badge ' + badgeClass(row.status) + '">' + statusLabel(row.status) + "</span>"
          + (actions.length ? '<div class="row-actions">' + actions.join("") + "</div>" : "")
          + "</article>";
      }).join("");
    };

    const load = function () {
      return Promise.all([
        request("/api/v1/academic-sessions"),
        request("/api/v1/school-settings")
      ]).then(function (results) {
        if (!results[0].ok) {
          if (list) list.innerHTML = "<p>" + escapeHtml(firstError(results[0].body)) + "</p>";
          return;
        }
        rows = results[0].body.data || [];
        settings = (results[1].ok && results[1].body.data) || {};
        render();
        suggestYear();
      });
    };

    load();

    if (root) {
      root.addEventListener("click", function (event) {
        const button = event.target.closest("[data-activate-session], [data-archive-session], [data-seal-term], [data-promote-session]");
        if (!button) return;
        const name = button.getAttribute("data-name") || "this year";
        const year = button.getAttribute("data-year") || "";
        const sessionId = button.getAttribute("data-activate-session") || button.getAttribute("data-archive-session");
        const termId = button.getAttribute("data-seal-term");
        const promoteId = button.getAttribute("data-promote-session");
        const sourceId = button.getAttribute("data-source-session");
        const sourceName = button.getAttribute("data-source-name") || "the previous year";

        let path = "";
        let method = "POST";
        let payload = null;
        let alertOptions = {};

        if (button.hasAttribute("data-activate-session")) {
          path = "/api/v1/academic-sessions/" + sessionId + "/activate";
          alertOptions = {
            title: "Make this the live year",
            copy: name + " will become the current session. The previous live year will be archived.",
            confirmLabel: "Make live",
            cancelLabel: "Leave the calendar",
            danger: false
          };
        } else if (button.hasAttribute("data-promote-session")) {
          path = "/api/v1/academic-sessions/" + promoteId + "/promote";
          payload = {
            source_academic_session_id: Number(sourceId),
            copy_teachers: true,
            enroll_pupils: true
          };
          alertOptions = {
            title: "Copy the school into this year",
            copy: "Forms, subjects, class teachers, and continuing pupils will be copied from "
              + sourceName + " into " + name
              + ". Marks already sealed on this year’s terms will move with them. You can run this more than once.",
            confirmLabel: "Copy the roll",
            cancelLabel: "Leave it",
            danger: false
          };
        } else if (button.hasAttribute("data-archive-session")) {
          path = "/api/v1/academic-sessions/" + sessionId;
          method = "PUT";
          payload = { status: "archived" };
          alertOptions = {
            title: "Archive this year",
            copy: name + " will leave the live calendar. Forms and the roll stay sealed on the ledger.",
            confirmLabel: "Archive year",
            cancelLabel: "Keep it open",
            danger: true
          };
        } else {
          path = "/api/v1/terms/" + termId + "/activate";
          alertOptions = {
            title: "Seal this term",
            copy: name + (year ? " of " + year : "") + " will become the current term for the whole school.",
            confirmLabel: "Seal term",
            cancelLabel: "Leave it planned",
            danger: false
          };
        }

        confirmDesk(alertOptions).then(function (ok) {
          if (!ok) return;
          if (notice) notice.textContent = "";
          button.disabled = true;
          const options = { method: method };
          if (payload) options.body = JSON.stringify(payload);
          request(path, options).then(function (result) {
            if (!result.ok) {
              if (notice) notice.textContent = firstError(result.body);
              button.disabled = false;
              return;
            }
            return load();
          }).catch(function () {
            if (notice) notice.textContent = "Unable to reach the office.";
            button.disabled = false;
          });
        });
      });
    }

    if (!form) return;
    const button = form.querySelector(".solid-btn");
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      const name = document.getElementById("yearName");
      const start = document.getElementById("startDate");
      const end = document.getElementById("endDate");
      const terms = document.getElementById("termCount");
      const live = document.getElementById("yearLive");
      if (formNotice) formNotice.textContent = "";
      setButtonState(button, true, "Sealing…");
      request("/api/v1/academic-sessions", {
        method: "POST",
        body: JSON.stringify({
          name: name ? name.value : "",
          starts_on: start ? start.value : "",
          ends_on: end ? end.value : "",
          term_count: terms ? Number(terms.value) : 3,
          status: live && live.checked ? "active" : "planned"
        })
      }).then(function (result) {
        if (!result.ok) {
          const message = firstError(result.body);
          if (formNotice) formNotice.textContent = message;
          setButtonState(button, false, message);
          window.setTimeout(function () { setButtonState(button, false); }, 2200);
          return;
        }
        form.reset();
        if (terms) terms.value = "3";
        setButtonState(button, false, "Sealed.");
        window.setTimeout(function () { setButtonState(button, false); }, 1600);
        return load();
      }).catch(function () {
        if (formNotice) formNotice.textContent = "Unable to reach the office.";
        setButtonState(button, false, "Unable to reach the office.");
        window.setTimeout(function () { setButtonState(button, false); }, 1800);
      });
    });
  };

  const wireClasses = function () {
    const table = document.querySelector("[data-form-table]");
    if (!table) return;

    const PAGE_SIZE = 10;
    const search = document.getElementById("formSearch");
    const levelFilter = document.getElementById("levelFilter");
    const sessionFilter = document.getElementById("sessionFilter");
    const statusFilter = document.getElementById("statusFilter");
    const copy = document.querySelector("[data-form-copy]");
    const notice = document.querySelector("[data-form-notice]");
    const pager = document.querySelector("[data-form-pager]");
    const pages = document.querySelector("[data-form-pages]");
    const form = document.querySelector("[data-form-form]");
    const formNotice = document.querySelector("[data-form-form-notice]");
    const subjectForm = document.querySelector("[data-subject-form]");
    const subjectFormNotice = document.querySelector("[data-subject-form-notice]");
    const subjectNotice = document.querySelector("[data-subject-notice]");
    const root = document.querySelector("[data-form-root]") || table;
    const assignSelect = document.querySelector("[data-desk-assign-staff]");
    const newClassFields = document.querySelector("[data-new-class]");
    const newArmField = document.querySelector("[data-new-arm]");
    let offerings = [];
    let levels = [];
    let sessions = [];
    let campuses = [];
    let staff = [];
    let subjects = [];
    let departments = [];
    let selectedOfferingId = 0;
    let page = 1;

    const setMetricValue = function (key, value) {
      const node = document.querySelector('[data-metric="' + key + '"]');
      if (node) node.textContent = value;
    };

    const setMetricDelta = function (key, value) {
      const node = document.querySelector('[data-metric-delta="' + key + '"]');
      if (node && value) node.textContent = value;
    };

    const formName = function (row) {
      return row.form || ((row.class_section || {}).name) || "Form";
    };

    const subjectNames = function (row) {
      return ((row.subjects || []).map(function (item) { return item.name; }).filter(Boolean).join(", "));
    };

    const levelOf = function (row) {
      return {
        id: row.level_id || ((((row.class_section || {}).school_class || {}).level || {}).id) || 0,
        name: row.level_name || ((((row.class_section || {}).school_class || {}).level || {}).name) || "",
        slug: row.level_slug || ((((row.class_section || {}).school_class || {}).level || {}).slug) || ""
      };
    };

    const fillOptions = function (select, rows, placeholder, extra) {
      if (!select) return;
      const current = select.value;
      const extras = extra || [];
      select.innerHTML = '<option value="">' + placeholder + "</option>" + extras.map(function (item) {
        return '<option value="' + escapeHtml(item.value) + '">' + escapeHtml(item.label) + "</option>";
      }).join("") + (rows || []).map(function (row) {
        return '<option value="' + row.id + '">' + escapeHtml(row.name) + "</option>";
      }).join("");
      if (current && Array.from(select.options).some(function (option) { return option.value === current; })) {
        select.value = current;
      }
    };

    const assignableStaff = function () {
      return staff.filter(function (row) {
        const roles = row.roles || [];
        const teaching = roles.indexOf("teacher") !== -1
          || roles.indexOf("staff") !== -1
          || roles.indexOf("principal") !== -1
          || roles.indexOf("vice_principal") !== -1;
        return row.status === "active" && row.account_status !== "suspended" && teaching;
      });
    };

    const fillStaffSelects = function () {
      const rows = assignableStaff().map(function (row) {
        return { id: row.id, name: row.name + (row.staff_number ? " · " + row.staff_number : "") };
      });
      fillOptions(document.getElementById("formTeacher"), rows, "Appoint later");
      fillOptions(assignSelect, rows, "Select a master");
    };

    const renderSubjects = function () {
      const offering = offerings.find(function (row) { return Number(row.id) === Number(selectedOfferingId); }) || null;
      const title = document.querySelector("[data-subject-panel-title]");
      const copyNode = document.querySelector("[data-subject-panel-copy]");
      const offerBox = document.querySelector("[data-subject-offer]");
      const offeredBox = document.querySelector("[data-subject-offered]");
      const catalogue = document.querySelector("[data-subject-catalogue]");
      const pick = document.getElementById("subjectOfferPick");

      if (offering) {
        if (title) title.textContent = "Subjects on " + formName(offering);
        if (copyNode) copyNode.textContent = "Offer a sealed subject onto this form, or seal a new one below.";
        if (offerBox) offerBox.hidden = false;
        const offered = offering.subjects || [];
        const offeredIds = offered.map(function (row) { return Number(row.id); });
        if (offeredBox) {
          offeredBox.innerHTML = offered.length
            ? offered.map(function (row) {
                return '<span class="offering-pill">' + escapeHtml(row.name)
                  + ' <button type="button" class="ghost-btn" data-unoffer-subject="' + (row.offering_id || "")
                  + '" data-name="' + escapeHtml(row.name) + '">Remove</button></span>';
              }).join("")
            : "<p>No subjects offered on this form yet.</p>";
        }
        fillOptions(pick, subjects.filter(function (row) {
          return row.is_active !== false && offeredIds.indexOf(Number(row.id)) === -1;
        }).map(function (row) {
          return { id: row.id, name: row.name + (row.code ? " · " + row.code : "") };
        }), "Select a subject");
      } else {
        if (title) title.textContent = "The subjects";
        if (copyNode) copyNode.textContent = "Seal a subject into the school, then offer it on a form.";
        if (offerBox) offerBox.hidden = true;
      }

      if (catalogue) {
        catalogue.innerHTML = subjects.length
          ? subjects.map(function (row) {
              const dept = (row.department && row.department.name) || "";
              return '<div class="person subject-row"><div><strong>' + escapeHtml(row.name)
                + "</strong><small>" + escapeHtml([row.code, dept].filter(Boolean).join(" · ") || "Catalogue")
                + '</small></div><button class="ghost-btn" type="button" data-delete-subject="' + row.id
                + '" data-name="' + escapeHtml(row.name) + '">Remove</button></div>';
            }).join("")
          : "<p>No subjects on the books yet.</p>";
      }
    };

    const classesForLevel = function (levelId) {
      const level = levels.find(function (row) { return Number(row.id) === Number(levelId); });
      return (level && level.classes) || [];
    };

    const syncClassOptions = function () {
      const classSelect = document.getElementById("formClass");
      fillOptions(classSelect, classesForLevel((document.getElementById("formLevel") || {}).value), "Select a class", [
        { value: "new", label: "New class…" }
      ]);
      syncArmOptions();
    };

    const syncArmOptions = function () {
      const classSelect = document.getElementById("formClass");
      const armSelect = document.getElementById("formArm");
      const classId = classSelect ? classSelect.value : "";
      if (newClassFields) newClassFields.hidden = classId !== "new";
      const klass = classesForLevel((document.getElementById("formLevel") || {}).value)
        .find(function (row) { return String(row.id) === String(classId); });
      const sections = (klass && klass.sections) || [];
      fillOptions(armSelect, sections, "Select an arm", [
        { value: "new", label: "New arm…" }
      ]);
      if (classId === "new" && armSelect) armSelect.value = "new";
      if (newArmField) newArmField.hidden = (armSelect && armSelect.value) !== "new";
    };

    const visibleRows = function () {
      const query = search ? search.value.trim().toLowerCase() : "";
      const levelId = levelFilter ? Number(levelFilter.value) : 0;
      const sessionId = sessionFilter ? Number(sessionFilter.value) : 0;
      const status = statusFilter ? statusFilter.value : "";

      return offerings.filter(function (row) {
        const level = levelOf(row);
        const hay = [formName(row), level.name, row.class_teacher, (row.campus && row.campus.name), subjectNames(row)].join(" ").toLowerCase();
        if (query && hay.indexOf(query) === -1) return false;
        if (levelId && Number(level.id) !== levelId) return false;
        if (sessionId && Number(row.academic_session_id) !== sessionId) return false;
        if (status === "active" && !row.is_active) return false;
        if (status === "inactive" && row.is_active) return false;
        return true;
      }).sort(function (a, b) {
        const left = levelOf(a).name + " " + formName(a);
        const right = levelOf(b).name + " " + formName(b);
        return left.localeCompare(right);
      });
    };

    const apply = function () {
      const rows = visibleRows();
      const lastPage = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
      if (page > lastPage) page = lastPage;
      const start = (page - 1) * PAGE_SIZE;
      const slice = rows.slice(start, start + PAGE_SIZE);
      const session = sessions.find(function (row) { return String(row.id) === (sessionFilter && sessionFilter.value); });
      const campus = campuses.find(function (row) { return row.is_active; }) || campuses[0];

      if (copy) {
        copy.textContent = [
          session ? session.name : (rows.length + " form" + (rows.length === 1 ? "" : "s") + " on the books"),
          campus && campus.name ? campus.name + " campus" : ""
        ].filter(Boolean).join(" · ") || "No forms on the books yet";
      }

      const withTeacher = rows.filter(function (row) { return row.class_teacher; }).length;
      const roll = rows.reduce(function (sum, row) { return sum + Number(row.enrollment_count || 0); }, 0);
      const average = rows.length ? Math.round(roll / rows.length) : 0;
      setMetricValue("forms", String(rows.filter(function (row) { return row.is_active; }).length));
      setMetricDelta("forms", sessionFilter && sessionFilter.value ? "This session" : "On the books");
      setMetricValue("teachers", String(withTeacher));
      setMetricValue("average", rows.length ? String(average) : "—");
      setMetricValue("levels", String(levels.length));
      setMetricDelta("levels", levels.map(function (level) { return level.name; }).join(" · ") || "No levels sealed");

      if (!rows.length) {
        table.innerHTML = '<tr><td colspan="7">No forms match the ledger filters.</td></tr>';
      } else {
        table.innerHTML = slice.map(function (row) {
          const section = row.class_section || {};
          const schoolClass = section.school_class || {};
          const level = levelOf(row);
          const name = formName(row);
          const offered = subjectNames(row);
          const closeLabel = row.is_active ? "Close" : "Reopen";
          const closeAttr = row.is_active ? "data-close-form" : "data-reopen-form";
          return "<tr>"
            + '<td><div class="person"><span class="mark">' + escapeHtml(mark(schoolClass.short_code || name)) + "</span><div><strong>"
            + escapeHtml(name) + "</strong><small>" + escapeHtml([level.name || "", offered].filter(Boolean).join(" · ")) + "</small></div></div></td>"
            + "<td>" + escapeHtml(level.slug ? level.slug.toUpperCase() : (level.name || "—")) + "</td>"
            + "<td>" + escapeHtml(row.class_teacher || "—") + "</td>"
            + "<td>" + escapeHtml((row.enrollment_count ?? 0) + " / " + (row.capacity || "—")) + "</td>"
            + "<td>" + escapeHtml((row.campus && row.campus.name) || "—") + "</td>"
            + "<td><span class=\"badge " + (row.is_active ? "ok" : "warn") + "\">"
            + (row.is_active ? "Active" : "Inactive") + "</span></td>"
            + '<td><div class="row-actions">'
            + '<button class="ghost-btn" type="button" data-subjects-form="' + row.id
            + '" data-name="' + escapeHtml(name) + '">Subjects</button>'
            + '<button class="ghost-btn" type="button" data-appoint-form="' + row.id
            + '" data-name="' + escapeHtml(name) + '">Appoint</button>'
            + '<button class="ghost-btn" type="button" ' + closeAttr + '="' + row.id
            + '" data-name="' + escapeHtml(name) + '">' + closeLabel + "</button>"
            + '<button class="ghost-btn" type="button" data-delete-form="' + row.id
            + '" data-name="' + escapeHtml(name) + '">Remove</button></div></td>'
            + "</tr>";
        }).join("");
      }

      if (pager) {
        if (!rows.length) pager.textContent = "Showing 0 of 0";
        else pager.textContent = "Showing " + (start + 1) + "–" + (start + slice.length) + " of " + rows.length;
      }
      if (pages) {
        let html = "";
        if (lastPage > 1) {
          html += '<button type="button" data-form-page="' + Math.max(1, page - 1) + '"' + (page === 1 ? " disabled" : "") + ">Prev</button>";
          for (let i = 1; i <= lastPage; i += 1) {
            html += '<button type="button" data-form-page="' + i + '"' + (i === page ? ' class="current"' : "") + ">" + i + "</button>";
          }
          html += '<button type="button" data-form-page="' + Math.min(lastPage, page + 1) + '"' + (page === lastPage ? " disabled" : "") + ">Next</button>";
        }
        pages.innerHTML = html;
      }
    };

    const load = function () {
      return Promise.all([
        request("/api/v1/class-section-offerings"),
        request("/api/v1/levels"),
        request("/api/v1/academic-sessions"),
        request("/api/v1/campuses"),
        request("/api/v1/staff"),
        request("/api/v1/school-settings"),
        request("/api/v1/subjects"),
        request("/api/v1/departments")
      ]).then(function (results) {
        if (!results[0].ok) {
          table.innerHTML = "<tr><td colspan=\"7\">" + escapeHtml(firstError(results[0].body)) + "</td></tr>";
          return;
        }

        offerings = results[0].body.data || [];
        levels = (results[1].ok && results[1].body.data) || [];
        sessions = (results[2].ok && results[2].body.data) || [];
        campuses = (results[3].ok && results[3].body.data) || [];
        staff = (results[4].ok && results[4].body.data) || [];
        const settings = (results[5].ok && results[5].body.data) || {};
        subjects = (results[6].ok && results[6].body.data) || [];
        departments = (results[7].ok && results[7].body.data) || [];

        fillOptions(levelFilter, levels, "All levels");
        fillOptions(sessionFilter, sessions.map(function (row) {
          return { id: row.id, name: row.name + " Session" };
        }), "All sessions");
        fillOptions(document.getElementById("formSession"), sessions, "Select session");
        fillOptions(document.getElementById("formCampus"), campuses, "Select campus");
        fillOptions(document.getElementById("formLevel"), levels, "Select level");
        fillStaffSelects();
        fillOptions(document.getElementById("subjectDepartment"), departments, "No department");

        const currentSessionId = settings.current_academic_session_id
          || (sessions.find(function (row) { return row.status === "active"; }) || {}).id;
        if (currentSessionId) {
          if (sessionFilter && !sessionFilter.value) sessionFilter.value = String(currentSessionId);
          if (document.getElementById("formSession") && !document.getElementById("formSession").value) {
            document.getElementById("formSession").value = String(currentSessionId);
          }
        }
        const campus = campuses.find(function (row) { return row.is_active; }) || campuses[0];
        if (campus && document.getElementById("formCampus") && !document.getElementById("formCampus").value) {
          document.getElementById("formCampus").value = String(campus.id);
        }

        apply();
        renderSubjects();
      });
    };

    [search, levelFilter, sessionFilter, statusFilter].forEach(function (node) {
      if (!node) return;
      node.addEventListener("input", function () { page = 1; apply(); });
      node.addEventListener("change", function () { page = 1; apply(); });
    });

    if (pages) {
      pages.addEventListener("click", function (event) {
        const button = event.target.closest("[data-form-page]");
        if (!button || button.disabled) return;
        page = Number(button.getAttribute("data-form-page")) || 1;
        apply();
      });
    }

    const formLevel = document.getElementById("formLevel");
    const formClass = document.getElementById("formClass");
    const formArm = document.getElementById("formArm");
    if (formLevel) formLevel.addEventListener("change", syncClassOptions);
    if (formClass) formClass.addEventListener("change", syncArmOptions);
    if (formArm) formArm.addEventListener("change", function () {
      if (newArmField) newArmField.hidden = formArm.value !== "new";
    });

    root.addEventListener("click", function (event) {
      const button = event.target.closest("[data-delete-form], [data-close-form], [data-reopen-form], [data-appoint-form]");
      if (!button) return;
      const name = button.getAttribute("data-name") || "this form";
      const id = button.getAttribute("data-delete-form")
        || button.getAttribute("data-close-form")
        || button.getAttribute("data-reopen-form")
        || button.getAttribute("data-appoint-form");
      if (!id) return;

      if (button.hasAttribute("data-appoint-form")) {
        confirmDesk({
          title: "Appoint a master",
          copy: "Choose who keeps " + name + ".",
          confirmLabel: "Appoint",
          cancelLabel: "Leave unset",
          assign: true
        }).then(function (staffId) {
          if (!staffId) return;
          if (notice) notice.textContent = "";
          request("/api/v1/class-teacher-assignments", {
            method: "POST",
            body: JSON.stringify({
              staff_profile_id: Number(staffId),
              class_section_offering_id: Number(id)
            })
          }).then(function (result) {
            if (!result.ok) {
              if (notice) notice.textContent = firstError(result.body);
              return;
            }
            return load();
          });
        });
        return;
      }

      let path = "/api/v1/class-section-offerings/" + id;
      let method = "DELETE";
      let payload = null;
      let alertOptions = {
        title: "Remove this form",
        copy: name + " will leave the live structure. It can only be removed if no pupils or subjects remain.",
        confirmLabel: "Remove form",
        cancelLabel: "Keep it",
        danger: true
      };

      if (button.hasAttribute("data-close-form")) {
        method = "PUT";
        payload = { is_active: false };
        alertOptions = {
          title: "Close this form",
          copy: name + " will leave the live session. The roll stays sealed on the ledger.",
          confirmLabel: "Close form",
          cancelLabel: "Leave it open",
          danger: true
        };
      } else if (button.hasAttribute("data-reopen-form")) {
        method = "PUT";
        payload = { is_active: true };
        alertOptions = {
          title: "Reopen this form",
          copy: name + " will return to the live session.",
          confirmLabel: "Reopen form",
          cancelLabel: "Leave it closed",
          danger: false
        };
      }

      confirmDesk(alertOptions).then(function (ok) {
        if (!ok) return;
        if (notice) notice.textContent = "";
        button.disabled = true;
        const options = { method: method };
        if (payload) options.body = JSON.stringify(payload);
        request(path, options).then(function (result) {
          if (!result.ok) {
            if (notice) notice.textContent = firstError(result.body);
            button.disabled = false;
            return;
          }
          return load();
        }).catch(function () {
          if (notice) notice.textContent = "Unable to reach the office.";
          button.disabled = false;
        });
      });
    });

    if (form) {
      const button = form.querySelector(".solid-btn");
      form.addEventListener("submit", function (event) {
        event.preventDefault();
        const sessionId = Number((document.getElementById("formSession") || {}).value);
        const campusId = Number((document.getElementById("formCampus") || {}).value);
        const levelId = Number((document.getElementById("formLevel") || {}).value);
        const classChoice = (document.getElementById("formClass") || {}).value || "";
        const armChoice = (document.getElementById("formArm") || {}).value || "";
        const capacity = Number((document.getElementById("formCapacity") || {}).value) || 30;
        const teacherId = Number((document.getElementById("formTeacher") || {}).value) || 0;

        if (formNotice) formNotice.textContent = "";
        setButtonState(button, true, "Sealing…");

        const ensureClass = function () {
          if (classChoice && classChoice !== "new") {
            return Promise.resolve(Number(classChoice));
          }
          return request("/api/v1/classes", {
            method: "POST",
            body: JSON.stringify({
              level_id: levelId,
              name: (document.getElementById("formClassName") || {}).value || "",
              short_code: (document.getElementById("formClassCode") || {}).value || ""
            })
          }).then(function (result) {
            if (!result.ok) throw result;
            return result.body.data.id;
          });
        };

        const ensureArm = function (classId) {
          if (armChoice && armChoice !== "new") {
            return Promise.resolve(Number(armChoice));
          }
          return request("/api/v1/classes/" + classId + "/sections", {
            method: "POST",
            body: JSON.stringify({
              arm: (document.getElementById("formArmName") || {}).value || ""
            })
          }).then(function (result) {
            if (!result.ok) throw result;
            return result.body.data.id;
          });
        };

        ensureClass().then(function (classId) {
          return ensureArm(classId);
        }).then(function (sectionId) {
          const payload = {
            class_section_id: sectionId,
            academic_session_id: sessionId,
            campus_id: campusId,
            capacity: capacity
          };
          if (teacherId) payload.staff_profile_id = teacherId;
          return request("/api/v1/class-section-offerings", {
            method: "POST",
            body: JSON.stringify(payload)
          });
        }).then(function (result) {
          if (!result.ok) throw result;
          selectedOfferingId = (result.body.data && result.body.data.id) || selectedOfferingId;
          form.reset();
          if (document.getElementById("formCapacity")) document.getElementById("formCapacity").value = "30";
          if (newClassFields) newClassFields.hidden = true;
          if (newArmField) newArmField.hidden = true;
          setButtonState(button, false, "Sealed.");
          window.setTimeout(function () { setButtonState(button, false); }, 1600);
          return load();
        }).catch(function (result) {
          const message = result && result.body ? firstError(result.body) : "Unable to reach the office.";
          if (formNotice) formNotice.textContent = message;
          setButtonState(button, false, message);
          window.setTimeout(function () { setButtonState(button, false); }, 2200);
        });
      });
    }

    const desk = document.querySelector("[data-page='classes']") || document;
    desk.addEventListener("click", function (event) {
      const subjectsBtn = event.target.closest("[data-subjects-form]");
      if (subjectsBtn) {
        selectedOfferingId = Number(subjectsBtn.getAttribute("data-subjects-form")) || 0;
        if (subjectNotice) subjectNotice.textContent = "";
        renderSubjects();
        const panel = document.getElementById("seal-subject");
        if (panel && typeof panel.scrollIntoView === "function") {
          panel.scrollIntoView({ behavior: "smooth", block: "nearest" });
        }
        return;
      }

      const offerAdd = event.target.closest("[data-subject-offer-add]");
      if (offerAdd) {
        const pick = document.getElementById("subjectOfferPick");
        const subjectId = Number(pick && pick.value);
        if (!selectedOfferingId || !subjectId) {
          if (subjectNotice) subjectNotice.textContent = "Choose a form and a subject first.";
          return;
        }
        if (subjectNotice) subjectNotice.textContent = "";
        request("/api/v1/subject-offerings", {
          method: "POST",
          body: JSON.stringify({
            class_section_offering_id: Number(selectedOfferingId),
            subject_id: subjectId
          })
        }).then(function (result) {
          if (!result.ok) {
            if (subjectNotice) subjectNotice.textContent = firstError(result.body);
            return;
          }
          return load();
        });
        return;
      }

      const unoffer = event.target.closest("[data-unoffer-subject]");
      if (unoffer) {
        const id = unoffer.getAttribute("data-unoffer-subject");
        const name = unoffer.getAttribute("data-name") || "this subject";
        if (!id) return;
        confirmDesk({
          title: "Remove from this form",
          copy: name + " will leave this form. The catalogue subject remains.",
          confirmLabel: "Remove from form",
          cancelLabel: "Keep it",
          danger: true
        }).then(function (ok) {
          if (!ok) return;
          if (subjectNotice) subjectNotice.textContent = "";
          request("/api/v1/subject-offerings/" + id, { method: "DELETE" }).then(function (result) {
            if (!result.ok) {
              if (subjectNotice) subjectNotice.textContent = firstError(result.body);
              return;
            }
            return load();
          });
        });
        return;
      }

      const del = event.target.closest("[data-delete-subject]");
      if (del) {
        const id = del.getAttribute("data-delete-subject");
        const name = del.getAttribute("data-name") || "this subject";
        if (!id) return;
        confirmDesk({
          title: "Remove this subject",
          copy: name + " will leave the catalogue. It can only be removed if no form still offers it.",
          confirmLabel: "Remove subject",
          cancelLabel: "Keep it",
          danger: true
        }).then(function (ok) {
          if (!ok) return;
          if (subjectNotice) subjectNotice.textContent = "";
          request("/api/v1/subjects/" + id, { method: "DELETE" }).then(function (result) {
            if (!result.ok) {
              if (subjectNotice) subjectNotice.textContent = firstError(result.body);
              return;
            }
            return load();
          });
        });
      }
    });

    if (subjectForm) {
      const button = subjectForm.querySelector(".solid-btn");
      subjectForm.addEventListener("submit", function (event) {
        event.preventDefault();
        const payload = {
          name: ((document.getElementById("subjectName") || {}).value || "").trim(),
          code: ((document.getElementById("subjectCode") || {}).value || "").trim(),
          department_id: Number((document.getElementById("subjectDepartment") || {}).value) || 0
        };
        if (!payload.code) delete payload.code;
        if (!payload.department_id) delete payload.department_id;

        if (subjectFormNotice) subjectFormNotice.textContent = "";
        setButtonState(button, true, "Sealing…");
        request("/api/v1/subjects", {
          method: "POST",
          body: JSON.stringify(payload)
        }).then(function (result) {
          if (!result.ok) throw result;
          const id = result.body.data && result.body.data.id;
          if (!selectedOfferingId || !id) return result;
          return request("/api/v1/subject-offerings", {
            method: "POST",
            body: JSON.stringify({
              class_section_offering_id: Number(selectedOfferingId),
              subject_id: id
            })
          }).then(function (offered) {
            if (!offered.ok) throw offered;
            return offered;
          });
        }).then(function () {
          subjectForm.reset();
          setButtonState(button, false, "Sealed.");
          window.setTimeout(function () { setButtonState(button, false); }, 1600);
          return load();
        }).catch(function (result) {
          const message = result && result.body ? firstError(result.body) : "Unable to reach the office.";
          if (subjectFormNotice) subjectFormNotice.textContent = message;
          setButtonState(button, false, message);
          window.setTimeout(function () { setButtonState(button, false); }, 2200);
        });
      });
    }

    load();
  };

  wireSettings();
  wireDesks();
  wireAccount();
  wirePassword();
  wireSessions();
  wireClasses();
})();
