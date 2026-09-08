(function () {
  const studentFields = document.querySelectorAll("[data-cbt-student-field]");
  const staffFields = document.querySelectorAll("[data-cbt-staff-field]");
  const modeButtons = document.querySelectorAll("[data-cbt-mode]");
  const secretLabel = document.querySelector("[data-cbt-secret-label]");
  const admission = document.getElementById("admission-number");
  const email = document.getElementById("email");

  const setMode = function (mode) {
    const student = mode === "student";
    studentFields.forEach(function (node) { node.hidden = !student; });
    staffFields.forEach(function (node) { node.hidden = student; });

    if (admission) {
      admission.required = student;
      admission.disabled = !student;
      if (!student) admission.value = "";
    }
    if (email) {
      email.required = !student;
      email.disabled = student;
      if (student) email.value = "";
    }
    if (secretLabel) {
      secretLabel.textContent = student ? "Parent’s phone / passphrase" : "CBT desk password";
    }
    modeButtons.forEach(function (button) {
      const active = button.getAttribute("data-cbt-mode") === mode;
      button.setAttribute("aria-pressed", active ? "true" : "false");
      button.classList.toggle("is-active", active);
    });
  };

  modeButtons.forEach(function (button) {
    button.addEventListener("click", function () {
      setMode(button.getAttribute("data-cbt-mode") || "student");
    });
  });

  setMode("student");

  if (new URLSearchParams(window.location.search).get("from") === "logout") {
    const alertBox = document.querySelector("[data-auth-alert]");
    if (alertBox) {
      alertBox.hidden = false;
      alertBox.classList.add("is-visible");
      alertBox.textContent = "Signed out of CBT. You can return to the office portal below.";
    }
  }
})();
