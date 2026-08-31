(function () {
  if (document.body.getAttribute("data-page") !== "parent-desk") return;

  const csrfToken = function () {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : "";
  };

  const loginHref = (window.srsLoginPath && window.srsLoginPath()) || "/parent/login";
  const CHILD_KEY = "srs.family.childId";
  const DESK_POLL_MS = 8000;

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

  const escapeHtml = function (value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  };

  const setText = function (selector, value) {
    document.querySelectorAll(selector).forEach(function (node) {
      node.textContent = value == null || value === "" ? "—" : String(value);
    });
  };

  const applyGreeting = function (name) {
    const greeting = document.querySelector("[data-greeting]");
    if (!greeting) return;
    const hour = Number(new Date().toLocaleString("en-GB", {
      timeZone: "Africa/Lagos",
      hour: "numeric",
      hour12: false
    }));
    const part = hour < 12 ? "morning" : hour < 16 ? "afternoon" : "evening";
    greeting.textContent = name ? "Good " + part + ", " + name + "." : "Good " + part + ".";
  };

  const tickClock = function () {
    const node = document.querySelector("[data-clock]");
    if (!node) return;
    node.textContent = new Date().toLocaleTimeString("en-GB", {
      timeZone: "Africa/Lagos",
      hour12: false
    });
  };

  const paintAvatar = function (selector, initials, photoUrl) {
    document.querySelectorAll(selector).forEach(function (node) {
      if (photoUrl) {
        node.innerHTML = '<img src="' + escapeHtml(photoUrl) + '" alt="">';
        return;
      }
      node.textContent = initials || "—";
    });
  };

  const pretty = function (value) {
    if (!value) return "";
    return String(value).replace(/_/g, " ").replace(/\b\w/g, function (letter) {
      return letter.toUpperCase();
    });
  };

  const paintChildren = function (rows) {
    const root = document.querySelector("[data-children]");
    if (!root) return;
    if (!rows || !rows.length) {
      root.innerHTML = '<p class="empty">No child is sealed to this household yet.</p>';
      return;
    }
    root.innerHTML = rows.map(function (row) {
      const crest = row.photo_url
        ? '<img src="' + escapeHtml(row.photo_url) + '" alt="">'
        : escapeHtml(row.initials || "—");
      return '<a class="form-card pupil-child" href="/parent/profile" data-open-child="' + escapeHtml(row.id) + '">'
        + '<span class="mark">' + crest + "</span>"
        + "<div><strong>" + escapeHtml(row.full_name || "Pupil") + "</strong>"
        + "<span>" + escapeHtml([row.admission_number, row.form, row.campus, pretty(row.relationship)].filter(Boolean).join(" · ") || "On the roll")
        + "</span></div></a>";
    }).join("");
  };

  const setNote = function (message) {
    const node = document.querySelector("[data-desk-note]");
    if (!node) return;
    node.hidden = !message;
    node.textContent = message || "";
  };

  const paint = function (data) {
    const metrics = data.metrics || {};
    applyGreeting(data.name);
    setText("[data-school]", data.school);
    setText("[data-date]", data.date_label);
    setText("[data-full-name]", data.full_name);
    setText("[data-session]", data.session);
    setText("[data-term]", data.term);
    setText("[data-session-line]", [data.session, data.term].filter(Boolean).join(" · ") || "Family house");
    setText("[data-title-line]", metrics.children === 1 ? "1 child on this desk" : (metrics.children || 0) + " children on this desk");
    setText('[data-metric="children"]', metrics.children);
    paintAvatar("[data-hero-avatar]", data.initials, null);

    const logo = document.querySelector("[data-school-logo]");
    if (logo && data.logo_path) logo.setAttribute("src", data.logo_path);

    if (data.full_name && data.school) {
      document.title = data.full_name + " | " + data.school;
    }

    paintChildren(data.children);
  };

  let pollTimer = null;

  const load = function () {
    return request("/api/v1/parent-desk").then(function (result) {
      if (!result.ok || !result.body || !result.body.data) {
        setNote("The family desk could not read the school ledger.");
        return;
      }
      setNote("");
      paint(result.body.data);
    }).catch(function () {
      setNote("The family desk could not read the school ledger.");
    });
  };

  const startPoll = function () {
    window.clearInterval(pollTimer);
    pollTimer = window.setInterval(load, DESK_POLL_MS);
  };

  document.addEventListener("visibilitychange", function () {
    if (document.hidden) {
      window.clearInterval(pollTimer);
      return;
    }
    load();
    startPoll();
  });

  document.addEventListener("click", function (event) {
    const link = event.target.closest("[data-open-child]");
    if (!link) return;
    try {
      sessionStorage.setItem(CHILD_KEY, String(link.getAttribute("data-open-child") || ""));
    } catch (error) {}
  });

  tickClock();
  window.setInterval(tickClock, 1000);
  load().then(startPoll);
})();
