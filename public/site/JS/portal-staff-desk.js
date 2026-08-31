(function () {
  if (document.body.getAttribute("data-page") !== "staff-desk") return;

  const csrfToken = function () {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : "";
  };

  const loginHref = (window.srsLoginPath && window.srsLoginPath()) || "/staff/login";
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
    const node = document.querySelector(selector);
    if (node) node.textContent = value == null || value === "" ? "—" : String(value);
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

  const paintWeek = function (days) {
    const root = document.querySelector("[data-week]");
    if (!root) return;
    if (!days || !days.length) {
      root.innerHTML = "";
      return;
    }
    root.innerHTML = days.map(function (day) {
      const percent = Number(day.percent);
      const height = Number.isFinite(percent) ? Math.max(8, percent) : 8;
      const classes = ["week-day"];
      if (day.today) classes.push("is-today");
      if (day.future) classes.push("is-future");
      return '<div class="' + classes.join(" ") + '">' +
        "<span>" + escapeHtml(day.label) + "</span>" +
        '<div class="week-bar"><i style="height:' + height + '%"></i></div>' +
        "</div>";
    }).join("");
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
      return '<li class="bell is-' + escapeHtml(status) + '">' +
        '<div class="bell-time"><strong>' + escapeHtml(row.hour) + "</strong><span>" + escapeHtml(row.meridiem) + "</span></div>" +
        '<div class="bell-spine"><i></i></div>' +
        '<div class="bell-copy"><strong>' + escapeHtml(row.subject) + "</strong><span>" +
        escapeHtml([row.form, row.starts_at && row.ends_at ? row.starts_at + "–" + row.ends_at : ""].filter(Boolean).join(" · ")) +
        "</span></div>" +
        '<span class="bell-status">' + escapeHtml(row.status_label || "") + "</span>" +
        "</li>";
    }).join("");
  };

  const paintTasks = function (rows) {
    const root = document.querySelector("[data-tasks]");
    const count = document.querySelector("[data-task-count]");
    const open = (rows || []).filter(function (row) { return !row.done; }).length;
    if (count) count.textContent = String(open);
    if (!root) return;
    if (!rows || !rows.length) {
      root.innerHTML = '<p class="empty">The house is quiet. Nothing needs you yet.</p>';
      return;
    }
    root.innerHTML = rows.map(function (row) {
      return '<a class="task' + (row.done ? " is-done" : "") + '" href="' + escapeHtml(row.href || "/staff") + '">' +
        "<i></i><div><strong>" + escapeHtml(row.title) + "</strong><span>" + escapeHtml(row.detail || "") + "</span></div></a>";
    }).join("");
  };

  const paintForms = function (forms) {
    const ribbon = document.querySelector("[data-forms-ribbon]");
    const grid = document.querySelector("[data-forms]");
    if (ribbon) {
      if (!forms || !forms.length) {
        ribbon.hidden = true;
        ribbon.innerHTML = "";
      } else {
        ribbon.hidden = false;
        ribbon.innerHTML = forms.map(function (form) {
          return '<a class="form-chip" href="/staff/students">' + escapeHtml(form.name) +
            (form.is_class_teacher ? " <em>house</em>" : "") + "</a>";
        }).join("");
      }
    }
    if (!grid) return;
    if (!forms || !forms.length) {
      grid.innerHTML = '<p class="empty">No form is assigned to this desk yet.</p>';
      return;
    }
    grid.innerHTML = forms.map(function (form) {
      return '<div class="form-card"><strong>' + escapeHtml(form.name) + "</strong><span>" +
        escapeHtml(form.pupils + (form.pupils === 1 ? " pupil" : " pupils")) +
        (form.is_class_teacher ? " · class teacher" : "") +
        "</span></div>";
    }).join("");
  };

  const paintHouse = function (house) {
    if (!house) {
      setText("[data-house-name]", "Forms");
      setText("[data-house-pupils]", "—");
      setText("[data-house-present]", "—");
      setText("[data-house-work]", "—");
      return;
    }
    setText("[data-house-name]", house.name || "Forms");
    setText("[data-house-pupils]", house.pupils);
    setText("[data-house-present]", house.present);
    setText("[data-house-work]", house.assignments);
  };

  const paintNotices = function (rows) {
    const root = document.querySelector("[data-notices]");
    if (!root) return;
    if (!rows || !rows.length) {
      root.innerHTML = '<p class="empty">No notices in the hall yet.</p>';
      return;
    }
    root.innerHTML = rows.map(function (row) {
      return '<article class="notice"><b>' + escapeHtml(row.when || "—") + "</b><div><strong>" +
        escapeHtml(row.title) + "</strong><span>" + escapeHtml(row.excerpt || "") + "</span></div></article>";
    }).join("");
  };

  const paintDates = function (rows) {
    const root = document.querySelector("[data-dates]");
    if (!root) return;
    if (!rows || !rows.length) {
      root.innerHTML = '<p class="empty">No dates on the near calendar.</p>';
      return;
    }
    root.innerHTML = rows.map(function (row) {
      return '<article class="date-card"><time><strong>' + escapeHtml(row.day) + "</strong><span>" +
        escapeHtml(row.month) + "</span></time><p><strong>" + escapeHtml(row.title) +
        "</strong><span>" + escapeHtml(row.detail || "") + "</span></p></article>";
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
    setText("[data-initials]", data.initials);
    setText("[data-title-line]", [data.title, data.staff_number].filter(Boolean).join(" · "));
    setText("[data-session-line]", [data.session, data.term].filter(Boolean).join(" · ") || "Faculty house");
    setText("[data-lede]", data.department
      ? data.department + " · live from the school ledger."
      : "Your forms, bells, and letters — drawn live from the school ledger.");

    animateValue(document.querySelector('[data-metric="pupils"]'), metrics.pupils);
    animateValue(document.querySelector('[data-metric="assignments"]'), metrics.assignments);
    animateValue(document.querySelector('[data-metric="letters"]'), metrics.letters);
    if (metrics.attendance_percent == null) {
      setText('[data-metric="attendance"]', "—");
    } else {
      animateValue(document.querySelector('[data-metric="attendance"]'), metrics.attendance_percent, "", "%");
    }
    if (metrics.average_percent == null) {
      setText('[data-metric="average"]', "—");
    } else {
      animateValue(document.querySelector('[data-metric="average"]'), metrics.average_percent, "", "%");
    }

    setText('[data-metric-delta="pupils"]', metrics.pupils_delta);
    setText('[data-metric-delta="assignments"]', metrics.assignments_delta);
    setText('[data-metric-delta="average"]', metrics.average_delta);
    setText('[data-metric-delta="letters"]', metrics.letters === 1 ? "Unread letter" : "Unread");
    const attendanceDelta = document.querySelector('[data-metric="attendance"]') && document.querySelector(".dial-core span");
    if (attendanceDelta) attendanceDelta.textContent = metrics.attendance_delta || "Today’s roll";

    setDial(metrics.attendance_percent);
    paintWeek(data.week);
    paintSchedule(data.schedule);
    paintTasks(data.tasks);
    paintForms(data.forms);
    paintHouse(data.house);
    paintNotices(data.notices);
    paintDates(data.dates);
  };

  let pollTimer = null;

  const load = function () {
    return request("/api/v1/staff-desk").then(function (result) {
      if (!result.ok || !result.body || !result.body.data) {
        setNote("The faculty house could not read the school ledger.");
        return;
      }
      setNote("");
      paint(result.body.data);
    }).catch(function () {
      setNote("The faculty house could not read the school ledger.");
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
