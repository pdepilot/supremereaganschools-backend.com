(function () {
  const csrfToken = function () {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : "";
  };

  const request = function (url, options) {
    const headers = Object.assign({
      "Accept": "application/json",
      "X-Requested-With": "XMLHttpRequest",
      "X-XSRF-TOKEN": csrfToken()
    }, options && options.headers);

    if (options && options.body && !(options.body instanceof FormData) && !headers["Content-Type"] && typeof options.body === "string") {
      headers["Content-Type"] = "application/json";
    }

    return fetch(url, Object.assign({ credentials: "same-origin" }, options, { headers })).then(function (response) {
      if (response.status === 401) {
        window.location.replace("/portal/login");
      }
      return response.json().then(function (body) {
        return { ok: response.ok, status: response.status, body: body };
      }).catch(function () {
        return { ok: false, status: response.status, body: {} };
      });
    });
  };

  const firstError = function (body) {
    if (body && body.errors) {
      const keys = Object.keys(body.errors);
      if (keys.length) return body.errors[keys[0]][0];
    }
    return (body && body.message) || "The office could not complete that request.";
  };

  const escapeHtml = function (value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  };

  const $ = function (sel) { return document.querySelector(sel); };

  const imageUrl = function (row) {
    return (row && (row.featured_image_url || row.featured_image)) || "";
  };

  const showImagePreview = function (src, alt) {
    const box = $("[data-news-image-preview]");
    const img = box && box.querySelector("img");
    if (!box || !img) return;
    if (!src) {
      box.hidden = true;
      img.removeAttribute("src");
      return;
    }
    img.src = src;
    img.alt = alt || "Featured image";
    box.hidden = false;
  };

  const loadTaxonomy = function () {
    return Promise.all([
      request("/api/v1/post-categories"),
      request("/api/v1/post-tags")
    ]).then(function (results) {
      const categories = (results[0].body.data || []);
      const tags = (results[1].body.data || []);
      const select = $("#newsCategory");
      if (select) {
        select.innerHTML = categories.map(function (row) {
          return '<option value="' + row.id + '">' + escapeHtml(row.name) + "</option>";
        }).join("");
      }
      const tagBox = $("[data-news-tags]");
      if (tagBox) {
        tagBox.innerHTML = tags.map(function (row) {
          return '<label><input type="checkbox" value="' + row.id + '"> ' + escapeHtml(row.name) + "</label>";
        }).join("");
      }
      const catBoard = $("[data-category-board]");
      if (catBoard) {
        catBoard.innerHTML = categories.map(function (row) {
          return '<p><button type="button" data-edit-cat="' + row.id + '">' + escapeHtml(row.name) + (row.is_active ? "" : " (off)") + "</button></p>";
        }).join("");
      }
      const tagBoard = $("[data-tag-board]");
      if (tagBoard) {
        tagBoard.innerHTML = tags.map(function (row) {
          return '<p><button type="button" data-edit-tag="' + row.id + '">' + escapeHtml(row.name) + "</button></p>";
        }).join("");
      }
      window.__srsCats = categories;
      window.__srsTags = tags;
    });
  };

  let newsPage = 1;

  const renderPager = function (meta) {
    const el = $("[data-news-pager]");
    if (!el) return;
    const current = (meta && meta.current_page) || 1;
    const last = (meta && meta.last_page) || 1;
    const total = (meta && meta.total) || 0;
    const from = meta && meta.from;
    const to = meta && meta.to;
    newsPage = current;
    if (!total) {
      el.innerHTML = "";
      return;
    }
    let buttons = "";
    if (current > 1) {
      buttons += '<button class="ghost-btn" type="button" data-news-page="' + (current - 1) + '">Previous</button>';
    }
    for (let i = 1; i <= last; i++) {
      buttons += '<button class="ghost-btn' + (i === current ? " is-current" : "") + '" type="button" data-news-page="' + i + '"' + (i === current ? ' aria-current="page"' : "") + ">" + i + "</button>";
    }
    if (current < last) {
      buttons += '<button class="ghost-btn" type="button" data-news-page="' + (current + 1) + '">Next</button>';
    }
    el.innerHTML = '<p class="news-desk-count">Showing ' + (from || 0) + "–" + (to || 0) + " of " + total + "</p>" +
      (last > 1 ? '<div class="news-desk-pages">' + buttons + "</div>" : "");
  };

  const loadPosts = function (page) {
    if (page) newsPage = page;
    const q = ($("#newsSearch") && $("#newsSearch").value) || "";
    const status = ($("#newsStatusFilter") && $("#newsStatusFilter").value) || "";
    const params = new URLSearchParams();
    if (q) params.set("q", q);
    if (status) params.set("status", status);
    params.set("page", String(newsPage));
    return request("/api/v1/posts?" + params.toString()).then(function (result) {
      const data = result.body.data || {};
      const summary = data.summary || {};
      ["total", "published", "drafts", "scheduled", "featured", "needs_seo", "needs_image", "needs_review"].forEach(function (key) {
        const el = document.querySelector("[data-metric=\"" + key + "\"]");
        if (el) el.textContent = summary[key] == null ? "—" : summary[key];
      });
      const board = $("[data-news-board]");
      const copy = $("[data-news-copy]");
      if (copy) copy.textContent = (data.meta && data.meta.total != null) ? data.meta.total + " articles on the desk" : "";
      renderPager(data.meta || {});
      if (!board) return;
      const items = data.items || [];
      const calendar = $("[data-news-calendar]");
      if (calendar) {
        const upcoming = summary.calendar || [];
        calendar.innerHTML = upcoming.length
          ? upcoming.map(function (row) {
              return "<p>" + escapeHtml(row.scheduled_at || "") + " — " + escapeHtml(row.title || "") + "</p>";
            }).join("")
          : "<p>No scheduled articles.</p>";
      }
      if (!items.length) {
        board.innerHTML = "<p>No articles in this view.</p>";
        if ((data.meta && data.meta.current_page) > 1) {
          return loadPosts(data.meta.current_page - 1);
        }
        return;
      }
      board.innerHTML = items.map(function (row) {
        const views = row.views_count == null ? 0 : row.views_count;
        const src = imageUrl(row);
        const photo = src
          ? '<img class="ticket-photo" src="' + escapeHtml(src) + '" alt="' + escapeHtml(row.featured_image_alt || row.title || "Article image") + '">'
          : '<span class="ticket-photo is-empty">No image</span>';
        return '<article class="ticket">' +
          photo +
          "<div><h3>" + escapeHtml(row.title) + "</h3><p>" +
          escapeHtml(row.status || "") + " · " + escapeHtml((row.category && row.category.name) || "") +
          " · " + views + " " + (views === 1 ? "view" : "views") +
          "</p></div>" +
          '<span class="badge">' + escapeHtml(row.status || "") + "</span>" +
          '<div class="row-actions">' +
          '<button class="ghost-btn" type="button" data-edit-post="' + row.id + '">Edit</button>' +
          '<button class="ghost-btn" type="button" data-delete-post="' + row.id + '" data-title="' + escapeHtml(row.title) + '">Delete</button>' +
          "</div></article>";
      }).join("");
    });
  };

  const editor = $("[data-news-editor]");
  document.querySelectorAll("[data-cmd]").forEach(function (button) {
    button.addEventListener("click", function () {
      if (!editor) return;
      const cmd = button.getAttribute("data-cmd");
      if (cmd === "h2" || cmd === "h3") document.execCommand("formatBlock", false, cmd);
      else if (cmd === "ul") document.execCommand("insertUnorderedList");
      else if (cmd === "ol") document.execCommand("insertOrderedList");
      else if (cmd === "quote") document.execCommand("formatBlock", false, "blockquote");
      else if (cmd === "link") {
        const href = window.prompt("Link address");
        if (href) document.execCommand("createLink", false, href);
      } else document.execCommand(cmd);
    });
  });

  const fillForm = function (row) {
    $("#newsId").value = row.id || "";
    $("#newsTitle").value = row.title || "";
    $("#newsExcerpt").value = row.excerpt || "";
    $("#newsStatus").value = row.status || "draft";
    if (row.category && row.category.id) $("#newsCategory").value = row.category.id;
    if (editor) editor.innerHTML = row.content || "";
    $("#newsImageAlt").value = row.featured_image_alt || "";
    showImagePreview(imageUrl(row), row.featured_image_alt || row.title || "");
    if ($("#newsImage")) $("#newsImage").value = "";
    $("#newsMetaTitle").value = row.meta_title || "";
    $("#newsMetaDescription").value = row.meta_description || "";
    $("#newsCanonical").value = row.canonical_url || "";
    $("#newsOgTitle").value = row.og_title || "";
    $("#newsFeatured").checked = !!row.is_featured;
    $("#newsPinned").checked = !!row.is_pinned;
    $("#newsAds").checked = row.ads_enabled !== false;
    $("#newsIndex").checked = row.indexable !== false;
    $("#newsComments").checked = !!row.allow_comments;
    if ($("#newsType")) $("#newsType").value = row.content_type || "article";
    if ($("#newsCtaType")) $("#newsCtaType").value = row.cta_type || "";
    if ($("#newsCtaStrength")) $("#newsCtaStrength").value = row.cta_strength || "standard";
    if ($("#newsAudience")) $("#newsAudience").value = row.audience || "";
    if ($("#newsLevel")) $("#newsLevel").value = row.educational_level || "";
    if ($("#newsIntent")) $("#newsIntent").value = row.intent || "";
    if ($("#newsPillar")) $("#newsPillar").value = row.pillar_topic || "";
    if ($("#newsSupport")) $("#newsSupport").value = row.supporting_topic || "";
    if ($("#newsParent")) $("#newsParent").checked = !!row.is_parent_resource;
    if ($("#newsChild")) $("#newsChild").checked = !!row.child_directed;
    if ($("#newsReviewed")) $("#newsReviewed").value = row.last_reviewed_at ? String(row.last_reviewed_at).slice(0, 10) : "";
    if ($("#newsReviewDue")) $("#newsReviewDue").value = row.review_due_at ? String(row.review_due_at).slice(0, 10) : "";
    const list = $("[data-news-checklist]");
    if (list) {
      list.innerHTML = (row.checklist || []).map(function (item) {
        return "<li>" + (item.ok ? "Ready" : "Needs work") + " — " + escapeHtml(item.label || "") + "</li>";
      }).join("");
    }
    const ids = (row.tags || []).map(function (tag) { return String(tag.id); });
    document.querySelectorAll("[data-news-tags] input").forEach(function (box) {
      box.checked = ids.indexOf(box.value) !== -1;
    });
    $("[data-news-warnings]").textContent = (row.warnings || []).join(" ");
    $("[data-news-preview]").hidden = !row.id;
    $("[data-news-remove]").hidden = !row.id;
    $("[data-news-form-title]").textContent = row.id ? "Edit article" : "Compose an article";
    if (row.id) $("[data-news-preview]").setAttribute("data-id", row.id);
    if ($("#newsScheduled") && row.scheduled_at) {
      $("#newsScheduled").value = String(row.scheduled_at).slice(0, 16);
    }
    const formPanel = document.querySelector("[data-news-form]");
    if (formPanel && row.id) formPanel.scrollIntoView({ behavior: "smooth", block: "start" });
  };

  const payload = function () {
    const tags = Array.prototype.slice.call(document.querySelectorAll("[data-news-tags] input:checked")).map(function (box) {
      return parseInt(box.value, 10);
    });
    return {
      title: $("#newsTitle").value,
      excerpt: $("#newsExcerpt").value,
      content: editor ? editor.innerHTML : "",
      category_id: parseInt($("#newsCategory").value, 10),
      status: $("#newsStatus").value,
      tag_ids: tags,
      featured_image_alt: $("#newsImageAlt").value,
      meta_title: $("#newsMetaTitle").value,
      meta_description: $("#newsMetaDescription").value,
      canonical_url: $("#newsCanonical").value || null,
      og_title: $("#newsOgTitle").value,
      is_featured: $("#newsFeatured").checked,
      is_pinned: $("#newsPinned").checked,
      ads_enabled: $("#newsAds").checked,
      indexable: $("#newsIndex").checked,
      allow_comments: $("#newsComments").checked,
      scheduled_at: $("#newsScheduled").value || null,
      content_type: $("#newsType") ? $("#newsType").value : "article",
      cta_type: $("#newsCtaType") ? $("#newsCtaType").value || null : null,
      cta_strength: $("#newsCtaStrength") ? $("#newsCtaStrength").value : "standard",
      audience: $("#newsAudience") ? $("#newsAudience").value || null : null,
      educational_level: $("#newsLevel") ? $("#newsLevel").value || null : null,
      intent: $("#newsIntent") ? $("#newsIntent").value || null : null,
      pillar_topic: $("#newsPillar") ? $("#newsPillar").value : null,
      supporting_topic: $("#newsSupport") ? $("#newsSupport").value : null,
      is_parent_resource: $("#newsParent") ? $("#newsParent").checked : false,
      child_directed: $("#newsChild") ? $("#newsChild").checked : false,
      last_reviewed_at: $("#newsReviewed") ? $("#newsReviewed").value || null : null,
      review_due_at: $("#newsReviewDue") ? $("#newsReviewDue").value || null : null
    };
  };

  const imageInput = $("#newsImage");
  if (imageInput) {
    imageInput.addEventListener("change", function () {
      const file = imageInput.files && imageInput.files[0];
      if (!file) return;
      const reader = new FileReader();
      reader.onload = function () { showImagePreview(String(reader.result || ""), file.name); };
      reader.readAsDataURL(file);
    });
  }

  const form = $("[data-news-form]");
  if (form) {
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      const id = $("#newsId").value;
      const data = payload();
      const file = $("#newsImage").files[0];
      const resource = $("#newsResource") && $("#newsResource").files[0];
      const notice = $("[data-news-form-notice]");
      const sendJson = function () {
        return request(id ? "/api/v1/posts/" + id : "/api/v1/posts", {
          method: id ? "PUT" : "POST",
          body: JSON.stringify(data)
        });
      };
      const after = function (result) {
        notice.textContent = result.ok ? result.body.message : firstError(result.body);
        if (result.ok) {
          fillForm(result.body.data || {});
          loadPosts();
        }
      };
      const appendFiles = function (body) {
        Object.keys(data).forEach(function (key) {
          if (key === "tag_ids") data[key].forEach(function (tag) { body.append("tag_ids[]", tag); });
          else if (data[key] !== null && data[key] !== undefined) body.append(key, data[key] === true ? 1 : data[key] === false ? 0 : data[key]);
        });
        if (file) body.append("featured_image", file);
        if (resource) body.append("resource_file", resource);
        body.append("_method", "PUT");
      };
      if ((file || resource) && id) {
        const body = new FormData();
        appendFiles(body);
        request("/api/v1/posts/" + id, { method: "POST", body: body }).then(after);
      } else {
        sendJson().then(function (result) {
          if (result.ok && (file || resource) && result.body.data && result.body.data.id) {
            const body = new FormData();
            appendFiles(body);
            return request("/api/v1/posts/" + result.body.data.id, { method: "POST", body: body }).then(after);
          }
          after(result);
        });
      }
    });
  }

  document.addEventListener("click", function (event) {
    const postBtn = event.target.closest("[data-edit-post]");
    if (postBtn) {
      request("/api/v1/posts/" + postBtn.getAttribute("data-edit-post") + "?full=1").then(function (result) {
        if (result.ok) fillForm(result.body.data || {});
      });
    }
    if (event.target.closest("[data-news-clear]")) {
      form && form.reset();
      $("#newsId").value = "";
      if (editor) editor.innerHTML = "";
      showImagePreview("", "");
      $("[data-news-preview]").hidden = true;
      $("[data-news-remove]").hidden = true;
    }
    if (event.target.closest("[data-news-preview]")) {
      const id = $("#newsId").value;
      if (id) window.open("/news/preview/" + id, "_blank");
    }
    const pageBtn = event.target.closest("[data-news-page]");
    if (pageBtn) {
      loadPosts(parseInt(pageBtn.getAttribute("data-news-page"), 10) || 1);
      return;
    }
    const deleteBtn = event.target.closest("[data-delete-post], [data-news-remove]");
    if (deleteBtn) {
      const id = deleteBtn.getAttribute("data-delete-post") || $("#newsId").value;
      const title = deleteBtn.getAttribute("data-title") || ($("#newsTitle") && $("#newsTitle").value) || "this article";
      if (!id) return;
      if (!window.confirm("Delete “" + title + "”? This cannot be undone.")) return;
      request("/api/v1/posts/" + id, { method: "DELETE" }).then(function (result) {
        const notice = $("[data-news-form-notice]");
        if (notice) notice.textContent = result.ok ? "Article deleted." : firstError(result.body);
        if (!result.ok) return;
        if (form) form.reset();
        $("#newsId").value = "";
        if (editor) editor.innerHTML = "";
        showImagePreview("", "");
        $("[data-news-preview]").hidden = true;
        $("[data-news-remove]").hidden = true;
        $("[data-news-form-title]").textContent = "Compose an article";
        loadPosts();
      });
    }
  });

  ["#newsSearch", "#newsStatusFilter"].forEach(function (sel) {
    const el = $(sel);
    if (!el) return;
    const resetAndLoad = function () { loadPosts(1); };
    el.addEventListener("input", resetAndLoad);
    el.addEventListener("change", resetAndLoad);
  });

  const catForm = $("[data-category-form]");
  if (catForm) {
    catForm.addEventListener("submit", function (event) {
      event.preventDefault();
      const id = $("#categoryId").value;
      const body = JSON.stringify({
        name: $("#categoryName").value,
        description: $("#categoryDescription").value,
        meta_title: $("#categoryMetaTitle").value,
        meta_description: $("#categoryMetaDescription").value,
        is_active: $("#categoryActive").checked
      });
      request(id ? "/api/v1/post-categories/" + id : "/api/v1/post-categories", {
        method: id ? "PUT" : "POST",
        body: body
      }).then(function () {
        catForm.reset();
        $("#categoryId").value = "";
        loadTaxonomy().then(loadPosts);
      });
    });
  }

  const tagForm = $("[data-tag-form]");
  if (tagForm) {
    tagForm.addEventListener("submit", function (event) {
      event.preventDefault();
      const id = $("#tagId").value;
      const body = JSON.stringify({
        name: $("#tagName").value,
        description: $("#tagDescription").value
      });
      request(id ? "/api/v1/post-tags/" + id : "/api/v1/post-tags", {
        method: id ? "PUT" : "POST",
        body: body
      }).then(function () {
        tagForm.reset();
        $("#tagId").value = "";
        loadTaxonomy();
      });
    });
  }

  document.addEventListener("click", function (event) {
    const cat = event.target.closest("[data-edit-cat]");
    if (cat && window.__srsCats) {
      const row = window.__srsCats.find(function (item) { return String(item.id) === cat.getAttribute("data-edit-cat"); });
      if (row) {
        $("#categoryId").value = row.id;
        $("#categoryName").value = row.name;
        $("#categoryDescription").value = row.description || "";
        $("#categoryMetaTitle").value = row.meta_title || "";
        $("#categoryMetaDescription").value = row.meta_description || "";
        $("#categoryActive").checked = !!row.is_active;
      }
    }
    const tag = event.target.closest("[data-edit-tag]");
    if (tag && window.__srsTags) {
      const row = window.__srsTags.find(function (item) { return String(item.id) === tag.getAttribute("data-edit-tag"); });
      if (row) {
        $("#tagId").value = row.id;
        $("#tagName").value = row.name;
        $("#tagDescription").value = row.description || "";
      }
    }
  });

  const settingsForm = $("[data-publish-settings]");
  if (settingsForm) {
    request("/api/v1/publishing-settings").then(function (result) {
      const row = result.body.data || {};
      $("#adsenseEnabled").checked = !!row.adsense_enabled;
      $("#adsenseClient").value = row.adsense_client_id || "";
      $("#adsenseAuto").checked = !!row.adsense_auto_ads;
      $("#adsenseVerify").value = row.adsense_verification || "";
      $("#analyticsEnabled").checked = !!row.analytics_enabled;
      $("#analyticsId").value = row.analytics_measurement_id || "";
      $("[data-settings-ready]").textContent = row.adsense_ready
        ? "A publisher ID is present. This is not AdSense approval."
        : "AdSense will not render until it is enabled and a valid ca-pub ID is stored.";
    });
    settingsForm.addEventListener("submit", function (event) {
      event.preventDefault();
      request("/api/v1/publishing-settings", {
        method: "PUT",
        body: JSON.stringify({
          adsense_enabled: $("#adsenseEnabled").checked,
          adsense_client_id: $("#adsenseClient").value,
          adsense_auto_ads: $("#adsenseAuto").checked,
          adsense_verification: $("#adsenseVerify").value,
          analytics_enabled: $("#analyticsEnabled").checked,
          analytics_measurement_id: $("#analyticsId").value
        })
      }).then(function (result) {
        $("[data-settings-ready]").textContent = result.ok
          ? (result.body.data.adsense_ready ? "Saved. A publisher ID is present. This is not AdSense approval." : "Saved. Ads remain off until a valid ID is enabled.")
          : firstError(result.body);
      });
    });
  }

  loadTaxonomy().then(loadPosts);
})();
