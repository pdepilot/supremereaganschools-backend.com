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

    if (options && options.body && !(options.body instanceof FormData) && !headers["Content-Type"] && typeof options.body === "string") {
      headers["Content-Type"] = "application/json";
    }

    return fetch(url, Object.assign({ credentials: "same-origin" }, options, { headers })).then(function (response) {
      if (response.status === 401) {
        window.location.replace("/portal/login");
      }
      return response.json().then(function (body) {
        return { ok: response.ok, status: response.status, body: body };
      }).catch(function () {
        return { ok: false, status: response.status, body: {} };
      });
    });
  };

  const firstError = function (body) {
    if (body && body.errors) {
      const keys = Object.keys(body.errors);
      if (keys.length) return body.errors[keys[0]][0];
    }
    return (body && body.message) || "The office could not complete that request.";
  };

  const escapeHtml = function (value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  };

  const $ = function (sel) { return document.querySelector(sel); };

  const confirmDesk = function (options) {
    const root = document.querySelector("[data-desk-alert]");
    if (!root) return Promise.resolve(window.confirm((options && options.copy) || "Confirm?"));

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
      if (confirmBtn) confirmBtn.focus();
    });
  };

  const toLocalInput = function (iso) {
    if (!iso) return "";
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return "";
    const pad = function (n) { return String(n).padStart(2, "0"); };
    return d.getFullYear() + "-" + pad(d.getMonth() + 1) + "-" + pad(d.getDate()) +
      "T" + pad(d.getHours()) + ":" + pad(d.getMinutes());
  };

  const formatWhen = function (iso) {
    if (!iso) return "—";
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return "—";
    return d.toLocaleString(undefined, {
      weekday: "short",
      day: "numeric",
      month: "short",
      year: "numeric",
      hour: "numeric",
      minute: "2-digit"
    });
  };

  const showImagePreview = function (src, alt) {
    const box = $("[data-event-image-preview]");
    const img = box && box.querySelector("img");
    if (!box || !img) return;
    if (!src) {
      box.hidden = true;
      img.removeAttribute("src");
      return;
    }
    img.src = src;
    img.alt = alt || "Cover image";
    box.hidden = false;
  };

  let eventsPage = 1;

  const renderPager = function (meta) {
    const el = $("[data-events-pager]");
    if (!el) return;
    const current = (meta && meta.current_page) || 1;
    const last = (meta && meta.last_page) || 1;
    const total = (meta && meta.total) || 0;
    const from = meta && meta.from;
    const to = meta && meta.to;
    eventsPage = current;
    if (!total) {
      el.innerHTML = "";
      return;
    }
    let buttons = "";
    if (current > 1) {
      buttons += '<button class="ghost-btn" type="button" data-events-page="' + (current - 1) + '">Previous</button>';
    }
    for (let i = 1; i <= last; i++) {
      buttons += '<button class="ghost-btn' + (i === current ? " is-current" : "") + '" type="button" data-events-page="' + i + '"' + (i === current ? ' aria-current="page"' : "") + ">" + i + "</button>";
    }
    if (current < last) {
      buttons += '<button class="ghost-btn" type="button" data-events-page="' + (current + 1) + '">Next</button>';
    }
    el.innerHTML = '<p class="events-desk-count">Showing ' + (from || 0) + "–" + (to || 0) + " of " + total + "</p>" +
      (last > 1 ? '<div class="events-desk-pages">' + buttons + "</div>" : "");
  };

  const resetForm = function () {
    const form = $("[data-event-form]");
    if (form) form.reset();
    const id = $("#eventId");
    if (id) id.value = "";
    const title = $("[data-event-form-title]");
    if (title) title.textContent = "Compose an event";
    const notice = $("[data-event-form-notice]");
    if (notice) notice.textContent = "";
    showImagePreview("", "");
  };

  const fillForm = function (row) {
    $("#eventId").value = row.id || "";
    $("#eventTitle").value = row.title || "";
    $("#eventCategory").value = row.category || "";
    $("#eventStatus").value = row.status || "draft";
    $("#eventStartsAt").value = toLocalInput(row.starts_at);
    $("#eventEndsAt").value = toLocalInput(row.ends_at);
    $("#eventLocation").value = row.location || "";
    $("#eventSummary").value = row.summary || "";
    $("#eventBody").value = row.body || "";
    $("#eventCoverAlt").value = row.cover_image_alt || "";
    $("#eventFeatured").checked = !!row.is_featured;
    $("#eventCover").value = "";
    showImagePreview(row.cover_image_url || row.cover_image || "", row.cover_image_alt || row.title || "");
    const title = $("[data-event-form-title]");
    if (title) title.textContent = "Edit event";
    const notice = $("[data-event-form-notice]");
    if (notice) notice.textContent = "";
    const compose = $("#compose-event");
    if (compose) compose.scrollIntoView({ behavior: "smooth", block: "start" });
  };

  const loadEvents = function (page) {
    if (page) eventsPage = page;
    const q = ($("#eventSearch") && $("#eventSearch").value) || "";
    const status = ($("#eventStatusFilter") && $("#eventStatusFilter").value) || "";
    const params = new URLSearchParams();
    if (q) params.set("q", q);
    if (status) params.set("status", status);
    params.set("page", String(eventsPage));

    return request("/api/v1/events?" + params.toString()).then(function (result) {
      const board = $("[data-events-board]");
      const copy = $("[data-events-copy]");
      const notice = $("[data-events-notice]");
      if (!result.ok) {
        if (board) board.innerHTML = "<p>" + escapeHtml(firstError(result.body)) + "</p>";
        return;
      }
      const data = result.body.data || {};
      const summary = data.summary || {};
      ["total", "published", "drafts", "upcoming", "featured"].forEach(function (key) {
        const el = document.querySelector('[data-metric="' + key + '"]');
        if (el) el.textContent = summary[key] == null ? "—" : summary[key];
      });
      if (copy) copy.textContent = (data.meta && data.meta.total != null) ? data.meta.total + " events on the desk" : "";
      if (notice) notice.textContent = "";
      renderPager(data.meta || {});
      const items = data.items || [];
      if (!board) return;
      if (!items.length) {
        board.innerHTML = "<p>No events yet. Compose one on the right.</p>";
        return;
      }
      board.innerHTML = items.map(function (row) {
        const photo = row.cover_image_url || row.cover_image;
        const photoHtml = photo
          ? '<img class="ticket-photo" src="' + escapeHtml(photo) + '" alt="">'
          : '<span class="ticket-photo is-empty">No image</span>';
        return '<article class="ticket">' +
          '<div class="ticket-head">' + photoHtml +
          '<div><h3>' + escapeHtml(row.title) + '</h3>' +
          '<p>' + escapeHtml(formatWhen(row.starts_at)) + (row.location ? " · " + escapeHtml(row.location) : "") + "</p>" +
          '<p><strong>' + escapeHtml(row.status || "draft") + '</strong>' +
          (row.is_featured ? " · featured" : "") +
          (row.category ? " · " + escapeHtml(row.category) : "") +
          "</p></div></div>" +
          '<p>' + escapeHtml(row.summary || "") + "</p>" +
          '<div class="row-actions">' +
          '<button class="ghost-btn" type="button" data-edit-event="' + row.id + '">Edit</button>' +
          '<button class="ghost-btn" type="button" data-delete-event="' + row.id + '">Delete</button>' +
          "</div></article>";
      }).join("");
    });
  };

  const collectPayload = function () {
    const fd = new FormData();
    fd.append("title", ($("#eventTitle") && $("#eventTitle").value) || "");
    fd.append("summary", ($("#eventSummary") && $("#eventSummary").value) || "");
    fd.append("body", ($("#eventBody") && $("#eventBody").value) || "");
    fd.append("category", ($("#eventCategory") && $("#eventCategory").value) || "");
    fd.append("starts_at", ($("#eventStartsAt") && $("#eventStartsAt").value) || "");
    const ends = ($("#eventEndsAt") && $("#eventEndsAt").value) || "";
    if (ends) fd.append("ends_at", ends);
    fd.append("location", ($("#eventLocation") && $("#eventLocation").value) || "");
    fd.append("status", ($("#eventStatus") && $("#eventStatus").value) || "draft");
    fd.append("is_featured", ($("#eventFeatured") && $("#eventFeatured").checked) ? "1" : "0");
    fd.append("cover_image_alt", ($("#eventCoverAlt") && $("#eventCoverAlt").value) || "");
    const file = $("#eventCover") && $("#eventCover").files && $("#eventCover").files[0];
    if (file) fd.append("cover_image", file);
    return fd;
  };

  const bind = function () {
    const form = $("[data-event-form]");
    if (form) {
      form.addEventListener("submit", function (event) {
        event.preventDefault();
        const notice = $("[data-event-form-notice]");
        const id = ($("#eventId") && $("#eventId").value) || "";
        const url = id ? "/api/v1/events/" + id : "/api/v1/events";
        const method = "POST";
        const body = collectPayload();
        if (id) body.append("_method", "PUT");
        request(url, { method: method, body: body }).then(function (result) {
          if (!result.ok) {
            if (notice) notice.textContent = firstError(result.body);
            return;
          }
          if (notice) notice.textContent = "Event saved.";
          resetForm();
          loadEvents(eventsPage);
        });
      });
    }

    const board = $("[data-events-board]");
    if (board) {
      board.addEventListener("click", function (event) {
        const edit = event.target.closest("[data-edit-event]");
        if (edit) {
          const id = edit.getAttribute("data-edit-event");
          request("/api/v1/events/" + id).then(function (result) {
            if (!result.ok) return;
            fillForm(result.body.data || {});
          });
          return;
        }
        const del = event.target.closest("[data-delete-event]");
        if (del) {
          const id = del.getAttribute("data-delete-event");
          confirmDesk({
            title: "Delete this event?",
            copy: "It will leave the public calendar. This cannot be undone from the desk.",
            confirmLabel: "Delete",
            danger: true
          }).then(function (ok) {
            if (!ok) return;
            request("/api/v1/events/" + id, { method: "DELETE" }).then(function (result) {
              const notice = $("[data-events-notice]");
              if (!result.ok) {
                if (notice) notice.textContent = firstError(result.body);
                return;
              }
              if (notice) notice.textContent = "Event deleted.";
              if (($("#eventId") && $("#eventId").value) === id) resetForm();
              loadEvents(eventsPage);
            });
          });
        }
      });
    }

    const pager = $("[data-events-pager]");
    if (pager) {
      pager.addEventListener("click", function (event) {
        const btn = event.target.closest("[data-events-page]");
        if (!btn) return;
        loadEvents(Number(btn.getAttribute("data-events-page")));
      });
    }

    const search = $("#eventSearch");
    if (search) {
      let timer;
      search.addEventListener("input", function () {
        clearTimeout(timer);
        timer = setTimeout(function () { loadEvents(1); }, 280);
      });
    }
    const filter = $("#eventStatusFilter");
    if (filter) filter.addEventListener("change", function () { loadEvents(1); });

    const neu = $("[data-new-event]");
    if (neu) neu.addEventListener("click", function () {
      resetForm();
      const compose = $("#compose-event");
      if (compose) compose.scrollIntoView({ behavior: "smooth", block: "start" });
    });

    const reset = $("[data-reset-event]");
    if (reset) reset.addEventListener("click", resetForm);

    const cover = $("#eventCover");
    if (cover) {
      cover.addEventListener("change", function () {
        const file = cover.files && cover.files[0];
        if (!file) return;
        const url = URL.createObjectURL(file);
        showImagePreview(url, file.name);
      });
    }
  };

  bind();
  loadEvents(1);
})();
