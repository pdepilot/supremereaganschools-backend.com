(function () {
  const loginPath = function () {
    const path = window.location.pathname || "";
    if (path.indexOf("/staff") === 0) return "/staff/login";
    if (path.indexOf("/parent") === 0) return "/parent/login";
    if (path.indexOf("/student") === 0) return "/student/login";
    return "/portal/login";
  };

  window.srsLoginPath = loginPath;

  const csrfToken = function () {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : "";
  };

  const logout = function () {
    fetch("/logout", {
      method: "POST",
      headers: {
        "Accept": "application/json",
        "X-Requested-With": "XMLHttpRequest",
        "X-XSRF-TOKEN": csrfToken()
      },
      credentials: "same-origin"
    }).finally(function () {
      window.location.replace(loginPath());
    });
  };

  document.querySelectorAll("[data-logout], .logout-link, .ps-logout").forEach(function (link) {
    link.addEventListener("click", function (event) {
      event.preventDefault();
      logout();
    });
  });
})();
