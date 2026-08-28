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
    card.innerHTML =
      '<img src="' +
      item.url +
      '" alt=""><span dir="ltr">' +
      (item.path.split("/").pop() || item.path) +
      "</span>";
    grid.insertBefore(card, grid.firstChild);
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

    if (!input || !log || !grid) return;

    let busy = false;

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
