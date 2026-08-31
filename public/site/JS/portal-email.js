(function () {
  if (document.body.getAttribute("data-page") !== "email") return;

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

  const audienceLabel = function (value) {
    const map = {
      user: "One person",
      users: "Several people",
      custom: "Typed addresses",
      whole_school: "Whole school",
      parents: "All parents",
      staff: "All staff",
      students: "All students",
      teaching_staff: "Teaching staff"
    };
    return map[value] || value || "";
  };

  const clockFromIso = function (iso) {
    if (!iso) return "—";
    const date = new Date(iso);
    if (!Number.isFinite(date.getTime())) return "—";
    return date.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
  };

  const form = document.querySelector("[data-email-form]");
  const formNotice = document.querySelector("[data-email-form-notice]");
  const boardNotice = document.querySelector("[data-email-notice]");
  const boardCopy = document.querySelector("[data-email-copy]");
  const previewCopy = document.querySelector("[data-email-preview-copy]");
  const formTitle = document.querySelector("[data-email-form-title]");
  const sendBtn = document.querySelector("[data-email-send]");
  const previewBtn = document.querySelector("[data-email-preview]");
  const iframe = document.querySelector("[data-email-iframe]");
  let templates = [];
  let people = [];
  let selectedId = 0;
  let selectedUserIds = [];

  const setFormNotice = function (message) {
    if (formNotice) formNotice.textContent = message || "";
  };

  const setMetricValue = function (key, value) {
    const node = document.querySelector('[data-metric="' + key + '"]');
    if (node) node.textContent = value == null || value === "" ? "—" : String(value);
  };

  const setMetricDelta = function (key, value) {
    const node = document.querySelector('[data-metric-delta="' + key + '"]');
    if (node) node.textContent = value || "";
  };

  const payload = function () {
    const audience = (document.getElementById("emailAudience") || {}).value || "user";
    const body = {
      template_id: Number((document.getElementById("emailTemplateId") || {}).value || 0) || null,
      subject: ((document.getElementById("emailSubject") || {}).value || "").trim(),
      body: ((document.getElementById("emailBody") || {}).value || "").trim(),
      audience: audience,
      recipients: ((document.getElementById("emailRecipients") || {}).value || "").trim()
    };
    if (audience === "user" || audience === "users") {
      body.user_ids = selectedUserIds.slice();
    }
    return body;
  };

  const currentAudience = function () {
    return (document.getElementById("emailAudience") || {}).value || "user";
  };

  const syncAudience = function () {
    const audience = currentAudience();
    const peopleWrap = document.querySelector("[data-email-people]");
    const typedWrap = document.querySelector("[data-email-recipients]");
    const hint = document.querySelector("[data-email-people-hint]");
    if (peopleWrap) peopleWrap.hidden = audience !== "user" && audience !== "users";
    if (typedWrap) typedWrap.hidden = audience !== "custom";
    if (hint) {
      hint.textContent = audience === "users"
        ? "Tick every house this circular should reach."
        : "Pick one person on the books.";
    }
    if (audience === "user" && selectedUserIds.length > 1) {
      selectedUserIds = selectedUserIds.slice(0, 1);
    }
    paintPeople();
  };

  const selectedPeople = function () {
    return people.filter(function (row) {
      return selectedUserIds.indexOf(Number(row.id)) !== -1;
    });
  };

  const togglePerson = function (id) {
    const audience = currentAudience();
    id = Number(id);
    if (!id) return;
    if (audience === "user") {
      selectedUserIds = [id];
    } else if (selectedUserIds.indexOf(id) !== -1) {
      selectedUserIds = selectedUserIds.filter(function (value) { return value !== id; });
    } else {
      selectedUserIds.push(id);
    }
    paintPeople();
  };

  const paintPeople = function () {
    const list = document.querySelector("[data-email-people-list]");
    const pills = document.querySelector("[data-email-pills]");
    const query = ((document.getElementById("emailPeopleSearch") || {}).value || "").trim().toLowerCase();
    if (pills) {
      const chosen = selectedPeople();
      pills.innerHTML = chosen.length ? chosen.map(function (row) {
        return '<span class="email-pill">' + escapeHtml(row.name)
          + ' <button type="button" class="ghost-btn" data-email-drop="' + escapeHtml(row.id) + '">Remove</button></span>';
      }).join("") : "";
    }
    if (!list) return;
    const rows = people.filter(function (row) {
      if (!query) return true;
      return (row.name + " " + row.email + " " + row.role).toLowerCase().indexOf(query) !== -1;
    });
    if (!people.length) {
      list.innerHTML = "<p>No mailboxes on the books yet.</p>";
      return;
    }
    if (!rows.length) {
      list.innerHTML = "<p>No one matches that search.</p>";
      return;
    }
    list.innerHTML = rows.map(function (row) {
      const active = selectedUserIds.indexOf(Number(row.id)) !== -1;
      return '<button type="button" class="email-person' + (active ? " is-active" : "") + '" data-user-id="'
        + escapeHtml(row.id) + '"><strong>' + escapeHtml(row.name) + "</strong><span>"
        + escapeHtml(row.role) + " · " + escapeHtml(row.email) + "</span></button>";
    }).join("");
  };

  const applyTemplate = function (row) {
    selectedId = row ? Number(row.id) : 0;
    const idField = document.getElementById("emailTemplateId");
    const subject = document.getElementById("emailSubject");
    const body = document.getElementById("emailBody");
    if (idField) idField.value = row ? String(row.id) : "";
    if (subject) subject.value = row ? row.subject : "";
    if (body) body.value = row ? row.body : "";
    if (formTitle) formTitle.textContent = row ? row.name : "Compose a letter";
    setFormNotice("");
    syncAudience();
    paintTemplates();
  };

  const paintTemplates = function () {
    const list = document.querySelector("[data-email-templates]");
    if (!list) return;
    if (!templates.length) {
      list.innerHTML = "<p>No templates on the desk. Use a blank letter.</p>";
      return;
    }
    list.innerHTML = templates.map(function (row) {
      const active = Number(row.id) === Number(selectedId);
      return '<article class="template-card' + (active ? " is-active" : "") + '" data-template-id="'
        + escapeHtml(row.id) + '"><p class="eyebrow">' + escapeHtml(audienceLabel(row.audience))
        + '</p><h3>' + escapeHtml(row.name) + "</h3><p>" + escapeHtml(row.subject) + "</p></article>";
    }).join("");
  };

  const paintOutbox = function (rows) {
    const list = document.querySelector("[data-email-outbox]");
    if (!list) return;
    if (!rows.length) {
      list.innerHTML = "<p>No letters have left the office yet.</p>";
      return;
    }
    list.innerHTML = rows.map(function (row) {
      const badge = row.status === "failed" ? "low" : (row.status === "partial" ? "warn" : "ok");
      return '<article class="ticket"><div class="ticket-code">POST-'
        + String(row.id).padStart(3, "0") + "</div><div><h3>" + escapeHtml(row.subject)
        + "</h3><p>" + escapeHtml(audienceLabel(row.audience)) + " · "
        + escapeHtml(row.sent_count) + " of " + escapeHtml(row.recipient_count)
        + "</p></div><span class=\"badge " + badge + "\">" + escapeHtml(row.status) + "</span></article>";
    }).join("");
  };

  const paintDesk = function (data) {
    const metrics = (data && data.metrics) || {};
    const from = (data && data.from) || {};
    setMetricValue("sent", metrics.sent_today == null ? "—" : metrics.sent_today);
    setMetricValue("templates", metrics.templates == null ? "—" : metrics.templates);
    setMetricValue("last", metrics.last_sent_at ? clockFromIso(metrics.last_sent_at) : "—");
    setMetricDelta("last", metrics.last_subject || "None yet");
    setMetricValue("from", from.address ? String(from.address).split("@")[0] : "—");
    setMetricDelta("from", from.address || "School mailbox");
    if (boardCopy) {
      boardCopy.textContent = templates.length
        ? "Choose a paper, then write on the right"
        : "No templates yet · write a blank letter";
    }
  };

  const loadDesk = function () {
    return Promise.all([
      request("/api/v1/email-center"),
      request("/api/v1/email-center/templates"),
      request("/api/v1/email-center/outbox"),
      request("/api/v1/email-center/people")
    ]).then(function (results) {
      if (!results[0].ok) {
        if (boardCopy) boardCopy.textContent = "The office could not open the post.";
        if (boardNotice) boardNotice.textContent = firstError(results[0].body);
        return;
      }
      if (results[1].ok) templates = results[1].body.data || [];
      if (results[3].ok) people = results[3].body.data || [];
      paintDesk(results[0].body.data || {});
      paintTemplates();
      paintPeople();
      if (results[2].ok) paintOutbox(results[2].body.data || []);
      if (boardNotice) boardNotice.textContent = "";
    });
  };

  const runPreview = function () {
    const body = payload();
    if (!body.subject || !body.body) {
      setFormNotice("A subject and letter are required.");
      return;
    }
    if (body.audience === "custom" && !body.recipients) {
      setFormNotice("Add at least one mailbox for a named dispatch.");
      return;
    }
    if ((body.audience === "user" || body.audience === "users") && !selectedUserIds.length) {
      setFormNotice(body.audience === "user"
        ? "Choose the person this letter is for."
        : "Choose at least one person on the books.");
      return;
    }
    setButtonState(previewBtn, true, "Sealing preview…");
    request("/api/v1/email-center/preview", {
      method: "POST",
      body: JSON.stringify(body)
    }).then(function (result) {
      setButtonState(previewBtn, false);
      if (!result.ok) {
        setFormNotice(firstError(result.body));
        return;
      }
      const data = result.body.data || {};
      if (iframe) iframe.srcdoc = data.html || "";
      if (previewCopy) {
        previewCopy.textContent = (data.recipient_count || 0) + " mailbox"
          + (data.recipient_count === 1 ? "" : "es")
          + (data.sample_name ? " · reads as " + data.sample_name : "");
      }
      setFormNotice("");
    }).catch(function () {
      setButtonState(previewBtn, false);
      setFormNotice("The office could not prepare that preview.");
    });
  };

  if (form) {
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      const body = payload();
      if (!body.subject || !body.body) {
        setFormNotice("A subject and letter are required.");
        return;
      }
      if (body.audience === "custom" && !body.recipients) {
        setFormNotice("Add at least one mailbox for a named dispatch.");
        return;
      }
      if ((body.audience === "user" || body.audience === "users") && !selectedUserIds.length) {
        setFormNotice(body.audience === "user"
          ? "Choose the person this letter is for."
          : "Choose at least one person on the books.");
        return;
      }
      const chosen = selectedPeople();
      const who = body.audience === "user" && chosen[0]
        ? chosen[0].name
        : (body.audience === "users"
          ? selectedUserIds.length + (selectedUserIds.length === 1 ? " person" : " people") + " on the books"
          : audienceLabel(body.audience));
      confirmDesk({
        title: "Dispatch this circular?",
        copy: "It will leave through the Hostinger mailbox to " + who + ".",
        confirmLabel: "Send it"
      }).then(function (ok) {
        if (!ok) return;
        setButtonState(sendBtn, true, "Dispatching…");
        request("/api/v1/email-center/send", {
          method: "POST",
          body: JSON.stringify(body)
        }).then(function (result) {
          setButtonState(sendBtn, false);
          if (!result.ok) {
            setFormNotice(firstError(result.body));
            return;
          }
          setFormNotice("");
          loadDesk();
          runPreview();
        }).catch(function () {
          setButtonState(sendBtn, false);
          setFormNotice("The office could not dispatch that letter.");
        });
      });
    });
  }

  if (previewBtn) previewBtn.addEventListener("click", runPreview);

  const audience = document.getElementById("emailAudience");
  if (audience) audience.addEventListener("change", syncAudience);

  const search = document.getElementById("emailPeopleSearch");
  if (search) search.addEventListener("input", paintPeople);

  const peopleRoot = document.querySelector("[data-email-people]");
  if (peopleRoot) {
    peopleRoot.addEventListener("click", function (event) {
      const drop = event.target.closest("[data-email-drop]");
      if (drop) {
        event.preventDefault();
        togglePerson(drop.getAttribute("data-email-drop"));
        return;
      }
      const person = event.target.closest("[data-user-id]");
      if (!person) return;
      event.preventDefault();
      togglePerson(person.getAttribute("data-user-id"));
    });
  }

  const blank = document.querySelector("[data-email-blank]");
  if (blank) {
    blank.addEventListener("click", function () {
      applyTemplate(null);
      if (iframe) iframe.srcdoc = "";
      if (previewCopy) previewCopy.textContent = "Press Preview to see the Hostinger letter";
    });
  }

  const templateList = document.querySelector("[data-email-templates]");
  if (templateList) {
    templateList.addEventListener("click", function (event) {
      const card = event.target.closest("[data-template-id]");
      if (!card) return;
      const row = templates.find(function (item) {
        return Number(item.id) === Number(card.getAttribute("data-template-id"));
      });
      if (row) applyTemplate(row);
    });
  }

  syncAudience();
  loadDesk().then(function () {
    if (templates[0]) applyTemplate(templates[0]);
  });
})();
