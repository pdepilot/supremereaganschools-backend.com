(function () {
  const IDLE_MS = 15 * 60 * 1000;

  const loginPath = function () {
    const path = window.location.pathname || "";
    if (path.indexOf("/staff") === 0) return "/staff/login";
    if (path.indexOf("/parent") === 0) return "/parent/login";
    if (path.indexOf("/student") === 0) return "/student/login";
    return "/portal/login";
  };

  window.srsLoginPath = loginPath;
  window.srsIdleMinutes = 15;

  const csrfToken = function () {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : "";
  };

  let loggingOut = false;
  let idleTimer = null;

  const logout = function (reason) {
    if (loggingOut) return;
    loggingOut = true;
    window.clearTimeout(idleTimer);

    const target = loginPath();
    const query = reason === "idle" ? "?idle=1" : "";

    fetch("/logout", {
      method: "POST",
      headers: {
        "Accept": "application/json",
        "X-Requested-With": "XMLHttpRequest",
        "X-XSRF-TOKEN": csrfToken()
      },
      credentials: "same-origin"
    }).finally(function () {
      window.location.replace(target + query);
    });
  };

  window.srsLogout = logout;

  const bumpIdle = function () {
    if (loggingOut) return;
    window.clearTimeout(idleTimer);
    idleTimer = window.setTimeout(function () {
      logout("idle");
    }, IDLE_MS);
  };

  ["mousemove", "mousedown", "keydown", "touchstart", "touchmove", "scroll", "click", "wheel"]
    .forEach(function (eventName) {
      document.addEventListener(eventName, bumpIdle, { passive: true, capture: true });
    });

  document.addEventListener("visibilitychange", function () {
    if (!document.hidden) bumpIdle();
  });

  window.addEventListener("focus", bumpIdle);

  document.querySelectorAll("[data-logout], .logout-link, .ps-logout").forEach(function (link) {
    link.addEventListener("click", function (event) {
      event.preventDefault();
      logout();
    });
  });

  bumpIdle();
})();
