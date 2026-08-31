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

  const naira = function (value) {
    const amount = Number(value || 0);
    if (!Number.isFinite(amount)) return "—";
    return "₦" + amount.toLocaleString("en-NG", { maximumFractionDigits: 0 });
  };

  const fillOptions = function (select, rows, placeholder, extra) {
    if (!select) return;
    const current = select.value;
    const extras = extra || [];
    select.innerHTML = '<option value="">' + placeholder + "</option>" + extras.map(function (item) {
      return '<option value="' + escapeHtml(item.value) + '">' + escapeHtml(item.label) + "</option>";
    }).join("") + (rows || []).map(function (row) {
      return '<option value="' + row.id + '">' + escapeHtml(row.name) + "</option>";
    }).join("");
    if (current && Array.from(select.options).some(function (option) { return option.value === current; })) {
      select.value = current;
    }
  };

  const codeFromName = function (name) {
    return String(name || "").replace(/[^A-Za-z0-9]+/g, "_").replace(/^_|_$/g, "").slice(0, 20).toUpperCase();
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

  const compactLabel = function (pack) {
    if (!pack) return "—";
    if (pack.label) return pack.label;
    return naira(pack);
  };

  const channelLabel = function (channel) {
    if (channel === "pos") return "POS";
    if (!channel) return "—";
    return String(channel).charAt(0).toUpperCase() + String(channel).slice(1);
  };

  const confirmDesk = function (options) {
    const root = document.querySelector("[data-desk-alert]");
    if (!root) return Promise.resolve(false);

    const card = root.querySelector(".desk-alert-card");
    const title = root.querySelector("[data-desk-alert-title]");
    const copy = root.querySelector("[data-desk-alert-copy]");
    const confirmBtn = root.querySelector("[data-desk-alert-confirm]");
    const cancelBtn = root.querySelector(".desk-alert-actions [data-desk-alert-dismiss]");
    const danger = !!(options && options.danger);
    const previous = document.activeElement;

    if (title) title.textContent = (options && options.title) || "Seal this action?";
    if (copy) copy.textContent = (options && options.copy) || "";
    if (confirmBtn) {
      confirmBtn.textContent = (options && options.confirmLabel) || "Confirm";
      confirmBtn.classList.toggle("is-danger", danger);
    }
    if (cancelBtn) cancelBtn.textContent = (options && options.cancelLabel) || "Keep the book";
    if (card) card.classList.toggle("is-danger", danger);

    return new Promise(function (resolve) {
      let settled = false;
      const finish = function (ok) {
        if (settled) return;
        settled = true;
        root.hidden = true;
        document.body.classList.remove("desk-alert-open");
        document.removeEventListener("keydown", onKey);
        root.removeEventListener("click", onClick);
        if (previous && typeof previous.focus === "function") previous.focus();
        resolve(ok);
      };
      const onKey = function (event) {
        if (event.key === "Escape") finish(false);
      };
      const onClick = function (event) {
        if (event.target.closest("[data-desk-alert-confirm]")) {
          event.preventDefault();
          finish(true);
          return;
        }
        if (event.target.closest("[data-desk-alert-dismiss]")) {
          event.preventDefault();
          finish(false);
        }
      };

      root.hidden = false;
      document.body.classList.add("desk-alert-open");
      document.addEventListener("keydown", onKey);
      root.addEventListener("click", onClick);
      const focusTarget = danger ? cancelBtn : confirmBtn;
      if (focusTarget) focusTarget.focus();
    });
  };

  const initials = function (value) {
    if (!value) return "—";
    const parts = String(value).trim().split(/\s+/);
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
  };

  const statusLabel = function (status) {
    if (status === "paid" || status === "paid_in_full") return "Paid in Full";
    if (status === "partial" || status === "partially_paid") return "Partially Paid";
    if (status === "unpaid" || status === "outstanding") return "Outstanding";
    if (status === "posted") return "Posted";
    if (status === "pending") return "Pending";
    if (status === "failed") return "Failed";
    if (status === "void") return "Void";
    return status ? String(status) : "—";
  };

  const badgeClass = function (status) {
    if (status === "paid" || status === "paid_in_full" || status === "posted") return "ok";
    if (status === "partial" || status === "partially_paid" || status === "pending") return "warn";
    return "low";
  };

  const setMetric = function (key, value, delta) {
    const valueNode = document.querySelector('[data-metric="' + key + '"]');
    const deltaNode = document.querySelector('[data-metric-delta="' + key + '"]');
    if (valueNode) valueNode.textContent = value == null || value === "" ? "—" : value;
    if (delta && deltaNode) deltaNode.textContent = delta;
  };

  const wireFeeBook = function () {
    const table = document.querySelector("[data-fee-table]");
    const form = document.querySelector("[data-fee-form]");
    if (!table || !form) return;

    const PAGE_SIZE = 10;
    const notice = document.querySelector("[data-fee-notice]");
    const formNotice = document.querySelector("[data-fee-form-notice]");
    const copy = document.querySelector("[data-fee-copy]");
    const pager = document.querySelector("[data-fee-pager]");
    const pages = document.querySelector("[data-fee-pages]");
    const search = document.getElementById("feeSearch");
    const sessionFilter = document.getElementById("feeSessionFilter");
    const termFilter = document.getElementById("feeTermFilter");
    const newTypeFields = document.querySelector("[data-new-type]");
    const clearBtn = document.querySelector("[data-fee-clear]");
    const submitBtn = form.querySelector('button[type="submit"]');
    let types = [];
    let structures = [];
    let sessions = [];
    let levels = [];
    let settings = {};
    let page = 1;

    const setNotice = function (node, message) {
      if (node) node.textContent = message || "";
    };

    const scopeLabel = function (row) {
      if (row.class_name) return row.class_name;
      if (row.level_name) return row.level_name;
      return "Whole school";
    };

    const typeName = function (row) {
      return row.fee_type || ((types.find(function (item) { return Number(item.id) === Number(row.fee_type_id); }) || {}).name) || "Fee";
    };

    const termsForSession = function (sessionId) {
      const session = sessions.find(function (row) { return Number(row.id) === Number(sessionId); });
      return (session && session.terms) || [];
    };

    const classesForLevel = function (levelId) {
      const level = levels.find(function (row) { return Number(row.id) === Number(levelId); });
      return (level && level.classes) || [];
    };

    const syncNewType = function () {
      const typeSelect = document.getElementById("feeType");
      if (newTypeFields) newTypeFields.hidden = !(typeSelect && typeSelect.value === "new");
    };

    const syncTerms = function (select, sessionId, placeholder) {
      fillOptions(select, termsForSession(sessionId), placeholder || "Whole session");
    };

    const syncClasses = function () {
      const levelId = (document.getElementById("feeLevel") || {}).value;
      fillOptions(document.getElementById("feeClass"), classesForLevel(levelId), "Whole level");
    };

    const fillTypeSelect = function () {
      fillOptions(document.getElementById("feeType"), types.filter(function (row) {
        return row.is_active !== false;
      }).map(function (row) {
        return { id: row.id, name: row.name + (row.code ? " · " + row.code : "") };
      }), "Select a fee", [{ value: "new", label: "New fee…" }]);
    };

    const resetForm = function () {
      form.reset();
      if (document.getElementById("feeStructureId")) document.getElementById("feeStructureId").value = "";
      const title = document.querySelector("[data-fee-form-title]");
      if (title) title.textContent = "Set a fee";
      if (submitBtn) {
        submitBtn.dataset.label = "Seal the fee";
        submitBtn.textContent = "Seal the fee";
      }
      if (clearBtn) clearBtn.hidden = true;
      if (newTypeFields) newTypeFields.hidden = true;
      const currentSessionId = settings.current_academic_session_id
        || (sessions.find(function (row) { return row.status === "active"; }) || {}).id;
      if (currentSessionId && document.getElementById("feeSession")) {
        document.getElementById("feeSession").value = String(currentSessionId);
      }
      syncTerms(document.getElementById("feeTerm"), (document.getElementById("feeSession") || {}).value, "Whole session");
      if (settings.current_term_id && document.getElementById("feeTerm")) {
        document.getElementById("feeTerm").value = String(settings.current_term_id);
      }
      syncClasses();
      fillTypeSelect();
    };

    const visibleRows = function () {
      const query = search ? search.value.trim().toLowerCase() : "";
      const sessionId = sessionFilter ? Number(sessionFilter.value) : 0;
      const termId = termFilter ? Number(termFilter.value) : 0;
      return structures.filter(function (row) {
        const hay = [typeName(row), row.term_name, row.session_name, scopeLabel(row)].join(" ").toLowerCase();
        if (query && hay.indexOf(query) === -1) return false;
        if (sessionId && Number(row.academic_session_id) !== sessionId) return false;
        if (termId && Number(row.term_id) !== termId) return false;
        return true;
      }).sort(function (a, b) {
        return (typeName(a) + scopeLabel(a)).localeCompare(typeName(b) + scopeLabel(b));
      });
    };

    const apply = function () {
      const rows = visibleRows();
      const lastPage = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
      if (page > lastPage) page = lastPage;
      const start = (page - 1) * PAGE_SIZE;
      const slice = rows.slice(start, start + PAGE_SIZE);

      if (copy) {
        copy.textContent = rows.length
          ? rows.length + " fee" + (rows.length === 1 ? "" : "s") + " on the book"
          : "No fees on the book yet";
      }

      if (!rows.length) {
        table.innerHTML = '<tr><td colspan="5">No fees match the book filters.</td></tr>';
      } else {
        table.innerHTML = slice.map(function (row) {
          return "<tr>"
            + '<td><div class="person"><span class="mark">' + escapeHtml((row.fee_type_code || typeName(row)).slice(0, 3).toUpperCase())
            + "</span><div><strong>" + escapeHtml(typeName(row)) + "</strong><small>"
            + escapeHtml(row.session_name || "") + "</small></div></div></td>"
            + "<td>" + escapeHtml(row.term_name || "Whole session") + "</td>"
            + "<td>" + escapeHtml(scopeLabel(row)) + "</td>"
            + "<td>" + escapeHtml(naira(row.amount_naira)) + "</td>"
            + '<td><div class="row-actions">'
            + '<button class="ghost-btn" type="button" data-revise-fee="' + row.id + '">Revise</button>'
            + '<button class="ghost-btn" type="button" data-delete-fee="' + row.id
            + '" data-name="' + escapeHtml(typeName(row)) + '">Remove</button></div></td>'
            + "</tr>";
        }).join("");
      }

      if (pager) {
        if (!rows.length) pager.textContent = "Showing 0 of 0";
        else pager.textContent = "Showing " + (start + 1) + "–" + (start + slice.length) + " of " + rows.length;
      }
      if (pages) {
        let html = "";
        if (lastPage > 1) {
          html += '<button type="button" data-fee-page="' + Math.max(1, page - 1) + '"' + (page === 1 ? " disabled" : "") + ">Prev</button>";
          for (let i = 1; i <= lastPage; i += 1) {
            html += '<button type="button" data-fee-page="' + i + '"' + (i === page ? ' class="current"' : "") + ">" + i + "</button>";
          }
          html += '<button type="button" data-fee-page="' + Math.min(lastPage, page + 1) + '"' + (page === lastPage ? " disabled" : "") + ">Next</button>";
        }
        pages.innerHTML = html;
      }
    };

    const load = function () {
      return Promise.all([
        request("/api/v1/fee-structures"),
        request("/api/v1/fee-types"),
        request("/api/v1/academic-sessions"),
        request("/api/v1/levels"),
        request("/api/v1/school-settings")
      ]).then(function (results) {
        if (!results[0].ok) {
          table.innerHTML = "<tr><td colspan=\"5\">" + escapeHtml(firstError(results[0].body)) + "</td></tr>";
          return;
        }
        structures = results[0].body.data || [];
        types = (results[1].ok && results[1].body.data) || [];
        sessions = (results[2].ok && results[2].body.data) || [];
        levels = (results[3].ok && results[3].body.data) || [];
        settings = (results[4].ok && results[4].body.data) || {};

        fillOptions(sessionFilter, sessions.map(function (row) {
          return { id: row.id, name: row.name + " Session" };
        }), "All sessions");
        fillOptions(document.getElementById("feeSession"), sessions, "Select session");
        fillOptions(document.getElementById("feeLevel"), levels, "Whole school");
        fillTypeSelect();

        const currentSessionId = settings.current_academic_session_id
          || (sessions.find(function (row) { return row.status === "active"; }) || {}).id;
        if (currentSessionId) {
          if (sessionFilter && !sessionFilter.value) sessionFilter.value = String(currentSessionId);
          if (document.getElementById("feeSession") && !document.getElementById("feeSession").value) {
            document.getElementById("feeSession").value = String(currentSessionId);
          }
        }
        syncTerms(termFilter, sessionFilter && sessionFilter.value, "All terms");
        syncTerms(document.getElementById("feeTerm"), (document.getElementById("feeSession") || {}).value, "Whole session");
        if (settings.current_term_id) {
          if (termFilter && !termFilter.value) termFilter.value = String(settings.current_term_id);
          if (document.getElementById("feeTerm") && !document.getElementById("feeTerm").value) {
            document.getElementById("feeTerm").value = String(settings.current_term_id);
          }
        }
        syncClasses();
        apply();
      });
    };

    const fillRevise = function (row) {
      if (!row) return;
      if (document.getElementById("feeStructureId")) document.getElementById("feeStructureId").value = String(row.id);
      fillTypeSelect();
      if (document.getElementById("feeType")) document.getElementById("feeType").value = String(row.fee_type_id);
      syncNewType();
      if (document.getElementById("feeSession")) document.getElementById("feeSession").value = String(row.academic_session_id);
      syncTerms(document.getElementById("feeTerm"), row.academic_session_id, "Whole session");
      if (document.getElementById("feeTerm")) document.getElementById("feeTerm").value = row.term_id ? String(row.term_id) : "";
      if (document.getElementById("feeLevel")) document.getElementById("feeLevel").value = row.level_id ? String(row.level_id) : "";
      syncClasses();
      if (document.getElementById("feeClass")) document.getElementById("feeClass").value = row.school_class_id ? String(row.school_class_id) : "";
      if (document.getElementById("feeAmount")) document.getElementById("feeAmount").value = String(Math.round(Number(row.amount_naira) || 0));
      const title = document.querySelector("[data-fee-form-title]");
      if (title) title.textContent = "Revise this fee";
      if (submitBtn) {
        submitBtn.dataset.label = "Revise the fee";
        submitBtn.textContent = "Revise the fee";
      }
      if (clearBtn) clearBtn.hidden = false;
      const panel = document.getElementById("set-fee");
      if (panel && typeof panel.scrollIntoView === "function") {
        panel.scrollIntoView({ behavior: "smooth", block: "nearest" });
      }
    };

    [search, sessionFilter, termFilter].forEach(function (node) {
      if (!node) return;
      node.addEventListener("input", function () { page = 1; apply(); });
      node.addEventListener("change", function () {
        if (node === sessionFilter) {
          syncTerms(termFilter, sessionFilter.value, "All terms");
        }
        page = 1;
        apply();
      });
    });

    if (pages) {
      pages.addEventListener("click", function (event) {
        const button = event.target.closest("[data-fee-page]");
        if (!button || button.disabled) return;
        page = Number(button.getAttribute("data-fee-page")) || 1;
        apply();
      });
    }

    const typeSelect = document.getElementById("feeType");
    if (typeSelect) typeSelect.addEventListener("change", syncNewType);
    const sessionSelect = document.getElementById("feeSession");
    if (sessionSelect) sessionSelect.addEventListener("change", function () {
      syncTerms(document.getElementById("feeTerm"), sessionSelect.value, "Whole session");
    });
    const levelSelect = document.getElementById("feeLevel");
    if (levelSelect) levelSelect.addEventListener("change", syncClasses);

    if (clearBtn) {
      clearBtn.addEventListener("click", function () {
        resetForm();
        setNotice(formNotice, "");
      });
    }

    table.addEventListener("click", function (event) {
      const revise = event.target.closest("[data-revise-fee]");
      if (revise) {
        const row = structures.find(function (item) { return String(item.id) === String(revise.getAttribute("data-revise-fee")); });
        fillRevise(row);
        return;
      }
      const del = event.target.closest("[data-delete-fee]");
      if (!del) return;
      const id = del.getAttribute("data-delete-fee");
      const name = del.getAttribute("data-name") || "this fee";
      confirmDesk({
        title: "Remove this fee",
        copy: name + " will leave the book. Raised invoices stay sealed.",
        confirmLabel: "Remove fee",
        cancelLabel: "Keep it",
        danger: true
      }).then(function (ok) {
        if (!ok) return;
        setNotice(notice, "");
        request("/api/v1/fee-structures/" + id, { method: "DELETE" }).then(function (result) {
          if (!result.ok) {
            setNotice(notice, firstError(result.body));
            return;
          }
          return load();
        });
      });
    });

    form.addEventListener("submit", function (event) {
      event.preventDefault();
      setNotice(formNotice, "");
      const structureId = ((document.getElementById("feeStructureId") || {}).value || "").trim();
      const typeChoice = (document.getElementById("feeType") || {}).value || "";
      const amount = Number((document.getElementById("feeAmount") || {}).value || 0);
      const payload = {
        academic_session_id: Number((document.getElementById("feeSession") || {}).value),
        term_id: Number((document.getElementById("feeTerm") || {}).value) || null,
        level_id: Number((document.getElementById("feeLevel") || {}).value) || null,
        school_class_id: Number((document.getElementById("feeClass") || {}).value) || null,
        amount: amount
      };

      const ensureType = function () {
        if (typeChoice && typeChoice !== "new") {
          return Promise.resolve(Number(typeChoice));
        }
        const name = ((document.getElementById("feeTypeName") || {}).value || "").trim();
        const code = ((document.getElementById("feeTypeCode") || {}).value || "").trim() || codeFromName(name);
        return request("/api/v1/fee-types", {
          method: "POST",
          body: JSON.stringify({ name: name, code: code })
        }).then(function (result) {
          if (!result.ok) throw result;
          return result.body.data.id;
        });
      };

      setButtonState(submitBtn, true, structureId ? "Revising…" : "Sealing…");
      const save = structureId
        ? Promise.resolve(request("/api/v1/fee-structures/" + structureId, {
            method: "PUT",
            body: JSON.stringify(payload)
          }))
        : ensureType().then(function (typeId) {
            payload.fee_type_id = typeId;
            return request("/api/v1/fee-structures", {
              method: "POST",
              body: JSON.stringify(payload)
            });
          });

      save.then(function (result) {
        if (!result.ok) throw result;
        resetForm();
        setButtonState(submitBtn, false, "Sealed.");
        window.setTimeout(function () { setButtonState(submitBtn, false); }, 1600);
        return load();
      }).catch(function (result) {
        const message = result && result.body ? firstError(result.body) : "Unable to reach the office.";
        setNotice(formNotice, message);
        setButtonState(submitBtn, false, message);
        window.setTimeout(function () { setButtonState(submitBtn, false); }, 2200);
      });
    });

    load();
  };

  const wireAdminLedger = function () {
    const form = document.querySelector("[data-payment-form]");
    const tickets = document.querySelector("[data-receipt-list]");
    if (!form || !tickets) return;

    const notice = document.querySelector("[data-payment-notice]");
    const hint = document.querySelector("[data-pupil-hint]");
    const ledgerCopy = document.querySelector("[data-ledger-copy]");
    const receiptCopy = document.querySelector("[data-receipt-copy]");
    const raiseBtn = document.querySelector("[data-raise-invoices]");
    const submitBtn = form.querySelector('button[type="submit"]');
    const pupilInput = document.getElementById("pupil");
    let currentTermId = null;
    let lookupTimer = null;

    const setNotice = function (node, message) {
      if (node) node.textContent = message || "";
    };

    const loadSummary = function () {
      return request("/api/v1/invoices/summary").then(function (result) {
        if (!result.ok) {
          setNotice(ledgerCopy, firstError(result.body));
          return;
        }
        const data = result.body.data || {};
        currentTermId = data.term_id || null;
        const termLabel = [data.term_name, data.session_name].filter(Boolean).join(" · ") || "Current term";
        const share = data.collection_share;
        setMetric("expected", compactLabel(data.expected), termLabel);
        setMetric("collected", compactLabel(data.collected), share == null ? "Nothing posted" : share + "% of the book");
        setMetric("outstanding", compactLabel(data.outstanding), "Still due");
        setMetric("today", compactLabel(data.today), "Posted today");
        setNotice(ledgerCopy, termLabel + (data.invoice_count ? " · " + data.invoice_count + " invoices" : " · No invoices raised"));
        if (raiseBtn) raiseBtn.disabled = !currentTermId;
      });
    };

    const loadReceipts = function () {
      return request("/api/v1/payments?status=posted&limit=8").then(function (result) {
        if (!result.ok) {
          tickets.innerHTML = "<p>" + escapeHtml(firstError(result.body)) + "</p>";
          setNotice(receiptCopy, "Unable to read the ledger");
          return;
        }
        const rows = result.body.data || [];
        if (!rows.length) {
          tickets.innerHTML = "<p>No receipts have been posted yet.</p>";
          setNotice(receiptCopy, "Waiting for the first post");
          return;
        }
        setNotice(receiptCopy, "Latest posts on the book");
        tickets.innerHTML = rows.map(function (row) {
          const line = [row.student_name || row.admission_number, row.form, channelLabel(row.channel)]
            .filter(Boolean).join(" · ");
          return '<article class="ticket">'
            + '<div class="ticket-code">' + escapeHtml(row.reference || "—") + "</div>"
            + "<div><h3>" + escapeHtml(naira(row.amount_naira)) + "</h3><p>" + escapeHtml(line) + "</p></div>"
            + '<span class="badge ' + badgeClass(row.status) + '">' + escapeHtml(statusLabel(row.status)) + "</span>"
            + "</article>";
        }).join("");
      });
    };

    const lookupPupil = function () {
      const number = ((pupilInput && pupilInput.value) || "").trim();
      if (!number) {
        setNotice(hint, "");
        return;
      }
      request("/api/v1/invoices?admission_number=" + encodeURIComponent(number)).then(function (result) {
        if (((pupilInput && pupilInput.value) || "").trim() !== number) return;
        if (!result.ok) {
          setNotice(hint, firstError(result.body));
          return;
        }
        const rows = result.body.data || [];
        const open = rows.filter(function (row) {
          return row.status === "unpaid" || row.status === "partial";
        });
        if (open.length) {
          const invoice = open[0];
          setNotice(hint, (invoice.student_name || number) + " · " + naira(invoice.balance_naira) + " remaining"
            + (invoice.term_name ? " · " + invoice.term_name : ""));
          return;
        }
        if (rows.length) {
          setNotice(hint, (rows[0].student_name || number) + " has no open invoice.");
          return;
        }
        setNotice(hint, "No invoice on this admission number.");
      });
    };

    if (pupilInput) {
      pupilInput.addEventListener("input", function () {
        window.clearTimeout(lookupTimer);
        lookupTimer = window.setTimeout(lookupPupil, 280);
      });
      pupilInput.addEventListener("blur", lookupPupil);
    }

    form.addEventListener("submit", function (event) {
      event.preventDefault();
      setNotice(notice, "");
      const payload = {
        admission_number: ((document.getElementById("pupil") || {}).value || "").trim(),
        amount: Number((document.getElementById("amount") || {}).value || 0),
        channel: (document.getElementById("method") || {}).value,
        note: ((document.getElementById("note") || {}).value || "").trim() || null,
        paid_at: ((document.getElementById("paidOn") || {}).value || "").trim() || null
      };
      setButtonState(submitBtn, true, "Posting…");
      request("/api/v1/payments", {
        method: "POST",
        body: JSON.stringify(payload)
      }).then(function (result) {
        if (!result.ok) {
          setNotice(notice, firstError(result.body));
          setButtonState(submitBtn, false);
          return;
        }
        form.reset();
        setNotice(hint, "");
        setNotice(notice, "Posted " + naira(result.body.data.amount_naira) + " · " + (result.body.data.reference || ""));
        setButtonState(submitBtn, false, "Posted.");
        window.setTimeout(function () { setButtonState(submitBtn, false); }, 1600);
        return Promise.all([
          window.srsReloadFeeLedger ? window.srsReloadFeeLedger() : loadSummary(),
          loadReceipts()
        ]);
      }).catch(function () {
        setNotice(notice, "Unable to reach the office.");
        setButtonState(submitBtn, false);
      });
    });

    if (raiseBtn) {
      raiseBtn.addEventListener("click", function () {
        if (!currentTermId) {
          setNotice(notice, "Seal a current term in Setup before raising invoices.");
          return;
        }
        confirmDesk({
          title: "Raise the term’s invoices",
          copy: "Every enrolled pupil without a term invoice will be raised onto the book. Existing invoices stay sealed.",
          confirmLabel: "Raise invoices",
          cancelLabel: "Leave the book",
          danger: false
        }).then(function (ok) {
          if (!ok) return;
          setButtonState(raiseBtn, true, "Raising…");
          request("/api/v1/invoices/generate", {
            method: "POST",
            body: JSON.stringify({ term_id: currentTermId })
          }).then(function (result) {
            if (!result.ok) {
              setNotice(notice, firstError(result.body));
              setButtonState(raiseBtn, false);
              return;
            }
            const data = result.body.data || {};
            setNotice(notice, "Raised " + (data.created || 0) + " invoices · skipped " + (data.skipped || 0) + ".");
            if (notice) notice.style.color = "var(--navy)";
            setButtonState(raiseBtn, false, "Raised.");
            window.setTimeout(function () {
              setButtonState(raiseBtn, false);
              if (notice) notice.style.color = "";
            }, 1800);
            return Promise.all([
              window.srsReloadFeeLedger ? window.srsReloadFeeLedger() : loadSummary(),
              loadReceipts(),
              lookupPupil()
            ]);
          }).catch(function () {
            setNotice(notice, "Unable to reach the office.");
            setButtonState(raiseBtn, false);
          });
        });
      });
    }

    loadSummary();
    loadReceipts();
  };

  const wireInvoiceLedger = function () {
    const table = document.querySelector("[data-invoice-table]");
    if (!table) return;

    const copy = document.querySelector("[data-invoice-copy]");
    const pager = document.querySelector("[data-invoice-pager]");
    const pages = document.querySelector("[data-invoice-pages]");
    const search = document.getElementById("invoiceSearch");
    const sessionFilter = document.getElementById("invoiceSessionFilter");
    const termFilter = document.getElementById("invoiceTermFilter");
    const classFilter = document.getElementById("invoiceClassFilter");
    const sectionFilter = document.getElementById("invoiceSectionFilter");
    const statusFilter = document.getElementById("invoiceStatusFilter");
    const PAGE_SIZE = 12;
    let page = 1;
    let rows = [];
    let sessions = [];
    let classes = [];
    let sections = [];
    let currentSessionId = null;
    let currentTermId = null;
    let timer = null;

    const termsForSession = function (sessionId) {
      const session = sessions.filter(function (item) { return String(item.id) === String(sessionId); })[0];
      return (session && session.terms) || [];
    };

    const sectionsForClass = function (classId) {
      if (!classId) return sections;
      return sections.filter(function (item) { return String(item.school_class_id) === String(classId); });
    };

    const queryString = function () {
      const params = new URLSearchParams();
      const q = search ? search.value.trim() : "";
      const sessionId = sessionFilter ? sessionFilter.value : "";
      const termId = termFilter ? termFilter.value : "";
      const classId = classFilter ? classFilter.value : "";
      const sectionId = sectionFilter ? sectionFilter.value : "";
      const status = statusFilter ? statusFilter.value : "";
      if (q) params.set("q", q);
      if (sessionId) params.set("academic_session_id", sessionId);
      if (termId) params.set("term_id", termId);
      if (classId) params.set("school_class_id", classId);
      if (sectionId) params.set("class_section_id", sectionId);
      if (status) params.set("status", status);
      if (!q && !sessionId && !termId && !classId && !sectionId && !status) {
        params.set("scope", "all");
      }
      return params.toString();
    };

    const apply = function () {
      const lastPage = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
      if (page > lastPage) page = lastPage;
      const start = (page - 1) * PAGE_SIZE;
      const slice = rows.slice(start, start + PAGE_SIZE);

      if (!rows.length) {
        table.innerHTML = '<tr><td colspan="9">No invoices match these filters.</td></tr>';
      } else {
        table.innerHTML = slice.map(function (row) {
          const status = row.fee_status || row.status;
          return "<tr>"
            + "<td><strong>" + escapeHtml(row.student_name || "—") + "</strong></td>"
            + "<td>" + escapeHtml(row.admission_number || "—") + "</td>"
            + "<td>" + escapeHtml(row.form || "—") + "</td>"
            + "<td>" + escapeHtml(row.session_name || "—") + "</td>"
            + "<td>" + escapeHtml(row.term_name || "—") + "</td>"
            + "<td>" + naira(row.total_naira) + "</td>"
            + "<td>" + naira(row.paid_naira) + "</td>"
            + "<td>" + naira(row.balance_naira) + "</td>"
            + '<td><span class="badge ' + badgeClass(status) + '">'
            + escapeHtml(row.fee_status_label || statusLabel(status)) + "</span></td>"
            + "</tr>";
        }).join("");
      }

      if (pager) {
        pager.textContent = rows.length
          ? (start + 1) + "–" + Math.min(start + PAGE_SIZE, rows.length) + " of " + rows.length
          : "No invoices on the book";
      }
      if (pages) {
        if (lastPage <= 1) {
          pages.innerHTML = "";
        } else {
          let html = '<button type="button" data-invoice-page="' + Math.max(1, page - 1) + '"'
            + (page === 1 ? " disabled" : "") + ">Prev</button>";
          for (let i = 1; i <= lastPage; i++) {
            html += '<button type="button" data-invoice-page="' + i + '"'
              + (i === page ? ' class="current"' : "") + ">" + i + "</button>";
          }
          html += '<button type="button" data-invoice-page="' + Math.min(lastPage, page + 1) + '"'
            + (page === lastPage ? " disabled" : "") + ">Next</button>";
          pages.innerHTML = html;
        }
      }
    };

    const paintSummary = function (data) {
      const termLabel = [data.term_name, data.session_name].filter(Boolean).join(" · ") || "Selected book";
      const share = data.collection_share;
      setMetric("expected", compactLabel(data.expected), termLabel);
      setMetric("collected", compactLabel(data.collected), share == null ? "Nothing posted" : share + "% of the book");
      setMetric("outstanding", compactLabel(data.outstanding), "Still due");
      setMetric("today", compactLabel(data.today), "Posted today");
      if (copy) {
        copy.textContent = (data.students_with_fees || 0) + " invoices · "
          + (data.paid_in_full_count || 0) + " paid in full · "
          + (data.partially_paid_count || 0) + " partial · "
          + (data.outstanding_count || 0) + " outstanding";
      }
    };

    const load = function () {
      const qs = queryString();
      const suffix = qs ? ("?" + qs) : "";
      return Promise.all([
        request("/api/v1/invoices/summary" + suffix),
        request("/api/v1/invoices" + suffix)
      ]).then(function (results) {
        if (results[0].ok) {
          paintSummary(results[0].body.data || {});
        } else if (copy) {
          copy.textContent = firstError(results[0].body);
        }
        if (!results[1].ok) {
          table.innerHTML = '<tr><td colspan="9">' + escapeHtml(firstError(results[1].body)) + "</td></tr>";
          return;
        }
        rows = results[1].body.data || [];
        apply();
      }).catch(function () {
        table.innerHTML = '<tr><td colspan="9">Unable to reach the office.</td></tr>';
      });
    };

    window.srsReloadFeeLedger = load;

    const syncTerms = function (sessionId) {
      fillOptions(termFilter, termsForSession(sessionId), "All terms");
    };

    const syncSections = function (classId) {
      fillOptions(sectionFilter, sectionsForClass(classId), "All arms");
    };

    Promise.all([
      request("/api/v1/academic-sessions"),
      request("/api/v1/classes"),
      request("/api/v1/invoices/summary")
    ]).then(function (results) {
      sessions = results[0].ok ? (results[0].body.data || []) : [];
      classes = results[1].ok ? (results[1].body.data || []) : [];
      const summary = results[2].ok ? (results[2].body.data || {}) : {};
      currentSessionId = summary.academic_session_id || null;
      currentTermId = summary.term_id || null;
      sections = [];
      classes.forEach(function (row) {
        (row.sections || []).forEach(function (section) {
          sections.push({
            id: section.id,
            name: section.name || section.arm || "Arm",
            school_class_id: row.id
          });
        });
      });

      fillOptions(sessionFilter, sessions, "All sessions");
      fillOptions(classFilter, classes, "All classes");
      if (currentSessionId && sessionFilter) sessionFilter.value = String(currentSessionId);
      syncTerms(sessionFilter ? sessionFilter.value : "");
      if (currentTermId && termFilter) termFilter.value = String(currentTermId);
      syncSections(classFilter ? classFilter.value : "");
      page = 1;
      return load();
    }).catch(function () {
      table.innerHTML = '<tr><td colspan="9">Unable to reach the office.</td></tr>';
    });

    [search, sessionFilter, termFilter, classFilter, sectionFilter, statusFilter].forEach(function (node) {
      if (!node) return;
      node.addEventListener(node.tagName === "INPUT" ? "input" : "change", function () {
        if (node === sessionFilter) {
          syncTerms(sessionFilter.value);
          if (termFilter) termFilter.value = "";
        }
        if (node === classFilter) {
          syncSections(classFilter.value);
          if (sectionFilter) sectionFilter.value = "";
        }
        page = 1;
        window.clearTimeout(timer);
        timer = window.setTimeout(load, node.tagName === "INPUT" ? 280 : 0);
      });
    });

    if (pages) {
      pages.addEventListener("click", function (event) {
        const button = event.target.closest("[data-invoice-page]");
        if (!button) return;
        page = Number(button.getAttribute("data-invoice-page")) || 1;
        apply();
      });
    }
  };

  const setText = function (node, value) {
    if (node) node.textContent = value;
  };

  const renderParentInvoice = function (invoice, payments, child) {
    const cards = document.querySelectorAll(".fee-summary-card");
    if (cards[0]) {
      setText(cards[0].querySelector("h3"), naira(invoice.total_naira));
      setText(cards[0].querySelector("small"), invoice.session_name || "Academic session");
    }
    if (cards[1]) {
      setText(cards[1].querySelector("h3"), naira(invoice.paid_naira));
      setText(cards[1].querySelector("small"), (invoice.percentage_paid || 0) + "% of total fees paid");
    }
    if (cards[2]) {
      setText(cards[2].querySelector("h3"), naira(invoice.balance_naira));
      setText(cards[2].querySelector("small"), invoice.balance_naira > 0 ? "Payment required" : "Fully paid");
    }

    const heading = document.querySelector(".current-fee-card .card-heading p");
    const badge = document.querySelector(".current-fee-card .status-badge");
    setText(heading, (invoice.term_name || "Term") + " • " + (invoice.session_name || ""));
    if (badge) {
      badge.className = "status-badge " + (invoice.fee_status === "paid_in_full" || invoice.status === "paid" ? "paid" : "pending");
      badge.textContent = invoice.fee_status_label || statusLabel(invoice.status);
    }

    const nameNode = document.querySelector(".student-mini-profile h6");
    const metaNode = document.querySelector(".student-mini-profile span");
    const avatar = document.querySelector(".student-mini-avatar");
    const displayName = (child && child.full_name) || invoice.student_name || "—";
    setText(nameNode, displayName);
    setText(metaNode, (invoice.form || "") + " • Student ID: " + (invoice.admission_number || "—"));
    if (avatar) avatar.textContent = initials(displayName);

    const percent = document.querySelector(".progress-label strong");
    const bar = document.querySelector(".fee-progress .progress-bar");
    setText(percent, (invoice.percentage_paid || 0) + "%");
    if (bar) bar.style.width = (invoice.percentage_paid || 0) + "%";

    const figures = document.querySelectorAll(".payment-figures strong");
    if (figures[0]) figures[0].textContent = naira(invoice.paid_naira);
    if (figures[1]) figures[1].textContent = naira(invoice.balance_naira);

    const deadline = document.querySelector(".payment-deadline-card h3");
    if (deadline && invoice.due_on) {
      const date = new Date(invoice.due_on + "T00:00:00");
      deadline.textContent = date.toLocaleDateString("en-GB", { day: "numeric", month: "long" });
    }

    const breakdownHeading = document.querySelector(".fee-table")
      ? document.querySelectorAll(".portal-card .card-heading p")[1]
      : null;
    const breakdownPs = document.querySelectorAll(".portal-card.mb-4 .card-heading p, .portal-card:nth-of-type(1) .card-heading p");
    document.querySelectorAll(".card-heading p").forEach(function (node) {
      if (node.previousElementSibling && node.previousElementSibling.textContent.indexOf("Fee Breakdown") !== -1) {
        node.textContent = (invoice.term_name || "Term") + " • " + (invoice.session_name || "");
      }
      if (node.previousElementSibling && node.previousElementSibling.textContent.indexOf("Payment History") !== -1) {
        node.textContent = "Recent payments made for " + displayName;
      }
    });

    const body = document.querySelector(".fee-table tbody");
    const foot = document.querySelector(".fee-table tfoot");
    const items = invoice.items || [];
    if (body) {
      body.innerHTML = items.map(function (item) {
        const klass = item.status === "paid" ? "paid" : (item.status === "partial" ? "pending" : "unpaid");
        return "<tr><td><div class=\"fee-description\"><div><strong>" + (item.description || item.fee_type || "Fee")
          + "</strong></div></div></td><td>" + naira(item.amount_naira) + "</td><td>"
          + naira(item.paid_naira) + "</td><td>" + naira(item.balance_naira)
          + '</td><td><span class="status-badge ' + klass + '">' + statusLabel(item.status) + "</span></td></tr>";
      }).join("") || "<tr><td colspan=\"5\">No fee lines on this invoice.</td></tr>";
    }
    if (foot) {
      foot.innerHTML = "<tr><td><strong>Total</strong></td><td><strong>" + naira(invoice.total_naira)
        + "</strong></td><td><strong>" + naira(invoice.paid_naira) + "</strong></td><td><strong>"
        + naira(invoice.balance_naira) + "</strong></td><td></td></tr>";
    }

    const history = document.querySelector(".payment-history-table tbody");
    if (history) {
      const rows = (payments || []).filter(function (row) {
        return row.status === "posted" && (!invoice.id || Number(row.invoice_id) === Number(invoice.id));
      });
      if (!rows.length) {
        history.innerHTML = "<tr><td colspan=\"6\">No payments have been posted yet.</td></tr>";
      } else {
        history.innerHTML = rows.map(function (row) {
          const paid = row.paid_at ? new Date(row.paid_at) : null;
          const dateLabel = paid ? paid.toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" }) : "—";
          const timeLabel = paid ? paid.toLocaleTimeString("en-GB", { hour: "numeric", minute: "2-digit" }) : "";
          return "<tr><td><strong>" + dateLabel + "</strong><small>" + timeLabel + "</small></td>"
            + '<td><span class="payment-reference">' + (row.reference || "—") + "</span></td>"
            + "<td>" + (row.note || "School Fees") + "</td>"
            + "<td><strong>" + naira(row.amount_naira) + "</strong></td>"
            + '<td><span class="status-badge paid">Successful</span></td>'
            + '<td><button type="button" class="receipt-btn" data-receipt="' + row.id + '"><i class="bi bi-receipt"></i> Receipt</button></td></tr>';
        }).join("");
      }
    }
  };

  const loadParentFees = function (studentId, child) {
    const query = studentId ? ("?student_profile_id=" + encodeURIComponent(studentId)) : "";
    Promise.all([
      request("/api/v1/invoices" + query),
      request("/api/v1/payments" + query)
    ]).then(function (results) {
      if (!results[0].ok) {
        const body = document.querySelector(".fee-table tbody");
        if (body) body.innerHTML = "<tr><td colspan=\"5\">" + firstError(results[0].body) + "</td></tr>";
        return;
      }
      const invoices = results[0].body.data || [];
      const payments = results[1].ok ? (results[1].body.data || []) : [];
      if (!invoices.length) {
        const body = document.querySelector(".fee-table tbody");
        if (body) body.innerHTML = "<tr><td colspan=\"5\">No invoice has been raised for this pupil yet.</td></tr>";
        return;
      }
      renderParentInvoice(invoices[0], payments, child);
    });
  };

  const wireParentFees = function () {
    const selector = document.getElementById("studentSelect");
    const table = document.querySelector(".fee-table");
    if (!selector || !table) return;

    const pay = document.querySelector(".fee-actions .btn-primary");
    if (pay) {
      pay.addEventListener("click", function () {
        window.alert("Online card checkout is not available yet. Please pay at the school office.");
      });
    }
    const printBtn = document.querySelector(".fee-actions .btn-outline-secondary");
    if (printBtn) {
      printBtn.addEventListener("click", function () {
        window.print();
      });
    }
    document.addEventListener("click", function (event) {
      const button = event.target.closest("[data-receipt]");
      if (!button) return;
      request("/api/v1/payments/" + button.getAttribute("data-receipt")).then(function (result) {
        if (!result.ok) {
          window.alert(firstError(result.body));
          return;
        }
        const row = result.body.data || {};
        const popup = window.open("", "receipt");
        if (!popup) return;
        popup.document.write("<pre>Supreme Reagan Schools\nReceipt " + (row.reference || "") + "\n"
          + (row.student_name || "") + "\n" + naira(row.amount_naira) + " · " + (row.channel || "") + "\n"
          + (row.paid_at || "") + "</pre>");
        popup.document.close();
        popup.print();
      });
    });

    request("/api/v1/me/children").then(function (result) {
      if (result.status === 403) {
        const wrap = document.querySelector(".student-selector");
        if (wrap) wrap.style.display = "none";
        loadParentFees(null, null);
        return;
      }
      if (!result.ok) return;
      const children = result.body.data || [];
      if (!children.length) {
        selector.innerHTML = "<option>No linked children</option>";
        return;
      }
      selector.innerHTML = children.map(function (child) {
        return '<option value="' + child.id + '">' + (child.full_name || "Child") + "</option>";
      }).join("");
      selector.addEventListener("change", function () {
        const child = children.filter(function (item) { return String(item.id) === String(selector.value); })[0];
        loadParentFees(selector.value, child);
      });
      loadParentFees(children[0].id, children[0]);
    });
  };

  if (document.body.getAttribute("data-page") === "fees") {
    wireFeeBook();
    wireAdminLedger();
    wireInvoiceLedger();
  }
  wireParentFees();
})();
