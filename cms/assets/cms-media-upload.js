(function () {
  "use strict";

  function faDigits(n) {
    return String(Math.round(n)).replace(/\d/g, function (d) {
      return "۰۱۲۳۴۵۶۷۸۹"[Number(d)];
    });
  }

  function uploadOne(file, autoFrame) {
    return new Promise(function (resolve, reject) {
      const formData = new FormData();
      formData.append("file", file);
      formData.append("kind", "image");
      formData.append("auto_frame", autoFrame ? "1" : "0");

      const xhr = new XMLHttpRequest();
      xhr.open("POST", "upload.php");
      xhr.withCredentials = true;

      xhr.onload = function () {
        let data = null;
        try {
          data = JSON.parse(xhr.responseText);
        } catch (err) {
          data = null;
        }
        if (xhr.status >= 200 && xhr.status < 300 && data && data.path) {
          resolve(data);
          return;
        }
        reject(
          new Error(
            (data && data.error) || "آپلود ناموفق بود (کد " + xhr.status + ")"
          )
        );
      };

      xhr.onerror = function () {
        reject(new Error("خطای شبکه در آپلود"));
      };

      xhr.send(formData);
    });
  }

  function appendLog(log, text, isError) {
    const li = document.createElement("li");
    li.className = "cms-bulk-upload__log-item" + (isError ? " is-error" : "");
    li.textContent = text;
    log.appendChild(li);
    log.scrollTop = log.scrollHeight;
  }

  function appendSessionItem(grid, emptyEl, item) {
    if (emptyEl) emptyEl.remove();

    const card = document.createElement("div");
    card.className = "cms-media-item cms-media-item--static";
    card.title = item.path;
    card.dataset.sessionPath = item.path;
    card.innerHTML =
      '<img src="' +
      item.url +
      '" alt=""><span dir="ltr">' +
      (item.path.split("/").pop() || item.path) +
      "</span>";
    grid.insertBefore(card, grid.firstChild);
  }

  function isFramablePath(path) {
    const ext = (path.split(".").pop() || "").toLowerCase();
    return ext === "png" || ext === "webp" || ext === "gif";
  }

  function updateFrameButton(frameBtn, grid) {
    if (!frameBtn || !grid) return;
    const cards = grid.querySelectorAll("[data-session-path]");
    let hasCandidate = false;
    cards.forEach(function (card) {
      if (isFramablePath(card.dataset.sessionPath || "")) {
        hasCandidate = true;
      }
    });
    frameBtn.disabled = !hasCandidate;
  }

  function sessionImageUrl(grid, path, cacheToken) {
    const fileName = path.split("/").pop() || path;
    let base = "";
    const sample = grid && grid.querySelector("[data-session-path] img");
    if (sample && sample.src) {
      try {
        const u = new URL(sample.src, window.location.href);
        const marker = "/uploads/";
        const idx = u.pathname.indexOf(marker);
        if (idx >= 0) {
          base = u.pathname.slice(0, idx);
        }
      } catch (err) {
        base = "";
      }
    }
    return (
      base +
      path +
      "?v=" +
      encodeURIComponent(fileName + "-" + cacheToken)
    );
  }

  function applyFrameResults(grid, results) {
    if (!grid || !results || !results.length) return;

    const cacheBust = Date.now();
    results.forEach(function (row) {
      const oldPath = row.old || row.path;
      const newPath = row.path || oldPath;
      const card = grid.querySelector(
        '[data-session-path="' + CSS.escape(oldPath) + '"]'
      );
      if (!card) return;

      card.dataset.sessionPath = newPath;
      card.title = newPath;

      const img = card.querySelector("img");
      const label = card.querySelector("span");
      const fileName = newPath.split("/").pop() || newPath;
      const url = sessionImageUrl(grid, newPath, cacheBust);

      if (img) img.src = url;
      if (label) label.textContent = fileName;
    });
  }

  document.addEventListener("DOMContentLoaded", function () {
    const input = document.getElementById("cms-bulk-files");
    const drop = document.getElementById("cms-bulk-drop");
    const progress = document.getElementById("cms-bulk-progress");
    const progressBar = document.getElementById("cms-bulk-progress-bar");
    const progressText = document.getElementById("cms-bulk-progress-text");
    const log = document.getElementById("cms-bulk-log");
    const grid = document.getElementById("cms-bulk-session-grid");
    const countBadge = document.getElementById("cms-bulk-session-count");
    const emptyEl = document.getElementById("cms-bulk-session-empty");
    const frameBtn = document.getElementById("cms-bulk-frame-btn");

    if (!input || !log || !grid) return;

    let busy = false;

    async function frameSessionImages() {
      if (busy) return;
      busy = true;
      log.innerHTML = "";
      if (progress) progress.hidden = false;
      if (progressText) {
        progressText.textContent = "در حال مرکز‌چینی تصاویر نشست…";
      }
      if (progressBar) progressBar.style.width = "35%";
      if (frameBtn) frameBtn.disabled = true;

      try {
        const response = await fetch("media-auto-frame.php", {
          method: "POST",
          credentials: "same-origin",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ scope: "session" }),
        });

        let data = null;
        try {
          data = await response.json();
        } catch (err) {
          data = null;
        }

        if (!response.ok || !data || !data.ok) {
          throw new Error(
            (data && data.error) ||
              "مرکز‌چینی ناموفق بود (کد " + response.status + ")"
          );
        }

        const results = data.results || [];
        if (!results.length) {
          appendLog(log, "هیچ PNG/WebP/GIF در نشست برای مرکز‌چینی نیست.", false);
        }

        results.forEach(function (row) {
          const name = (row.old || row.path || "").split("/").pop() || "تصویر";
          const prefix = row.changed ? "✓" : row.skipped ? "○" : "✗";
          appendLog(
            log,
            prefix + " " + name + ": " + (row.message || ""),
            !row.changed && !row.skipped
          );
        });

        applyFrameResults(grid, results);
        if (progressBar) progressBar.style.width = "100%";
        if (progressText) {
          progressText.textContent =
            "پایان — " + faDigits(results.length) + " فایل بررسی شد";
        }
      } catch (err) {
        appendLog(
          log,
          "✗ " + (err.message || "خطا در مرکز‌چینی"),
          true
        );
        if (progressText) {
          progressText.textContent = "مرکز‌چینی ناموفق بود";
        }
      }

      updateFrameButton(frameBtn, grid);
      busy = false;
      window.setTimeout(function () {
        if (progress) progress.hidden = true;
        if (progressBar) progressBar.style.width = "0%";
      }, 1800);
    }

    async function runQueue(files) {
      if (busy || !files.length) return;
      busy = true;
      input.value = "";
      log.innerHTML = "";
      if (progress) progress.hidden = false;

      let done = 0;
      const total = files.length;
      let sessionCount = parseInt(countBadge?.textContent || "0", 10) || 0;

      for (const file of files) {
        if (progressText) {
          progressText.textContent =
            "آپلود " +
            faDigits(done + 1) +
            " از " +
            faDigits(total) +
            ": " +
            file.name;
        }
        if (progressBar) {
          progressBar.style.width =
            Math.round((done / total) * 100) + "%";
        }

        try {
          const data = await uploadOne(file, false);
          appendLog(log, "✓ " + file.name + " → " + data.path, false);
          appendSessionItem(grid, emptyEl, data);
          sessionCount += 1;
          if (countBadge) {
            countBadge.textContent = String(sessionCount);
          }
          updateFrameButton(frameBtn, grid);
        } catch (err) {
          appendLog(
            log,
            "✗ " + file.name + ": " + (err.message || "خطا"),
            true
          );
        }

        done += 1;
        if (progressBar) {
          progressBar.style.width =
            Math.round((done / total) * 100) + "%";
        }
      }

      if (progressText) {
        progressText.textContent =
          "پایان — " + faDigits(done) + " فایل پردازش شد";
      }
      busy = false;
      window.setTimeout(function () {
        if (progress) progress.hidden = true;
        if (progressBar) progressBar.style.width = "0%";
      }, 1800);
    }

    input.addEventListener("change", function () {
      if (!input.files || !input.files.length) return;
      runQueue(Array.prototype.slice.call(input.files));
    });

    if (frameBtn) {
      frameBtn.addEventListener("click", frameSessionImages);
    }

    if (drop) {
      ["dragenter", "dragover"].forEach(function (ev) {
        drop.addEventListener(ev, function (e) {
          e.preventDefault();
          drop.classList.add("is-dragover");
        });
      });
      ["dragleave", "drop"].forEach(function (ev) {
        drop.addEventListener(ev, function (e) {
          e.preventDefault();
          drop.classList.remove("is-dragover");
        });
      });
      drop.addEventListener("drop", function (e) {
        const dt = e.dataTransfer;
        if (!dt || !dt.files || !dt.files.length) return;
        const images = Array.prototype.filter.call(dt.files, function (f) {
          return (f.type || "").startsWith("image/");
        });
        if (images.length) runQueue(images);
      });
    }
  });
})();
