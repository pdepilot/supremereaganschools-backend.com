(function () {
  const clock = document.querySelector("[data-clock]");
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
    window.setInterval(tick, 1000);
  }

  document.querySelectorAll("[data-toggle-pass]").forEach(function (button) {
    button.addEventListener("click", function () {
      const field = document.getElementById(button.getAttribute("aria-controls"));
      if (!field) return;
      const shown = field.type === "text";
      field.type = shown ? "password" : "text";
      button.setAttribute("aria-pressed", shown ? "false" : "true");
      button.textContent = shown ? "Show" : "Hide";
      button.setAttribute("aria-label", shown ? "Show password" : "Hide password");
    });
  });
})();
