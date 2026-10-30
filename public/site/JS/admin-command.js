(function () {
  const loginHref = "/portal/login";

  const csrfToken = function () {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : "";
  };

  const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  /* Theme management — Light (Alabaster & Forest Emerald) / Dark (Obsidian & Cypress) */
  const savedTheme = localStorage.getItem("srs_theme") || "light";
  document.documentElement.setAttribute("data-theme", savedTheme);

  const icons = {
    sun: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>',
    moon: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>',
    dashboard: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9"></rect><rect x="14" y="3" width="7" height="5"></rect><rect x="14" y="12" width="7" height="9"></rect><rect x="3" y="16" width="7" height="5"></rect></svg>',
    nursery: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M8 14s1.5 2 4 2 4-2 4-2"></path><line x1="9" y1="9" x2="9.01" y2="9"></line><line x1="15" y1="9" x2="15.01" y2="9"></line></svg>',
    primary: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>',
    secondary: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m4 6 8-4 8 4"></path><path d="m18 10 4 2v8a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-8l4-2"></path><path d="M14 22v-4a2 2 0 0 0-2-2v0a2 2 0 0 0-2 2v4"></path></svg>',
    students: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>',
    teachers: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>',
    classes: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 2 7 12 12 22 7 12 2"></polygon><polyline points="2 17 12 22 22 17"></polyline><polyline points="2 12 12 17 22 12"></polyline></svg>',
    academic_sessions: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>',
    timetable: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>',
    fees: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"></rect><line x1="2" y1="10" x2="22" y2="10"></line></svg>',
    grades: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="6"></circle><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"></path></svg>',
    cbt: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>',
    announcements: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>',
    news: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-2 2Zm0 0a2 2 0 0 1-2-2v-9c0-1.1.9-2 2-2h2"></path><path d="M18 14h-8"></path><path d="M15 18h-5"></path><path d="M10 6h8v4h-8V6Z"></path></svg>',
    events: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>',
    email: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"></rect><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"></path></svg>',
    contact: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>',
    messages: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path></svg>',
    reports: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>',
    settings: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"></path><circle cx="12" cy="12" r="3"></circle></svg>',
    roles: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>',
    admins: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><line x1="19" y1="8" x2="19" y2="14"></line><line x1="22" y1="11" x2="16" y2="11"></line></svg>',
    account: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>'
  };

  const getIconForHref = function (href, label) {
    const raw = (href || "").split("/").pop() || "";
    const key = raw.replace(/\.html$/i, "").replace(/-/g, "_").toLowerCase();
    if (icons[key]) return icons[key];
    const lbl = (label || "").toLowerCase();
    if (lbl.indexOf("desk") !== -1 || lbl.indexOf("dash") !== -1) return icons.dashboard;
    if (lbl.indexOf("pupil") !== -1 || lbl.indexOf("student") !== -1) return icons.students;
    if (lbl.indexOf("staff") !== -1 || lbl.indexOf("master") !== -1 || lbl.indexOf("teacher") !== -1) return icons.teachers;
    if (lbl.indexOf("class") !== -1) return icons.classes;
    if (lbl.indexOf("session") !== -1) return icons.academic_sessions;
    if (lbl.indexOf("time") !== -1) return icons.timetable;
    if (lbl.indexOf("fee") !== -1) return icons.fees;
    if (lbl.indexOf("mark") !== -1 || lbl.indexOf("grade") !== -1) return icons.grades;
    if (lbl.indexOf("cbt") !== -1) return icons.cbt;
    if (lbl.indexOf("notice") !== -1 || lbl.indexOf("announc") !== -1) return icons.announcements;
    if (lbl.indexOf("news") !== -1) return icons.news;
    if (lbl.indexOf("event") !== -1) return icons.events;
    if (lbl.indexOf("email") !== -1) return icons.email;
    if (lbl.indexOf("contact") !== -1 || lbl.indexOf("chute") !== -1) return icons.contact;
    if (lbl.indexOf("mail") !== -1 || lbl.indexOf("message") !== -1) return icons.messages;
    if (lbl.indexOf("report") !== -1) return icons.reports;
    if (lbl.indexOf("setup") !== -1 || lbl.indexOf("setting") !== -1) return icons.settings;
    if (lbl.indexOf("role") !== -1) return icons.roles;
    if (lbl.indexOf("admin") !== -1) return icons.admins;
    if (lbl.indexOf("profile") !== -1 || lbl.indexOf("account") !== -1) return icons.account;
    return '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle></svg>';
  };

  const pagePermission = {
    dashboard: ["desk.view", "desk.administer"],
    students: ["students.view", "students.create", "students.edit", "students.delete"],
    teachers: ["staff.view", "staff.create", "staff.edit", "staff.delete"],
    classes: ["academics.view", "academics.manage"],
    academic_sessions: ["academics.view", "academics.manage"],
    sessions: ["academics.view", "academics.manage"],
    nursery: ["students.view", "academics.view"],
    primary: ["students.view", "academics.view"],
    secondary: ["students.view", "academics.view"],
    wing: ["students.view", "academics.view"],
    timetable: ["timetable.view", "timetable.manage"],
    fees: ["fees.view", "fees.manage", "payments.view", "payments.manage"],
    grades: ["marks.view", "marks.manage"],
    announcements: ["notices.view", "notices.manage"],
    news: ["news.view", "news.manage"],
    events: ["events.view", "events.manage"],
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
    dashboard: pagePermission.dashboard,
    "students.html": pagePermission.students,
    students: pagePermission.students,
    "teachers.html": pagePermission.teachers,
    teachers: pagePermission.teachers,
    "classes.html": pagePermission.classes,
    classes: pagePermission.classes,
    "academic_sessions.html": pagePermission.academic_sessions,
    academic_sessions: pagePermission.academic_sessions,
    "academic-sessions": pagePermission.academic_sessions,
    "academic-sessions.html": pagePermission.academic_sessions,
    "nursery.html": pagePermission.nursery,
    nursery: pagePermission.nursery,
    "primary.html": pagePermission.primary,
    primary: pagePermission.primary,
    "secondary.html": pagePermission.secondary,
    secondary: pagePermission.secondary,
    "timetable.html": pagePermission.timetable,
    timetable: pagePermission.timetable,
    "fees.html": pagePermission.fees,
    fees: pagePermission.fees,
    "grades.html": pagePermission.grades,
    grades: pagePermission.grades,
    "announcements.html": pagePermission.announcements,
    announcements: pagePermission.announcements,
    "news.html": pagePermission.news,
    news: pagePermission.news,
    "events.html": pagePermission.events,
    events: pagePermission.events,
    "email.html": pagePermission.email,
    email: pagePermission.email,
    "contact.html": pagePermission.contact,
    contact: pagePermission.contact,
    "messages.html": pagePermission.messages,
    messages: pagePermission.messages,
    "reports.html": pagePermission.reports,
    reports: pagePermission.reports,
    "settings.html": pagePermission.settings,
    settings: pagePermission.settings,
    "roles.html": pagePermission.roles,
    roles: pagePermission.roles,
    "admins.html": pagePermission.admins,
    admins: pagePermission.admins,
    "account.html": pagePermission.account,
    account: pagePermission.account
  };

  const hrefKey = function (href) {
    const raw = (href || "").split("/").pop() || "";
    return raw.replace(/\.html$/i, "");
  };

  const requiredForHref = function (href) {
    const key = hrefKey(href);
    return hrefPermission[key]
      || hrefPermission[key + ".html"]
      || hrefPermission[key.replace(/-/g, "_")]
      || hrefPermission[key.replace(/_/g, "-")]
      || null;
  };

  const isSuper = function (me) {
    return !!(me && (me.is_super_admin || ((me.roles || []).indexOf("super_admin") !== -1)));
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

  /* Attach logout handlers */
  const bindLogout = function (container) {
    const root = container || document;
    root.querySelectorAll("[data-logout]").forEach(function (link) {
      if (link.__logoutBound) return;
      link.__logoutBound = true;
      link.addEventListener("click", function (event) {
        event.preventDefault();
        if (typeof window.srsLogout === "function") {
          window.srsLogout();
          return;
        }
        fetch("/logout", {
          method: "POST",
          headers: {
            "Accept": "application/json",
            "X-Requested-With": "XMLHttpRequest",
            "X-XSRF-TOKEN": csrfToken()
          },
          credentials: "same-origin"
        }).finally(function () {
          window.location.replace((window.srsLoginPath && window.srsLoginPath()) || loginHref);
        });
      });
    });
  };

  /* Dynamic Topbar Builder — matches Bricks-Site Topbar */
  const ensureTopbar = function () {
    const stage = document.querySelector(".stage");
    if (!stage || stage.querySelector(".topbar")) return;

    const pageSlug = document.body.getAttribute("data-page") || "dashboard";
    const titleMap = {
      dashboard: "Command Desk",
      teachers: "Staff Directory",
      students: "Pupils & Roll",
      classes: "Classes & Departments",
      academic_sessions: "Academic Sessions",
      timetable: "Timetable & Schedules",
      fees: "Fees & Payment Ledger",
      grades: "Marks & Term Reports",
      announcements: "School Notices",
      news: "Press & News Bulletin",
      events: "School Calendar & Events",
      email: "Email Dispatch Chute",
      contact: "Inbound Admissions & Desk",
      messages: "Staff Internal Mail",
      reports: "Audit & Growth Reports",
      settings: "Command Settings",
      roles: "Roles & Permissions",
      admins: "Administrators",
      account: "Administrator Profile",
      nursery: "Early Years Wing",
      primary: "Primary Wing",
      secondary: "Secondary Wing"
    };
    const pageTitle = titleMap[pageSlug] || pageSlug.charAt(0).toUpperCase() + pageSlug.slice(1);

    const topbar = document.createElement("header");
    topbar.className = "topbar";
    topbar.innerHTML = `
      <div class="top-left">
        <button class="icon-btn menu-btn" id="menuBtn" aria-label="Open menu">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
        </button>
        <div class="crumbs">
          <span>Workspace</span>
          <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
          <b id="crumbTitle">${pageTitle}</b>
        </div>
      </div>
      <div class="top-actions">
        <div class="live-pill">
          <span class="live-dot"></span>
          <span data-clock>00:00:00</span> · Desk open
        </div>
        <button class="icon-btn theme-btn" id="themeBtn" aria-label="Toggle theme">
          ${savedTheme === "dark" ? icons.sun : icons.moon}
        </button>
        <button class="icon-btn" id="notificationsBtn" aria-label="Notifications" title="Alerts & Notices">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
          <span class="notification-dot"></span>
        </button>
        <div class="top-user">
          <div class="avatar user-avatar" data-user-avatar title="School Administrator">AD</div>
          <div>
            <b data-user-name>Administrator</b>
            <small data-user-role>Academic Command</small>
          </div>
        </div>
      </div>
    `;

    stage.insertBefore(topbar, stage.firstChild);
  };

  /* Dynamic Sidebar Enhancement — adapts Supreme Reagan Schools rail to Bricks-site aesthetic */
  const ensureSidebarStructure = function () {
    const rail = document.querySelector(".rail");
    if (!rail) return;

    // Mobile drawer scrim overlay
    let scrim = document.getElementById("scrim");
    if (!scrim) {
      scrim = document.createElement("div");
      scrim.id = "scrim";
      scrim.className = "scrim";
      document.body.appendChild(scrim);
    }

    // Enhance Brand Header
    let logoBlock = rail.querySelector(".brand") || rail.querySelector(".rail-logo");
    if (logoBlock && !rail.querySelector(".brand-copy")) {
      const brandWrapper = document.createElement("div");
      brandWrapper.className = "brand";
      brandWrapper.innerHTML = `
        <a href="/portal/dashboard" class="brand-link" title="Supreme Reagan Schools Command Desk">
          <img src="/site/Image/logo_rail.png" alt="Supreme Reagan Schools Logo" class="brand-logo-img">
          <div class="brand-copy">
            <strong>SUPREME REAGAN</strong>
            <small>COMMAND DESK</small>
          </div>
        </a>
        <button class="icon-btn sidebar-close" id="sidebarClose" aria-label="Close menu">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
      `;
      logoBlock.replaceWith(brandWrapper);
    }

    // Enhance Workspace Pill
    const nav = rail.querySelector(".rail-nav");
    if (nav && !rail.querySelector(".workspace-pill")) {
      const wsPill = document.createElement("div");
      wsPill.className = "workspace-pill";
      wsPill.innerHTML = `
        <span class="workspace-dot"></span>
        <div><b>Supreme Reagan Schools</b><small>Academic Command &amp; Records</small></div>
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="7 15 12 20 17 15"></polyline><polyline points="7 9 12 4 17 9"></polyline></svg>
      `;
      rail.insertBefore(wsPill, nav);
    }

    // Ensure Section Captions inside .rail-nav
    if (nav && !nav.querySelector(".nav-caption")) {
      const insertCaption = function (targetSelector, text) {
        const el = nav.querySelector(targetSelector);
        if (el) {
          const cap = document.createElement("p");
          cap.className = "nav-caption";
          cap.textContent = text;
          nav.insertBefore(cap, el);
        }
      };

      insertCaption('a[href*="dashboard"]', "OVERVIEW");
      insertCaption('.rail-wings, a[href*="nursery"]', "ACADEMIC WINGS");
      insertCaption('a[href*="students"]', "STUDENTS & STAFF");
      insertCaption('a[href*="fees"]', "FINANCE & ACADEMICS");
      insertCaption('a[href*="announcements"]', "COMMUNICATIONS");
      insertCaption('a[href*="reports"]', "ADMINISTRATION");
    }

    // Enhance each nav link with SVG icon
    nav.querySelectorAll("a.rail-btn").forEach(function (btn) {
      if (btn.querySelector("svg") || btn.querySelector("i")) return;
      const href = btn.getAttribute("href") || "";
      const text = btn.textContent.trim();
      const iconSvg = getIconForHref(href, text);
      const span = btn.querySelector("span");
      if (span) {
        btn.insertAdjacentHTML("afterbegin", iconSvg);
      } else {
        btn.innerHTML = iconSvg + `<span>${text}</span>`;
      }
    });

    // Ensure Sidebar Bottom Widget (Help Card + Admin Mini)
    if (!rail.querySelector(".sidebar-bottom")) {
      // Remove old full-height vertical exit if present
      const oldExit = rail.querySelector(".rail-exit");
      if (oldExit && oldExit.parentNode === rail) {
        oldExit.remove();
      }

      const bottom = document.createElement("div");
      bottom.className = "sidebar-bottom";
      bottom.innerHTML = `
        <div class="help-card">
          <span class="help-icon">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
          </span>
          <div>
            <b>School Desk Support</b>
            <small>Office guides &amp; records</small>
          </div>
        </div>
        <div class="admin-mini">
          <div class="avatar user-avatar" data-user-avatar title="School Administrator">AD</div>
          <div class="admin-meta">
            <b data-user-name>Administrator</b>
            <small data-user-role>Academic Command</small>
          </div>
          <a class="rail-exit" href="../superAdminLogin.html" data-logout title="Log out" aria-label="Log out">
            <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
              <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
              <polyline points="16 17 21 12 16 7"></polyline>
              <line x1="21" y1="12" x2="9" y2="12"></line>
            </svg>
          </a>
        </div>
      `;
      rail.appendChild(bottom);
      bindLogout(bottom);
    }

    // Wire up Drawer & Theme toggle
    const menuBtn = document.getElementById("menuBtn");
    const sidebarClose = document.getElementById("sidebarClose");

    const toggleDrawer = function (open) {
      rail.classList.toggle("mobile-open", open);
      scrim.classList.toggle("show", open);
    };

    if (menuBtn) {
      menuBtn.onclick = function () { toggleDrawer(true); };
    }
    if (sidebarClose) {
      sidebarClose.onclick = function () { toggleDrawer(false); };
    }
    if (scrim) {
      scrim.onclick = function () { toggleDrawer(false); };
    }

    const themeBtn = document.getElementById("themeBtn");
    if (themeBtn) {
      themeBtn.onclick = function () {
        const current = document.documentElement.getAttribute("data-theme") || "light";
        const next = current === "dark" ? "light" : "dark";
        document.documentElement.setAttribute("data-theme", next);
        localStorage.setItem("srs_theme", next);
        themeBtn.innerHTML = next === "dark" ? icons.sun : icons.moon;
      };
    }
  };

  /* Initialize structure */
  ensureSidebarStructure();
  ensureTopbar();
  bindLogout(document);

  /* Clock */
  const updateClocks = function () {
    const timeStr = new Date().toLocaleTimeString("en-GB", {
      timeZone: "Africa/Lagos",
      hour: "2-digit",
      minute: "2-digit",
      second: "2-digit",
      hour12: false
    });
    document.querySelectorAll("[data-clock]").forEach(function (clockNode) {
      clockNode.textContent = timeStr;
    });
  };
  updateClocks();
  setInterval(updateClocks, 1000);

  const greeting = document.querySelector("[data-greeting]");
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

  const populateUserData = function (me) {
    if (!me) return;
    const name = me.name || "Administrator";
    const role = isSuper(me) ? "Super Administrator" : "School Administrator";
    const initials = name.split(" ").map(function (n) { return n[0]; }).slice(0, 2).join("").toUpperCase() || "AD";

    document.querySelectorAll("[data-user-name]").forEach(function (el) { el.textContent = name; });
    document.querySelectorAll("[data-user-role]").forEach(function (el) { el.textContent = role; });
    document.querySelectorAll("[data-user-avatar]").forEach(function (el) {
      el.textContent = initials;
      el.title = name;
    });
  };

  const ensureRolesRail = function (me) {
    const nav = document.querySelector(".rail-nav");
    if (!nav) return;
    const permissions = (me && me.permissions) || [];
    const canSee = !!(me && (isSuper(me) || hasAny(permissions, pagePermission.roles)));
    let link = nav.querySelector('a[href="roles.html"], a[href="/portal/roles"]');
    if (!canSee) {
      if (link) link.remove();
      return;
    }
    if (!link) {
      link = document.createElement("a");
      link.className = "rail-btn";
      link.href = "/portal/roles";
      link.innerHTML = icons.roles + "<span>Roles</span>";
      const setup = nav.querySelector('a[href="settings.html"], a[href="/portal/settings"]');
      if (setup) nav.insertBefore(link, setup);
      else nav.appendChild(link);
    } else if (link.getAttribute("href") === "roles.html") {
      link.href = "/portal/roles";
      if (!link.querySelector("svg")) link.insertAdjacentHTML("afterbegin", icons.roles);
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
    const canSee = !!(me && (isSuper(me) || hasExact(permissions, pagePermission.admins)));
    let link = nav.querySelector('a[href="admins.html"], a[href="/portal/admins"]');
    if (!canSee) {
      if (link) link.remove();
      return;
    }
    if (!link) {
      link = document.createElement("a");
      link.className = "rail-btn";
      link.href = "/portal/admins";
      link.innerHTML = icons.admins + "<span>Admins</span>";
      const setup = nav.querySelector('a[href="settings.html"], a[href="/portal/settings"]');
      if (setup) nav.insertBefore(link, setup);
      else nav.appendChild(link);
    } else if (link.getAttribute("href") === "admins.html") {
      link.href = "/portal/admins";
      if (!link.querySelector("svg")) link.insertAdjacentHTML("afterbegin", icons.admins);
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
      link.innerHTML = icons.account + "<span>Profile</span>";
      nav.appendChild(link);
    } else if (link.getAttribute("href") === "account.html") {
      link.href = "/portal/account";
      if (!link.querySelector("svg")) link.insertAdjacentHTML("afterbegin", icons.account);
    }
    if (document.body.getAttribute("data-page") === "account") {
      link.classList.add("active");
      link.setAttribute("aria-current", "page");
    }
  };

  const ensureCbtRail = function (me) {
    const nav = document.querySelector(".rail-nav");
    if (!nav) return;
    const permissions = (me && me.permissions) || [];
    const needed = ["cbt.view", "cbt.manage", "cbt.proctor", "cbt.mark"];
    const canSee = !!(me && (isSuper(me) || hasAny(permissions, needed)));
    let link = nav.querySelector('a[href="/cbt/login"], a[href="/cbt"], a[data-cbt-entry]');
    if (!canSee) {
      if (link) link.remove();
      return;
    }
    if (!link) {
      link = document.createElement("a");
      link.className = "rail-btn";
      link.setAttribute("data-cbt-entry", "1");
      link.href = "/cbt/login";
      link.innerHTML = icons.cbt + "<span>CBT</span>";
      const marks = nav.querySelector('a[href="grades.html"], a[href="/portal/grades"]');
      if (marks && marks.nextSibling) nav.insertBefore(link, marks.nextSibling);
      else if (marks) nav.insertBefore(link, marks.nextSibling);
      else nav.appendChild(link);
    }
  };

  const hideEmptyWingGroup = function () {
    const wings = document.querySelector(".rail-wings");
    if (!wings) return;
    const visible = Array.prototype.slice.call(wings.querySelectorAll("a.rail-btn"))
      .some(function (link) { return !link.hidden; });
    wings.hidden = !visible;
  };

  const applyDeskAccess = function (me) {
    if (!me) return;
    window.srsMe = me;
    populateUserData(me);
    ensureRolesRail(me);
    ensureAdminsRail(me);
    ensureAccountRail();
    ensureCbtRail(me);

    if (isSuper(me)) {
      hideEmptyWingGroup();
      return;
    }

    const owned = me.permissions || [];
    const page = document.body.getAttribute("data-page") || "";
    const needed = pagePermission[page] || pagePermission[page.replace(/-/g, "_")];

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
      const required = requiredForHref(link.getAttribute("href") || "");
      if (!required || !required.length) return;
      const key = hrefKey(link.getAttribute("href") || "");
      const check = key === "admins" ? hasExact : hasAny;
      if (!check(owned, required)) link.hidden = true;
    });

    hideEmptyWingGroup();
  };

  window.srsHasPermission = function (slug) {
    const me = window.srsMe;
    if (!me) return false;
    if (isSuper(me)) return true;
    const owned = me.permissions || [];
    if (owned.indexOf("desk.administer") !== -1 && String(slug).indexOf("admins.") !== 0) return true;
    return owned.indexOf(slug) !== -1;
  };

  window.srsHasAnyPermission = function (slugs) {
    if (!slugs || !slugs.length) return true;
    return slugs.some(function (slug) { return window.srsHasPermission(slug); });
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

  const toast = document.querySelector("[data-toast]");
  if (toast) {
    window.setTimeout(function () { toast.classList.add("show"); }, 400);
    window.setTimeout(function () { toast.classList.remove("show"); }, 4200);
  }
})();