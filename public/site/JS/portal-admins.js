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
        if (response.status === 401) window.location.replace("/portal/login");
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

  const confirmDesk = function (options) {
    const root = document.querySelector("[data-desk-alert]");
    if (!root) return Promise.resolve(window.confirm(options && options.copy ? options.copy : "Continue?"));

    const title = root.querySelector("[data-desk-alert-title]");
    const copy = root.querySelector("[data-desk-alert-copy]");
    const confirmBtn = root.querySelector("[data-desk-alert-confirm]");
    const card = root.querySelector(".desk-alert-card");

    if (title) title.textContent = (options && options.title) || "Seal this action?";
    if (copy) copy.textContent = (options && options.copy) || "";
    if (confirmBtn) confirmBtn.textContent = (options && options.confirm) || "Confirm";
    if (card) card.classList.toggle("is-danger", !!(options && options.danger));
    root.hidden = false;

    return new Promise(function (resolve) {
      const finish = function (ok) {
        root.hidden = true;
        root.removeEventListener("click", onClick);
        resolve(ok);
      };
      const onClick = function (event) {
        if (event.target.closest("[data-desk-alert-confirm]")) finish(true);
        if (event.target.closest("[data-desk-alert-dismiss]")) finish(false);
      };
      root.addEventListener("click", onClick);
    });
  };

  const table = document.querySelector("[data-admins-table]");
  if (!table) return;

  const search = document.querySelector("[data-admins-search]");
  const statusFilter = document.querySelector("[data-admins-status]");
  const roleFilter = document.querySelector("[data-admins-role]");
  const roleSelect = document.querySelector("[data-admins-role-select]");
  const copy = document.querySelector("[data-admins-copy]");
  const notice = document.querySelector("[data-admins-notice]");
  const form = document.querySelector("[data-admins-form]");
  const formNotice = document.querySelector("[data-admins-form-notice]");
  const formTitle = document.querySelector("[data-admins-form-title]");
  const formCopy = document.querySelector("[data-admins-form-copy]");
  const passwordInput = document.getElementById("adminPassword");
  const passwordConfirm = document.getElementById("adminPasswordConfirm");
  const passwordHint = document.querySelector("[data-admins-password-hint]");
  const passwordRow = document.querySelector("[data-admins-password-row]");
  const cancelEdit = document.querySelector("[data-cancel-admin-edit]");
  const resetPasswordBtn = document.querySelector("[data-admins-reset-password]");
  const submit = form && form.querySelector("[data-admins-submit]");

  let rows = [];
  let roles = [];
  let editingId = null;
  let me = window.srsMe || null;

  const isSuper = function () {
    return !!(me && (me.is_super_admin || (me.roles || []).indexOf("super_admin") !== -1));
  };

  const can = function (slug) {
    if (isSuper()) return true;
    return ((me && me.permissions) || []).indexOf(slug) !== -1;
  };

  const setMetric = function (key, value) {
    const node = document.querySelector('[data-metric="' + key + '"]');
    if (node) node.textContent = value;
  };

  const setNotice = function (node, message, ok) {
    if (!node) return;
    node.textContent = message || "";
    node.classList.toggle("is-ok", !!ok && !!message);
    node.classList.toggle("is-error", !ok && !!message);
  };

  const formatDate = function (value) {
    if (!value) return "—";
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return "—";
    return date.toLocaleDateString("en-GB", { day: "2-digit", month: "short", year: "numeric" });
  };

  const statusBadge = function (status) {
    const label = status === "suspended" ? "Suspended" : status === "inactive" ? "Inactive" : "Active";
    const klass = status === "suspended" ? "warn" : status === "inactive" ? "low" : "ok";
    return '<span class="badge ' + klass + '">' + label + "</span>";
  };

  const paintRoleOptions = function () {
    const options = roles.map(function (role) {
      return '<option value="' + escapeHtml(role.slug) + '">' + escapeHtml(role.name) + "</option>";
    }).join("");
    if (roleSelect) {
      roleSelect.innerHTML = '<option value="">Select a role</option>' + options;
    }
    if (roleFilter) {
      roleFilter.innerHTML = '<option value="">All roles</option>' + options;
    }
  };

  const resetForm = function () {
    editingId = null;
    if (form) form.reset();
    if (formTitle) formTitle.textContent = "Add admin user";
    if (formCopy) formCopy.textContent = "Create a desk account and assign one appointable role";
    if (passwordInput) passwordInput.required = true;
    if (passwordConfirm) passwordConfirm.required = true;
    if (passwordHint) passwordHint.hidden = false;
    if (passwordRow) passwordRow.hidden = false;
    if (cancelEdit) cancelEdit.hidden = true;
    if (resetPasswordBtn) resetPasswordBtn.hidden = true;
    if (submit) {
      submit.hidden = !can("admins.create");
      submit.textContent = "Create admin";
      submit.dataset.label = "Create admin";
    }
    setNotice(formNotice, "", true);
  };

  const fillForm = function (row) {
    editingId = row.id;
    document.getElementById("adminFirstName").value = row.first_name || "";
    document.getElementById("adminLastName").value = row.last_name || "";
    document.getElementById("adminEmail").value = row.email || "";
    if (roleSelect) roleSelect.value = row.role || "";
    if (passwordInput) {
      passwordInput.value = "";
      passwordInput.required = false;
    }
    if (passwordConfirm) {
      passwordConfirm.value = "";
      passwordConfirm.required = false;
    }
    if (passwordRow) passwordRow.hidden = true;
    if (passwordHint) passwordHint.hidden = true;
    if (formTitle) formTitle.textContent = "Edit " + (row.name || "admin");
    if (formCopy) formCopy.textContent = "Update name, email, or role. Use Reset password for a new key.";
    if (cancelEdit) cancelEdit.hidden = false;
    if (resetPasswordBtn) resetPasswordBtn.hidden = !can("admins.edit") || !!(me && me.id === row.id);
    if (submit) {
      submit.hidden = !can("admins.edit");
      submit.textContent = "Save admin";
      submit.dataset.label = "Save admin";
    }
    setNotice(formNotice, "", true);
    form.scrollIntoView({ behavior: "smooth", block: "nearest" });
  };

  const filtered = function () {
    const q = ((search && search.value) || "").trim().toLowerCase();
    const status = (statusFilter && statusFilter.value) || "";
    const role = (roleFilter && roleFilter.value) || "";
    return rows.filter(function (row) {
      if (status && row.status !== status) return false;
      if (role && row.role !== role && (row.roles || []).indexOf(role) === -1) return false;
      if (!q) return true;
      return String(row.name || "").toLowerCase().indexOf(q) !== -1
        || String(row.email || "").toLowerCase().indexOf(q) !== -1;
    });
  };

  const paint = function () {
    const list = filtered();
    setMetric("admins", String(rows.length));
    setMetric("active", String(rows.filter(function (row) { return row.status === "active"; }).length));
    setMetric("suspended", String(rows.filter(function (row) { return row.status === "suspended"; }).length));
    setMetric("super", String(rows.filter(function (row) { return row.is_super_admin; }).length));
    if (copy) copy.textContent = list.length + " of " + rows.length + " desk accounts";

    if (!list.length) {
      table.innerHTML = '<tr><td colspan="6">No admin users match this search.</td></tr>';
      return;
    }

    table.innerHTML = list.map(function (row) {
      const actions = [];
      if (can("admins.edit")) {
        actions.push('<button type="button" class="ghost-btn" data-edit="' + row.id + '">Edit</button>');
      }
      if (can("admins.suspend") && row.status === "active" && !(me && me.id === row.id)) {
        actions.push('<button type="button" class="ghost-btn" data-suspend="' + row.id + '">Suspend</button>');
      }
      if (can("admins.suspend") && row.status === "suspended") {
        actions.push('<button type="button" class="ghost-btn" data-reinstate="' + row.id + '">Reactivate</button>');
      }
      if (can("admins.delete") && !(me && me.id === row.id)) {
        actions.push('<button type="button" class="ghost-btn" data-delete="' + row.id + '">Delete</button>');
      }
      return "<tr>"
        + "<td>" + escapeHtml(row.name) + "</td>"
        + "<td>" + escapeHtml(row.email) + "</td>"
        + "<td>" + escapeHtml(row.role_name || row.role || "—") + "</td>"
        + "<td>" + statusBadge(row.status) + "</td>"
        + "<td>" + escapeHtml(formatDate(row.created_at)) + "</td>"
        + '<td><div class="row-actions">' + (actions.join(" ") || "—") + "</div></td>"
        + "</tr>";
    }).join("");
  };

  const load = function () {
    return Promise.all([
      request("/api/v1/admins"),
      request("/api/v1/admins/roles")
    ]).then(function (results) {
      const listRes = results[0];
      const rolesRes = results[1];
      if (!listRes.ok) {
        setNotice(notice, firstError(listRes.body), false);
        table.innerHTML = '<tr><td colspan="6">Unable to load admin users.</td></tr>';
        return;
      }
      rows = listRes.body.data || [];
      roles = (rolesRes.ok && rolesRes.body.data) || [];
      paintRoleOptions();
      paint();
      setNotice(notice, "", true);
    });
  };

  if (search) search.addEventListener("input", paint);
  if (statusFilter) statusFilter.addEventListener("change", paint);
  if (roleFilter) roleFilter.addEventListener("change", paint);
  if (cancelEdit) cancelEdit.addEventListener("click", resetForm);

  table.addEventListener("click", function (event) {
    const editId = event.target.getAttribute("data-edit");
    const suspendId = event.target.getAttribute("data-suspend");
    const reinstateId = event.target.getAttribute("data-reinstate");
    const deleteId = event.target.getAttribute("data-delete");

    if (editId) {
      const row = rows.find(function (item) { return String(item.id) === String(editId); });
      if (row) fillForm(row);
      return;
    }

    if (suspendId) {
      const row = rows.find(function (item) { return String(item.id) === String(suspendId); });
      confirmDesk({
        title: "Suspend this admin?",
        copy: "Suspend " + (row && row.name ? row.name : "this account") + " (" + (row && row.email ? row.email : "") + "). They will not be able to sign in until reactivated.",
        confirm: "Suspend",
        danger: true
      }).then(function (ok) {
        if (!ok) return;
        request("/api/v1/admins/" + suspendId + "/suspend", { method: "POST", body: "{}" }).then(function (res) {
          if (!res.ok) return setNotice(notice, firstError(res.body), false);
          setNotice(notice, "Admin suspended.", true);
          return load();
        });
      });
      return;
    }

    if (reinstateId) {
      request("/api/v1/admins/" + reinstateId + "/reinstate", { method: "POST", body: "{}" }).then(function (res) {
        if (!res.ok) return setNotice(notice, firstError(res.body), false);
        setNotice(notice, "Admin reactivated.", true);
        return load();
      });
      return;
    }

    if (deleteId) {
      const row = rows.find(function (item) { return String(item.id) === String(deleteId); });
      confirmDesk({
        title: "Delete this admin?",
        copy: "Permanently remove " + (row && row.name ? row.name : "this account") + " (" + (row && row.email ? row.email : "") + ") from desk access. This cannot be undone from the directory.",
        confirm: "Delete",
        danger: true
      }).then(function (ok) {
        if (!ok) return;
        request("/api/v1/admins/" + deleteId, { method: "DELETE" }).then(function (res) {
          if (!res.ok) return setNotice(notice, firstError(res.body), false);
          setNotice(notice, "Admin removed.", true);
          if (editingId && String(editingId) === String(deleteId)) resetForm();
          return load();
        });
      });
    }
  });

  if (form) {
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      const payload = {
        first_name: document.getElementById("adminFirstName").value.trim(),
        last_name: document.getElementById("adminLastName").value.trim(),
        email: document.getElementById("adminEmail").value.trim(),
        role: roleSelect ? roleSelect.value : ""
      };

      if (!editingId) {
        payload.password = passwordInput ? passwordInput.value : "";
        payload.password_confirmation = passwordConfirm ? passwordConfirm.value : "";
      }

      const url = editingId ? "/api/v1/admins/" + editingId : "/api/v1/admins";
      const method = editingId ? "PUT" : "POST";

      request(url, { method: method, body: JSON.stringify(payload) }).then(function (res) {
        if (!res.ok) return setNotice(formNotice, firstError(res.body), false);
        setNotice(formNotice, editingId ? "Admin updated." : "Admin created.", true);
        resetForm();
        return load();
      });
    });
  }

  if (resetPasswordBtn) {
    resetPasswordBtn.addEventListener("click", function () {
      if (!editingId) return;
      const password = window.prompt("Enter a new password (min 8 characters):");
      if (password == null) return;
      const confirm = window.prompt("Confirm the new password:");
      if (confirm == null) return;
      request("/api/v1/admins/" + editingId + "/password", {
        method: "PUT",
        body: JSON.stringify({ password: password, password_confirmation: confirm })
      }).then(function (res) {
        if (!res.ok) return setNotice(formNotice, firstError(res.body), false);
        setNotice(formNotice, "Password reset.", true);
      });
    });
  }

  const boot = function () {
    me = window.srsMe || me;
    resetForm();
    load();
  };

  if (window.srsMe) boot();
  else {
    const wait = setInterval(function () {
      if (window.srsMe) {
        clearInterval(wait);
        boot();
      }
    }, 50);
    setTimeout(function () {
      clearInterval(wait);
      boot();
    }, 2500);
  }
})();
