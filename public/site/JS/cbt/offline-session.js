/**
 * High-level offline exam session helpers for Phase 10A.
 * Local persistence first; online autosave remains separate.
 */
(function (global) {
  "use strict";

  var FORBIDDEN = [
    "is_correct",
    "correct_option_id",
    "correct_option",
    "answer_key",
    "correctAnswer",
    "correct_answer",
    "correct",
    "solution",
    "explanation"
  ];

  function deepHasForbidden(node) {
    if (!node || typeof node !== "object") return false;
    if (Array.isArray(node)) {
      for (var i = 0; i < node.length; i += 1) {
        if (deepHasForbidden(node[i])) return true;
      }
      return false;
    }
    var keys = Object.keys(node);
    for (var k = 0; k < keys.length; k += 1) {
      if (FORBIDDEN.indexOf(keys[k]) !== -1) return true;
      if (deepHasForbidden(node[keys[k]])) return true;
    }
    return false;
  }

  function validatePackage(pkg) {
    if (!pkg || !pkg.attempt || !pkg.exam) {
      return { ok: false, reason: "Missing attempt or exam package." };
    }
    if (!pkg.attempt.uuid || !pkg.attempt.ends_at) {
      return { ok: false, reason: "Package missing uuid or authoritative ends_at." };
    }
    if (!pkg.exam.questions || !pkg.exam.questions.length) {
      return { ok: false, reason: "Package has no questions." };
    }
    for (var i = 0; i < pkg.exam.questions.length; i += 1) {
      var q = pkg.exam.questions[i];
      if (!q.options || !q.options.length) {
        return { ok: false, reason: "Question missing options." };
      }
    }
    if (deepHasForbidden(pkg)) {
      return { ok: false, reason: "Package contains forbidden answer-key fields." };
    }
    return { ok: true };
  }

  function computeClockOffset(serverNowIso) {
    var serverMs = new Date(serverNowIso).getTime();
    if (!Number.isFinite(serverMs)) return 0;
    return serverMs - Date.now();
  }

  function estimatedServerNow(clockOffsetMs) {
    return Date.now() + (Number(clockOffsetMs) || 0);
  }

  var CbtOfflineSession = {
    validatePackage: validatePackage,
    computeClockOffset: computeClockOffset,
    estimatedServerNow: estimatedServerNow,

    cachePackage: function (pkg) {
      var store = global.CbtOfflineStore;
      if (!store || !store.isAvailable()) {
        return Promise.reject(new Error("INDEXEDDB_UNAVAILABLE"));
      }
      var check = validatePackage(pkg);
      if (!check.ok) {
        return Promise.reject(new Error(check.reason || "INVALID_PACKAGE"));
      }

      var nowIso = new Date().toISOString();
      var attempt = pkg.attempt;
      var clockOffset = computeClockOffset(attempt.server_now || nowIso);

      return store.putPackage({
        attempt_uuid: attempt.uuid,
        attempt_id: attempt.id,
        exam_id: attempt.exam_id,
        package_version: pkg.package_version || 1,
        package: pkg,
        cached_at: nowIso,
        updated_at: nowIso
      }).then(function () {
        return store.putAttempt({
          attempt_uuid: attempt.uuid,
          attempt_id: attempt.id,
          exam_id: attempt.exam_id,
          started_at: attempt.started_at,
          ends_at: attempt.ends_at,
          status: attempt.status,
          mode: attempt.mode,
          package_version: pkg.package_version || 1,
          clock_offset_ms: clockOffset,
          current_question_index: 0,
          created_at: nowIso,
          updated_at: nowIso
        });
      }).then(function () {
        return store.setMeta("clock_offset:" + attempt.uuid, clockOffset);
      }).then(function () {
        var answers = pkg.answers || [];
        return store.getAnswersForAttempt(attempt.uuid).then(function (existing) {
          var byQ = {};
          (existing || []).forEach(function (row) {
            byQ[row.exam_question_id] = row;
          });
          return Promise.all(answers.map(function (answer) {
            var prior = byQ[answer.exam_question_id];
            if (prior && prior.sync_state === "saved_locally") {
              var serverAt = answer.answered_at || "";
              var localAt = prior.local_updated_at || prior.answered_at || "";
              if (localAt >= serverAt) {
                return null;
              }
            }
            return store.putAnswer({
              attempt_uuid: attempt.uuid,
              exam_question_id: answer.exam_question_id,
              selected_option_id: answer.selected_exam_option_id,
              answered_at: answer.answered_at || nowIso,
              local_updated_at: answer.answered_at || nowIso,
              sync_state: "server_synced"
            });
          }));
        });
      }).then(function () {
        return {
          attempt_uuid: attempt.uuid,
          clock_offset_ms: clockOffset
        };
      });
    },

    saveAnswerLocally: function (attemptUuid, examQuestionId, selectedOptionId) {
      var store = global.CbtOfflineStore;
      if (!store || !store.isAvailable()) {
        return Promise.reject(new Error("INDEXEDDB_UNAVAILABLE"));
      }
      var nowIso = new Date().toISOString();
      var eventId = (global.crypto && crypto.randomUUID)
        ? crypto.randomUUID()
        : ("evt-" + Date.now() + "-" + Math.random().toString(16).slice(2));

      return store.nextLocalSequence(attemptUuid).then(function (localSequence) {
        return store.putAnswer({
          attempt_uuid: attemptUuid,
          exam_question_id: Number(examQuestionId),
          selected_option_id: selectedOptionId == null ? null : Number(selectedOptionId),
          answered_at: nowIso,
          local_updated_at: nowIso,
          sync_state: "saved_locally",
          last_event_id: eventId
        }).then(function () {
          return store.enqueueSync({
            event_id: eventId,
            attempt_uuid: attemptUuid,
            type: "answer",
            exam_question_id: Number(examQuestionId),
            selected_option_id: selectedOptionId == null ? null : Number(selectedOptionId),
            selected_exam_option_id: selectedOptionId == null ? null : Number(selectedOptionId),
            client_answered_at: nowIso,
            local_updated_at: nowIso,
            local_sequence: localSequence,
            state: "pending"
          }).then(function (queueId) {
            return {
              event_id: eventId,
              queue_id: queueId,
              local_sequence: localSequence,
              client_answered_at: nowIso
            };
          }).catch(function () {
            return {
              event_id: eventId,
              queue_id: null,
              local_sequence: localSequence,
              client_answered_at: nowIso
            };
          });
        });
      });
    },

    markAnswerServerSynced: function (attemptUuid, examQuestionId, selectedOptionId, answeredAt) {
      var store = global.CbtOfflineStore;
      if (!store || !store.isAvailable()) return Promise.resolve();
      return store.putAnswer({
        attempt_uuid: attemptUuid,
        exam_question_id: Number(examQuestionId),
        selected_option_id: selectedOptionId == null ? null : Number(selectedOptionId),
        answered_at: answeredAt || new Date().toISOString(),
        local_updated_at: new Date().toISOString(),
        sync_state: "server_synced"
      }).then(function () {
        return store.markPendingForQuestionSynced(attemptUuid, examQuestionId);
      });
    },

    loadLocalSession: function (attemptId) {
      var store = global.CbtOfflineStore;
      if (!store || !store.isAvailable()) {
        return Promise.reject(new Error("INDEXEDDB_UNAVAILABLE"));
      }

      return store.getPackageByAttemptId(attemptId).then(function (pkgRow) {
        if (!pkgRow) return null;
        return Promise.all([
          store.getAttempt(pkgRow.attempt_uuid),
          store.getAnswersForAttempt(pkgRow.attempt_uuid),
          store.getMeta("ui_index:" + pkgRow.attempt_uuid)
        ]).then(function (parts) {
          return {
            packageRow: pkgRow,
            attemptRow: parts[0],
            answers: parts[1] || [],
            questionIndex: typeof parts[2] === "number" ? parts[2] : (parts[0] && parts[0].current_question_index) || 0
          };
        });
      });
    },

    persistQuestionIndex: function (attemptUuid, index) {
      var store = global.CbtOfflineStore;
      if (!store || !store.isAvailable()) return Promise.resolve();
      return store.getAttempt(attemptUuid).then(function (row) {
        if (!row) return null;
        row.current_question_index = index;
        row.updated_at = new Date().toISOString();
        return store.putAttempt(row);
      }).then(function () {
        return store.setMeta("ui_index:" + attemptUuid, index);
      });
    },

    mergeAnswers: function (serverAnswers, localAnswers) {
      var map = {};
      (serverAnswers || []).forEach(function (answer) {
        map[answer.exam_question_id] = {
          selected: answer.selected_exam_option_id,
          at: answer.answered_at || "",
          sync_state: "server_synced"
        };
      });
      (localAnswers || []).forEach(function (answer) {
        var qid = answer.exam_question_id;
        var localAt = answer.local_updated_at || answer.answered_at || "";
        var existing = map[qid];
        if (!existing || localAt >= (existing.at || "")) {
          map[qid] = {
            selected: answer.selected_option_id,
            at: localAt,
            sync_state: answer.sync_state || "saved_locally"
          };
        }
      });
      var out = {};
      Object.keys(map).forEach(function (qid) {
        out[qid] = map[qid].selected;
      });
      return { answers: out, meta: map };
    },

    updateCachedEndsAt: function (attemptUuid, endsAt, serverNow) {
      var store = global.CbtOfflineStore;
      if (!store || !store.isAvailable()) return Promise.resolve();
      return store.getAttempt(attemptUuid).then(function (row) {
        if (!row) return null;
        row.ends_at = endsAt;
        if (serverNow) {
          row.clock_offset_ms = computeClockOffset(serverNow);
        }
        row.updated_at = new Date().toISOString();
        return store.putAttempt(row);
      }).then(function () {
        return store.getPackage(attemptUuid);
      }).then(function (pkgRow) {
        if (!pkgRow || !pkgRow.package || !pkgRow.package.attempt) return null;
        pkgRow.package.attempt.ends_at = endsAt;
        if (serverNow) pkgRow.package.attempt.server_now = serverNow;
        pkgRow.updated_at = new Date().toISOString();
        return store.putPackage(pkgRow);
      });
    }
  };

  global.CbtOfflineSession = CbtOfflineSession;
})(window);
