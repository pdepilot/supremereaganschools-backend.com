(function () {
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

  const formatDate = function (value) {
    if (!value) return "—";
    const date = new Date(value + "T00:00:00");
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleDateString("en-GB", {
      weekday: "long",
      day: "numeric",
      month: "long",
      year: "numeric"
    });
  };

  const shortDate = function (value) {
    if (!value) return "—";
    const date = new Date(value + "T00:00:00");
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" });
  };

  const weekday = function (value) {
    if (!value) return "—";
    const date = new Date(value + "T00:00:00");
    if (Number.isNaN(date.getTime())) return "—";
    return date.toLocaleDateString("en-GB", { weekday: "long" });
  };

  const monthLabel = function (value) {
    const date = new Date(value + "-01T00:00:00");
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleDateString("en-GB", { month: "long", year: "numeric" });
  };

  const percentLabel = function (value) {
    if (value === null || value === undefined || value === "") return "0%";
    return String(value).replace(/\.0$/, "") + "%";
  };

  const statusLabel = function (status) {
    if (status === "present") return "Present";
    if (status === "absent") return "Absent";
    if (status === "late") return "Late";
    return status ? String(status) : "—";
  };

  const wireStaffRegister = function () {
    const dateInput = document.getElementById("attendanceDate");
    const classSelect = document.getElementById("attendanceClass");
    const list = document.querySelector(".attendance-students");
    const saveButton = document.getElementById("saveAttendance");
    if (!dateInput || !classSelect || !list) return;

    const title = document.querySelector(".attendance-list-header h4");
    const subtitle = document.querySelector(".attendance-list-header p");
    const footer = document.querySelector(".attendance-footer span");
    const avatars = ["avatar-one", "avatar-two", "avatar-three", "avatar-four", "avatar-five", "avatar-six"];
    let original = {};
    let canMark = false;

    const setCounts = function (summary, visibleCount) {
      const total = document.getElementById("totalStudents");
      const present = document.getElementById("presentCount");
      const absent = document.getElementById("absentCount");
      const late = document.getElementById("lateCount");
      if (total) total.textContent = summary && summary.total != null ? summary.total : "0";
      if (present) present.textContent = summary && summary.present != null ? summary.present : "0";
      if (absent) absent.textContent = summary && summary.absent != null ? summary.absent : "0";
      if (late) late.textContent = summary && summary.late != null ? summary.late : "0";
      if (footer) {
        const shown = visibleCount != null ? visibleCount : (summary && summary.total) || 0;
        footer.innerHTML = "Showing <strong>" + shown + "</strong> of <strong>"
          + ((summary && summary.total) || 0) + "</strong> students";
      }
    };

    const recount = function () {
      let present = 0;
      let absent = 0;
      let late = 0;
      const rows = list.querySelectorAll(".attendance-student");
      rows.forEach(function (row) {
        if (row.style.display === "none") return;
        const active = row.querySelector(".attendance-status.active");
        if (!active) return;
        if (active.dataset.status === "present") present += 1;
        if (active.dataset.status === "absent") absent += 1;
        if (active.dataset.status === "late") late += 1;
      });
      const presentNode = document.getElementById("presentCount");
      const absentNode = document.getElementById("absentCount");
      const lateNode = document.getElementById("lateCount");
      if (presentNode) presentNode.textContent = String(present);
      if (absentNode) absentNode.textContent = String(absent);
      if (lateNode) lateNode.textContent = String(late);
    };

    const render = function (payload) {
      const students = (payload && payload.students) || [];
      original = {};
      canMark = !!(payload && payload.can_mark);
      if (title) title.textContent = (payload.form || "Class") + " Attendance";
      if (subtitle) subtitle.textContent = formatDate(payload.marked_on);
      if (saveButton) saveButton.disabled = !canMark;
      const markAll = document.getElementById("markAllPresent");
      if (markAll) markAll.disabled = !canMark;

      if (!students.length) {
        list.innerHTML = '<p class="breadcrumb-text">No pupils are on the roll for this date.</p>';
        setCounts(payload.summary, 0);
        return;
      }

      list.innerHTML = students.map(function (row, index) {
        const status = (row.attendance && row.attendance.status) || "";
        original[row.enrollment_id] = status;
        const name = row.full_name || "Pupil";
        return '<div class="attendance-student" data-name="' + name.replace(/"/g, "") + '" data-enrollment="' + row.enrollment_id + '">'
          + '<div class="attendance-student-info">'
          + '<div class="student-avatar ' + avatars[index % avatars.length] + '">' + initials(name) + "</div>"
          + "<div><strong>" + name + "</strong><span>" + (row.admission_number || "—") + "</span></div>"
          + "</div>"
          + '<div class="attendance-actions">'
          + '<button type="button" class="attendance-status present' + (status === "present" ? " active" : "") + '" data-status="present">Present</button>'
          + '<button type="button" class="attendance-status absent' + (status === "absent" ? " active" : "") + '" data-status="absent">Absent</button>'
          + '<button type="button" class="attendance-status late' + (status === "late" ? " active" : "") + '" data-status="late">Late</button>'
          + "</div></div>";
      }).join("");

      setCounts(payload.summary, students.length);
      recount();
    };

    const loadRegister = function () {
      const offeringId = classSelect.value;
      const markedOn = dateInput.value;
      if (!offeringId || !markedOn) {
        list.innerHTML = '<p class="breadcrumb-text">Choose a class and date to load the register.</p>';
        return;
      }

      request("/api/v1/attendance/register?class_section_offering_id=" + encodeURIComponent(offeringId)
        + "&marked_on=" + encodeURIComponent(markedOn)).then(function (result) {
        if (!result.ok) {
          list.innerHTML = '<p class="breadcrumb-text">' + firstError(result.body) + "</p>";
          setCounts({ total: 0, present: 0, absent: 0, late: 0 }, 0);
          return;
        }
        render(result.body.data || {});
      });
    };

    list.addEventListener("click", function (event) {
      const button = event.target.closest(".attendance-status");
      if (!button || !canMark) return;
      const parent = button.closest(".attendance-actions");
      if (!parent) return;
      parent.querySelectorAll(".attendance-status").forEach(function (item) {
        item.classList.remove("active");
      });
      button.classList.add("active");
      recount();
    });

    const markAll = document.getElementById("markAllPresent");
    if (markAll) {
      markAll.addEventListener("click", function () {
        if (!canMark) return;
        list.querySelectorAll(".attendance-student").forEach(function (student) {
          student.querySelectorAll(".attendance-status").forEach(function (button) {
            button.classList.remove("active");
          });
          const present = student.querySelector('[data-status="present"]');
          if (present) present.classList.add("active");
        });
        recount();
      });
    }

    const search = document.getElementById("attendanceSearch");
    if (search) {
      search.addEventListener("keyup", function () {
        const value = this.value.toLowerCase();
        let visible = 0;
        list.querySelectorAll(".attendance-student").forEach(function (student) {
          const name = (student.dataset.name || "").toLowerCase();
          const show = !value || name.indexOf(value) !== -1;
          student.style.display = show ? "" : "none";
          if (show) visible += 1;
        });
        if (footer) {
          const total = list.querySelectorAll(".attendance-student").length;
          footer.innerHTML = "Showing <strong>" + visible + "</strong> of <strong>" + total + "</strong> students";
        }
        recount();
      });
    }

    if (saveButton) {
      saveButton.addEventListener("click", function () {
        if (!canMark) return;
        const rows = [];
        let changed = false;
        list.querySelectorAll(".attendance-student").forEach(function (student) {
          const active = student.querySelector(".attendance-status.active");
          if (!active) return;
          const enrollmentId = Number(student.dataset.enrollment);
          const status = active.dataset.status;
          rows.push({ enrollment_id: enrollmentId, status: status });
          const previous = original[enrollmentId] || "";
          if (previous && previous !== status) changed = true;
        });

        if (!rows.length) {
          window.alert("Mark at least one pupil before saving.");
          return;
        }

        const payload = {
          class_section_offering_id: Number(classSelect.value),
          marked_on: dateInput.value,
          records: rows
        };

        if (changed) {
          const reason = window.prompt("One or more marks have changed. Please enter a reason for the correction.");
          if (!reason || !reason.trim()) return;
          payload.correction_reason = reason.trim();
        }

        saveButton.disabled = true;
        request("/api/v1/attendance/bulk", {
          method: "POST",
          body: JSON.stringify(payload)
        }).then(function (result) {
          saveButton.disabled = !canMark;
          if (!result.ok) {
            window.alert(firstError(result.body));
            return;
          }
          saveButton.textContent = "Roll sealed";
          setTimeout(function () {
            saveButton.textContent = "Seal the roll";
          }, 2500);
          loadRegister();
        });
      });
    }

    dateInput.addEventListener("change", loadRegister);
    classSelect.addEventListener("change", loadRegister);

    if (!dateInput.value) {
      const now = new Date();
      const lagos = new Date(now.toLocaleString("en-US", { timeZone: "Africa/Lagos" }));
      const month = String(lagos.getMonth() + 1).padStart(2, "0");
      const day = String(lagos.getDate()).padStart(2, "0");
      dateInput.value = lagos.getFullYear() + "-" + month + "-" + day;
    }

    request("/api/v1/attendance/offerings").then(function (result) {
      if (!result.ok) {
        list.innerHTML = '<p class="breadcrumb-text">' + firstError(result.body) + "</p>";
        return;
      }
      const offerings = result.body.data || [];
      if (!offerings.length) {
        classSelect.innerHTML = "<option value=\"\">No assigned class</option>";
        list.innerHTML = '<p class="breadcrumb-text">No class is assigned for attendance.</p>';
        return;
      }
      classSelect.innerHTML = offerings.map(function (offering) {
        return '<option value="' + offering.id + '">' + (offering.form || ("Class " + offering.id)) + "</option>";
      }).join("");
      loadRegister();
    });
  };

  const setText = function (selector, value) {
    document.querySelectorAll(selector).forEach(function (node) {
      node.textContent = value;
    });
  };

  const renderParentSummary = function (payload, child) {
    const summary = (payload && payload.summary) || {};
    const records = (payload && payload.records) || [];
    const rate = percentLabel(summary.percentage);
    const recorded = summary.recorded || 0;

    setText(".attendance-card:nth-child(1) strong", rate);
    setText(".attendance-card:nth-child(1) small", recorded ? "This academic session" : "No marks recorded yet");
    setText(".attendance-card:nth-child(2) strong", String(summary.present || 0));
    setText(".attendance-card:nth-child(2) small", "Out of " + recorded + " school days");
    setText(".attendance-card:nth-child(3) strong", String(summary.absent || 0));
    setText(".attendance-card:nth-child(3) small", "This academic session");
    setText(".attendance-card:nth-child(4) strong", String(summary.late || 0));
    setText(".attendance-card:nth-child(4) small", "This academic session");
    setText(".attendance-percentage", rate);
    setText(".progress-label strong", rate);
    setText(".attendance-overview .section-header p", "2025/2026 Academic Session");

    const bar = document.querySelector(".large-progress > div");
    if (bar) bar.style.width = (summary.percentage || 0) + "%";

    const breakdown = document.querySelectorAll(".attendance-breakdown strong");
    if (breakdown[0]) breakdown[0].textContent = String(summary.present || 0);
    if (breakdown[1]) breakdown[1].textContent = String(summary.absent || 0);
    if (breakdown[2]) breakdown[2].textContent = String(summary.late || 0);

    if (child) {
      const nameNode = document.querySelector(".selected-child strong");
      const formNode = document.querySelector(".selected-child small");
      const avatar = document.querySelector(".selected-child .child-avatar");
      if (nameNode) nameNode.textContent = child.full_name || "—";
      if (formNode) formNode.textContent = child.current_form || "—";
      if (avatar) avatar.textContent = initials(child.full_name);
    }

    const months = {};
    records.forEach(function (row) {
      const key = (row.marked_on || "").slice(0, 7);
      if (!key) return;
      if (!months[key]) months[key] = { present: 0, absent: 0, late: 0, total: 0 };
      months[key].total += 1;
      if (row.status === "present") months[key].present += 1;
      if (row.status === "absent") months[key].absent += 1;
      if (row.status === "late") months[key].late += 1;
    });

    const monthKeys = Object.keys(months).sort();
    const monthBox = document.querySelector(".monthly-summary");
    if (monthBox) {
      const rowsHtml = monthKeys.length
        ? monthKeys.map(function (key) {
          const row = months[key];
          const pct = row.total ? Math.round(((row.present + row.late) / row.total) * 100) : 0;
          return '<div class="month-row"><span>' + monthLabel(key) + "</span><strong>" + pct + "%</strong></div>";
        }).join("")
        : '<div class="month-row"><span>No monthly marks yet</span><strong>—</strong></div>';
      const header = monthBox.querySelector(".section-header");
      monthBox.innerHTML = (header ? header.outerHTML : "") + rowsHtml;
    }

    const monthSelect = document.querySelector(".month-select");
    const body = document.querySelector(".attendance-table tbody");
    const applyMonth = function () {
      const selected = monthSelect ? monthSelect.value : "";
      const visible = records.filter(function (row) {
        return !selected || (row.marked_on || "").slice(0, 7) === selected;
      });
      if (!body) return;
      if (!visible.length) {
        body.innerHTML = '<tr><td colspan="5">No attendance has been recorded yet.</td></tr>';
        return;
      }
      body.innerHTML = visible.map(function (row) {
        return "<tr><td>" + shortDate(row.marked_on) + "</td><td>" + weekday(row.marked_on) + "</td>"
          + '<td><span class="attendance-status ' + (row.status || "") + '">' + statusLabel(row.status) + "</span></td>"
          + "<td>—</td><td>" + (row.remark || "—") + "</td></tr>";
      }).join("");
    };

    if (monthSelect) {
      monthSelect.innerHTML = '<option value="">All dates</option>' + monthKeys.map(function (key) {
        return '<option value="' + key + '">' + monthLabel(key) + "</option>";
      }).join("");
      monthSelect.onchange = applyMonth;
    }
    applyMonth();
  };

  const loadChildAttendance = function (studentId, child) {
    const query = studentId ? ("?student_profile_id=" + encodeURIComponent(studentId)) : "";
    request("/api/v1/attendance/summary" + query).then(function (result) {
      if (!result.ok) {
        const body = document.querySelector(".attendance-table tbody");
        if (body) body.innerHTML = '<tr><td colspan="5">' + firstError(result.body) + "</td></tr>";
        return;
      }
      renderParentSummary(result.body.data || {}, child);
    });
  };

  const wireParentStudent = function () {
    const selector = document.querySelector(".attendance-selector");
    const table = document.querySelector(".attendance-table");
    if (!selector || !table) return;

    request("/api/v1/me/children").then(function (result) {
      if (result.status === 403) {
        selector.style.display = "none";
        const heading = document.querySelector(".page-heading p");
        if (heading) heading.textContent = "View your school attendance and punctuality.";
        loadChildAttendance(null, null);
        return;
      }

      if (!result.ok) {
        const body = document.querySelector(".attendance-table tbody");
        if (body) body.innerHTML = '<tr><td colspan="5">' + firstError(result.body) + "</td></tr>";
        return;
      }

      const children = result.body.data || [];
      const select = document.querySelector(".child-select");
      if (!children.length) {
        if (select) select.innerHTML = "<option>No linked children</option>";
        const body = document.querySelector(".attendance-table tbody");
        if (body) body.innerHTML = '<tr><td colspan="5">No children are linked to this account.</td></tr>';
        return;
      }

      if (select) {
        select.innerHTML = children.map(function (child) {
          return '<option value="' + child.id + '">' + (child.full_name || "Child") + "</option>";
        }).join("");
        select.addEventListener("change", function () {
          const child = children.filter(function (item) {
            return String(item.id) === String(select.value);
          })[0];
          loadChildAttendance(select.value, child);
        });
      }

      loadChildAttendance(children[0].id, children[0]);
    });
  };

  const menuButton = document.getElementById("menuButton");
  const sidebar = document.getElementById("sidebar");
  if (menuButton && sidebar) {
    menuButton.addEventListener("click", function () {
      sidebar.classList.toggle("show");
    });
  }

  wireStaffRegister();
  wireParentStudent();
})();
