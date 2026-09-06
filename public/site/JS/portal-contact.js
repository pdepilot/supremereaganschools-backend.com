(function () {
  if (document.body.getAttribute("data-page") !== "contact") return;

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

  const CONTACT_POLL_MS = 8000;

  const can = function (slug) {
    if (window.srsHasPermission) return !!window.srsHasPermission(slug);
    const me = window.srsMe;
    if (me && (me.is_super_admin || (me.roles || []).indexOf("super_admin") !== -1)) return true;
    return ((me && me.permissions) || []).indexOf(slug) !== -1;
  };

  const canViewApplications = function () {
    return can("admissions.view") || can("admissions.manage");
  };

  const canManageApplications = function () {
    return can("admissions.manage");
  };

  const statusLabel = function (value) {
    const map = {
      unread: "Unread",
      read: "Read",
      urgent: "Urgent",
      cleared: "Cleared",
      submitted: "Submitted",
      under_review: "Under review",
      exam_scheduled: "Exam scheduled",
      offered: "Offered",
      admitted: "Admitted",
      rejected: "Rejected",
      withdrawn: "Withdrawn"
    };
    return map[value] || value || "—";
  };

  const prettyLabel = function (value) {
    if (!value) return "—";
    return String(value).replace(/_/g, " ").replace(/\b\w/g, function (ch) {
      return ch.toUpperCase();
    });
  };

  const clockStamp = function (value) {
    if (!value) return "—";
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return "—";
    return date.toLocaleString("en-GB", {
      timeZone: "Africa/Lagos",
      day: "2-digit",
      month: "short",
      year: "numeric",
      hour: "2-digit",
      minute: "2-digit",
      hour12: false
    });
  };

  const excerpt = function (value, limit) {
    const text = String(value || "").replace(/\s+/g, " ").trim();
    if (text.length <= limit) return text;
    return text.slice(0, limit).replace(/\s+\S*$/, "") + "…";
  };

  const isWaiting = function (item) {
    return item && (item.status === "unread" || item.status === "urgent");
  };

  const isOpen = function (item) {
    return item && item.status !== "cleared";
  };

  const hasReply = function (item) {
    return !!(item && (item.replied || (item.replies && item.replies.length)));
  };

  const badgeFor = function (item) {
    if (hasReply(item)) return "Replied";
    return statusLabel(item && item.status);
  };

  const matchesFilter = function (item, filter) {
    if (!filter) return true;
    if (filter === "waiting") return isWaiting(item);
    if (filter === "open") return isOpen(item);
    if (filter === "replied") return hasReply(item);
    if (filter === "cleared") return item.status === "cleared";
    return true;
  };

  const matchesSearch = function (item, query) {
    if (!query) return true;
    const hay = [item.name, item.email, item.phone, item.subject, item.message]
      .join(" ")
      .toLowerCase();
    return hay.indexOf(query) !== -1;
  };

  const applicationWaiting = function (item) {
    return item && (item.status === "submitted" || item.status === "under_review");
  };

  const matchesApplicationSearch = function (item, query) {
    if (!query) return true;
    const hay = [
      item.reference,
      item.full_name,
      item.first_name,
      item.surname,
      item.parent_name,
      item.parent_email,
      item.parent_phone,
      item.class_applied,
      item.level_name,
      item.session_name
    ].join(" ").toLowerCase();
    return hay.indexOf(query) !== -1;
  };

  const setText = function (selector, value) {
    const node = document.querySelector(selector);
    if (node) node.textContent = value == null || value === "" ? "—" : String(value);
  };

  const setHtml = function (selector, html) {
    const node = document.querySelector(selector);
    if (node) node.innerHTML = html;
  };

  const copyEl = document.querySelector("[data-contact-copy]");
  const noticeEl = document.querySelector("[data-contact-notice]");
  const formNoticeEl = document.querySelector("[data-contact-form-notice]");
  const listEl = document.querySelector("[data-contact-list]");
  const searchEl = document.getElementById("contactSearch");
  const filterEl = document.getElementById("contactFilter");
  const emptyEl = document.querySelector("[data-contact-letter-empty]");
  const sheetEl = document.querySelector("[data-contact-letter-sheet]");
  const composeEl = document.querySelector("[data-contact-compose]");
  const deleteBtn = document.querySelector("[data-contact-delete]");
  const replyForm = document.querySelector("[data-contact-reply-form]");
  const sendBtn = document.querySelector("[data-contact-send]");
  const lettersPane = document.querySelector("[data-letters-pane]");
  const applicationsPane = document.querySelector("[data-applications-pane]");
  const applicationsTab = document.querySelector("[data-admissions-tab]");
  const applicationsMetric = document.querySelector("[data-metric-applications]");
  const applicationListEl = document.querySelector("[data-application-list]");
  const applicationSearchEl = document.getElementById("applicationSearch");
  const applicationFilterEl = document.getElementById("applicationFilter");
  const applicationEmptyEl = document.querySelector("[data-application-empty]");
  const applicationSheetEl = document.querySelector("[data-application-sheet]");
  const applicationStatusForm = document.querySelector("[data-application-status-form]");
  const applicationFormNotice = document.querySelector("[data-application-form-notice]");
  const applicationSaveBtn = document.querySelector("[data-application-save]");
  const applicationStatusEl = document.getElementById("applicationStatus");

  let letters = [];
  let applications = [];
  let selectedId = null;
  let selected = null;
  let selectedApplicationId = null;
  let selectedApplication = null;
  let activeTab = "letters";
  let pollTimer = null;

  const setNotice = function (el, message) {
    if (el) el.textContent = message || "";
  };

  const setMetric = function (key, value, delta) {
    const valueEl = document.querySelector('[data-metric="' + key + '"]');
    const deltaEl = document.querySelector('[data-metric-delta="' + key + '"]');
    if (valueEl) valueEl.textContent = value;
    if (deltaEl && delta) deltaEl.textContent = delta;
  };

  const paintMetrics = function () {
    const waiting = letters.filter(isWaiting).length;
    const open = letters.filter(isOpen).length;
    const replied = letters.filter(hasReply).length;
    const pendingApps = applications.filter(applicationWaiting).length;
    setMetric("waiting", String(waiting), waiting === 1 ? "Needs the office" : "Need the office");
    setMetric("open", String(open), "Still on the desk");
    setMetric("replied", String(replied), "Sealed answers");
    setMetric("total", String(letters.length), "From the website");
    if (canViewApplications()) {
      setMetric("applications", String(applications.length), pendingApps
        ? (pendingApps === 1 ? "1 awaiting review" : pendingApps + " awaiting review")
        : "On the admissions roll");
    }
  };

  const paintList = function () {
    if (!listEl) return;
    const query = ((searchEl && searchEl.value) || "").trim().toLowerCase();
    const filter = (filterEl && filterEl.value) || "";
    const rows = letters.filter(function (item) {
      return matchesFilter(item, filter) && matchesSearch(item, query);
    });

    if (!rows.length) {
      listEl.innerHTML = "<p>" + (letters.length ? "No letters match that search." : "No letters from the website yet.") + "</p>";
      return;
    }

    listEl.innerHTML = rows.map(function (item) {
      const active = String(item.id) === String(selectedId) ? " is-active" : "";
      return '<article class="ticket' + active + '" data-contact-id="' + escapeHtml(item.id) + '">' +
        '<div class="ticket-code">' + escapeHtml(clockStamp(item.created_at)) + "</div>" +
        "<div><h3>" + escapeHtml(item.name) + "</h3><p>" +
        escapeHtml(excerpt(item.subject || item.message, 72)) +
        "</p></div>" +
        '<span class="badge">' + escapeHtml(badgeFor(item)) + "</span>" +
        "</article>";
    }).join("");
  };

  const paintApplicationList = function () {
    if (!applicationListEl) return;
    const query = ((applicationSearchEl && applicationSearchEl.value) || "").trim().toLowerCase();
    const filter = (applicationFilterEl && applicationFilterEl.value) || "";
    const rows = applications.filter(function (item) {
      if (filter && item.status !== filter) return false;
      return matchesApplicationSearch(item, query);
    });

    if (!rows.length) {
      applicationListEl.innerHTML = "<p>" + (applications.length
        ? "No applications match that search."
        : "No admission applications have been submitted yet.") + "</p>";
      return;
    }

    applicationListEl.innerHTML = rows.map(function (item) {
      const active = String(item.id) === String(selectedApplicationId) ? " is-active" : "";
      const line = [item.reference, item.class_applied || item.level_name, item.parent_name]
        .filter(Boolean)
        .join(" · ");
      return '<article class="ticket' + active + '" data-application-id="' + escapeHtml(item.id) + '">' +
        '<div class="ticket-code">' + escapeHtml(clockStamp(item.created_at)) + "</div>" +
        "<div><h3>" + escapeHtml(item.full_name || ((item.first_name || "") + " " + (item.surname || "")).trim() || "Applicant") + "</h3><p>" +
        escapeHtml(line || "Admission application") +
        "</p></div>" +
        '<span class="badge">' + escapeHtml(statusLabel(item.status)) + "</span>" +
        "</article>";
    }).join("");
  };

  const paintThread = function (item) {
    const thread = document.querySelector("[data-contact-thread]");
    if (!thread) return;
    const replies = (item && item.replies) || [];
    if (!replies.length) {
      thread.innerHTML = "";
      return;
    }
    thread.innerHTML = replies.map(function (reply) {
      return '<article class="contact-reply">' +
        "<h3>" + escapeHtml(reply.subject || "Reply") + "</h3>" +
        '<p class="msg-meta">' + escapeHtml((reply.author || "The office") + " · " + clockStamp(reply.sent_at)) + "</p>" +
        '<p class="letter-body">' + escapeHtml(reply.body) + "</p>" +
        "</article>";
    }).join("");
  };

  const paintLetter = function (item) {
    const nameEl = document.querySelector("[data-contact-letter-name]");
    const subjectEl = document.querySelector("[data-contact-letter-subject]");
    const statusEl = document.querySelector("[data-contact-letter-status]");
    const emailEl = document.querySelector("[data-contact-letter-email]");
    const phoneEl = document.querySelector("[data-contact-letter-phone]");
    const timeEl = document.querySelector("[data-contact-letter-time]");
    const assigneeEl = document.querySelector("[data-contact-letter-assignee]");
    const bodyEl = document.querySelector("[data-contact-letter-body]");
    const toEl = document.getElementById("contactReplyTo");
    const subjectInput = document.getElementById("contactReplySubject");

    if (!item) {
      selected = null;
      selectedId = null;
      if (nameEl) nameEl.textContent = "Open a letter";
      if (subjectEl) subjectEl.textContent = "Choose a name on the left to read it here.";
      if (emptyEl) emptyEl.hidden = false;
      if (sheetEl) sheetEl.hidden = true;
      if (composeEl) composeEl.hidden = true;
      if (deleteBtn) deleteBtn.hidden = true;
      paintList();
      return;
    }

    selected = item;
    selectedId = item.id;
    if (emptyEl) emptyEl.hidden = true;
    if (sheetEl) sheetEl.hidden = false;
    if (composeEl) composeEl.hidden = false;
    if (deleteBtn) deleteBtn.hidden = false;
    if (nameEl) nameEl.textContent = item.name || "Letter";
    if (subjectEl) subjectEl.textContent = item.subject || "General correspondence";
    if (statusEl) statusEl.textContent = hasReply(item) ? "Replied" : statusLabel(item.status);
    if (emailEl) {
      emailEl.innerHTML = item.email
        ? '<a href="mailto:' + escapeHtml(item.email) + '">' + escapeHtml(item.email) + "</a>"
        : "—";
    }
    if (phoneEl) {
      phoneEl.innerHTML = item.phone
        ? '<a href="tel:' + escapeHtml(item.phone) + '">' + escapeHtml(item.phone) + "</a>"
        : "—";
    }
    if (timeEl) timeEl.textContent = clockStamp(item.created_at);
    if (assigneeEl) assigneeEl.textContent = item.assigned_to || "—";
    if (bodyEl) bodyEl.textContent = item.message || "";
    if (toEl) toEl.value = (item.name || "") + (item.email ? " · " + item.email : "");
    if (subjectInput && (!subjectInput.value || subjectInput.dataset.locked !== "1")) {
      subjectInput.value = item.subject ? "Re: " + item.subject : "A note from Supreme Reagan Schools";
    }
    paintThread(item);
    paintList();
  };

  const paintApplication = function (item) {
    if (!item) {
      selectedApplication = null;
      selectedApplicationId = null;
      if (applicationEmptyEl) applicationEmptyEl.hidden = false;
      if (applicationSheetEl) applicationSheetEl.hidden = true;
      paintApplicationList();
      return;
    }

    selectedApplication = item;
    selectedApplicationId = item.id;
    if (applicationEmptyEl) applicationEmptyEl.hidden = true;
    if (applicationSheetEl) applicationSheetEl.hidden = false;
    if (applicationStatusForm) applicationStatusForm.hidden = !canManageApplications();

    setText("[data-application-name]", item.full_name || ((item.first_name || "") + " " + (item.surname || "")).trim() || "Applicant");
    setText("[data-application-ref]", [item.reference, statusLabel(item.status)].filter(Boolean).join(" · "));
    setText("[data-application-status]", statusLabel(item.status));
    setText("[data-application-session]", item.session_name);
    setText("[data-application-level]", item.level_name);
    setText("[data-application-class]", item.class_applied);
    setText("[data-application-term]", prettyLabel(item.entry_term));
    setText("[data-application-time]", clockStamp(item.created_at));
    setText("[data-application-gender]", prettyLabel(item.gender));
    setText("[data-application-dob]", item.date_of_birth || "—");
    setText("[data-application-nationality]", item.nationality);
    setText("[data-application-state]", item.state_of_origin);
    setText("[data-application-lga]", item.lga);
    setText("[data-application-address]", item.home_address);
    setText("[data-application-previous]", [item.previous_school, item.last_class].filter(Boolean).join(" · ") || "—");
    setText("[data-application-parent]", item.parent_name);
    setText("[data-application-relationship]", prettyLabel(item.relationship));
    setText("[data-application-occupation]", item.parent_occupation);

    const email = item.parent_email
      ? '<a href="mailto:' + escapeHtml(item.parent_email) + '">' + escapeHtml(item.parent_email) + "</a>"
      : "—";
    const phone = item.parent_phone
      ? '<a href="tel:' + escapeHtml(item.parent_phone) + '">' + escapeHtml(item.parent_phone) + "</a>"
      : "—";
    setHtml("[data-application-email]", email);
    setHtml("[data-application-phone]", phone);

    const docs = item.documents || [];
    const docsEl = document.querySelector("[data-application-documents]");
    if (docsEl) {
      docsEl.innerHTML = docs.length
        ? docs.map(function (doc) {
          const label = doc.original_name || prettyLabel(doc.type) || "Document";
          return '<a class="ghost-btn" href="/api/v1/documents/' + encodeURIComponent(doc.id) + '/download" target="_blank" rel="noopener">'
            + escapeHtml(label) + "</a>";
        }).join("")
        : "<span>No documents attached.</span>";
    }

    if (applicationStatusEl) applicationStatusEl.value = item.status || "submitted";
    setNotice(applicationFormNotice, "");
    paintApplicationList();
  };

  const upsert = function (item) {
    if (!item || !item.id) return;
    let found = false;
    letters = letters.map(function (row) {
      if (String(row.id) === String(item.id)) {
        found = true;
        return item;
      }
      return row;
    });
    if (!found) letters.unshift(item);
    paintMetrics();
  };

  const upsertApplication = function (item) {
    if (!item || !item.id) return;
    let found = false;
    applications = applications.map(function (row) {
      if (String(row.id) === String(item.id)) {
        found = true;
        return item;
      }
      return row;
    });
    if (!found) applications.unshift(item);
    paintMetrics();
  };

  const setTab = function (tab) {
    activeTab = tab === "applications" ? "applications" : "letters";
    document.querySelectorAll("[data-desk-tab]").forEach(function (btn) {
      btn.classList.toggle("is-active", btn.getAttribute("data-desk-tab") === activeTab);
    });
    if (lettersPane) lettersPane.hidden = activeTab !== "letters";
    if (applicationsPane) applicationsPane.hidden = activeTab !== "applications";
    if (copyEl) {
      if (activeTab === "applications") {
        copyEl.textContent = applications.length
          ? applications.length + (applications.length === 1 ? " application on the desk." : " applications on the desk.")
          : "No admission applications have been submitted yet.";
      } else {
        copyEl.textContent = letters.length
          ? letters.length + (letters.length === 1 ? " letter on the desk." : " letters on the desk.")
          : "The website has not sent a letter yet.";
      }
    }
  };

  const loadLetters = function () {
    return request("/api/v1/contact-enquiries").then(function (result) {
      if (!result.ok) {
        setNotice(noticeEl, firstError(result.body));
        return;
      }
      setNotice(noticeEl, "");
      letters = Array.isArray(result.body.data) ? result.body.data : [];
      paintMetrics();
      if (selectedId) {
        const current = letters.find(function (row) { return String(row.id) === String(selectedId); });
        if (current) {
          selected = current;
          paintLetter(current);
          return;
        }
        paintLetter(null);
        return;
      }
      paintList();
      if (activeTab === "letters" && copyEl) {
        copyEl.textContent = letters.length
          ? letters.length + (letters.length === 1 ? " letter on the desk." : " letters on the desk.")
          : "The website has not sent a letter yet.";
      }
    });
  };

  const loadApplications = function () {
    if (!canViewApplications()) {
      applications = [];
      paintMetrics();
      return Promise.resolve();
    }
    return request("/api/v1/admission-applications").then(function (result) {
      if (!result.ok) {
        if (activeTab === "applications") setNotice(noticeEl, firstError(result.body));
        return;
      }
      applications = Array.isArray(result.body.data) ? result.body.data : [];
      paintMetrics();
      if (selectedApplicationId) {
        const current = applications.find(function (row) {
          return String(row.id) === String(selectedApplicationId);
        });
        if (current) {
          selectedApplication = current;
          paintApplication(current);
          return;
        }
        paintApplication(null);
        return;
      }
      paintApplicationList();
      if (activeTab === "applications" && copyEl) {
        copyEl.textContent = applications.length
          ? applications.length + (applications.length === 1 ? " application on the desk." : " applications on the desk.")
          : "No admission applications have been submitted yet.";
      }
    });
  };

  const refresh = function () {
    return Promise.all([loadLetters(), loadApplications()]);
  };

  const openLetter = function (id) {
    selectedId = id;
    return request("/api/v1/contact-enquiries/" + id).then(function (result) {
      if (!result.ok) {
        setNotice(noticeEl, firstError(result.body));
        return;
      }
      const item = result.body.data || null;
      if (!item) return;
      upsert(item);
      const subjectInput = document.getElementById("contactReplySubject");
      const bodyInput = document.getElementById("contactReplyBody");
      if (subjectInput) subjectInput.dataset.locked = "";
      if (bodyInput) bodyInput.value = "";
      setNotice(formNoticeEl, "");
      paintLetter(item);
    });
  };

  const openApplication = function (id) {
    selectedApplicationId = id;
    return request("/api/v1/admission-applications/" + id).then(function (result) {
      if (!result.ok) {
        setNotice(noticeEl, firstError(result.body));
        return;
      }
      const item = result.body.data || null;
      if (!item) return;
      upsertApplication(item);
      paintApplication(item);
    });
  };

  if (listEl) {
    listEl.addEventListener("click", function (event) {
      const ticket = event.target.closest("[data-contact-id]");
      if (!ticket) return;
      openLetter(ticket.getAttribute("data-contact-id"));
    });
  }

  if (applicationListEl) {
    applicationListEl.addEventListener("click", function (event) {
      const ticket = event.target.closest("[data-application-id]");
      if (!ticket) return;
      openApplication(ticket.getAttribute("data-application-id"));
    });
  }

  if (searchEl) searchEl.addEventListener("input", paintList);
  if (filterEl) filterEl.addEventListener("change", paintList);
  if (applicationSearchEl) applicationSearchEl.addEventListener("input", paintApplicationList);
  if (applicationFilterEl) applicationFilterEl.addEventListener("change", paintApplicationList);

  document.querySelectorAll("[data-desk-tab]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      setTab(btn.getAttribute("data-desk-tab"));
    });
  });

  if (document.getElementById("contactReplySubject")) {
    document.getElementById("contactReplySubject").addEventListener("input", function () {
      this.dataset.locked = "1";
    });
  }

  if (deleteBtn) {
    deleteBtn.addEventListener("click", function () {
      if (!selectedId) return;
      const name = selected && selected.name ? selected.name : "this letter";
      confirmDesk({
        title: "Remove this letter?",
        copy: "The note from " + name + " will leave the desk. This cannot be undone.",
        confirmLabel: "Delete letter",
        cancelLabel: "Keep it",
        danger: true
      }).then(function (ok) {
        if (!ok) return;
        setButtonState(deleteBtn, true, "Removing…");
        request("/api/v1/contact-enquiries/" + selectedId, { method: "DELETE" }).then(function (result) {
          setButtonState(deleteBtn, false);
          if (!result.ok) {
            setNotice(noticeEl, firstError(result.body));
            return;
          }
          letters = letters.filter(function (row) { return String(row.id) !== String(selectedId); });
          paintMetrics();
          paintLetter(null);
          setNotice(noticeEl, "Letter removed from the desk.");
        });
      });
    });
  }

  if (replyForm) {
    replyForm.addEventListener("submit", function (event) {
      event.preventDefault();
      if (!selectedId) return;
      const subject = (document.getElementById("contactReplySubject") || {}).value || "";
      const body = (document.getElementById("contactReplyBody") || {}).value || "";
      setButtonState(sendBtn, true, "Sending…");
      setNotice(formNoticeEl, "");
      request("/api/v1/contact-enquiries/" + selectedId + "/reply", {
        method: "POST",
        body: JSON.stringify({ subject: subject, body: body })
      }).then(function (result) {
        setButtonState(sendBtn, false);
        if (!result.ok) {
          setNotice(formNoticeEl, firstError(result.body));
          return;
        }
        const item = result.body.data || null;
        if (item) upsert(item);
        const bodyInput = document.getElementById("contactReplyBody");
        if (bodyInput) bodyInput.value = "";
        setNotice(formNoticeEl, "Reply dispatched through the school mailbox.");
        paintLetter(item || selected);
      });
    });
  }

  if (applicationSaveBtn) {
    applicationSaveBtn.addEventListener("click", function () {
      if (!selectedApplicationId || !applicationStatusEl) return;
      setButtonState(applicationSaveBtn, true, "Saving…");
      setNotice(applicationFormNotice, "");
      request("/api/v1/admission-applications/" + selectedApplicationId, {
        method: "PUT",
        body: JSON.stringify({ status: applicationStatusEl.value })
      }).then(function (result) {
        setButtonState(applicationSaveBtn, false);
        if (!result.ok) {
          setNotice(applicationFormNotice, firstError(result.body));
          return;
        }
        const item = result.body.data || null;
        if (item) upsertApplication(item);
        setNotice(applicationFormNotice, "Application status updated.");
        paintApplication(item || selectedApplication);
      });
    });
  }

  const startPoll = function () {
    window.clearInterval(pollTimer);
    pollTimer = window.setInterval(refresh, CONTACT_POLL_MS);
  };

  document.addEventListener("visibilitychange", function () {
    if (document.hidden) {
      window.clearInterval(pollTimer);
      return;
    }
    refresh();
    startPoll();
  });

  const boot = function () {
    if (applicationsTab) applicationsTab.hidden = !canViewApplications();
    if (applicationsMetric) applicationsMetric.hidden = !canViewApplications();
    if (applicationStatusForm) applicationStatusForm.hidden = !canManageApplications();
    if (window.location.hash === "#applications" && canViewApplications()) {
      setTab("applications");
    } else {
      setTab("letters");
    }
    refresh().then(startPoll);
  };

  if (window.srsMe) boot();
  else {
    const wait = setInterval(function () {
      if (window.srsMe) {
        clearInterval(wait);
        boot();
      }
    }, 50);
    setTimeout(function () {
      clearInterval(wait);
      boot();
    }, 2500);
  }
})();
