(function () {
  const csrfToken = function () {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : "";
  };

  document.querySelectorAll("[data-enquiry-form]").forEach(function (form) {
    let started = false;
    form.addEventListener("focusin", function () {
      if (started) return;
      started = true;
      if (window.srsTrack) window.srsTrack("admission_enquiry_started", { type: "enquiry" });
    });

    form.addEventListener("submit", function (event) {
      event.preventDefault();
      const notice = form.querySelector("[data-enquiry-notice]");
      const payload = {
        name: form.querySelector("[name='name']").value,
        email: form.querySelector("[name='email']").value,
        phone: form.querySelector("[name='phone']").value,
        subject: (form.querySelector("[name='subject']") && form.querySelector("[name='subject']").value) || "Admission enquiry",
        message: form.querySelector("[name='message']").value,
        intended_level: form.querySelector("[name='intended_level']") ? form.querySelector("[name='intended_level']").value : "",
        enquiry_type: form.querySelector("[name='enquiry_type']") ? form.querySelector("[name='enquiry_type']").value : "",
        source_url: form.querySelector("[name='source_url']") ? form.querySelector("[name='source_url']").value : window.location.href,
        source_post_id: form.querySelector("[name='source_post_id']") ? form.querySelector("[name='source_post_id']").value : null
      };

      fetch("/api/v1/contact-enquiries", {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Accept": "application/json",
          "Content-Type": "application/json",
          "X-Requested-With": "XMLHttpRequest",
          "X-XSRF-TOKEN": csrfToken()
        },
        body: JSON.stringify(payload)
      }).then(function (response) {
        return response.json().then(function (body) {
          return { ok: response.ok, body: body };
        });
      }).then(function (result) {
        if (!result.ok) {
          if (notice) notice.textContent = (result.body && result.body.message) || "The office could not receive that letter.";
          return;
        }
        form.reset();
        if (notice) notice.textContent = "Received. The office will write back to the address you gave.";
        if (window.srsTrack) window.srsTrack("admission_enquiry_submitted", { type: "enquiry" });
      }).catch(function () {
        if (notice) notice.textContent = "The office could not receive that letter.";
      });
    });
  });
})();
