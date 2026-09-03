(function () {
  const banner = document.querySelector("[data-consent-banner]");
  if (!banner) {
    return;
  }

  const readConsent = function () {
    const match = document.cookie.match(/(?:^|; )srs_consent=([^;]*)/);
    if (!match) {
      return null;
    }
    try {
      return JSON.parse(decodeURIComponent(match[1]));
    } catch (error) {
      return null;
    }
  };

  const reveal = function (show) {
    banner.hidden = !show;
    document.body.classList.toggle("is-consenting", show);
  };

  if (!readConsent()) {
    reveal(true);
  }

  const token = function () {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : "";
  };

  const send = function (ads, analytics) {
    const body = new URLSearchParams();
    body.set("ads", ads ? "1" : "0");
    body.set("analytics", analytics ? "1" : "0");
    const csrf = document.querySelector('meta[name="csrf-token"], input[name="_token"]');
    if (csrf) {
      const value = csrf.getAttribute("content") || csrf.value;
      if (value) body.set("_token", value);
    }

    return fetch("/privacy/consent", {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Accept": "application/json",
        "X-Requested-With": "XMLHttpRequest",
        "X-XSRF-TOKEN": token(),
        "Content-Type": "application/x-www-form-urlencoded"
      },
      body: body.toString()
    }).then(function (response) {
      if (!response.ok) {
        throw new Error("Consent could not be saved.");
      }
      reveal(false);
    }).catch(function () {
      reveal(true);
    });
  };

  banner.addEventListener("submit", function (event) {
    event.preventDefault();
    const submitter = event.submitter;
    const all = submitter && submitter.hasAttribute("data-consent-all");
    const analyticsOnly = submitter && submitter.getAttribute("name") === "analytics";
    send(!!all, !!(all || analyticsOnly));
  });
})();
