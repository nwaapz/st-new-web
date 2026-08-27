(function () {
  "use strict";

  function normalizeQuery(value) {
    var map = {
      "\u06F0": "0",
      "\u06F1": "1",
      "\u06F2": "2",
      "\u06F3": "3",
      "\u06F4": "4",
      "\u06F5": "5",
      "\u06F6": "6",
      "\u06F7": "7",
      "\u06F8": "8",
      "\u06F9": "9",
      "\u0660": "0",
      "\u0661": "1",
      "\u0662": "2",
      "\u0663": "3",
      "\u0664": "4",
      "\u0665": "5",
      "\u0666": "6",
      "\u0667": "7",
      "\u0668": "8",
      "\u0669": "9",
      "\u064A": "\u06CC",
      "\u0643": "\u06A9",
      "\u0629": "\u0647",
      "\u200C": " ",
    };
    var s = String(value || "").trim();
    var out = "";
    for (var i = 0; i < s.length; i += 1) {
      var ch = s.charAt(i);
      out += Object.prototype.hasOwnProperty.call(map, ch) ? map[ch] : ch;
    }
    return out.toLowerCase().replace(/\s+/g, " ").trim();
  }

  function applyFilter(root) {
    var input = root.querySelector(".cms-check-list-filter__input");
    var list = root.querySelector(".cms-check-list");
    var empty = root.querySelector(".cms-check-list-filter__empty");
    if (!input || !list) return;

    var query = normalizeQuery(input.value);
    var items = list.querySelectorAll(".cms-check-list__item");
    var visibleCount = 0;

    items.forEach(function (item) {
      var inputEl = item.querySelector('input[type="checkbox"], input[type="radio"]');
      var haystack = normalizeQuery(
        item.getAttribute("data-cms-check-search") || item.textContent || ""
      );
      var checked = inputEl && inputEl.checked;
      var show = query === "" || checked || haystack.indexOf(query) !== -1;

      item.hidden = !show;
      item.classList.toggle("is-filtered-out", !show);
      if (show) visibleCount += 1;
    });

    if (empty) {
      empty.hidden = visibleCount > 0 || query === "";
    }
  }

  function initFilter(root) {
    var input = root.querySelector(".cms-check-list-filter__input");
    var list = root.querySelector(".cms-check-list");
    if (!input || !list) return;

    input.addEventListener("input", function () {
      applyFilter(root);
    });

    input.addEventListener("search", function () {
      applyFilter(root);
    });

    list.addEventListener("change", function (event) {
      var target = event.target;
      if (target && target.matches('input[type="checkbox"], input[type="radio"]')) {
        applyFilter(root);
      }
    });

    applyFilter(root);
  }

  function initAll() {
    document.querySelectorAll("[data-cms-check-list-filter]").forEach(initFilter);
  }

  window.cmsInitCheckListFilter = initFilter;

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initAll);
  } else {
    initAll();
  }
})();
