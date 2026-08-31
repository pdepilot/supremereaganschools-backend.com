(function () {
  const csrfToken = function () {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : "";
  };

  const ensureCsrf = function () {
    return fetch("/api/v1/health", {
      credentials: "same-origin",
      headers: { "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" }
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

    form.addEventListener("focusin", function () {
      if (window.srsTrack) window.srsTrack("application_started", { type: "application" });
    }, { once: true });

    form.addEventListener("submit", function (event) {
      event.preventDefault();
      if (button) button.disabled = true;
      postForm("/api/v1/admission-applications", form).then(function (result) {
        if (button) button.disabled = false;
        if (!result.ok) {
          window.alert(firstError(result.body));
          return;
        }
        const reference = (result.body.data && result.body.data.reference) || "";
        form.reset();
        if (success) {
          success.textContent = reference
            ? "Application received. Your reference is " + reference + "."
            : "Application received by the admissions office.";
          success.classList.add("show");
          success.scrollIntoView({ behavior: "smooth", block: "center" });
        }
        if (note) {
          note.textContent = "Keep your reference number. Supporting documents are stored privately with the application.";
        }
      });
    });
  };

  wireContact();
  wireAdmissions();
})();
