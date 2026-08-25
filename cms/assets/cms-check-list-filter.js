(function () {
  "use strict";

  function normalizeQuery(value) {
    return String(value || "")
      .trim()
      .toLowerCase()
      .replace(/\u200c/g, "");
  }

  function applyFilter(root) {
    const input = root.querySelector(".cms-check-list-filter__input");
    const list = root.querySelector(".cms-check-list");
    const empty = root.querySelector(".cms-check-list-filter__empty");
    if (!input || !list) return;

    const query = normalizeQuery(input.value);
    const items = list.querySelectorAll(".cms-check-list__item");
    let visibleCount = 0;

    items.forEach(function (item) {
      const checkbox = item.querySelector('input[type="checkbox"]');
      const haystack = normalizeQuery(item.getAttribute("data-cms-check-search") || item.textContent);
      const checked = checkbox && checkbox.checked;
      const show = query === "" || checked || haystack.indexOf(query) !== -1;

      item.hidden = !show;
      if (show) visibleCount += 1;
    });

    if (empty) {
      empty.hidden = visibleCount > 0 || query === "";
    }
  }

  function initFilter(root) {
    const input = root.querySelector(".cms-check-list-filter__input");
    const list = root.querySelector(".cms-check-list");
    if (!input || !list) return;

    input.addEventListener("input", function () {
      applyFilter(root);
    });

    list.addEventListener("change", function (event) {
      const target = event.target;
      if (target && target.matches('input[type="checkbox"]')) {
        applyFilter(root);
      }
    });

    applyFilter(root);
  }

  function initAll() {
    document.querySelectorAll("[data-cms-check-list-filter]").forEach(initFilter);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initAll);
  } else {
    initAll();
  }
})();
