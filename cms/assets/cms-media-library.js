(function () {
  "use strict";

  document.addEventListener("DOMContentLoaded", function () {
    const grid = document.getElementById("cms-media-library-grid");
    const search = document.getElementById("cms-media-library-search");
    const stats = document.getElementById("cms-media-library-stats");
    const filteredNote = document.getElementById("cms-media-library-filtered");

    if (!grid) return;

    const items = Array.prototype.slice.call(
      grid.querySelectorAll(".cms-media-library__item")
    );

    function updateFilter() {
      const q = (search && search.value ? search.value : "")
        .trim()
        .toLowerCase();
      let visible = 0;

      items.forEach(function (card) {
        const name = card.dataset.name || "";
        const path = (card.dataset.path || "").toLowerCase();
        const show = q === "" || name.indexOf(q) !== -1 || path.indexOf(q) !== -1;
        card.hidden = !show;
        if (show) visible += 1;
      });

      if (filteredNote) {
        if (q !== "") {
          filteredNote.hidden = false;
          filteredNote.textContent =
            visible + " تصویر از " + items.length + " مورد نمایش داده می‌شود";
        } else {
          filteredNote.hidden = true;
        }
      }
    }

    if (search) {
      search.addEventListener("input", updateFilter);
    }

    grid.addEventListener("click", function (e) {
      const btn = e.target.closest(".cms-media-library__delete");
      if (!btn) return;

      const path = btn.dataset.path || "";
      if (!path) return;

      const name = path.split("/").pop() || path;
      if (
        !window.confirm(
          "حذف «" + name + "» از سرور؟\n\nاگر در محصول یا دسته استفاده شده باشد، آن تصویر شکسته می‌شود."
        )
      ) {
        return;
      }

      btn.disabled = true;
      btn.textContent = "…";

      fetch("media-delete.php", {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ path: path }),
      })
        .then(function (response) {
          return response.json().then(function (data) {
            return { response: response, data: data };
          });
        })
        .then(function (result) {
          if (
            !result.response.ok ||
            !result.data ||
            !result.data.ok
          ) {
            throw new Error(
              (result.data && result.data.error) || "حذف ناموفق بود"
            );
          }

          const card = btn.closest(".cms-media-library__item");
          if (card) {
            const idx = items.indexOf(card);
            if (idx !== -1) items.splice(idx, 1);
            card.remove();
          }

          if (stats && items.length >= 0) {
            stats.textContent = items.length + " تصویر";
          }

          if (items.length === 0 && grid) {
            grid.remove();
            const empty = document.createElement("p");
            empty.className = "cms-muted";
            empty.id = "cms-media-library-empty";
            empty.textContent = "هنوز تصویری روی سرور نیست.";
            grid.parentNode.insertBefore(empty, grid);
          }

          updateFilter();
        })
        .catch(function (err) {
          btn.disabled = false;
          btn.textContent = "حذف";
          window.alert(err.message || "خطا در حذف");
        });
    });
  });
})();
