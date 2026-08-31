(function () {
  const toggle = document.querySelector(".menu-toggle");
  const menu = document.getElementById("classicMenu");
  const navbar = document.getElementById("mainNavbar");
  const ink = document.querySelector(".nav-ink");
  const houseNav = document.querySelector(".house-nav");
  const progress = document.querySelector(".scroll-progress i");
  const clock = document.querySelector("[data-house-clock]");
  const lamp = document.querySelector("[data-house-lamp]");

  const here = (location.pathname.replace(/\/+$/, "") || "/").toLowerCase();
  const houseRoots = ["/nursery", "/primary", "/secondary", "/branches", "/resources"];

  const pathOf = function (href) {
    if (!href) return "";
    try {
      return (new URL(href, location.origin).pathname.replace(/\/+$/, "") || "/").toLowerCase();
    } catch (error) {
      return href.replace("./", "").split("#")[0].toLowerCase();
    }
  };

  const stem = function (value) {
    return (value || "").replace(/\.html$/, "");
  };

  const isHouseHere = houseRoots.some(function (root) {
    return here === root || here.indexOf(root + "/") === 0 || stem(here) === root;
  });

  const isHere = function (href) {
    const path = pathOf(href);
    if (!path) return false;
    const file = path.split("/").pop();
    const currentFile = here.split("/").pop();
    const isHomeHref = path === "/" || stem(file) === "index";
    const isHomePage = here === "/" || here === "/index" || here === "/index.html";
    if (isHomeHref) return isHomePage;
    if (path === here || stem(path) === here || path === here + ".html") return true;
    if (here.indexOf(path + "/") === 0 || here.indexOf(stem(path) + "/") === 0) return true;
    return file !== "" && (file === currentFile || file === currentFile + ".html" || stem(file) === stem(currentFile));
  };

  const setMenuOpen = function (open) {
    if (!toggle || !menu) return;
    toggle.classList.toggle("is-open", open);
    menu.classList.toggle("is-open", open);
    document.body.classList.toggle("menu-open", open);
    toggle.setAttribute("aria-expanded", open ? "true" : "false");
    toggle.setAttribute("aria-label", open ? "Close menu" : "Open menu");
    if (open) closePanels();
  };

  const closePanels = function () {
    document.querySelectorAll(".house-item.is-open, .portal-dock.is-open").forEach(function (node) {
      node.classList.remove("is-open");
      const trigger = node.querySelector("[aria-expanded]");
      if (trigger) trigger.setAttribute("aria-expanded", "false");
    });
  };

  const moveInk = function (target) {
    if (!ink || !houseNav || !target) return;
    const navBox = houseNav.getBoundingClientRect();
    const box = target.getBoundingClientRect();
    ink.style.left = (box.left - navBox.left) + "px";
    ink.style.width = box.width + "px";
  };

  const currentLink = function () {
    if (!houseNav) return null;
    const links = houseNav.querySelectorAll(".house-link, .house-trigger");
    let match = null;
    links.forEach(function (link) {
      const href = link.getAttribute("href") || "";
      const isHome = link.getAttribute("data-nav") === "home" && (here === "/" || here === "/index.html" || here === "/index");
      const isHouse = link.classList.contains("house-trigger") && isHouseHere;
      if (isHome || isHouse || isHere(href)) {
        match = link;
        link.classList.add("is-current");
        if (!link.classList.contains("house-trigger")) {
          link.setAttribute("aria-current", "page");
        }
      }
    });
    return match || houseNav.querySelector(".house-link.is-current, .house-trigger.is-current");
  };

  const syncChrome = function () {
    const scrolled = window.scrollY > 18;
    document.body.classList.toggle("header-visible", true);
    document.body.classList.toggle("is-scrolled", scrolled);
    if (navbar) navbar.classList.toggle("scrolled", window.scrollY > 40);
    if (scrollTopBtn) scrollTopBtn.classList.toggle("is-visible", window.scrollY > 360);
    if (progress) {
      const max = Math.max(1, document.documentElement.scrollHeight - window.innerHeight);
      progress.style.width = Math.min(100, (window.scrollY / max) * 100) + "%";
    }
  };

  const tickHouse = function () {
    const now = new Date();
    const parts = new Intl.DateTimeFormat("en-GB", {
      timeZone: "Africa/Lagos",
      hour: "2-digit",
      minute: "2-digit",
      hour12: false
    }).formatToParts(now);
    const hour = Number((parts.find(function (part) { return part.type === "hour"; }) || {}).value || 0);
    const minute = (parts.find(function (part) { return part.type === "minute"; }) || {}).value || "00";
    if (clock) clock.textContent = String(hour).padStart(2, "0") + ":" + minute + " Lagos";
    const open = hour >= 8 && hour < 16;
    if (lamp) {
      lamp.classList.toggle("is-rest", !open);
      const label = lamp.querySelector("[data-house-state]");
      if (label) label.textContent = open ? "The house is open" : "The house is at rest";
    }
  };

  const wing = menu ? menu.querySelector(".classic-menu-wing") : null;
  const wingTrigger = menu ? menu.querySelector(".classic-menu-house-trigger") : null;

  const setWingOpen = function (open) {
    if (!wing || !wingTrigger) return;
    wing.classList.toggle("is-open", open);
    wingTrigger.setAttribute("aria-expanded", open ? "true" : "false");
  };

  if (toggle && menu) {
    toggle.addEventListener("click", function () {
      setMenuOpen(!menu.classList.contains("is-open"));
    });

    menu.querySelectorAll("a").forEach(function (link) {
      link.addEventListener("click", function () {
        setMenuOpen(false);
      });
    });
  }

  if (wingTrigger) {
    wingTrigger.addEventListener("click", function (event) {
      event.preventDefault();
      event.stopPropagation();
      setWingOpen(!wing.classList.contains("is-open"));
    });
  }

  document.querySelectorAll(".house-trigger, .portal-trigger").forEach(function (trigger) {
    trigger.addEventListener("click", function (event) {
      event.preventDefault();
      const dock = trigger.closest(".house-item, .portal-dock");
      if (!dock) return;
      const open = !dock.classList.contains("is-open");
      closePanels();
      dock.classList.toggle("is-open", open);
      trigger.setAttribute("aria-expanded", open ? "true" : "false");
    });
  });

  document.addEventListener("click", function (event) {
    if (!event.target.closest(".house-item, .portal-dock")) closePanels();
  });

  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") {
      setMenuOpen(false);
      closePanels();
    }
  });

  if (menu) {
    menu.querySelectorAll("a[href]").forEach(function (link) {
      if (isHere(link.getAttribute("href") || "")) {
        link.classList.add("is-current");
        link.setAttribute("aria-current", "page");
      }
    });
    if (wing && isHouseHere) {
      wing.classList.add("is-current");
      setWingOpen(true);
    }
  }

  if (houseNav) {
    const active = currentLink();
    moveInk(active);
    houseNav.querySelectorAll(".house-link, .house-trigger").forEach(function (link) {
      link.addEventListener("mouseenter", function () {
        moveInk(link);
      });
    });
    houseNav.addEventListener("mouseleave", function () {
      moveInk(currentLink());
    });
    window.addEventListener("resize", function () {
      moveInk(currentLink());
    });
  }

  const scrollTopBtn = document.createElement("button");
  scrollTopBtn.className = "scroll-top";
  scrollTopBtn.type = "button";
  scrollTopBtn.setAttribute("aria-label", "Back to top");
  scrollTopBtn.innerHTML = '<i class="bi bi-chevron-up"></i>';
  document.body.appendChild(scrollTopBtn);

  const easeOutCubic = function (t) {
    return 1 - Math.pow(1 - t, 3);
  };

  const animateToTop = function () {
    const start = window.scrollY;
    if (start < 8) return;
    const duration = 1100;
    const began = performance.now();

    const tick = function (now) {
      const progressValue = Math.min(1, (now - began) / duration);
      window.scrollTo(0, Math.round(start * (1 - easeOutCubic(progressValue))));
      if (progressValue < 1) requestAnimationFrame(tick);
    };

    requestAnimationFrame(tick);
  };

  scrollTopBtn.addEventListener("click", function () {
    if (scrollTopBtn.classList.contains("is-waiting")) return;
    scrollTopBtn.classList.add("is-waiting");
    setTimeout(function () {
      animateToTop();
      scrollTopBtn.classList.remove("is-waiting");
    }, 420);
  });

  window.addEventListener("scroll", syncChrome, { passive: true });
  syncChrome();
  tickHouse();
  setInterval(tickHouse, 30000);
})();
