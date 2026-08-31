(function () {
  const loginHref = "/portal/login";

  const csrfToken = function () {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : "";
  };

  const clock = document.querySelector("[data-clock]");
  const greeting = document.querySelector("[data-greeting]");
  const toast = document.querySelector("[data-toast]");
  const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  document.querySelectorAll("[data-logout]").forEach(function (link) {
    link.addEventListener("click", function (event) {
      event.preventDefault();
      fetch("/logout", {
        method: "POST",
        headers: {
          "Accept": "application/json",
          "X-Requested-With": "XMLHttpRequest",
          "X-XSRF-TOKEN": csrfToken()
        },
        credentials: "same-origin"
      }).finally(function () {
        window.location.replace(loginHref);
      });
    });
  });

  if (clock) {
    const tick = function () {
      clock.textContent = new Date().toLocaleTimeString("en-GB", {
        timeZone: "Africa/Lagos",
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit",
        hour12: false
      });
    };
    tick();
    setInterval(tick, 1000);
  }

  if (greeting) {
    const hour = Number(new Date().toLocaleString("en-GB", {
      timeZone: "Africa/Lagos",
      hour: "numeric",
      hour12: false
    }));
    const part = hour < 12 ? "morning" : hour < 16 ? "afternoon" : "evening";
    const apply = function (name) {
      greeting.textContent = "Good " + part + ", " + name + ".";
    };

    if (greeting.getAttribute("data-name")) {
      apply(greeting.getAttribute("data-name"));
    }

    fetch("/api/v1/me", {
      headers: {
        "Accept": "application/json",
        "X-Requested-With": "XMLHttpRequest",
        "X-XSRF-TOKEN": csrfToken()
      },
      credentials: "same-origin"
    }).then(function (response) {
      if (response.status === 401) {
        window.location.replace(loginHref);
        return null;
      }
      return response.json();
    }).then(function (body) {
      if (body && body.data && body.data.name) {
        greeting.setAttribute("data-name", body.data.name);
        apply(body.data.name);
      }
    }).catch(function () {});
  }

  if (document.body.getAttribute("data-page") !== "dashboard") {
    document.querySelectorAll("[data-count]").forEach(function (node) {
    const raw = node.getAttribute("data-count");
    const prefix = node.getAttribute("data-prefix") || "";
    const suffix = node.getAttribute("data-suffix") || "";
    const target = Number(raw);
    if (!Number.isFinite(target)) return;
    if (reduceMotion) {
      node.textContent = prefix + (Number.isInteger(target) ? target.toLocaleString() : target) + suffix;
      return;
    }
    const duration = 1100;
    const start = performance.now();
    const decimals = String(raw).includes(".") ? 1 : 0;
    const step = function (now) {
      const t = Math.min(1, (now - start) / duration);
      const eased = 1 - Math.pow(1 - t, 3);
      const value = target * eased;
      node.textContent = prefix + (decimals ? value.toFixed(decimals) : Math.round(value).toLocaleString()) + suffix;
      if (t < 1) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
  });
  }

  if (toast) {
    window.setTimeout(function () {
      toast.classList.add("show");
    }, 400);
    window.setTimeout(function () {
      toast.classList.remove("show");
    }, 4200);
  }
})();
