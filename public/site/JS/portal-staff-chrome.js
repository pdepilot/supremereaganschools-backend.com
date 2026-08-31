(function () {
  if (!document.body.classList.contains("faculty-house")) return;

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
        window.location.replace((window.srsLoginPath && window.srsLoginPath()) || "/staff/login");
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
    if (!body) return "The house could not complete that request.";
    if (body.errors) {
      const keys = Object.keys(body.errors);
      if (keys.length) return body.errors[keys[0]][0];
    }
    return body.message || "The house could not complete that request.";
  };

  const tickClock = function () {
    const node = document.querySelector("[data-clock]");
    if (!node) return;
    node.textContent = new Date().toLocaleTimeString("en-GB", {
      timeZone: "Africa/Lagos",
      hour12: false
    });
  };

  const openModal = function (id) {
    const modal = document.getElementById(id);
    if (modal) modal.classList.add("show");
  };

  const closeModal = function (modal) {
    if (modal) modal.classList.remove("show");
  };

  document.querySelectorAll("[data-open-modal]").forEach(function (button) {
    button.addEventListener("click", function () {
      openModal(button.getAttribute("data-open-modal"));
    });
  });

  document.querySelectorAll(".house-modal, .assignment-modal, .material-modal, .message-modal").forEach(function (modal) {
    modal.addEventListener("click", function (event) {
      if (event.target === modal || event.target.closest("[data-close-modal]")) {
        closeModal(modal);
      }
    });
  });

  document.addEventListener("keydown", function (event) {
    if (event.key !== "Escape") return;
    document.querySelectorAll(".house-modal.show, .assignment-modal.show, .material-modal.show, .message-modal.show").forEach(closeModal);
  });

  const printButton = document.getElementById("printTimetable");
  if (printButton) {
    printButton.addEventListener("click", function () {
      window.print();
    });
  }

  const filterRows = function (input, rows, attr) {
    if (!input) return;
    input.addEventListener("keyup", function () {
      const value = this.value.toLowerCase();
      document.querySelectorAll(rows).forEach(function (row) {
        row.style.display = row.innerText.toLowerCase().indexOf(value) === -1 ? "none" : "";
      });
    });
    if (attr) {
      const filter = document.getElementById(attr);
      if (filter) {
        filter.addEventListener("change", function () {
          const value = this.value;
          document.querySelectorAll(rows).forEach(function (row) {
            row.style.display = !value || value === "all" || row.getAttribute("data-status") === value ? "" : "none";
          });
        });
      }
    }
  };

  filterRows(document.getElementById("studentSearch"), "[data-assigned-students] tr");
  filterRows(document.getElementById("assignmentSearch"), ".assignments-table tbody tr", "assignmentFilter");
  filterRows(document.getElementById("announcementSearch"), "#announcementGrid .announcement-card");
  filterRows(document.getElementById("materialSearch"), ".materials-grid .material-card");
  filterRows(document.getElementById("conversationSearch"), "#conversationList .conversation");

  const announcementFilter = document.getElementById("announcementFilter");
  if (announcementFilter) {
    announcementFilter.addEventListener("change", function () {
      const value = this.value;
      document.querySelectorAll("#announcementGrid .announcement-card").forEach(function (card) {
        card.style.display = !value || card.getAttribute("data-category") === value ? "" : "none";
      });
    });
  }

  const fileInput = document.getElementById("fileInput");
  if (fileInput) {
    fileInput.addEventListener("change", function () {
      const label = document.querySelector("[data-file-name]");
      if (label && fileInput.files[0]) label.textContent = fileInput.files[0].name;
    });
  }

  const page = document.body.getAttribute("data-page");
  if (page === "students" || page === "assignments") {
    request("/api/v1/staff-desk").then(function (result) {
      if (!result.ok || !result.body.data) return;
      const data = result.body.data;
      const metrics = data.metrics || {};
      const set = function (key, value) {
        const node = document.querySelector('[data-metric="' + key + '"]');
        if (node) node.textContent = value == null || value === "" ? "—" : String(value);
      };
      if (page === "students") {
        set("pupils", metrics.pupils);
        set("attendance", metrics.attendance_percent == null ? "—" : metrics.attendance_percent + "%");
        set("forms", (data.forms || []).length);
        set("letters", metrics.letters);
      }
      if (page === "assignments") {
        set("open", metrics.assignments);
        const delta = document.querySelector('[data-metric-delta="open"]');
        if (delta) delta.textContent = metrics.assignments_delta || "";
      }
    });
  }

  const fillAccount = function (data) {
    const name = data.name || "";
    const email = data.email || "";
    const initials = name.split(/\s+/).filter(Boolean).slice(0, 2).map(function (part) {
      return part.charAt(0);
    }).join("").toUpperCase() || "F";
    document.querySelectorAll("[data-account-name]").forEach(function (node) {
      if (node.tagName === "INPUT") node.value = name;
      else node.textContent = name || "—";
    });
    document.querySelectorAll("[data-account-email]").forEach(function (node) {
      if (node.tagName === "INPUT") node.value = email;
      else node.textContent = email || "—";
    });
    document.querySelectorAll("[data-account-initials]").forEach(function (node) {
      node.textContent = initials;
    });
    const roles = (data.roles || []).join(" · ");
    document.querySelectorAll("[data-account-roles]").forEach(function (node) {
      node.textContent = roles || "Faculty";
    });
  };

  if (page === "profile" || page === "settings") {
    request("/api/v1/me").then(function (result) {
      if (result.ok && result.body.data) fillAccount(result.body.data);
    });
  }

  const accountForm = document.querySelector("[data-account-form]");
  if (accountForm) {
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
  if (passwordForm) {
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

  tickClock();
  window.setInterval(tickClock, 1000);
})();
