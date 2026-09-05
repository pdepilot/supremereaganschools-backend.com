(function () {
  const csrfToken = function () {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : "";
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
        if (response.status === 401) window.location.replace("/portal/login");
        return { ok: response.ok, status: response.status, body: body };
      }).catch(function () {
        return { ok: false, status: response.status, body: {} };
      });
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

  const setNotice = function (node, message, ok) {
    if (!node) return;
    node.textContent = message || "";
    node.classList.toggle("is-ok", !!ok && !!message);
    node.classList.toggle("is-error", !ok && !!message);
  };

  const accountForm = document.querySelector("[data-account-form]");
  const passwordForm = document.querySelector("[data-password-form]");
  if (!accountForm || !passwordForm) return;

  const accountNotice = document.querySelector("[data-account-notice]");
  const passwordNotice = document.querySelector("[data-password-notice]");
  const nameInput = document.getElementById("accountName");
  const emailInput = document.getElementById("accountEmail");

  const load = function () {
    return request("/api/v1/me").then(function (res) {
      if (!res.ok) {
        setNotice(accountNotice, firstError(res.body), false);
        return;
      }
      const data = res.body.data || {};
      if (nameInput) nameInput.value = data.name || "";
      if (emailInput) emailInput.value = data.email || "";
    });
  };

  accountForm.addEventListener("submit", function (event) {
    event.preventDefault();
    request("/api/v1/me", {
      method: "PUT",
      body: JSON.stringify({
        name: nameInput.value.trim(),
        email: emailInput.value.trim()
      })
    }).then(function (res) {
      if (!res.ok) return setNotice(accountNotice, firstError(res.body), false);
      setNotice(accountNotice, "Profile updated.", true);
      if (res.body.data && res.body.data.name) {
        window.srsMe = Object.assign({}, window.srsMe || {}, res.body.data);
      }
    });
  });

  passwordForm.addEventListener("submit", function (event) {
    event.preventDefault();
    const payload = {
      current_password: document.getElementById("currentPassword").value,
      password: document.getElementById("newPassword").value,
      password_confirmation: document.getElementById("newPasswordConfirm").value
    };
    request("/api/v1/me/password", {
      method: "PUT",
      body: JSON.stringify(payload)
    }).then(function (res) {
      if (!res.ok) return setNotice(passwordNotice, firstError(res.body), false);
      setNotice(passwordNotice, "Password updated.", true);
      passwordForm.reset();
    });
  });

  load();
})();
