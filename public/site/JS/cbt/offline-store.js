/**
 * IndexedDB storage layer for CBT offline continuity (Phase 10A).
 * Server remains authoritative — this is continuity only, not tamper-proof.
 */
(function (global) {
  "use strict";

  var DB_NAME = "supreme_reagan_cbt";
  var DB_VERSION = 1;
  var STORE_PACKAGES = "exam_packages";
  var STORE_ATTEMPTS = "attempts";
  var STORE_ANSWERS = "answers";
  var STORE_SYNC_QUEUE = "sync_queue";
  var STORE_METADATA = "metadata";

  var dbPromise = null;

  function openDb() {
    if (dbPromise) return dbPromise;
    if (!global.indexedDB) {
      return Promise.reject(new Error("INDEXEDDB_UNAVAILABLE"));
    }

    dbPromise = new Promise(function (resolve, reject) {
      var request = global.indexedDB.open(DB_NAME, DB_VERSION);
      request.onerror = function () {
        reject(request.error || new Error("INDEXEDDB_OPEN_FAILED"));
      };
      request.onsuccess = function () {
        resolve(request.result);
      };
      request.onupgradeneeded = function (event) {
        var db = event.target.result;
        if (!db.objectStoreNames.contains(STORE_PACKAGES)) {
          db.createObjectStore(STORE_PACKAGES, { keyPath: "attempt_uuid" });
        }
        if (!db.objectStoreNames.contains(STORE_ATTEMPTS)) {
          db.createObjectStore(STORE_ATTEMPTS, { keyPath: "attempt_uuid" });
        }
        if (!db.objectStoreNames.contains(STORE_ANSWERS)) {
          var answers = db.createObjectStore(STORE_ANSWERS, { keyPath: "id" });
          answers.createIndex("by_attempt", "attempt_uuid", { unique: false });
          answers.createIndex("by_attempt_question", ["attempt_uuid", "exam_question_id"], { unique: true });
        }
        if (!db.objectStoreNames.contains(STORE_SYNC_QUEUE)) {
          var queue = db.createObjectStore(STORE_SYNC_QUEUE, { keyPath: "id", autoIncrement: true });
          queue.createIndex("by_attempt", "attempt_uuid", { unique: false });
          queue.createIndex("by_state", "state", { unique: false });
        }
        if (!db.objectStoreNames.contains(STORE_METADATA)) {
          db.createObjectStore(STORE_METADATA, { keyPath: "key" });
        }
      };
    });

    return dbPromise;
  }

  function withStore(storeName, mode, work) {
    return openDb().then(function (db) {
      return new Promise(function (resolve, reject) {
        var tx = db.transaction(storeName, mode);
        var store = tx.objectStore(storeName);
        var result;
        try {
          result = work(store);
        } catch (err) {
          reject(err);
          return;
        }
        tx.oncomplete = function () {
          resolve(result);
        };
        tx.onerror = function () {
          reject(tx.error || new Error("INDEXEDDB_TX_FAILED"));
        };
        if (result && typeof result.onsuccess !== "undefined") {
          result.onsuccess = function () {
            result = result.result;
          };
          result.onerror = function () {
            reject(result.error);
          };
        }
      });
    });
  }

  function reqToPromise(request) {
    return new Promise(function (resolve, reject) {
      request.onsuccess = function () {
        resolve(request.result);
      };
      request.onerror = function () {
        reject(request.error);
      };
    });
  }

  function put(storeName, value) {
    return openDb().then(function (db) {
      return new Promise(function (resolve, reject) {
        var tx = db.transaction(storeName, "readwrite");
        tx.oncomplete = function () {
          resolve(value);
        };
        tx.onerror = function () {
          reject(tx.error);
        };
        tx.objectStore(storeName).put(value);
      });
    });
  }

  function get(storeName, key) {
    return openDb().then(function (db) {
      return reqToPromise(db.transaction(storeName, "readonly").objectStore(storeName).get(key));
    });
  }

  function answerKey(attemptUuid, examQuestionId) {
    return String(attemptUuid) + ":" + String(examQuestionId);
  }

  var CbtOfflineStore = {
    dbName: DB_NAME,
    version: DB_VERSION,
    isAvailable: function () {
      return !!global.indexedDB;
    },
    open: openDb,
    putPackage: function (packageRecord) {
      return put(STORE_PACKAGES, packageRecord);
    },
    getPackage: function (attemptUuid) {
      return get(STORE_PACKAGES, attemptUuid);
    },
    getPackageByAttemptId: function (attemptId) {
      return openDb().then(function (db) {
        return reqToPromise(db.transaction(STORE_PACKAGES, "readonly").objectStore(STORE_PACKAGES).getAll()).then(function (rows) {
          rows = rows || [];
          for (var i = 0; i < rows.length; i += 1) {
            if (String(rows[i].attempt_id) === String(attemptId)) return rows[i];
          }
          return null;
        });
      });
    },
    putAttempt: function (attemptRecord) {
      return put(STORE_ATTEMPTS, attemptRecord);
    },
    getAttempt: function (attemptUuid) {
      return get(STORE_ATTEMPTS, attemptUuid);
    },
    getAttemptById: function (attemptId) {
      return openDb().then(function (db) {
        return reqToPromise(db.transaction(STORE_ATTEMPTS, "readonly").objectStore(STORE_ATTEMPTS).getAll()).then(function (rows) {
          rows = rows || [];
          for (var i = 0; i < rows.length; i += 1) {
            if (String(rows[i].attempt_id) === String(attemptId)) return rows[i];
          }
          return null;
        });
      });
    },
    putAnswer: function (answerRecord) {
      var record = Object.assign({}, answerRecord, {
        id: answerKey(answerRecord.attempt_uuid, answerRecord.exam_question_id)
      });
      return put(STORE_ANSWERS, record);
    },
    getAnswersForAttempt: function (attemptUuid) {
      return openDb().then(function (db) {
        var index = db.transaction(STORE_ANSWERS, "readonly").objectStore(STORE_ANSWERS).index("by_attempt");
        return reqToPromise(index.getAll(attemptUuid));
      });
    },
    enqueueSync: function (item) {
      return openDb().then(function (db) {
        return new Promise(function (resolve, reject) {
          var tx = db.transaction(STORE_SYNC_QUEUE, "readwrite");
          var req = tx.objectStore(STORE_SYNC_QUEUE).add(Object.assign({
            state: item.state || "pending",
            created_at: item.created_at || new Date().toISOString(),
            attempts: item.attempts || 0
          }, item));
          req.onsuccess = function () {
            resolve(req.result);
          };
          req.onerror = function () {
            reject(req.error);
          };
        });
      });
    },
    listQueueByAttempt: function (attemptUuid, states) {
      var allowed = states || ["pending", "syncing", "failed"];
      return openDb().then(function (db) {
        var index = db.transaction(STORE_SYNC_QUEUE, "readonly").objectStore(STORE_SYNC_QUEUE).index("by_attempt");
        return reqToPromise(index.getAll(attemptUuid)).then(function (rows) {
          rows = rows || [];
          return rows.filter(function (row) {
            return allowed.indexOf(row.state) !== -1;
          }).sort(function (a, b) {
            var seq = (a.local_sequence || 0) - (b.local_sequence || 0);
            if (seq !== 0) return seq;
            return String(a.local_updated_at || a.created_at || "").localeCompare(String(b.local_updated_at || b.created_at || ""));
          });
        });
      });
    },
    updateQueueItem: function (id, patch) {
      return openDb().then(function (db) {
        return new Promise(function (resolve, reject) {
          var tx = db.transaction(STORE_SYNC_QUEUE, "readwrite");
          var store = tx.objectStore(STORE_SYNC_QUEUE);
          var getReq = store.get(id);
          getReq.onsuccess = function () {
            var row = getReq.result;
            if (!row) {
              resolve(null);
              return;
            }
            Object.keys(patch || {}).forEach(function (key) {
              row[key] = patch[key];
            });
            row.updated_at = new Date().toISOString();
            store.put(row);
          };
          getReq.onerror = function () {
            reject(getReq.error);
          };
          tx.oncomplete = function () {
            resolve(true);
          };
          tx.onerror = function () {
            reject(tx.error);
          };
        });
      });
    },
    markQueueSynced: function (id, ack) {
      return this.updateQueueItem(id, {
        state: "synced",
        ack_status: (ack && ack.status) || "synced",
        synced_at: new Date().toISOString()
      });
    },
    markQueueRejected: function (id, ack) {
      return this.updateQueueItem(id, {
        state: "rejected",
        ack_status: (ack && ack.status) || "rejected",
        reject_reason: (ack && ack.reason) || null,
        synced_at: new Date().toISOString()
      });
    },
    markQueueFailed: function (id, reason) {
      return openDb().then(function (db) {
        return new Promise(function (resolve, reject) {
          var tx = db.transaction(STORE_SYNC_QUEUE, "readwrite");
          var store = tx.objectStore(STORE_SYNC_QUEUE);
          var getReq = store.get(id);
          getReq.onsuccess = function () {
            var row = getReq.result;
            if (!row) {
              resolve(null);
              return;
            }
            row.state = "failed";
            row.last_error = reason || "retryable_failure";
            row.attempts = (row.attempts || 0) + 1;
            row.updated_at = new Date().toISOString();
            store.put(row);
          };
          getReq.onerror = function () {
            reject(getReq.error);
          };
          tx.oncomplete = function () {
            resolve(true);
          };
          tx.onerror = function () {
            reject(tx.error);
          };
        });
      });
    },
    markPendingForQuestionSynced: function (attemptUuid, examQuestionId) {
      return this.listQueueByAttempt(attemptUuid, ["pending", "syncing", "failed"]).then(function (rows) {
        var matches = rows.filter(function (row) {
          return Number(row.exam_question_id) === Number(examQuestionId);
        });
        return Promise.all(matches.map(function (row) {
          return CbtOfflineStore.markQueueSynced(row.id, { status: "synced_via_online_autosave" });
        }));
      });
    },
    nextLocalSequence: function (attemptUuid) {
      var key = "seq:" + attemptUuid;
      return this.getMeta(key).then(function (current) {
        var next = (Number(current) || 0) + 1;
        return CbtOfflineStore.setMeta(key, next).then(function () {
          return next;
        });
      });
    },
    setMeta: function (key, value) {
      return put(STORE_METADATA, { key: key, value: value, updated_at: new Date().toISOString() });
    },
    getMeta: function (key) {
      return get(STORE_METADATA, key).then(function (row) {
        return row ? row.value : null;
      });
    }
  };

  global.CbtOfflineStore = CbtOfflineStore;
})(window);
