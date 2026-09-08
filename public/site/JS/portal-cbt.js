(function () {
  const page = document.body.getAttribute("data-cbt-page") || "";
  if (!page) return;

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
    if (connLabel) connLabel.textContent = detail || (state === "ok" ? "Connected" : "Connection problem");
  };

  const setSaveState = function (text) {
    if (saveLabel) saveLabel.textContent = text || "";
  };

  const syncOnline = function () {
    if (navigator.onLine) setConnection("ok", "Connected");
    else setConnection("bad", "Browser reports offline — answers cannot sync until connection returns");
  };

  window.addEventListener("online", syncOnline);
  window.addEventListener("offline", syncOnline);
  syncOnline();

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
          ? '<div class="cbt-muted">Latest: ' + escapeHtml(exam.latest_result.score) + ' / ' + escapeHtml(exam.latest_result.max_score)
            + ' (' + escapeHtml(exam.latest_result.percentage) + '%) · Grade ' + escapeHtml(exam.latest_result.grade || '—') + '</div>'
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
        + '<div class="cbt-actions">'
        + '<a class="cbt-btn cbt-btn-ghost" href="/cbt">Back to desk</a>'
        + '<button type="button" class="cbt-btn cbt-btn-primary" data-cbt-start' + disabled + '>' + startLabel + '</button>'
        + '</div>';

      const startBtn = panel.querySelector("[data-cbt-start]");
      if (!startBtn) return;

      startBtn.addEventListener("click", function () {
        if (startLocked) return;
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

  const attemptState = {
    attempt: null,
    exam: null,
    index: 0,
    answers: {},
    pending: {},
    saveTimers: {},
    timerId: null,
    submitting: false,
    channel: null
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
      + '<strong>' + escapeHtml(result.score) + ' / ' + escapeHtml(result.max_score) + '</strong>'
      + '<span>' + escapeHtml(result.percentage) + '% · Grade ' + escapeHtml(result.grade || '—')
      + ' · ' + (result.passed ? 'Passed' : 'Not passed') + '</span>'
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

  const flushAnswer = function (questionId) {
    const optionId = attemptState.answers[questionId];
    const attemptId = attemptState.attempt && attemptState.attempt.id;
    if (!attemptId) return Promise.resolve();

    setSaveState("Saving…");
    setConnection(navigator.onLine ? "warn" : "bad", navigator.onLine ? "Saving…" : "Connection problem");

    return request("/api/v1/cbt/attempts/" + attemptId + "/answers", {
      method: "POST",
      body: JSON.stringify({
        exam_question_id: Number(questionId),
        selected_exam_option_id: optionId
      })
    }).then(function (result) {
      if (!result.ok) {
        setSaveState("Not saved");
        setConnection("bad", "Save failed");
        showAlert(firstError(result.body));
        attemptState.pending[questionId] = true;
        return false;
      }
      delete attemptState.pending[questionId];
      setSaveState("Saved");
      setConnection("ok", "Connected");
      showAlert("");
      return true;
    });
  };

  const queueSave = function (questionId) {
    if (attemptState.saveTimers[questionId]) {
      window.clearTimeout(attemptState.saveTimers[questionId]);
    }
    attemptState.pending[questionId] = true;
    setSaveState("Saving…");
    attemptState.saveTimers[questionId] = window.setTimeout(function () {
      flushAnswer(questionId);
    }, 280);
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
    const readOnly = attemptState.attempt.status !== "in_progress";

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
        if (readOnly) return;
        const optionId = Number(input.value);
        attemptState.answers[question.id] = optionId;
        renderQuestion();
        queueSave(question.id);
      });
    });
  };

  const tickTimer = function () {
    const timer = document.querySelector("[data-cbt-timer]");
    if (!timer || !attemptState.attempt || !attemptState.attempt.ends_at) return;

    const ends = new Date(attemptState.attempt.ends_at).getTime();
    const remaining = Math.floor((ends - Date.now()) / 1000);
    timer.textContent = formatClock(remaining);
    timer.classList.toggle("is-urgent", remaining <= 60);

    if (remaining <= 0 && attemptState.attempt.status === "in_progress" && !attemptState.submitting) {
      stopTimer();
      submitAttempt(true);
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

  const submitAttempt = function (forced) {
    if (attemptState.submitting || !attemptState.attempt) return;
    attemptState.submitting = true;
    setSaveState("Submitting…");

    flushAllPending().then(function () {
      return request("/api/v1/cbt/attempts/" + attemptState.attempt.id + "/submit", {
        method: "POST",
        body: JSON.stringify({})
      });
    }).then(function (result) {
      if (!result.ok) {
        attemptState.submitting = false;
        showAlert(firstError(result.body));
        setSaveState("Submit failed");
        if (forced) {
          // Keep trying soft recovery by reloading attempt state.
          window.setTimeout(function () { window.location.reload(); }, 1200);
        }
        return;
      }

      const payload = result.body.data || {};
      attemptState.attempt.status = "submitted";
      attemptState.attempt.result = payload;
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
      if (attemptState.attempt.status !== "in_progress") return;
      const question = (attemptState.exam.questions || [])[attemptState.index];
      if (!question) return;
      attemptState.answers[question.id] = null;
      renderQuestion();
      queueSave(question.id);
    });

    if (submit) submit.addEventListener("click", function () {
      if (attemptState.attempt.status !== "in_progress" || attemptState.submitting) return;
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

  const renderAttempt = function () {
    const attemptId = pathId("attempts");
    if (!attemptId) return;

    bindAttemptControls();

    if (window.BroadcastChannel) {
      attemptState.channel = new BroadcastChannel("srs-cbt-attempt");
      attemptState.channel.onmessage = function (event) {
        if (event.data && event.data.type === "cbt-submitted" && String(event.data.attemptId) === String(attemptId)) {
          window.location.reload();
        }
      };
    }

    request("/api/v1/cbt/attempts/" + attemptId).then(function (result) {
      if (!result.ok) {
        showAlert(firstError(result.body));
        document.querySelector("[data-cbt-question]").innerHTML = '<div class="cbt-empty">Unable to load this attempt.</div>';
        return;
      }

      const attempt = result.body.data || {};
      attemptState.attempt = attempt;
      attemptState.exam = attempt.exam || {};
      hydrateAnswers(attempt);

      const title = document.querySelector("[data-cbt-exam-title]");
      const subject = document.querySelector("[data-cbt-exam-subject]");
      if (title) title.textContent = attemptState.exam.title || "Examination";
      if (subject) subject.textContent = (attemptState.exam.subject && attemptState.exam.subject.name) || "CBT";

      if (attempt.status === "submitted") {
        stopTimer();
        setReadOnly();
        if (attempt.result) renderResultPanel(attempt.result);
        else showAlert("This attempt has already been submitted.", "info");
        return;
      }

      renderQuestion();
      tickTimer();
      attemptState.timerId = window.setInterval(tickTimer, 1000);

      // Periodic ownership/status check for multi-tab submit.
      window.setInterval(function () {
        if (attemptState.submitting || attemptState.attempt.status !== "in_progress") return;
        request("/api/v1/cbt/attempts/" + attemptId).then(function (check) {
          if (!check.ok) return;
          const fresh = check.body.data || {};
          if (fresh.status === "submitted") {
            window.location.reload();
          } else if (fresh.ends_at) {
            attemptState.attempt.ends_at = fresh.ends_at;
            attemptState.attempt.server_now = fresh.server_now;
          }
        });
      }, 15000);
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

      const rows = (result.body.data && result.body.data.results) || [];
      if (!rows.length) {
        list.innerHTML = '<div class="cbt-empty">No CBT results yet.</div>';
        return;
      }

      list.innerHTML = rows.map(function (row) {
        return '<article class="cbt-card">'
          + '<h3>' + escapeHtml(row.exam_title || ("Exam #" + row.exam_id)) + '</h3>'
          + '<div class="cbt-meta">'
          + '<span>' + escapeHtml(row.subject || "Subject") + '</span>'
          + '<span>' + escapeHtml(formatWhen(row.submitted_at || row.marked_at)) + '</span>'
          + '<span>' + escapeHtml(row.score) + ' / ' + escapeHtml(row.max_score) + '</span>'
          + '<span>' + escapeHtml(row.percentage) + '%</span>'
          + '<span>Grade ' + escapeHtml(row.grade || "—") + '</span>'
          + '<span>' + (row.passed ? "Passed" : "Not passed") + '</span>'
          + '</div></article>';
      }).join("");
    });
  };

  if (page === "desk") renderDesk();
  if (page === "exam") renderExam();
  if (page === "attempt") renderAttempt();
  if (page === "results") renderResults();
})();
