(function () {
  if (document.body.getAttribute("data-page") !== "messages") return;

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

  const INBOX_POLL_MS = 8000;

  const relative = function (value) {
    if (!value) return "";
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return "";
    const delta = Date.now() - date.getTime();
    const minutes = Math.round(delta / 60000);
    if (minutes < 1) return "Just now";
    if (minutes < 60) return minutes + " min";
    const hours = Math.round(minutes / 60);
    if (hours < 24) return hours + " hr";
    const days = Math.round(hours / 24);
    if (days === 1) return "Yesterday";
    return days + " days";
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

  const itemKey = function (item) {
    if (!item) return "";
    return String(item.kind || "") + ":" + String(item.id || "");
  };

  const kindLabel = function (value) {
    if (value === "application") return "Admission application";
    return "Enquiry";
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

  const isRead = function (item, cleared) {
    if (cleared) return true;
    const status = item && item.status;
    return status === "read"
      || status === "cleared"
      || status === "under_review"
      || status === "exam_scheduled"
      || status === "offered"
      || status === "admitted"
      || status === "rejected"
      || status === "withdrawn";
  };

  const findItem = function (data, kind, id) {
    const lanes = [data.urgent, data.watch, data.cleared];
    for (let i = 0; i < lanes.length; i += 1) {
      const hit = (lanes[i] || []).find(function (row) {
        return String(row.kind) === String(kind) && String(row.id) === String(id);
      });
      if (hit) return hit;
    }
    return null;
  };

  const wireInbox = function () {
    const urgentLane = document.querySelector("[data-inbox-urgent]");
    const watchLane = document.querySelector("[data-inbox-watch]");
    const clearedLane = document.querySelector("[data-inbox-cleared]");
    if (!urgentLane || !watchLane || !clearedLane) return;

    const copy = document.querySelector("[data-inbox-copy]");
    const notice = document.querySelector("[data-inbox-notice]");
    const clearUrgentBtn = document.querySelector("[data-inbox-clear-urgent]");
    const clearLetterBtn = document.querySelector("[data-inbox-clear-letter]");
    const letterEmpty = document.querySelector("[data-inbox-letter-empty]");
    const letterSheet = document.querySelector("[data-inbox-letter-sheet]");
    const letterName = document.querySelector("[data-inbox-letter-name]");
    const letterSubject = document.querySelector("[data-inbox-letter-subject]");

    let lastData = { urgent: [], watch: [], cleared: [], summary: {} };
    let selected = null;
    let pollTimer = null;

    const setNotice = function (message) {
      if (notice) notice.textContent = message || "";
    };

    const setMetric = function (key, value) {
      const node = document.querySelector('[data-metric="' + key + '"]');
      if (node) node.textContent = value;
    };

    const setCount = function (key, value) {
      const node = document.querySelector('[data-inbox-count="' + key + '"]');
      if (!node) return;
      const n = Number(value) || 0;
      node.textContent = n === 1 ? "1 letter" : n + " letters";
    };

    const renderLane = function (container, items, cleared) {
      if (!container) return;
      if (!items || !items.length) {
        container.innerHTML = "<p>No letters in this lane.</p>";
        return;
      }
      container.innerHTML = items.map(function (item) {
        const key = itemKey(item);
        const klass = "msg-item"
          + (isRead(item, cleared) ? " read" : "")
          + (selected && itemKey(selected) === key ? " is-active" : "");
        const metaParts = [];
        if (cleared) metaParts.push("Cleared");
        metaParts.push(relative(item.created_at) || statusLabel(item.status));
        if (item.kind === "application") metaParts.push("Application");
        return '<article class="' + klass + '" data-kind="' + escapeHtml(item.kind)
          + '" data-id="' + escapeHtml(item.id) + '"><i></i><div><h3>'
          + escapeHtml(item.name || "—") + "</h3><p>"
          + escapeHtml(excerpt(item.preview || item.subject || "", 90))
          + '</p><div class="msg-meta">' + escapeHtml(metaParts.join(" · "))
          + "</div></div></article>";
      }).join("");
    };

    const resetLetter = function () {
      selected = null;
      if (letterName) letterName.textContent = "Open a letter";
      if (letterSubject) letterSubject.textContent = "Click a name on the chute to read it here.";
      if (letterEmpty) letterEmpty.hidden = false;
      if (letterSheet) letterSheet.hidden = true;
      if (clearLetterBtn) clearLetterBtn.hidden = true;
    };

    const paintLetter = function (item) {
      selected = item;
      if (!item) {
        resetLetter();
        return;
      }

      if (letterName) letterName.textContent = item.name || "—";
      if (letterSubject) {
        const bits = [item.subject || kindLabel(item.kind)];
        if (item.reference) bits.push(item.reference);
        letterSubject.textContent = bits.join(" · ");
      }
      const kindNode = document.querySelector("[data-inbox-letter-kind]");
      const statusNode = document.querySelector("[data-inbox-letter-status]");
      const emailNode = document.querySelector("[data-inbox-letter-email]");
      const phoneNode = document.querySelector("[data-inbox-letter-phone]");
      const timeNode = document.querySelector("[data-inbox-letter-time]");
      const bodyNode = document.querySelector("[data-inbox-letter-body]");

      if (kindNode) kindNode.textContent = kindLabel(item.kind);
      if (statusNode) statusNode.textContent = statusLabel(item.status);
      if (emailNode) {
        emailNode.innerHTML = item.email
          ? '<a href="mailto:' + escapeHtml(item.email) + '">' + escapeHtml(item.email) + "</a>"
          : "—";
      }
      if (phoneNode) {
        phoneNode.innerHTML = item.phone
          ? '<a href="tel:' + escapeHtml(item.phone) + '">' + escapeHtml(item.phone) + "</a>"
          : "—";
      }
      if (timeNode) timeNode.textContent = clockStamp(item.created_at);
      if (bodyNode) bodyNode.textContent = item.preview || "No message was left with this letter.";

      if (letterEmpty) letterEmpty.hidden = true;
      if (letterSheet) letterSheet.hidden = false;
      if (clearLetterBtn) {
        clearLetterBtn.hidden = !(item.kind === "enquiry" && item.status !== "cleared");
      }
    };

    const render = function (data) {
      lastData = data || { urgent: [], watch: [], cleared: [], summary: {} };
      const summary = lastData.summary || {};
      renderLane(urgentLane, lastData.urgent, false);
      renderLane(watchLane, lastData.watch, false);
      renderLane(clearedLane, lastData.cleared, true);
      setMetric("unread", summary.unread != null ? summary.unread : 0);
      setMetric("urgent", summary.urgent != null ? summary.urgent : 0);
      setMetric("watch", summary.watch != null ? summary.watch : 0);
      setMetric("cleared", summary.cleared_today != null ? summary.cleared_today : 0);
      setCount("urgent", (lastData.urgent || []).length);
      setCount("watch", (lastData.watch || []).length);
      setCount("cleared", (lastData.cleared || []).length);

      const total = (lastData.urgent || []).length
        + (lastData.watch || []).length
        + (lastData.cleared || []).length;
      if (copy) {
        copy.textContent = total === 0
          ? "The chute is empty."
          : (total === 1 ? "1 letter on the desk." : total + " letters on the desk.");
      }

      if (selected) {
        const fresh = findItem(lastData, selected.kind, selected.id);
        if (fresh) paintLetter(fresh);
        else resetLetter();
      }
    };

    const load = function () {
      return request("/api/v1/inbox").then(function (result) {
        if (!result.ok) {
          if (copy) copy.textContent = firstError(result.body);
          setNotice(firstError(result.body));
          return;
        }
        setNotice("");
        render(result.body.data || {});
      });
    };

    const openLetter = function (kind, id) {
      const cached = findItem(lastData, kind, id);
      if (cached) paintLetter(cached);

      return request("/api/v1/inbox/open", {
        method: "POST",
        body: JSON.stringify({ kind: kind, id: Number(id) })
      }).then(function (result) {
        if (!result.ok) {
          setNotice(firstError(result.body));
          return;
        }
        setNotice("");
        render(result.body.data || {});
      });
    };

    document.addEventListener("click", function (event) {
      const item = event.target.closest(".msg-item[data-kind]");
      if (!item || !item.closest("[data-inbox-urgent], [data-inbox-watch], [data-inbox-cleared]")) return;
      openLetter(item.getAttribute("data-kind"), item.getAttribute("data-id"));
    });

    if (clearUrgentBtn) {
      clearUrgentBtn.addEventListener("click", function () {
        confirmDesk({
          title: "Clear the urgent lane?",
          copy: "Admission and fee enquiries marked urgent will move to the cleared lane. Admission applications stay until the office decides.",
          confirmLabel: "Clear them",
          cancelLabel: "Keep them"
        }).then(function (ok) {
          if (!ok) return;
          setButtonState(clearUrgentBtn, true, "Clearing…");
          request("/api/v1/inbox/clear-urgent", {
            method: "POST",
            body: JSON.stringify({})
          }).then(function (result) {
            setButtonState(clearUrgentBtn, false);
            if (!result.ok) {
              setNotice(firstError(result.body));
              return;
            }
            setNotice("");
            render(result.body.data || {});
          });
        });
      });
    }

    if (clearLetterBtn) {
      clearLetterBtn.addEventListener("click", function () {
        if (!selected || selected.kind !== "enquiry") return;
        confirmDesk({
          title: "Clear this letter?",
          copy: "This enquiry will leave the chute as handled.",
          confirmLabel: "Clear it",
          cancelLabel: "Keep it"
        }).then(function (ok) {
          if (!ok) return;
          setButtonState(clearLetterBtn, true, "Clearing…");
          request("/api/v1/contact-enquiries/" + selected.id, {
            method: "PUT",
            body: JSON.stringify({ status: "cleared" })
          }).then(function (result) {
            setButtonState(clearLetterBtn, false);
            if (!result.ok) {
              setNotice(firstError(result.body));
              return;
            }
            setNotice("");
            return load();
          });
        });
      });
    }

    const startLiveChute = function () {
      if (pollTimer) window.clearInterval(pollTimer);
      pollTimer = window.setInterval(function () {
        if (document.hidden) return;
        if (document.body.classList.contains("desk-alert-open")) return;
        load();
      }, INBOX_POLL_MS);
      document.addEventListener("visibilitychange", function () {
        if (!document.hidden) load();
      });
      window.addEventListener("pageshow", function () {
        load();
      });
    };

    load();
    startLiveChute();
  };

  wireInbox();
})();
