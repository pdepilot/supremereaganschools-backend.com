(function () {
  if (document.body.getAttribute("data-page") !== "student-desk") return;

  const csrfToken = function () {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : "";
  };

  const loginHref = (window.srsLoginPath && window.srsLoginPath()) || "/student/login";
  const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  const DESK_POLL_MS = 8000;
  const DIAL = 289;

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

  const setDial = function (percent) {
    const ring = document.querySelector("[data-dial]");
    if (!ring) return;
    const number = Number(percent);
    const ratio = Number.isFinite(number) ? Math.max(0, Math.min(100, number)) / 100 : 0;
    ring.style.strokeDashoffset = String(DIAL * (1 - ratio));
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

  const splitTime = function (value) {
    const parts = String(value || "").trim().split(/\s+/);
    return { hour: parts[0] || "—", meridiem: parts[1] || "" };
  };

  const paintSchedule = function (rows) {
    const root = document.querySelector("[data-schedule]");
    if (!root) return;
    if (!rows || !rows.length) {
      root.innerHTML = '<li class="empty">No bells on the board for you today.</li>';
      return;
    }
    root.innerHTML = rows.map(function (row) {
      const status = row.status || "later";
      const start = splitTime(row.starts_at);
      return '<li class="bell is-' + escapeHtml(status) + '">'
        + '<div class="bell-time"><strong>' + escapeHtml(start.hour) + "</strong><span>" + escapeHtml(start.meridiem) + "</span></div>"
        + '<div class="bell-spine"><i></i></div>'
        + '<div class="bell-copy"><strong>' + escapeHtml(row.subject || "Period") + "</strong><span>"
        + escapeHtml([row.teacher, row.starts_at && row.ends_at ? row.starts_at + "–" + row.ends_at : ""].filter(Boolean).join(" · "))
        + "</span></div>"
        + '<span class="bell-status">' + escapeHtml(row.status_label || "") + "</span>"
        + "</li>";
    }).join("");
  };

  const paintAssignments = function (rows) {
    const root = document.querySelector("[data-assignments]");
    const count = document.querySelector("[data-task-count]");
    const open = (rows || []).filter(function (row) { return !row.overdue; });
    if (count) count.textContent = String(open.length);
    if (!root) return;
    if (!rows || !rows.length) {
      root.innerHTML = '<p class="empty">No assignments are waiting on the board.</p>';
      return;
    }
    root.innerHTML = rows.map(function (row) {
      return '<a class="task' + (row.overdue ? " is-overdue" : "") + '" href="/student/assignments">'
        + "<i></i><div><strong>" + escapeHtml(row.title || "Assignment") + "</strong><span>"
        + escapeHtml([row.subject, row.overdue ? "Overdue" : (row.due_label ? "Due " + row.due_label : "Class work")].filter(Boolean).join(" · "))
        + "</span></div></a>";
    }).join("");
  };

  const paintNotices = function (rows) {
    const root = document.querySelector("[data-notices]");
    if (!root) return;
    if (!rows || !rows.length) {
      root.innerHTML = '<p class="empty">No notices in the hall yet.</p>';
      return;
    }
    root.innerHTML = rows.map(function (row) {
      return '<article class="notice"><b>' + escapeHtml(row.when || "—") + "</b><div><strong>"
        + escapeHtml(row.title || "Notice") + "</strong><span>" + escapeHtml(row.excerpt || "") + "</span></div></article>";
    }).join("");
  };

  const paintDates = function (rows) {
    const root = document.querySelector("[data-dates]");
    if (!root) return;
    if (!rows || !rows.length) {
      root.innerHTML = '<p class="empty">No dates on the near calendar.</p>';
      return;
    }
    root.innerHTML = rows.slice(0, 4).map(function (row) {
      const due = String(row.due_on || "");
      const parts = due.split("-");
      const day = parts[2] ? String(Number(parts[2])) : "—";
      const months = ["", "Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
      const month = parts[1] ? (months[Number(parts[1])] || "") : "";
      return '<article class="date-card"><time><strong>' + escapeHtml(day) + "</strong><span>"
        + escapeHtml(month || row.due_label || "") + "</span></time><p><strong>"
        + escapeHtml(row.title || "Assignment") + "</strong><span>"
        + escapeHtml(row.subject || "Class work") + "</span></p></article>";
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
    setText("[data-form]", data.form || "Unplaced");
    setText("[data-admission]", data.admission_number);
    setText("[data-class-teacher]", data.class_teacher);
    setText("[data-campus]", data.campus);
    setText("[data-title-line]", [data.form || "Unplaced", data.admission_number].filter(Boolean).join(" · "));
    setText("[data-session-line]", [data.session, data.term].filter(Boolean).join(" · ") || "Pupil house");
    setText("[data-lede]", data.form
      ? data.form + (data.campus ? " · " + data.campus : "") + " — live from the school ledger."
      : "Your bells, work, and letters — drawn live from the school ledger.");
    paintAvatar("[data-hero-avatar]", data.initials, data.photo_url);

    const logo = document.querySelector("[data-school-logo]");
    if (logo && data.logo_path) logo.setAttribute("src", data.logo_path);

    if (data.full_name && data.school) {
      document.title = data.full_name + " | " + data.school;
    }

    if (metrics.average_percent == null) {
      setText('[data-metric="average"]', "—");
    } else {
      animateValue(document.querySelector('[data-metric="average"]'), metrics.average_percent, "", "%");
    }
    if (metrics.attendance_percent == null) {
      setText('[data-metric="attendance"]', "—");
    } else {
      animateValue(document.querySelector('[data-metric="attendance"]'), metrics.attendance_percent, "", "%");
    }
    setText('[data-metric="position"]', metrics.class_position_label);
    animateValue(document.querySelector('[data-metric="assignments"]'), metrics.assignments);
    animateValue(document.querySelector('[data-metric="letters"]'), metrics.letters);

    setText('[data-metric-delta="average"]', metrics.average_delta);
    setText('[data-metric-delta="position"]', metrics.position_delta);
    setText('[data-metric-delta="assignments"]', metrics.assignments_delta);
    setText('[data-metric-delta="letters"]', Number(metrics.letters) === 1 ? "Unread letter" : "Unread");
    const dialCopy = document.querySelector(".dial-core span");
    if (dialCopy) dialCopy.textContent = metrics.attendance_delta || "Your roll";

    setDial(metrics.attendance_percent);
    paintSchedule(data.schedule);
    paintAssignments(data.assignments);
    paintNotices(data.notices);
    paintDates(data.assignments);
  };

  let pollTimer = null;

  const load = function () {
    return request("/api/v1/student-desk").then(function (result) {
      if (!result.ok || !result.body || !result.body.data) {
        setNote("The pupil desk could not read the school ledger.");
        return;
      }
      setNote("");
      paint(result.body.data);
    }).catch(function () {
      setNote("The pupil desk could not read the school ledger.");
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

  tickClock();
  window.setInterval(tickClock, 1000);
  load().then(startPoll);
})();
