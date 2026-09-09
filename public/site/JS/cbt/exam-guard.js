/**
 * Phase 10B.1: Strict exam-exit monitoring + automatic submission.
 * Deterrent only — server remains authoritative. Not dedicated proctoring.
 */
(function (global) {
  "use strict";

  function newId() {
    return (global.crypto && crypto.randomUUID)
      ? crypto.randomUUID()
      : ("evt-" + Date.now() + "-" + Math.random().toString(16).slice(2));
  }

  function csrfToken() {
    var match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : "";
  }

  function metaKey(attemptUuid) {
    return "auto_submit:" + attemptUuid;
  }

  var CbtExamGuard = {
    active: false,
    attempt: null,
    handlersBound: false,
    exitTriggered: false,
    correlationId: null,
    ignoreUntil: 0,
    fullscreenRequested: false,
    onExit: null,
    onStatus: null,
    channel: null,

    start: function (attempt, hooks) {
      this.stop();
      if (!attempt || attempt.status !== "in_progress") return;
      this.attempt = attempt;
      this.onExit = hooks && hooks.onExit;
      this.onStatus = hooks && hooks.onStatus;
      this.exitTriggered = false;
      this.correlationId = null;
      this.ignoreUntil = Date.now() + 1500;
      this.active = true;
      this.bind();
      this.requestFullscreen();
      if (this.onStatus) this.onStatus({ state: "monitoring", message: "Exam monitoring active" });
    },

    stop: function () {
      this.active = false;
      this.unbind();
      this.exitTriggered = true;
    },

    bind: function () {
      if (this.handlersBound) return;
      var self = this;
      this._onVisibility = function () {
        if (!self.active || self.exitTriggered) return;
        if (document.visibilityState === "hidden") {
          self.handleExit("tab_hidden");
        }
      };
      this._onBlur = function () {
        if (!self.active || self.exitTriggered) return;
        // Zero-tolerance policy: focus loss while monitoring is an exit event.
        // Deduplicated with visibilitychange via exitTriggered.
        self.handleExit("window_blur");
      };
      this._onFullscreen = function () {
        if (!self.active || self.exitTriggered) return;
        if (self.fullscreenRequested && !document.fullscreenElement) {
          self.handleExit("fullscreen_exit");
        }
      };
      document.addEventListener("visibilitychange", this._onVisibility);
      window.addEventListener("blur", this._onBlur);
      document.addEventListener("fullscreenchange", this._onFullscreen);
      this.handlersBound = true;

      if (global.BroadcastChannel) {
        this.channel = new BroadcastChannel("srs-cbt-attempt");
        this.channel.onmessage = function (event) {
          if (!event.data) return;
          if (event.data.type === "attempt_auto_submitted"
            && self.attempt
            && String(event.data.attemptId) === String(self.attempt.id)) {
            self.exitTriggered = true;
            self.active = false;
            if (self.onExit) {
              self.onExit({
                serverAck: !!event.data.serverAck,
                fromBroadcast: true,
                result: event.data.result || null,
                message: event.data.message || null
              });
            }
          }
        };
      }
    },

    unbind: function () {
      if (!this.handlersBound) return;
      document.removeEventListener("visibilitychange", this._onVisibility);
      window.removeEventListener("blur", this._onBlur);
      document.removeEventListener("fullscreenchange", this._onFullscreen);
      this.handlersBound = false;
      if (this.channel) {
        try { this.channel.close(); } catch (e) {}
        this.channel = null;
      }
    },

    requestFullscreen: function () {
      var root = document.documentElement;
      if (!root || !root.requestFullscreen) {
        this.persistFullscreenNote("unavailable");
        return;
      }
      var self = this;
      root.requestFullscreen().then(function () {
        self.fullscreenRequested = true;
      }).catch(function () {
        self.fullscreenRequested = false;
        self.persistFullscreenNote("denied");
      });
    },

    persistFullscreenNote: function (reason) {
      var store = global.CbtOfflineStore;
      if (!store || !store.isAvailable() || !this.attempt || !this.attempt.uuid) return;
      store.setMeta("fullscreen:" + this.attempt.uuid, {
        status: reason,
        at: new Date().toISOString()
      });
    },

    handleExit: function (trigger) {
      if (!this.active || this.exitTriggered) return;
      if (Date.now() < this.ignoreUntil) return;
      this.exitTriggered = true;
      this.active = false;
      this.correlationId = newId();
      var integrityEventId = newId();
      var payload = {
        trigger: trigger,
        integrity_event_id: integrityEventId,
        correlation_id: this.correlationId,
        client_submitted_at: new Date().toISOString(),
        reason: "auto_submitted_exam_exit"
      };

      if (this.onStatus) {
        this.onStatus({
          state: "autoSubmitting",
          message: "Examination submitted automatically because the examination window was left."
        });
      }

      this.persistPending(payload);
      this.submit(payload);
    },

    persistPending: function (payload) {
      var store = global.CbtOfflineStore;
      if (!store || !store.isAvailable() || !this.attempt || !this.attempt.uuid) return Promise.resolve();
      return store.setMeta(metaKey(this.attempt.uuid), {
        state: "pending_server",
        reason: payload.reason,
        trigger: payload.trigger,
        integrity_event_id: payload.integrity_event_id,
        correlation_id: payload.correlation_id,
        client_occurred_at: payload.client_submitted_at,
        attempt_id: this.attempt.id,
        attempt_uuid: this.attempt.uuid
      });
    },

    markServerAck: function (payload) {
      var store = global.CbtOfflineStore;
      if (!store || !store.isAvailable() || !this.attempt || !this.attempt.uuid) return Promise.resolve();
      return store.setMeta(metaKey(this.attempt.uuid), Object.assign({}, payload, {
        state: "server_acknowledged",
        acknowledged_at: new Date().toISOString()
      }));
    },

    submit: function (payload) {
      var self = this;
      var attempt = this.attempt;
      if (!attempt) return;

      var body = JSON.stringify({
        reason: "auto_submitted_exam_exit",
        integrity_event_id: payload.integrity_event_id,
        correlation_id: payload.correlation_id,
        trigger: payload.trigger,
        client_submitted_at: payload.client_submitted_at
      });

      var url = "/api/v1/cbt/attempts/" + attempt.id + "/auto-submit";
      var headers = {
        "Accept": "application/json",
        "Content-Type": "application/json",
        "X-Requested-With": "XMLHttpRequest",
        "X-XSRF-TOKEN": csrfToken()
      };

      var send = function () {
        return fetch(url, {
          method: "POST",
          credentials: "same-origin",
          keepalive: true,
          headers: headers,
          body: body
        }).then(function (response) {
          return response.json().then(function (json) {
            return { ok: response.ok, status: response.status, body: json };
          }).catch(function () {
            return { ok: false, status: response.status, body: {} };
          });
        });
      };

      if (!navigator.onLine) {
        if (self.onExit) {
          self.onExit({
            serverAck: false,
            offline: true,
            message: "Your examination exit was recorded on this device. The server could not be reached. Your examination will be reconciled when connectivity is restored."
          });
        }
        return;
      }

      send().then(function (result) {
        if (result.ok) {
          self.markServerAck({
            integrity_event_id: payload.integrity_event_id,
            correlation_id: payload.correlation_id,
            trigger: payload.trigger
          });
          if (self.channel) {
            self.channel.postMessage({
              type: "attempt_auto_submitted",
              attemptId: attempt.id,
              serverAck: true,
              result: result.body.data || null,
              message: "Your examination has been submitted."
            });
          }
          if (self.onExit) {
            self.onExit({
              serverAck: true,
              result: result.body.data || null,
              message: "Your examination has been submitted."
            });
          }
          return;
        }

        if (self.onExit) {
          self.onExit({
            serverAck: false,
            message: "Your examination exit was recorded on this device. The server could not be reached. Your examination will be reconciled when connectivity is restored."
          });
        }
      }).catch(function () {
        if (self.onExit) {
          self.onExit({
            serverAck: false,
            message: "Your examination exit was recorded on this device. The server could not be reached. Your examination will be reconciled when connectivity is restored."
          });
        }
      });
    }
  };

  global.CbtExamGuard = CbtExamGuard;
})(window);
