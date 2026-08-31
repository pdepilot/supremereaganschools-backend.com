(function () {
  const allowed = {
    article_view: true,
    article_cta_click: true,
    admission_enquiry_started: true,
    admission_enquiry_submitted: true,
    application_started: true
  };

  window.srsTrack = function (name, params) {
    if (!allowed[name]) return;
    const safe = {};
    if (params && params.slug) safe.content_slug = String(params.slug).slice(0, 80);
    if (params && params.type) safe.content_type = String(params.type).slice(0, 40);
    if (params && params.target) safe.target = String(params.target).slice(0, 80);
    if (typeof window.gtag === "function") {
      window.gtag("event", name, safe);
    }
  };

  const article = document.querySelector("[data-article-view]");
  if (article) {
    window.srsTrack("article_view", { slug: article.getAttribute("data-article-slug") });
  }

  document.addEventListener("click", function (event) {
    const link = event.target.closest("[data-analytics='article_cta_click']");
    if (link) {
      window.srsTrack("article_cta_click", {
        slug: article ? article.getAttribute("data-article-slug") : "",
        target: link.getAttribute("data-cta-target") || link.getAttribute("href")
      });
    }
  });
})();
