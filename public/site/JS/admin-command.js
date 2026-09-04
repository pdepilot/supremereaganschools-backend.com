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

  const pagePermission = {
    dashboard: "desk",
    students: "pupils",
    teachers: "staff",
    classes: "academics",
    academic_sessions: "academics",
    nursery: "academics",
    primary: "academics",
    secondary: "academics",
    wing: "academics",
    timetable: "timetable",
    fees: "fees",
    grades: "marks",
    announcements: "notices",
    news: "news",
    email: "email",
    contact: "contact",
    messages: "messages",
    reports: "reports",
    settings: "settings",
    admins: "admins"
  };

  const hrefPermission = {
    "dashboard.html": "desk",
    "students.html": "pupils",
    "teachers.html": "staff",
    "classes.html": "academics",
    "academic_sessions.html": "academics",
    "nursery.html": "academics",
    "primary.html": "academics",
    "secondary.html": "academics",
    "timetable.html": "timetable",
    "fees.html": "fees",
    "grades.html": "marks",
    "announcements.html": "notices",
    "news.html": "news",
    "email.html": "email",
    "contact.html": "contact",
    "messages.html": "messages",
    "reports.html": "reports",
    "settings.html": "settings",
    "admins.html": "admins"
  };

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

  const applyGreeting = function (name) {
    if (!greeting || !name) return;
    const hour = Number(new Date().toLocaleString("en-GB", {
      timeZone: "Africa/Lagos",
      hour: "numeric",
      hour12: false
    }));
    const part = hour < 12 ? "morning" : hour < 16 ? "afternoon" : "evening";
    greeting.setAttribute("data-name", name);
    greeting.textContent = "Good " + part + ", " + name + ".";
  };

  const ensureAdminsRail = function (me) {
    const nav = document.querySelector(".rail-nav");
    if (!nav) return;

    const roles = (me && me.roles) || [];
    const permissions = (me && me.permissions) || [];
    const canSeeAdmins = !!(me && (me.is_super_admin
      || roles.indexOf("super_admin") !== -1
      || roles.indexOf("school_admin") !== -1
      || permissions.indexOf("admins") !== -1));

    let adminsLink = nav.querySelector('a[href="admins.html"], a[href="/portal/admins"]');
    if (!canSeeAdmins) {
      if (adminsLink) adminsLink.remove();
      return;
    }

    if (!adminsLink) {
      adminsLink = document.createElement("a");
      adminsLink.className = "rail-btn";
      adminsLink.href = "admins.html";
      adminsLink.innerHTML = "<span>Admins</span>";
      const setup = nav.querySelector('a[href="settings.html"], a[href="/portal/settings"]');
      if (setup) nav.insertBefore(adminsLink, setup);
      else nav.appendChild(adminsLink);
    }

    if (document.body.getAttribute("data-page") === "admins") {
      adminsLink.classList.add("active");
      adminsLink.setAttribute("aria-current", "page");
    }
  };

  const applyDeskAccess = function (me) {
    if (!me) return;

    window.srsMe = me;
    ensureAdminsRail(me);

    if (me.is_super_admin || ((me.roles || []).indexOf("super_admin") !== -1)) {
      return;
    }

    const allowed = me.permissions || [];
    const page = document.body.getAttribute("data-page") || "";
    const needed = pagePermission[page];

    if (needed === "admins") {
      if ((me.roles || []).indexOf("school_admin") === -1 && allowed.indexOf("admins") === -1) {
        window.location.replace("/portal/dashboard");
      }
      return;
    }

    if (needed && allowed.indexOf(needed) === -1) {
      window.location.replace("/portal/dashboard");
      return;
    }

    document.querySelectorAll(".rail-nav a.rail-btn").forEach(function (link) {
      const href = (link.getAttribute("href") || "").split("/").pop();
      const permission = hrefPermission[href];
      if (!permission) return;
      if (permission === "admins") return;
      if (allowed.indexOf(permission) === -1) {
        link.hidden = true;
      }
    });

    const wings = document.querySelector(".rail-wings");
    if (wings && allowed.indexOf("academics") === -1) {
      wings.hidden = true;
    }
  };

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
    if (!body || !body.data) return;
    applyGreeting(body.data.name);
    applyDeskAccess(body.data);
  }).catch(function () {});

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
