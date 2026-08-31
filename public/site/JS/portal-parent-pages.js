(function () {
  const page = document.body.getAttribute("data-page") || "";
  if (page.indexOf("parent_") !== 0) return;

  const csrfToken = function () {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : "";
  };

  const loginHref = (window.srsLoginPath && window.srsLoginPath()) || "/parent/login";
  const CHILD_KEY = "srs.family.childId";

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

  const dash = function (value) {
    return value == null || String(value).trim() === "" ? "—" : String(value);
  };

  const formatScore = function (value) {
    if (value === null || value === undefined || value === "") return "—";
    const number = Number(value);
    if (Number.isNaN(number)) return "—";
    return String(number).replace(/\.0+$/, "").replace(/(\.\d*?)0+$/, "$1");
  };

  const pretty = function (value) {
    if (!value) return "—";
    return String(value).replace(/_/g, " ").replace(/\b\w/g, function (letter) {
      return letter.toUpperCase();
    });
  };

  const setText = function (selector, value) {
    document.querySelectorAll(selector).forEach(function (node) {
      node.textContent = value == null || value === "" ? "—" : String(value);
    });
  };

  const naira = function (value) {
    const amount = Number(value);
    if (!Number.isFinite(amount)) return "—";
    return "₦" + amount.toLocaleString("en-NG", { maximumFractionDigits: 0 });
  };

  const formatDay = function (value) {
    if (!value) return "—";
    const date = new Date(String(value).length <= 10 ? value + "T12:00:00" : value);
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" });
  };

  const weekday = function (value) {
    if (!value) return "—";
    const date = new Date(value + "T12:00:00");
    if (Number.isNaN(date.getTime())) return "—";
    return date.toLocaleDateString("en-GB", { weekday: "long" });
  };

  const ordinal = function (value) {
    const number = Number(value);
    if (!number) return "—";
    const remainder = number % 100;
    if (remainder >= 11 && remainder <= 13) return number + "th";
    if (number % 10 === 1) return number + "st";
    if (number % 10 === 2) return number + "nd";
    if (number % 10 === 3) return number + "rd";
    return number + "th";
  };

  const percent = function (value) {
    if (value == null || value === "") return "—";
    return String(value).replace(/\.0+$/, "") + "%";
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

  const setNote = function (message) {
    const node = document.querySelector("[data-desk-note]");
    if (!node) return;
    node.hidden = !message;
    node.textContent = message || "";
  };

  const field = function (label, value) {
    return '<div class="field"><span>' + escapeHtml(label) + "</span><strong>"
      + escapeHtml(dash(value)) + "</strong></div>";
  };

  const tickClock = function () {
    const node = document.querySelector("[data-clock]");
    if (!node) return;
    node.textContent = new Date().toLocaleTimeString("en-GB", {
      timeZone: "Africa/Lagos",
      hour12: false
    });
  };

  const printSheet = function () {
    document.body.classList.add("is-printing-student");
    const done = function () {
      document.body.classList.remove("is-printing-student");
      window.removeEventListener("afterprint", done);
    };
    window.addEventListener("afterprint", done);
    window.print();
    window.setTimeout(done, 1200);
  };

  const printResults = function (payload) {
    if (window.srsTermReport && payload) {
      window.srsTermReport.print(payload);
      return;
    }
    printSheet();
  };

  const storedChild = function () {
    const params = new URLSearchParams(window.location.search);
    const fromQuery = params.get("child");
    if (fromQuery) return String(fromQuery);
    try {
      return sessionStorage.getItem(CHILD_KEY) || "";
    } catch (error) {
      return "";
    }
  };

  const saveChild = function (id) {
    try {
      sessionStorage.setItem(CHILD_KEY, id ? String(id) : "");
    } catch (error) {}
  };

  const resolveChild = function (children) {
    const wanted = storedChild();
    if (wanted && children.some(function (row) { return String(row.id) === String(wanted); })) {
      return Number(wanted);
    }
    return children[0] ? Number(children[0].id) : null;
  };

  const withChild = function (url) {
    if (!childId) return url;
    return url + (url.indexOf("?") >= 0 ? "&" : "?") + "student_profile_id=" + encodeURIComponent(childId);
  };

  let desk = null;
  let children = [];
  let childId = null;
  let resultPeriods = [];

  const selectedChild = function () {
    return children.find(function (row) { return Number(row.id) === Number(childId); }) || null;
  };

  const paintChrome = function (data) {
    setText("[data-guardian-name]", data.full_name);
    setText("[data-school-short]", data.school_short || data.school);
    paintAvatar("[data-top-avatar]", data.initials, null);
    const logo = document.querySelector("[data-school-logo]");
    if (logo && data.logo_path) logo.setAttribute("src", data.logo_path);
    if (data.full_name && data.school) {
      const current = document.title.split("|")[0].trim();
      document.title = current + " | " + data.school;
    }
  };

  const paintChildSelect = function () {
    const select = document.querySelector("[data-child-select]");
    if (!select) return;
    if (!children.length) {
      select.innerHTML = '<option value="">No child on this desk</option>';
      return;
    }
    select.innerHTML = children.map(function (row) {
      return '<option value="' + escapeHtml(row.id) + '">'
        + escapeHtml(row.full_name || "Pupil")
        + (row.admission_number ? " · " + escapeHtml(row.admission_number) : "")
        + "</option>";
    }).join("");
    if (childId) select.value = String(childId);
    select.onchange = function () {
      childId = Number(select.value) || null;
      saveChild(childId);
      loadPage();
    };
  };

  const loadDesk = function () {
    return request("/api/v1/parent-desk").then(function (result) {
      if (!result.ok || !result.body || !result.body.data) {
        setNote("The family desk could not read the school ledger.");
        return null;
      }
      setNote("");
      desk = result.body.data;
      children = desk.children || [];
      childId = resolveChild(children);
      if (childId) saveChild(childId);
      paintChrome(desk);
      paintChildSelect();
      return desk;
    }).catch(function () {
      setNote("The family desk could not read the school ledger.");
      return null;
    });
  };

  const needChild = function (emptyMessage) {
    if (childId) return true;
    setNote(children.length ? "Choose a child on this household desk." : "No child is sealed to this household yet.");
    if (emptyMessage) emptyMessage();
    return false;
  };

  const wireProfile = function () {
    const personal = document.querySelector("[data-profile-personal]");
    const guardiansRoot = document.querySelector("[data-profile-guardians]");
    if (!needChild(function () {
      if (personal) personal.innerHTML = "<p class=\"empty\">Choose a child to open the folder.</p>";
      if (guardiansRoot) guardiansRoot.innerHTML = "";
    })) return;

    const child = selectedChild() || {};
    request("/api/v1/students/" + childId).then(function (result) {
      if (!result.ok) {
        setNote(firstError(result.body));
        return;
      }
      const row = result.body.data || {};
      setText("[data-full-name]", row.full_name || child.full_name);
      setText("[data-form]", row.current_form || child.form || "Unplaced");
      setText("[data-admission]", row.admission_number);
      setText("[data-status-line]", pretty(row.status) + " account");
      paintAvatar("[data-hero-avatar]", child.initials, row.photo_url || child.photo_url);
      if (personal) {
        personal.innerHTML = field("Surname", row.surname)
          + field("First name", row.first_name)
          + field("Other names", row.other_names)
          + field("Gender", pretty(row.gender))
          + field("Date of birth", formatDay(row.date_of_birth))
          + field("Admitted", formatDay(row.admitted_on))
          + field("Class", row.current_form || child.form)
          + field("Campus", row.campus_name || child.campus)
          + field("Session", row.session_name)
          + field("Admission number", row.admission_number);
      }
      if (!guardiansRoot) return;
      const list = row.guardians || [];
      if (!list.length) {
        guardiansRoot.innerHTML = "<p class=\"empty\">No parent or guardian is sealed on this record.</p>";
        return;
      }
      guardiansRoot.innerHTML = list.map(function (item) {
        return '<article class="form-card"><strong>' + escapeHtml(item.full_name || "Guardian")
          + "</strong><span>" + escapeHtml([pretty(item.relationship), item.phone, item.email].filter(Boolean).join(" · "))
          + "</span></article>";
      }).join("");
    });
  };

  const paintResultRows = function (payload, body) {
    const types = payload.assessment_types || [];
    const head = document.querySelector("[data-results-head]");
    const colCount = 3 + Math.max(types.length, 3);
    if (head) {
      head.innerHTML = "<tr><th>Subject</th>"
        + (types.length ? types.map(function (type) {
          return "<th>" + escapeHtml(type.name) + "</th>";
        }).join("") : "<th>First C.A.</th><th>Second C.A.</th><th>Exam</th>")
        + "<th>Total</th><th>Grade</th><th>Remark</th></tr>";
    }
    const rows = payload.results || [];
    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="' + colCount + '">No results have been recorded for this term yet.</td></tr>';
      return;
    }
    body.innerHTML = rows.map(function (row) {
      const letter = row.grade || "—";
      const papers = (row.scores && row.scores.length)
        ? row.scores
        : (types.length ? types : [{}, {}, {}]).map(function (type) {
          return { score: type && type.score != null ? type.score : null };
        });
      const paperCells = papers.map(function (cell) {
        return "<td>" + escapeHtml(formatScore(cell && cell.score)) + "</td>";
      }).join("");
      return "<tr><td>" + escapeHtml(dash(row.subject_name)) + "</td>" + paperCells
        + "<td><strong>" + escapeHtml(percent(row.total)) + "</strong></td>"
        + "<td><span class=\"grade-badge grade-" + escapeHtml(String(letter).toLowerCase()) + "\">"
        + escapeHtml(letter) + "</span></td><td>" + escapeHtml(dash(row.remark)) + "</td></tr>";
    }).join("");
  };

  const uniqueSessions = function (periods) {
    const sessions = [];
    (periods || []).forEach(function (row) {
      if (!row.academic_session_id) return;
      if (sessions.some(function (item) { return String(item.id) === String(row.academic_session_id); })) return;
      sessions.push({ id: row.academic_session_id, name: row.session_name || "Session" });
    });
    return sessions;
  };

  const paintResultPicks = function (payload, periods) {
    const sessionSelect = document.querySelector("[data-session-select]");
    const termSelect = document.querySelector("[data-term-select]");
    if (!sessionSelect || !termSelect) return periods || [];
    const list = payload.periods || periods || [];
    const sessions = uniqueSessions(list);
    const selected = list.find(function (row) { return String(row.id) === String(payload.term_id); })
      || list.find(function (row) { return row.is_current; })
      || list[0];
    const sessionId = selected ? selected.academic_session_id : (sessions[0] && sessions[0].id);
    sessionSelect.innerHTML = sessions.length
      ? sessions.map(function (session) {
        return '<option value="' + escapeHtml(session.id) + '">' + escapeHtml(session.name) + "</option>";
      }).join("")
      : '<option value="">No session yet</option>';
    if (sessionId) sessionSelect.value = String(sessionId);
    const terms = list.filter(function (row) {
      return String(row.academic_session_id) === String(sessionSelect.value);
    });
    termSelect.innerHTML = terms.length
      ? terms.map(function (term) {
        return '<option value="' + escapeHtml(term.id) + '">' + escapeHtml(term.name) + "</option>";
      }).join("")
      : '<option value="">No term yet</option>';
    if (selected && String(selected.academic_session_id) === String(sessionSelect.value)) {
      termSelect.value = String(selected.id);
    } else if (terms[0]) {
      termSelect.value = String(terms[0].id);
    }
    return list;
  };

  const paintResultSummary = function (payload) {
    setText('[data-metric="average"]', percent(payload.average));
    setText('[data-metric-delta="average"]', payload.average != null && Number(payload.average) >= 75
      ? "Excellent performance" : "Term average");
    setText('[data-metric="position"]', ordinal(payload.class_position));
    setText('[data-metric-delta="position"]', payload.class_size
      ? ("Out of " + payload.class_size + " students") : "No position yet");
    const highest = payload.highest || {};
    setText('[data-metric="highest"]', percent(highest.total));
    setText('[data-metric-delta="highest"]', highest.subject_name || "Highest subject");
    setText("[data-term-line]", [payload.session_name, payload.term_name, payload.form].filter(Boolean).join(" · ") || "—");
  };

  const wireAcademics = function () {
    const printBtn = document.querySelector("[data-print-results]");
    const sessionSelect = document.querySelector("[data-session-select]");
    const termSelect = document.querySelector("[data-term-select]");
    const body = document.querySelector("[data-results-body]");
    let lastResults = null;

    const termForSession = function (sessionId) {
      const inSession = resultPeriods.filter(function (row) {
        return String(row.academic_session_id) === String(sessionId);
      });
      return inSession.find(function (row) { return row.is_current; }) || inSession[0] || null;
    };

    const loadResults = function (termId) {
      if (!needChild(function () {
        if (body) body.innerHTML = '<tr><td colspan="7">Choose a child to open the mark book.</td></tr>';
      })) return;

      let url = withChild("/api/v1/results");
      if (termId) url += (url.indexOf("?") >= 0 ? "&" : "?") + "term_id=" + encodeURIComponent(termId);
      request(url).then(function (result) {
        if (!result.ok) {
          if (body) body.innerHTML = '<tr><td colspan="7">' + escapeHtml(firstError(result.body)) + "</td></tr>";
          return;
        }
        const payload = result.body.data || {};
        lastResults = payload;
        resultPeriods = paintResultPicks(payload, resultPeriods);
        paintResultSummary(payload);
        if (!body) return;
        paintResultRows(payload, body);
      });
    };

    if (printBtn && !printBtn.getAttribute("data-wired")) {
      printBtn.setAttribute("data-wired", "1");
      printBtn.addEventListener("click", function () {
        printResults(lastResults);
      });
    }
    if (sessionSelect && !sessionSelect.getAttribute("data-wired")) {
      sessionSelect.setAttribute("data-wired", "1");
      sessionSelect.addEventListener("change", function () {
        const row = termForSession(sessionSelect.value);
        loadResults(row ? row.id : "");
      });
    }
    if (termSelect && !termSelect.getAttribute("data-wired")) {
      termSelect.setAttribute("data-wired", "1");
      termSelect.addEventListener("change", function () {
        loadResults(termSelect.value);
      });
    }
    loadResults();
  };

  const assignmentCard = function (row, today) {
    const handed = row.submission;
    const late = row.due_on && row.due_on < today && !handed;
    const dueLabel = handed
      ? ((handed.late ? "Handed in late" : "Handed in") + " " + formatDay(handed.submitted_at))
      : (row.due_on && row.due_on < today ? "Overdue" : ("Due " + formatDay(row.due_on)));
    let html = '<article class="task task-sheet' + (late ? " is-overdue" : "") + (handed ? " is-done" : "")
      + '" data-assignment-id="' + escapeHtml(row.id) + '"><i></i><div><strong>'
      + escapeHtml(row.title || "Assignment") + "</strong><span>"
      + escapeHtml([row.subject_name, row.staff_name, dueLabel].filter(Boolean).join(" · ") || "Class work")
      + "</span>";
    if (row.instructions) {
      html += '<p class="task-copy">' + escapeHtml(row.instructions) + "</p>";
    }
    if (handed && handed.download_url) {
      html += '<p class="task-meta"><a class="task-file" href="' + escapeHtml(handed.download_url) + '">'
        + escapeHtml(handed.original_name || "Download paper") + "</a></p>";
    }
    if (handed && handed.notes) {
      html += '<p class="task-copy">' + escapeHtml(handed.notes) + "</p>";
    }
    if (!handed) {
      html += '<p class="task-meta">Not handed in yet.</p>';
    }
    return html + "</div></article>";
  };

  const wireAssignments = function () {
    const root = document.querySelector("[data-assignment-list]");
    if (!needChild(function () {
      if (root) root.innerHTML = "<p class=\"empty\">Choose a child to open the work board.</p>";
    })) return;

    request(withChild("/api/v1/assignments")).then(function (result) {
      if (!result.ok) {
        if (root) root.innerHTML = "<p class=\"empty\">" + escapeHtml(firstError(result.body)) + "</p>";
        return;
      }
      const rows = result.body.data || [];
      const today = new Date().toISOString().slice(0, 10);
      const open = rows.filter(function (row) { return !row.due_on || row.due_on >= today; });
      const overdue = rows.filter(function (row) { return row.due_on && row.due_on < today; });
      const soonEnd = new Date();
      soonEnd.setDate(soonEnd.getDate() + 7);
      const soon = open.filter(function (row) { return row.due_on && row.due_on <= soonEnd.toISOString().slice(0, 10); });
      setText('[data-metric="open"]', open.length);
      setText('[data-metric="soon"]', soon.length);
      setText('[data-metric="overdue"]', overdue.length);
      if (!root) return;
      if (!rows.length) {
        root.innerHTML = "<p class=\"empty\">No work is on the board for this form.</p>";
        return;
      }
      root.innerHTML = rows.map(function (row) { return assignmentCard(row, today); }).join("");
    });
  };

  const wireAttendance = function () {
    const body = document.querySelector("[data-attendance-body]");
    if (!needChild(function () {
      if (body) body.innerHTML = '<tr><td colspan="4">Choose a child to open the roll.</td></tr>';
    })) return;

    request(withChild("/api/v1/attendance/summary")).then(function (result) {
      if (!result.ok) {
        if (body) body.innerHTML = '<tr><td colspan="4">' + escapeHtml(firstError(result.body)) + "</td></tr>";
        return;
      }
      const payload = result.body.data || {};
      const summary = payload.summary || {};
      setText('[data-metric="attendance"]', summary.percentage == null ? "—" : percent(summary.percentage));
      setText('[data-metric-delta="attendance"]', summary.recorded ? (summary.recorded + " days marked") : "No roll marked yet");
      setText('[data-metric="present"]', summary.present == null ? "—" : summary.present);
      setText('[data-metric="absent"]', summary.absent == null ? "—" : summary.absent);
      const rows = payload.records || [];
      if (!body) return;
      if (!rows.length) {
        body.innerHTML = '<tr><td colspan="4">No attendance has been marked on this admission yet.</td></tr>';
        return;
      }
      body.innerHTML = rows.slice().reverse().map(function (row) {
        return "<tr><td>" + escapeHtml(formatDay(row.marked_on)) + "</td><td>" + escapeHtml(weekday(row.marked_on))
          + '</td><td><span class="badge ' + (row.status === "present" ? "ok" : (row.status === "absent" ? "warn" : "")) + '">'
          + escapeHtml(pretty(row.status)) + "</span></td><td>" + escapeHtml(dash(row.remark)) + "</td></tr>";
      }).join("");
    });
  };

  const wireFees = function () {
    const invoicesBody = document.querySelector("[data-fee-invoices]");
    const paymentsBody = document.querySelector("[data-fee-payments]");
    if (!needChild(function () {
      if (invoicesBody) invoicesBody.innerHTML = '<tr><td colspan="6">Choose a child to open the fee ledger.</td></tr>';
      if (paymentsBody) paymentsBody.innerHTML = '<tr><td colspan="4">Choose a child to open the receipts.</td></tr>';
    })) return;

    request(withChild("/api/v1/me/fees/summary")).then(function (result) {
      if (!result.ok) {
        if (invoicesBody) invoicesBody.innerHTML = '<tr><td colspan="6">' + escapeHtml(firstError(result.body)) + "</td></tr>";
        return;
      }
      const payload = result.body.data || {};
      setText('[data-metric="expected"]', payload.expected || naira(payload.expected_naira));
      setText('[data-metric="collected"]', payload.collected || naira(payload.collected_naira));
      setText('[data-metric="outstanding"]', payload.outstanding || naira(payload.outstanding_naira));
      setText("[data-fee-status]", payload.fee_status_label || pretty(payload.status));
      setText("[data-fee-term]", [payload.session_name, payload.term_name].filter(Boolean).join(" · ") || "Fee ledger");
      const rows = payload.invoices || [];
      if (!invoicesBody) return;
      if (!rows.length) {
        invoicesBody.innerHTML = '<tr><td colspan="6">No invoices are sealed on this admission yet.</td></tr>';
        return;
      }
      invoicesBody.innerHTML = rows.map(function (row) {
        return "<tr><td>" + escapeHtml(dash(row.number)) + "</td><td>" + escapeHtml(dash(row.term_name))
          + "</td><td>" + escapeHtml(naira(row.total_naira)) + "</td><td>" + escapeHtml(naira(row.paid_naira))
          + "</td><td>" + escapeHtml(naira(row.balance_naira)) + "</td><td>"
          + escapeHtml(row.fee_status_label || pretty(row.status)) + "</td></tr>";
      }).join("");
    });

    request(withChild("/api/v1/me/payments")).then(function (result) {
      if (!paymentsBody) return;
      if (!result.ok) {
        paymentsBody.innerHTML = '<tr><td colspan="4">' + escapeHtml(firstError(result.body)) + "</td></tr>";
        return;
      }
      const rows = (result.body.data || []).filter(function (row) { return row.status !== "void"; });
      if (!rows.length) {
        paymentsBody.innerHTML = '<tr><td colspan="4">No receipts have been posted yet.</td></tr>';
        return;
      }
      paymentsBody.innerHTML = rows.map(function (row) {
        return "<tr><td>" + escapeHtml(dash(row.reference)) + "</td><td>" + escapeHtml(formatDay(row.paid_at))
          + "</td><td>" + escapeHtml(naira(row.amount_naira)) + "</td><td>" + escapeHtml(pretty(row.channel))
          + "</td></tr>";
      }).join("");
    });
  };

  const wireTimetable = function () {
    const printBtn = document.querySelector("[data-print-timetable]");
    if (printBtn && !printBtn.getAttribute("data-wired")) {
      printBtn.setAttribute("data-wired", "1");
      printBtn.addEventListener("click", printSheet);
    }
    const body = document.querySelector("[data-timetable-body]");
    setText("[data-term]", desk && desk.term);
    setText("[data-session]", desk && desk.session);
    if (!needChild(function () {
      if (body) body.innerHTML = '<tr><td colspan="6">Choose a child to open the bells.</td></tr>';
    })) return;

    const child = selectedChild() || {};
    setText("[data-form]", child.form || "Unplaced");
    const offeringId = child.class_section_offering_id;
    if (!offeringId) {
      if (body) body.innerHTML = '<tr><td colspan="6">No class is assigned to this child yet.</td></tr>';
      return;
    }
    request("/api/v1/timetable?class_section_offering_id=" + encodeURIComponent(offeringId)).then(function (grid) {
      if (!grid.ok) {
        if (body) body.innerHTML = '<tr><td colspan="6">' + escapeHtml(firstError(grid.body)) + "</td></tr>";
        return;
      }
      const data = grid.body.data || {};
      if (data.form) setText("[data-form]", data.form);
      if (data.session_name) setText("[data-session]", data.session_name);
      const slots = data.slots || [];
      const times = [];
      slots.forEach(function (slot) {
        if (slot.starts_at && times.indexOf(slot.starts_at) === -1) times.push(slot.starts_at);
      });
      times.sort();
      if (!times.length) {
        if (body) body.innerHTML = '<tr><td colspan="6">No bells are on the board for this form yet.</td></tr>';
        return;
      }
      if (!body) return;
      body.innerHTML = times.map(function (time) {
        const cells = [1, 2, 3, 4, 5].map(function (day) {
          const hit = slots.find(function (slot) {
            return Number(slot.day_of_week) === day && slot.starts_at === time;
          });
          if (!hit) return "<td>—</td>";
          return "<td><strong>" + escapeHtml(hit.subject_name || hit.label || "Period")
            + "</strong><small>" + escapeHtml(dash(hit.staff_name)) + "</small></td>";
        }).join("");
        const end = (slots.find(function (slot) { return slot.starts_at === time; }) || {}).ends_at;
        return '<tr><td class="time-cell">' + escapeHtml(time) + (end ? "<br>" + escapeHtml(end) : "") + "</td>" + cells + "</tr>";
      }).join("");
    });
  };

  const wireMaterials = function () {
    const root = document.querySelector("[data-material-grid]");
    const search = document.querySelector("[data-material-search]");
    let rows = [];
    const paint = function () {
      if (!root) return;
      const needle = search && search.value ? search.value.toLowerCase() : "";
      const visible = rows.filter(function (row) {
        if (!needle) return true;
        return [row.title, row.subject_name, row.staff_name].join(" ").toLowerCase().indexOf(needle) !== -1;
      });
      if (!visible.length) {
        root.innerHTML = "<p class=\"empty\">No papers match that search.</p>";
        return;
      }
      root.innerHTML = visible.map(function (row) {
        const doc = row.document || {};
        const href = doc.id ? "/api/v1/documents/" + doc.id + "/download" : "#";
        return '<article class="material-card"><span class="badge">'
          + escapeHtml(dash(row.subject_name)) + "</span><h3>" + escapeHtml(row.title || "Paper")
          + "</h3><p>" + escapeHtml(dash(row.staff_name)) + "</p>"
          + "<p>" + escapeHtml(dash(doc.original_name)) + "</p>"
          + '<a class="ghost-btn" href="' + escapeHtml(href) + '">Open</a></article>';
      }).join("");
    };
    if (!needChild(function () {
      if (root) root.innerHTML = "<p class=\"empty\">Choose a child to open the papers.</p>";
    })) return;

    request(withChild("/api/v1/learning-materials")).then(function (result) {
      if (!result.ok) {
        if (root) root.innerHTML = "<p class=\"empty\">" + escapeHtml(firstError(result.body)) + "</p>";
        return;
      }
      rows = result.body.data || [];
      if (!rows.length) {
        if (root) root.innerHTML = "<p class=\"empty\">No papers have been posted for this form yet.</p>";
        return;
      }
      paint();
    });
    if (search && !search.getAttribute("data-wired")) {
      search.setAttribute("data-wired", "1");
      search.addEventListener("input", paint);
    }
  };

  const wireAnnouncements = function () {
    request("/api/v1/announcements").then(function (result) {
      const root = document.querySelector("[data-notice-list]");
      if (!root) return;
      if (!result.ok) {
        root.innerHTML = "<p class=\"empty\">" + escapeHtml(firstError(result.body)) + "</p>";
        return;
      }
      const rows = result.body.data || [];
      if (!rows.length) {
        root.innerHTML = "<p class=\"empty\">No notices in the hall yet.</p>";
        return;
      }
      root.innerHTML = rows.map(function (row) {
        return '<article class="announcement-card"><h3>' + escapeHtml(row.title || "Notice") + "</h3>"
          + "<p>" + escapeHtml(row.body || "") + "</p>"
          + "<span>" + escapeHtml(formatDay(row.published_at || row.created_at)) + "</span></article>";
      }).join("");
    });
  };

  const wireMessages = function () {
    const list = document.querySelector("[data-conversation-items]");
    const pane = document.querySelector("[data-chat-pane]");
    const notice = document.querySelector("[data-message-notice]");
    const recipient = document.querySelector("[data-message-recipient]");
    let activeId = null;
    let meId = null;

    request("/api/v1/me").then(function (result) {
      if (result.ok) meId = result.body.data && result.body.data.id;
    });

    const paintChat = function (row) {
      if (!pane) return;
      const messages = row.messages || [];
      pane.innerHTML = '<div class="chat-head"><div><strong>'
        + escapeHtml(row.other_name || row.subject || "Letter") + "</strong><p class=\"head-copy\">"
        + escapeHtml(dash(row.subject)) + "</p></div></div>"
        + '<div class="chat-body">' + messages.map(function (item) {
          const mine = meId && Number(item.sender_id) === Number(meId);
          return '<div class="message ' + (mine ? "sent" : "received") + '"><small>'
            + escapeHtml(formatDay(item.created_at)) + "</small><p>"
            + escapeHtml(item.body || "") + "</p></div>";
        }).join("") + "</div>"
        + '<form class="chat-compose" data-reply-form><input name="body" required placeholder="Write a reply">'
        + '<button class="gold-btn" type="submit">Send</button></form>';
      const form = pane.querySelector("[data-reply-form]");
      if (form) {
        form.addEventListener("submit", function (event) {
          event.preventDefault();
          const body = (form.querySelector("[name=body]") || {}).value || "";
          request("/api/v1/conversations/" + row.id + "/messages", {
            method: "POST",
            body: JSON.stringify({ body: body })
          }).then(function (sent) {
            if (!sent.ok) {
              if (notice) notice.textContent = firstError(sent.body);
              return;
            }
            openConversation(row.id);
          });
        });
      }
    };

    const paintList = function (rows) {
      const unread = rows.reduce(function (sum, row) { return sum + Number(row.unread_count || 0); }, 0);
      setText("[data-inbox-copy]", unread ? (unread + " unread") : (rows.length ? rows.length + " letters" : "No letters yet"));
      if (!list) return;
      if (!rows.length) {
        list.innerHTML = "<p class=\"empty\">No letters yet.</p>";
        return;
      }
      list.innerHTML = rows.map(function (row) {
        const active = String(row.id) === String(activeId) ? " active" : "";
        const unreadClass = Number(row.unread_count) > 0 ? " unread" : "";
        return '<button type="button" class="conversation' + unreadClass + active + '" data-id="' + row.id + '">'
          + '<span class="mark">' + escapeHtml((row.other_name || "O").charAt(0).toUpperCase()) + "</span>"
          + "<div><strong>" + escapeHtml(row.other_name || "Office") + "</strong>"
          + "<h3>" + escapeHtml(dash(row.subject)) + "</h3><p>"
          + escapeHtml(dash(row.preview)) + "</p></div></button>";
      }).join("");
    };

    const refreshList = function () {
      return request("/api/v1/conversations").then(function (result) {
        if (!result.ok) return [];
        const rows = result.body.data || [];
        paintList(rows);
        return rows;
      });
    };

    const openConversation = function (id) {
      activeId = id;
      request("/api/v1/conversations/" + id).then(function (result) {
        if (!result.ok) return;
        paintChat(result.body.data || {});
        refreshList();
      });
    };

    if (list && !list.getAttribute("data-wired")) {
      list.setAttribute("data-wired", "1");
      list.addEventListener("click", function (event) {
        const item = event.target.closest("[data-id]");
        if (item) openConversation(item.getAttribute("data-id"));
      });
    }

    request("/api/v1/messages/recipients").then(function (result) {
      if (!recipient) return;
      const rows = (result.ok && result.body.data) || [];
      recipient.innerHTML = '<option value="">Write to…</option>' + rows.map(function (row) {
        return '<option value="' + row.id + '">' + escapeHtml(row.name || "Staff") + "</option>";
      }).join("");
    });

    const sendBtn = document.querySelector("[data-message-send]");
    if (sendBtn && !sendBtn.getAttribute("data-wired")) {
      sendBtn.setAttribute("data-wired", "1");
      sendBtn.addEventListener("click", function () {
        const payload = {
          recipient_id: Number((recipient || {}).value || 0),
          subject: (document.querySelector("[data-message-subject]") || {}).value || "",
          body: (document.querySelector("[data-message-body]") || {}).value || ""
        };
        request("/api/v1/conversations", { method: "POST", body: JSON.stringify(payload) }).then(function (result) {
          if (!result.ok) {
            if (notice) notice.textContent = firstError(result.body);
            return;
          }
          if (notice) notice.textContent = "";
          const subject = document.querySelector("[data-message-subject]");
          const composeBody = document.querySelector("[data-message-body]");
          if (subject) subject.value = "";
          if (composeBody) composeBody.value = "";
          openConversation(result.body.data.id);
        });
      });
    }

    refreshList();
  };

  const wireSettings = function () {
    const copy = document.querySelector("[data-settings-copy]");
    if (copy) {
      copy.textContent = "Sign in with a child’s name or admission number. The key is your registered phone number. An office email and passphrase are optional, and forgot-password stays on that second door.";
    }

    const fillAccount = function (data) {
      const name = document.getElementById("accountName");
      const email = document.getElementById("accountEmail");
      if (name) name.value = data.name || desk && desk.full_name || "";
      if (email) email.value = data.email || "";
    };

    request("/api/v1/me").then(function (result) {
      if (result.ok && result.body.data) fillAccount(result.body.data);
    });

    const accountForm = document.querySelector("[data-account-form]");
    if (accountForm && !accountForm.getAttribute("data-wired")) {
      accountForm.setAttribute("data-wired", "1");
      accountForm.addEventListener("submit", function (event) {
        event.preventDefault();
        const notice = accountForm.querySelector(".form-notice");
        request("/api/v1/me", {
          method: "PUT",
          body: JSON.stringify({
            name: (document.getElementById("accountName") || {}).value || "",
            email: (document.getElementById("accountEmail") || {}).value || ""
          })
        }).then(function (result) {
          if (!result.ok) {
            if (notice) notice.textContent = firstError(result.body);
            return;
          }
          if (notice) notice.textContent = "Details sealed.";
          fillAccount(result.body.data || {});
        });
      });
    }

    const passwordForm = document.querySelector("[data-password-form]");
    if (passwordForm && !passwordForm.getAttribute("data-wired")) {
      passwordForm.setAttribute("data-wired", "1");
      passwordForm.addEventListener("submit", function (event) {
        event.preventDefault();
        const notice = passwordForm.querySelector(".form-notice");
        request("/api/v1/me/password", {
          method: "PUT",
          body: JSON.stringify({
            current_password: (document.getElementById("currentPassword") || {}).value || "",
            password: (document.getElementById("newPassword") || {}).value || "",
            password_confirmation: (document.getElementById("confirmPassword") || {}).value || ""
          })
        }).then(function (result) {
          if (!result.ok) {
            if (notice) notice.textContent = firstError(result.body);
            return;
          }
          passwordForm.reset();
          if (notice) notice.textContent = "Passphrase reset.";
        });
      });
    }
  };

  const loadPage = function () {
    if (page === "parent_profile") wireProfile();
    if (page === "parent_academics") wireAcademics();
    if (page === "parent_assignments") wireAssignments();
    if (page === "parent_attendance") wireAttendance();
    if (page === "parent_fees") wireFees();
    if (page === "parent_timetable") wireTimetable();
    if (page === "parent_materials") wireMaterials();
    if (page === "parent_messages") wireMessages();
    if (page === "parent_announcements") wireAnnouncements();
    if (page === "parent_settings") wireSettings();
  };

  tickClock();
  window.setInterval(tickClock, 1000);

  loadDesk().then(loadPage);
})();
