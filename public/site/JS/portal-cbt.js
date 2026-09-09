(function () {
  const page = document.body.getAttribute("data-cbt-page") || "";
  if (!page) return;

  const attemptState = {
    attempt: null,
    exam: null,
    index: 0,
    answers: {},
    answerMeta: {},
    pending: {},
    saveTimers: {},
    timerId: null,
    submitting: false,
    channel: null,
    wasOffline: !navigator.onLine,
    clockOffsetMs: 0,
    offlineReady: false,
    localUnavailable: false,
    expiredLocally: false
  };

  const csrfToken = function () {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : "";
  };

  const escapeHtml = function (value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  };

  const firstError = function (body) {
    if (!body) return "The CBT desk could not complete that request.";
    if (body.errors) {
      const keys = Object.keys(body.errors);
      if (keys.length) return body.errors[keys[0]][0];
    }
    return body.message || "The CBT desk could not complete that request.";
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
          window.location.replace("/cbt/login");
        }
        return { ok: response.ok, status: response.status, body: body };
      }).catch(function () {
        return { ok: false, status: response.status, body: {} };
      });
    }).catch(function () {
      return { ok: false, status: 0, body: { message: "Connection problem. Answers may not be saved." } };
    });
  };

  const alertBox = document.querySelector("[data-cbt-alert]");
  const showAlert = function (message, kind) {
    if (!alertBox) return;
    alertBox.textContent = message || "";
    alertBox.classList.toggle("is-visible", !!message);
    alertBox.classList.toggle("is-info", kind === "info");
  };

  const connDot = document.querySelector("[data-cbt-conn-dot]");
  const connLabel = document.querySelector("[data-cbt-conn-label]");
  const saveLabel = document.querySelector("[data-cbt-save-label]");

  const setConnection = function (state, detail) {
    if (connDot) {
      connDot.classList.remove("is-warn", "is-bad");
      if (state === "warn") connDot.classList.add("is-warn");
      if (state === "bad") connDot.classList.add("is-bad");
    }
    if (connLabel) connLabel.textContent = detail || (state === "ok" ? "Online" : "Connection problem");
  };

  const setSaveState = function (text) {
    if (saveLabel) saveLabel.textContent = text || "";
  };

  const offlineEnabled = function () {
    return !!(window.CbtOfflineStore && window.CbtOfflineSession && window.CbtOfflineStore.isAvailable());
  };

  const updateConnectivityUi = function (kind) {
    if (kind === "offline") {
      setConnection("bad", "Offline — your answers are being saved on this device.");
      return;
    }
    if (kind === "reconnecting") {
      setConnection("warn", "Connection restored — your answers are saved on this device.");
      return;
    }
    setConnection("ok", "Online");
  };

  const syncOnline = function () {
    if (!navigator.onLine) {
      updateConnectivityUi("offline");
      return;
    }
    if (attemptState.wasOffline) {
      updateConnectivityUi("reconnecting");
      attemptState.wasOffline = false;
      if (page === "attempt" && attemptState.attempt && attemptState.attempt.status === "in_progress") {
        flushAllPending();
        triggerOfflineSync("Connection restored — synchronizing…");
      }
      return;
    }
    updateConnectivityUi("online");
  };

  window.addEventListener("online", syncOnline);
  window.addEventListener("offline", function () {
    attemptState.wasOffline = true;
    updateConnectivityUi("offline");
  });
  syncOnline();

  const registerCbtServiceWorker = function () {
    if (!("serviceWorker" in navigator)) return;
    navigator.serviceWorker.register("/cbt-sw.js", { scope: "/cbt/" }).catch(function () {
      // Non-fatal: exam still works online without SW.
    });
  };
  registerCbtServiceWorker();

  document.querySelectorAll("[data-cbt-logout]").forEach(function (link) {
    link.addEventListener("click", function (event) {
      event.preventDefault();
      request("/cbt/logout", { method: "POST", body: "{}" }).finally(function () {
        window.location.href = "/cbt/login?from=logout";
      });
    });
  });

  const pathId = function (segment) {
    const parts = window.location.pathname.split("/").filter(Boolean);
    const index = parts.indexOf(segment);
    return index >= 0 ? parts[index + 1] : null;
  };

  const formatWhen = function (iso) {
    if (!iso) return "—";
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return "—";
    return date.toLocaleString();
  };

  const formatDuration = function (minutes) {
    const value = Number(minutes || 0);
    if (!Number.isFinite(value) || value <= 0) return "—";
    return value + " min";
  };

  const loadMeLabel = function () {
    return request("/api/v1/me").then(function (result) {
      if (!result.ok) return null;
      const user = result.body.data || {};
      const label = document.querySelector("[data-cbt-student-label]");
      if (label) label.textContent = user.name || user.email || "Student";
      return user;
    });
  };

  const badgeFor = function (exam) {
    if (exam.attempt_status === "in_progress") return '<span class="cbt-badge is-progress">In progress</span>';
    if (exam.attempt_status === "submitted") return '<span class="cbt-badge is-done">Submitted</span>';
    return '<span class="cbt-badge is-open">Available</span>';
  };

  const renderDesk = function () {
    const list = document.querySelector("[data-cbt-exam-list]");
    if (!list) return;

    Promise.all([
      loadMeLabel(),
      request("/api/v1/cbt/exams")
    ]).then(function (parts) {
      const result = parts[1];
      if (!result.ok) {
        list.innerHTML = '<div class="cbt-empty">Unable to load exams.</div>';
        showAlert(firstError(result.body));
        if (result.status === 0) setConnection("bad", "Connection problem");
        return;
      }

      setConnection("ok", "Connected");
      const data = result.body.data || {};
      const student = data.student;
      const label = document.querySelector("[data-cbt-student-label]");
      if (label && student) {
        label.textContent = (student.name || "Student") + (student.admission_number ? " · " + student.admission_number : "");
      }

      const exams = data.exams || [];
      if (!exams.length) {
        list.innerHTML = '<div class="cbt-empty">No eligible examinations are available right now.</div>';
        return;
      }

      list.innerHTML = exams.map(function (exam) {
        const action = exam.active_attempt_id
          ? '<a class="cbt-btn cbt-btn-primary" href="/cbt/attempts/' + exam.active_attempt_id + '">Resume exam</a>'
          : (exam.attempts_remaining > 0
            ? '<a class="cbt-btn cbt-btn-primary" href="/cbt/exams/' + exam.id + '">Open instructions</a>'
            : '<span class="cbt-muted">No attempts remaining</span>');

        const resultLine = exam.latest_result
          ? (exam.latest_result.details_unlocked === false
            ? '<div class="cbt-muted">Result available — unlock with Result Checker</div>'
            : '<div class="cbt-muted">Latest: ' + escapeHtml(exam.latest_result.score) + ' / ' + escapeHtml(exam.latest_result.max_score)
              + ' (' + escapeHtml(exam.latest_result.percentage) + '%) · Grade ' + escapeHtml(exam.latest_result.grade || '—') + '</div>')
          : '';

        return '<article class="cbt-card">'
          + '<div class="cbt-actions" style="justify-content:space-between;align-items:flex-start;">'
          + '<h3>' + escapeHtml(exam.title) + '</h3>' + badgeFor(exam) + '</div>'
          + '<div class="cbt-meta">'
          + '<span>' + escapeHtml(exam.subject || 'Subject') + '</span>'
          + '<span>' + escapeHtml(formatDuration(exam.duration_minutes)) + '</span>'
          + '<span>' + escapeHtml(String(exam.question_count || 0)) + ' questions</span>'
          + '<span>Max ' + escapeHtml(exam.max_score) + '</span>'
          + '<span>Attempts left: ' + escapeHtml(String(exam.attempts_remaining)) + '</span>'
          + '</div>'
          + '<div class="cbt-muted" style="font-size:0.8rem;margin-bottom:0.75rem;">Window: '
          + escapeHtml(formatWhen(exam.starts_at)) + ' → ' + escapeHtml(formatWhen(exam.ends_at)) + '</div>'
          + resultLine
          + '<div class="cbt-actions" style="margin-top:0.75rem;">' + action + '</div>'
          + '</article>';
      }).join("");
    });
  };

  let startLocked = false;

  const renderExam = function () {
    const panel = document.querySelector("[data-cbt-exam-panel]");
    const examId = pathId("exams");
    if (!panel || !examId) return;

    loadMeLabel();
    request("/api/v1/cbt/exams/" + examId).then(function (result) {
      if (!result.ok) {
        panel.innerHTML = '<div class="cbt-empty">This examination is not available.</div>';
        showAlert(firstError(result.body));
        return;
      }

      const exam = result.body.data || {};
      const startLabel = exam.can_resume ? "Resume exam" : "Start exam";
      const disabled = (!exam.can_start && !exam.can_resume) ? " disabled" : "";

      panel.innerHTML = ''
        + '<h1>' + escapeHtml(exam.title) + '</h1>'
        + '<p class="cbt-lede">' + escapeHtml(exam.subject && exam.subject.name ? exam.subject.name : "Examination") + '</p>'
        + '<div class="cbt-meta">'
        + '<span>Duration: ' + escapeHtml(formatDuration(exam.duration_minutes)) + '</span>'
        + '<span>Questions: ' + escapeHtml(String(exam.question_count || 0)) + '</span>'
        + '<span>Total marks: ' + escapeHtml(exam.max_score) + '</span>'
        + '<span>Attempts remaining: ' + escapeHtml(String(exam.attempts_remaining)) + '</span>'
        + '</div>'
        + '<div class="cbt-card" style="margin-bottom:1rem;">'
        + '<h3>Instructions</h3>'
        + '<p class="cbt-lede" style="white-space:pre-wrap;">' + escapeHtml(exam.instructions || "Follow the invigilator’s directions. The timer starts when you begin.") + '</p>'
        + '<ul class="cbt-rules">'
        + '<li>The server controls the official start and end time.</li>'
        + '<li>Your answers are saved online while you remain connected.</li>'
        + '<li>Refreshing this page after starting resumes the same attempt.</li>'
        + '<li>Submit only when you are finished — submission cannot be undone.</li>'
        + '</ul></div>'
        + '<div class="cbt-card" style="margin-bottom:1rem;border-color:var(--cbt-danger,#b42318);">'
        + '<h3>EXAMINATION WARNING</h3>'
        + '<p class="cbt-lede">This examination uses strict exam monitoring.</p>'
        + '<p class="cbt-lede">Leaving the examination tab/window, minimizing the browser, switching to another application, or exiting fullscreen may automatically submit your examination.</p>'
        + '<p class="cbt-lede">Make sure you are ready before starting. Once the examination begins, leaving the examination screen may result in automatic submission.</p>'
        + (exam.can_resume ? '' : '<label class="cbt-check" style="display:flex;gap:0.5rem;align-items:flex-start;margin-top:0.75rem;">'
          + '<input type="checkbox" data-cbt-policy-ack>'
          + '<span>I understand that leaving this examination screen may automatically submit my attempt.</span></label>')
        + '</div>'
        + '<div class="cbt-actions">'
        + '<a class="cbt-btn cbt-btn-ghost" href="/cbt">Back to desk</a>'
        + '<button type="button" class="cbt-btn cbt-btn-primary" data-cbt-start' + disabled + '>' + startLabel + '</button>'
        + '</div>';

      const startBtn = panel.querySelector("[data-cbt-start]");
      if (!startBtn) return;
      const ack = panel.querySelector("[data-cbt-policy-ack]");
      if (ack && !exam.can_resume) {
        startBtn.disabled = true;
        ack.addEventListener("change", function () {
          startBtn.disabled = !ack.checked || disabled === " disabled";
        });
      }

      startBtn.addEventListener("click", function () {
        if (startLocked) return;
        if (ack && !exam.can_resume && !ack.checked) {
          showAlert("Acknowledge the examination monitoring policy before starting.");
          return;
        }
        if (exam.can_resume && exam.active_attempt_id) {
          window.location.href = "/cbt/attempts/" + exam.active_attempt_id;
          return;
        }

        startLocked = true;
        startBtn.disabled = true;
        startBtn.textContent = "Starting…";
        request("/api/v1/cbt/exams/" + examId + "/attempts", {
          method: "POST",
          body: JSON.stringify({})
        }).then(function (startResult) {
          if (!startResult.ok) {
            startLocked = false;
            startBtn.disabled = false;
            startBtn.textContent = startLabel;
            showAlert(firstError(startResult.body));
            return;
          }
          const attempt = startResult.body.data || {};
          window.location.href = "/cbt/attempts/" + attempt.id;
        });
      });
    });
  };

  const answeredCount = function () {
    return Object.keys(attemptState.answers).filter(function (key) {
      return attemptState.answers[key] != null;
    }).length;
  };

  const formatClock = function (totalSeconds) {
    const seconds = Math.max(0, Math.floor(totalSeconds || 0));
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    const pad = function (n) { return String(n).padStart(2, "0"); };
    return h > 0 ? (h + ":" + pad(m) + ":" + pad(s)) : (pad(m) + ":" + pad(s));
  };

  const stopTimer = function () {
    if (attemptState.timerId) {
      window.clearInterval(attemptState.timerId);
      attemptState.timerId = null;
    }
  };

  const renderResultPanel = function (result) {
    const panel = document.querySelector("[data-cbt-result-panel]");
    const root = document.querySelector("[data-cbt-attempt-root]");
    if (root) root.hidden = true;
    if (!panel) return;
    panel.hidden = false;
    panel.innerHTML = ''
      + '<div class="cbt-result-hero">'
      + '<span class="cbt-muted">Server result</span>'
      + '<strong>' + (result.details_unlocked === false
        ? 'Result recorded'
        : escapeHtml(result.score) + ' / ' + escapeHtml(result.max_score)) + '</strong>'
      + '<span>' + (result.details_unlocked === false
        ? escapeHtml(result.access_message || 'Purchase a Result Checker to view detailed scores.')
        : (escapeHtml(result.percentage) + '% · Grade ' + escapeHtml(result.grade || '—')
          + ' · ' + (result.passed ? 'Passed' : 'Not passed'))) + '</span>'
      + '</div>'
      + '<div class="cbt-actions">'
      + '<a class="cbt-btn cbt-btn-primary" href="/cbt/results">View result history</a>'
      + '<a class="cbt-btn cbt-btn-ghost" href="/cbt">Back to desk</a>'
      + '</div>';
  };

  const setReadOnly = function () {
    document.querySelectorAll("[data-cbt-submit], [data-cbt-prev], [data-cbt-next], [data-cbt-clear]").forEach(function (btn) {
      btn.disabled = true;
    });
    document.querySelectorAll(".cbt-option input").forEach(function (input) {
      input.disabled = true;
    });
  };

  const triggerOfflineSync = function (statusMessage) {
    if (!window.CbtOfflineSync || !attemptState.attempt || attemptState.attempt.status !== "in_progress") {
      return;
    }
    if (statusMessage) setSaveState(statusMessage);
    window.CbtOfflineSync.start(attemptState.attempt, function (status) {
      if (!status) return;
      if (status.state === "synced") {
        setSaveState("Synced with server");
        updateConnectivityUi("online");
      } else if (status.state === "syncing") {
        setSaveState(status.message || "Synchronizing answers…");
      } else if (status.state === "offline") {
        updateConnectivityUi("offline");
        setSaveState("Saved on this device");
      } else if (status.state === "retrying" || status.state === "pending") {
        setSaveState(status.message || "Saved on this device");
      }
    });
  };

  const flushAnswer = function (questionId) {
    const optionId = attemptState.answers[questionId];
    const attemptId = attemptState.attempt && attemptState.attempt.id;
    if (!attemptId) return Promise.resolve();

    if (attemptState.expiredLocally) {
      setSaveState("Time expired — answers locked");
      return Promise.resolve(false);
    }

    if (!navigator.onLine) {
      setSaveState("Saved on this device");
      updateConnectivityUi("offline");
      attemptState.pending[questionId] = true;
      return Promise.resolve(false);
    }

    setSaveState("Saving to server…");
    setConnection("warn", "Online — saving…");

    return request("/api/v1/cbt/attempts/" + attemptId + "/answers", {
      method: "POST",
      body: JSON.stringify({
        exam_question_id: Number(questionId),
        selected_exam_option_id: optionId,
        client_answered_at: new Date().toISOString()
      })
    }).then(function (result) {
      if (!result.ok) {
        setSaveState(attemptState.offlineReady ? "Saved on this device (server pending)" : "Not saved");
        setConnection("bad", "Server save failed");
        if (result.status !== 0) showAlert(firstError(result.body));
        attemptState.pending[questionId] = true;
        triggerOfflineSync();
        return false;
      }
      delete attemptState.pending[questionId];
      setSaveState("Saved to server");
      updateConnectivityUi("online");
      showAlert("");
      if (offlineEnabled() && attemptState.attempt.uuid) {
        window.CbtOfflineSession.markAnswerServerSynced(
          attemptState.attempt.uuid,
          questionId,
          optionId,
          (result.body.data && result.body.data.answered_at) || null
        );
      }
      return true;
    });
  };

  const persistLocalAnswer = function (questionId) {
    if (!offlineEnabled() || !attemptState.attempt || !attemptState.attempt.uuid) {
      return Promise.reject(new Error("INDEXEDDB_UNAVAILABLE"));
    }
    return window.CbtOfflineSession.saveAnswerLocally(
      attemptState.attempt.uuid,
      questionId,
      attemptState.answers[questionId]
    );
  };

  const queueSave = function (questionId) {
    if (attemptState.saveTimers[questionId]) {
      window.clearTimeout(attemptState.saveTimers[questionId]);
    }
    attemptState.pending[questionId] = true;

    if (attemptState.expiredLocally) {
      setSaveState("Time expired — answers locked");
      return;
    }

    setSaveState("Saving on this device…");
    persistLocalAnswer(questionId).then(function () {
      setSaveState(navigator.onLine ? "Saved on this device — syncing…" : "Saved on this device");
      if (attemptState.channel && attemptState.attempt) {
        attemptState.channel.postMessage({
          type: "cbt-answer-local",
          attemptId: attemptState.attempt.id,
          exam_question_id: Number(questionId),
          selected_exam_option_id: attemptState.answers[questionId],
          local_updated_at: new Date().toISOString()
        });
      }
      if (navigator.onLine) {
        attemptState.saveTimers[questionId] = window.setTimeout(function () {
          flushAnswer(questionId);
        }, 280);
      }
    }).catch(function () {
      attemptState.localUnavailable = true;
      setSaveState("Local save failed");
      showAlert("This browser could not save your answer on this device. Check connectivity or use a supported browser.");
      if (navigator.onLine) {
        attemptState.saveTimers[questionId] = window.setTimeout(function () {
          flushAnswer(questionId);
        }, 280);
      }
    });
  };

  const flushAllPending = function () {
    const ids = Object.keys(attemptState.pending);
    return Promise.all(ids.map(function (id) {
      if (attemptState.saveTimers[id]) {
        window.clearTimeout(attemptState.saveTimers[id]);
        delete attemptState.saveTimers[id];
      }
      return flushAnswer(id);
    }));
  };

  const renderQuestion = function () {
    const host = document.querySelector("[data-cbt-question]");
    const qnav = document.querySelector("[data-cbt-qnav]");
    const progress = document.querySelector("[data-cbt-progress]");
    if (!host || !attemptState.exam) return;

    const questions = attemptState.exam.questions || [];
    const question = questions[attemptState.index];
    if (!question) {
      host.innerHTML = '<div class="cbt-empty">No questions available.</div>';
      return;
    }

    const selected = attemptState.answers[question.id];
    const readOnly = attemptState.attempt.status !== "in_progress" || attemptState.expiredLocally;

    host.innerHTML = ''
      + '<div class="cbt-question-head">'
      + '<span>Question ' + (attemptState.index + 1) + ' of ' + questions.length + '</span>'
      + '<span>' + escapeHtml(String(question.marks)) + ' mark(s)</span>'
      + '</div>'
      + '<p class="cbt-stem">' + escapeHtml(question.stem) + '</p>'
      + '<div class="cbt-options">'
      + (question.options || []).map(function (option) {
        const isSelected = Number(selected) === Number(option.id);
        return '<label class="cbt-option' + (isSelected ? ' is-selected' : '') + '">'
          + '<input type="radio" name="cbt-option" value="' + escapeHtml(String(option.id)) + '"'
          + (isSelected ? ' checked' : '')
          + (readOnly ? ' disabled' : '') + '>'
          + '<span><strong>' + escapeHtml(option.label || '') + '</strong> '
          + escapeHtml(option.body) + '</span></label>';
      }).join("")
      + '</div>';

    if (qnav) {
      qnav.innerHTML = questions.map(function (item, index) {
        const classes = [];
        if (index === attemptState.index) classes.push("is-current");
        if (attemptState.answers[item.id] != null) classes.push("is-answered");
        return '<button type="button" data-cbt-jump="' + index + '" class="' + classes.join(" ") + '">' + (index + 1) + '</button>';
      }).join("");

      qnav.querySelectorAll("[data-cbt-jump]").forEach(function (button) {
        button.addEventListener("click", function () {
          attemptState.index = Number(button.getAttribute("data-cbt-jump")) || 0;
          renderQuestion();
        });
      });
    }

    if (progress) {
      progress.textContent = answeredCount() + " of " + questions.length + " answered";
    }

    host.querySelectorAll('input[name="cbt-option"]').forEach(function (input) {
      input.addEventListener("change", function () {
        if (readOnly || attemptState.expiredLocally) return;
        const optionId = Number(input.value);
        attemptState.answers[question.id] = optionId;
        renderQuestion();
        queueSave(question.id);
      });
    });

    if (offlineEnabled() && attemptState.attempt && attemptState.attempt.uuid) {
      window.CbtOfflineSession.persistQuestionIndex(attemptState.attempt.uuid, attemptState.index);
    }
  };

  const tickTimer = function () {
    const timer = document.querySelector("[data-cbt-timer]");
    if (!timer || !attemptState.attempt || !attemptState.attempt.ends_at) return;

    const ends = new Date(attemptState.attempt.ends_at).getTime();
    const nowEstimate = offlineEnabled()
      ? window.CbtOfflineSession.estimatedServerNow(attemptState.clockOffsetMs)
      : Date.now();
    const remaining = Math.floor((ends - nowEstimate) / 1000);
    timer.textContent = formatClock(remaining);
    timer.classList.toggle("is-urgent", remaining <= 60);

    if (remaining <= 0 && attemptState.attempt.status === "in_progress" && !attemptState.submitting) {
      attemptState.expiredLocally = true;
      setReadOnly();
      setSaveState("Time expired — answers locked");
      stopTimer();
      if (navigator.onLine) {
        submitAttempt(true);
      } else {
        showAlert("Time is up. Your answers are saved on this device. Reconnect to submit to the server.", "info");
      }
    }
  };

  const openModal = function (summary) {
    const modal = document.querySelector("[data-cbt-modal]");
    const copy = document.querySelector("[data-cbt-modal-copy]");
    if (copy) copy.textContent = summary;
    if (modal) modal.classList.add("is-open");
  };

  const closeModal = function () {
    const modal = document.querySelector("[data-cbt-modal]");
    if (modal) modal.classList.remove("is-open");
  };

  const handleAutoSubmitExit = function (info) {
    attemptState.submitting = true;
    attemptState.expiredLocally = true;
    stopTimer();
    setReadOnly();
    closeModal();
    if (window.CbtExamGuard) window.CbtExamGuard.stop();
    if (window.CbtOfflineSync && attemptState.attempt) {
      window.CbtOfflineSync.stop(attemptState.attempt.id);
    }

    if (info && info.serverAck && info.result) {
      attemptState.attempt.status = "submitted";
      attemptState.attempt.result = info.result;
      attemptState.attempt.submission_reason = info.result.submission_reason || "auto_submitted_exam_exit";
      setSaveState("Submitted automatically");
      showAlert(info.message || "Your examination has been submitted.", "info");
      renderResultPanel(info.result);
      return;
    }

    setSaveState("Exit recorded on this device");
    showAlert(info && info.message
      ? info.message
      : "Examination submitted automatically because the examination window was left.", "info");
    const host = document.querySelector("[data-cbt-question]");
    if (host) {
      host.innerHTML = '<div class="cbt-empty">'
        + escapeHtml(info && info.serverAck
          ? "Your examination has been submitted."
          : "Examination exit recorded. Answers remain on this device until the server acknowledges submission.")
        + '</div>';
    }
  };

  const startExamMonitoring = function () {
    if (!window.CbtExamGuard || !attemptState.attempt || attemptState.attempt.status !== "in_progress") {
      return;
    }
    window.CbtExamGuard.start(attemptState.attempt, {
      onStatus: function (status) {
        if (!status) return;
        if (status.state === "autoSubmitting") {
          setSaveState("Submitting…");
          setReadOnly();
          showAlert(status.message || "Examination submitted automatically because the examination window was left.");
        } else if (status.state === "monitoring") {
          setSaveState("Exam monitoring active");
        }
      },
      onExit: handleAutoSubmitExit
    });
  };

  const submitAttempt = function (forced, reason) {
    if (attemptState.submitting || !attemptState.attempt) return;
    attemptState.submitting = true;
    setSaveState("Submitting…");
    if (window.CbtExamGuard) window.CbtExamGuard.stop();

    const submitReason = reason || (forced ? "timer_expired" : "student_manual");

    flushAllPending().then(function () {
      return request("/api/v1/cbt/attempts/" + attemptState.attempt.id + "/submit", {
        method: "POST",
        body: JSON.stringify({
          reason: submitReason,
          client_submitted_at: new Date().toISOString()
        })
      });
    }).then(function (result) {
      if (!result.ok) {
        attemptState.submitting = false;
        showAlert(firstError(result.body));
        setSaveState("Submit failed");
        if (forced) {
          window.setTimeout(function () { window.location.reload(); }, 1200);
        } else if (attemptState.attempt.status === "in_progress") {
          startExamMonitoring();
        }
        return;
      }

      const payload = result.body.data || {};
      attemptState.attempt.status = "submitted";
      attemptState.attempt.result = payload;
      attemptState.attempt.submission_reason = payload.submission_reason || submitReason;
      stopTimer();
      setReadOnly();
      closeModal();
      setSaveState("Submitted");
      if (attemptState.channel) {
        attemptState.channel.postMessage({ type: "cbt-submitted", attemptId: attemptState.attempt.id });
      }
      renderResultPanel(payload);
    });
  };

  const bindAttemptControls = function () {
    const prev = document.querySelector("[data-cbt-prev]");
    const next = document.querySelector("[data-cbt-next]");
    const clear = document.querySelector("[data-cbt-clear]");
    const submit = document.querySelector("[data-cbt-submit]");
    const cancel = document.querySelector("[data-cbt-modal-cancel]");
    const confirm = document.querySelector("[data-cbt-modal-confirm]");

    if (prev) prev.addEventListener("click", function () {
      flushAllPending();
      attemptState.index = Math.max(0, attemptState.index - 1);
      renderQuestion();
    });

    if (next) next.addEventListener("click", function () {
      flushAllPending();
      const max = (attemptState.exam.questions || []).length - 1;
      attemptState.index = Math.min(max, attemptState.index + 1);
      renderQuestion();
    });

    if (clear) clear.addEventListener("click", function () {
      if (attemptState.attempt.status !== "in_progress" || attemptState.expiredLocally) return;
      const question = (attemptState.exam.questions || [])[attemptState.index];
      if (!question) return;
      attemptState.answers[question.id] = null;
      renderQuestion();
      queueSave(question.id);
    });

    if (submit) submit.addEventListener("click", function () {
      if (attemptState.attempt.status !== "in_progress" || attemptState.submitting || attemptState.expiredLocally) return;
      const total = (attemptState.exam.questions || []).length;
      const answered = answeredCount();
      openModal("You have answered " + answered + " of " + total + " questions. Unanswered questions score zero. Submit now?");
    });

    if (cancel) cancel.addEventListener("click", closeModal);
    if (confirm) confirm.addEventListener("click", function () {
      if (attemptState.submitting) return;
      confirm.disabled = true;
      submitAttempt(false);
    });
  };

  const hydrateAnswers = function (attempt) {
    const map = {};
    (attempt.answers || []).forEach(function (answer) {
      map[answer.exam_question_id] = answer.selected_exam_option_id;
    });
    attemptState.answers = map;
  };

  const applyAttemptPayload = function (attempt, options) {
    options = options || {};
    attemptState.attempt = attempt;
    attemptState.exam = attempt.exam || {};
    if (attempt.server_now) {
      attemptState.clockOffsetMs = offlineEnabled()
        ? window.CbtOfflineSession.computeClockOffset(attempt.server_now)
        : 0;
    }

    const title = document.querySelector("[data-cbt-exam-title]");
    const subject = document.querySelector("[data-cbt-exam-subject]");
    if (title) title.textContent = attemptState.exam.title || "Examination";
    if (subject) {
      subject.textContent = (attemptState.exam.subject && attemptState.exam.subject.name)
        || attemptState.exam.subject
        || "CBT";
    }

    if (typeof options.questionIndex === "number") {
      attemptState.index = options.questionIndex;
    }

    if (attempt.status === "submitted") {
      stopTimer();
      setReadOnly();
      if (window.CbtExamGuard) window.CbtExamGuard.stop();
      if (attempt.result) renderResultPanel(attempt.result);
      else showAlert("This attempt has already been submitted.", "info");
      return;
    }

    renderQuestion();
    tickTimer();
    if (!attemptState.timerId) {
      attemptState.timerId = window.setInterval(tickTimer, 1000);
    }
    startExamMonitoring();
  };

  const cacheOfflinePackage = function (attemptId) {
    if (!offlineEnabled()) {
      attemptState.localUnavailable = true;
      showAlert("This browser cannot provide offline exam protection. Please use a supported modern browser.", "info");
      return Promise.resolve(false);
    }

    return request("/api/v1/cbt/attempts/" + attemptId + "/offline-package").then(function (result) {
      if (!result.ok) return false;
      const pkg = result.body.data || {};
      return window.CbtOfflineSession.cachePackage(pkg).then(function (meta) {
        attemptState.offlineReady = true;
        if (meta && typeof meta.clock_offset_ms === "number") {
          attemptState.clockOffsetMs = meta.clock_offset_ms;
        }
        return true;
      }).catch(function () {
        attemptState.localUnavailable = true;
        showAlert("This browser cannot provide offline exam protection. Please use a supported modern browser.", "info");
        return false;
      });
    });
  };

  const restoreFromLocal = function (attemptId) {
    if (!offlineEnabled()) return Promise.resolve(null);
    return window.CbtOfflineSession.loadLocalSession(attemptId).then(function (local) {
      if (!local || !local.packageRow || !local.packageRow.package) return null;
      const pkg = local.packageRow.package;
      const merged = window.CbtOfflineSession.mergeAnswers(pkg.answers || [], local.answers || []);
      const attempt = Object.assign({}, pkg.attempt, {
        exam: pkg.exam,
        answers: pkg.answers || []
      });
      if (local.attemptRow && local.attemptRow.ends_at) {
        attempt.ends_at = local.attemptRow.ends_at;
      }
      if (local.attemptRow && typeof local.attemptRow.clock_offset_ms === "number") {
        attemptState.clockOffsetMs = local.attemptRow.clock_offset_ms;
      }
      attemptState.answers = merged.answers;
      attemptState.answerMeta = merged.meta;
      attemptState.offlineReady = true;
      applyAttemptPayload(attempt, { questionIndex: local.questionIndex || 0 });
      updateConnectivityUi(navigator.onLine ? "reconnecting" : "offline");
      setSaveState("Restored from this device");
      showAlert("Loaded your exam from this device. Your answers are saved locally.", "info");
      if (navigator.onLine) triggerOfflineSync();
      return attempt;
    }).catch(function () {
      return null;
    });
  };

  const mergeLocalOverServer = function (attempt) {
    if (!offlineEnabled() || !attempt.uuid) {
      hydrateAnswers(attempt);
      return Promise.resolve(attempt);
    }
    return window.CbtOfflineSession.loadLocalSession(attempt.id).then(function (local) {
      if (!local) {
        hydrateAnswers(attempt);
        return attempt;
      }
      const merged = window.CbtOfflineSession.mergeAnswers(attempt.answers || [], local.answers || []);
      attemptState.answers = merged.answers;
      attemptState.answerMeta = merged.meta;
      if (typeof local.questionIndex === "number") {
        attemptState.index = local.questionIndex;
      }
      Object.keys(merged.meta || {}).forEach(function (qid) {
        if (merged.meta[qid].sync_state === "saved_locally") {
          attemptState.pending[qid] = true;
        }
      });
      return attempt;
    }).catch(function () {
      hydrateAnswers(attempt);
      return attempt;
    });
  };

  const renderAttempt = function () {
    const attemptId = pathId("attempts");
    if (!attemptId) return;

    bindAttemptControls();

    if (window.BroadcastChannel) {
      attemptState.channel = new BroadcastChannel("srs-cbt-attempt");
      attemptState.channel.onmessage = function (event) {
        if (!event.data) return;
        if ((event.data.type === "cbt-submitted" || event.data.type === "attempt_auto_submitted")
          && String(event.data.attemptId) === String(attemptId)) {
          if (event.data.type === "attempt_auto_submitted") {
            handleAutoSubmitExit({
              serverAck: !!event.data.serverAck,
              fromBroadcast: true,
              result: event.data.result || null,
              message: event.data.message || "Your examination has been submitted."
            });
            return;
          }
          window.location.reload();
          return;
        }
        if (event.data.type === "cbt-answer-local" && String(event.data.attemptId) === String(attemptId)) {
          const qid = event.data.exam_question_id;
          const at = event.data.local_updated_at || "";
          const existing = attemptState.answerMeta[qid];
          if (!existing || at >= (existing.at || "")) {
            attemptState.answers[qid] = event.data.selected_exam_option_id;
            attemptState.answerMeta[qid] = {
              selected: event.data.selected_exam_option_id,
              at: at,
              sync_state: "saved_locally"
            };
            attemptState.pending[qid] = true;
            renderQuestion();
          }
        }
      };
    }

    const startLivePolling = function () {
      window.setInterval(function () {
        if (!navigator.onLine) return;
        if (attemptState.submitting || !attemptState.attempt || attemptState.attempt.status !== "in_progress") return;
        request("/api/v1/cbt/attempts/" + attemptId).then(function (check) {
          if (!check.ok) return;
          const fresh = check.body.data || {};
          if (fresh.status === "submitted") {
            window.location.reload();
          } else if (fresh.ends_at) {
            attemptState.attempt.ends_at = fresh.ends_at;
            attemptState.attempt.server_now = fresh.server_now;
            if (fresh.server_now && offlineEnabled()) {
              attemptState.clockOffsetMs = window.CbtOfflineSession.computeClockOffset(fresh.server_now);
            }
            if (offlineEnabled() && attemptState.attempt.uuid) {
              window.CbtOfflineSession.updateCachedEndsAt(
                attemptState.attempt.uuid,
                fresh.ends_at,
                fresh.server_now
              );
            }
          }
        });
      }, 15000);
    };

    request("/api/v1/cbt/attempts/" + attemptId).then(function (result) {
      if (!result.ok) {
        return restoreFromLocal(attemptId).then(function (restored) {
          if (!restored) {
            showAlert(firstError(result.body));
            document.querySelector("[data-cbt-question]").innerHTML = '<div class="cbt-empty">Unable to load this attempt.</div>';
            return;
          }
          startLivePolling();
        });
      }

      const attempt = result.body.data || {};
      return mergeLocalOverServer(attempt).then(function () {
        applyAttemptPayload(attempt);
        if (attempt.status === "in_progress") {
          cacheOfflinePackage(attemptId).then(function () {
            triggerOfflineSync();
          });
          if (navigator.onLine) flushAllPending();
        }
        startLivePolling();
      });
    }).catch(function () {
      restoreFromLocal(attemptId).then(function (restored) {
        if (!restored) {
          showAlert("Unable to load this attempt.");
          document.querySelector("[data-cbt-question]").innerHTML = '<div class="cbt-empty">Unable to load this attempt.</div>';
        } else {
          triggerOfflineSync();
        }
      });
    });
  };

  const renderResults = function () {
    const list = document.querySelector("[data-cbt-results-list]");
    if (!list) return;

    loadMeLabel();
    request("/api/v1/cbt/results").then(function (result) {
      if (!result.ok) {
        list.innerHTML = '<div class="cbt-empty">Unable to load results.</div>';
        showAlert(firstError(result.body));
        return;
      }

      const data = result.body.data || {};
      const rows = data.results || [];
      const pricing = data.pricing || {};
      if (!rows.length) {
        list.innerHTML = '<div class="cbt-empty">No CBT results yet.</div>';
        return;
      }

      list.innerHTML = rows.map(function (row) {
        if (!row.details_unlocked && !row.result_unlocked) {
          return '<article class="cbt-card">'
            + '<h3>RESULT AVAILABLE</h3>'
            + '<strong>' + escapeHtml(row.exam_title || ("Exam #" + row.exam_id)) + '</strong>'
            + '<p class="cbt-lede">' + escapeHtml(row.subject || "")
            + (row.academic_session || row.term
              ? ' · ' + escapeHtml([row.academic_session, row.term].filter(Boolean).join(" "))
              : "")
            + '</p>'
            + '<p class="cbt-lede">Your result has been processed. Detailed result access requires a Result Checker'
            + (pricing.amount_label ? ' (' + escapeHtml(pricing.amount_label) + ')' : '') + '.</p>'
            + '<div class="cbt-actions">'
            + '<button type="button" class="cbt-btn cbt-btn-primary" data-unlock-result="' + row.id + '">Unlock Detailed Result</button>'
            + '</div></article>';
        }
        return '<article class="cbt-card">'
          + '<h3>RESULT UNLOCKED</h3>'
          + '<strong>' + escapeHtml(row.exam_title || ("Exam #" + row.exam_id)) + '</strong>'
          + '<div class="cbt-meta">'
          + '<span>' + escapeHtml(row.subject || "Subject") + '</span>'
          + '<span>' + escapeHtml(formatWhen(row.submitted_at || row.marked_at)) + '</span>'
          + '<span>Score: ' + escapeHtml(row.score) + ' / ' + escapeHtml(row.max_score) + '</span>'
          + '<span>Percentage: ' + escapeHtml(row.percentage) + '%</span>'
          + '<span>Grade: ' + escapeHtml(row.grade || "—") + '</span>'
          + '<span>Status: ' + (row.passed ? "PASSED" : "NOT PASSED") + '</span>'
          + '</div>'
          + '<div class="cbt-actions" style="margin-top:0.75rem">'
          + '<a class="cbt-btn cbt-btn-primary" href="/cbt/results/' + row.id + '">View Detailed Result</a>'
          + '</div></article>';
      }).join("");
    });

    list.addEventListener("click", function (event) {
      const id = event.target.getAttribute("data-unlock-result");
      if (!id) return;
      event.target.disabled = true;
      event.target.textContent = "Starting checkout…";
      request("/api/v1/cbt/results/" + id + "/unlock", {
        method: "POST",
        body: JSON.stringify({ amount: 100 })
      }).then(function (purchaseResult) {
        if (!purchaseResult.ok) {
          event.target.disabled = false;
          event.target.textContent = "Unlock Detailed Result";
          showAlert(firstError(purchaseResult.body));
          return;
        }
        const data = purchaseResult.body.data || {};
        if (data.unlocked) {
          window.location.href = "/cbt/results/" + id;
          return;
        }
        const url = data.authorization_url;
        if (!url) {
          showAlert("Checkout URL missing.");
          return;
        }
        window.location.href = url;
      });
    });
  };

  const renderResultDetail = function () {
    const host = document.querySelector("[data-result-detail]");
    if (!host) return;
    loadMeLabel();
    const parts = window.location.pathname.split("/").filter(Boolean);
    const id = parts[parts.length - 1];
    const startUnlock = function (button) {
      if (button) {
        button.disabled = true;
        button.textContent = "Starting checkout…";
      }
      request("/api/v1/cbt/results/" + id + "/unlock", {
        method: "POST",
        body: JSON.stringify({ amount: 100 })
      }).then(function (started) {
        if (!started.ok) {
          if (button) {
            button.disabled = false;
            button.textContent = "Unlock Detailed Result";
          }
          return showAlert(firstError(started.body));
        }
        const data = started.body.data || {};
        if (data.unlocked) {
          window.location.reload();
          return;
        }
        if (!data.authorization_url) return showAlert("Checkout URL missing.");
        window.location.href = data.authorization_url;
      });
    };
    request("/api/v1/cbt/results/" + id + "/detailed").then(function (result) {
      if (!result.ok) {
        if (result.status === 402) {
          request("/api/v1/cbt/results/" + id + "/access").then(function (accessResult) {
            const pricing = (accessResult.body.data && accessResult.body.data.pricing) || {};
            host.innerHTML = '<h1>RESULT AVAILABLE</h1>'
              + '<p class="cbt-lede">Your result has been processed. Detailed result access requires a Result Checker'
              + (pricing.amount_label ? ' (' + escapeHtml(pricing.amount_label) + ')' : '') + '.</p>'
              + '<button type="button" class="cbt-btn cbt-btn-primary" data-unlock-detail>Unlock Detailed Result</button>';
            host.querySelector("[data-unlock-detail]").addEventListener("click", function (event) {
              startUnlock(event.target);
            });
          });
          return;
        }
        host.innerHTML = '<div class="cbt-empty">Unable to load detailed result.</div>';
        showAlert(firstError(result.body));
        return;
      }
      const row = result.body.data.result || {};
      const payment = result.body.data.payment || {};
      const perf = row.performance || {};
      host.innerHTML = '<h1>RESULT UNLOCKED</h1>'
        + '<strong>' + escapeHtml(row.exam_title || "CBT result") + '</strong>'
        + '<div class="cbt-meta">'
        + '<span>' + escapeHtml(row.student_name || "") + '</span>'
        + '<span>' + escapeHtml(row.admission_number || "") + '</span>'
        + '<span>' + escapeHtml(row.subject || "") + '</span>'
        + '<span>' + escapeHtml(row.academic_session || "") + '</span>'
        + '<span>' + escapeHtml(row.term || "") + '</span>'
        + '</div>'
        + '<div class="cbt-stat-grid" style="margin:1rem 0">'
        + '<div class="cbt-stat"><strong>' + escapeHtml(row.score) + ' / ' + escapeHtml(row.max_score) + '</strong><span>Score</span></div>'
        + '<div class="cbt-stat"><strong>' + escapeHtml(row.percentage) + '%</strong><span>Percentage</span></div>'
        + '<div class="cbt-stat"><strong>' + escapeHtml(row.grade || "—") + '</strong><span>Grade</span></div>'
        + '<div class="cbt-stat"><strong>' + (row.passed ? "PASSED" : "NOT PASSED") + '</strong><span>Status</span></div>'
        + '</div>'
        + '<p class="cbt-lede">Attempted ' + escapeHtml(perf.attempted || 0)
        + ' · Correct ' + escapeHtml(perf.correct || 0)
        + ' · Incorrect ' + escapeHtml(perf.incorrect || 0)
        + ' · Unanswered ' + escapeHtml(perf.unanswered || 0) + '</p>'
        + (payment.reference ? '<p class="cbt-muted">Payment reference: <strong>' + escapeHtml(payment.reference) + '</strong></p>' : '')
        + '<div class="cbt-actions"><button type="button" class="cbt-btn" onclick="window.print()">Print result</button>'
        + '<a class="cbt-btn" href="/cbt/results">Back</a></div>';
    });
  };

  const renderPaymentStatus = function () {
    const host = document.querySelector("[data-payment-status]");
    if (!host) return;
    const params = new URLSearchParams(window.location.search);
    const hint = params.get("status") || "unknown";
    const reference = params.get("reference") || "";
    host.innerHTML = '<h1>Result Checker</h1>'
      + '<p class="cbt-lede">Payment received. Checking payment status…</p>'
      + (reference ? '<p class="cbt-muted">Reference: ' + escapeHtml(reference) + '</p>' : '')
      + '<div class="cbt-actions"><a class="cbt-btn cbt-btn-primary" href="/cbt/results">Open results</a></div>';

    if (!reference) {
      host.querySelector(".cbt-lede").textContent = "Payment reference missing.";
      return;
    }

    request("/api/v1/payments/transactions/" + encodeURIComponent(reference) + "/verify", {
      method: "POST",
      body: "{}"
    }).then(function (result) {
      const payment = result.body.data && result.body.data.payment;
      if (!result.ok || !payment) {
        host.querySelector(".cbt-lede").textContent = "Payment received. Your result is being unlocked. Please refresh shortly.";
        return;
      }
      if (payment.status === "paid") {
        host.querySelector(".cbt-lede").textContent = "Payment successful. Your detailed CBT result is unlocked.";
        showAlert("Payment verified by the server.", "info");
      } else if (payment.status === "failed" || payment.status === "cancelled") {
        host.querySelector(".cbt-lede").textContent = "Payment failed or could not be verified. You can try again from Results.";
      } else {
        host.querySelector(".cbt-lede").textContent = "Payment received. Your result is being unlocked. Please refresh shortly.";
      }
      if (hint === "success" && payment.status !== "paid") {
        // Browser hint is ignored for unlock — server status wins.
      }
    });
  };

  if (page === "desk") renderDesk();
  if (page === "exam") renderExam();
  if (page === "attempt") renderAttempt();
  if (page === "results") renderResults();
  if (page === "result-detail") renderResultDetail();
  if (page === "result-checker-success") renderPaymentStatus();
})();
