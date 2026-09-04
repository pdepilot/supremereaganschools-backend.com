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

  const escapeHtml = function (value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  };

  const mark = function (value) {
    if (!value) return "—";
    return String(value).replace(/[^A-Za-z0-9]/g, "").slice(0, 2).toUpperCase() || "—";
  };

  const setButtonState = function (button, busy, label) {
    if (!button) return;
    if (!button.dataset.label) button.dataset.label = button.textContent;
    button.disabled = !!busy;
    button.classList.toggle("is-busy", !!busy);
    if (label) button.textContent = label;
    else if (!busy) button.textContent = button.dataset.label;
  };

  const badge = function (status, accountStatus) {
    if (accountStatus === "suspended") {
      return '<span class="badge warn">Suspended</span>';
    }
    const ok = status === "active";
    const label = status === "on_leave" ? "On leave" : (status ? status.charAt(0).toUpperCase() + status.slice(1) : "—");
    return '<span class="badge ' + (ok ? "ok" : "warn") + '">' + label + "</span>";
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
    if (cancelBtn) cancelBtn.textContent = (options && options.cancelLabel) || "Keep them";
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

  const pathWing = function () {
    const match = window.location.pathname.match(/\/portal\/(nursery|primary|secondary)/);
    return match ? match[1] : "";
  };

  const feeCell = function (row) {
    if (!row.fee_state || row.fee_state === "none") {
      return row.fee_label || "No invoice";
    }
    if (row.fee_state === "paid") {
      return row.fee_status_label || "Paid in Full";
    }
    const status = row.fee_status_label || (row.fee_state === "partial" ? "Partially Paid" : "Outstanding");
    return row.fee_label ? status + " · " + row.fee_label : status;
  };

  const dash = function (value) {
    return value == null || String(value).trim() === "" ? "—" : String(value);
  };

  const prettyLabel = function (value) {
    if (!value) return "—";
    return String(value).replace(/_/g, " ").replace(/\b\w/g, function (letter) {
      return letter.toUpperCase();
    });
  };

  const formatDay = function (value) {
    if (!value) return "—";
    const date = new Date(String(value).length <= 10 ? value + "T12:00:00" : value);
    if (Number.isNaN(date.getTime())) return String(value);
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

  const metaCell = function (label, value) {
    return "<div><dt>" + escapeHtml(label) + "</dt><dd>" + escapeHtml(dash(value)) + "</dd></div>";
  };

  const primaryGuardian = function (row) {
    const list = (row && row.guardians) || [];
    return list.find(function (item) { return item && item.is_primary; }) || list[0] || null;
  };

  const schoolAddress = function (school) {
    if (!school) return "";
    return [school.address, school.city, school.state].filter(Boolean).join(", ");
  };

  const pupilRow = function (row) {
    const gender = row.gender
      ? row.gender.charAt(0).toUpperCase() + row.gender.slice(1)
      : "—";
    const suspended = row.account_status === "suspended";
    const action = suspended
      ? '<button class="ghost-btn" type="button" data-reinstate-pupil="' + row.id
        + '" data-name="' + escapeHtml(row.full_name || "this pupil") + '">Reinstate</button>'
      : '<button class="ghost-btn" type="button" data-suspend-pupil="' + row.id
        + '" data-name="' + escapeHtml(row.full_name || "this pupil") + '">Suspend</button>';
    const portrait = row.photo_url
      ? '<span class="mark has-photo"><img src="' + escapeHtml(row.photo_url) + '" alt=""></span>'
      : '<span class="mark">' + escapeHtml(mark(row.full_name)) + "</span>";
    return "<tr>"
      + '<td><div class="person">' + portrait + "<div><strong>"
      + escapeHtml(row.full_name || "—") + "</strong><small>"
      + escapeHtml(gender) + "</small></div></div></td>"
      + "<td>" + escapeHtml(row.admission_number || "—") + "</td>"
      + "<td>" + escapeHtml(row.current_form || "—") + "</td>"
      + "<td>" + escapeHtml(row.primary_guardian || "—") + "</td>"
      + "<td>" + escapeHtml(feeCell(row)) + "</td>"
      + "<td>" + badge(row.status, row.account_status) + "</td>"
      + '<td><div class="row-actions">'
      + '<button class="ghost-btn" type="button" data-view-pupil="' + row.id + '">View</button>'
      + (document.querySelector("[data-pupil-form]")
        ? '<button class="ghost-btn" type="button" data-edit-pupil="' + row.id + '">Revise</button>'
        : "")
      + action
      + '<button class="ghost-btn" type="button" data-delete-pupil="' + row.id
      + '" data-name="' + escapeHtml(row.full_name || "this pupil") + '">Remove</button></div></td>'
      + "</tr>";
  };

  const fillPupilTable = function (node, visible, emptyCopy) {
    if (!node) return;
    if (!visible.length) {
      node.innerHTML = "<tr><td colspan=\"7\">" + escapeHtml(emptyCopy) + "</td></tr>";
      return;
    }
    node.innerHTML = visible.map(pupilRow).join("");
  };

  const wirePupils = function () {
    const splitTables = {
      nursery: document.querySelector('[data-pupil-table="nursery"]'),
      primary: document.querySelector('[data-pupil-table="primary"]'),
      secondary: document.querySelector('[data-pupil-table="secondary"]'),
      unplaced: document.querySelector('[data-pupil-table="unplaced"]')
    };
    const table = splitTables.nursery || document.querySelector("[data-pupil-table]");
    if (!table) return;

    const search = document.querySelector("[data-pupil-search]");
    const feeFilter = document.querySelector("[data-pupil-fees]");
    const statusFilter = document.querySelector("[data-pupil-status]");
    const pager = document.querySelector("[data-pupil-pager]");
    const copy = document.querySelector("[data-roll-copy]");
    const form = document.querySelector("[data-pupil-form]");
    const notice = document.querySelector("[data-pupil-notice]");
    const rollNotice = document.querySelector("[data-roll-notice]");
    const formSelect = document.getElementById("pupilForm");
    const rollRoot = document.querySelector("[data-roll-root]") || table;
    const unplacedWrap = document.querySelector("[data-unplaced-wrap]");
    const formTitle = document.querySelector("[data-pupil-form-title]");
    const formCopy = document.querySelector("[data-pupil-form-copy]");
    const cancelEdit = document.querySelector("[data-cancel-pupil-edit]");
    const photoInput = document.getElementById("pupilPhoto");
    const photoPreview = document.querySelector("[data-pupil-photo-preview]");
    const photoEmpty = document.querySelector("[data-pupil-photo-empty]");
    const camera = document.querySelector("[data-pupil-camera]");
    const pickPhotoBtn = document.querySelector("[data-pick-photo]");
    const openCameraBtn = document.querySelector("[data-open-camera]");
    const snapPhotoBtn = document.querySelector("[data-snap-photo]");
    const cancelCameraBtn = document.querySelector("[data-cancel-camera]");
    const clearPhotoBtn = document.querySelector("[data-clear-photo]");
    const wing = pathWing();
    let rows = [];
    let offerings = [];
    let school = {};
    let viewing = null;
    let editingId = null;
    let photoFile = null;
    let photoObjectUrl = null;
    let existingPhotoUrl = null;
    let cameraStream = null;

    const syncPhotoActions = function () {
      if (clearPhotoBtn) clearPhotoBtn.hidden = !(photoFile || existingPhotoUrl) || !!(camera && !camera.hidden);
    };

    const fieldValue = function (id, value) {
      const node = document.getElementById(id);
      if (node) node.value = value == null ? "" : String(value);
    };

    const limitDob = function () {
      const dob = document.getElementById("pupilDob");
      if (!dob) return;
      const cap = new Date();
      cap.setDate(cap.getDate() - 1);
      dob.max = cap.getFullYear() + "-" + String(cap.getMonth() + 1).padStart(2, "0") + "-" + String(cap.getDate()).padStart(2, "0");
    };

    const stopCamera = function () {
      if (cameraStream) {
        cameraStream.getTracks().forEach(function (track) { track.stop(); });
        cameraStream = null;
      }
      if (camera) {
        camera.hidden = true;
        camera.srcObject = null;
      }
      if (openCameraBtn) openCameraBtn.hidden = false;
      if (pickPhotoBtn) pickPhotoBtn.hidden = false;
      if (snapPhotoBtn) snapPhotoBtn.hidden = true;
      if (cancelCameraBtn) cancelCameraBtn.hidden = true;
      syncPhotoActions();
    };

    const showPhotoPreview = function (src) {
      if (photoObjectUrl) {
        URL.revokeObjectURL(photoObjectUrl);
        photoObjectUrl = null;
      }
      if (src && String(src).indexOf("blob:") === 0) photoObjectUrl = src;
      if (photoPreview) {
        photoPreview.hidden = !src;
        photoPreview.src = src || "";
      }
      if (photoEmpty) photoEmpty.hidden = !!src;
      if (camera) camera.hidden = true;
      syncPhotoActions();
    };

    const setPhotoFile = function (file) {
      photoFile = file || null;
      if (photoInput && !file) photoInput.value = "";
      if (file) showPhotoPreview(URL.createObjectURL(file));
      else showPhotoPreview(existingPhotoUrl);
    };

    const pickGuardian = function (row) {
      const list = (row && row.guardians) || [];
      return list.find(function (item) { return item && item.is_primary; }) || list[0] || null;
    };

    const collectGuardian = function () {
      const fullName = ((document.getElementById("pupilGuardianName") || {}).value || "").trim();
      if (!fullName) return null;
      const guardian = {
        full_name: fullName,
        relationship: (document.getElementById("pupilGuardianRelation") || {}).value || "guardian",
        phone: ((document.getElementById("pupilGuardianPhone") || {}).value || "").trim(),
        email: ((document.getElementById("pupilGuardianEmail") || {}).value || "").trim(),
        occupation: ((document.getElementById("pupilGuardianOccupation") || {}).value || "").trim(),
        address: ((document.getElementById("pupilGuardianAddress") || {}).value || "").trim(),
        alternate_phone: ((document.getElementById("pupilGuardianAltPhone") || {}).value || "").trim(),
        password: (document.getElementById("pupilGuardianPassword") || {}).value || ""
      };
      if (!guardian.phone) delete guardian.phone;
      if (!guardian.email) delete guardian.email;
      if (!guardian.occupation) delete guardian.occupation;
      if (!guardian.address) delete guardian.address;
      if (!guardian.alternate_phone) delete guardian.alternate_phone;
      if (!guardian.password) delete guardian.password;
      return guardian;
    };

    const setEditMode = function (row) {
      editingId = row ? row.id : null;
      existingPhotoUrl = row && row.photo_url ? row.photo_url : null;
      const guardianPassword = document.getElementById("pupilGuardianPassword");
      if (guardianPassword) guardianPassword.value = "";
      const pupilPassword = document.getElementById("pupilPassword");
      if (pupilPassword) pupilPassword.value = "";
      if (cancelEdit) cancelEdit.hidden = !editingId;
      if (formTitle) formTitle.textContent = editingId ? "Revise this pupil" : "Register a pupil";
      if (formCopy) {
        formCopy.textContent = editingId
          ? "Update the pupil, photograph, and the parent or guardian already on the books. Set a pupil passphrase to retire the parent telephone as the sign-in key."
          : "Seal a new admission into the current session, with a photograph and a parent or guardian on the books. Give the pupil a passphrase; until then the parent telephone still opens the pupil desk.";
      }
      const submit = form && form.querySelector("[data-pupil-submit]");
      if (submit) {
        submit.dataset.label = editingId ? "Save the revision" : "Seal the admission";
        if (!submit.classList.contains("is-busy")) submit.textContent = submit.dataset.label;
      }
    };

    const resetForm = function () {
      stopCamera();
      existingPhotoUrl = null;
      setPhotoFile(null);
      if (form) form.reset();
      fieldValue("pupilStatus", "active");
      fieldValue("pupilNationality", "Nigerian");
      limitDob();
      setEditMode(null);
      showPhotoPreview(null);
    };

    const fillPupilForm = function (row) {
      if (!row) return;
      stopCamera();
      fieldValue("pupilSurname", row.surname);
      fieldValue("pupilFirstName", row.first_name);
      fieldValue("pupilOtherNames", row.other_names);
      fieldValue("pupilGender", row.gender);
      fieldValue("pupilDob", row.date_of_birth);
      fieldValue("pupilStatus", row.status || "active");
      fieldValue("pupilAdmittedOn", row.admitted_on);
      fieldValue("pupilNationality", row.nationality || "Nigerian");
      fieldValue("pupilState", row.state_of_origin);
      fieldValue("pupilLga", row.lga);
      fieldValue("pupilAddress", row.home_address);
      fieldValue("pupilPreviousSchool", row.previous_school);
      fieldValue("pupilAdmission", row.admission_number);
      fieldValue("pupilBloodGroup", row.blood_group);
      fieldValue("pupilGenotype", row.genotype);
      fieldValue("pupilMedical", row.medical_notes);
      fieldValue("pupilInterests", row.interests);
      if (formSelect) {
        const offeringId = row.class_section_offering_id ? String(row.class_section_offering_id) : "";
        const live = offerings.find(function (item) { return String(item.id) === offeringId; });
        if (offeringId && !Array.prototype.some.call(formSelect.options, function (opt) { return opt.value === offeringId; })) {
          const option = document.createElement("option");
          option.value = offeringId;
          option.textContent = (live && live.form) || row.current_form || "Current form";
          option.setAttribute("data-section", live ? live.class_section_id : "");
          option.setAttribute("data-session", live ? live.academic_session_id : ((row.enrollments || [])[0] || {}).academic_session_id || "");
          formSelect.appendChild(option);
        }
        formSelect.value = offeringId;
      }
      const guardian = pickGuardian(row);
      fieldValue("pupilGuardianName", guardian && guardian.full_name);
      fieldValue("pupilGuardianRelation", guardian && guardian.relationship);
      fieldValue("pupilGuardianPhone", guardian && guardian.phone);
      fieldValue("pupilGuardianEmail", guardian && guardian.email);
      fieldValue("pupilGuardianAltPhone", guardian && guardian.alternate_phone);
      fieldValue("pupilGuardianOccupation", guardian && guardian.occupation);
      fieldValue("pupilGuardianAddress", guardian && guardian.address);
      setEditMode(row);
      setPhotoFile(null);
    };

    const beginEdit = function (id) {
      const row = rows.find(function (item) { return String(item.id) === String(id); });
      if (!row) return;
      fillPupilForm(row);
      const panel = document.getElementById("register-pupil");
      if (panel && typeof panel.scrollIntoView === "function") {
        panel.scrollIntoView({ behavior: "smooth", block: "start" });
      }
      window.location.hash = "register-pupil";
    };

    const viewRoot = document.querySelector("[data-pupil-view]");
    const viewHead = document.querySelector("[data-pupil-view-head]");
    const viewBody = document.querySelector("[data-pupil-view-body]");
    const viewNotice = document.querySelector("[data-pupil-view-notice]");
    const printSheet = document.querySelector("[data-pupil-print-sheet]");
    const reviseFromView = document.querySelector("[data-revise-from-view]");

    const closeView = function () {
      viewing = null;
      if (viewRoot) viewRoot.hidden = true;
      document.body.classList.remove("desk-alert-open");
    };

    const openView = function () {
      if (!viewRoot) return;
      viewRoot.hidden = false;
      document.body.classList.add("desk-alert-open");
    };

    const letterhead = function () {
      const logo = school.logo_path || "/site/Image/logo_main.png";
      const place = schoolAddress(school);
      return '<header class="pupil-print-head"><img src="' + escapeHtml(logo) + '" alt="">'
        + "<div><p class=\"eyebrow\">" + escapeHtml(school.short_name || "SRS") + "</p>"
        + "<h1>" + escapeHtml(school.name || "Supreme Reagan Schools") + "</h1>"
        + (school.motto ? "<p class=\"pupil-print-motto\">" + escapeHtml(school.motto) + "</p>" : "")
        + (place ? "<p>" + escapeHtml(place) + "</p>" : "")
        + "</div></header>";
    };

    const paintView = function (row) {
      viewing = row;
      const photo = row.photo_url
        ? '<img src="' + escapeHtml(row.photo_url) + '" alt="">'
        : '<span class="mark">' + escapeHtml(mark(row.full_name)) + "</span>";
      if (viewHead) {
        viewHead.innerHTML = '<div class="pupil-view-hero">' + photo
          + '<div><h2 id="pupilViewTitle">' + escapeHtml(row.full_name || "Pupil") + "</h2>"
          + "<p>" + escapeHtml([row.admission_number, row.current_form || "Unplaced", row.session_name].filter(Boolean).join(" · ")) + "</p>"
          + badge(row.status, row.account_status)
          + "</div></div>";
      }
      const guardians = (row.guardians || []).map(function (item) {
        return '<article class="pupil-view-guardian"><strong>' + escapeHtml(item.full_name || "Guardian")
          + "</strong><p>" + escapeHtml([prettyLabel(item.relationship), item.phone, item.alternate_phone, item.email, item.occupation].filter(function (part) {
            return part && part !== "—";
          }).join(" · "))
          + "</p>" + (item.address ? "<p>" + escapeHtml(item.address) + "</p>" : "")
          + "</article>";
      }).join("") || "<p>No parent or guardian is sealed on this record.</p>";
      if (viewBody) {
        viewBody.innerHTML = '<dl class="letter-meta pupil-view-meta">'
          + metaCell("Admission", row.admission_number)
          + metaCell("Gender", prettyLabel(row.gender))
          + metaCell("Date of birth", formatDay(row.date_of_birth))
          + metaCell("Admitted", formatDay(row.admitted_on))
          + metaCell("Form", row.current_form || "Unplaced")
          + metaCell("Wing", prettyLabel(row.level_name || row.wing))
          + metaCell("Campus", row.campus_name)
          + metaCell("Session", row.session_name)
          + metaCell("Fees", row.fee_status_label || row.fee_label)
          + metaCell("Nationality", row.nationality)
          + metaCell("State of origin", row.state_of_origin)
          + metaCell("L.G.A.", row.lga)
          + "</dl>"
          + '<p class="pupil-view-address"><strong>Home</strong> ' + escapeHtml(dash(row.home_address)) + "</p>"
          + '<p class="pupil-view-address"><strong>Previous school</strong> ' + escapeHtml(dash(row.previous_school)) + "</p>"
          + "<h3>Parent or guardian</h3>" + guardians
          + "<h3>Health</h3><dl class=\"letter-meta pupil-view-meta\">"
          + metaCell("Blood group", row.blood_group)
          + metaCell("Genotype", row.genotype)
          + metaCell("Interests", row.interests)
          + "</dl>"
          + (row.medical_notes ? "<p class=\"pupil-view-notes\">" + escapeHtml(row.medical_notes) + "</p>" : "");
      }
      if (viewNotice) viewNotice.textContent = "";
      if (reviseFromView) reviseFromView.hidden = !form;
      openView();
    };

    const showPupil = function (id) {
      if (!id || !viewRoot) return;
      if (viewNotice) viewNotice.textContent = "Opening the record…";
      openView();
      request("/api/v1/students/" + id).then(function (result) {
        if (!result.ok || !result.body || !result.body.data) {
          if (viewNotice) viewNotice.textContent = firstError(result.body);
          return;
        }
        paintView(result.body.data);
      }).catch(function () {
        if (viewNotice) viewNotice.textContent = "The office could not open that record.";
      });
    };

    const runPrint = function (html) {
      if (!printSheet) {
        window.print();
        return;
      }
      printSheet.innerHTML = html;
      printSheet.hidden = false;
      document.body.classList.add("is-printing-pupil");
      let finished = false;
      const done = function () {
        if (finished) return;
        finished = true;
        document.body.classList.remove("is-printing-pupil");
        printSheet.hidden = true;
        window.removeEventListener("afterprint", done);
      };
      window.addEventListener("afterprint", done);
      window.print();
      window.setTimeout(done, 1200);
    };

    const printRecord = function () {
      if (!viewing) return;
      const guardian = primaryGuardian(viewing);
      runPrint(letterhead()
        + '<p class="pupil-print-kicker">Pupil record</p>'
        + "<h2>" + escapeHtml(viewing.full_name || "Pupil") + "</h2>"
        + '<dl class="letter-meta pupil-view-meta">'
        + metaCell("Admission", viewing.admission_number)
        + metaCell("Gender", prettyLabel(viewing.gender))
        + metaCell("Date of birth", formatDay(viewing.date_of_birth))
        + metaCell("Admitted", formatDay(viewing.admitted_on))
        + metaCell("Form", viewing.current_form || "Unplaced")
        + metaCell("Session", viewing.session_name)
        + metaCell("Campus", viewing.campus_name)
        + metaCell("Fees", viewing.fee_status_label || viewing.fee_label)
        + metaCell("Parent", guardian && guardian.full_name)
        + metaCell("Parent phone", guardian && guardian.phone)
        + metaCell("Home", viewing.home_address)
        + metaCell("Previous school", viewing.previous_school)
        + "</dl>"
        + "<p class=\"pupil-print-foot\">Drawn " + escapeHtml(todayLagos()) + " · " + escapeHtml(school.name || "Supreme Reagan Schools") + "</p>");
    };

    const printAdmission = function () {
      if (!viewing) return;
      const guardian = primaryGuardian(viewing);
      const parent = guardian && guardian.full_name ? guardian.full_name : "Parent/Guardian";
      const formName = viewing.current_form || "the class assigned by the office";
      const sessionName = viewing.session_name || "the current academic session";
      runPrint(letterhead()
        + '<p class="pupil-print-kicker">Admission letter</p>'
        + "<p>" + escapeHtml(todayLagos()) + "</p>"
        + "<p>Dear " + escapeHtml(parent) + ",</p>"
        + "<p>We write to confirm that <strong>" + escapeHtml(viewing.full_name || "your child")
        + "</strong> has been admitted to " + escapeHtml(school.name || "Supreme Reagan Schools")
        + " for " + escapeHtml(sessionName) + ".</p>"
        + '<dl class="letter-meta pupil-view-meta">'
        + metaCell("Admission number", viewing.admission_number)
        + metaCell("Class", formName)
        + metaCell("Date of admission", formatDay(viewing.admitted_on))
        + metaCell("Campus", viewing.campus_name)
        + "</dl>"
        + "<p>The pupil may sign in at the pupil desk with their name or admission number and the passphrase the office issued. Until a passphrase is set, the parent’s registered phone number on this record still opens that desk"
        + (guardian && guardian.phone ? " (" + escapeHtml(guardian.phone) + ")" : "")
        + ".</p>"
        + "<p>With the compliments of the office,</p>"
        + "<p><strong>" + escapeHtml(school.name || "Supreme Reagan Schools") + "</strong><br>The school office</p>");
    };

    if (viewRoot) {
      viewRoot.addEventListener("click", function (event) {
        if (event.target.closest("[data-pupil-view-dismiss]")) {
          closeView();
          return;
        }
        if (event.target.closest("[data-revise-from-view]")) {
          const id = viewing && viewing.id;
          closeView();
          if (id) beginEdit(id);
          return;
        }
        if (event.target.closest("[data-print-pupil]")) {
          printRecord();
          return;
        }
        if (event.target.closest("[data-print-admission]")) {
          printAdmission();
        }
      });
      document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" && viewRoot && !viewRoot.hidden) closeView();
      });
    }

    const setMetricValue = function (key, value) {
      const node = document.querySelector('[data-metric="' + key + '"]');
      if (node) node.textContent = value;
    };

    const setMetricDelta = function (key, value) {
      const node = document.querySelector('[data-metric-delta="' + key + '"]');
      if (node && value) node.textContent = value;
    };

    const markRail = function () {
      if (!wing) return;
      document.querySelectorAll(".rail-nav .rail-btn").forEach(function (btn) {
        const href = btn.getAttribute("href") || "";
        const live = href.indexOf(wing + ".html") !== -1 || href.indexOf("/portal/" + wing) !== -1;
        btn.classList.toggle("active", live);
        if (live) btn.setAttribute("aria-current", "page");
        else btn.removeAttribute("aria-current");
      });
    };

    const matchesFilters = function (row) {
      const query = ((search && search.value) || "").trim().toLowerCase();
      const status = statusFilter && statusFilter.value;
      const feeState = feeFilter && feeFilter.value;
      const hay = ((row.full_name || "") + " " + (row.admission_number || "")).toLowerCase();
      if (query && hay.indexOf(query) === -1) return false;
      if (status === "suspended") {
        if (row.account_status !== "suspended") return false;
      } else if (status && row.status !== status) return false;
      if (feeState && (row.fee_state || "none") !== feeState) return false;
      return true;
    };

    const apply = function () {
      const visible = rows.filter(matchesFilters);
      if (splitTables.nursery) {
        fillPupilTable(splitTables.nursery, visible.filter(function (row) { return row.wing === "nursery"; }), "No nursery pupils on the roll.");
        fillPupilTable(splitTables.primary, visible.filter(function (row) { return row.wing === "primary"; }), "No primary pupils on the roll.");
        fillPupilTable(splitTables.secondary, visible.filter(function (row) { return row.wing === "secondary"; }), "No secondary pupils on the roll.");
        const unplaced = visible.filter(function (row) { return !row.wing; });
        fillPupilTable(splitTables.unplaced, unplaced, "No unplaced pupils.");
        if (unplacedWrap) unplacedWrap.hidden = unplaced.length === 0;
      } else {
        fillPupilTable(table, visible, "No pupils on this desk yet.");
      }

      if (pager) pager.textContent = "Showing " + visible.length + " of " + rows.length;
      if (!wing) {
        setMetricValue("on-roll", String(rows.length));
        setMetricValue("active", String(rows.filter(function (row) { return row.status === "active"; }).length));
        setMetricValue("pending", String(rows.filter(function (row) { return row.status === "pending"; }).length));
        setMetricValue("inactive", String(rows.filter(function (row) {
          return row.status === "inactive" || row.status === "withdrawn" || row.status === "graduated";
        }).length));
      }
    };

    const fillOfferings = function (sessionId) {
      if (!formSelect) return;
      const live = offerings.filter(function (row) {
        if (!row.is_active) return false;
        if (sessionId && row.academic_session_id !== sessionId) return false;
        return true;
      });
      const list = live.length ? live : offerings.filter(function (row) { return row.is_active; });
      formSelect.innerHTML = '<option value="">No form yet</option>' + list.map(function (row) {
        const label = (row.form || "Form") + (row.campus && row.campus.name ? " · " + row.campus.name : "");
        return '<option value="' + row.id + '" data-section="' + row.class_section_id
          + '" data-session="' + row.academic_session_id + '">' + escapeHtml(label) + "</option>";
      }).join("");
    };

    const loadWingDesk = function () {
      if (!wing) return Promise.resolve();
      markRail();
      return request("/api/v1/level-desks/" + encodeURIComponent(wing)).then(function (result) {
        if (!result.ok) return;
        const data = result.body.data || {};
        const metrics = data.metrics || {};
        if (data.name) {
          document.title = data.name + " | Supreme Reagan Schools";
          const title = document.querySelector("[data-wing-title]");
          const tableTitle = document.querySelector("[data-wing-table-title]");
          if (title) title.textContent = data.name + " desk";
          if (tableTitle) tableTitle.textContent = data.name + " roll";
        }
        if (data.copy) {
          const intro = document.querySelector("[data-wing-copy]");
          if (intro) intro.textContent = data.copy;
        }
        setMetricValue("pupils", metrics.pupils == null ? "—" : String(metrics.pupils));
        setMetricDelta("pupils", metrics.pupils_delta);
        setMetricValue("forms", metrics.forms == null ? "—" : String(metrics.forms));
        setMetricDelta("forms", metrics.forms_delta);
        if (metrics.attendance_percent == null) setMetricValue("attendance", "—");
        else setMetricValue("attendance", metrics.attendance_percent + "%");
        setMetricDelta("attendance", metrics.attendance_delta);
        setMetricValue("outstanding", metrics.outstanding || "—");
        setMetricDelta("outstanding", metrics.outstanding_delta);
        if (copy) {
          copy.textContent = [data.session, data.term].filter(Boolean).join(" · ") || "No session sealed yet";
        }
      });
    };

    const load = function () {
      const query = wing ? "?wing=" + encodeURIComponent(wing) : "";
      return Promise.all([
        request("/api/v1/students" + query),
        request("/api/v1/school-settings"),
        request("/api/v1/class-section-offerings"),
        request("/api/v1/campuses"),
        loadWingDesk()
      ]).then(function (results) {
        if (!results[0].ok) {
          fillPupilTable(table, [], firstError(results[0].body));
          if (splitTables.nursery) {
            fillPupilTable(splitTables.primary, [], firstError(results[0].body));
            fillPupilTable(splitTables.secondary, [], firstError(results[0].body));
          }
          return;
        }

        rows = results[0].body.data || [];
        school = (results[1].ok && results[1].body.data) || {};
        offerings = (results[2].ok && results[2].body.data) || [];
        const campuses = (results[3].ok && results[3].body.data) || [];
        const campus = campuses.find(function (row) { return row.is_active; }) || campuses[0];
        const session = school.current_academic_session || {};
        const sessionName = session.name || "";
        const campusName = campus && campus.name ? campus.name : "";

        if (copy && !wing) {
          copy.textContent = [sessionName, campusName].filter(Boolean).join(" · ") || "No session sealed yet";
        }
        if (school.name && !wing) {
          document.title = "Pupils | " + school.name;
        }
        fillOfferings(school.current_academic_session_id || null);
        apply();
      });
    };

    [search, feeFilter, statusFilter].forEach(function (node) {
      if (!node) return;
      node.addEventListener("input", apply);
      node.addEventListener("change", apply);
    });

    rollRoot.addEventListener("click", function (event) {
      const viewBtn = event.target.closest("[data-view-pupil]");
      if (viewBtn) {
        showPupil(viewBtn.getAttribute("data-view-pupil"));
        return;
      }

      const editBtn = event.target.closest("[data-edit-pupil]");
      if (editBtn) {
        beginEdit(editBtn.getAttribute("data-edit-pupil"));
        return;
      }

      const button = event.target.closest("[data-delete-pupil], [data-suspend-pupil], [data-reinstate-pupil]");
      if (!button) return;
      const name = button.getAttribute("data-name") || "this pupil";
      const id = button.getAttribute("data-delete-pupil")
        || button.getAttribute("data-suspend-pupil")
        || button.getAttribute("data-reinstate-pupil");
      if (!id) return;

      let path = "/api/v1/students/" + id;
      let method = "DELETE";
      let alertOptions = {
        title: "Remove from the roll",
        copy: name + " will leave the live roll. Enrollment and fee history remain sealed on the ledger.",
        confirmLabel: "Remove pupil",
        cancelLabel: "Keep them",
        danger: true
      };
      if (button.hasAttribute("data-suspend-pupil")) {
        path += "/suspend";
        method = "POST";
        alertOptions = {
          title: "Suspend this desk",
          copy: name + " will not be able to sign in. They remain on their form until reinstated.",
          confirmLabel: "Suspend pupil",
          cancelLabel: "Leave them active",
          danger: true
        };
      } else if (button.hasAttribute("data-reinstate-pupil")) {
        path += "/reinstate";
        method = "POST";
        alertOptions = {
          title: "Restore the desk",
          copy: name + " will return to the live roll and may sign in again.",
          confirmLabel: "Reinstate pupil",
          cancelLabel: "Leave them suspended",
          danger: false
        };
      }

      confirmDesk(alertOptions).then(function (ok) {
        if (!ok) return;
        if (rollNotice) rollNotice.textContent = "";
        button.disabled = true;
        request(path, { method: method }).then(function (result) {
          if (!result.ok) {
            const message = firstError(result.body);
            if (rollNotice) rollNotice.textContent = message;
            button.disabled = false;
            return;
          }
          return load();
        }).catch(function () {
          if (rollNotice) rollNotice.textContent = "Unable to reach the office.";
          button.disabled = false;
        });
      });
    });

    if (photoInput) {
      photoInput.addEventListener("change", function () {
        stopCamera();
        setPhotoFile(photoInput.files && photoInput.files[0] ? photoInput.files[0] : null);
      });
    }

    if (pickPhotoBtn && photoInput) {
      pickPhotoBtn.addEventListener("click", function () {
        stopCamera();
        photoInput.click();
      });
    }

    if (clearPhotoBtn) {
      clearPhotoBtn.addEventListener("click", function () {
        stopCamera();
        setPhotoFile(null);
        if (notice && !editingId && !existingPhotoUrl) {
          notice.textContent = "A pupil photograph is required before you can save.";
        } else if (notice) {
          notice.textContent = "";
        }
      });
    }

    if (openCameraBtn && camera) {
      openCameraBtn.addEventListener("click", function () {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
          if (notice) notice.textContent = "This browser cannot open a camera. Upload an image instead.";
          if (photoInput) photoInput.click();
          return;
        }
        if (notice) notice.textContent = "";
        navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: "environment" } }, audio: false }).then(function (stream) {
          cameraStream = stream;
          camera.srcObject = stream;
          camera.hidden = false;
          if (photoPreview) photoPreview.hidden = true;
          if (photoEmpty) photoEmpty.hidden = true;
          openCameraBtn.hidden = true;
          if (pickPhotoBtn) pickPhotoBtn.hidden = true;
          if (snapPhotoBtn) snapPhotoBtn.hidden = false;
          if (cancelCameraBtn) cancelCameraBtn.hidden = false;
          syncPhotoActions();
          return camera.play();
        }).catch(function () {
          return navigator.mediaDevices.getUserMedia({ video: true, audio: false }).then(function (stream) {
            cameraStream = stream;
            camera.srcObject = stream;
            camera.hidden = false;
            if (photoPreview) photoPreview.hidden = true;
            if (photoEmpty) photoEmpty.hidden = true;
            openCameraBtn.hidden = true;
            if (pickPhotoBtn) pickPhotoBtn.hidden = true;
            if (snapPhotoBtn) snapPhotoBtn.hidden = false;
            if (cancelCameraBtn) cancelCameraBtn.hidden = false;
            syncPhotoActions();
            return camera.play();
          });
        }).catch(function () {
          if (notice) notice.textContent = "The camera could not be opened. Upload an image instead.";
          if (photoInput) photoInput.click();
        });
      });
    }

    if (snapPhotoBtn && camera) {
      snapPhotoBtn.addEventListener("click", function () {
        const width = camera.videoWidth || 480;
        const height = camera.videoHeight || 640;
        const max = 720;
        const scale = width > max ? max / width : 1;
        const canvas = document.createElement("canvas");
        canvas.width = Math.max(1, Math.round(width * scale));
        canvas.height = Math.max(1, Math.round(height * scale));
        const context = canvas.getContext("2d");
        if (!context) return;
        context.drawImage(camera, 0, 0, canvas.width, canvas.height);
        canvas.toBlob(function (blob) {
          if (!blob) return;
          setPhotoFile(new File([blob], "capture.jpg", { type: blob.type || "image/jpeg" }));
          stopCamera();
        }, "image/jpeg", 0.86);
      });
    }

    if (cancelCameraBtn) {
      cancelCameraBtn.addEventListener("click", function () {
        stopCamera();
        if (photoFile) showPhotoPreview(URL.createObjectURL(photoFile));
        else showPhotoPreview(existingPhotoUrl);
      });
    }

    if (cancelEdit) {
      cancelEdit.addEventListener("click", function () {
        if (notice) notice.textContent = "";
        resetForm();
        if (window.location.hash === "#register-pupil") {
          history.replaceState(null, "", window.location.pathname + window.location.search);
        }
      });
    }

    if (form) {
      const button = form.querySelector("[data-pupil-submit]") || form.querySelector(".solid-btn");
      form.addEventListener("submit", function (event) {
        event.preventDefault();
        const revising = !!editingId;
        if (!photoFile && !revising) {
          if (notice) notice.textContent = "A pupil photograph is required. Upload a file or capture one with the camera.";
          return;
        }
        if (!photoFile && revising && !existingPhotoUrl) {
          if (notice) notice.textContent = "A pupil photograph is required. Upload a file or capture one with the camera.";
          return;
        }
        const option = formSelect && formSelect.options[formSelect.selectedIndex];
        const data = new FormData();
        data.append("surname", (document.getElementById("pupilSurname") || {}).value || "");
        data.append("first_name", (document.getElementById("pupilFirstName") || {}).value || "");
        const otherNames = (document.getElementById("pupilOtherNames") || {}).value || "";
        if (otherNames) data.append("other_names", otherNames);
        data.append("gender", (document.getElementById("pupilGender") || {}).value || "");
        data.append("status", (document.getElementById("pupilStatus") || {}).value || "active");
        const appendField = function (key, id) {
          const value = ((document.getElementById(id) || {}).value || "").trim();
          if (value) data.append(key, value);
        };
        appendField("date_of_birth", "pupilDob");
        appendField("admitted_on", "pupilAdmittedOn");
        appendField("nationality", "pupilNationality");
        appendField("state_of_origin", "pupilState");
        appendField("lga", "pupilLga");
        appendField("home_address", "pupilAddress");
        appendField("previous_school", "pupilPreviousSchool");
        appendField("blood_group", "pupilBloodGroup");
        appendField("genotype", "pupilGenotype");
        appendField("medical_notes", "pupilMedical");
        appendField("interests", "pupilInterests");
        appendField("password", "pupilPassword");
        const admission = (document.getElementById("pupilAdmission") || {}).value || "";
        if (admission) data.append("admission_number", admission);
        if (option && option.value) {
          const sectionId = option.getAttribute("data-section");
          const sessionId = option.getAttribute("data-session");
          if (sectionId) data.append("class_section_id", sectionId);
          if (sessionId) data.append("academic_session_id", sessionId);
        }
        const guardian = collectGuardian();
        if (guardian) {
          Object.keys(guardian).forEach(function (key) {
            data.append("guardian[" + key + "]", guardian[key]);
          });
        }
        if (photoFile) data.append("photo", photoFile, photoFile.name || "capture.jpg");
        if (notice) notice.textContent = "";
        const path = revising ? "/api/v1/students/" + editingId : "/api/v1/students";
        if (revising) data.append("_method", "PUT");
        const busyLabel = revising ? "Saving…" : "Sealing…";
        const doneLabel = revising ? "Saved." : "Sealed.";
        setButtonState(button, true, busyLabel);
        request(path, {
          method: "POST",
          body: data
        }).then(function (result) {
          if (!result.ok) {
            const message = firstError(result.body);
            if (notice) notice.textContent = message;
            setButtonState(button, false, message);
            window.setTimeout(function () { setButtonState(button, false); }, 2200);
            return;
          }
          resetForm();
          setButtonState(button, false, doneLabel);
          window.setTimeout(function () { setButtonState(button, false); }, 1600);
          return load();
        }).catch(function () {
          if (notice) notice.textContent = "Unable to reach the office.";
          setButtonState(button, false, "Unable to reach the office.");
          window.setTimeout(function () { setButtonState(button, false); }, 1800);
        });
      });
    }

    limitDob();
    load();
  };

  const staffRow = function (row) {
    const subjects = (row.subjects || []).filter(Boolean).join(", ") || "—";
    const forms = (row.forms || []).filter(Boolean).join(", ") || "—";
    const suspended = row.account_status === "suspended";
    const name = escapeHtml(row.name || "this master");
    const action = suspended
      ? '<button class="ghost-btn" type="button" data-reinstate-staff="' + row.id
        + '" data-name="' + name + '">Reinstate</button>'
      : '<button class="ghost-btn" type="button" data-suspend-staff="' + row.id
        + '" data-name="' + name + '">Suspend</button>';
    return "<tr>"
      + '<td><div class="person"><span class="mark">' + escapeHtml(mark(row.name)) + "</span><div><strong>"
      + escapeHtml(row.name || "—") + "</strong><small>"
      + escapeHtml(row.job_title || "") + "</small></div></div></td>"
      + "<td>" + escapeHtml(row.staff_number || "—") + "</td>"
      + "<td>" + escapeHtml(row.department || "—") + "</td>"
      + "<td>" + escapeHtml(subjects) + "</td>"
      + "<td>" + escapeHtml(forms) + "</td>"
      + "<td>" + badge(row.status, row.account_status) + "</td>"
      + '<td><div class="row-actions">'
      + (document.querySelector("[data-staff-form]")
        ? '<button class="ghost-btn" type="button" data-edit-staff="' + row.id + '">Revise</button>'
        : "")
      + action
      + '<button class="ghost-btn" type="button" data-delete-staff="' + row.id
      + '" data-name="' + name + '">Remove</button></div></td>'
      + "</tr>";
  };

  const wireStaff = function () {
    const table = document.querySelector("[data-staff-table]");
    if (!table) return;

    const search = document.querySelector("[data-staff-search]");
    const departmentFilter = document.querySelector("[data-staff-department]");
    const formFilter = document.querySelector("[data-staff-form-filter]");
    const statusFilter = document.querySelector("[data-staff-status]");
    const copy = document.querySelector("[data-staff-copy]");
    const notice = document.querySelector("[data-staff-notice]");
    const form = document.querySelector("[data-staff-form]");
    const formNotice = document.querySelector("[data-staff-form-notice]");
    const formTitle = document.querySelector("[data-staff-form-title]");
    const formCopy = document.querySelector("[data-staff-form-copy]");
    const formDepartment = document.getElementById("staffDepartment");
    const formOffering = document.getElementById("staffForm");
    const formRole = document.getElementById("staffRole");
    const passwordInput = document.getElementById("staffPassword");
    const passwordHint = document.querySelector("[data-staff-password-hint]");
    const cancelEdit = document.querySelector("[data-cancel-staff-edit]");
    const addDepartmentBtn = document.querySelector("[data-add-department]");
    const newDepartmentInput = document.querySelector("[data-new-department]");
    const departmentNotice = document.querySelector("[data-department-notice]");
    const formBlock = document.querySelector("[data-form-block]");
    const root = document.querySelector("[data-staff-root]") || table;
    const submit = form && form.querySelector("[data-staff-submit]");
    let rows = [];
    let departments = [];
    let offerings = [];
    let subjectCount = 0;
    let editingId = null;

    const fieldValue = function (id, value) {
      const node = document.getElementById(id);
      if (node) node.value = value == null ? "" : String(value);
    };

    const setMetricValue = function (key, value) {
      const node = document.querySelector('[data-metric="' + key + '"]');
      if (node) node.textContent = value;
    };

    const fillSelect = function (node, blank, list) {
      if (!node) return;
      const selected = node.value;
      node.innerHTML = '<option value="">' + blank + "</option>" + list.map(function (item) {
        return '<option value="' + escapeHtml(item.value) + '">' + escapeHtml(item.label) + "</option>";
      }).join("");
      if (selected) node.value = selected;
    };

    const departmentOptions = function () {
      return departments.map(function (department) {
        return { value: String(department.id), label: department.name };
      });
    };

    const offeringOptions = function () {
      return offerings.map(function (item) {
        const session = item.academic_session && item.academic_session.name
          ? " · " + item.academic_session.name
          : "";
        const teacher = item.class_teacher ? " · " + item.class_teacher : "";
        return {
          value: String(item.id),
          label: (item.form || "Form") + session + teacher
        };
      });
    };

    const paintDepartments = function (selectId) {
      fillSelect(departmentFilter, "All departments", departmentOptions());
      fillSelect(formDepartment, "No department yet", departmentOptions());
      if (selectId) fieldValue("staffDepartment", selectId);
    };

    const paintOfferings = function (selectId) {
      fillSelect(formOffering, "No form yet", offeringOptions());
      if (selectId) fieldValue("staffForm", selectId);
    };

    const canAssignForm = function () {
      const role = (formRole && formRole.value) || "teacher";
      return role !== "accountant";
    };

    const syncFormField = function () {
      const allowed = canAssignForm();
      if (formOffering) formOffering.disabled = !allowed;
      if (formBlock) formBlock.hidden = !allowed;
      if (!allowed) fieldValue("staffForm", "");
    };

    const setEditMode = function (row) {
      editingId = row ? row.id : null;
      if (passwordInput) passwordInput.required = !editingId;
      if (passwordHint) passwordHint.hidden = !editingId;
      if (cancelEdit) cancelEdit.hidden = !editingId;
      if (formTitle) formTitle.textContent = editingId ? "Revise this master" : "Appoint a master";
      if (formCopy) {
        formCopy.textContent = editingId
          ? "Update the department, form, and desk for this appointment."
          : "Seal a new staff account onto the live directory";
      }
      if (submit) {
        submit.dataset.label = editingId ? "Save the revision" : "Seal the appointment";
        if (!submit.classList.contains("is-busy")) submit.textContent = submit.dataset.label;
      }
      syncFormField();
    };

    const resetForm = function () {
      if (form) form.reset();
      fieldValue("staffRole", "teacher");
      if (newDepartmentInput) newDepartmentInput.value = "";
      if (departmentNotice) departmentNotice.textContent = "";
      setEditMode(null);
    };

    const fillStaffForm = function (row) {
      if (!row) return;
      fieldValue("staffName", row.name);
      fieldValue("staffEmail", row.email);
      fieldValue("staffRole", (row.roles && row.roles[0]) || "teacher");
      fieldValue("staffGender", row.gender);
      fieldValue("staffTitle", row.job_title);
      fieldValue("staffPhone", row.phone);
      fieldValue("staffNumber", row.staff_number);
      fieldValue("staffPassword", "");
      fieldValue("staffDepartment", row.department_id);
      const offeringId = row.class_section_offering_id ? String(row.class_section_offering_id) : "";
      if (offeringId && formOffering && !Array.prototype.some.call(formOffering.options, function (opt) {
        return opt.value === offeringId;
      })) {
        const live = offerings.find(function (item) { return String(item.id) === offeringId; });
        const option = document.createElement("option");
        option.value = offeringId;
        option.textContent = (live && live.form) || (row.forms && row.forms[0]) || "Current form";
        formOffering.appendChild(option);
      }
      fieldValue("staffForm", offeringId);
      setEditMode(row);
    };

    const beginEdit = function (id) {
      const row = rows.find(function (item) { return String(item.id) === String(id); });
      if (!row) return;
      fillStaffForm(row);
      const panel = document.getElementById("appoint-staff");
      if (panel && typeof panel.scrollIntoView === "function") {
        panel.scrollIntoView({ behavior: "smooth", block: "start" });
      }
    };

    const matchesFilters = function (row) {
      const query = ((search && search.value) || "").trim().toLowerCase();
      const departmentId = departmentFilter && departmentFilter.value;
      const formName = formFilter && formFilter.value;
      const status = statusFilter && statusFilter.value;
      const hay = [
        row.name, row.staff_number, row.job_title, row.department,
        (row.subjects || []).join(" "),
        (row.forms || []).join(" ")
      ].join(" ").toLowerCase();
      if (query && hay.indexOf(query) === -1) return false;
      if (departmentId && String(row.department_id) !== String(departmentId)) return false;
      if (formName && (row.forms || []).indexOf(formName) === -1) return false;
      if (status === "suspended") {
        if (row.account_status !== "suspended") return false;
      } else if (status && row.status !== status) return false;
      return true;
    };

    const apply = function () {
      const visible = rows.filter(matchesFilters);
      if (!visible.length) {
        table.innerHTML = "<tr><td colspan=\"7\">No staff appointments yet.</td></tr>";
      } else {
        table.innerHTML = visible.map(staffRow).join("");
      }

      setMetricValue("masters", String(rows.length));
      setMetricValue("active", String(rows.filter(function (row) {
        return row.status === "active" && row.account_status !== "suspended";
      }).length));
      setMetricValue("subjects", String(subjectCount));
      setMetricValue("departments", String(departments.length));
      if (copy) {
        copy.textContent = rows.length
          ? rows.length + " appointment" + (rows.length === 1 ? "" : "s") + " on the books"
          : "No appointments on the books yet";
      }
    };

    const load = function () {
      return Promise.all([
        request("/api/v1/staff"),
        request("/api/v1/departments"),
        request("/api/v1/subjects"),
        request("/api/v1/class-section-offerings?is_active=1")
      ]).then(function (results) {
        if (!results[0].ok) {
          table.innerHTML = "<tr><td colspan=\"7\">" + escapeHtml(firstError(results[0].body)) + "</td></tr>";
          return;
        }

        rows = results[0].body.data || [];
        departments = (results[1].ok && results[1].body.data) || [];
        subjectCount = ((results[2].ok && results[2].body.data) || []).length;
        offerings = ((results[3].ok && results[3].body.data) || []).filter(function (item) {
          return item.is_active !== false;
        });

        paintDepartments();
        paintOfferings();

        const forms = [];
        rows.forEach(function (row) {
          (row.forms || []).forEach(function (name) {
            if (name && forms.indexOf(name) === -1) forms.push(name);
          });
        });
        fillSelect(formFilter, "All forms", forms.map(function (name) {
          return { value: name, label: name };
        }));

        apply();
      });
    };

    [search, departmentFilter, formFilter, statusFilter].forEach(function (node) {
      if (!node) return;
      node.addEventListener("input", apply);
      node.addEventListener("change", apply);
    });

    if (formRole) formRole.addEventListener("change", syncFormField);

    if (addDepartmentBtn) {
      addDepartmentBtn.addEventListener("click", function () {
        const name = ((newDepartmentInput && newDepartmentInput.value) || "").trim();
        if (!name) {
          if (departmentNotice) departmentNotice.textContent = "Name the department first.";
          return;
        }
        if (departmentNotice) departmentNotice.textContent = "";
        setButtonState(addDepartmentBtn, true, "Adding…");
        request("/api/v1/departments", {
          method: "POST",
          body: JSON.stringify({ name: name })
        }).then(function (result) {
          if (!result.ok) {
            if (departmentNotice) departmentNotice.textContent = firstError(result.body);
            setButtonState(addDepartmentBtn, false);
            return;
          }
          const created = result.body.data || {};
          if (created.id) {
            departments.push(created);
            departments.sort(function (a, b) {
              return String(a.name).localeCompare(String(b.name));
            });
            paintDepartments(created.id);
          }
          if (newDepartmentInput) newDepartmentInput.value = "";
          setButtonState(addDepartmentBtn, false, "Added.");
          window.setTimeout(function () { setButtonState(addDepartmentBtn, false); }, 1400);
        }).catch(function () {
          if (departmentNotice) departmentNotice.textContent = "Unable to reach the office.";
          setButtonState(addDepartmentBtn, false);
        });
      });
    }

    root.addEventListener("click", function (event) {
      const editBtn = event.target.closest("[data-edit-staff]");
      if (editBtn) {
        beginEdit(editBtn.getAttribute("data-edit-staff"));
        return;
      }

      const button = event.target.closest("[data-delete-staff], [data-suspend-staff], [data-reinstate-staff]");
      if (!button) return;
      const name = button.getAttribute("data-name") || "this master";
      const id = button.getAttribute("data-delete-staff")
        || button.getAttribute("data-suspend-staff")
        || button.getAttribute("data-reinstate-staff");
      if (!id) return;

      let path = "/api/v1/staff/" + id;
      let method = "DELETE";
      let alertOptions = {
        title: "Remove from the books",
        copy: name + " will leave the live directory. Class and subject history remain sealed on the ledger.",
        confirmLabel: "Remove staff",
        cancelLabel: "Keep them",
        danger: true
      };
      if (button.hasAttribute("data-suspend-staff")) {
        path += "/suspend";
        method = "POST";
        alertOptions = {
          title: "Suspend this desk",
          copy: name + " will not be able to sign in. Assignments stay on the ledger until reinstated.",
          confirmLabel: "Suspend staff",
          cancelLabel: "Leave them active",
          danger: true
        };
      } else if (button.hasAttribute("data-reinstate-staff")) {
        path += "/reinstate";
        method = "POST";
        alertOptions = {
          title: "Restore the desk",
          copy: name + " will return to the live directory and may sign in again.",
          confirmLabel: "Reinstate staff",
          cancelLabel: "Leave them suspended",
          danger: false
        };
      }

      confirmDesk(alertOptions).then(function (ok) {
        if (!ok) return;
        if (notice) notice.textContent = "";
        button.disabled = true;
        request(path, { method: method }).then(function (result) {
          if (!result.ok) {
            const message = firstError(result.body);
            if (notice) notice.textContent = message;
            button.disabled = false;
            return;
          }
          return load();
        }).catch(function () {
          if (notice) notice.textContent = "Unable to reach the office.";
          button.disabled = false;
        });
      });
    });

    if (cancelEdit) {
      cancelEdit.addEventListener("click", function () {
        resetForm();
        paintDepartments();
        paintOfferings();
      });
    }

    if (form) {
      form.addEventListener("submit", function (event) {
        event.preventDefault();
        const payload = {
          name: (document.getElementById("staffName") || {}).value || "",
          email: (document.getElementById("staffEmail") || {}).value || "",
          password: (document.getElementById("staffPassword") || {}).value || "",
          role: (document.getElementById("staffRole") || {}).value || "teacher",
          gender: (document.getElementById("staffGender") || {}).value || "",
          job_title: (document.getElementById("staffTitle") || {}).value || "",
          phone: (document.getElementById("staffPhone") || {}).value || "",
          staff_number: (document.getElementById("staffNumber") || {}).value || "",
          department_id: (document.getElementById("staffDepartment") || {}).value || "",
          class_section_offering_id: (document.getElementById("staffForm") || {}).value || ""
        };
        if (!payload.gender) delete payload.gender;
        if (!payload.job_title) delete payload.job_title;
        if (!payload.phone) delete payload.phone;
        if (!payload.staff_number) delete payload.staff_number;
        if (payload.department_id) payload.department_id = Number(payload.department_id);
        else payload.department_id = null;
        payload.class_section_offering_id = payload.class_section_offering_id
          ? Number(payload.class_section_offering_id)
          : null;
        if (!payload.password) delete payload.password;

        if (formNotice) formNotice.textContent = "";
        const busyLabel = editingId ? "Saving…" : "Sealing…";
        const doneLabel = editingId ? "Saved." : "Sealed.";
        setButtonState(submit, true, busyLabel);
        request(editingId ? "/api/v1/staff/" + editingId : "/api/v1/staff", {
          method: editingId ? "PUT" : "POST",
          body: JSON.stringify(payload)
        }).then(function (result) {
          if (!result.ok) {
            const message = firstError(result.body);
            if (formNotice) formNotice.textContent = message;
            setButtonState(submit, false, message);
            window.setTimeout(function () { setButtonState(submit, false); }, 2200);
            return;
          }
          resetForm();
          paintDepartments();
          paintOfferings();
          setButtonState(submit, false, doneLabel);
          window.setTimeout(function () { setButtonState(submit, false); }, 1600);
          return load();
        }).catch(function () {
          if (formNotice) formNotice.textContent = "Unable to reach the office.";
          setButtonState(submit, false, "Unable to reach the office.");
          window.setTimeout(function () { setButtonState(submit, false); }, 1800);
        });
      });
    }

    setEditMode(null);
    load();
  };

  const wireChildren = function () {
    const grid = document.querySelector("[data-children-grid]");
    if (!grid) return;

    request("/api/v1/me/children").then(function (result) {
      if (!result.ok) {
        grid.innerHTML = "<p>" + firstError(result.body) + "</p>";
        return;
      }

      const rows = result.body.data || [];
      if (!rows.length) {
        grid.innerHTML = "<p>No children are linked to this account yet.</p>";
        return;
      }

      grid.innerHTML = rows.map(function (row) {
        const initials = mark(row.full_name);
        return '<div class="child-card">'
          + '<div class="child-card-top">'
          + '<div class="child-avatar">' + initials + "</div>"
          + '<div class="child-basic">'
          + "<h2>" + (row.full_name || "—") + "</h2>"
          + "<p><i class=\"bi bi-mortarboard-fill\"></i> " + (row.current_form || "—") + "</p>"
          + '<span class="student-id">Student ID: ' + (row.admission_number || "—") + "</span>"
          + "</div>"
          + '<span class="status-badge">' + (row.status || "—") + "</span>"
          + "</div></div>";
      }).join("");
    });
  };

  const wireAssignedStudents = function () {
    const table = document.querySelector("[data-assigned-students]");
    if (!table) return;

    request("/api/v1/me/students").then(function (result) {
      if (!result.ok) {
        table.innerHTML = "<tr><td colspan=\"8\">" + firstError(result.body) + "</td></tr>";
        return;
      }

      const rows = result.body.data || [];
      if (!rows.length) {
        table.innerHTML = "<tr><td colspan=\"8\">No pupils are assigned to you yet.</td></tr>";
        return;
      }

      table.innerHTML = rows.map(function (row) {
        return "<tr>"
          + "<td><input type=\"checkbox\"></td>"
          + "<td><div class=\"student-info\"><div class=\"student-avatar\">" + mark(row.full_name) + "</div><div><strong>"
          + (row.full_name || "—") + "</strong><span>" + (row.current_form || "") + "</span></div></div></td>"
          + "<td>" + (row.admission_number || "—") + "</td>"
          + "<td>" + ((row.gender || "").charAt(0).toUpperCase() + (row.gender || "").slice(1)) + "</td>"
          + "<td>—</td>"
          + "<td>—</td>"
          + "<td>" + badge(row.status) + "</td>"
          + "<td></td>"
          + "</tr>";
      }).join("");
    });
  };

  wirePupils();
  wireStaff();
  wireChildren();
  wireAssignedStudents();
})();
