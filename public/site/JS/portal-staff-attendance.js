/**
 * Supreme Reagan Schools - Staff GPS Attendance Module
 * Handles mobile-friendly GPS clock-in, clock-out, history, and correction requests.
 */
(function () {
  'use strict';

  let currentOverview = null;
  let activeCampus = null;
  let cachedPosition = null;
  let isAcquiringGps = false;

  const elements = {
    campusSelect: document.getElementById('attendanceCampusSelect'),
    gpsStatusBox: document.getElementById('gpsStatusBox'),
    gpsStatusText: document.getElementById('gpsStatusText'),
    gpsAccuracyText: document.getElementById('gpsAccuracyText'),
    gpsDistanceText: document.getElementById('gpsDistanceText'),
    btnAcquireGps: document.getElementById('btnAcquireGps'),
    btnClockIn: document.getElementById('btnClockIn'),
    btnClockOut: document.getElementById('btnClockOut'),
    clockInLabel: document.getElementById('clockInLabel'),
    clockOutLabel: document.getElementById('clockOutLabel'),
    todayClockInTime: document.getElementById('todayClockInTime'),
    todayClockOutTime: document.getElementById('todayClockOutTime'),
    todayStatusBadge: document.getElementById('todayStatusBadge'),
    todayCampusName: document.getElementById('todayCampusName'),
    todayDuration: document.getElementById('todayDuration'),
    kpiPresent: document.getElementById('kpiPresent'),
    kpiLate: document.getElementById('kpiLate'),
    kpiMissing: document.getElementById('kpiMissing'),
    kpiHours: document.getElementById('kpiHours'),
    kpiMonthLabel: document.getElementById('kpiMonthLabel'),
    historyTbody: document.getElementById('historyTbody'),
    historyEmptyState: document.getElementById('historyEmptyState'),
    correctionModal: document.getElementById('correctionModal'),
    correctionForm: document.getElementById('correctionForm'),
    btnCancelCorrection: document.getElementById('btnCancelCorrection'),
    alertToast: document.getElementById('attendanceToast'),
    alertToastText: document.getElementById('attendanceToastText'),
  };

  function showToast(message, type = 'success') {
    if (!elements.alertToast || !elements.alertToastText) {
      alert(message);
      return;
    }
    elements.alertToastText.textContent = message;
    elements.alertToast.className = 'desk-toast ' + (type === 'error' ? 'toast-error' : 'toast-success');
    elements.alertToast.hidden = false;
    setTimeout(() => {
      elements.alertToast.hidden = true;
    }, 5000);
  }

  function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) return meta.getAttribute('content');
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
  }

  async function apiRequest(url, options = {}) {
    const headers = {
      'Accept': 'application/json',
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': getCsrfToken(),
      'X-Requested-With': 'XMLHttpRequest',
      ...(options.headers || {}),
    };

    const response = await fetch(url, { ...options, headers });
    const json = await response.json().catch(() => null);

    if (!response.ok) {
      let errorMessage = (json && (json.message || (json.errors && Object.values(json.errors).flat()[0])))
        || 'An error occurred. Please try again.';
      throw new Error(errorMessage);
    }

    return json;
  }

  /**
   * Acquire live GPS position from the browser/device.
   */
  function acquireGpsPosition() {
    return new Promise((resolve, reject) => {
      if (!navigator.geolocation) {
        reject(new Error('Geolocation is not supported by your browser or phone.'));
        return;
      }

      if (elements.gpsStatusText) {
        elements.gpsStatusText.textContent = 'Acquiring high-accuracy GPS fix...';
        elements.gpsStatusBox.className = 'gps-status-box acquiring';
      }

      isAcquiringGps = true;
      if (elements.btnAcquireGps) elements.btnAcquireGps.disabled = true;

      navigator.geolocation.getCurrentPosition(
        (position) => {
          isAcquiringGps = false;
          if (elements.btnAcquireGps) elements.btnAcquireGps.disabled = false;
          cachedPosition = {
            latitude: position.coords.latitude,
            longitude: position.coords.longitude,
            accuracy: position.coords.accuracy,
            timestamp: position.timestamp,
          };
          updateGpsStatusDisplay();
          resolve(cachedPosition);
        },
        (error) => {
          isAcquiringGps = false;
          if (elements.btnAcquireGps) elements.btnAcquireGps.disabled = false;
          let msg = 'Failed to acquire GPS location.';
          switch (error.code) {
            case error.PERMISSION_DENIED:
              msg = 'Location permission was denied. Please allow location access in your browser settings to clock in/out.';
              break;
            case error.POSITION_UNAVAILABLE:
              msg = 'Location position unavailable. Please ensure GPS is turned on and you have a clear view of the sky.';
              break;
            case error.TIMEOUT:
              msg = 'Location request timed out. Please try again.';
              break;
          }
          if (elements.gpsStatusText) {
            elements.gpsStatusText.textContent = msg;
            elements.gpsStatusBox.className = 'gps-status-box error';
          }
          reject(new Error(msg));
        },
        {
          enableHighAccuracy: true,
          timeout: 20000,
          maximumAge: 0,
        }
      );
    });
  }

  function updateGpsStatusDisplay() {
    if (!cachedPosition) return;

    if (elements.gpsAccuracyText) {
      elements.gpsAccuracyText.textContent = `Accuracy: ±${Math.round(cachedPosition.accuracy)}m`;
    }

    if (activeCampus && activeCampus.latitude && activeCampus.longitude) {
      const dist = calculateDistance(
        cachedPosition.latitude,
        cachedPosition.longitude,
        activeCampus.latitude,
        activeCampus.longitude
      );
      const isInside = dist <= (activeCampus.radius_meters || 100);
      const isAccurate = cachedPosition.accuracy <= (activeCampus.gps_accuracy_threshold || 50);

      if (elements.gpsDistanceText) {
        elements.gpsDistanceText.textContent = `Distance: ${Math.round(dist)}m from ${activeCampus.name} (Boundary: ${activeCampus.radius_meters || 100}m)`;
      }

      if (elements.gpsStatusText && elements.gpsStatusBox) {
        if (!isAccurate) {
          elements.gpsStatusText.textContent = `GPS accuracy low (±${Math.round(cachedPosition.accuracy)}m). Minimum required is ${activeCampus.gps_accuracy_threshold || 50}m.`;
          elements.gpsStatusBox.className = 'gps-status-box warning';
        } else if (isInside) {
          elements.gpsStatusText.textContent = `Verified inside ${activeCampus.name} premises (${Math.round(dist)}m). Ready to clock.`;
          elements.gpsStatusBox.className = 'gps-status-box success';
        } else {
          elements.gpsStatusText.textContent = `Outside campus boundary (${Math.round(dist)}m away). Maximum radius is ${activeCampus.radius_meters || 100}m.`;
          elements.gpsStatusBox.className = 'gps-status-box error';
        }
      }
    } else {
      if (elements.gpsStatusText && elements.gpsStatusBox) {
        elements.gpsStatusText.textContent = 'GPS location acquired. Please choose an assigned campus.';
        elements.gpsStatusBox.className = 'gps-status-box success';
      }
    }
  }

  function calculateDistance(lat1, lon1, lat2, lon2) {
    const R = 6371000;
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;
    const a =
      Math.sin(dLat / 2) * Math.sin(dLat / 2) +
      Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
      Math.sin(dLon / 2) * Math.sin(dLon / 2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    return R * c;
  }

  async function loadOverview() {
    try {
      const res = await apiRequest('/api/v1/staff/attendance/overview');
      currentOverview = res.data;
      renderOverview();
    } catch (err) {
      console.error('Failed to load attendance overview', err);
      showToast(err.message, 'error');
    }
  }

  function renderOverview() {
    if (!currentOverview) return;

    const { today, campuses, month_stats } = currentOverview;

    // Monthly KPIs
    if (elements.kpiPresent) elements.kpiPresent.textContent = month_stats.present_days;
    if (elements.kpiLate) elements.kpiLate.textContent = month_stats.late_days;
    if (elements.kpiMissing) elements.kpiMissing.textContent = month_stats.missing_clock_outs;
    if (elements.kpiHours) elements.kpiHours.textContent = month_stats.total_hours + 'h';
    if (elements.kpiMonthLabel) elements.kpiMonthLabel.textContent = month_stats.month;

    // Populate Campuses
    if (elements.campusSelect) {
      const currentSelectedVal = elements.campusSelect.value;
      elements.campusSelect.innerHTML = '';

      if (!campuses || campuses.length === 0) {
        elements.campusSelect.innerHTML = '<option value="">No assigned campus found</option>';
        activeCampus = null;
      } else {
        campuses.forEach((c) => {
          const opt = document.createElement('option');
          opt.value = c.id;
          opt.textContent = `${c.name} ${c.is_primary ? '(Primary)' : ''}`;
          if (c.id == currentSelectedVal || (!currentSelectedVal && c.is_primary)) {
            opt.selected = true;
            activeCampus = c;
          }
          elements.campusSelect.appendChild(opt);
        });

        if (!activeCampus && campuses.length > 0) {
          activeCampus = campuses[0];
        }
      }
    }

    // Today's summary card
    if (elements.todayClockInTime) elements.todayClockInTime.textContent = today.clock_in_time || 'Not clocked in';
    if (elements.todayClockOutTime) elements.todayClockOutTime.textContent = today.clock_out_time || 'Not clocked out';
    if (elements.todayCampusName) {
      if (today.clock_out_campus_name && today.clock_out_campus_name !== today.campus_name) {
        elements.todayCampusName.textContent = `${today.campus_name} → ${today.clock_out_campus_name}`;
      } else {
        elements.todayCampusName.textContent = today.campus_name || (activeCampus ? activeCampus.name : '—');
      }
    }

    if (elements.todayStatusBadge) {
      if (today.status_label) {
        elements.todayStatusBadge.textContent = today.status_label;
        elements.todayStatusBadge.className = 'status-pill ' + (today.status === 'late' ? 'pill-warning' : 'pill-success');
      } else {
        elements.todayStatusBadge.textContent = 'Pending clock-in';
        elements.todayStatusBadge.className = 'status-pill pill-secondary';
      }
    }

    // Buttons state
    if (elements.btnClockIn) {
      elements.btnClockIn.disabled = Boolean(today.has_clocked_in);
      if (today.has_clocked_in) {
        elements.btnClockIn.title = 'You have already clocked in for today.';
      } else {
        elements.btnClockIn.title = 'Clock in using current GPS location.';
      }
    }

    if (elements.btnClockOut) {
      elements.btnClockOut.disabled = !today.has_clocked_in || Boolean(today.has_clocked_out);
      if (!today.has_clocked_in) {
        elements.btnClockOut.title = 'You must clock in first before clocking out.';
      } else if (today.has_clocked_out) {
        elements.btnClockOut.title = 'You have already clocked out for today.';
      } else {
        elements.btnClockOut.title = 'Clock out using current GPS location.';
      }
    }

    updateGpsStatusDisplay();
  }

  async function handleClockIn() {
    if (!activeCampus) {
      showToast('Please select a campus to clock in.', 'error');
      return;
    }

    try {
      elements.btnClockIn.disabled = true;
      if (elements.clockInLabel) elements.clockInLabel.textContent = 'Acquiring GPS...';

      const position = await acquireGpsPosition();

      if (elements.clockInLabel) elements.clockInLabel.textContent = 'Verifying with school...';

      const res = await apiRequest('/api/v1/staff/attendance/clock-in', {
        method: 'POST',
        body: JSON.stringify({
          campus_id: activeCampus.id,
          latitude: position.latitude,
          longitude: position.longitude,
          accuracy: position.accuracy,
          device: navigator.userAgent,
        }),
      });

      showToast(res.message || 'Clock-in successful!', 'success');
      await loadOverview();
      await loadHistory();
    } catch (err) {
      showToast(err.message, 'error');
    } finally {
      if (elements.clockInLabel) elements.clockInLabel.textContent = 'Clock In';
      renderOverview();
    }
  }

  async function handleClockOut() {
    if (!activeCampus) {
      showToast('Please select a campus to clock out.', 'error');
      return;
    }

    try {
      elements.btnClockOut.disabled = true;
      if (elements.clockOutLabel) elements.clockOutLabel.textContent = 'Acquiring GPS...';

      const position = await acquireGpsPosition();

      if (elements.clockOutLabel) elements.clockOutLabel.textContent = 'Verifying with school...';

      const res = await apiRequest('/api/v1/staff/attendance/clock-out', {
        method: 'POST',
        body: JSON.stringify({
          campus_id: activeCampus.id,
          latitude: position.latitude,
          longitude: position.longitude,
          accuracy: position.accuracy,
          device: navigator.userAgent,
        }),
      });

      showToast(res.message || 'Clock-out successful!', 'success');
      await loadOverview();
      await loadHistory();
    } catch (err) {
      showToast(err.message, 'error');
    } finally {
      if (elements.clockOutLabel) elements.clockOutLabel.textContent = 'Clock Out';
      renderOverview();
    }
  }

  async function loadHistory(page = 1) {
    if (!elements.historyTbody) return;

    try {
      const res = await apiRequest(`/api/v1/staff/attendance/history?page=${page}`);
      const records = Array.isArray(res.data) ? res.data : (res.data?.items || []);

      elements.historyTbody.innerHTML = '';

      if (records.length === 0) {
        if (elements.historyEmptyState) elements.historyEmptyState.hidden = false;
        return;
      }

      if (elements.historyEmptyState) elements.historyEmptyState.hidden = true;

      records.forEach((r) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td><strong>${r.date}</strong></td>
          <td>${r.clock_out_campus_name && r.clock_out_campus_name !== r.campus_name ? `${r.campus_name} → ${r.clock_out_campus_name}` : (r.campus_name || '—')}</td>
          <td>${r.clock_in_formatted || '—'}</td>
          <td>${r.clock_out_formatted || '—'}</td>
          <td>${r.duration_formatted || '—'}</td>
          <td><span class="status-pill pill-${r.badge_class || 'secondary'}">${r.status_label || r.status}</span></td>
          <td>
            ${
              r.has_pending_adjustment
                ? '<span class="status-pill pill-warning">Correction Pending</span>'
                : `<button class="ghost-btn table-action-btn" type="button" data-correction-id="${r.id}" data-date="${r.date}">Request Edit</button>`
            }
          </td>
        `;
        elements.historyTbody.appendChild(tr);
      });

      // Bind correction buttons
      elements.historyTbody.querySelectorAll('[data-correction-id]').forEach((btn) => {
        btn.addEventListener('click', () => {
          const recordId = btn.getAttribute('data-correction-id');
          const date = btn.getAttribute('data-date');
          openCorrectionModal(recordId, date);
        });
      });
    } catch (err) {
      console.error('Failed to load attendance history', err);
    }
  }

  function openCorrectionModal(recordId, date) {
    if (!elements.correctionModal) return;
    document.getElementById('correctionRecordId').value = recordId;
    document.getElementById('correctionRecordDate').textContent = date;
    elements.correctionModal.hidden = false;
  }

  function closeCorrectionModal() {
    if (!elements.correctionModal) return;
    elements.correctionModal.hidden = true;
    if (elements.correctionForm) elements.correctionForm.reset();
  }

  async function handleCorrectionSubmit(e) {
    e.preventDefault();
    const recordId = document.getElementById('correctionRecordId').value;
    const clockIn = document.getElementById('correctionClockIn').value;
    const clockOut = document.getElementById('correctionClockOut').value;
    const reason = document.getElementById('correctionReason').value;

    if (!reason || reason.trim().length < 5) {
      showToast('Please provide a detailed reason for the correction.', 'error');
      return;
    }

    try {
      const submitBtn = elements.correctionForm.querySelector('button[type="submit"]');
      if (submitBtn) submitBtn.disabled = true;

      const res = await apiRequest('/api/v1/staff/attendance/correction', {
        method: 'POST',
        body: JSON.stringify({
          record_id: parseInt(recordId, 10),
          requested_clock_in: clockIn ? clockIn : null,
          requested_clock_out: clockOut ? clockOut : null,
          reason: reason.trim(),
        }),
      });

      showToast(res.message || 'Correction request submitted for review.', 'success');
      closeCorrectionModal();
      await loadHistory();
    } catch (err) {
      showToast(err.message, 'error');
    } finally {
      const submitBtn = elements.correctionForm.querySelector('button[type="submit"]');
      if (submitBtn) submitBtn.disabled = false;
    }
  }

  function init() {
    if (elements.campusSelect) {
      elements.campusSelect.addEventListener('change', () => {
        const selectedId = elements.campusSelect.value;
        if (currentOverview && currentOverview.campuses) {
          activeCampus = currentOverview.campuses.find((c) => c.id == selectedId) || null;
        }
        updateGpsStatusDisplay();
      });
    }

    if (elements.btnAcquireGps) {
      elements.btnAcquireGps.addEventListener('click', () => {
        acquireGpsPosition().catch((err) => showToast(err.message, 'error'));
      });
    }

    if (elements.btnClockIn) {
      elements.btnClockIn.addEventListener('click', handleClockIn);
    }

    if (elements.btnClockOut) {
      elements.btnClockOut.addEventListener('click', handleClockOut);
    }

    if (elements.btnCancelCorrection) {
      elements.btnCancelCorrection.addEventListener('click', closeCorrectionModal);
    }

    if (elements.correctionForm) {
      elements.correctionForm.addEventListener('submit', handleCorrectionSubmit);
    }

    // Initial data load
    loadOverview();
    loadHistory();

    // Auto-prompt location acquisition on page load for convenience
    setTimeout(() => {
      acquireGpsPosition().catch(() => {});
    }, 800);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

