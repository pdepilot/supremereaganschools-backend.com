(function () {
  const root = document.getElementById("heroSlideshow");
  if (!root) return;

  const items = Array.prototype.slice.call(root.querySelectorAll(".carousel-inner > .carousel-item"));
  if (items.length < 2) return;

  const indicators = Array.prototype.slice.call(root.querySelectorAll("[data-bs-slide-to]"));
  const INTERVAL = Number(root.getAttribute("data-bs-interval")) || 8500;

  if (window.bootstrap && bootstrap.Carousel) {
    const existing = bootstrap.Carousel.getInstance(root);
    if (existing) existing.dispose();
  }

  let index = items.findIndex(function (item) {
    return item.classList.contains("active");
  });
  if (index < 0) index = 0;

  let timer = null;

  const show = function (next) {
    index = (next + items.length) % items.length;

    items.forEach(function (item, i) {
      item.classList.toggle("active", i === index);
      item.classList.remove("carousel-item-next", "carousel-item-prev", "carousel-item-start", "carousel-item-end");
    });

    indicators.forEach(function (dot, i) {
      const on = i === index;
      dot.classList.toggle("active", on);
      if (on) {
        dot.setAttribute("aria-current", "true");
      } else {
        dot.removeAttribute("aria-current");
      }
    });
  };

  const stop = function () {
    if (timer) {
      window.clearInterval(timer);
      timer = null;
    }
  };

  const start = function () {
    stop();
    timer = window.setInterval(function () {
      if (document.hidden) return;
      show(index + 1);
    }, INTERVAL);
  };

  show(index);
  start();

  indicators.forEach(function (dot) {
    dot.addEventListener("click", function (event) {
      event.preventDefault();
      const next = Number(dot.getAttribute("data-bs-slide-to"));
      if (Number.isNaN(next)) return;
      show(next);
      start();
    });
  });
})();
