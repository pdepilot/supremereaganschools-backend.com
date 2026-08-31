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

    if (options && options.body && !headers["Content-Type"] && typeof options.body === "string") {
      headers["Content-Type"] = "application/json";
    }

    return fetch(url, Object.assign({
      credentials: "same-origin"
    }, options, { headers })).then(function (response) {
      if (response.status === 401) {
          window.location.replace((window.srsLoginPath && window.srsLoginPath()) || "/portal/login");
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
    if (!body) return "The office could not complete that request.";
    if (body.errors) {
      const keys = Object.keys(body.errors);
      if (keys.length) return body.errors[keys[0]][0];
    }
    return body.message || "The office could not complete that request.";
  };

  const escapeHtml = function (value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  };

  const setButtonState = function (button, busy, label) {
    if (!button) return;
    if (!button.dataset.label) button.dataset.label = button.textContent;
    button.disabled = !!busy;
    button.classList.toggle("is-busy", !!busy);
    if (label) button.textContent = label;
    else if (!busy) button.textContent = button.dataset.label;
  };

  const confirmDesk = function (options) {
    const root = document.querySelector("[data-desk-alert]");
    if (!root) return Promise.resolve(false);

    const card = root.querySelector(".desk-alert-card");
    const title = root.querySelector("[data-desk-alert-title]");
    const copy = root.querySelector("[data-desk-alert-copy]");
    const confirmBtn = root.querySelector("[data-desk-alert-confirm]");
    const cancelBtn = root.querySelector(".desk-alert-actions [data-desk-alert-dismiss]");
    const danger = !!(options && options.danger);
    const previous = document.activeElement;

    if (title) title.textContent = (options && options.title) || "Seal this action?";
    if (copy) copy.textContent = (options && options.copy) || "";
    if (confirmBtn) {
      confirmBtn.textContent = (options && options.confirmLabel) || "Confirm";
      confirmBtn.classList.toggle("is-danger", danger);
    }
    if (cancelBtn) cancelBtn.textContent = (options && options.cancelLabel) || "Keep it";
    if (card) card.classList.toggle("is-danger", danger);

    return new Promise(function (resolve) {
      let settled = false;
      const finish = function (ok) {
        if (settled) return;
        settled = true;
        root.hidden = true;
        document.body.classList.remove("desk-alert-open");
        document.removeEventListener("keydown", onKey);
        root.removeEventListener("click", onClick);
        if (previous && typeof previous.focus === "function") previous.focus();
        resolve(ok);
      };
      const onKey = function (event) {
        if (event.key === "Escape") finish(false);
      };
      const onClick = function (event) {
        if (event.target.closest("[data-desk-alert-confirm]")) {
          event.preventDefault();
          finish(true);
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
      const focusTarget = danger ? cancelBtn : confirmBtn;
      if (focusTarget) focusTarget.focus();
    });
  };

  const initials = function (value) {
    if (!value) return "—";
    const parts = String(value).trim().split(/\s+/);
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
  };

  const fillSelect = function (select, rows, labelKey, valueKey, placeholder) {
    if (!select) return;
    const current = select.value;
    select.innerHTML = "";
    if (placeholder) {
      const option = document.createElement("option");
      option.value = "";
      option.textContent = placeholder;
      select.appendChild(option);
    }
    (rows || []).forEach(function (row) {
      const option = document.createElement("option");
      option.value = String(row[valueKey]);
      option.textContent = row[labelKey];
      select.appendChild(option);
    });
    if (current && Array.from(select.options).some(function (option) { return option.value === current; })) {
      select.value = current;
    }
  };

  const setBadge = function (count) {
    document.querySelectorAll(".notification-badge").forEach(function (badge) {
      badge.textContent = count > 0 ? String(count) : "";
      badge.style.display = count > 0 ? "" : "none";
    });
    document.querySelectorAll(".notification-dot").forEach(function (dot) {
      dot.style.display = count > 0 ? "" : "none";
    });
  };

  const loadNotifications = function () {
    request("/api/v1/notifications").then(function (result) {
      if (!result.ok) return;
      setBadge((result.body.data && result.body.data.unread_count) || 0);
    });
  };

  const loadContext = function () {
    return request("/api/v1/classroom/context").then(function (result) {
      return result.ok ? result.body.data : { offerings: [], children: [] };
    });
  };

  const audienceLabel = function (value) {
    const map = {
      whole_school: "Whole school",
      parents: "Parents",
      staff: "Staff",
      students: "Students",
      secondary: "Secondary",
      teaching_staff: "Teaching staff",
      non_teaching_staff: "Non-teaching staff",
      department: "Department"
    };
    return map[value] || value || "";
  };

  const ANNOUNCEMENT_POLL_MS = 8000;

  const categoryLabel = function (value) {
    const map = { academic: "Academic", event: "Event", general: "General", urgent: "Urgent" };
    return map[value] || value || "General";
  };

  const noticeStatusBadge = function (row) {
    if (row.status === "draft") return { className: "warn", label: "Draft" };
    if (row.status === "archived") return { className: "low", label: "Archived" };
    if (row.category === "urgent") return { className: "warn", label: "Urgent" };
    return { className: "ok", label: "Live" };
  };

  const clockFromIso = function (iso) {
    if (!iso) return "—";
    const date = new Date(iso);
    if (!Number.isFinite(date.getTime())) return "—";
    return date.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
  };

  const truncateText = function (value, length) {
    const text = String(value || "").trim();
    if (text.length <= length) return text || "—";
    return text.slice(0, length).trim() + "…";
  };

  const announcementFingerprint = function (rows) {
    return JSON.stringify((rows || []).map(function (row) {
      return [row.id, row.title, row.body, row.status, row.category, row.audience, row.department_id, row.published_at, row.created_at];
    }));
  };

  const renderAnnouncements = function (rows, selectedId) {
    const adminBoard = document.querySelector("[data-notice-board]");
    if (adminBoard) {
      adminBoard.innerHTML = rows.length ? rows.map(function (row) {
        const badge = noticeStatusBadge(row);
        const active = Number(row.id) === Number(selectedId);
        const audience = row.department_name || audienceLabel(row.audience);
        const excerpt = escapeHtml((row.body || "").slice(0, 90));
        let actions = '<button type="button" class="ghost-btn" data-notice-edit>Revise</button>';
        if (row.status === "published") {
          actions += '<button type="button" class="ghost-btn" data-notice-archive>Archive</button>';
        }
        actions += '<button type="button" class="ghost-btn" data-notice-remove>Remove</button>';
        return '<article class="ticket' + (active ? " is-active" : "") + '" data-notice-id="'
          + escapeHtml(row.id) + '"><div class="ticket-code">NOTE-'
          + String(row.id).padStart(3, "0") + "</div><div><h3>"
          + escapeHtml(row.title) + "</h3><p>" + escapeHtml(audience) + " · "
          + escapeHtml(categoryLabel(row.category)) + (excerpt ? " · " + excerpt : "")
          + '</p></div><span class="badge ' + badge.className + '">'
          + escapeHtml(badge.label) + '</span><div class="row-actions">' + actions + "</div></article>";
      }).join("") : "<p>No circulars on the board.</p>";
    }

    const grid = document.getElementById("announcementGrid");
    if (grid) {
      grid.innerHTML = rows.length ? rows.map(function (row) {
        return '<article class="announcement-card" data-title="' + escapeHtml(row.title)
          + '" data-category="' + escapeHtml(row.category || "general") + '"><div class="announcement-card-top"><div><h3>'
          + escapeHtml(row.title) + "</h3><p>" + escapeHtml(audienceLabel(row.audience))
          + "</p></div></div><p>" + escapeHtml(row.body) + "</p></article>";
      }).join("") : "<p>No announcements yet.</p>";
    }

    const list = document.querySelector(".announcement-list");
    if (list) {
      list.innerHTML = rows.length ? rows.map(function (row) {
        return '<article class="announcement-card"><div class="announcement-content"><div class="announcement-top"><span class="announcement-category">'
          + escapeHtml((row.category || "general").toUpperCase()) + '</span></div><h2>'
          + escapeHtml(row.title) + "</h2><p>" + escapeHtml(row.body) + "</p></div></article>";
      }).join("") : "<p>No announcements yet.</p>";
    }
  };

  const days = ["", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday"];

  const clockLabel = function (value) {
    if (!value) return "—";
    return String(value).replace(/^0/, "");
  };

  const addMinutes = function (hhmm, minutes) {
    const parts = String(hhmm || "08:00").split(":");
    const date = new Date(2000, 0, 1, Number(parts[0]) || 0, Number(parts[1]) || 0);
    date.setMinutes(date.getMinutes() + minutes);
    const hour = String(date.getHours()).padStart(2, "0");
    const min = String(date.getMinutes()).padStart(2, "0");
    return hour + ":" + min;
  };

  const renderTimetable = function (payload) {
    const slots = (payload && payload.slots) || [];
    const times = [];
    slots.forEach(function (slot) {
      if (times.indexOf(slot.starts_at) === -1) times.push(slot.starts_at);
    });
    times.sort();
    const editable = !!(payload && payload.can_edit && document.querySelector("[data-bell-form]"));

    const adminGrid = document.querySelector(".time-grid");
    if (adminGrid) {
      let html = '<div class="slot"></div><div class="day">Mon</div><div class="day">Tue</div><div class="day">Wed</div><div class="day">Thu</div><div class="day">Fri</div>';
      if (!times.length) {
        html += '<div class="slot"></div><div class="lesson" style="grid-column: span 5"><strong>No lessons yet</strong><span>Set a period on the right to ring the first bell.</span></div>';
      }
      times.forEach(function (time) {
        html += '<div class="slot">' + escapeHtml(clockLabel(time)) + "</div>";
        for (let day = 1; day <= 5; day += 1) {
          const hit = slots.find(function (slot) { return Number(slot.day_of_week) === day && slot.starts_at === time; });
          const title = hit ? (hit.subject_name || hit.label || "—") : (editable ? "Set" : "");
          const staff = hit ? (hit.staff_name || "") : "";
          html += '<div class="lesson' + (hit ? "" : " is-empty") + '" data-day="' + day
            + '" data-time="' + escapeHtml(time) + '"'
            + (hit ? ' data-slot-id="' + hit.id + '"' : "") + "><strong>"
            + escapeHtml(title) + "</strong><span>" + escapeHtml(staff) + "</span></div>";
        }
      });
      adminGrid.innerHTML = html;
      const heading = document.querySelector("[data-bell-title]")
        || (adminGrid.closest(".surface") && adminGrid.closest(".surface").querySelector("h2"));
      if (heading && payload.form) {
        heading.textContent = payload.form + (payload.session_name ? " · " + payload.session_name : "");
      }
      const copy = document.querySelector("[data-bell-copy]");
      if (copy) {
        copy.textContent = [payload.class_teacher, "Monday to Friday"].filter(Boolean).join(" · ") || "Monday to Friday";
      }
    }

    const body = document.getElementById("timetableBody") || document.querySelector(".timetable-table tbody");
    if (body) {
      if (!times.length) {
        body.innerHTML = '<tr><td colspan="6">No lessons on this timetable yet.</td></tr>';
      } else {
        body.innerHTML = times.map(function (time) {
          let row = "<tr><td>" + escapeHtml(time) + "</td>";
          for (let day = 1; day <= 5; day += 1) {
            const hit = slots.find(function (slot) { return slot.day_of_week === day && slot.starts_at === time; });
            row += "<td>" + escapeHtml(hit ? (hit.subject_name || hit.label || "") : "") + "</td>";
          }
          return row + "</tr>";
        }).join("");
      }
    }
  };

  const renderAssignments = function (rows) {
    const tbody = document.querySelector(".assignments-table tbody");
    if (tbody) {
      tbody.innerHTML = rows.length ? rows.map(function (row) {
        const handed = (row.submitted_count == null ? 0 : row.submitted_count) + " / " + (row.on_roll == null ? "—" : row.on_roll);
        return "<tr data-status=\"" + escapeHtml(row.status || "") + "\" data-assignment-id=\"" + escapeHtml(row.id)
          + "\" data-title=\"" + escapeHtml(row.title || "Assignment") + "\"><td>" + escapeHtml(row.title) + "</td><td>"
          + escapeHtml(row.subject_name || "") + "</td><td>" + escapeHtml(row.form || "") + "</td><td>"
          + escapeHtml(row.due_on || "") + "</td><td>" + escapeHtml(handed) + "</td><td>"
          + escapeHtml(row.status || "—") + "</td></tr>";
      }).join("") : "<tr><td colspan=\"6\">No assignments yet.</td></tr>";
    }

    const list = document.querySelector(".assignments-list");
    if (list) {
      list.innerHTML = rows.length ? rows.map(function (row) {
        return '<div class="assignment-card"><div class="assignment-details"><div class="assignment-title-row"><div><span class="assignment-subject">'
          + escapeHtml(row.subject_name || "") + "</span><h3>" + escapeHtml(row.title)
          + "</h3></div><span>" + escapeHtml(row.status) + "</span></div><p>"
          + escapeHtml(row.instructions || "") + "</p><small>Due " + escapeHtml(row.due_on || "")
          + "</small></div></div>";
      }).join("") : "<p>No assignments for this pupil.</p>";
    }
  };

  const openAssignmentInbox = function (assignmentId, title) {
    const panel = document.querySelector("[data-submission-inbox]");
    const heading = document.querySelector("[data-inbox-title]");
    const body = document.querySelector("[data-inbox-body]");
    if (!panel || !body) return;
    panel.hidden = false;
    if (heading) heading.textContent = title || "Handed in";
    body.innerHTML = "<tr><td colspan=\"5\">Opening the class papers…</td></tr>";
    document.querySelectorAll(".assignments-table tbody tr[data-assignment-id]").forEach(function (row) {
      row.classList.toggle("is-open", String(row.getAttribute("data-assignment-id")) === String(assignmentId));
    });
    request("/api/v1/assignments/" + assignmentId + "/submissions").then(function (result) {
      if (!result.ok) {
        body.innerHTML = "<tr><td colspan=\"5\">" + escapeHtml(firstError(result.body)) + "</td></tr>";
        return;
      }
      const rows = result.body.data || [];
      if (!rows.length) {
        body.innerHTML = "<tr><td colspan=\"5\">No pupils are on this roll.</td></tr>";
        return;
      }
      body.innerHTML = rows.map(function (row) {
        const status = row.submitted ? (row.late ? "Late" : "Handed in") : "Missing";
        const paper = row.download_url
          ? '<a href="' + escapeHtml(row.download_url) + '">' + escapeHtml(row.original_name || "Download") + "</a>"
          : "—";
        return "<tr><td>" + escapeHtml(row.student_name || "Pupil") + "</td><td>"
          + escapeHtml(row.admission_number || "—") + "</td><td>" + escapeHtml(status) + "</td><td>"
          + paper + "</td><td>" + escapeHtml(row.notes || "—") + "</td></tr>";
      }).join("");
    });
  };

  const renderMaterials = function (rows) {
    const grids = document.querySelectorAll(".materials-grid");
    grids.forEach(function (grid) {
      grid.innerHTML = rows.length ? rows.map(function (row) {
        const doc = row.document || {};
        const href = doc.id ? "/api/v1/documents/" + doc.id + "/download" : "#";
        return '<article class="material-card"><div class="material-card-body"><h3>'
          + escapeHtml(row.title) + "</h3><p>" + escapeHtml(row.subject_name || "")
          + " · " + escapeHtml(row.form || "") + '</p><a href="' + href + '">Download</a></div></article>';
      }).join("") : "<p>No materials yet.</p>";
    });
  };

  const renderConversations = function (rows, activeId) {
    const list = document.getElementById("conversationList") || document.querySelector(".message-list");
    if (!list) return;
    const items = rows.length ? rows.map(function (row) {
      const klass = "conversation" + (Number(row.id) === Number(activeId) ? " active" : "") + (row.unread_count ? " unread" : "");
      return '<div class="' + klass + '" data-id="' + row.id + '"><div class="conversation-avatar">'
        + escapeHtml(initials(row.other_name)) + '</div><div class="conversation-details"><strong>'
        + escapeHtml(row.other_name || "Conversation") + "</strong><h3>" + escapeHtml(row.subject)
        + "</h3><p>" + escapeHtml((row.preview || "").slice(0, 80)) + "</p></div></div>";
    }).join("") : "<p>No conversations yet.</p>";

    if (list.id === "conversationList") {
      list.innerHTML = items;
    } else {
      const header = list.querySelector(".message-list-header");
      list.innerHTML = (header ? header.outerHTML : "") + items;
    }
  };

  const renderChat = function (conversation) {
    const body = document.getElementById("chatBody") || document.querySelector(".chat-body, .message-thread");
    if (!body) return;
    const messages = conversation.messages || [];
    body.innerHTML = messages.length ? messages.map(function (message) {
      const mine = document.body.getAttribute("data-user-name") === message.sender_name;
      return '<div class="message ' + (mine ? "sent" : "received") + '"><div><strong>'
        + escapeHtml(message.sender_name || "") + "</strong><p>" + escapeHtml(message.body)
        + "</p></div></div>";
    }).join("") : "<p>Select a conversation.</p>";
  };

  const wireAnnouncements = function () {
    const board = document.querySelector("[data-notice-board]");
    const form = document.querySelector("[data-notice-form]");
    const staffForm = document.getElementById("announcementForm");
    const needsList = board || document.getElementById("announcementGrid") || document.querySelector(".announcement-list");
    if (!needsList && !form && !staffForm && !document.getElementById("noticeTitle")) return;

    let allRows = [];
    let fingerprint = "";
    let selectedId = 0;
    let pollTimer = 0;

    const formNotice = document.querySelector("[data-notice-form-notice]");
    const boardNotice = document.querySelector("[data-notice-notice]");
    const boardCopy = document.querySelector("[data-notice-copy]");
    const formTitle = document.querySelector("[data-notice-form-title]");
    const dispatchBtn = document.querySelector("[data-notice-dispatch]");
    const draftBtn = document.querySelector("[data-notice-draft]");
    const clearBtn = document.querySelector("[data-notice-clear]");
    const archiveBtn = form ? form.querySelector("[data-notice-archive]") : null;
    const removeBtn = form ? form.querySelector("[data-notice-remove]") : null;

    const setFormNotice = function (message) {
      if (formNotice) formNotice.textContent = message || "";
    };

    const setBoardNotice = function (message) {
      if (boardNotice) boardNotice.textContent = message || "";
    };

    const setMetricValue = function (key, value) {
      const node = document.querySelector('[data-metric="' + key + '"]');
      if (node) node.textContent = value == null || value === "" ? "—" : String(value);
    };

    const setMetricDelta = function (key, value) {
      const node = document.querySelector('[data-metric-delta="' + key + '"]');
      if (node) node.textContent = value || "";
    };

    const syncDepartmentField = function () {
      const wrap = document.querySelector("[data-notice-department]");
      const audience = document.getElementById("audience");
      if (wrap) wrap.hidden = !(audience && audience.value === "department");
    };

    const noticePayload = function (status) {
      const payload = {
        title: ((document.getElementById("noticeTitle") || {}).value || "").trim(),
        body: ((document.getElementById("body") || {}).value || "").trim(),
        category: (document.getElementById("noticeCategory") || {}).value || "general",
        audience: (document.getElementById("audience") || {}).value || "whole_school",
        status: status
      };
      if (payload.audience === "department") {
        payload.department_id = Number((document.getElementById("noticeDepartment") || {}).value) || null;
      }
      return payload;
    };

    const currentNotice = function () {
      return allRows.find(function (row) { return Number(row.id) === Number(selectedId); }) || null;
    };

    const syncFormChrome = function () {
      const row = currentNotice();
      if (formTitle) formTitle.textContent = row ? "Revise a circular" : "Compose a circular";
      if (dispatchBtn) {
        if (!row) dispatchBtn.textContent = "Dispatch notice";
        else if (row.status === "archived") dispatchBtn.textContent = "Dispatch again";
        else if (row.status === "published") dispatchBtn.textContent = "Revise notice";
        else dispatchBtn.textContent = "Dispatch notice";
        dispatchBtn.dataset.label = dispatchBtn.textContent;
      }
      if (draftBtn) draftBtn.hidden = !!(row && row.status === "published");
      if (clearBtn) clearBtn.hidden = !row && !((document.getElementById("noticeTitle") || {}).value);
      if (archiveBtn) archiveBtn.hidden = !(row && row.status === "published");
      if (removeBtn) removeBtn.hidden = !row;
      syncDepartmentField();
    };

    const clearForm = function () {
      selectedId = 0;
      if (form) form.reset();
      const idField = document.getElementById("noticeId");
      if (idField) idField.value = "";
      setFormNotice("");
      syncFormChrome();
      if (board) {
        board.querySelectorAll(".ticket.is-active").forEach(function (node) {
          node.classList.remove("is-active");
        });
      }
    };

    const fillForm = function (id) {
      const row = allRows.find(function (item) { return Number(item.id) === Number(id); });
      if (!row || !form) return;
      selectedId = Number(row.id);
      const idField = document.getElementById("noticeId");
      if (idField) idField.value = String(row.id);
      const title = document.getElementById("noticeTitle");
      const body = document.getElementById("body");
      const audience = document.getElementById("audience");
      const category = document.getElementById("noticeCategory");
      const department = document.getElementById("noticeDepartment");
      if (title) title.value = row.title || "";
      if (body) body.value = row.body || "";
      if (audience) audience.value = row.audience || "whole_school";
      if (category) category.value = row.category || "general";
      if (department && row.department_id) department.value = String(row.department_id);
      setFormNotice("");
      syncFormChrome();
      paintBoard();
      form.scrollIntoView({ behavior: "smooth", block: "nearest" });
    };

    const filteredRows = function () {
      const query = ((document.getElementById("noticeSearch") || {}).value || "").trim().toLowerCase();
      const status = (document.getElementById("noticeStatusFilter") || {}).value || "";
      const audience = (document.getElementById("noticeAudienceFilter") || {}).value || "";
      return allRows.filter(function (row) {
        if (status && row.status !== status) return false;
        if (audience && row.audience !== audience) return false;
        if (!query) return true;
        const haystack = [row.title, row.body, audienceLabel(row.audience), row.department_name, categoryLabel(row.category)]
          .join(" ").toLowerCase();
        return haystack.indexOf(query) !== -1;
      });
    };

    const paintMetrics = function () {
      const live = allRows.filter(function (row) { return row.status === "published"; });
      const drafts = allRows.filter(function (row) { return row.status === "draft"; });
      const archived = allRows.filter(function (row) { return row.status === "archived"; });
      const last = live[0] || allRows[0] || null;
      setMetricValue("live", live.length);
      setMetricValue("drafts", drafts.length);
      setMetricValue("archived", archived.length);
      setMetricDelta("live", live.length === 1 ? "On the board" : "On the board");
      setMetricDelta("drafts", drafts.length ? "Awaiting seal" : "None waiting");
      setMetricDelta("archived", archived.length ? "Off the board" : "None filed");
      if (last && last.published_at) {
        setMetricValue("last", clockFromIso(last.published_at));
        setMetricDelta("last", truncateText(last.title, 28));
      } else {
        setMetricValue("last", "—");
        setMetricDelta("last", "None yet");
      }
      if (boardCopy) {
        boardCopy.textContent = allRows.length
          ? "Live board · " + allRows.length + (allRows.length === 1 ? " circular" : " circulars")
          : "No circulars yet · compose one to the right";
      }
    };

    const paintBoard = function () {
      if (board) renderAnnouncements(filteredRows(), selectedId);
      else renderAnnouncements(allRows, selectedId);
    };

    const applyRows = function (rows, force) {
      const next = announcementFingerprint(rows);
      if (!force && next === fingerprint) return;
      fingerprint = next;
      allRows = rows;
      if (selectedId && !allRows.some(function (row) { return Number(row.id) === Number(selectedId); })) {
        clearForm();
        setFormNotice("That circular is no longer on the board.");
      }
      paintMetrics();
      paintBoard();
    };

    const refresh = function (force) {
      return request("/api/v1/announcements").then(function (result) {
        if (!result.ok) {
          if (boardCopy) boardCopy.textContent = "The office could not load the board.";
          setBoardNotice(firstError(result.body));
          return;
        }
        setBoardNotice("");
        applyRows(result.body.data || [], !!force);
      });
    };

    const saveNotice = function (status, button) {
      const payload = noticePayload(status);
      if (!payload.title || !payload.body) {
        setFormNotice("A title and body are required.");
        return Promise.resolve();
      }
      if (payload.audience === "department" && !payload.department_id) {
        setFormNotice("Choose the department this circular is for.");
        return Promise.resolve();
      }

      const editing = Number((document.getElementById("noticeId") || {}).value || selectedId || 0);
      setButtonState(button, true, "Sealing…");
      const url = editing ? "/api/v1/announcements/" + editing : "/api/v1/announcements";
      return request(url, {
        method: editing ? "PUT" : "POST",
        body: JSON.stringify(payload)
      }).then(function (result) {
        setButtonState(button, false);
        if (!result.ok) {
          setFormNotice(firstError(result.body));
          return;
        }
        clearForm();
        return refresh(true);
      }).catch(function () {
        setButtonState(button, false);
        setFormNotice("The office could not complete that request.");
      });
    };

    const archiveNotice = function (id) {
      const row = allRows.find(function (item) { return Number(item.id) === Number(id); });
      if (!row) return;
      confirmDesk({
        title: "Archive this circular?",
        copy: "“" + row.title + "” will leave the live board. Houses will no longer see it.",
        confirmLabel: "Archive it"
      }).then(function (ok) {
        if (!ok) return;
        request("/api/v1/announcements/" + id, {
          method: "PUT",
          body: JSON.stringify({ status: "archived" })
        }).then(function (result) {
          if (!result.ok) {
            setBoardNotice(firstError(result.body));
            return;
          }
          if (Number(selectedId) === Number(id)) clearForm();
          refresh(true);
        });
      });
    };

    const removeNotice = function (id) {
      const row = allRows.find(function (item) { return Number(item.id) === Number(id); });
      if (!row) return;
      confirmDesk({
        title: "Remove this circular?",
        copy: "“" + row.title + "” will be struck from the books. This cannot be undone.",
        confirmLabel: "Remove it",
        danger: true
      }).then(function (ok) {
        if (!ok) return;
        request("/api/v1/announcements/" + id, { method: "DELETE" }).then(function (result) {
          if (!result.ok) {
            setBoardNotice(firstError(result.body));
            return;
          }
          if (Number(selectedId) === Number(id)) clearForm();
          refresh(true);
        });
      });
    };

    const startLiveBoard = function () {
      if (pollTimer) window.clearInterval(pollTimer);
      pollTimer = window.setInterval(function () {
        if (document.hidden) return;
        refresh(false);
      }, ANNOUNCEMENT_POLL_MS);
      document.addEventListener("visibilitychange", function () {
        if (!document.hidden) refresh(false);
      });
      window.addEventListener("pageshow", function () {
        refresh(false);
      });
    };

    if (form) {
      form.addEventListener("submit", function (event) {
        event.preventDefault();
        saveNotice("published", dispatchBtn);
      });

      if (draftBtn) {
        draftBtn.addEventListener("click", function () {
          saveNotice("draft", draftBtn);
        });
      }
      if (clearBtn) {
        clearBtn.addEventListener("click", function () {
          clearForm();
        });
      }
      if (archiveBtn) {
        archiveBtn.addEventListener("click", function () {
          if (selectedId) archiveNotice(selectedId);
        });
      }
      if (removeBtn) {
        removeBtn.addEventListener("click", function () {
          if (selectedId) removeNotice(selectedId);
        });
      }

      const audience = document.getElementById("audience");
      if (audience) audience.addEventListener("change", syncDepartmentField);
      form.addEventListener("input", syncFormChrome);

      ["noticeSearch", "noticeStatusFilter", "noticeAudienceFilter"].forEach(function (id) {
        const node = document.getElementById(id);
        if (!node) return;
        node.addEventListener("input", paintBoard);
        node.addEventListener("change", paintBoard);
      });

      if (board) {
        board.addEventListener("click", function (event) {
          const ticket = event.target.closest("[data-notice-id]");
          if (!ticket) return;
          const id = Number(ticket.getAttribute("data-notice-id"));
          if (event.target.closest("[data-notice-remove]")) {
            event.preventDefault();
            removeNotice(id);
            return;
          }
          if (event.target.closest("[data-notice-archive]")) {
            event.preventDefault();
            archiveNotice(id);
            return;
          }
          fillForm(id);
        });
      }

      syncFormChrome();
    }

    if (staffForm) {
      staffForm.addEventListener("submit", function (event) {
        event.preventDefault();
        request("/api/v1/announcements", {
          method: "POST",
          body: JSON.stringify({
            title: document.getElementById("announcementTitle").value,
            category: document.getElementById("announcementCategory").value,
            audience: document.getElementById("announcementAudience").value,
            body: document.getElementById("announcementBody").value
          })
        }).then(function (result) {
          if (!result.ok) {
            window.alert(firstError(result.body));
            return;
          }
          staffForm.reset();
          const modal = document.getElementById("announcementModal");
          if (modal) modal.classList.remove("show");
          refresh(true);
        });
      }, true);
    }

    const boot = form
      ? request("/api/v1/departments").then(function (result) {
        if (result.ok) {
          fillSelect(document.getElementById("noticeDepartment"), result.body.data || [], "name", "id", "Select a department");
        }
      })
      : Promise.resolve();

    boot.then(function () {
      refresh(true);
      startLiveBoard();
    });
  };

  const wireTimetable = function () {
    const filter = document.getElementById("classFilter") || document.querySelector(".time-grid") || document.querySelector(".timetable-table");
    if (!filter && !document.getElementById("printTimetable")) return;
    if (!document.querySelector(".time-grid") && !document.querySelector(".timetable-table") && !document.getElementById("classFilter")) return;

    const form = document.querySelector("[data-bell-form]");
    const notice = document.querySelector("[data-bell-notice]");
    const formNotice = document.querySelector("[data-bell-form-notice]");
    const removeBtn = document.querySelector("[data-bell-remove]");
    const clearBtn = document.querySelector("[data-bell-clear]");
    const kindSelect = document.getElementById("bellKind");
    let context = { offerings: [] };
    let slots = [];
    let staff = [];
    let subjects = [];
    let departments = [];

    const setMetricValue = function (key, value) {
      const node = document.querySelector('[data-metric="' + key + '"]');
      if (node) node.textContent = value == null || value === "" ? "—" : String(value);
    };

    const currentOffering = function () {
      const picker = document.getElementById("classFilter") || document.getElementById("timetableStudent") || document.querySelector(".panel-title select");
      const offeringId = picker && picker.value && picker.value !== "all" ? Number(picker.value) : Number(((context.offerings || [])[0] || {}).id || 0);
      return (context.offerings || []).find(function (row) { return Number(row.id) === offeringId; }) || null;
    };

    const syncKind = function () {
      const other = kindSelect && kindSelect.value === "other";
      const subjectField = document.querySelector("[data-bell-subject-field]");
      const labelField = document.querySelector("[data-bell-label-field]");
      if (subjectField) subjectField.hidden = !!other;
      if (labelField) labelField.hidden = !other;
      syncNewSubject();
    };

    const syncNewSubject = function () {
      const other = kindSelect && kindSelect.value === "other";
      const select = document.getElementById("bellSubject");
      const fields = document.querySelector("[data-bell-new-subject]");
      if (fields) fields.hidden = other || !(select && select.value === "new");
    };

    const syncSubjects = function (preferredId) {
      const select = document.getElementById("bellSubject");
      if (!select) return;
      const offering = currentOffering();
      const offered = (offering && offering.subjects) || [];
      const offeredIds = offered.map(function (row) { return Number(row.id); });
      const catalogue = subjects.filter(function (row) {
        return row.is_active !== false && offeredIds.indexOf(Number(row.id)) === -1;
      });
      const current = preferredId != null ? String(preferredId) : select.value;
      select.innerHTML = "";
      const add = function (value, label) {
        const option = document.createElement("option");
        option.value = value;
        option.textContent = label;
        select.appendChild(option);
      };
      add("", "Select subject");
      add("new", "New subject…");
      offered.forEach(function (row) { add(String(row.id), row.name); });
      catalogue.forEach(function (row) {
        add("offer:" + row.id, row.name + " · offer on this form");
      });
      if (current && Array.from(select.options).some(function (option) { return option.value === current; })) {
        select.value = current;
      }
      syncNewSubject();
    };

    const refreshClassroom = function (preferredId) {
      return Promise.all([
        loadContext(),
        request("/api/v1/subjects")
      ]).then(function (results) {
        context = results[0] || context;
        if (results[1].ok) subjects = results[1].body.data || subjects;
        syncSubjects(preferredId);
        return preferredId;
      });
    };

    const ensureSubject = function (offering) {
      const other = kindSelect && kindSelect.value === "other";
      if (other) return Promise.resolve(null);
      const value = (document.getElementById("bellSubject") || {}).value || "";
      if (!value) return Promise.resolve(null);

      const offerOnForm = function (id) {
        const already = (offering.subjects || []).some(function (row) { return Number(row.id) === Number(id); });
        if (already) return refreshClassroom(id).then(function () { return Number(id); });
        return request("/api/v1/subject-offerings", {
          method: "POST",
          body: JSON.stringify({
            class_section_offering_id: offering.id,
            subject_id: Number(id)
          })
        }).then(function (result) {
          if (!result.ok) throw result;
          return refreshClassroom(id).then(function () { return Number(id); });
        });
      };

      if (value === "new") {
        const payload = {
          name: ((document.getElementById("bellSubjectName") || {}).value || "").trim(),
          code: ((document.getElementById("bellSubjectCode") || {}).value || "").trim(),
          department_id: Number((document.getElementById("bellSubjectDepartment") || {}).value) || 0
        };
        if (!payload.code) delete payload.code;
        if (!payload.department_id) delete payload.department_id;
        return request("/api/v1/subjects", {
          method: "POST",
          body: JSON.stringify(payload)
        }).then(function (result) {
          if (!result.ok) throw result;
          return offerOnForm(result.body.data.id);
        });
      }

      if (value.indexOf("offer:") === 0) {
        return offerOnForm(value.slice(6));
      }

      return Promise.resolve(Number(value) || null);
    };

    const fillStaff = function () {
      const rows = staff.filter(function (row) {
        return row.status === "active" && row.account_status !== "suspended";
      }).map(function (row) {
        return { id: row.id, name: row.name };
      });
      fillSelect(document.getElementById("bellStaff"), rows, "name", "id", "No master yet");
    };

    const applyMetrics = function (payload) {
      setMetricValue("periods", payload && payload.period_count != null ? payload.period_count : "—");
      setMetricValue("mapped", payload && payload.mapped_forms != null ? payload.mapped_forms : "—");
      setMetricValue("first", payload && payload.first_bell ? clockLabel(payload.first_bell) : "—");
      setMetricValue("last", payload && payload.last_bell ? clockLabel(payload.last_bell) : "—");
    };

    const resetForm = function () {
      if (!form) return;
      form.reset();
      if (document.getElementById("bellSlotId")) document.getElementById("bellSlotId").value = "";
      if (document.getElementById("bellStarts")) document.getElementById("bellStarts").value = "08:00";
      if (document.getElementById("bellEnds")) document.getElementById("bellEnds").value = "08:40";
      if (kindSelect) kindSelect.value = "lesson";
      const title = document.querySelector("[data-bell-form-title]");
      if (title) title.textContent = "Set a period";
      if (removeBtn) removeBtn.hidden = true;
      if (clearBtn) clearBtn.hidden = true;
      syncKind();
      syncSubjects();
    };

    const fillForm = function (slot, day, time) {
      if (!form) return;
      if (document.getElementById("bellSlotId")) document.getElementById("bellSlotId").value = slot ? String(slot.id) : "";
      if (document.getElementById("bellDay")) document.getElementById("bellDay").value = String(slot ? slot.day_of_week : day || 1);
      if (document.getElementById("bellStarts")) document.getElementById("bellStarts").value = slot ? slot.starts_at : (time || "08:00");
      if (document.getElementById("bellEnds")) document.getElementById("bellEnds").value = slot ? slot.ends_at : addMinutes(time || "08:00", 40);
      if (kindSelect) kindSelect.value = slot && !slot.subject_id ? "other" : "lesson";
      syncKind();
      syncSubjects();
      if (document.getElementById("bellSubject")) document.getElementById("bellSubject").value = slot && slot.subject_id ? String(slot.subject_id) : "";
      syncNewSubject();
      if (document.getElementById("bellLabel")) document.getElementById("bellLabel").value = slot && slot.label ? slot.label : "";
      if (document.getElementById("bellStaff")) document.getElementById("bellStaff").value = slot && slot.staff_profile_id ? String(slot.staff_profile_id) : "";
      const title = document.querySelector("[data-bell-form-title]");
      if (title) title.textContent = slot ? "Edit this period" : "Set a period";
      if (removeBtn) removeBtn.hidden = !slot;
      if (clearBtn) clearBtn.hidden = false;
    };

    const loadGrid = function () {
      const offering = currentOffering();
      if (!offering) {
        applyMetrics({});
        const grid = document.querySelector(".time-grid");
        if (grid) {
          grid.innerHTML = '<div class="slot"></div><div class="day">Mon</div><div class="day">Tue</div><div class="day">Wed</div><div class="day">Thu</div><div class="day">Fri</div>'
            + '<div class="slot"></div><div class="lesson" style="grid-column: span 5"><strong>No form on the books</strong><span>Open a form on Classes before the bell can ring.</span></div>';
        }
        return;
      }
      request("/api/v1/timetable?class_section_offering_id=" + encodeURIComponent(offering.id)).then(function (result) {
        if (!result.ok) {
          if (notice) notice.textContent = firstError(result.body);
          return;
        }
        const payload = result.body.data || {};
        slots = payload.slots || [];
        renderTimetable(payload);
        applyMetrics(payload);
      });
    };

    Promise.all([
      loadContext(),
      form ? request("/api/v1/staff") : Promise.resolve({ ok: false, body: {} }),
      form ? request("/api/v1/subjects") : Promise.resolve({ ok: false, body: {} }),
      form ? request("/api/v1/departments") : Promise.resolve({ ok: false, body: {} })
    ]).then(function (results) {
      context = results[0] || { offerings: [] };
      staff = (results[1].ok && results[1].body.data) || [];
      subjects = (results[2].ok && results[2].body.data) || [];
      departments = (results[3].ok && results[3].body.data) || [];

      const select = document.getElementById("classFilter") || document.querySelector(".panel-title select");
      if (select && select.id !== "subjectFilter") {
        if (!select.id) select.id = "classFilter";
        fillSelect(select, context.offerings || [], "form", "id", select.options.length && select.options[0].value === "all" ? "All classes" : "Select a form");
        if ((context.offerings || [])[0] && select.value === "") {
          select.value = String(context.offerings[0].id);
        }
      }
      const childSelect = document.getElementById("timetableStudent");
      if (childSelect) {
        fillSelect(childSelect, context.children || [], "full_name", "class_section_offering_id");
      }
      fillStaff();
      fillSelect(document.getElementById("bellSubjectDepartment"), departments, "name", "id", "No department");
      syncSubjects();
      const picker = document.getElementById("classFilter") || document.getElementById("timetableStudent") || document.querySelector(".panel-title select");
      if (picker) picker.addEventListener("change", function () {
        resetForm();
        syncSubjects();
        loadGrid();
      });
      loadGrid();
    });

    if (kindSelect) kindSelect.addEventListener("change", syncKind);
    const subjectSelect = document.getElementById("bellSubject");
    if (subjectSelect) subjectSelect.addEventListener("change", syncNewSubject);

    const grid = document.querySelector("[data-bell-grid]") || document.querySelector(".time-grid");
    if (form && grid) {
      grid.addEventListener("click", function (event) {
        const cell = event.target.closest(".lesson[data-day]");
        if (!cell) return;
        const id = cell.getAttribute("data-slot-id");
        const day = Number(cell.getAttribute("data-day"));
        const time = cell.getAttribute("data-time");
        const slot = id ? slots.find(function (row) { return String(row.id) === String(id); }) : null;
        if (formNotice) formNotice.textContent = "";
        fillForm(slot || null, day, time);
        form.scrollIntoView({ behavior: "smooth", block: "nearest" });
      });
    }

    if (clearBtn) {
      clearBtn.addEventListener("click", function () {
        resetForm();
      });
    }

    if (removeBtn) {
      removeBtn.addEventListener("click", function () {
        const id = (document.getElementById("bellSlotId") || {}).value;
        if (!id) return;
        confirmDesk({
          title: "Remove this period",
          copy: "This cell will leave the live bell. The rest of the week stays sealed.",
          confirmLabel: "Remove period",
          cancelLabel: "Keep it",
          danger: true
        }).then(function (ok) {
          if (!ok) return;
          if (notice) notice.textContent = "";
          request("/api/v1/timetable/" + id, { method: "DELETE" }).then(function (result) {
            if (!result.ok) {
              if (notice) notice.textContent = firstError(result.body);
              return;
            }
            resetForm();
            loadGrid();
          });
        });
      });
    }

    if (form) {
      const button = form.querySelector(".solid-btn");
      form.addEventListener("submit", function (event) {
        event.preventDefault();
        const offering = currentOffering();
        if (!offering) {
          if (formNotice) formNotice.textContent = "Open a form on Classes before the bell can ring.";
          return;
        }
        const slotId = (document.getElementById("bellSlotId") || {}).value;
        const other = kindSelect && kindSelect.value === "other";
        const payload = {
          class_section_offering_id: offering.id,
          day_of_week: Number((document.getElementById("bellDay") || {}).value),
          starts_at: ((document.getElementById("bellStarts") || {}).value || "").slice(0, 5),
          ends_at: ((document.getElementById("bellEnds") || {}).value || "").slice(0, 5)
        };
        const staffId = Number((document.getElementById("bellStaff") || {}).value) || 0;
        payload.staff_profile_id = staffId || null;

        if (formNotice) formNotice.textContent = "";
        setButtonState(button, true, "Sealing…");
        ensureSubject(offering).then(function (subjectId) {
          if (other) {
            payload.label = (document.getElementById("bellLabel") || {}).value || "";
            payload.subject_id = null;
          } else {
            payload.subject_id = subjectId;
            payload.label = null;
          }
          return request(slotId ? "/api/v1/timetable/" + slotId : "/api/v1/timetable", {
            method: slotId ? "PUT" : "POST",
            body: JSON.stringify(payload)
          });
        }).then(function (result) {
          if (!result.ok) {
            const message = firstError(result.body);
            if (formNotice) formNotice.textContent = message;
            setButtonState(button, false, message);
            window.setTimeout(function () { setButtonState(button, false); }, 2200);
            return;
          }
          resetForm();
          setButtonState(button, false, "Sealed.");
          window.setTimeout(function () { setButtonState(button, false); }, 1600);
          loadGrid();
        }).catch(function (result) {
          const message = result && result.body ? firstError(result.body) : "Unable to reach the office.";
          if (formNotice) formNotice.textContent = message;
          setButtonState(button, false, message);
          window.setTimeout(function () { setButtonState(button, false); }, 1800);
        });
      });
    }
  };

  const wireAssignments = function () {
    if (!document.getElementById("assignmentForm") && !document.getElementById("studentSelect") && !document.querySelector(".assignments-table, .assignments-list")) return;

    loadContext().then(function (context) {
      fillSelect(document.getElementById("assignmentClass"), context.offerings || [], "form", "id");
      fillSelect(document.getElementById("studentSelect"), context.children || [], "full_name", "id");

      const syncSubjects = function () {
        const offeringId = Number((document.getElementById("assignmentClass") || {}).value);
        const offering = (context.offerings || []).find(function (row) { return Number(row.id) === offeringId; });
        fillSelect(document.getElementById("assignmentSubject"), (offering && offering.subjects) || [], "name", "id", "Select subject");
      };
      const classSelect = document.getElementById("assignmentClass");
      if (classSelect) {
        classSelect.addEventListener("change", syncSubjects);
        syncSubjects();
      }

      const refresh = function () {
        const student = document.getElementById("studentSelect");
        const query = student && student.value ? "?student_profile_id=" + encodeURIComponent(student.value) : "";
        request("/api/v1/assignments" + query).then(function (result) {
          if (result.ok) renderAssignments(result.body.data || []);
        });
      };

      if (document.getElementById("studentSelect")) {
        document.getElementById("studentSelect").addEventListener("change", refresh);
      }
      refresh();
    });

    const tbody = document.querySelector(".assignments-table tbody");
    if (tbody && !tbody.getAttribute("data-wired-inbox")) {
      tbody.setAttribute("data-wired-inbox", "1");
      tbody.addEventListener("click", function (event) {
        const row = event.target.closest("tr[data-assignment-id]");
        if (!row) return;
        openAssignmentInbox(row.getAttribute("data-assignment-id"), row.getAttribute("data-title"));
      });
    }

    const form = document.getElementById("assignmentForm");
    if (form) {
      form.addEventListener("submit", function (event) {
        event.preventDefault();
        request("/api/v1/assignments", {
          method: "POST",
          body: JSON.stringify({
            title: document.getElementById("assignmentTitle").value,
            subject_id: Number(document.getElementById("assignmentSubject").value),
            class_section_offering_id: Number(document.getElementById("assignmentClass").value),
            due_on: document.getElementById("assignmentDue").value,
            instructions: document.getElementById("assignmentInstructions").value
          })
        }).then(function (result) {
          if (!result.ok) {
            window.alert(firstError(result.body));
            return;
          }
          form.reset();
          const modal = document.getElementById("assignmentModal");
          if (modal) modal.classList.remove("show");
          request("/api/v1/assignments").then(function (list) {
            if (list.ok) renderAssignments(list.body.data || []);
          });
        });
      }, true);
    }
  };

  const wireMaterials = function () {
    if (!document.getElementById("uploadForm") && !document.getElementById("materialStudent") && !document.querySelector(".materials-grid")) return;

    loadContext().then(function (context) {
      fillSelect(document.getElementById("materialClass"), context.offerings || [], "form", "id");
      fillSelect(document.getElementById("materialStudent"), context.children || [], "full_name", "id");
      const syncSubjects = function () {
        const offeringId = Number((document.getElementById("materialClass") || {}).value);
        const offering = (context.offerings || []).find(function (row) { return Number(row.id) === offeringId; });
        fillSelect(document.getElementById("materialSubject"), (offering && offering.subjects) || [], "name", "id", "Select subject");
      };
      const classSelect = document.getElementById("materialClass");
      if (classSelect) {
        classSelect.addEventListener("change", syncSubjects);
        syncSubjects();
      }

      const refresh = function () {
        const student = document.getElementById("materialStudent");
        const query = student && student.value ? "?student_profile_id=" + encodeURIComponent(student.value) : "";
        request("/api/v1/learning-materials" + query).then(function (result) {
          if (result.ok) renderMaterials(result.body.data || []);
        });
      };
      if (document.getElementById("materialStudent")) {
        document.getElementById("materialStudent").addEventListener("change", refresh);
      }
      refresh();
    });

    const form = document.getElementById("uploadForm");
    if (form) {
      form.addEventListener("submit", function (event) {
        event.preventDefault();
        const data = new FormData();
        data.append("class_section_offering_id", document.getElementById("materialClass").value);
        data.append("subject_id", document.getElementById("materialSubject").value);
        const file = document.getElementById("fileInput");
        if (file && file.files[0]) data.append("file", file.files[0]);
        request("/api/v1/learning-materials", { method: "POST", body: data }).then(function (result) {
          if (!result.ok) {
            window.alert(firstError(result.body));
            return;
          }
          form.reset();
          const modal = document.getElementById("uploadModal");
          if (modal) modal.classList.remove("show");
          request("/api/v1/learning-materials").then(function (list) {
            if (list.ok) renderMaterials(list.body.data || []);
          });
        });
      }, true);
    }
  };

  const wireMessages = function () {
    if (!document.getElementById("conversationList") && !document.getElementById("composeForm") && !document.querySelector(".messages-container")) return;

    let activeId = null;

    const refreshList = function () {
      return request("/api/v1/conversations").then(function (result) {
        if (!result.ok) return [];
        const rows = result.body.data || [];
        renderConversations(rows, activeId);
        return rows;
      });
    };

    const openConversation = function (id) {
      activeId = id;
      request("/api/v1/conversations/" + id).then(function (result) {
        if (!result.ok) return;
        renderChat(result.body.data);
        refreshList();
        loadNotifications();
      });
    };

    document.addEventListener("click", function (event) {
      const item = event.target.closest("[data-id].conversation, .message-item[data-id]");
      if (!item) return;
      openConversation(item.getAttribute("data-id"));
    });

    request("/api/v1/me").then(function (me) {
      if (me.ok && me.body && me.body.data && me.body.data.name) {
        document.body.setAttribute("data-user-name", me.body.data.name);
      }
    }).then(function () {
      return refreshList();
    }).then(function (rows) {
      if (rows && rows[0]) openConversation(rows[0].id);
    });

    request("/api/v1/messages/recipients").then(function (result) {
      if (result.ok) fillSelect(document.getElementById("composeRecipient"), result.body.data || [], "name", "id", "Select recipient");
    });

    const compose = document.getElementById("composeForm");
    if (compose) {
      compose.addEventListener("submit", function (event) {
        event.preventDefault();
        request("/api/v1/conversations", {
          method: "POST",
          body: JSON.stringify({
            recipient_id: Number(document.getElementById("composeRecipient").value),
            subject: document.getElementById("composeSubject").value,
            body: document.getElementById("composeBody").value
          })
        }).then(function (result) {
          if (!result.ok) {
            window.alert(firstError(result.body));
            return;
          }
          compose.reset();
          const created = result.body.data || {};
          refreshList().then(function () {
            if (created.id) openConversation(created.id);
          });
        });
      }, true);
    }

    const send = document.getElementById("sendMessage");
    const input = document.getElementById("messageInput");
    if (send && input) {
      send.addEventListener("click", function () {
        if (!activeId || !input.value.trim()) return;
        request("/api/v1/conversations/" + activeId + "/messages", {
          method: "POST",
          body: JSON.stringify({ body: input.value })
        }).then(function (result) {
          if (!result.ok) {
            window.alert(firstError(result.body));
            return;
          }
          input.value = "";
          openConversation(activeId);
        });
      });
    }
  };

  loadNotifications();
  wireAnnouncements();
  wireTimetable();
  wireAssignments();
  wireMaterials();
  wireMessages();
})();
