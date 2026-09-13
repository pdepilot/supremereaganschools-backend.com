(function () {
  const csrfToken = function () {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : "";
  };

  let csrfWarm = null;

  const ensureCsrf = function () {
    if (csrfToken()) {
      return Promise.resolve();
    }
    if (csrfWarm) {
      return csrfWarm;
    }
    csrfWarm = fetch("/api/v1/health", {
      credentials: "same-origin",
      headers: { "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" }
    }).then(function () {
      return undefined;
    }).finally(function () {
      csrfWarm = null;
    });
    return csrfWarm;
  };

  // Warm the session cookie as soon as the public desk loads so Paystack submit skips a round-trip.
  ensureCsrf();

  const firstError = function (body) {
    if (!body) return "The office could not complete that request.";
    if (body.errors) {
      const keys = Object.keys(body.errors);
      if (keys.length) return body.errors[keys[0]][0];
    }
    return body.message || "The office could not complete that request.";
  };

  const postJson = function (url, payload) {
    return ensureCsrf().then(function () {
      return fetch(url, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Accept": "application/json",
          "Content-Type": "application/json",
          "X-Requested-With": "XMLHttpRequest",
          "X-XSRF-TOKEN": csrfToken()
        },
        body: JSON.stringify(payload)
      }).then(function (response) {
        return response.json().then(function (body) {
          return { ok: response.ok, status: response.status, body: body };
        }).catch(function () {
          return { ok: false, status: response.status, body: {} };
        });
      });
    });
  };

  const postForm = function (url, form) {
    return ensureCsrf().then(function () {
      const data = new FormData(form);
      return fetch(url, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Accept": "application/json",
          "X-Requested-With": "XMLHttpRequest",
          "X-XSRF-TOKEN": csrfToken()
        },
        body: data
      }).then(function (response) {
        return response.json().then(function (body) {
          return { ok: response.ok, status: response.status, body: body };
        }).catch(function () {
          return { ok: false, status: response.status, body: {} };
        });
      });
    });
  };

  const getJson = function (url) {
    return fetch(url, {
      credentials: "same-origin",
      headers: {
        "Accept": "application/json",
        "X-Requested-With": "XMLHttpRequest"
      }
    }).then(function (response) {
      return response.json().then(function (body) {
        return { ok: response.ok, status: response.status, body: body };
      }).catch(function () {
        return { ok: false, status: response.status, body: {} };
      });
    });
  };

  const wireContact = function () {
    const form = document.getElementById("contactForm");
    if (!form) return;
    const success = document.getElementById("contactSuccess");
    const button = form.querySelector("button[type='submit']");

    form.addEventListener("submit", function (event) {
      event.preventDefault();
      if (button) button.disabled = true;
      postJson("/api/v1/contact-enquiries", {
        name: document.getElementById("contactName").value,
        phone: document.getElementById("contactPhone").value,
        email: document.getElementById("contactEmail").value,
        subject: document.getElementById("contactSubject").value,
        message: document.getElementById("contactMessage").value
      }).then(function (result) {
        if (button) button.disabled = false;
        if (!result.ok) {
          window.alert(firstError(result.body));
          return;
        }
        form.reset();
        if (success) {
          success.textContent = "Thank you. Your letter has been received by the school office.";
          success.classList.add("show");
        }
      });
    });
  };

  const wireAdmissions = function () {
    const form = document.getElementById("applicationForm");
    if (!form) return;
    const success = document.getElementById("admissionSuccess");
    const button = form.querySelector("button[type='submit']");
    const note = form.querySelector(".admission-note");
    const feeLabel = document.querySelector("[data-admission-fee]");
    const levelSelect = document.getElementById("level");
    const classSelect = document.getElementById("classApplied");
    const sessionInput = document.getElementById("session");

    const fallbackBook = {
      "Activity": ["Activity 1", "Activity 2"],
      "Nursery": ["Nursery 1", "Nursery 2", "Nursery 3"],
      "Primary": ["Basic 1", "Basic 2", "Basic 3", "Basic 4", "Basic 5"],
      "Junior Secondary": ["JSS 1", "JSS 2", "JSS 3"],
      "Senior Secondary": ["SS 1", "SS 2", "SS 3"]
    };

    let bookByLevel = fallbackBook;

    const fillClassOptions = function (levelName) {
      if (!classSelect) return;
      const classes = bookByLevel[levelName] || [];
      const current = classSelect.value;
      classSelect.innerHTML = '<option value="">Select class</option>' + classes.map(function (name) {
        return '<option value="' + name.replace(/"/g, "&quot;") + '">' + name + "</option>";
      }).join("");
      if (current && classes.indexOf(current) !== -1) {
        classSelect.value = current;
      }
    };

    if (levelSelect) {
      levelSelect.addEventListener("change", function () {
        fillClassOptions(levelSelect.value);
      });
      if (levelSelect.value) fillClassOptions(levelSelect.value);
    }

    const params = new URLSearchParams(window.location.search);
    const paidStatus = params.get("status");
    const paidReference = params.get("reference");
    if (success && (paidStatus === "success" || paidStatus === "pending") && paidReference) {
      success.textContent = paidStatus === "success"
        ? "Payment successful. Your application has been received and is being reviewed. Reference: " + paidReference + "."
        : "Payment is still confirming. Keep your reference " + paidReference + ". You will receive an email once it is settled.";
      success.classList.add("show");
      if (note) {
        note.textContent = "A confirmation email has been sent to the parent email on the form when payment settles.";
      }
      success.scrollIntoView({ behavior: "smooth", block: "center" });
    } else if (success && paidStatus === "failed") {
      success.textContent = "Payment was not completed. Please submit the form again to retry Paystack checkout.";
      success.classList.add("show");
    }

    getJson("/api/v1/admission-applications/fee").then(function (result) {
      if (!result.ok || !feeLabel) return;
      const label = result.body.data && result.body.data.amount_label;
      if (label) feeLabel.textContent = label;
    });

    getJson("/api/v1/admission-applications/options").then(function (result) {
      if (!result.ok || !result.body.data) return;
      const data = result.body.data;
      const levels = data.levels || [];
      if (levels.length && levelSelect) {
        bookByLevel = {};
        levelSelect.innerHTML = '<option value="">Select level</option>';
        levels.forEach(function (row) {
          bookByLevel[row.name] = row.classes || [];
          const option = document.createElement("option");
          option.value = row.name;
          option.textContent = row.name;
          levelSelect.appendChild(option);
        });
        fillClassOptions(levelSelect.value);
      }
      if (sessionInput && !sessionInput.value && data.suggested_session) {
        sessionInput.value = data.suggested_session;
        sessionInput.placeholder = data.suggested_session;
      }
    });

    form.addEventListener("focusin", function () {
      if (window.srsTrack) window.srsTrack("application_started", { type: "application" });
    }, { once: true });

    form.addEventListener("submit", function (event) {
      event.preventDefault();
      const overlay = document.getElementById("admissionCheckoutOverlay");
      const overlayCopy = overlay ? overlay.querySelector("[data-checkout-copy]") : null;
      if (overlay) {
        overlay.hidden = false;
        overlay.setAttribute("aria-hidden", "false");
      }
      if (overlayCopy) {
        overlayCopy.textContent = "Preparing secure Paystack checkout…";
      }
      if (button) {
        button.disabled = true;
        button.textContent = "Opening Paystack…";
      }
      postForm("/api/v1/admission-applications", form).then(function (result) {
        if (!result.ok) {
          if (overlay) {
            overlay.hidden = true;
            overlay.setAttribute("aria-hidden", "true");
          }
          if (button) {
            button.disabled = false;
            button.textContent = "Pay & Submit Application";
          }
          window.alert(firstError(result.body));
          return;
        }
        const data = result.body.data || {};
        const checkoutUrl = data.authorization_url;
        if (checkoutUrl) {
          if (overlayCopy) {
            overlayCopy.textContent = "Redirecting to Paystack…";
          }
          window.location.replace(checkoutUrl);
          return;
        }
        if (overlay) {
          overlay.hidden = true;
          overlay.setAttribute("aria-hidden", "true");
        }
        if (button) {
          button.disabled = false;
          button.textContent = "Pay & Submit Application";
        }
        window.alert("Payment checkout could not be started. Please try again.");
      }).catch(function () {
        if (overlay) {
          overlay.hidden = true;
          overlay.setAttribute("aria-hidden", "true");
        }
        if (button) {
          button.disabled = false;
          button.textContent = "Pay & Submit Application";
        }
        window.alert("Unable to reach the admissions office. Please try again.");
      });
    });
  };

  wireContact();
  wireAdmissions();
})();
