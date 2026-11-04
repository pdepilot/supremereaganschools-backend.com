/**
 * Supreme Reagan Schools - Admin Staff Attendance Command Desk
 * Handles live roll monitoring, geofence configuration, multi-campus assignments,
 * verification logs audit, correction approvals, and CSV exports.
 */
(function () {
  'use strict';

  let currentTab = 'roll';
  let campusesList = [];
  let departmentsList = [];

  const elements = {
    // KPI counters
    kpiTotalStaff: document.getElementById('kpiTotalStaff'),
    kpiPresent: document.getElementById('kpiPresent'),
    kpiLate: document.getElementById('kpiLate'),
    kpiAbsent: document.getElementById('kpiAbsent'),
    kpiMissingOut: document.getElementById('kpiMissingOut'),
    kpiRejected: document.getElementById('kpiRejected'),

    // Tabs
    tabNavs: document.querySelectorAll('[data-tab-target]'),
    tabSections: document.querySelectorAll('[data-tab-section]'),

    // Tab 1: Live Roll
    filterDate: document.getElementById('filterDate'),
    filterCampus: document.getElementById('filterCampus'),
    filterStatus: document.getElementById('filterStatus'),
    filterSearch: document.getElementById('filterSearch'),
    btnApplyFilters: document.getElementById('btnApplyFilters'),
    btnExportCsv: document.getElementById('btnExportCsv'),
    btnOpenManualModal: document.getElementById('btnOpenManualModal'),
    rollTbody: document.getElementById('rollTbody'),
    rollEmpty: document.getElementById('rollEmpty'),

    // Tab 2: Geofences
    geofenceGrid: document.getElementById('geofenceGrid'),
    btnOpenGeofenceModal: document.getElementById('btnOpenGeofenceModal'),

    // Tab 3: Assignments
    assignmentsTbody: document.getElementById('assignmentsTbody'),
    assignmentsEmpty: document.getElementById('assignmentsEmpty'),
    btnOpenAssignModal: document.getElementById('btnOpenAssignModal'),
    assignmentCampusFilter: document.getElementById('assignmentCampusFilter'),

    // Tab 4: Logs
    logsTbody: document.getElementById('logsTbody'),
    logsEmpty: document.getElementById('logsEmpty'),
    logsFilterResult: document.getElementById('logsFilterResult'),

    // Tab 5: Adjustments
    adjustmentsTbody: document.getElementById('adjustmentsTbody'),
    adjustmentsEmpty: document.getElementById('adjustmentsEmpty'),

    // Modals
    modalGeofence: document.getElementById('modalGeofence'),
    formGeofence: document.getElementById('formGeofence'),
    modalAssign: document.getElementById('modalAssign'),
    formAssign: document.getElementById('formAssign'),
    modalManual: document.getElementById('modalManual'),
    formManual: document.getElementById('formManual'),
    modalReview: document.getElementById('modalReview'),
    formReview: document.getElementById('formReview'),

    // Toast
    toast: document.getElementById('adminToast'),
    toastText: document.getElementById('adminToastText'),
  };

  function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) return meta.getAttribute('content');
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
  }

  function showToast(message, type = 'success') {
    if (!elements.toast || !elements.toastText) {
      alert(message);
      return;
    }
    elements.toastText.textContent = message;
    elements.toast.className = 'desk-toast ' + (type === 'error' ? 'toast-error' : 'toast-success');
    elements.toast.hidden = false;
    setTimeout(() => {
      elements.toast.hidden = true;
    }, 4500);
  }

  async function api(url, options = {}) {
    const headers = {
      'Accept': 'application/json',
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': getCsrfToken(),
      'X-Requested-With': 'XMLHttpRequest',
      ...(options.headers || {}),
    };

    const res = await fetch(url, { ...options, headers });
    const json = await res.json().catch(() => null);

    if (!res.ok) {
      const msg = (json && (json.message || (json.errors && Object.values(json.errors).flat()[0])))
        || 'An error occurred. Please try again.';
      throw new Error(msg);
    }

    return json;
  }

  function getLagosDateString() {
    const parts = new Intl.DateTimeFormat('en-GB', {
      timeZone: 'Africa/Lagos',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
    }).formatToParts(new Date());

    const year = parts.find((part) => part.type === 'year').value;
    const month = parts.find((part) => part.type === 'month').value;
    const day = parts.find((part) => part.type === 'day').value;

    return `${year}-${month}-${day}`;
  }

  // Set default filter date to today (Lagos timezone)
  function initDefaultDate() {
    if (elements.filterDate) {
      const today = getLagosDateString();
      elements.filterDate.value = today;
    }
  }

  async function loadOverview() {
    try {
      const date = elements.filterDate ? elements.filterDate.value : '';
      const campusId = elements.filterCampus ? elements.filterCampus.value : '';
      const query = new URLSearchParams();
      if (date) query.set('date', date);
      if (campusId) query.set('campus_id', campusId);

      const res = await api(`/api/v1/admin/staff-attendance/overview?${query.toString()}`);
      const d = res.data;

      if (elements.kpiTotalStaff) elements.kpiTotalStaff.textContent = d.total_active_staff;
      if (elements.kpiPresent) elements.kpiPresent.textContent = d.present_count;
      if (elements.kpiLate) elements.kpiLate.textContent = d.late_count;
      if (elements.kpiAbsent) elements.kpiAbsent.textContent = d.absent_count;
      if (elements.kpiMissingOut) elements.kpiMissingOut.textContent = d.missing_clock_out_count;
      if (elements.kpiRejected) elements.kpiRejected.textContent = d.rejected_attempts + d.suspicious_attempts;
    } catch (err) {
      console.error('Failed to load attendance overview', err);
    }
  }

  async function loadCampuses() {
    try {
      const res = await api('/api/v1/campuses');
      campusesList = Array.isArray(res.data) ? res.data : (res.data?.items || []);

      // Populate filter dropdowns
      [elements.filterCampus, elements.assignmentCampusFilter, document.getElementById('manualCampusId'), document.getElementById('assignCampusId')].forEach((sel) => {
        if (!sel) return;
        const current = sel.value;
        const isFilter = sel.id.includes('Filter') || sel.id === 'filterCampus';
        sel.innerHTML = isFilter ? '<option value="">All Campuses</option>' : '<option value="">Choose a campus</option>';
        campusesList.forEach((c) => {
          const opt = document.createElement('option');
          opt.value = c.id;
          opt.textContent = c.name + (c.code ? ` (${c.code})` : '');
          if (c.id == current) opt.selected = true;
          sel.appendChild(opt);
        });
      });

      renderGeofenceCards();
    } catch (err) {
      console.error('Failed to load campuses', err);
    }
  }

  async function loadStaffMembers() {
    try {
      const res = await api('/api/v1/staff?per_page=100');
      const staff = Array.isArray(res.data) ? res.data : (res.data?.items || []);

      [document.getElementById('manualStaffId'), document.getElementById('assignStaffId')].forEach((sel) => {
        if (!sel) return;
        sel.innerHTML = '<option value="">Select staff member</option>';
        staff.forEach((s) => {
          const opt = document.createElement('option');
          opt.value = s.id;
          opt.textContent = `${s.name || s.user_name || 'Staff'} (${s.staff_number || 'No #'})`;
          sel.appendChild(opt);
        });
      });
    } catch (err) {
      console.error('Failed to load staff list for select', err);
    }
  }

  // TAB 1: Live Roll Records
  async function loadRecords() {
    if (!elements.rollTbody) return;

    try {
      const params = new URLSearchParams();
      if (elements.filterDate && elements.filterDate.value) params.set('date', elements.filterDate.value);
      if (elements.filterCampus && elements.filterCampus.value) params.set('campus_id', elements.filterCampus.value);
      if (elements.filterStatus && elements.filterStatus.value) params.set('status', elements.filterStatus.value);
      if (elements.filterSearch && elements.filterSearch.value.trim()) params.set('search', elements.filterSearch.value.trim());

      const res = await api(`/api/v1/admin/staff-attendance/records?${params.toString()}`);
      const records = Array.isArray(res.data) ? res.data : (res.data?.items || []);

      elements.rollTbody.innerHTML = '';
      if (records.length === 0) {
        if (elements.rollEmpty) elements.rollEmpty.hidden = false;
        return;
      }
      if (elements.rollEmpty) elements.rollEmpty.hidden = true;

      records.forEach((r) => {
        const tr = document.createElement('tr');
        const flagHtml = r.is_flagged
          ? `<span class="status-pill pill-danger" title="${r.flag_reason || 'Suspicious reading'}">Alert</span>`
          : '<span style="color:#94a3b8;">Normal</span>';

        tr.innerHTML = `
          <td><strong>${r.date}</strong></td>
          <td>
            <strong>${r.staff_name || 'Staff'}</strong>
            <div style="font-size:0.75rem; color:#64748b;">${r.staff_number || ''} · ${r.department_name || 'General'}</div>
          </td>
          <td>
            <div>${r.campus_name || '—'}</div>
            ${r.clock_out_campus_name && r.clock_out_campus_name !== r.campus_name ? `<div style="font-size:0.75rem; color:#4338ca; font-weight:600;">↳ Out: ${r.clock_out_campus_name}</div>` : ''}
          </td>
          <td>
            <div>${r.clock_in_formatted || '<span style="color:#94a3b8;">Not In</span>'}</div>
            ${r.clock_in_distance_meters !== null ? `<div style="font-size:0.75rem; color:#64748b;">${Math.round(r.clock_in_distance_meters)}m away</div>` : ''}
          </td>
          <td>
            <div>${r.clock_out_formatted || '<span style="color:#94a3b8;">Not Out</span>'}</div>
            ${r.clock_out_distance_meters !== null ? `<div style="font-size:0.75rem; color:#64748b;">${Math.round(r.clock_out_distance_meters)}m away</div>` : ''}
          </td>
          <td>${r.duration_formatted || '—'}</td>
          <td><span class="status-pill pill-${r.badge_class || 'secondary'}">${r.status_label || r.status}</span></td>
          <td>${flagHtml}</td>
          <td>
            <button class="ghost-btn table-action-btn" type="button" data-edit-record="${r.id}" data-staff-id="${r.staff_profile_id}" data-campus-id="${r.campus_id}" data-date="${r.date}" data-status="${r.status}">Edit</button>
          </td>
        `;
        elements.rollTbody.appendChild(tr);
      });

      // Bind edit buttons
      elements.rollTbody.querySelectorAll('[data-edit-record]').forEach((btn) => {
        btn.addEventListener('click', () => {
          openManualModal({
            staff_id: btn.getAttribute('data-staff-id'),
            campus_id: btn.getAttribute('data-campus-id'),
            date: btn.getAttribute('data-date'),
            status: btn.getAttribute('data-status'),
          });
        });
      });
    } catch (err) {
      console.error('Failed to load attendance records', err);
      showToast(err.message, 'error');
    }
  }

  // TAB 2: Geofence Cards
  function renderGeofenceCards() {
    if (!elements.geofenceGrid) return;
    elements.geofenceGrid.innerHTML = '';

    if (campusesList.length === 0) {
      elements.geofenceGrid.innerHTML = '<p style="color:#94a3b8;">No campuses found.</p>';
      return;
    }

    campusesList.forEach((c) => {
      const card = document.createElement('div');
      card.className = 'gps-card';

      const latitude = c.latitude == null ? null : Number(c.latitude);
      const longitude = c.longitude == null ? null : Number(c.longitude);

      const hasCoords =
        latitude !== null &&
        longitude !== null &&
        Number.isFinite(latitude) &&
        Number.isFinite(longitude) &&
        latitude >= -90 &&
        latitude <= 90 &&
        longitude >= -180 &&
        longitude <= 180;

      const gracePeriod = c.grace_period_minutes ?? 15;

      card.innerHTML = `
        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:0.75rem;">
          <div>
            <h3 style="font-size:1.1rem; font-weight:700; margin:0;">${c.name}</h3>
            <span style="font-size:0.8rem; color:#64748b;">${c.code || 'No Code'} · ${c.address || 'Address not set'}</span>
          </div>
          <span class="status-pill ${hasCoords ? 'pill-success' : 'pill-warning'}">
            ${hasCoords ? 'Geofenced' : 'No GPS'}
          </span>
        </div>

        <div style="font-size:0.85rem; display:grid; grid-template-columns:1fr 1fr; gap:0.5rem; margin-bottom:1rem; background:#f8fafc; padding:0.75rem; border-radius:8px;">
          <div><strong>Coordinates:</strong> ${hasCoords ? `${latitude.toFixed(6)}, ${longitude.toFixed(6)}` : 'Not configured'}</div>
          <div><strong>Radius:</strong> ${c.radius_meters || 100} meters</div>
          <div><strong>GPS Accuracy Max:</strong> ${c.gps_accuracy_threshold || 50} meters</div>
          <div><strong>Boundary Type:</strong> ${(c.geofence_type || 'circle').toUpperCase()}</div>
          <div><strong>Hours:</strong> ${c.operating_hours_start || '06:30'} - ${c.operating_hours_end || '18:30'}</div>
          <div><strong>Expected In:</strong> ${c.expected_clock_in_time || '07:45'} (Grace: ${gracePeriod}m)</div>
        </div>

        <div style="display:flex; justify-content:flex-end;">
          <button class="ghost-btn table-action-btn" type="button" data-edit-campus="${c.id}">Configure Geofence</button>
        </div>
      `;
      elements.geofenceGrid.appendChild(card);
    });

    elements.geofenceGrid.querySelectorAll('[data-edit-campus]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const id = btn.getAttribute('data-edit-campus');
        const campus = campusesList.find((c) => c.id == id);
        if (campus) openGeofenceModal(campus);
      });
    });
  }

  function openGeofenceModal(campus = null) {
    if (!elements.modalGeofence || !elements.formGeofence) return;
    elements.formGeofence.reset();
    const scrollBody = elements.modalGeofence.querySelector('.modal-scroll-body');
    if (scrollBody) scrollBody.scrollTop = 0;

    document.getElementById('geofenceCampusId').value = campus ? campus.id : '';
    document.getElementById('geofenceName').value = campus ? campus.name : '';
    document.getElementById('geofenceCode').value = campus ? (campus.code || '') : '';
    document.getElementById('geofenceAddress').value = campus ? (campus.address || '') : '';
    document.getElementById('geofenceLat').value = campus && campus.latitude !== null ? campus.latitude : '';
    document.getElementById('geofenceLng').value = campus && campus.longitude !== null ? campus.longitude : '';
    document.getElementById('geofenceRadius').value = campus ? (campus.radius_meters || 100) : 100;
    document.getElementById('geofenceAccuracy').value = campus ? (campus.gps_accuracy_threshold || 50) : 50;
    document.getElementById('geofenceHoursStart').value = campus ? (campus.operating_hours_start || '06:30:00') : '06:30:00';
    document.getElementById('geofenceHoursEnd').value = campus ? (campus.operating_hours_end || '18:30:00') : '18:30:00';
    document.getElementById('geofenceClockIn').value = campus ? (campus.expected_clock_in_time || '07:45:00') : '07:45:00';
    document.getElementById('geofenceClockOut').value = campus ? (campus.expected_clock_out_time || '16:00:00') : '16:00:00';
    document.getElementById('geofenceGrace').value = campus ? (campus.grace_period_minutes ?? 15) : 15;

    elements.modalGeofence.hidden = false;
  }

  function closeGeofenceModal() {
    if (elements.modalGeofence) elements.modalGeofence.hidden = true;
  }

  async function handleGeofenceSubmit(e) {
    e.preventDefault();
    const id = document.getElementById('geofenceCampusId').value;
    const isNew = !id;
    const url = isNew ? '/api/v1/campuses' : `/api/v1/campuses/${id}`;
    const method = isNew ? 'POST' : 'PUT';

    const latitudeInput = document.getElementById('geofenceLat').value.trim();
    const longitudeInput = document.getElementById('geofenceLng').value.trim();
    const graceInput = document.getElementById('geofenceGrace').value.trim();

    const latitude = latitudeInput === '' ? null : Number(latitudeInput);
    const longitude = longitudeInput === '' ? null : Number(longitudeInput);
    const gracePeriod = graceInput === '' ? 15 : Number.parseInt(graceInput, 10);

    if (
      latitude === null ||
      longitude === null ||
      !Number.isFinite(latitude) ||
      !Number.isFinite(longitude) ||
      latitude < -90 ||
      latitude > 90 ||
      longitude < -180 ||
      longitude > 180
    ) {
      showToast('Please enter valid campus GPS coordinates.', 'error');
      return;
    }

    if (!Number.isInteger(gracePeriod) || gracePeriod < 0 || gracePeriod > 120) {
      showToast('Grace period must be between 0 and 120 minutes.', 'error');
      return;
    }

    const payload = {
      name: document.getElementById('geofenceName').value.trim(),
      code: document.getElementById('geofenceCode').value.trim() || null,
      address: document.getElementById('geofenceAddress').value.trim() || null,
      latitude,
      longitude,
      radius_meters: parseInt(document.getElementById('geofenceRadius').value, 10) || 100,
      gps_accuracy_threshold: parseInt(document.getElementById('geofenceAccuracy').value, 10) || 50,
      operating_hours_start: document.getElementById('geofenceHoursStart').value,
      operating_hours_end: document.getElementById('geofenceHoursEnd').value,
      expected_clock_in_time: document.getElementById('geofenceClockIn').value,
      expected_clock_out_time: document.getElementById('geofenceClockOut').value,
      grace_period_minutes: gracePeriod,
    };

    try {
      const res = await api(url, {
        method,
        body: JSON.stringify(payload),
      });

      showToast(res.message || 'Campus geofence updated successfully.');
      closeGeofenceModal();
      await loadCampuses();
    } catch (err) {
      showToast(err.message, 'error');
    }
  }

  // TAB 3: Multi-Campus Staff Assignments
  async function loadAssignments() {
    if (!elements.assignmentsTbody) return;

    try {
      const params = new URLSearchParams();
      if (elements.assignmentCampusFilter && elements.assignmentCampusFilter.value) {
        params.set('campus_id', elements.assignmentCampusFilter.value);
      }

      const res = await api(`/api/v1/admin/staff-attendance/assignments?${params.toString()}`);
      const list = Array.isArray(res.data) ? res.data : (res.data?.items || []);

      elements.assignmentsTbody.innerHTML = '';
      if (list.length === 0) {
        if (elements.assignmentsEmpty) elements.assignmentsEmpty.hidden = false;
        return;
      }
      if (elements.assignmentsEmpty) elements.assignmentsEmpty.hidden = true;

      list.forEach((a) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td>
            <strong>${a.staff_name || 'Staff'}</strong>
            <div style="font-size:0.75rem; color:#64748b;">${a.staff_number || ''}</div>
          </td>
          <td><strong>${a.campus_name}</strong></td>
          <td>
            <span class="status-pill ${a.is_primary ? 'pill-info' : 'pill-secondary'}">
              ${a.is_primary ? 'Primary Campus' : 'Secondary Assignment'}
            </span>
          </td>
          <td>${a.start_date} ${a.end_date ? 'to ' + a.end_date : '(Ongoing)'}</td>
          <td>
            <span class="status-pill ${a.is_active ? 'pill-success' : 'pill-danger'}">
              ${a.is_active ? 'Active' : 'Inactive'}
            </span>
          </td>
          <td>
            <button class="ghost-btn table-action-btn" type="button" data-toggle-assignment="${a.id}" data-active="${a.is_active ? '0' : '1'}">
              ${a.is_active ? 'Deactivate' : 'Activate'}
            </button>
            <button class="ghost-btn table-action-btn" type="button" data-delete-assignment="${a.id}" style="color:#dc2626;">Remove</button>
          </td>
        `;
        elements.assignmentsTbody.appendChild(tr);
      });

      elements.assignmentsTbody.querySelectorAll('[data-toggle-assignment]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const id = btn.getAttribute('data-toggle-assignment');
          const newStatus = btn.getAttribute('data-active') === '1';
          try {
            await api(`/api/v1/admin/staff-attendance/assignments/${id}`, {
              method: 'PUT',
              body: JSON.stringify({ is_active: newStatus }),
            });
            showToast('Assignment status updated.');
            loadAssignments();
          } catch (err) {
            showToast(err.message, 'error');
          }
        });
      });

      elements.assignmentsTbody.querySelectorAll('[data-delete-assignment]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          if (!confirm('Are you sure you want to remove this campus assignment?')) return;
          const id = btn.getAttribute('data-delete-assignment');
          try {
            await api(`/api/v1/admin/staff-attendance/assignments/${id}`, {
              method: 'DELETE',
            });
            showToast('Assignment removed.');
            loadAssignments();
          } catch (err) {
            showToast(err.message, 'error');
          }
        });
      });
    } catch (err) {
      console.error('Failed to load assignments', err);
    }
  }

  function openAssignModal() {
    if (!elements.modalAssign || !elements.formAssign) return;
    elements.formAssign.reset();
    const scrollBody = elements.modalAssign.querySelector('.modal-scroll-body');
    if (scrollBody) scrollBody.scrollTop = 0;
    document.getElementById('assignStartDate').value = getLagosDateString();
    elements.modalAssign.hidden = false;
  }

  function closeAssignModal() {
    if (elements.modalAssign) elements.modalAssign.hidden = true;
  }

  async function handleAssignSubmit(e) {
    e.preventDefault();
    const payload = {
      staff_profile_id: parseInt(document.getElementById('assignStaffId').value, 10),
      campus_id: parseInt(document.getElementById('assignCampusId').value, 10),
      is_primary: document.getElementById('assignIsPrimary').checked,
      start_date: document.getElementById('assignStartDate').value,
      end_date: document.getElementById('assignEndDate').value || null,
      notes: document.getElementById('assignNotes').value.trim() || null,
    };

    try {
      const res = await api('/api/v1/admin/staff-attendance/assignments', {
        method: 'POST',
        body: JSON.stringify(payload),
      });

      showToast(res.message || 'Staff assigned to campus successfully.');
      closeAssignModal();
      await loadAssignments();
    } catch (err) {
      showToast(err.message, 'error');
    }
  }

  // TAB 4: Verification Logs Audit
  async function loadLogs() {
    if (!elements.logsTbody) return;

    try {
      const params = new URLSearchParams();
      if (elements.logsFilterResult && elements.logsFilterResult.value) {
        if (elements.logsFilterResult.value === 'suspicious') {
          params.set('is_suspicious', '1');
        } else {
          params.set('result', elements.logsFilterResult.value);
        }
      }

      const res = await api(`/api/v1/admin/staff-attendance/logs?${params.toString()}`);
      const logs = Array.isArray(res.data) ? res.data : (res.data?.items || []);

      elements.logsTbody.innerHTML = '';
      if (logs.length === 0) {
        if (elements.logsEmpty) elements.logsEmpty.hidden = false;
        return;
      }
      if (elements.logsEmpty) elements.logsEmpty.hidden = true;

      logs.forEach((l) => {
        const tr = document.createElement('tr');
        const isSuccess = l.result === 'accepted';
        const isSuspicious = l.is_suspicious;

        const latitude = l.latitude == null ? null : Number(l.latitude);
        const longitude = l.longitude == null ? null : Number(l.longitude);

        const hasGpsFix =
          latitude !== null &&
          longitude !== null &&
          Number.isFinite(latitude) &&
          Number.isFinite(longitude);

        const distance = l.distance_meters == null
          ? null
          : Number(l.distance_meters);

        const accuracy = l.gps_accuracy == null
          ? null
          : Number(l.gps_accuracy);

        tr.innerHTML = `
          <td><strong>${l.attempted_at_formatted || '—'}</strong></td>
          <td>
            <strong>${l.staff_name || 'Staff'}</strong>
            <div style="font-size:0.75rem; color:#64748b;">${l.staff_number || ''}</div>
          </td>
          <td>${l.campus_name || '—'}</td>
          <td><span class="status-pill ${l.action === 'clock_in' ? 'pill-info' : 'pill-secondary'}">${l.action}</span></td>
          <td>
            <div>${hasGpsFix ? `${latitude.toFixed(6)}, ${longitude.toFixed(6)}` : 'No fix'}</div>
            <div style="font-size:0.75rem; color:#64748b;">Dist: ${Number.isFinite(distance) ? Math.round(distance) + 'm' : '—'} · Acc: ${Number.isFinite(accuracy) ? '±' + Math.round(accuracy) + 'm' : '—'}</div>
          </td>
          <td>
            <span class="status-pill ${isSuccess ? 'pill-success' : 'pill-danger'}">
              ${l.result}
            </span>
            ${isSuspicious ? '<span class="status-pill pill-danger" style="margin-left:4px;">Suspicious</span>' : ''}
          </td>
          <td style="font-size:0.8rem; color:${isSuccess ? '#166534' : '#dc2626'}; max-width:240px;">
            ${l.rejection_reason || (isSuspicious ? (l.metadata && l.metadata.suspicious_reason) : 'Verified')}
          </td>
        `;
        elements.logsTbody.appendChild(tr);
      });
    } catch (err) {
      console.error('Failed to load verification logs', err);
    }
  }

  // TAB 5: Correction Requests
  async function loadAdjustments() {
    if (!elements.adjustmentsTbody) return;

    try {
      const res = await api('/api/v1/admin/staff-attendance/adjustments?status=pending');
      const list = Array.isArray(res.data) ? res.data : (res.data?.items || []);

      elements.adjustmentsTbody.innerHTML = '';
      if (list.length === 0) {
        if (elements.adjustmentsEmpty) elements.adjustmentsEmpty.hidden = false;
        return;
      }
      if (elements.adjustmentsEmpty) elements.adjustmentsEmpty.hidden = true;

      list.forEach((adj) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td><strong>${adj.record_date}</strong></td>
          <td>
            <strong>${adj.staff_name}</strong>
            <div style="font-size:0.75rem; color:#64748b;">${adj.staff_number || ''}</div>
          </td>
          <td>${adj.campus_name || '—'}</td>
          <td>
            <div style="font-size:0.8rem;">
              In: <strong>${adj.requested_values.clock_in_at || 'Unchanged'}</strong><br>
              Out: <strong>${adj.requested_values.clock_out_at || 'Unchanged'}</strong>
            </div>
          </td>
          <td style="font-size:0.85rem; max-width:280px;">${adj.reason}</td>
          <td>
            <button class="ghost-btn table-action-btn" type="button" data-review-adj="${adj.id}" data-action="approve" style="color:#16a34a; font-weight:700;">Approve</button>
            <button class="ghost-btn table-action-btn" type="button" data-review-adj="${adj.id}" data-action="reject" style="color:#dc2626; margin-left:4px;">Reject</button>
          </td>
        `;
        elements.adjustmentsTbody.appendChild(tr);
      });

      elements.adjustmentsTbody.querySelectorAll('[data-review-adj]').forEach((btn) => {
        btn.addEventListener('click', () => {
          const id = btn.getAttribute('data-review-adj');
          const isApprove = btn.getAttribute('data-action') === 'approve';
          openReviewModal(id, isApprove);
        });
      });
    } catch (err) {
      console.error('Failed to load adjustments', err);
    }
  }

  function openReviewModal(adjId, isApprove) {
    if (!elements.modalReview || !elements.formReview) return;
    const scrollBody = elements.modalReview.querySelector('.modal-scroll-body');
    if (scrollBody) scrollBody.scrollTop = 0;
    document.getElementById('reviewAdjustmentId').value = adjId;
    document.getElementById('reviewIsApprove').value = isApprove ? '1' : '0';
    document.getElementById('reviewActionText').textContent = isApprove ? 'Approve' : 'Reject';
    document.getElementById('reviewNotes').value = '';
    elements.modalReview.hidden = false;
  }

  function closeReviewModal() {
    if (elements.modalReview) elements.modalReview.hidden = true;
  }

  async function handleReviewSubmit(e) {
    e.preventDefault();
    const id = document.getElementById('reviewAdjustmentId').value;
    const approve = document.getElementById('reviewIsApprove').value === '1';
    const notes = document.getElementById('reviewNotes').value.trim();

    try {
      const res = await api(`/api/v1/admin/staff-attendance/adjustments/${id}/review`, {
        method: 'POST',
        body: JSON.stringify({
          approve,
          review_notes: notes || null,
        }),
      });

      showToast(res.message);
      closeReviewModal();
      await loadAdjustments();
      await loadRecords();
      await loadOverview();
    } catch (err) {
      showToast(err.message, 'error');
    }
  }

  // Manual Attendance Modal
  function openManualModal(prefill = {}) {
    if (!elements.modalManual || !elements.formManual) return;
    elements.formManual.reset();
    const scrollBody = elements.modalManual.querySelector('.modal-scroll-body');
    if (scrollBody) scrollBody.scrollTop = 0;

    if (prefill.staff_id) document.getElementById('manualStaffId').value = prefill.staff_id;
    if (prefill.campus_id) document.getElementById('manualCampusId').value = prefill.campus_id;
    document.getElementById('manualDate').value = prefill.date || getLagosDateString();
    if (prefill.status) document.getElementById('manualStatus').value = prefill.status;

    elements.modalManual.hidden = false;
  }

  function closeManualModal() {
    if (elements.modalManual) elements.modalManual.hidden = true;
  }

  async function handleManualSubmit(e) {
    e.preventDefault();
    const payload = {
      staff_profile_id: parseInt(document.getElementById('manualStaffId').value, 10),
      campus_id: parseInt(document.getElementById('manualCampusId').value, 10),
      date: document.getElementById('manualDate').value,
      clock_in_at: document.getElementById('manualClockIn').value ? document.getElementById('manualDate').value + ' ' + document.getElementById('manualClockIn').value : null,
      clock_out_at: document.getElementById('manualClockOut').value ? document.getElementById('manualDate').value + ' ' + document.getElementById('manualClockOut').value : null,
      status: document.getElementById('manualStatus').value,
      reason: document.getElementById('manualReason').value.trim(),
    };

    try {
      const res = await api('/api/v1/admin/staff-attendance/manual', {
        method: 'POST',
        body: JSON.stringify(payload),
      });

      showToast(res.message || 'Manual attendance recorded.');
      closeManualModal();
      await loadRecords();
      await loadOverview();
    } catch (err) {
      showToast(err.message, 'error');
    }
  }

  function switchTab(tabKey) {
    currentTab = tabKey;
    elements.tabNavs.forEach((btn) => {
      btn.classList.toggle('active', btn.getAttribute('data-tab-target') === tabKey);
    });
    elements.tabSections.forEach((sec) => {
      sec.hidden = sec.getAttribute('data-tab-section') !== tabKey;
    });

    if (tabKey === 'roll') loadRecords();
    if (tabKey === 'geofences') renderGeofenceCards();
    if (tabKey === 'assignments') loadAssignments();
    if (tabKey === 'logs') loadLogs();
    if (tabKey === 'adjustments') loadAdjustments();
  }

  function exportCsv() {
    const params = new URLSearchParams();
    if (elements.filterDate && elements.filterDate.value) params.set('date', elements.filterDate.value);
    if (elements.filterCampus && elements.filterCampus.value) params.set('campus_id', elements.filterCampus.value);
    if (elements.filterStatus && elements.filterStatus.value) params.set('status', elements.filterStatus.value);
    window.location.href = `/api/v1/admin/staff-attendance/export?${params.toString()}`;
  }

  function init() {
    initDefaultDate();

    // Tab switcher
    elements.tabNavs.forEach((btn) => {
      btn.addEventListener('click', () => {
        switchTab(btn.getAttribute('data-tab-target'));
      });
    });

    // Filters
    if (elements.btnApplyFilters) elements.btnApplyFilters.addEventListener('click', () => {
      loadOverview();
      loadRecords();
    });

    if (elements.btnExportCsv) elements.btnExportCsv.addEventListener('click', exportCsv);
    if (elements.btnOpenManualModal) elements.btnOpenManualModal.addEventListener('click', () => openManualModal());
    if (elements.btnOpenGeofenceModal) elements.btnOpenGeofenceModal.addEventListener('click', () => openGeofenceModal());
    if (elements.btnOpenAssignModal) elements.btnOpenAssignModal.addEventListener('click', openAssignModal);

    if (elements.assignmentCampusFilter) {
      elements.assignmentCampusFilter.addEventListener('change', loadAssignments);
    }
    if (elements.logsFilterResult) {
      elements.logsFilterResult.addEventListener('change', loadLogs);
    }

    // Modal listeners
    document.querySelectorAll('[data-dismiss-modal]').forEach((btn) => {
      btn.addEventListener('click', () => {
        closeGeofenceModal();
        closeAssignModal();
        closeManualModal();
        closeReviewModal();
      });
    });

    if (elements.formGeofence) elements.formGeofence.addEventListener('submit', handleGeofenceSubmit);
    if (elements.formAssign) elements.formAssign.addEventListener('submit', handleAssignSubmit);
    if (elements.formManual) elements.formManual.addEventListener('submit', handleManualSubmit);
    if (elements.formReview) elements.formReview.addEventListener('submit', handleReviewSubmit);

    // Initial load
    loadCampuses();
    loadStaffMembers();
    loadOverview();
    loadRecords();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

