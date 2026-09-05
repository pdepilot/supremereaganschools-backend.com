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

  const table = document.querySelector("[data-roles-table]");
  if (!table) return;

  const form = document.querySelector("[data-roles-form]");
  const formNotice = document.querySelector("[data-roles-form-notice]");
  const formTitle = document.querySelector("[data-roles-form-title]");
  const formCopy = document.querySelector("[data-roles-form-copy]");
  const notice = document.querySelector("[data-roles-notice]");
  const copy = document.querySelector("[data-roles-copy]");
  const permissionsBox = document.querySelector("[data-roles-permissions]");
  const cancelEdit = document.querySelector("[data-cancel-role-edit]");
  const submit = form && form.querySelector("[data-roles-submit]");
  const slugInput = document.getElementById("roleSlug");

  let roles = [];
  let catalogue = [];
  let editingId = null;

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

  const selectedPermissions = function () {
    return Array.prototype.slice.call(form.querySelectorAll('input[name="permissions"]:checked'))
      .map(function (node) { return node.value; });
  };

  const paintPermissions = function (selected) {
    if (!permissionsBox) return;
    const chosen = selected || [];
    permissionsBox.innerHTML = catalogue.map(function (group) {
      return '<div class="permission-group"><p class="eyebrow">' + escapeHtml(group.module) + "</p>"
        + (group.permissions || []).map(function (item) {
          const checked = chosen.indexOf(item.slug) !== -1 ? " checked" : "";
          return '<label class="permission-chip"><input type="checkbox" name="permissions" value="'
            + escapeHtml(item.slug) + '"' + checked + "> <span>" + escapeHtml(item.name) + "</span></label>";
        }).join("")
        + "</div>";
    }).join("");
  };

  const resetForm = function () {
    editingId = null;
    if (form) form.reset();
    if (formTitle) formTitle.textContent = "Create a role";
    if (formCopy) formCopy.textContent = "Name the desk and tick the modules it may open";
    if (slugInput) slugInput.disabled = false;
    if (cancelEdit) cancelEdit.hidden = true;
    if (submit) {
      submit.textContent = "Seal the role";
      submit.dataset.label = "Seal the role";
      submit.hidden = false;
    }
    paintPermissions([]);
    setNotice(formNotice, "", true);
  };

  const fillForm = function (role) {
    editingId = role.id;
    document.getElementById("roleName").value = role.name || "";
    document.getElementById("roleDescription").value = role.description || "";
    if (slugInput) {
      slugInput.value = role.slug || "";
      slugInput.disabled = true;
    }
    if (formTitle) formTitle.textContent = "Update " + (role.name || "role");
    if (formCopy) formCopy.textContent = role.is_super_admin
      ? "Super administrator already holds every permission."
      : "Adjust the name and module keys for this role";
    if (cancelEdit) cancelEdit.hidden = false;
    paintPermissions(role.permissions || []);
    if (submit) {
      submit.hidden = !!role.is_super_admin;
      submit.textContent = "Save role";
      submit.dataset.label = "Save role";
    }
    Array.prototype.slice.call(form.querySelectorAll("input")).forEach(function (node) {
      if (role.is_super_admin && node.name === "permissions") node.disabled = true;
      else if (node !== slugInput) node.disabled = !!role.is_super_admin && node.name !== "name";
    });
    if (!role.is_super_admin) {
      Array.prototype.slice.call(form.querySelectorAll("input")).forEach(function (node) {
        if (node !== slugInput) node.disabled = false;
      });
    }
    setNotice(formNotice, "", true);
    form.scrollIntoView({ behavior: "smooth", block: "nearest" });
  };

  const paint = function () {
    setMetric("roles", String(roles.length));
    setMetric("system", String(roles.filter(function (role) { return role.is_system_role; }).length));
    setMetric("custom", String(roles.filter(function (role) { return !role.is_system_role; }).length));
    const permissionCount = catalogue.reduce(function (sum, group) {
      return sum + ((group.permissions && group.permissions.length) || 0);
    }, 0);
    setMetric("permissions", String(permissionCount));
    if (copy) copy.textContent = roles.length + " roles on the command desk";

    const canCreate = !!(window.srsHasPermission && window.srsHasPermission("roles.create"));
    const canEdit = !!(window.srsHasPermission && window.srsHasPermission("roles.edit"));
    const canDelete = !!(window.srsHasPermission && window.srsHasPermission("roles.delete"));
    if (submit) submit.hidden = !canCreate && !editingId;
    if (form && !canCreate && !canEdit) {
      Array.prototype.slice.call(form.querySelectorAll("input, button")).forEach(function (node) {
        if (node === cancelEdit) return;
        node.disabled = true;
      });
    }

    if (!roles.length) {
      table.innerHTML = '<tr><td colspan="6">No roles yet.</td></tr>';
      return;
    }

    table.innerHTML = roles.map(function (role) {
      const actions = [];
      if (canEdit) {
        actions.push('<button type="button" class="ghost-btn" data-edit-role="' + role.id + '">Edit</button>');
      }
      if (canDelete && !role.is_system_role && !role.is_super_admin) {
        actions.push('<button type="button" class="ghost-btn danger" data-remove-role="' + role.id + '">Remove</button>');
      }
      return "<tr>"
        + "<td>" + escapeHtml(role.name) + "</td>"
        + "<td>" + escapeHtml(role.slug) + "</td>"
        + "<td>" + escapeHtml(role.users_count != null ? role.users_count : "—") + "</td>"
        + "<td>" + escapeHtml(role.permissions_count != null ? role.permissions_count : ((role.permissions || []).length)) + "</td>"
        + "<td>" + (role.is_system_role ? "System" : "Custom") + "</td>"
        + '<td class="actions">' + (actions.join(" ") || "—") + "</td>"
        + "</tr>";
    }).join("");
  };

  const load = function () {
    return Promise.all([
      request("/api/v1/roles"),
      request("/api/v1/permissions")
    ]).then(function (results) {
      const rolesResult = results[0];
      const permissionsResult = results[1];
      if (!rolesResult.ok) {
        setNotice(notice, firstError(rolesResult.body), false);
        table.innerHTML = '<tr><td colspan="6">Could not load roles.</td></tr>';
        return;
      }
      roles = Array.isArray(rolesResult.body.data) ? rolesResult.body.data : [];
      catalogue = permissionsResult.ok && Array.isArray(permissionsResult.body.data)
        ? permissionsResult.body.data
        : [];
      setNotice(notice, "", true);
      paintPermissions([]);
      paint();
    });
  };

  const confirmDesk = function (options) {
    const root = document.querySelector("[data-desk-alert]");
    if (!root) return Promise.resolve(window.confirm(options.copy || "Continue?"));
    root.querySelector("[data-desk-alert-title]").textContent = options.title || "Seal this action?";
    root.querySelector("[data-desk-alert-copy]").textContent = options.copy || "";
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

  if (cancelEdit) cancelEdit.addEventListener("click", resetForm);

  table.addEventListener("click", function (event) {
    const edit = event.target.closest("[data-edit-role]");
    if (edit) {
      const role = roles.find(function (item) { return String(item.id) === edit.getAttribute("data-edit-role"); });
      if (role) fillForm(role);
      return;
    }
    const remove = event.target.closest("[data-remove-role]");
    if (remove) {
      confirmDesk({
        title: "Remove this role?",
        copy: "The role will be withdrawn if no users still hold it.",
        confirm: "Remove"
      }).then(function (ok) {
        if (!ok) return;
        request("/api/v1/roles/" + remove.getAttribute("data-remove-role"), { method: "DELETE" })
          .then(function (result) {
            setNotice(notice, result.ok ? "Role removed." : firstError(result.body), result.ok);
            if (result.ok) {
              if (String(editingId) === remove.getAttribute("data-remove-role")) resetForm();
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
        name: document.getElementById("roleName").value.trim(),
        description: document.getElementById("roleDescription").value.trim() || null,
        permissions: selectedPermissions()
      };
      if (!editingId) {
        const slug = slugInput && slugInput.value.trim();
        if (slug) payload.slug = slug;
        request("/api/v1/roles", { method: "POST", body: JSON.stringify(payload) })
          .then(function (result) {
            setNotice(formNotice, result.ok ? "Role sealed." : firstError(result.body), result.ok);
            if (result.ok) {
              resetForm();
              load();
            }
          });
        return;
      }
      request("/api/v1/roles/" + editingId, { method: "PUT", body: JSON.stringify(payload) })
        .then(function (result) {
          setNotice(formNotice, result.ok ? "Role updated." : firstError(result.body), result.ok);
          if (result.ok) {
            resetForm();
            load();
          }
        });
    });
  }

  resetForm();
  load();
})();
