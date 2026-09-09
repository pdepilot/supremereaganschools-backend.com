(function () {
  if (window.SrsDeskBell) return;

  const POLL_MS = 12000;
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

    if (options && options.body && typeof options.body === "string" && !headers["Content-Type"]) {
      headers["Content-Type"] = "application/json";
    }

    return fetch(url, Object.assign({ credentials: "same-origin" }, options, { headers })).then(function (response) {
      if (response.status === 401) {
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

  const escapeHtml = function (value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  };

  const deskArea = function () {
    const path = String(window.location.pathname || "");
    if (path.indexOf("/portal") === 0) return "admin";
    if (path.indexOf("/staff") === 0) return "staff";
    if (path.indexOf("/parent") === 0) return "parent";
    if (path.indexOf("/student") === 0) return "student";
    const page = document.body.getAttribute("data-page") || "";
    if (page.indexOf("student") === 0) return "student";
    if (page.indexOf("parent") === 0) return "parent";
    if (document.body.classList.contains("pupil-house") && page.indexOf("student") === -1) return "parent";
    if (document.querySelector(".admin-house, .command-house") || document.body.querySelector("[data-page='dashboard']")) {
      return document.querySelector("link[href*='admin-command']") ? "admin" : "staff";
    }
    return "staff";
  };

  const routesFor = function (area) {
    const map = {
      admin: { messages: "/portal/messages", announcements: "/portal/announcements", home: "/portal/dashboard" },
      staff: { messages: "/staff/messages", announcements: "/staff/announcements", home: "/staff" },
      parent: { messages: "/parent/messages", announcements: "/parent/announcements", home: "/parent" },
      student: { messages: "/student/messages", announcements: "/student/announcements", home: "/student" }
    };
    return map[area] || map.staff;
  };

  const hrefForItem = function (item) {
    const routes = routesFor(deskArea());
    const kind = String((item && item.kind) || "");
    if (kind === "message") return routes.messages;
    if (kind === "announcement" || kind === "notice") return routes.announcements;
    return routes.home;
  };

  const relativeTime = function (iso) {
    if (!iso) return "";
    const then = new Date(iso).getTime();
    if (!Number.isFinite(then)) return "";
    const diff = Math.max(0, Date.now() - then);
    const mins = Math.floor(diff / 60000);
    if (mins < 1) return "just now";
    if (mins < 60) return mins + "m ago";
    const hours = Math.floor(mins / 60);
    if (hours < 24) return hours + "h ago";
    const days = Math.floor(hours / 24);
    return days + "d ago";
  };

  let root = null;
  let panel = null;
  let badge = null;
  let open = false;
  let timer = null;
  let items = [];
  let unread = 0;

  const renderList = function () {
    if (!panel) return;
    const list = panel.querySelector("[data-bell-list]");
    if (!list) return;
    if (!items.length) {
      list.innerHTML = '<p class="desk-bell-empty">No notices yet.</p>';
      return;
    }
    list.innerHTML = items.map(function (item) {
      const unreadClass = item.read_at ? "" : " is-unread";
      return (
        '<button type="button" class="desk-bell-item' + unreadClass + '" data-bell-id="' + escapeHtml(item.id) + '">' +
          '<strong>' + escapeHtml(item.title || "Notice") + "</strong>" +
          '<span>' + escapeHtml(item.body || "") + "</span>" +
          '<em>' + escapeHtml(relativeTime(item.created_at)) + "</em>" +
        "</button>"
      );
    }).join("");
  };

  const setUnread = function (count) {
    unread = Number(count) || 0;
    if (!badge) return;
    if (unread > 0) {
      badge.hidden = false;
      badge.textContent = unread > 99 ? "99+" : String(unread);
    } else {
      badge.hidden = true;
      badge.textContent = "";
    }
    document.querySelectorAll(".notification-badge").forEach(function (node) {
      node.textContent = unread > 0 ? String(unread) : "";
      node.style.display = unread > 0 ? "" : "none";
    });
    document.querySelectorAll(".notification-dot").forEach(function (node) {
      node.style.display = unread > 0 ? "" : "none";
    });
  };

  const refresh = function () {
    return request("/api/v1/notifications").then(function (result) {
      if (!result.ok) return;
      const data = result.body.data || {};
      items = data.items || [];
      setUnread(data.unread_count || 0);
      renderList();
    });
  };

  const markRead = function (id) {
    if (!id) return Promise.resolve();
    return request("/api/v1/notifications/" + encodeURIComponent(id) + "/read", { method: "POST" });
  };

  const markAll = function () {
    return request("/api/v1/notifications/read-all", { method: "POST" }).then(function () {
      return refresh();
    });
  };

  const setOpen = function (next) {
    open = !!next;
    if (!root || !panel) return;
    root.classList.toggle("is-open", open);
    if (open) panel.removeAttribute("hidden");
    else panel.setAttribute("hidden", "hidden");
    const button = root.querySelector("[data-bell-toggle]");
    if (button) button.setAttribute("aria-expanded", open ? "true" : "false");
    if (open) refresh();
  };

  const mountHost = function () {
    const existing = document.querySelector("[data-desk-bell]");
    if (existing) return existing;

    const aside = document.querySelector(".stage-head .head-aside");
    const headTop = document.querySelector(".stage-head .head-top, .hero .hero-top");
    const stageHead = document.querySelector(".stage-head, .hero");
    const hostParent = aside || headTop || stageHead;
    if (!hostParent) return null;

    const wrap = document.createElement("div");
    wrap.className = "desk-bell";
    wrap.setAttribute("data-desk-bell", "1");
    wrap.innerHTML =
      '<button type="button" class="desk-bell-toggle" data-bell-toggle aria-haspopup="true" aria-expanded="false" title="Notifications" aria-label="Notifications">' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
          '<path d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 1 0-12 0v3.2a2 2 0 0 1-.6 1.4L4 17h5"></path>' +
          '<path d="M9.5 17a2.5 2.5 0 0 0 5 0"></path>' +
        "</svg>" +
        '<span class="desk-bell-badge" data-bell-badge hidden></span>' +
      "</button>" +
      '<div class="desk-bell-panel" data-bell-panel hidden>' +
        '<div class="desk-bell-head">' +
          "<strong>Notifications</strong>" +
          '<button type="button" class="desk-bell-mark" data-bell-mark-all>Mark all read</button>' +
        "</div>" +
        '<div class="desk-bell-list" data-bell-list></div>' +
      "</div>";

    if (aside) {
      aside.insertBefore(wrap, aside.firstChild);
    } else if (headTop) {
      const live = headTop.querySelector(".live-pill");
      if (live && live.parentElement === headTop) {
        const cluster = document.createElement("div");
        cluster.className = "head-aside desk-bell-cluster";
        live.replaceWith(cluster);
        cluster.appendChild(wrap);
        cluster.appendChild(live);
      } else {
        const action = headTop.querySelector(".gold-btn, .solid-btn, .head-actions");
        if (action && action.parentElement === headTop) {
          headTop.insertBefore(wrap, action);
        } else {
          headTop.appendChild(wrap);
        }
      }
    } else {
      hostParent.appendChild(wrap);
    }

    return wrap;
  };

  const wire = function () {
    root = mountHost();
    if (!root) return false;

    badge = root.querySelector("[data-bell-badge]");
    panel = root.querySelector("[data-bell-panel]");

    const toggle = root.querySelector("[data-bell-toggle]");
    if (toggle) {
      toggle.addEventListener("click", function (event) {
        event.preventDefault();
        event.stopPropagation();
        setOpen(!open);
      });
    }

    const markAllBtn = root.querySelector("[data-bell-mark-all]");
    if (markAllBtn) {
      markAllBtn.addEventListener("click", function (event) {
        event.preventDefault();
        markAll();
      });
    }

    root.addEventListener("click", function (event) {
      const itemBtn = event.target.closest("[data-bell-id]");
      if (!itemBtn) return;
      event.preventDefault();
      const id = itemBtn.getAttribute("data-bell-id");
      const item = items.find(function (row) { return String(row.id) === String(id); });
      markRead(id).then(function () {
        return refresh();
      }).then(function () {
        setOpen(false);
        if (item) {
          window.location.href = hrefForItem(item);
        }
      });
    });

    document.addEventListener("click", function (event) {
      if (!open || !root) return;
      if (root.contains(event.target)) return;
      setOpen(false);
    });

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && open) setOpen(false);
    });

    return true;
  };

  const start = function () {
    if (!document.querySelector(".stage-head, .hero")) return;
    if (!wire()) return;
    refresh();
    if (timer) window.clearInterval(timer);
    timer = window.setInterval(refresh, POLL_MS);
    document.addEventListener("visibilitychange", function () {
      if (!document.hidden) refresh();
    });
  };

  window.SrsDeskBell = {
    mount: start,
    refresh: refresh
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start);
  } else {
    start();
  }
})();
