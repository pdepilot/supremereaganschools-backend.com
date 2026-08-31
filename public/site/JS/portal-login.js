(function () {
  const form = document.querySelector("[data-auth-form]");
  if (!form) return;

  const alertBox = document.querySelector("[data-auth-alert]");
  const submit = form.querySelector("[type=submit], .login-btn");

  const deskPortal = function () {
    const path = window.location.pathname || "";
    if (path.indexOf("/student") === 0) return "student";
    if (path.indexOf("/parent") === 0) return "parent";
    if (path.indexOf("/staff") === 0) return "staff";
    return "portal";
  };

  const deskHome = function () {
    const path = window.location.pathname || "";
    if (path.indexOf("/student") === 0) return "/student/home";
    if (path.indexOf("/parent") === 0) return "/parent/home";
    if (path.indexOf("/staff") === 0) return "/staff/home";
    return "/portal/home";
  };

  const csrfToken = function () {
    const field = form.querySelector('input[name="_token"]');
    if (field && field.value) return field.value;
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : "";
  };

  const showError = function (message) {
    if (!alertBox) {
      window.alert(message);
      return;
    }
    alertBox.textContent = message;
    alertBox.hidden = false;
  };

  form.addEventListener("submit", function (event) {
    event.preventDefault();
    if (alertBox) {
      alertBox.textContent = "";
      alertBox.hidden = true;
    }

    const payload = {
      password: form.password.value,
      portal: (form.portal && form.portal.value) || deskPortal(),
      remember: form.remember && form.remember.checked
    };

    if (form.email) payload.email = form.email.value;
    if (form.admission_number) payload.admission_number = form.admission_number.value;

    if (submit) submit.disabled = true;

    fetch("/login", {
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
        showError(first || "Unable to sign in.");
        if (submit) submit.disabled = false;
        return;
      }

      window.location.href = (result.body && result.body.data && result.body.data.redirect) || deskHome();
    }).catch(function () {
      showError("Unable to reach the school office. Please try again.");
      if (submit) submit.disabled = false;
    });
  });
})();
