(function () {
  if (!document.querySelector("[data-auth-form]")) return;

  const KEY = "srs.auth-scene.index";
  const overlay = "linear-gradient(rgba(11, 22, 40, 0.58), rgba(7, 16, 28, 0.74))";
  const scenes = [
    "https://images.unsplash.com/photo-1521587760476-6c12a4b040da?auto=format&fit=crop&w=1920&q=80",
    "https://images.unsplash.com/photo-1524995997946-a1c2e315a42f?auto=format&fit=crop&w=1920&q=80",
    "https://images.unsplash.com/photo-1481627834876-b7833e8f5570?auto=format&fit=crop&w=1920&q=80",
    "https://images.unsplash.com/photo-1513475382585-d06e58bcb0e0?auto=format&fit=crop&w=1920&q=80",
    "https://images.unsplash.com/photo-1507842214122-87de407101b0?auto=format&fit=crop&w=1920&q=80",
    "https://images.unsplash.com/photo-1434030216411-0b793f4b4173?auto=format&fit=crop&w=1920&q=80",
    "https://images.unsplash.com/photo-1562774053-701939374585?auto=format&fit=crop&w=1920&q=80",
    "https://images.unsplash.com/photo-1498243691581-b145c3f54a5a?auto=format&fit=crop&w=1920&q=80",
    "https://images.unsplash.com/photo-1607237138185-eedd9c632b0b?auto=format&fit=crop&w=1920&q=80",
    "https://images.unsplash.com/photo-1523050854058-8df90110c9f1?auto=format&fit=crop&w=1920&q=80",
    "https://images.unsplash.com/photo-1487958449943-2429e8be8625?auto=format&fit=crop&w=1920&q=80",
    "https://images.unsplash.com/photo-1441974231531-c6227db76b6e?auto=format&fit=crop&w=1920&q=80",
    "https://images.unsplash.com/photo-1506905925346-21bda4d32df4?auto=format&fit=crop&w=1920&q=80",
    "https://images.unsplash.com/photo-1416879595882-3373a0480b5b?auto=format&fit=crop&w=1920&q=80",
    "https://images.unsplash.com/photo-1470071459604-3b5ec3a7fe05?auto=format&fit=crop&w=1920&q=80",
    "https://images.unsplash.com/photo-1472214103451-9374bd1c798e?auto=format&fit=crop&w=1920&q=80"
  ];

  const nextUrl = function () {
    let index = Number(window.localStorage.getItem(KEY));
    if (!Number.isFinite(index)) index = -1;
    index = (index + 1) % scenes.length;
    try {
      window.localStorage.setItem(KEY, String(index));
    } catch (error) {
      // Private browsing can block storage; still rotate in-memory.
    }
    return scenes[index];
  };

  const apply = function (url) {
    const panel = document.querySelector("[data-auth-scene='panel'], .auth-gallery-media");
    if (panel) {
      panel.style.backgroundImage = 'url("' + url + '")';
      return;
    }

    document.body.style.backgroundImage = overlay + ', url("' + url + '")';
    document.body.style.backgroundSize = "cover";
    document.body.style.backgroundPosition = "center";
    document.body.style.backgroundRepeat = "no-repeat";
  };

  const paint = function () {
    const url = nextUrl();
    const preview = new Image();
    preview.onload = function () { apply(url); };
    preview.src = url;
  };

  paint();
  window.addEventListener("pageshow", function (event) {
    if (event.persisted) paint();
  });
})();
