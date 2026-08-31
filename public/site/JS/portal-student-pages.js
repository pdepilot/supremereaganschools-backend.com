(function () {
  const page = document.body.getAttribute("data-page") || "";
  if (page.indexOf("student_") !== 0 || page === "student_desk") return;

  const csrfToken = function () {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : "";
  };

  const loginHref = (window.srsLoginPath && window.srsLoginPath()) || "/student/login";

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

  const paintChrome = function (data) {
    setText("[data-full-name]", data.full_name);
    setText("[data-school-short]", data.school_short || data.school);
    paintAvatar("[data-top-avatar]", data.initials, data.photo_url);
    const logo = document.querySelector("[data-school-logo]");
    if (logo && data.logo_path) logo.setAttribute("src", data.logo_path);
    const letters = document.querySelector("[data-letter-count]");
    if (letters) {
      const count = Number((data.metrics || {}).letters) || 0;
      letters.hidden = count < 1;
      letters.textContent = count > 9 ? "9+" : String(count);
    }
    if (data.full_name && data.school) {
      const current = document.title.split("|")[0].trim();
      document.title = current + " | " + data.school;
    }
  };

  const loadDesk = function () {
    return request("/api/v1/student-desk").then(function (result) {
      if (!result.ok || !result.body || !result.body.data) {
        setNote("The pupil desk could not read the school ledger.");
        return null;
      }
      setNote("");
      paintChrome(result.body.data);
      return result.body.data;
    }).catch(function () {
      setNote("The pupil desk could not read the school ledger.");
      return null;
    });
  };

  const wireProfile = function (desk) {
    const personal = document.querySelector("[data-profile-personal]");
    const guardiansRoot = document.querySelector("[data-profile-guardians]");
    if (!desk || !desk.id) {
      if (personal) personal.innerHTML = "<p class=\"empty\">No admission is sealed on this desk.</p>";
      return;
    }
    request("/api/v1/students/" + desk.id).then(function (result) {
      if (!result.ok) {
        setNote(firstError(result.body));
        return;
      }
      const row = result.body.data || {};
      setText("[data-form]", row.current_form || desk.form || "Unplaced");
      setText("[data-admission]", row.admission_number);
      setText("[data-status-line]", pretty(row.status) + " account");
      paintAvatar("[data-hero-avatar]", desk.initials, row.photo_url || desk.photo_url);
      const personal = document.querySelector("[data-profile-personal]");
      if (personal) {
        personal.innerHTML = field("Surname", row.surname)
          + field("First name", row.first_name)
          + field("Other names", row.other_names)
          + field("Gender", pretty(row.gender))
          + field("Date of birth", formatDay(row.date_of_birth))
          + field("Admitted", formatDay(row.admitted_on))
          + field("Class", row.current_form)
          + field("Campus", row.campus_name)
          + field("Session", row.session_name)
          + field("Class teacher", desk.class_teacher)
          + field("Nationality", row.nationality)
          + field("State of origin", row.state_of_origin)
          + field("L.G.A.", row.lga)
          + field("Home", row.home_address)
          + field("Previous school", row.previous_school)
          + field("Blood group", row.blood_group)
          + field("Genotype", row.genotype);
      }
      const guardians = guardiansRoot;
      if (!guardians) return;
      const list = row.guardians || [];
      if (!list.length) {
        guardians.innerHTML = "<p class=\"empty\">No parent or guardian is sealed on this record.</p>";
        return;
      }
      guardians.innerHTML = list.map(function (item) {
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
    let periods = [];
    let lastResults = null;

    const termForSession = function (sessionId) {
      const inSession = periods.filter(function (row) {
        return String(row.academic_session_id) === String(sessionId);
      });
      return inSession.find(function (row) { return row.is_current; }) || inSession[0] || null;
    };

    const loadResults = function (termId) {
      const body = document.querySelector("[data-results-body]");
      let url = "/api/v1/results";
      if (termId) url += "?term_id=" + encodeURIComponent(termId);
      request(url).then(function (result) {
        if (!result.ok) {
          if (body) body.innerHTML = '<tr><td colspan="7">' + escapeHtml(firstError(result.body)) + "</td></tr>";
          return;
        }
        const payload = result.body.data || {};
        lastResults = payload;
        periods = paintResultPicks(payload, periods);
        paintResultSummary(payload);
        if (!body) return;
        paintResultRows(payload, body);
      });
    };

    if (printBtn) printBtn.addEventListener("click", function () {
      printResults(lastResults);
    });
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
    if (row.can_submit) {
      html += '<form class="task-handin" data-assignment-submit="' + escapeHtml(row.id) + '">'
        + '<label class="field"><span>Note to teacher</span><textarea name="notes" rows="2">'
        + escapeHtml(handed && handed.notes ? handed.notes : "") + "</textarea></label>"
        + '<label class="field"><span>Paper</span><input type="file" name="file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"></label>'
        + '<button class="gold-btn" type="submit">' + (handed ? "Replace work" : "Hand in") + "</button>"
        + '<p class="task-status" hidden></p></form>';
    } else if (!handed) {
      html += '<p class="task-meta">Not handed in yet.</p>';
    }
    return html + "</div></article>";
  };

  const wireAssignmentSubmit = function (root, load) {
    if (!root || root.getAttribute("data-wired-submit")) return;
    root.setAttribute("data-wired-submit", "1");
    root.addEventListener("submit", function (event) {
      const form = event.target.closest("[data-assignment-submit]");
      if (!form || !root.contains(form)) return;
      event.preventDefault();
      const data = new FormData();
      const notes = form.querySelector('[name="notes"]');
      if (notes) data.append("notes", notes.value);
      const file = form.querySelector('[name="file"]');
      if (file && file.files[0]) data.append("file", file.files[0]);
      const status = form.querySelector(".task-status");
      const button = form.querySelector('button[type="submit"]');
      if (button) button.disabled = true;
      request("/api/v1/assignments/" + form.getAttribute("data-assignment-submit") + "/submissions", {
        method: "POST",
        body: data
      }).then(function (result) {
        if (button) button.disabled = false;
        if (!result.ok) {
          if (status) {
            status.hidden = false;
            status.textContent = firstError(result.body);
          }
          return;
        }
        load();
      });
    });
  };

  const wireAssignments = function () {
    const root = document.querySelector("[data-assignment-list]");
    const load = function () {
      request("/api/v1/assignments").then(function (result) {
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
          root.innerHTML = "<p class=\"empty\">No work is on the board for your form.</p>";
          return;
        }
        root.innerHTML = rows.map(function (row) { return assignmentCard(row, today); }).join("");
      });
    };
    wireAssignmentSubmit(root, load);
    load();
  };

  const wireAttendance = function () {
    request("/api/v1/attendance/summary").then(function (result) {
      const body = document.querySelector("[data-attendance-body]");
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
    request("/api/v1/me/fees/summary").then(function (result) {
      const body = document.querySelector("[data-fee-invoices]");
      if (!result.ok) {
        if (body) body.innerHTML = '<tr><td colspan="6">' + escapeHtml(firstError(result.body)) + "</td></tr>";
        return;
      }
      const payload = result.body.data || {};
      setText('[data-metric="expected"]', payload.expected || naira(payload.expected_naira));
      setText('[data-metric="collected"]', payload.collected || naira(payload.collected_naira));
      setText('[data-metric="outstanding"]', payload.outstanding || naira(payload.outstanding_naira));
      setText("[data-fee-status]", payload.fee_status_label || pretty(payload.status));
      setText("[data-fee-term]", [payload.session_name, payload.term_name].filter(Boolean).join(" · ") || "Fee ledger");
      const rows = payload.invoices || [];
      if (body) {
        if (!rows.length) {
          body.innerHTML = '<tr><td colspan="6">No invoices are sealed on this admission yet.</td></tr>';
        } else {
          body.innerHTML = rows.map(function (row) {
            return "<tr><td>" + escapeHtml(dash(row.number)) + "</td><td>" + escapeHtml(dash(row.term_name))
              + "</td><td>" + escapeHtml(naira(row.total_naira)) + "</td><td>" + escapeHtml(naira(row.paid_naira))
              + "</td><td>" + escapeHtml(naira(row.balance_naira)) + "</td><td>"
              + escapeHtml(row.fee_status_label || pretty(row.status)) + "</td></tr>";
          }).join("");
        }
      }
    });
    request("/api/v1/me/payments").then(function (result) {
      const body = document.querySelector("[data-fee-payments]");
      if (!body) return;
      if (!result.ok) {
        body.innerHTML = '<tr><td colspan="4">' + escapeHtml(firstError(result.body)) + "</td></tr>";
        return;
      }
      const rows = (result.body.data || []).filter(function (row) { return row.status !== "void"; });
      if (!rows.length) {
        body.innerHTML = '<tr><td colspan="4">No receipts have been posted yet.</td></tr>';
        return;
      }
      body.innerHTML = rows.map(function (row) {
        return "<tr><td>" + escapeHtml(dash(row.reference)) + "</td><td>" + escapeHtml(formatDay(row.paid_at))
          + "</td><td>" + escapeHtml(naira(row.amount_naira)) + "</td><td>" + escapeHtml(pretty(row.channel))
          + "</td></tr>";
      }).join("");
    });
  };

  const wireTimetable = function (desk) {
    const printBtn = document.querySelector("[data-print-timetable]");
    if (printBtn) printBtn.addEventListener("click", printSheet);
    setText("[data-term]", desk && desk.term);
    setText("[data-session]", desk && desk.session);
    setText("[data-form]", desk && desk.form);
    request("/api/v1/classroom/context").then(function (result) {
      const body = document.querySelector("[data-timetable-body]");
      const offerings = (result.ok && result.body.data && result.body.data.offerings) || [];
      const offeringId = offerings[0] && offerings[0].id;
      if (!offeringId) {
        if (body) body.innerHTML = '<tr><td colspan="6">No class is assigned to this desk yet.</td></tr>';
        return;
      }
      if (offerings[0].form) setText("[data-form]", offerings[0].form);
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
    request("/api/v1/learning-materials").then(function (result) {
      if (!result.ok) {
        if (root) root.innerHTML = "<p class=\"empty\">" + escapeHtml(firstError(result.body)) + "</p>";
        return;
      }
      rows = result.body.data || [];
      if (!rows.length) {
        if (root) root.innerHTML = "<p class=\"empty\">No papers have been posted for your form yet.</p>";
        return;
      }
      paint();
    });
    if (search) search.addEventListener("input", paint);
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

    if (list) {
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
    if (sendBtn) {
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
          const body = document.querySelector("[data-message-body]");
          if (subject) subject.value = "";
          if (body) body.value = "";
          openConversation(result.body.data.id);
        });
      });
    }

    refreshList();
  };

  const wireSettings = function (desk) {
    const fields = document.querySelector("[data-settings-fields]");
    const copy = document.querySelector("[data-settings-copy]");
    if (fields) {
      fields.innerHTML = field("Name", desk && desk.full_name)
        + field("Admission number", desk && desk.admission_number)
        + field("Class", desk && desk.form)
        + field("Session", desk && desk.session);
    }
    if (copy) {
      copy.textContent = "Sign in with your name or admission number and your pupil passphrase. Until the office issues one, the parent’s registered phone number still opens this desk.";
    }

    const passwordForm = document.querySelector("[data-password-form]");
    if (passwordForm && !passwordForm.getAttribute("data-wired")) {
      passwordForm.setAttribute("data-wired", "1");
      passwordForm.addEventListener("submit", function (event) {
        event.preventDefault();
        const notice = passwordForm.querySelector(".form-notice") || document.querySelector("[data-password-notice]");
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
          if (notice) notice.textContent = "Passphrase sealed.";
          passwordForm.reset();
        });
      });
    }
  };

  tickClock();
  window.setInterval(tickClock, 1000);

  loadDesk().then(function (desk) {
    if (page === "student_profile") wireProfile(desk);
    if (page === "student_academics") wireAcademics();
    if (page === "student_assignments") wireAssignments();
    if (page === "student_attendance") wireAttendance();
    if (page === "student_fees") wireFees();
    if (page === "student_timetable") wireTimetable(desk);
    if (page === "student_materials") wireMaterials();
    if (page === "student_messages") wireMessages();
    if (page === "student_announcements") wireAnnouncements();
    if (page === "student_settings") wireSettings(desk);
  });
})();
