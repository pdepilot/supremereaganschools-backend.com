/**
 * Phase 10B: CBT offline answer synchronization manager (cbt-offline-sync-v1).
 * "Synced" means the server acknowledged the event — never navigator.onLine alone.
 */
(function (global) {
  "use strict";

  var PROTOCOL = "cbt-offline-sync-v1";
  var BATCH_SIZE = 25;
  var MIN_BACKOFF_MS = 2000;
  var MAX_BACKOFF_MS = 60000;

  var workers = Object.create(null);
  var channel = null;

  function csrfToken() {
    var match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : "";
  }

  function postJson(url, body) {
    return fetch(url, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Accept": "application/json",
        "Content-Type": "application/json",
        "X-Requested-With": "XMLHttpRequest",
        "X-XSRF-TOKEN": csrfToken()
      },
      body: JSON.stringify(body)
    }).then(function (response) {
      return response.json().then(function (json) {
        return { ok: response.ok, status: response.status, body: json };
      }).catch(function () {
        return { ok: false, status: response.status, body: {} };
      });
    });
  }

  function ensureChannel() {
    if (channel || !global.BroadcastChannel) return channel;
    channel = new BroadcastChannel("srs-cbt-attempt");
    channel.onmessage = function (event) {
      if (!event.data || event.data.type !== "cbt-sync-lock") return;
      var key = String(event.data.attemptId || "");
      if (!key || !workers[key]) return;
      if (event.data.owner && event.data.owner !== workers[key].owner) {
        workers[key].externalLockUntil = Date.now() + 8000;
      }
    };
    return channel;
  }

  function announceLock(attemptId, owner) {
    ensureChannel();
    if (channel) {
      channel.postMessage({ type: "cbt-sync-lock", attemptId: attemptId, owner: owner });
    }
  }

  function isPermanent(status) {
    return String(status || "").indexOf("rejected_") === 0;
  }

  function isSuccess(status) {
    return status === "synced" || status === "already_synced";
  }

  function getWorker(attempt) {
    var key = String(attempt.id);
    if (!workers[key]) {
      workers[key] = {
        attempt: attempt,
        running: false,
        timer: null,
        backoffMs: MIN_BACKOFF_MS,
        owner: "tab-" + Math.random().toString(16).slice(2),
        externalLockUntil: 0,
        onStatus: null
      };
    } else {
      workers[key].attempt = attempt;
    }
    return workers[key];
  }

  function schedule(worker, delay) {
    if (worker.timer) {
      clearTimeout(worker.timer);
    }
    worker.timer = setTimeout(function () {
      worker.timer = null;
      CbtOfflineSync.flush(worker.attempt, worker.onStatus);
    }, delay);
  }

  function buildBatch(rows) {
    return rows.slice(0, BATCH_SIZE).map(function (row) {
      return {
        event_id: row.event_id,
        type: "answer",
        attempt_uuid: row.attempt_uuid,
        exam_question_id: row.exam_question_id,
        selected_exam_option_id: row.selected_exam_option_id != null
          ? row.selected_exam_option_id
          : row.selected_option_id,
        client_answered_at: row.client_answered_at || row.local_updated_at,
        local_sequence: row.local_sequence || 0,
        _queue_id: row.id
      };
    });
  }

  function applyResults(attemptUuid, batch, results, store, session) {
    var byEvent = Object.create(null);
    (results || []).forEach(function (result) {
      if (result && result.event_id) byEvent[result.event_id] = result;
    });

    return Promise.all(batch.map(function (event) {
      var ack = byEvent[event.event_id];
      if (!ack) {
        return store.markQueueFailed(event._queue_id, "missing_ack");
      }
      if (isSuccess(ack.status)) {
        return store.markQueueSynced(event._queue_id, ack).then(function () {
          return session.markAnswerServerSynced(
            attemptUuid,
            event.exam_question_id,
            ack.selected_exam_option_id != null ? ack.selected_exam_option_id : event.selected_exam_option_id,
            ack.answered_at || null
          );
        });
      }
      if (isPermanent(ack.status)) {
        return store.markQueueRejected(event._queue_id, ack);
      }
      return store.markQueueFailed(event._queue_id, ack.status || "retryable");
    }));
  }

  var CbtOfflineSync = {
    PROTOCOL: PROTOCOL,
    BATCH_SIZE: BATCH_SIZE,

    start: function (attempt, onStatus) {
      if (!attempt || !attempt.id || !attempt.uuid) return;
      var worker = getWorker(attempt);
      worker.onStatus = onStatus || worker.onStatus;
      ensureChannel();
      this.flush(attempt, worker.onStatus);
      schedule(worker, worker.backoffMs);
    },

    stop: function (attemptId) {
      var worker = workers[String(attemptId)];
      if (!worker) return;
      if (worker.timer) clearTimeout(worker.timer);
      worker.timer = null;
      worker.running = false;
    },

    flush: function (attempt, onStatus) {
      var store = global.CbtOfflineStore;
      var session = global.CbtOfflineSession;
      if (!store || !store.isAvailable() || !session || !attempt || !attempt.id) {
        return Promise.resolve({ synced: 0, pending: 0 });
      }
      if (!navigator.onLine) {
        if (onStatus) onStatus({ state: "offline", message: "Offline — answers saved on this device." });
        return Promise.resolve({ synced: 0, pending: -1 });
      }

      var worker = getWorker(attempt);
      worker.onStatus = onStatus || worker.onStatus;
      if (worker.running) {
        return Promise.resolve({ synced: 0, pending: -1, busy: true });
      }
      if (worker.externalLockUntil > Date.now()) {
        schedule(worker, 3000);
        return Promise.resolve({ synced: 0, pending: -1, locked: true });
      }

      worker.running = true;
      announceLock(attempt.id, worker.owner);
      if (worker.onStatus) worker.onStatus({ state: "syncing", message: "Synchronizing answers…" });

      return store.listQueueByAttempt(attempt.uuid, ["pending", "failed"]).then(function (rows) {
        if (!rows.length) {
          worker.backoffMs = MIN_BACKOFF_MS;
          if (worker.onStatus) worker.onStatus({ state: "idle", message: "All queued answers acknowledged." });
          return { synced: 0, pending: 0 };
        }

        var batch = buildBatch(rows);
        var batchId = (global.crypto && crypto.randomUUID)
          ? crypto.randomUUID()
          : ("batch-" + Date.now());

        return Promise.all(batch.map(function (event) {
          return store.updateQueueItem(event._queue_id, { state: "syncing" });
        })).then(function () {
          return postJson("/api/v1/cbt/attempts/" + attempt.id + "/sync", {
            protocol: PROTOCOL,
            batch_id: batchId,
            events: batch.map(function (event) {
              return {
                event_id: event.event_id,
                type: event.type,
                attempt_uuid: event.attempt_uuid,
                exam_question_id: event.exam_question_id,
                selected_exam_option_id: event.selected_exam_option_id,
                client_answered_at: event.client_answered_at,
                local_sequence: event.local_sequence
              };
            })
          });
        }).then(function (response) {
          if (!response.ok) {
            // Retryable transport/server failure — restore pending/failed.
            return Promise.all(batch.map(function (event) {
              return store.markQueueFailed(event._queue_id, "http_" + response.status);
            })).then(function () {
              worker.backoffMs = Math.min(MAX_BACKOFF_MS, Math.max(MIN_BACKOFF_MS, worker.backoffMs * 2));
              if (worker.onStatus) {
                worker.onStatus({
                  state: "retrying",
                  message: "Server unreachable — answers remain on this device."
                });
              }
              return { synced: 0, pending: rows.length, retryable: true };
            });
          }

          var payload = (response.body && response.body.data) || {};
          return applyResults(attempt.uuid, batch, payload.results || [], store, session).then(function () {
            return store.listQueueByAttempt(attempt.uuid, ["pending", "failed", "syncing"]).then(function (remaining) {
              var syncedCount = batch.length - remaining.filter(function (row) {
                return batch.some(function (event) { return event._queue_id === row.id; });
              }).length;
              worker.backoffMs = remaining.length ? Math.min(MAX_BACKOFF_MS, worker.backoffMs * 2) : MIN_BACKOFF_MS;
              if (worker.onStatus) {
                worker.onStatus({
                  state: remaining.length ? "pending" : "synced",
                  message: remaining.length
                    ? ("Saved on this device · " + remaining.length + " waiting for server ack")
                    : "Synced with server",
                  pending: remaining.length
                });
              }
              if (remaining.length) schedule(worker, worker.backoffMs);
              return { synced: syncedCount, pending: remaining.length };
            });
          });
        }).catch(function () {
          return Promise.all(batch.map(function (event) {
            return store.markQueueFailed(event._queue_id, "network_error");
          })).then(function () {
            worker.backoffMs = Math.min(MAX_BACKOFF_MS, Math.max(MIN_BACKOFF_MS, worker.backoffMs * 2));
            if (worker.onStatus) {
              worker.onStatus({
                state: "retrying",
                message: "Connection problem — answers remain on this device."
              });
            }
            schedule(worker, worker.backoffMs);
            return { synced: 0, pending: rows.length, retryable: true };
          });
        });
      }).finally(function () {
        worker.running = false;
      });
    }
  };

  global.CbtOfflineSync = CbtOfflineSync;
})(window);
