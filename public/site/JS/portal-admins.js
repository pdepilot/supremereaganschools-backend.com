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
      const type = response.headers.get("content-type") || "";
      if (type.indexOf("application/json") === -1) {
        if (response.status === 401) window.location.replace("/portal/login");
        return {
          ok: false,
          status: response.status,
          body: { message: response.status === 419
            ? "Your desk session expired. Refresh and sign in again."
            : "The office could not complete that request (" + response.status + ")." }
        };
      }
      return response.json().then(function (body) {
        if (response.status === 401) window.location.replace("/portal/login");
        return { ok: response.ok, status: response.status, body: body };
      }).catch(function () {
        return { ok: false, status: response.status, body: { message: "The office returned an unreadable reply." } };
      });
    }).catch(function () {
      return { ok: false, status: 0, body: { message: "Network error. Check your connection and try again." } };
    });
  };

  const setButtonState = function (button, busy, label) {
    if (!button) return;
    if (!button.dataset.label) button.dataset.label = button.textContent;
    button.disabled = !!busy;
    button.classList.toggle("is-busy", !!busy);
    if (label) button.textContent = label;
    else if (!busy) button.textContent = button.dataset.label;
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
  const pager = document.querySelector("[data-admins-pager]");
  const roleSelect = document.querySelector("[data-admins-role-select]");
  const permissionsBox = document.querySelector("[data-admins-permissions]");
  const permissionsField = document.querySelector("[data-admins-permissions-field]");
  const permissionsHint = document.querySelector("[data-admins-permissions-hint]");
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
  const viewRoot = document.querySelector("[data-admin-view]");
  const viewHead = document.querySelector("[data-admin-view-head]");
  const viewBody = document.querySelector("[data-admin-view-body]");
  const viewNotice = document.querySelector("[data-admin-view-notice]");
  const reviseFromView = document.querySelector("[data-revise-admin-from-view]");

  let rows = [];
  let roles = [];
  let catalogue = [];
  let editingId = null;
  let viewingId = null;
  let me = window.srsMe || null;
  let adminPage = 1;
  const adminPerPage = 10;
  let adminMeta = { current_page: 1, last_page: 1, per_page: 10, total: 0 };
  let searchDebounceTimer = null;

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
    if (message) {
      try { node.scrollIntoView({ behavior: "smooth", block: "nearest" }); } catch (e) {}
    }
  };

  const formatDate = function (value) {
    if (!value) return "—";
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return "—";
    return date.toLocaleDateString("en-GB", { day: "2-digit", month: "short", year: "numeric" });
  };

  const formatDateTime = function (value) {
    if (!value) return "—";
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return "—";
    return date.toLocaleString("en-GB", {
      day: "2-digit",
      month: "short",
      year: "numeric",
      hour: "2-digit",
      minute: "2-digit"
    });
  };

  const portalLabel = function (value) {
    const map = { portal: "Command desk", staff: "Staff desk", parent: "Family desk", student: "Pupil desk" };
    return map[value] || value || "—";
  };

  const metaCell = function (label, value) {
    return "<div><dt>" + escapeHtml(label) + "</dt><dd>" + escapeHtml(value == null || value === "" ? "—" : value) + "</dd></div>";
  };

  const closeView = function () {
    viewingId = null;
    if (viewRoot) viewRoot.hidden = true;
    document.body.classList.remove("desk-alert-open");
  };

  const openView = function () {
    if (!viewRoot) return;
    viewRoot.hidden = false;
    document.body.classList.add("desk-alert-open");
  };

  const paintView = function (row) {
    viewingId = row.id;
    const summary = row.login_summary || {};
    const rolesList = (row.role_details || []).map(function (role) {
      return "<li><strong>" + escapeHtml(role.name || role.slug || "Role") + "</strong>"
        + "<span>" + escapeHtml(role.is_personal ? "Personal desk role" : (role.slug || ""))
        + (role.permissions_count != null ? " · " + role.permissions_count + " keys" : "")
        + "</span></li>";
    }).join("") || "<li><strong>" + escapeHtml(row.role_name || row.role || "—") + "</strong><span>Assigned role</span></li>";

    const permissionGroups = (row.permission_groups || []).map(function (group) {
      const chips = (group.permissions || []).map(function (item) {
        return "<span>" + escapeHtml(item.name || item.slug) + "</span>";
      }).join("");
      return "<div><p class=\"eyebrow\">" + escapeHtml(group.module || "Module") + "</p>"
        + '<div class="admin-view-chips">' + (chips || "<span>None</span>") + "</div></div>";
    }).join("") || "<p>No desk permissions are assigned.</p>";

    const logRows = ((summary.recent || [])).map(function (item) {
      return "<li><div><strong>" + escapeHtml(formatDateTime(item.logged_in_at)) + "</strong>"
        + "<span>" + escapeHtml(portalLabel(item.portal))
        + (item.ip_address ? " · " + escapeHtml(item.ip_address) : "")
        + "</span></div></li>";
    }).join("") || "<li><div><strong>No sign-ins recorded yet</strong><span>Logins will appear here after the next sign-in.</span></div></li>";

    if (viewHead) {
      viewHead.innerHTML = '<p class="eyebrow">Admin dossier</p>'
        + '<h2 id="adminViewTitle">' + escapeHtml(row.name || "Admin") + "</h2>"
        + "<p>" + escapeHtml(row.email || "") + "</p>"
        + statusBadge(row.status);
    }

    if (viewBody) {
      viewBody.innerHTML = '<dl class="letter-meta pupil-view-meta">'
        + metaCell("Role template", row.role_name || row.role || "—")
        + metaCell("Status", row.status || "—")
        + metaCell("Permissions", String(row.permissions_count != null ? row.permissions_count : ((row.permissions || []).length)))
        + metaCell("Total logins", String(summary.total_logins != null ? summary.total_logins : 0))
        + metaCell("Last login", formatDateTime(summary.last_login_at))
        + metaCell("Last desk", portalLabel(summary.last_portal))
        + metaCell("Created", formatDate(row.created_at))
        + "</dl>"
        + "<h3>Roles</h3><ul class=\"admin-view-log\">" + rolesList + "</ul>"
        + "<h3>Permissions</h3>" + permissionGroups
        + "<h3>Login history</h3><ul class=\"admin-view-log\">" + logRows + "</ul>";
    }

    if (reviseFromView) reviseFromView.hidden = !can("admins.edit");
    if (viewNotice) viewNotice.textContent = "";
    openView();
  };

  const showAdmin = function (id) {
    if (!id || !viewRoot) return;
    if (viewNotice) viewNotice.textContent = "Opening the dossier…";
    openView();
    request("/api/v1/admins/" + id).then(function (res) {
      if (!res.ok || !res.body || !res.body.data) {
        if (viewNotice) viewNotice.textContent = firstError(res.body);
        return;
      }
      paintView(res.body.data);
    }).catch(function () {
      if (viewNotice) viewNotice.textContent = "The office could not open that dossier.";
    });
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

  const selectedPermissions = function () {
    if (!form) return [];
    return Array.prototype.slice.call(form.querySelectorAll('input[name="permissions"]:checked'))
      .map(function (node) { return node.value; });
  };

  const paintPermissions = function (selected) {
    if (!permissionsBox) return;
    const chosen = selected || [];
    const isSuperRole = roleSelect && roleSelect.value === "super_admin";
    if (permissionsField) permissionsField.hidden = !!isSuperRole;
    if (isSuperRole) {
      permissionsBox.innerHTML = "";
      return;
    }
    if (!catalogue.length) {
      permissionsBox.innerHTML = "<p class=\"field-hint\">Permission catalogue unavailable.</p>";
      return;
    }
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

  const permissionsForRole = function (slug) {
    const role = roles.find(function (item) { return item.slug === slug; });
    return (role && role.permissions) || [];
  };

  const resetForm = function () {
    editingId = null;
    if (form) form.reset();
    if (formTitle) formTitle.textContent = "Add admin user";
    if (formCopy) formCopy.textContent = "Create a desk account, pick a role template, and tick the modules they may open";
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
    paintPermissions([]);
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
    if (formCopy) formCopy.textContent = "Update name, email, role template, or desk permissions. Use Reset password for a new key.";
    if (cancelEdit) cancelEdit.hidden = false;
    if (resetPasswordBtn) resetPasswordBtn.hidden = !can("admins.edit") || !!(me && me.id === row.id);
    if (submit) {
      submit.hidden = !can("admins.edit");
      submit.textContent = "Save admin";
      submit.dataset.label = "Save admin";
    }
    paintPermissions(row.is_super_admin ? [] : (row.permissions || permissionsForRole(row.role)));
    setNotice(formNotice, "", true);
    form.scrollIntoView({ behavior: "smooth", block: "nearest" });
  };

  const buildAdminQuery = function () {
    const params = new URLSearchParams();
    params.set("page", String(adminPage));
    params.set("per_page", String(adminPerPage));
    const q = ((search && search.value) || "").trim();
    if (q) params.set("search", q);
    const status = (statusFilter && statusFilter.value) || "";
    if (status) params.set("status", status);
    const role = (roleFilter && roleFilter.value) || "";
    if (role) params.set("role", role);
    return params.toString();
  };

  const renderPagination = function () {
    if (!pager) return;
    const total = adminMeta.total || 0;
    const lastPage = adminMeta.last_page || 1;
    const current = adminMeta.current_page || 1;

    if (total === 0) {
      pager.innerHTML = '<span class="pager-info">Showing 0 of 0 desk accounts</span>';
      return;
    }

    const start = (current - 1) * adminPerPage + 1;
    const end = Math.min(current * adminPerPage, total);
    const infoText = "Showing " + start + "–" + end + " of " + total + " desk accounts";

    let pageBtns = "";
    if (lastPage > 1) {
      for (let i = 1; i <= lastPage; i++) {
        if (i === 1 || i === lastPage || (i >= current - 2 && i <= current + 2)) {
          const isCurrent = i === current;
          pageBtns += '<button type="button" class="ghost-btn' + (isCurrent ? " is-current" : "") + '" data-admin-page="' + i + '"' + (isCurrent ? ' aria-current="page"' : "") + ">" + i + "</button>";
        } else if (i === current - 3 || i === current + 3) {
          pageBtns += '<span class="pager-ellipsis" aria-hidden="true">…</span>';
        }
      }
    }

    pager.innerHTML = '<span class="pager-info">' + escapeHtml(infoText) + '</span>'
      + '<div class="pager-btns">'
      + '<button type="button" class="ghost-btn" data-admin-prev' + (current <= 1 ? " disabled" : "") + '>Previous</button>'
      + (pageBtns ? '<div class="staff-page-numbers">' + pageBtns + '</div>' : '')
      + '<button type="button" class="ghost-btn" data-admin-next' + (current >= lastPage ? " disabled" : "") + '>Next</button>'
      + '</div>';
  };

  const paint = function () {
    const list = rows;
    const total = adminMeta.total || rows.length;
    if (copy) {
      if (total === 0) {
        copy.textContent = "0 desk accounts";
      } else {
        const start = (adminMeta.current_page - 1) * adminPerPage + 1;
        const end = Math.min(adminMeta.current_page * adminPerPage, total);
        copy.textContent = "Showing " + start + "–" + end + " of " + total + " desk accounts";
      }
    }

    if (!list.length) {
      table.innerHTML = '<tr><td colspan="6">No admin users match this search.</td></tr>';
      return;
    }

    table.innerHTML = list.map(function (row) {
      const actions = [];
      if (can("admins.view")) {
        actions.push('<button type="button" class="ghost-btn" data-view="' + row.id + '">View</button>');
      }
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

  const fetchAdmins = function () {
    const qs = buildAdminQuery();
    return request("/api/v1/admins?" + qs).then(function (listRes) {
      if (!listRes.ok) {
        setNotice(notice, firstError(listRes.body), false);
        table.innerHTML = '<tr><td colspan="6">Unable to load admin users.</td></tr>';
        renderPagination();
        return;
      }
      rows = listRes.body.data || [];
      adminMeta = listRes.body.meta || {
        current_page: adminPage,
        last_page: 1,
        per_page: adminPerPage,
        total: rows.length
      };

      if (adminMeta.summary) {
        const s = adminMeta.summary;
        setMetric("admins", String(s.admins != null ? s.admins : adminMeta.total));
        setMetric("active", String(s.active != null ? s.active : "—"));
        setMetric("suspended", String(s.suspended != null ? s.suspended : "—"));
        setMetric("super", String(s.super != null ? s.super : "—"));
      } else {
        setMetric("admins", String(adminMeta.total));
      }

      if (adminMeta.last_page > 0 && adminPage > adminMeta.last_page) {
        adminPage = adminMeta.last_page;
        return fetchAdmins();
      }

      paint();
      renderPagination();
      setNotice(notice, "", true);
    });
  };

  const load = function () {
    return Promise.all([
      fetchAdmins(),
      request("/api/v1/admins/roles"),
      request("/api/v1/admins/permissions")
    ]).then(function (results) {
      const rolesRes = results[1];
      const permissionsRes = results[2];
      roles = (rolesRes.ok && rolesRes.body.data) || [];
      catalogue = (permissionsRes.ok && permissionsRes.body.data) || [];
      paintRoleOptions();
      if (editingId) {
        const current = rows.find(function (item) { return String(item.id) === String(editingId); });
        if (current) paintPermissions(current.is_super_admin ? [] : (current.permissions || []));
      } else if (roleSelect && roleSelect.value) {
        paintPermissions(permissionsForRole(roleSelect.value));
      } else {
        paintPermissions([]);
      }
    });
  };

  if (search) {
    search.addEventListener("input", function () {
      if (searchDebounceTimer) clearTimeout(searchDebounceTimer);
      searchDebounceTimer = setTimeout(function () {
        adminPage = 1;
        fetchAdmins();
      }, 300);
    });
  }
  if (statusFilter) {
    statusFilter.addEventListener("change", function () {
      adminPage = 1;
      fetchAdmins();
    });
  }
  if (roleFilter) {
    roleFilter.addEventListener("change", function () {
      adminPage = 1;
      fetchAdmins();
    });
  }
  if (pager) {
    pager.addEventListener("click", function (event) {
      const pageBtn = event.target.closest("[data-admin-page]");
      const prevBtn = event.target.closest("[data-admin-prev]");
      const nextBtn = event.target.closest("[data-admin-next]");

      if (pageBtn) {
        const target = parseInt(pageBtn.getAttribute("data-admin-page"), 10);
        if (target && target !== adminPage) {
          adminPage = target;
          fetchAdmins();
        }
      } else if (prevBtn && !prevBtn.disabled) {
        if (adminPage > 1) {
          adminPage--;
          fetchAdmins();
        }
      } else if (nextBtn && !nextBtn.disabled) {
        if (adminPage < (adminMeta.last_page || 1)) {
          adminPage++;
          fetchAdmins();
        }
      }
    });
  }
  if (cancelEdit) cancelEdit.addEventListener("click", resetForm);
  if (roleSelect) {
    roleSelect.addEventListener("change", function () {
      if (roleSelect.value === "super_admin") {
        paintPermissions([]);
        if (permissionsHint) permissionsHint.textContent = "Super administrators already hold every desk permission.";
        return;
      }
      if (permissionsHint) permissionsHint.textContent = "Only ticked modules appear in this admin’s sidebar.";
      paintPermissions(permissionsForRole(roleSelect.value));
    });
  }

  table.addEventListener("click", function (event) {
    const viewBtn = event.target.closest("[data-view]");
    const editBtn = event.target.closest("[data-edit]");
    const suspendBtn = event.target.closest("[data-suspend]");
    const reinstateBtn = event.target.closest("[data-reinstate]");
    const deleteBtn = event.target.closest("[data-delete]");

    const viewId = viewBtn && viewBtn.getAttribute("data-view");
    const editId = editBtn && editBtn.getAttribute("data-edit");
    const suspendId = suspendBtn && suspendBtn.getAttribute("data-suspend");
    const reinstateId = reinstateBtn && reinstateBtn.getAttribute("data-reinstate");
    const deleteId = deleteBtn && deleteBtn.getAttribute("data-delete");

    if (viewId) {
      showAdmin(viewId);
      return;
    }

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
        copy: "Remove " + (row && row.name ? row.name : "this account") + " (" + (row && row.email ? row.email : "") + ") from desk access. Historical school records linked to this account are kept.",
        confirm: "Delete",
        danger: true
      }).then(function (ok) {
        if (!ok) return;
        request("/api/v1/admins/" + deleteId + "/remove", { method: "POST", body: "{}" }).then(function (res) {
          if (!res.ok) return setNotice(notice, firstError(res.body), false);
          setNotice(notice, "Admin removed from the desk.", true);
          if (editingId && String(editingId) === String(deleteId)) resetForm();
          return load();
        });
      });
    }
  });

  if (viewRoot) {
    viewRoot.addEventListener("click", function (event) {
      if (event.target.closest("[data-admin-view-dismiss]")) {
        closeView();
      }
    });
  }

  if (reviseFromView) {
    reviseFromView.addEventListener("click", function () {
      const id = viewingId;
      if (!id) return;
      const row = rows.find(function (item) { return String(item.id) === String(id); });
      closeView();
      if (row) {
        fillForm(row);
        return;
      }
      request("/api/v1/admins/" + id).then(function (res) {
        if (res.ok && res.body && res.body.data) fillForm(res.body.data);
      });
    });
  }

  if (form) {
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      const payload = {
        first_name: document.getElementById("adminFirstName").value.trim(),
        last_name: document.getElementById("adminLastName").value.trim(),
        email: document.getElementById("adminEmail").value.trim(),
        role: roleSelect ? roleSelect.value : ""
      };

      if (!payload.first_name || !payload.last_name || !payload.email) {
        setNotice(formNotice, "First name, last name, and email are required.", false);
        return;
      }
      if (!payload.role) {
        setNotice(formNotice, "Choose a role template before saving.", false);
        return;
      }

      if (payload.role !== "super_admin") {
        payload.permissions = selectedPermissions();
        if (!payload.permissions.length) {
          setNotice(formNotice, "Tick at least one desk permission.", false);
          return;
        }
      }

      if (!editingId) {
        payload.password = passwordInput ? passwordInput.value : "";
        payload.password_confirmation = passwordConfirm ? passwordConfirm.value : "";
        if (!payload.password || payload.password.length < 8) {
          setNotice(formNotice, "Password must be at least 8 characters.", false);
          return;
        }
        if (payload.password !== payload.password_confirmation) {
          setNotice(formNotice, "Password confirmation does not match.", false);
          return;
        }
      }

      const url = editingId ? "/api/v1/admins/" + editingId : "/api/v1/admins";
      const wasEditing = !!editingId;
      setButtonState(submit, true, wasEditing ? "Saving…" : "Creating…");
      setNotice(formNotice, wasEditing ? "Saving admin…" : "Creating admin…", true);

      request(url, {
        method: "POST",
        headers: wasEditing ? { "X-HTTP-METHOD-OVERRIDE": "PUT" } : {},
        body: JSON.stringify(payload)
      }).then(function (res) {
        setButtonState(submit, false);
        if (!res.ok) return setNotice(formNotice, firstError(res.body), false);
        setNotice(formNotice, wasEditing ? "Admin saved." : "Admin created.", true);
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
      setNotice(formNotice, "Resetting password…", true);
      request("/api/v1/admins/" + editingId + "/password", {
        method: "POST",
        headers: { "X-HTTP-METHOD-OVERRIDE": "PUT" },
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
