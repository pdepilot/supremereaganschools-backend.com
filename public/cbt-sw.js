/**
 * Narrow CBT service worker — static exam assets + last-seen CBT shell pages only.
 * Does not cache API responses, payments, admin, or secrets.
 */
const CBT_SW_VERSION = "cbt-offline-v3";
const CBT_ASSET_CACHE = CBT_SW_VERSION + "-assets";
const CBT_SHELL_CACHE = CBT_SW_VERSION + "-shell";

const PRECACHE_URLS = [
  "/site/CSS/cbt.css",
  "/site/JS/portal-cbt.js",
  "/site/JS/cbt/offline-store.js",
  "/site/JS/cbt/offline-session.js",
  "/site/JS/cbt/offline-sync.js",
  "/site/JS/cbt/exam-guard.js",
  "/site/Image/logo_main.png"
];

self.addEventListener("install", function (event) {
  event.waitUntil(
    caches.open(CBT_ASSET_CACHE).then(function (cache) {
      return cache.addAll(PRECACHE_URLS);
    }).then(function () {
      return self.skipWaiting();
    })
  );
});

self.addEventListener("activate", function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.filter(function (key) {
        return key.indexOf("cbt-offline-") === 0 && key.indexOf(CBT_SW_VERSION) !== 0;
      }).map(function (key) {
        return caches.delete(key);
      }));
    }).then(function () {
      return self.clients.claim();
    })
  );
});

function isCbtShellRequest(url) {
  return url.origin === self.location.origin
    && url.pathname.indexOf("/cbt/") === 0
    && url.pathname.indexOf("/cbt/admin") !== 0
    && url.pathname.indexOf("/api/") !== 0;
}

function isCbtAsset(url) {
  if (url.origin !== self.location.origin) return false;
  if (url.pathname.indexOf("/site/CSS/cbt.css") === 0) return true;
  if (url.pathname.indexOf("/site/JS/portal-cbt.js") === 0) return true;
  if (url.pathname.indexOf("/site/JS/cbt/") === 0) return true;
  if (url.pathname.indexOf("/site/Image/logo_main.png") === 0) return true;
  return false;
}

self.addEventListener("fetch", function (event) {
  var request = event.request;
  if (request.method !== "GET") return;

  var url = new URL(request.url);

  // Never cache API / payments / admin.
  if (url.pathname.indexOf("/api/") === 0) return;
  if (url.pathname.indexOf("/cbt/admin") === 0) return;
  if (url.pathname.indexOf("/payments") === 0) return;

  if (isCbtAsset(url)) {
    event.respondWith(
      caches.open(CBT_ASSET_CACHE).then(function (cache) {
        return cache.match(request).then(function (cached) {
          var network = fetch(request).then(function (response) {
            if (response && response.ok) cache.put(request, response.clone());
            return response;
          }).catch(function () {
            return cached;
          });
          return cached || network;
        });
      })
    );
    return;
  }

  if (isCbtShellRequest(url) && request.mode === "navigate") {
    event.respondWith(
      fetch(request).then(function (response) {
        if (response && response.ok) {
          var copy = response.clone();
          caches.open(CBT_SHELL_CACHE).then(function (cache) {
            cache.put(request, copy);
          });
        }
        return response;
      }).catch(function () {
        return caches.open(CBT_SHELL_CACHE).then(function (cache) {
          return cache.match(request).then(function (cached) {
            return cached || caches.match("/cbt/attempts/" + (url.pathname.split("/").pop() || ""));
          });
        });
      })
    );
  }
});
