(function () {
  if (document.body.getAttribute("data-page") !== "dashboard") return;

  const csrfToken = function () {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : "";
  };

  const loginHref = (window.srsLoginPath && window.srsLoginPath()) || "/portal/login";
  const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

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
    const duration = 1100;
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
    if (!greeting || !name) return;
    const hour = Number(new Date().toLocaleString("en-GB", {
      timeZone: "Africa/Lagos",
      hour: "numeric",
      hour12: false
    }));
    const part = hour < 12 ? "morning" : hour < 16 ? "afternoon" : "evening";
    greeting.setAttribute("data-name", name);
    greeting.textContent = "Good " + part + ", " + name + ".";
  };

  const renderTickets = function (tickets) {
    const list = document.querySelector("[data-ticket-list]");
    if (!list) return;
    if (!tickets || !tickets.length) {
      list.innerHTML = "<p>No school tickets on the ledger yet.</p>";
      return;
    }
    list.innerHTML = tickets.map(function (ticket) {
      const tone = ticket.tone === "warn" ? "warn" : "ok";
      return '<article class="ticket"><div class="ticket-code">'
        + escapeHtml(ticket.code)
        + "</div><div><h3>"
        + escapeHtml(ticket.title)
        + "</h3><p>"
        + escapeHtml(ticket.detail)
        + '</p></div><span class="badge ' + tone + '">'
        + escapeHtml(ticket.badge)
        + "</span></article>";
    }).join("");
  };

  const renderTicker = function (tickets) {
    const track = document.querySelector("[data-ticker]");
    if (!track) return;
    if (!tickets || !tickets.length) {
      track.style.animation = "none";
      track.innerHTML = "<span>No school tickets on the ledger yet.</span>";
      return;
    }
    const spans = tickets.map(function (ticket) {
      const text = String(ticket.text || "");
      const code = String(ticket.code || "");
      const rest = code && text.indexOf(code) === 0 ? text.slice(code.length).trim() : text;
      return "<span><b>" + escapeHtml(code) + "</b> " + escapeHtml(rest) + "</span>";
    }).join("");
    track.style.animation = "";
    track.innerHTML = spans + spans;
  };

  const renderInbox = function (items) {
    const list = document.querySelector("[data-inbox-list]");
    if (!list) return;
    if (!items || !items.length) {
      list.innerHTML = "<p>The inbound chute is empty.</p>";
      return;
    }
    list.innerHTML = items.map(function (item) {
      const klass = item.unread ? "msg-item" : "msg-item read";
      return '<article class="' + klass + '"><i></i><div><h3>'
        + escapeHtml(item.name)
        + "</h3><p>"
        + escapeHtml(item.preview)
        + '</p><div class="msg-meta">'
        + escapeHtml(item.meta)
        + "</div></div></article>";
    }).join("");
  };

  const paint = function (data) {
    if (!data) return;
    applyGreeting(data.name);
    if (data.school) {
      document.title = "Command Desk | " + data.school;
    }

    const visibility = data.visibility || {};
    document.querySelectorAll("[data-requires]").forEach(function (node) {
      const key = node.getAttribute("data-requires");
      if (!key) return;
      node.hidden = visibility[key] === false;
    });

    const metrics = data.metrics || {};
    const attendance = document.querySelector('[data-metric="attendance"]');
    if (attendance && visibility.attendance !== false) {
      if (metrics.attendance_percent == null) {
        attendance.textContent = "—";
      } else {
        animateValue(attendance, metrics.attendance_percent, "", "%");
      }
      if (metrics.attendance_delta) {
        attendance.setAttribute("title", metrics.attendance_delta);
      }
    }

    if (visibility.pupils !== false) {
      animateValue(document.querySelector('[data-metric="pupils"]'), metrics.pupils, "", "");
      setText('[data-metric-delta="pupils"]', metrics.pupils_delta);
    }
    if (visibility.staff !== false) {
      animateValue(document.querySelector('[data-metric="staff"]'), metrics.staff, "", "");
      setText('[data-metric-delta="staff"]', metrics.staff_delta);
    }
    if (visibility.fees !== false) {
      animateValue(
        document.querySelector('[data-metric="fees"]'),
        metrics.fees_count,
        metrics.fees_prefix || "",
        metrics.fees_suffix || ""
      );
      setText('[data-metric-delta="fees"]', metrics.fees_delta);
    }
    if (visibility.forms !== false) {
      animateValue(document.querySelector('[data-metric="forms"]'), metrics.forms, "", "");
      setText('[data-metric-delta="forms"]', metrics.forms_delta);
    }

    const house = data.house || {};
    if (visibility.house !== false) {
      setText("[data-house-copy]", house.copy);
      setText('[data-house="session"]', house.session);
      setText('[data-house="term"]', house.term);
      setText('[data-house="levels"]', house.levels);
      setText('[data-house="outstanding"]', house.outstanding);
    }

    if (visibility.tickets !== false) {
      renderTickets(data.tickets);
      renderTicker(data.tickets);
    }
    if (visibility.inbox !== false) {
      renderInbox(data.inbox);
    }
    if (visibility.wings !== false) {
      renderWings(data.wings);
    }
  };

  const renderWings = function (wings) {
    (wings || []).forEach(function (wing) {
      const count = document.querySelector('[data-wing-count="' + wing.slug + '"]');
      if (!count) return;
      const pupils = Number((wing.metrics || {}).pupils);
      if (!Number.isFinite(pupils)) {
        count.textContent = "Connecting to this desk…";
        return;
      }
      count.textContent = pupils === 1 ? "1 pupil on the desk" : pupils + " pupils on the desk";
    });
  };

  const setLookupField = function (key, value) {
    const node = document.querySelector('[data-lookup-field="' + key + '"]');
    if (node) node.textContent = value == null || value === "" ? "—" : String(value);
  };

  const paintPupilCard = function (row) {
    const card = document.querySelector("[data-lookup-card]");
    if (!card || !row) return;
    card.hidden = false;
    const wingLabel = row.level_name || (row.wing ? row.wing.charAt(0).toUpperCase() + row.wing.slice(1) : "Unplaced");
    setText("[data-lookup-name]", row.full_name || "—");
    setText("[data-lookup-wing]", wingLabel);
    setText("[data-lookup-meta]", [row.admission_number, row.current_form, row.campus_name].filter(Boolean).join(" · ") || "On the school roll");
    const guardians = row.guardians || [];
    const guardian = guardians.filter(function (item) { return item.is_primary; })[0] || guardians[0] || {};
    const status = row.account_status === "suspended" ? "Suspended" : (row.status || "—");
    setLookupField("admission", row.admission_number);
    setLookupField("form", row.current_form);
    setLookupField("status", status);
    setLookupField("fees", row.fee_label);
    setLookupField("guardian", guardian.full_name || row.primary_guardian);
    setLookupField("contact", guardian.phone || row.phone || row.email);
    setLookupField("campus", row.campus_name);
    setLookupField("session", row.session_name);
  };

  const wireLookup = function () {
    const form = document.querySelector("[data-pupil-lookup]");
    const input = document.querySelector("[data-lookup-q]");
    const results = document.querySelector("[data-lookup-results]");
    const copy = document.querySelector("[data-lookup-copy]");
    const card = document.querySelector("[data-lookup-card]");
    if (!form || !input || !results) return;

    let timer = null;
    form.addEventListener("submit", function (event) {
      event.preventDefault();
    });

    const run = function () {
      const query = (input.value || "").trim();
      if (query.length < 2) {
        results.innerHTML = "";
        if (card) card.hidden = true;
        if (copy) copy.textContent = "Type to pull a record from the live roll.";
        return;
      }
      if (copy) copy.textContent = "Searching the school roll…";
      request("/api/v1/students?limit=8&q=" + encodeURIComponent(query)).then(function (result) {
        if ((input.value || "").trim() !== query) return;
        if (!result.ok) {
          results.innerHTML = "";
          if (copy) copy.textContent = "The office could not open the roll.";
          return;
        }
        const rows = result.body.data || [];
        if (!rows.length) {
          results.innerHTML = "";
          if (card) card.hidden = true;
          if (copy) copy.textContent = "No pupil matches that search.";
          return;
        }
        if (copy) copy.textContent = rows.length + (rows.length === 1 ? " match on the roll." : " matches on the roll.");
        results.innerHTML = rows.map(function (row) {
          const line = [row.admission_number, row.current_form || row.level_name || "Unplaced"].filter(Boolean).join(" · ");
          return '<button type="button" class="lookup-hit" data-lookup-id="' + row.id + '"><div><strong>'
            + escapeHtml(row.full_name || "—") + "</strong><small>"
            + escapeHtml(line) + "</small></div><span>Open</span></button>";
        }).join("");
      });
    };

    input.addEventListener("input", function () {
      window.clearTimeout(timer);
      timer = window.setTimeout(run, 280);
    });

    results.addEventListener("click", function (event) {
      const hit = event.target.closest("[data-lookup-id]");
      if (!hit) return;
      results.querySelectorAll(".lookup-hit").forEach(function (node) {
        node.classList.toggle("is-active", node === hit);
      });
      request("/api/v1/students/" + encodeURIComponent(hit.getAttribute("data-lookup-id"))).then(function (result) {
        if (!result.ok || !result.body.data) {
          if (copy) copy.textContent = "That record could not be opened.";
          return;
        }
        paintPupilCard(result.body.data);
      });
    });
  };

  request("/api/v1/portal-dashboard").then(function (result) {
    if (!result || !result.ok || !result.body || !result.body.data) {
      setText("[data-ticket-list]", "The command desk could not read the school ledger.");
      setText("[data-inbox-list]", "The inbound chute could not be opened.");
      return;
    }
    paint(result.body.data);
    if (result.body.data.visibility && result.body.data.visibility.lookup === false) {
      return;
    }
    wireLookup();
  }).catch(function () {
    setText("[data-ticket-list]", "The command desk could not read the school ledger.");
    setText("[data-inbox-list]", "The inbound chute could not be opened.");
  });
})();
