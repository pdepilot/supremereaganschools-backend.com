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
    dashboard: ["desk.view", "desk.administer"],
    students: ["students.view", "students.create", "students.edit", "students.delete"],
    teachers: ["staff.view", "staff.create", "staff.edit", "staff.delete"],
    classes: ["academics.view", "academics.manage"],
    academic_sessions: ["academics.view", "academics.manage"],
    nursery: ["students.view", "academics.view"],
    primary: ["students.view", "academics.view"],
    secondary: ["students.view", "academics.view"],
    wing: ["students.view", "academics.view"],
    timetable: ["timetable.view", "timetable.manage"],
    fees: ["fees.view", "fees.manage", "payments.view", "payments.manage"],
    grades: ["marks.view", "marks.manage"],
    announcements: ["notices.view", "notices.manage"],
    news: ["news.view", "news.manage"],
    email: ["email.view", "email.manage"],
    contact: ["contact.view", "contact.manage", "admissions.view", "admissions.manage"],
    messages: ["messages.view", "messages.manage"],
    reports: ["reports.view", "reports.export"],
    settings: ["settings.view", "settings.edit"],
    roles: ["roles.view", "roles.create", "roles.edit", "roles.delete", "permissions.view"],
    admins: ["admins.view", "admins.create", "admins.edit", "admins.suspend", "admins.delete"],
    account: []
  };

  const hrefPermission = {
    "dashboard.html": pagePermission.dashboard,
    "students.html": pagePermission.students,
    "teachers.html": pagePermission.teachers,
    "classes.html": pagePermission.classes,
    "academic_sessions.html": pagePermission.academic_sessions,
    "nursery.html": pagePermission.nursery,
    "primary.html": pagePermission.primary,
    "secondary.html": pagePermission.secondary,
    "timetable.html": pagePermission.timetable,
    "fees.html": pagePermission.fees,
    "grades.html": pagePermission.grades,
    "announcements.html": pagePermission.announcements,
    "news.html": pagePermission.news,
    "email.html": pagePermission.email,
    "contact.html": pagePermission.contact,
    "messages.html": pagePermission.messages,
    "reports.html": pagePermission.reports,
    "settings.html": pagePermission.settings,
    "roles.html": pagePermission.roles,
    "admins.html": pagePermission.admins,
    "account.html": pagePermission.account
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

  const hasAny = function (owned, needed) {
    if (!needed || !needed.length) return true;
    if (!owned || !owned.length) return false;
    if (owned.indexOf("desk.administer") !== -1) return true;
    return needed.some(function (slug) { return owned.indexOf(slug) !== -1; });
  };

  const hasExact = function (owned, needed) {
    if (!needed || !needed.length) return true;
    if (!owned || !owned.length) return false;
    return needed.some(function (slug) { return owned.indexOf(slug) !== -1; });
  };

  const ensureRolesRail = function (me) {
    const nav = document.querySelector(".rail-nav");
    if (!nav) return;
    const permissions = (me && me.permissions) || [];
    const canSee = !!(me && (me.is_super_admin || hasAny(permissions, pagePermission.roles)));
    let link = nav.querySelector('a[href="roles.html"], a[href="/portal/roles"]');
    if (!canSee) {
      if (link) link.remove();
      return;
    }
    if (!link) {
      link = document.createElement("a");
      link.className = "rail-btn";
      link.href = "/portal/roles";
      link.innerHTML = "<span>Roles</span>";
      const setup = nav.querySelector('a[href="settings.html"], a[href="/portal/settings"]');
      if (setup) nav.insertBefore(link, setup);
      else nav.appendChild(link);
    } else if (link.getAttribute("href") === "roles.html") {
      link.href = "/portal/roles";
    }
    if (document.body.getAttribute("data-page") === "roles") {
      link.classList.add("active");
      link.setAttribute("aria-current", "page");
    }
  };

  const ensureAdminsRail = function (me) {
    const nav = document.querySelector(".rail-nav");
    if (!nav) return;
    const permissions = (me && me.permissions) || [];
    const canSee = !!(me && (me.is_super_admin || hasExact(permissions, pagePermission.admins)));
    let link = nav.querySelector('a[href="admins.html"], a[href="/portal/admins"]');
    if (!canSee) {
      if (link) link.remove();
      return;
    }
    if (!link) {
      link = document.createElement("a");
      link.className = "rail-btn";
      link.href = "/portal/admins";
      link.innerHTML = "<span>Admins</span>";
      const setup = nav.querySelector('a[href="settings.html"], a[href="/portal/settings"]');
      if (setup) nav.insertBefore(link, setup);
      else nav.appendChild(link);
    } else if (link.getAttribute("href") === "admins.html") {
      link.href = "/portal/admins";
    }
    if (document.body.getAttribute("data-page") === "admins") {
      link.classList.add("active");
      link.setAttribute("aria-current", "page");
    }
  };

  const ensureAccountRail = function () {
    const nav = document.querySelector(".rail-nav");
    if (!nav) return;
    let link = nav.querySelector('a[href="account.html"], a[href="/portal/account"]');
    if (!link) {
      link = document.createElement("a");
      link.className = "rail-btn";
      link.href = "/portal/account";
      link.innerHTML = "<span>Profile</span>";
      nav.appendChild(link);
    } else if (link.getAttribute("href") === "account.html") {
      link.href = "/portal/account";
    }
    if (document.body.getAttribute("data-page") === "account") {
      link.classList.add("active");
      link.setAttribute("aria-current", "page");
    }
  };

  const applyDeskAccess = function (me) {
    if (!me) return;
    window.srsMe = me;
    ensureRolesRail(me);
    ensureAdminsRail(me);
    ensureAccountRail();

    if (me.is_super_admin || ((me.roles || []).indexOf("super_admin") !== -1)) {
      return;
    }

    const owned = me.permissions || [];
    const page = document.body.getAttribute("data-page") || "";
    const needed = pagePermission[page];

    if (page === "admins") {
      if (needed && !hasExact(owned, needed)) {
        window.location.replace("/portal/dashboard");
        return;
      }
    } else if (needed && needed.length && !hasAny(owned, needed)) {
      window.location.replace("/portal/dashboard");
      return;
    }

    document.querySelectorAll(".rail-nav a.rail-btn").forEach(function (link) {
      const href = (link.getAttribute("href") || "").split("/").pop();
      const required = hrefPermission[href] || hrefPermission[href + ".html"];
      if (!required || !required.length) return;
      const check = href === "admins" || href === "admins.html" ? hasExact : hasAny;
      if (!check(owned, required)) link.hidden = true;
    });

    const wings = document.querySelector(".rail-wings");
    if (wings && !hasAny(owned, pagePermission.nursery)) {
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
    window.setTimeout(function () { toast.classList.add("show"); }, 400);
    window.setTimeout(function () { toast.classList.remove("show"); }, 4200);
  }
})();
