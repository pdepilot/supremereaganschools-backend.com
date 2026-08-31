(function (root) {
  const escapeHtml = function (value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  };

  const dash = function (value) {
    if (value === null || value === undefined || value === "") return "—";
    return String(value);
  };

  const pretty = function (value) {
    if (!value) return "—";
    return String(value).replace(/_/g, " ").replace(/\b\w/g, function (ch) {
      return ch.toUpperCase();
    });
  };

  const formatScore = function (value) {
    if (value === null || value === undefined || value === "") return "—";
    const number = Number(value);
    if (Number.isNaN(number)) return "—";
    return String(number).replace(/\.0+$/, "").replace(/(\.\d*?)0+$/, "$1");
  };

  const percent = function (value) {
    if (value === null || value === undefined || value === "") return "—";
    return formatScore(value) + "%";
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

  const formatDay = function (value) {
    if (!value) return "—";
    const date = new Date(String(value) + (String(value).indexOf("T") >= 0 ? "" : "T12:00:00"));
    if (Number.isNaN(date.getTime())) return escapeHtml(value);
    return date.toLocaleDateString("en-GB", { day: "numeric", month: "long", year: "numeric" });
  };

  const todayLagos = function () {
    return new Date().toLocaleDateString("en-GB", {
      timeZone: "Africa/Lagos",
      day: "numeric",
      month: "long",
      year: "numeric"
    });
  };

  const meta = function (label, value) {
    return "<div><span>" + escapeHtml(label) + "</span><strong>" + escapeHtml(dash(value)) + "</strong></div>";
  };

  const commentBlock = function (title, text, signatory) {
    const body = text
      ? "<p>" + escapeHtml(text) + "</p>"
      : "<p class=\"term-report-awaiting\">Awaiting " + escapeHtml(title.toLowerCase()) + ".</p>";
    return '<article class="term-report-comment"><h3>' + escapeHtml(title) + "</h3>" + body
      + '<div class="term-report-sign"><span>Signature / Date</span><em>'
      + escapeHtml(signatory || "—") + "</em></div></article>";
  };

  const ensureSheet = function () {
    let node = document.querySelector("[data-report-sheet]");
    if (!node) {
      node = document.createElement("div");
      node.className = "term-report-sheet";
      node.setAttribute("data-report-sheet", "");
      document.body.appendChild(node);
    }
    return node;
  };

  const build = function (payload) {
    const school = payload.school || {};
    const types = payload.assessment_types || [];
    const rows = payload.results || [];
    const comments = payload.comments || {};
    const attendance = payload.attendance || {};
    const logo = school.logo_path || "/site/Image/logo_main.png";
    const address = [school.address, school.phone, school.email].filter(Boolean).join(" · ");
    const colCount = 3 + (types.length || 3);
    const head = "<tr><th>Subject</th>"
      + (types.length ? types.map(function (type) {
        return "<th>" + escapeHtml(type.name) + "</th>";
      }).join("") : "<th>First C.A.</th><th>Second C.A.</th><th>Exam</th>")
      + "<th>Total</th><th>Grade</th><th>Remark</th></tr>";
    const body = rows.length
      ? rows.map(function (row) {
        const papers = (row.scores && row.scores.length)
          ? row.scores
          : types.map(function () { return { score: null }; });
        return "<tr><td>" + escapeHtml(dash(row.subject_name)) + "</td>"
          + papers.map(function (cell) {
            return "<td>" + escapeHtml(formatScore(cell && cell.score)) + "</td>";
          }).join("")
          + "<td><strong>" + escapeHtml(percent(row.total)) + "</strong></td>"
          + "<td>" + escapeHtml(dash(row.grade)) + "</td>"
          + "<td>" + escapeHtml(dash(row.remark)) + "</td></tr>";
      }).join("")
      : '<tr><td colspan="' + colCount + '">No marks have been recorded for this term.</td></tr>';
    const opened = Number(attendance.opened) || 0;
    const roll = opened
      ? (formatScore(attendance.present) + " present of " + opened + " days"
        + (attendance.percentage != null ? " (" + percent(attendance.percentage) + ")" : ""))
      : "No roll has been marked this term.";
    const position = payload.class_position
      ? (ordinal(payload.class_position) + (payload.class_size ? " of " + payload.class_size : ""))
      : "—";

    return '<header class="term-report-head"><img src="' + escapeHtml(logo) + '" alt="">'
      + "<div><p class=\"term-report-kicker\">" + escapeHtml(school.short_name || "SRS") + "</p>"
      + "<h1>" + escapeHtml(school.name || "Supreme Reagan Schools") + "</h1>"
      + (school.motto ? "<p class=\"term-report-motto\">" + escapeHtml(school.motto) + "</p>" : "")
      + (address ? "<p>" + escapeHtml(address) + "</p>" : "")
      + "</div></header>"
      + '<p class="term-report-title">Terminal report sheet</p>'
      + "<p class=\"term-report-period\">" + escapeHtml([payload.term_name, payload.session_name].filter(Boolean).join(" · ") || "Term report") + "</p>"
      + '<div class="term-report-meta">'
      + meta("Pupil", payload.student_name)
      + meta("Admission no.", payload.admission_number)
      + meta("Class", payload.form)
      + meta("Sex", pretty(payload.gender))
      + meta("Average", percent(payload.average))
      + meta("Position", position)
      + "</div>"
      + '<table class="term-report-table"><thead>' + head + "</thead><tbody>" + body + "</tbody></table>"
      + '<p class="term-report-roll"><strong>Attendance</strong> ' + escapeHtml(roll) + "</p>"
      + '<div class="term-report-comments">'
      + commentBlock("Class teacher's comment", comments.class_teacher, payload.class_teacher)
      + commentBlock("Principal's comment", comments.principal, payload.principal)
      + "</div>"
      + '<p class="term-report-resume">'
      + (payload.resumption_on ? "Next term resumes " + escapeHtml(formatDay(payload.resumption_on)) + ". " : "")
      + "Drawn " + escapeHtml(todayLagos()) + ". This sheet is not a certificate.</p>";
  };

  const print = function (payload) {
    if (!payload) return;
    const node = ensureSheet();
    node.innerHTML = build(payload);
    node.hidden = false;
    document.body.classList.add("is-printing-report");
    let finished = false;
    const done = function () {
      if (finished) return;
      finished = true;
      document.body.classList.remove("is-printing-report");
      node.hidden = true;
      window.removeEventListener("afterprint", done);
    };
    window.addEventListener("afterprint", done);
    window.print();
    window.setTimeout(done, 1200);
  };

  root.srsTermReport = { build: build, print: print };
})(window);
