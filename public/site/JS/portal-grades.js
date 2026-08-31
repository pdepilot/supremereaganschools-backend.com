(function () {
  const csrfToken = function () {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : "";
  };

  const request = function (url, options) {
    const headers = Object.assign({
      "Accept": "application/json"
    }, options && options.headers, {
      "X-Requested-With": "XMLHttpRequest",
      "X-XSRF-TOKEN": csrfToken()
    });

    if (options && options.body && !headers["Content-Type"]) {
      headers["Content-Type"] = "application/json";
    }

    return fetch(url, Object.assign({
      credentials: "same-origin"
    }, options, { headers })).then(function (response) {
      return response.json().then(function (body) {
        if (response.status === 401) {
          window.location.replace((window.srsLoginPath && window.srsLoginPath()) || "/portal/login");
        }
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

  const initials = function (value) {
    if (!value) return "—";
    const parts = String(value).trim().split(/\s+/);
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
  };

  const avatars = ["avatar-one", "avatar-two", "avatar-three", "avatar-four", "avatar-five", "avatar-six"];

  const formatScore = function (value) {
    if (value === null || value === undefined || value === "") return "—";
    const number = Number(value);
    if (Number.isNaN(number)) return "—";
    return String(number).replace(/\.0+$/, "").replace(/(\.\d*?)0+$/, "$1");
  };

  const percentLabel = function (value) {
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

  const gradeClass = function (grade) {
    const letter = String(grade || "").toLowerCase();
    if (letter === "a") return "excellent";
    if (letter === "b") return "good";
    return letter || "";
  };

  const printTermReport = function (payload) {
    if (window.srsTermReport && payload) {
      window.srsTermReport.print(payload);
    }
  };

  const paintReportComments = function (root, payload) {
    if (!root) return;
    const box = root.querySelector("[data-report-comments]") || root;
    const classField = root.querySelector("[data-class-teacher-comment]");
    const principalField = root.querySelector("[data-principal-comment]");
    const save = root.querySelector("[data-save-report-comments]");
    const printBtn = root.querySelector("[data-print-report-sheet]");
    const can = (payload && payload.can_comment) || {};
    if (root.hasAttribute("data-report-comments") || box !== root) {
      if (box && box.hasAttribute("data-report-comments")) {
        box.hidden = !(payload && payload.enrollment_id);
      }
    }
    if (classField) {
      classField.value = (payload.comments && payload.comments.class_teacher) || "";
      classField.disabled = !can.class_teacher;
    }
    if (principalField) {
      principalField.value = (payload.comments && payload.comments.principal) || "";
      principalField.disabled = !can.principal;
    }
    if (save) save.hidden = !(can.class_teacher || can.principal);
    if (printBtn) printBtn.hidden = !(payload && payload.enrollment_id);
  };

  const saveReportComments = function (payload, root) {
    const can = (payload && payload.can_comment) || {};
    const body = {
      enrollment_id: payload.enrollment_id,
      term_id: payload.term_id
    };
    if (can.class_teacher) {
      const field = root.querySelector("[data-class-teacher-comment]");
      body.class_teacher_comment = field ? field.value : "";
    }
    if (can.principal) {
      const field = root.querySelector("[data-principal-comment]");
      body.principal_comment = field ? field.value : "";
    }
    return request("/api/v1/results/comments", {
      method: "PUT",
      body: JSON.stringify(body)
    });
  };

  const gradeFromScales = function (percent, scales) {
    if (percent === null || percent === undefined || Number.isNaN(percent)) {
      return { grade: "—", remark: "—" };
    }
    const match = (scales || []).find(function (scale) {
      return percent >= Number(scale.min_score) && percent <= Number(scale.max_score);
    });
    return match
      ? { grade: match.grade, remark: match.remark }
      : { grade: "—", remark: "—" };
  };

  const fillSelect = function (select, items, valueKey, labelKey, selected) {
    if (!select) return;
    select.innerHTML = (items || []).map(function (item) {
      const value = item[valueKey];
      const selectedAttr = String(value) === String(selected) ? " selected" : "";
      return '<option value="' + value + '"' + selectedAttr + ">" + item[labelKey] + "</option>";
    }).join("") || "<option value=\"\">None available</option>";
  };

  const wireStaffGrades = function () {
    const classSelect = document.getElementById("classSelect");
    const subjectSelect = document.getElementById("subjectSelect");
    const assessmentSelect = document.getElementById("assessmentSelect");
    const sessionSelect = document.getElementById("sessionSelect");
    const body = document.getElementById("gradesBody");
    const saveButton = document.getElementById("saveGrades");
    if (!classSelect || !subjectSelect || !assessmentSelect || !body) return;

    let contexts = { offerings: [], assessment_types: [], grade_scales: [], sessions: [] };
    let register = null;

    const selectedOffering = function () {
      const id = Number(classSelect.value);
      return (contexts.offerings || []).filter(function (item) { return item.id === id; })[0];
    };

    const selectedType = function () {
      if (assessmentSelect.value === "results") return null;
      const id = Number(assessmentSelect.value);
      return (contexts.assessment_types || []).filter(function (item) { return item.id === id; })[0];
    };

    let reportPayload = null;

    const openTermReport = function (studentId) {
      const panel = document.querySelector("[data-term-report]");
      if (!panel || !studentId) return;
      panel.hidden = false;
      const title = panel.querySelector("[data-term-report-title]");
      const copy = panel.querySelector("[data-term-report-copy]");
      if (copy) copy.textContent = "Opening the term report…";
      request("/api/v1/results?student_profile_id=" + encodeURIComponent(studentId)
        + (register && register.term_id ? "&term_id=" + encodeURIComponent(register.term_id) : ""))
        .then(function (result) {
          if (!result.ok) {
            reportPayload = null;
            if (copy) copy.textContent = firstError(result.body);
            return;
          }
          reportPayload = result.body.data || {};
          if (title) title.textContent = reportPayload.student_name || "Term report";
          if (copy) {
            copy.textContent = [reportPayload.admission_number, reportPayload.form, reportPayload.term_name]
              .filter(Boolean).join(" · ") || "Write comments, then print the sheet.";
          }
          paintReportComments(panel, reportPayload);
        });
    };

    const reportPanel = document.querySelector("[data-term-report]");
    if (reportPanel) {
      const saveComments = reportPanel.querySelector("[data-save-report-comments]");
      const printBtn = reportPanel.querySelector("[data-print-report-sheet]");
      if (saveComments) {
        saveComments.addEventListener("click", function () {
          if (!reportPayload || !reportPayload.enrollment_id) return;
          saveComments.disabled = true;
          saveReportComments(reportPayload, reportPanel).then(function (result) {
            saveComments.disabled = false;
            if (!result.ok) {
              window.alert(firstError(result.body));
              return;
            }
            reportPayload = result.body.data || reportPayload;
            paintReportComments(reportPanel, reportPayload);
          });
        });
      }
      if (printBtn) {
        printBtn.addEventListener("click", function () {
          printTermReport(reportPayload);
        });
      }
    }

    const setSummary = function (summary) {
      const total = document.getElementById("studentTotal");
      const average = document.getElementById("classAverage");
      const highest = document.getElementById("highestScore");
      const completed = document.getElementById("completedPercent");
      if (total) total.textContent = summary && summary.total != null ? summary.total : "0";
      if (average) average.textContent = percentLabel(summary && summary.average);
      if (highest) highest.textContent = percentLabel(summary && summary.highest);
      if (completed) completed.textContent = (summary && summary.completed_percent != null ? summary.completed_percent : 0) + "%";
    };

    const updateRowPreview = function (input) {
      const max = Number(input.getAttribute("max") || 100);
      let score = input.value === "" ? null : Number(input.value);
      if (score !== null && !Number.isNaN(score)) {
        score = Math.max(0, Math.min(max, score));
        input.value = score;
      }
      const percent = score === null || Number.isNaN(score) || max <= 0 ? null : (score / max) * 100;
      const result = register && register.view === "results"
        ? { grade: input.closest("tr").dataset.grade, remark: input.closest("tr").dataset.remark }
        : gradeFromScales(percent, contexts.grade_scales);
      const row = input.closest("tr");
      const badge = row.querySelector(".grade-badge");
      const remark = row.querySelector(".remark");
      const fill = row.querySelector(".score-fill");
      const letter = result.grade && result.grade !== "—" ? result.grade : "—";
      if (badge) {
        badge.textContent = letter;
        badge.className = "grade-badge" + (letter !== "—" ? " grade-" + letter.toLowerCase() : "");
      }
      if (remark) remark.textContent = result.remark || "—";
      if (fill) fill.style.width = (percent || 0) + "%";
    };

    const renderRows = function (payload) {
      register = payload;
      const students = payload.students || [];
      const max = payload.max_score || 100;
      const canEnter = !!payload.can_enter;
      const footer = document.querySelector(".grades-footer span");
      const heading = document.querySelector(".grades-toolbar h4");
      const subtitle = document.querySelector(".grades-toolbar p");
      const performance = document.querySelector(".performance-heading p");
      const performanceAvg = document.querySelector(".performance-average");
      const scoreHeader = document.getElementById("scoreColumnHeader");
      const type = payload.assessment_type;

      if (heading) heading.textContent = (payload.subject_name || "Subject") + " — " + (payload.form || "Class");
      if (subtitle) {
        subtitle.textContent = payload.view === "results"
          ? (payload.term_name || "Term") + " final totals"
          : (type ? type.name + " · max " + formatScore(type.max_score) : "Assessment");
      }
      if (performance) {
        performance.textContent = [payload.subject_name, payload.view === "results" ? "Final Result" : (type && type.name), payload.form]
          .filter(Boolean).join(" • ");
      }
      if (performanceAvg) {
        performanceAvg.textContent = percentLabel(payload.summary && payload.summary.average) + " Average";
      }
      if (scoreHeader) {
        scoreHeader.textContent = payload.view === "results" ? "Total" : (type ? type.name : "Score");
      }
      if (saveButton) {
        saveButton.disabled = !canEnter;
        saveButton.style.display = canEnter ? "" : "none";
        if (payload.can_amend) saveButton.textContent = "Revise marks";
        else if (canEnter) saveButton.textContent = "Seal marks";
      }

      if (!students.length) {
        body.innerHTML = '<tr><td colspan="7">No pupils are enrolled in this class.</td></tr>';
        setSummary(payload.summary);
        if (footer) footer.innerHTML = "Showing <strong>0</strong> of <strong>0</strong> students";
        const emptyPanel = document.querySelector("[data-term-report]");
        if (emptyPanel) emptyPanel.hidden = payload.view !== "results";
        return;
      }

      body.innerHTML = students.map(function (row, index) {
        const score = row.score;
        const letter = row.grade || "—";
        const width = score == null || max <= 0 ? 0 : Math.max(0, Math.min(100, (Number(score) / max) * 100));
        const rowEnter = !!row.can_enter;
        const valueAttr = score == null ? "" : ' value="' + formatScore(score) + '"';
        const sealed = row.recorded && !rowEnter
          ? '<small class="sealed-mark">Sealed</small>'
          : (row.entered_by ? '<small class="sealed-mark">By ' + escapeHtml(row.entered_by) + "</small>" : "");
        const nameHtml = payload.view === "results" && document.querySelector("[data-term-report]")
          ? '<button type="button" class="report-open" data-open-report="' + row.student_profile_id + '">'
            + escapeHtml(row.full_name || "—") + "</button>"
          : "<strong>" + (row.full_name || "—") + "</strong>";
        return "<tr data-enrollment=\"" + row.enrollment_id + "\" data-grade=\"" + (row.grade || "") + "\" data-remark=\"" + (row.remark || "") + "\" data-recorded=\"" + (row.recorded ? "1" : "0") + "\">"
          + "<td>" + String(index + 1).padStart(2, "0") + "</td>"
          + '<td><div class="grade-student"><div class="grade-avatar ' + avatars[index % avatars.length] + '">'
          + initials(row.full_name) + "</div>" + nameHtml + sealed + "</div></td>"
          + "<td>" + (row.admission_number || "—") + "</td>"
          + "<td>" + (rowEnter
            ? '<input class="score-input" type="number" min="0" max="' + max + '" step="0.5"' + valueAttr + ">"
            : "<strong>" + formatScore(score) + "</strong>")
          + "</td>"
          + '<td><span class="grade-badge' + (letter !== "—" ? " grade-" + String(letter).toLowerCase() : "") + '">' + letter + "</span></td>"
          + '<td><span class="remark">' + (row.remark || "—") + "</span></td>"
          + '<td><div class="score-bar"><div class="score-fill" style="width:' + width + '%;"></div></div></td>'
          + "</tr>";
      }).join("");

      body.querySelectorAll(".score-input").forEach(function (input) {
        input.addEventListener("input", function () {
          updateRowPreview(input);
        });
      });

      const reportPanel = document.querySelector("[data-term-report]");
      if (reportPanel) reportPanel.hidden = payload.view !== "results";
      body.querySelectorAll("[data-open-report]").forEach(function (button) {
        button.addEventListener("click", function () {
          openTermReport(button.getAttribute("data-open-report"));
        });
      });

      setSummary(payload.summary);
      if (footer) {
        footer.innerHTML = "Showing <strong>" + students.length + "</strong> of <strong>" + students.length + "</strong> students";
      }
    };

    const loadRegister = function () {
      const offering = selectedOffering();
      if (!offering || !subjectSelect.value) {
        body.innerHTML = '<tr><td colspan="7">Select a class and subject to load scores.</td></tr>';
        return;
      }
      const params = new URLSearchParams({
        class_section_offering_id: String(offering.id),
        subject_id: subjectSelect.value
      });
      if (sessionSelect && sessionSelect.value) {
        params.set("academic_session_id", sessionSelect.value);
      }
      if (assessmentSelect.value === "results") {
        params.set("view", "results");
      } else if (assessmentSelect.value) {
        params.set("assessment_type_id", assessmentSelect.value);
      }
      request("/api/v1/grades/register?" + params.toString()).then(function (result) {
        if (!result.ok) {
          body.innerHTML = '<tr><td colspan="7">' + firstError(result.body) + "</td></tr>";
          return;
        }
        renderRows(result.body.data || {});
      });
    };

    const syncSubjects = function () {
      const offering = selectedOffering();
      const subjects = offering ? offering.subjects || [] : [];
      const current = subjectSelect.value;
      fillSelect(subjectSelect, subjects, "id", "name", current);
      if (!subjectSelect.value && subjects[0]) subjectSelect.value = String(subjects[0].id);
    };

    const syncSessionToClass = function () {
      const offering = selectedOffering();
      if (offering && sessionSelect && offering.academic_session_id) {
        sessionSelect.value = String(offering.academic_session_id);
      }
    };

    classSelect.addEventListener("change", function () {
      syncSessionToClass();
      syncSubjects();
      loadRegister();
    });
    subjectSelect.addEventListener("change", loadRegister);
    assessmentSelect.addEventListener("change", loadRegister);
    if (sessionSelect) sessionSelect.addEventListener("change", loadRegister);

    if (saveButton) {
      saveButton.addEventListener("click", function () {
        if (!register || !register.can_enter) return;
        const offering = selectedOffering();
        const type = selectedType();
        if (!offering || !type) return;
        const scores = [];
        body.querySelectorAll("tr[data-enrollment]").forEach(function (row) {
          const input = row.querySelector(".score-input");
          if (!input && row.getAttribute("data-recorded") === "1") {
            return;
          }
          scores.push({
            enrollment_id: Number(row.getAttribute("data-enrollment")),
            score: input && input.value !== "" ? Number(input.value) : null
          });
        });
        if (!scores.length) return;
        saveButton.disabled = true;
        const bodyPayload = {
          class_section_offering_id: offering.id,
          subject_id: Number(subjectSelect.value),
          assessment_type_id: type.id,
          scores: scores
        };
        if (register.term_id) bodyPayload.term_id = register.term_id;
        else if (sessionSelect && sessionSelect.value) bodyPayload.academic_session_id = Number(sessionSelect.value);
        else bodyPayload.academic_session_id = offering.academic_session_id;
        request("/api/v1/grades/bulk", {
          method: "POST",
          body: JSON.stringify(bodyPayload)
        }).then(function (result) {
          saveButton.disabled = false;
          if (!result.ok) {
            window.alert(firstError(result.body));
            return;
          }
          const original = saveButton.innerHTML;
          saveButton.innerHTML = '<i class="bi bi-check-circle-fill"></i> Grades Saved';
          setTimeout(function () { saveButton.innerHTML = original; }, 2000);
          loadRegister();
        });
      });
    }

    const exportButton = document.getElementById("exportButton");
    if (exportButton) {
      exportButton.addEventListener("click", function () {
        const rows = [["#", "Student", "Admission number", "Score", "Grade", "Remark"]];
        body.querySelectorAll("tr[data-enrollment]").forEach(function (row, index) {
          const name = (row.querySelector("strong") || {}).textContent || "";
          const admission = row.children[2] ? row.children[2].textContent.trim() : "";
          const input = row.querySelector(".score-input");
          const score = input ? input.value : (row.children[3] ? row.children[3].textContent.trim() : "");
          const grade = (row.querySelector(".grade-badge") || {}).textContent || "";
          const remark = (row.querySelector(".remark") || {}).textContent || "";
          rows.push([String(index + 1), name.trim(), admission, score, grade.trim(), remark.trim()]);
        });
        const csv = rows.map(function (line) {
          return line.map(function (cell) { return '"' + String(cell).replace(/"/g, '""') + '"'; }).join(",");
        }).join("\n");
        const blob = new Blob([csv], { type: "text/csv" });
        const url = URL.createObjectURL(blob);
        const link = document.createElement("a");
        link.href = url;
        link.download = "grades.csv";
        link.click();
        URL.revokeObjectURL(url);
      });
    }

    const search = document.getElementById("gradeSearch");
    if (search) {
      search.addEventListener("keyup", function () {
        const value = this.value.toLowerCase();
        let visible = 0;
        body.querySelectorAll("tr").forEach(function (row) {
          const show = !value || row.innerText.toLowerCase().indexOf(value) !== -1;
          row.style.display = show ? "" : "none";
          if (show) visible += 1;
        });
        const footer = document.querySelector(".grades-footer span");
        if (footer) {
          footer.innerHTML = "Showing <strong>" + visible + "</strong> of <strong>"
            + body.querySelectorAll("tr").length + "</strong> students";
        }
      });
    }

    const filter = document.getElementById("gradeFilter");
    if (filter) {
      filter.addEventListener("change", function () {
        const selected = this.value;
        body.querySelectorAll("tr").forEach(function (row) {
          const badge = row.querySelector(".grade-badge");
          const grade = badge ? badge.textContent.trim() : "";
          row.style.display = selected === "all" || grade === selected ? "" : "none";
        });
      });
    }

    request("/api/v1/grades/contexts").then(function (result) {
      if (!result.ok) {
        body.innerHTML = '<tr><td colspan="7">' + firstError(result.body) + "</td></tr>";
        return;
      }
      contexts = result.body.data || contexts;
      fillSelect(classSelect, (contexts.offerings || []).map(function (item) {
        return Object.assign({}, item, {
          form: (item.form || "Form") + (item.session_name ? " · " + item.session_name : "")
        });
      }), "id", "form");
      fillSelect(sessionSelect, contexts.sessions || [], "id", "name", contexts.current_academic_session_id);
      const typeOptions = (contexts.assessment_types || []).map(function (type) {
        return { id: type.id, name: type.name };
      }).concat([{ id: "results", name: "Final Result" }]);
      fillSelect(assessmentSelect, typeOptions, "id", "name");
      syncSessionToClass();
      syncSubjects();
      loadRegister();
    });
  };

  const wireParentResults = function () {
    const table = document.querySelector(".performance-table");
    if (!table) return;

    const setText = function (node, value) {
      if (node) node.textContent = value;
    };

    const render = function (payload) {
      table._lastResults = payload;
      const cards = document.querySelectorAll(".stats-grid .stat-card");
      if (cards[0]) {
        setText(cards[0].querySelector("h2"), percentLabel(payload.average));
        setText(cards[0].querySelector("small"), payload.average != null && Number(payload.average) >= 75
          ? "Excellent performance" : "Term average");
      }
      if (cards[1]) {
        setText(cards[1].querySelector("h2"), ordinal(payload.class_position));
        setText(cards[1].querySelector("small"), payload.class_size
          ? ("Out of " + payload.class_size + " students") : "Class position");
      }
      if (cards[2]) {
        setText(cards[2].querySelector("h2"), percentLabel(payload.highest && payload.highest.total));
        setText(cards[2].querySelector("small"), (payload.highest && payload.highest.subject_name) || "Highest subject");
      }

      const heading = document.querySelector(".card-header-custom p");
      setText(heading, (payload.session_name || "Academic session") + " · " + (payload.term_name || "Term"));

      const body = table.querySelector("tbody");
      const rows = payload.results || [];
      if (body) {
        if (!rows.length) {
          body.innerHTML = '<tr><td colspan="6">No results have been recorded for this term yet.</td></tr>';
        } else {
          body.innerHTML = rows.map(function (row) {
            const letter = row.grade || "—";
            return "<tr><td>" + (row.subject_name || "—") + "</td><td>" + formatScore(row.ca_total)
              + "</td><td>" + formatScore(row.exam_score) + "</td><td><strong>" + percentLabel(row.total)
              + "</strong></td><td><span class=\"grade " + gradeClass(letter) + "\">" + letter
              + "</span></td><td>" + (row.remark || "—") + "</td></tr>";
          }).join("");
        }
      }

      const remark = document.querySelector(".remark-card p");
      const remarkBy = document.querySelector(".remark-card small");
      const classComment = payload.comments && payload.comments.class_teacher;
      if (remark) {
        remark.textContent = classComment
          || "Subject remarks are shown on each result line. A class-teacher comment is not recorded for this term.";
      }
      if (remarkBy) remarkBy.textContent = payload.class_teacher ? ("— " + payload.class_teacher) : "— Class Teacher";
    };

    const loadResults = function (studentId) {
      const query = studentId ? ("?student_profile_id=" + encodeURIComponent(studentId)) : "";
      request("/api/v1/results" + query).then(function (result) {
        if (!result.ok) {
          const body = table.querySelector("tbody");
          if (body) body.innerHTML = '<tr><td colspan="6">' + firstError(result.body) + "</td></tr>";
          return;
        }
        render(result.body.data || {});
      });
    };

    const download = document.querySelector(".download-btn");
    if (download) {
      download.addEventListener("click", function () {
        printTermReport(table._lastResults);
      });
    }

    const selector = document.getElementById("studentSelect");
    request("/api/v1/me/children").then(function (result) {
      if (result.status === 403) {
        const wrap = document.querySelector(".student-selector");
        if (wrap) wrap.style.display = "none";
        loadResults(null);
        return;
      }
      if (!result.ok) {
        loadResults(null);
        return;
      }
      const children = result.body.data || [];
      if (!selector) {
        loadResults(children[0] ? children[0].id : null);
        return;
      }
      if (!children.length) {
        selector.innerHTML = "<option>No linked children</option>";
        return;
      }
      selector.innerHTML = children.map(function (child) {
        return '<option value="' + child.id + '">' + (child.full_name || "Child") + "</option>";
      }).join("");
      selector.addEventListener("change", function () {
        loadResults(selector.value);
      });
      loadResults(children[0].id);
    });
  };

  const wireAdminPupilResults = function () {
    const root = document.querySelector("[data-admin-results]");
    if (!root) return;

    const input = root.querySelector("[data-grade-lookup-q]");
    const hits = root.querySelector("[data-grade-lookup-results]");
    const body = root.querySelector("[data-pupil-results-body]");
    const head = root.querySelector("[data-pupil-results-head]");
    const copy = root.querySelector("[data-pupil-results-copy]");
    const meta = root.querySelector("[data-pupil-results-meta]");
    const save = root.querySelector("[data-pupil-results-save]");
    if (!input || !hits || !body) return;

    let timer = null;
    let current = null;

    const paintResults = function (payload) {
      current = payload;
      const types = payload.assessment_types || [];
      const amend = !!payload.can_amend && !!payload.enrollment_id;
      if (save) save.hidden = !amend;
      const printBtn = root.querySelector("[data-print-report-sheet]");
      if (printBtn) printBtn.hidden = !payload.enrollment_id;
      paintReportComments(root, payload);
      if (copy) {
        copy.textContent = payload.student_name
          ? (payload.student_name + (payload.admission_number ? " · " + payload.admission_number : ""))
          : "Search a name or admission number to open every recorded mark for that pupil.";
      }
      if (meta) {
        const bits = [payload.form, payload.session_name, payload.term_name].filter(Boolean);
        const average = payload.average == null ? "" : "Average " + percentLabel(payload.average);
        meta.textContent = [bits.join(" · "), average].filter(Boolean).join(" · ")
          || "No enrolment found for this pupil on the selected year.";
      }
      const colCount = 3 + types.length;
      if (head) {
        head.innerHTML = "<tr><th>Subject</th>"
          + types.map(function (type) { return "<th>" + escapeHtml(type.name) + "</th>"; }).join("")
          + "<th>Total</th><th>Grade</th><th>Remark</th></tr>";
      }
      const rows = payload.results || [];
      if (!rows.length) {
        body.innerHTML = '<tr><td colspan="' + colCount + '">No subjects are offered for this pupil yet.</td></tr>';
        return;
      }
      body.innerHTML = rows.map(function (row) {
        const letter = row.grade || "—";
        const cells = (row.scores && row.scores.length ? row.scores : types.map(function (type) {
          return { assessment_type_id: type.id, max_score: type.max_score, score: null };
        })).map(function (cell) {
          const max = cell.max_score || 100;
          const valueAttr = cell.score == null ? "" : ' value="' + formatScore(cell.score) + '"';
          if (!amend) return "<td>" + formatScore(cell.score) + "</td>";
          return '<td><input class="score-input" type="number" min="0" max="' + max
            + '" step="0.5" data-type="' + cell.assessment_type_id + '"' + valueAttr + "></td>";
        }).join("");
        return '<tr data-subject="' + row.subject_id + '"><td>' + escapeHtml(row.subject_name || "—") + "</td>"
          + cells
          + "<td><strong>" + formatScore(row.total) + "</strong></td>"
          + '<td><span class="grade-badge' + (letter !== "—" ? " grade-" + String(letter).toLowerCase() : "") + '">'
          + letter + "</span></td><td>" + escapeHtml(row.remark || "—") + "</td></tr>";
      }).join("");
    };

    const loadPupil = function (id) {
      if (copy) copy.textContent = "Opening the term ledger…";
      request("/api/v1/results?student_profile_id=" + encodeURIComponent(id)).then(function (result) {
        if (!result.ok) {
          current = null;
          if (save) save.hidden = true;
          body.innerHTML = '<tr><td colspan="6">' + firstError(result.body) + "</td></tr>";
          return;
        }
        paintResults(result.body.data || {});
      });
    };

    const saveComments = root.querySelector("[data-save-report-comments]");
    const printSheetBtn = root.querySelector("[data-print-report-sheet]");
    if (saveComments) {
      saveComments.addEventListener("click", function () {
        if (!current || !current.enrollment_id) return;
        saveComments.disabled = true;
        saveReportComments(current, root).then(function (result) {
          saveComments.disabled = false;
          if (!result.ok) {
            window.alert(firstError(result.body));
            return;
          }
          paintResults(result.body.data || {});
        });
      });
    }
    if (printSheetBtn) {
      printSheetBtn.addEventListener("click", function () {
        printTermReport(current);
      });
    }

    if (save) {
      save.addEventListener("click", function () {
        if (!current || !current.can_amend || !current.enrollment_id) return;
        const jobs = [];
        body.querySelectorAll("tr[data-subject]").forEach(function (row) {
          row.querySelectorAll(".score-input").forEach(function (field) {
            if (field.value === "") return;
            jobs.push(request("/api/v1/grades", {
              method: "POST",
              body: JSON.stringify({
                enrollment_id: current.enrollment_id,
                subject_id: Number(row.getAttribute("data-subject")),
                assessment_type_id: Number(field.getAttribute("data-type")),
                term_id: current.term_id,
                score: Number(field.value)
              })
            }));
          });
        });
        if (!jobs.length) return;
        save.disabled = true;
        Promise.all(jobs).then(function (results) {
          save.disabled = false;
          const failed = results.find(function (item) { return !item.ok; });
          if (failed) {
            window.alert(firstError(failed.body));
            return;
          }
          loadPupil(current.student_profile_id);
        });
      });
    }

    input.addEventListener("input", function () {
      window.clearTimeout(timer);
      timer = window.setTimeout(function () {
        const query = (input.value || "").trim();
        if (query.length < 2) {
          hits.innerHTML = "";
          return;
        }
        request("/api/v1/students?limit=8&q=" + encodeURIComponent(query)).then(function (result) {
          if ((input.value || "").trim() !== query) return;
          if (!result.ok) {
            hits.innerHTML = "";
            return;
          }
          const rows = result.body.data || [];
          if (!rows.length) {
            hits.innerHTML = "<p>No pupil matches that search.</p>";
            return;
          }
          hits.innerHTML = rows.map(function (row) {
            const line = [row.admission_number, row.current_form || row.level_name || "Unplaced"].filter(Boolean).join(" · ");
            return '<button type="button" class="lookup-hit" data-grade-lookup-id="' + row.id + '"><div><strong>'
              + escapeHtml(row.full_name || "—") + "</strong><small>" + escapeHtml(line) + "</small></div><span>Open</span></button>";
          }).join("");
        });
      }, 280);
    });

    hits.addEventListener("click", function (event) {
      const hit = event.target.closest("[data-grade-lookup-id]");
      if (!hit) return;
      hits.querySelectorAll(".lookup-hit").forEach(function (node) {
        node.classList.toggle("is-active", node === hit);
      });
      loadPupil(hit.getAttribute("data-grade-lookup-id"));
    });
  };

  wireStaffGrades();
  wireParentResults();
  wireAdminPupilResults();
})();
