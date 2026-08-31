(function () {
  const page = document.body;
  const form = document.querySelector("[data-auth-form]");
  const clock = document.querySelector("[data-clock]");
  const media = document.querySelector("[data-parallax]");
  const dust = document.querySelector("[data-dust]");

  const alertBox = document.querySelector("[data-auth-alert]");
  const submit = document.querySelector("[data-auth-submit]");

  const csrfToken = function () {
    const field = form && form.querySelector('input[name="_token"]');
    if (field && field.value && field.value !== "{{CSRF_TOKEN}}") {
      return field.value;
    }
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : "";
  };

  const showError = function (message) {
    if (!alertBox) return;
    alertBox.textContent = message;
    alertBox.classList.add("is-visible");
  };

  const clearError = function () {
    if (!alertBox) return;
    alertBox.textContent = "";
    alertBox.classList.remove("is-visible");
  };

  const greeting = document.querySelector("[data-greeting]");
  if (greeting) {
    const hour = Number(new Date().toLocaleString("en-GB", {
      timeZone: "Africa/Lagos",
      hour: "numeric",
      hour12: false
    }));
    greeting.textContent = hour < 12 ? "Good morning." : hour < 16 ? "Good afternoon." : "Good evening.";
  }

  if (clock) {
    const tick = function () {
      clock.textContent = new Date().toLocaleTimeString("en-GB", {
        timeZone: "Africa/Lagos",
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit",
        hour12: false
      }) + "  WAT";
    };
    tick();
    setInterval(tick, 1000);
  }

  document.querySelectorAll("[data-toggle-pass]").forEach(function (button) {
    button.addEventListener("click", function () {
      const input = document.getElementById(button.getAttribute("aria-controls"));
      if (!input) return;
      const reveal = input.type === "password";
      input.type = reveal ? "text" : "password";
      button.setAttribute("aria-pressed", String(reveal));
      button.setAttribute("aria-label", reveal ? "Hide password" : "Show password");
    });
  });

  const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  if (media && !reduceMotion && window.matchMedia("(pointer: fine)").matches) {
    window.addEventListener("mousemove", function (event) {
      const x = (event.clientX / window.innerWidth - 0.5) * 18;
      const y = (event.clientY / window.innerHeight - 0.5) * 14;
      media.style.transform = "scale(1.08) translate(" + x + "px, " + y + "px)";
    });
  }

  if (!reduceMotion && dust && dust.getContext) {
    const context = dust.getContext("2d");
    const resize = function () {
      dust.width = dust.offsetWidth;
      dust.height = dust.offsetHeight;
    };
    resize();
    window.addEventListener("resize", resize);

    const motes = Array.from({ length: 46 }, function () {
      return {
        x: Math.random(),
        y: Math.random(),
        r: Math.random() * 1.4 + 0.25,
        s: Math.random() * 0.22 + 0.05,
        a: Math.random() * 0.45 + 0.15
      };
    });

    const draw = function () {
      context.clearRect(0, 0, dust.width, dust.height);
      motes.forEach(function (mote) {
        mote.y -= mote.s / 140;
        if (mote.y < 0) mote.y = 1;
        context.beginPath();
        context.fillStyle = "rgba(232, 195, 122, " + mote.a + ")";
        context.arc(mote.x * dust.width, mote.y * dust.height, mote.r, 0, Math.PI * 2);
        context.fill();
      });
      requestAnimationFrame(draw);
    };
    draw();
  }

  if (form) {
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      clearError();

      const payload = {
        email: form.email.value,
        password: form.password.value,
        portal: (form.portal && form.portal.value) || "portal",
        remember: form.remember && form.remember.checked
      };

      if (submit) {
        submit.disabled = true;
        submit.classList.add("is-busy");
      }

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
          showError((result.body && result.body.message) || "Unable to sign in.");
          if (submit) {
            submit.disabled = false;
            submit.classList.remove("is-busy");
          }
          return;
        }

        page.classList.add("is-unlocking");
        const destination = (result.body && result.body.data && result.body.data.redirect) || "/portal/home";
        window.setTimeout(function () {
          window.location.href = destination;
        }, 1700);
      }).catch(function () {
        showError("Unable to reach the school office. Please try again.");
        if (submit) {
          submit.disabled = false;
          submit.classList.remove("is-busy");
        }
      });
    });
  }
})();
