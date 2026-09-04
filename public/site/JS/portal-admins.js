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

    if (options && options.body && !headers["Content-Type"] && !(options.body instanceof FormData)) {
      headers["Content-Type"] = "application/json";
    }

    return fetch(url, Object.assign({
      credentials: "same-origin"
    }, options, { headers })).then(function (response) {
      return response.json().then(function (body) {
        if (response.status === 401) {
          window.location.replace("/portal/login");
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

  const escapeHtml = function (value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  };

  const setButtonState = function (button, busy, label) {
    if (!button) return;
    if (!button.dataset.label) button.dataset.label = button.textContent;
    button.disabled = !!busy;
    button.classList.toggle("is-busy", !!busy);
    if (label) button.textContent = label;
    else if (!busy) button.textContent = button.dataset.label;
  };

  const confirmDesk = function (options) {
    const root = document.querySelector("[data-desk-alert]");
    if (!root) return Promise.resolve(window.confirm(options && options.copy ? options.copy : "Continue?"));

    const title = root.querySelector("[data-desk-alert-title]");
    const copy = root.querySelector("[data-desk-alert-copy]");
    const confirmBtn = root.querySelector("[data-desk-alert-confirm]");

    if (title) title.textContent = (options && options.title) || "Seal this action?";
    if (copy) copy.textContent = (options && options.copy) || "";
    if (confirmBtn) confirmBtn.textContent = (options && options.confirm) || "Confirm";
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
  const copy = document.querySelector("[data-admins-copy]");
  const notice = document.querySelector("[data-admins-notice]");
  const form = document.querySelector("[data-admins-form]");
  const formNotice = document.querySelector("[data-admins-form-notice]");
  const formTitle = document.querySelector("[data-admins-form-title]");
  const formCopy = document.querySelector("[data-admins-form-copy]");
  const passwordInput = document.getElementById("adminPassword");
  const passwordHint = document.querySelector("[data-admins-password-hint]");
  const cancelEdit = document.querySelector("[data-cancel-admin-edit]");
  const permissionsBox = document.querySelector("[data-admins-permissions]");
  const permissionsBlock = document.querySelector("[data-admins-permissions-block]");
  const submit = form && form.querySelector("[data-admins-submit]");

  let rows = [];
  let catalogue = [];
  let editingId = null;
  let me = window.srsMe || null;

  const isSuper = function () {
    return !!(me && (me.is_super_admin || (me.roles || []).indexOf("super_admin") !== -1));
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

  const roleLabel = function (row) {
    if (row.is_super_admin || (row.roles || []).indexOf("super_admin") !== -1) return "Super admin";
    return "School admin";
  };

  const permissionLabels = function (row) {
    if (row.is_super_admin) return "All desks";
    const slugs = row.permissions || [];
    if (!slugs.length) return "All desks (default)";
    return slugs.map(function (slug) {
      const match = catalogue.find(function (item) { return item.slug === slug; });
      return match ? match.name : slug;
    }).join(", ");
  };

  const paintPermissions = function (selected) {
    if (!permissionsBox) return;
    const chosen = selected || [];
    const groups = {};
    catalogue.forEach(function (item) {
      const group = item.group || "Desk";
      if (!groups[group]) groups[group] = [];
      groups[group].push(item);
    });
    permissionsBox.innerHTML = Object.keys(groups).map(function (group) {
      return '<div class="permission-group"><p class="eyebrow">' + escapeHtml(group) + "</p>"
        + groups[group].map(function (item) {
          const checked = chosen.indexOf(item.slug) !== -1 ? " checked" : "";
          return '<label class="permission-chip"><input type="checkbox" name="permissions" value="'
            + escapeHtml(item.slug) + '"' + checked + "> <span>" + escapeHtml(item.name) + "</span></label>";
        }).join("")
        + "</div>";
    }).join("");
  };

  const selectedPermissions = function () {
    return Array.prototype.slice.call(form.querySelectorAll('input[name="permissions"]:checked'))
      .map(function (node) { return node.value; });
  };

  const resetForm = function () {
    editingId = null;
    if (form) form.reset();
    if (formTitle) formTitle.textContent = isSuper() ? "Appoint an admin" : "Change login details";
    if (formCopy) {
      formCopy.textContent = isSuper()
        ? "Seal a school admin account and choose which desks they may open"
        : "Select an admin from the directory to change their name, email, or password";
    }
    if (passwordInput) passwordInput.required = isSuper();
    if (passwordHint) passwordHint.hidden = isSuper();
    if (cancelEdit) cancelEdit.hidden = true;
    if (permissionsBlock) permissionsBlock.hidden = !isSuper();
    if (submit) {
      submit.hidden = !isSuper();
      submit.textContent = "Seal the admin";
      submit.dataset.label = "Seal the admin";
    }
    paintPermissions([]);
    setNotice(formNotice, "", true);
    Array.prototype.slice.call(form.querySelectorAll("input, button")).forEach(function (node) {
      if (node === cancelEdit) return;
      if (!isSuper() && node !== submit) node.disabled = true;
      else node.disabled = false;
    });
  };

  const fillForm = function (row) {
    editingId = row.id;
    document.getElementById("adminName").value = row.name || "";
    document.getElementById("adminEmail").value = row.email || "";
    if (passwordInput) {
      passwordInput.value = "";
      passwordInput.required = false;
    }
    if (passwordHint) passwordHint.hidden = false;
    if (formTitle) formTitle.textContent = "Update " + (row.name || "admin");
    if (formCopy) {
      formCopy.textContent = isSuper()
        ? "Change login details and desk permissions for this admin"
        : "Change login details for this admin";
    }
    if (cancelEdit) cancelEdit.hidden = false;
    if (permissionsBlock) permissionsBlock.hidden = !isSuper() || !!row.is_super_admin;
    paintPermissions(row.is_super_admin ? [] : (row.permissions || []));
    if (submit) {
      submit.hidden = false;
      submit.textContent = "Save login details";
      submit.dataset.label = "Save login details";
    }
    Array.prototype.slice.call(form.querySelectorAll("input, button")).forEach(function (node) {
      node.disabled = false;
    });
    if (row.is_super_admin && !isSuper()) {
      Array.prototype.slice.call(form.querySelectorAll("input, button")).forEach(function (node) {
        if (node !== cancelEdit) node.disabled = true;
      });
    }
    setNotice(formNotice, "", true);
    form.scrollIntoView({ behavior: "smooth", block: "nearest" });
  };

  const filtered = function () {
    const q = ((search && search.value) || "").trim().toLowerCase();
    const status = (statusFilter && statusFilter.value) || "";
    return rows.filter(function (row) {
      if (status && row.status !== status) return false;
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
    if (copy) copy.textContent = list.length + " of " + rows.length + " admin accounts";

    if (!list.length) {
      table.innerHTML = '<tr><td colspan="6">No admin accounts match this search.</td></tr>';
      return;
    }

    table.innerHTML = list.map(function (row) {
      const actions = [
        '<button type="button" class="ghost-btn" data-edit-admin="' + row.id + '">Edit login</button>'
      ];
      if (isSuper() && !row.is_super_admin) {
        if (row.status === "suspended") {
          actions.push('<button type="button" class="ghost-btn" data-reinstate-admin="' + row.id + '">Reinstate</button>');
        } else {
          actions.push('<button type="button" class="ghost-btn" data-suspend-admin="' + row.id + '">Suspend</button>');
        }
        actions.push('<button type="button" class="ghost-btn danger" data-remove-admin="' + row.id + '">Remove</button>');
      }
      return "<tr>"
        + "<td>" + escapeHtml(row.name || "—") + "</td>"
        + "<td>" + escapeHtml(row.email || "—") + "</td>"
        + "<td>" + escapeHtml(roleLabel(row)) + "</td>"
        + "<td>" + escapeHtml(permissionLabels(row)) + "</td>"
        + '<td><span class="badge ' + (row.status === "active" ? "ok" : "warn") + '">' + escapeHtml(row.status || "—") + "</span></td>"
        + '<td class="actions">' + actions.join(" ") + "</td>"
        + "</tr>";
    }).join("");
  };

  const loadCatalogue = function () {
    if (!isSuper()) {
      catalogue = [];
      return Promise.resolve();
    }
    return request("/api/v1/admins/permissions").then(function (result) {
      catalogue = result.ok && Array.isArray(result.body.data) ? result.body.data : [];
    });
  };

  const load = function () {
    return request("/api/v1/admins").then(function (result) {
      if (!result.ok) {
        setNotice(notice, firstError(result.body), false);
        table.innerHTML = '<tr><td colspan="6">Could not load admin accounts.</td></tr>';
        return;
      }
      rows = Array.isArray(result.body.data) ? result.body.data : [];
      setNotice(notice, "", true);
      paint();
    });
  };

  const ensureMe = function () {
    if (me) return Promise.resolve(me);
    return request("/api/v1/me").then(function (result) {
      me = result.ok ? result.body.data : null;
      window.srsMe = me;
      return me;
    });
  };

  if (search) search.addEventListener("input", paint);
  if (statusFilter) statusFilter.addEventListener("change", paint);
  if (cancelEdit) cancelEdit.addEventListener("click", resetForm);

  table.addEventListener("click", function (event) {
    const edit = event.target.closest("[data-edit-admin]");
    if (edit) {
      const row = rows.find(function (item) { return String(item.id) === edit.getAttribute("data-edit-admin"); });
      if (row) fillForm(row);
      return;
    }

    const suspend = event.target.closest("[data-suspend-admin]");
    if (suspend) {
      confirmDesk({
        title: "Suspend this admin?",
        copy: "They will lose portal access until reinstated.",
        confirm: "Suspend"
      }).then(function (ok) {
        if (!ok) return;
        request("/api/v1/admins/" + suspend.getAttribute("data-suspend-admin") + "/suspend", { method: "POST" })
          .then(function (result) {
            setNotice(notice, result.ok ? "Admin suspended." : firstError(result.body), result.ok);
            if (result.ok) load();
          });
      });
      return;
    }

    const reinstate = event.target.closest("[data-reinstate-admin]");
    if (reinstate) {
      request("/api/v1/admins/" + reinstate.getAttribute("data-reinstate-admin") + "/reinstate", { method: "POST" })
        .then(function (result) {
          setNotice(notice, result.ok ? "Admin reinstated." : firstError(result.body), result.ok);
          if (result.ok) load();
        });
      return;
    }

    const remove = event.target.closest("[data-remove-admin]");
    if (remove) {
      confirmDesk({
        title: "Remove this admin?",
        copy: "Their desk keys will be withdrawn and the account closed.",
        confirm: "Remove"
      }).then(function (ok) {
        if (!ok) return;
        request("/api/v1/admins/" + remove.getAttribute("data-remove-admin"), { method: "DELETE" })
          .then(function (result) {
            setNotice(notice, result.ok ? "Admin removed." : firstError(result.body), result.ok);
            if (result.ok) {
              if (String(editingId) === remove.getAttribute("data-remove-admin")) resetForm();
              load();
            }
          });
      });
    }
  });

  if (form) {
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      const payload = {
        name: document.getElementById("adminName").value.trim(),
        email: document.getElementById("adminEmail").value.trim()
      };
      const password = passwordInput ? passwordInput.value : "";
      if (password) payload.password = password;
      if (isSuper() && (!editingId || !(rows.find(function (row) { return row.id === editingId; }) || {}).is_super_admin)) {
        payload.permissions = selectedPermissions();
      }

      if (!editingId) {
        if (!isSuper()) return;
        if (!payload.password) {
          setNotice(formNotice, "A password is required for a new admin.", false);
          return;
        }
        setButtonState(submit, true, "Sealing…");
        request("/api/v1/admins", { method: "POST", body: JSON.stringify(payload) })
          .then(function (result) {
            setButtonState(submit, false);
            setNotice(formNotice, result.ok ? "Admin sealed onto the desk." : firstError(result.body), result.ok);
            if (result.ok) {
              resetForm();
              load();
            }
          });
        return;
      }

      setButtonState(submit, true, "Saving…");
      request("/api/v1/admins/" + editingId, { method: "PUT", body: JSON.stringify(payload) })
        .then(function (result) {
          setButtonState(submit, false);
          setNotice(formNotice, result.ok ? "Admin login details updated." : firstError(result.body), result.ok);
          if (result.ok) {
            resetForm();
            load();
          }
        });
    });
  }

  ensureMe().then(function () {
    resetForm();
    return loadCatalogue();
  }).then(load);
})();
