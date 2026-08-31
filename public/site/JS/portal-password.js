(function () {
  const form = document.querySelector("[data-password-form]");
  if (!form) return;

  const alertBox = document.querySelector("[data-auth-alert]");
  const submit = form.querySelector("[type=submit]");
  const kind = form.getAttribute("data-password-form") || "forgot";

  const csrfToken = function () {
    const field = form.querySelector('input[name="_token"]');
    if (field && field.value && field.value !== "{{CSRF_TOKEN}}") return field.value;
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : "";
  };

  const showMessage = function (message, ok) {
    if (!alertBox) {
      window.alert(message);
      return;
    }
    alertBox.textContent = message;
    alertBox.hidden = false;
    alertBox.classList.add("is-visible");
    if (ok) alertBox.classList.add("is-ok");
  };

  form.addEventListener("submit", function (event) {
    event.preventDefault();
    if (alertBox) {
      alertBox.textContent = "";
      alertBox.hidden = true;
      alertBox.classList.remove("is-visible", "is-ok");
    }

    const payload = {
      email: form.email.value,
      portal: (form.portal && form.portal.value) || "portal"
    };

    if (kind === "reset") {
      payload.token = form.token.value;
      payload.password = form.password.value;
      payload.password_confirmation = form.password_confirmation.value;
    }

    if (submit) submit.disabled = true;

    fetch(form.getAttribute("action") || (kind === "reset" ? "/reset-password" : "/forgot-password"), {
      method: "POST",
      headers: {
        "Accept": "application/json",
        "Content-Type": "application/json",
        "X-Requested-With": "XMLHttpRequest",
        "X-CSRF-TOKEN": csrfToken(),
        "X-XSRF-TOKEN": csrfToken()
      },
      credentials: "same-origin",
      body: JSON.stringify(payload)
    }).then(function (response) {
      return response.json().then(function (body) {
        return { ok: response.ok, body: body };
      });
    }).then(function (result) {
      if (!result.ok) {
        const errors = result.body && result.body.errors;
        const first = errors ? errors[Object.keys(errors)[0]][0] : (result.body && result.body.message);
        showMessage(first || "Unable to complete that request.", false);
        if (submit) submit.disabled = false;
        return;
      }

      const message = (result.body && result.body.message) || "Done.";
      showMessage(message, true);
      const destination = result.body && result.body.data && result.body.data.redirect;
      if (destination) {
        window.setTimeout(function () {
          window.location.href = destination;
        }, kind === "reset" ? 900 : 2200);
        return;
      }
      if (submit) submit.disabled = false;
    }).catch(function () {
      showMessage("Unable to reach the school office. Please try again.", false);
      if (submit) submit.disabled = false;
    });
  });
})();
