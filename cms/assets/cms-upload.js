(function () {
  "use strict";

  window.cmsUploadInProgress = 0;

  function faDigits(n) {
    return String(Math.round(n)).replace(/\d/g, function (d) {
      return "۰۱۲۳۴۵۶۷۸۹"[Number(d)];
    });
  }

  function progressRoot(input) {
    return (
      input.closest(".cms-field") ||
      input.closest(".cms-image-actions") ||
      input.parentElement
    );
  }

  function getProgressEl(input) {
    const root = progressRoot(input);
    if (!root) return null;
    let el = root.querySelector(".cms-upload-progress");
    if (!el) {
      el = document.createElement("div");
      el.className = "cms-upload-progress";
      el.hidden = true;
      el.innerHTML =
        '<div class="cms-upload-progress__track"><span class="cms-upload-progress__bar"></span></div>' +
        '<span class="cms-upload-progress__text">۰٪</span>';
      input.insertAdjacentElement("afterend", el);
    }
    return el;
  }

  function setFieldProgress(input, pct, text, isError) {
    const el = getProgressEl(input);
    if (!el) return;
    const bar = el.querySelector(".cms-upload-progress__bar");
    const label = el.querySelector(".cms-upload-progress__text");
    el.hidden = false;
    el.classList.toggle("is-error", Boolean(isError));
    if (typeof pct === "number" && pct >= 0) {
      if (bar) bar.style.width = Math.max(0, Math.min(100, pct)) + "%";
    }
    if (label && text) label.textContent = text;
  }

  function hideFieldProgress(input) {
    const el = getProgressEl(input);
    if (el) {
      el.hidden = true;
      el.classList.remove("is-error");
      const bar = el.querySelector(".cms-upload-progress__bar");
      if (bar) bar.style.width = "0%";
    }
  }

  function ensureOverlay() {
    let overlay = document.getElementById("cms-upload-overlay");
    if (overlay) return overlay;
    overlay = document.createElement("div");
    overlay.id = "cms-upload-overlay";
    overlay.className = "cms-upload-overlay";
    overlay.hidden = true;
    overlay.innerHTML =
      '<div class="cms-upload-overlay__panel" role="status" aria-live="polite">' +
      '<div class="cms-upload-progress__track cms-upload-progress__track--lg"><span class="cms-upload-progress__bar" id="cms-upload-overlay-bar"></span></div>' +
      '<p class="cms-upload-overlay__text" id="cms-upload-overlay-text">در حال آپلود…</p>' +
      "</div>";
    document.body.appendChild(overlay);
    return overlay;
  }

  function setOverlayProgress(pct, text) {
    const overlay = ensureOverlay();
    const bar = document.getElementById("cms-upload-overlay-bar");
    const label = document.getElementById("cms-upload-overlay-text");
    overlay.hidden = false;
    if (typeof pct === "number" && pct >= 0 && bar) {
      bar.style.width = Math.max(0, Math.min(100, pct)) + "%";
    }
    if (label && text) label.textContent = text;
  }

  function hideOverlay() {
    const overlay = document.getElementById("cms-upload-overlay");
    if (!overlay) return;
    overlay.hidden = true;
    const bar = document.getElementById("cms-upload-overlay-bar");
    if (bar) bar.style.width = "0%";
  }

  function fileLabel(input) {
    return document.querySelector('[data-file-label-for="' + input.id + '"]');
  }

  function ensureVideoPreview(previewId, url) {
    let vid = document.getElementById(previewId);
    if (!vid) {
      vid = document.createElement("video");
      vid.id = previewId;
      vid.className = "cms-image-preview";
      vid.controls = true;
      vid.preload = "metadata";
      vid.style.maxWidth = "min(100%, 420px)";
      vid.style.height = "auto";
      vid.style.background = "#111";
      const fileInput = document.querySelector(
        '[data-cms-preview-id="' + previewId + '"]'
      );
      const field = fileInput?.closest(".cms-field");
      const anchor = field?.querySelector(".cms-label");
      if (anchor) anchor.insertAdjacentElement("afterend", vid);
    }
    vid.src = url;
    vid.style.display = "block";
    return vid;
  }

  window.cmsStartUpload = function cmsStartUpload(input, opts) {
    opts = opts || {};
    if (!input || !input.files || !input.files[0]) return;

    const file = input.files[0];
    const kind = opts.kind || input.dataset.cmsUpload || "image";
    const textId = opts.textId || input.dataset.cmsTextId || "";
    const previewId = opts.previewId || input.dataset.cmsPreviewId || "";
    const subdir = opts.subdir || input.dataset.cmsUploadSubdir || "";
    const form = input.closest("form");
    const skipAutoFrame = form
      ? form.querySelector('[name="skip_image_auto_frame"]')
      : null;

    const formData = new FormData();
    formData.append("file", file);
    formData.append("kind", kind);
    if (subdir) formData.append("subdir", subdir);
    if (kind === "image") {
      formData.append("auto_frame", skipAutoFrame && skipAutoFrame.checked ? "0" : "1");
    }

    window.cmsUploadInProgress += 1;
    setFieldProgress(input, 0, "شروع آپلود…", false);
    const label = fileLabel(input);
    if (label) label.textContent = "در حال آپلود: " + file.name;

    const xhr = new XMLHttpRequest();
    xhr.open("POST", "upload.php");
    xhr.withCredentials = true;

    xhr.upload.onprogress = function (e) {
      if (e.lengthComputable && e.total > 0) {
        const pct = (e.loaded / e.total) * 100;
        setFieldProgress(input, pct, faDigits(pct) + "٪", false);
      } else {
        setFieldProgress(input, -1, "در حال آپلود…", false);
      }
    };

    xhr.onload = function () {
      window.cmsUploadInProgress = Math.max(0, window.cmsUploadInProgress - 1);
      let data = null;
      try {
        data = JSON.parse(xhr.responseText);
      } catch (err) {
        data = null;
      }

      if (xhr.status >= 200 && xhr.status < 300 && data && data.path) {
        setFieldProgress(input, 100, "آپلود کامل شد", false);
        const text = textId ? document.getElementById(textId) : null;
        if (text) {
          text.value = data.path;
          delete text.dataset.pendingUpload;
        }
        if (kind === "video" && previewId) {
          ensureVideoPreview(previewId, data.url || data.path);
        }
        input.value = "";
        if (label) label.textContent = "آپلود شد — برای ثبت نهایی «ذخیره» را بزنید";
        window.setTimeout(function () {
          hideFieldProgress(input);
        }, 2500);
        return;
      }

      const message =
        (data && data.error) ||
        "آپلود ناموفق بود (کد " + xhr.status + ")";
      setFieldProgress(input, 0, message, true);
      if (label) label.textContent = message;
    };

    xhr.onerror = function () {
      window.cmsUploadInProgress = Math.max(0, window.cmsUploadInProgress - 1);
      setFieldProgress(input, 0, "خطای شبکه در آپلود", true);
      if (label) label.textContent = "خطای شبکه در آپلود";
    };

    xhr.send(formData);
  };

  window.cmsMediaTarget = { textId: "", previewId: "", kind: "image" };

  function formatMediaSize(bytes) {
    if (!bytes || bytes < 1024) return "";
    if (bytes < 1024 * 1024) return Math.round(bytes / 1024) + " KB";
    return (bytes / (1024 * 1024)).toFixed(1) + " MB";
  }

  function renderMediaItem(it, kind) {
    const btn = document.createElement("button");
    btn.type = "button";
    btn.className =
      "cms-media-item" +
      (kind === "video" ? " cms-media-item--video" : "") +
      (it.in_session ? " cms-media-item--session" : "");
    btn.title = it.name;
    const sizeLabel = formatMediaSize(it.size);
    if (kind === "video") {
      btn.innerHTML =
        '<video class="cms-media-item__video" muted preload="metadata" playsinline src="' +
        it.url +
        '"></video><span>' +
        it.name +
        (sizeLabel ? " · " + sizeLabel : "") +
        "</span>";
    } else {
      btn.innerHTML =
        '<img src="' +
        it.url +
        '" alt=""><span>' +
        it.name +
        "</span>";
    }
    btn.onclick = function () {
      cmsPickMedia(it.path, it.url, kind);
    };
    return btn;
  }

  window.cmsOpenMediaPicker = function cmsOpenMediaPicker(textId, previewId, opts) {
    opts = opts || {};
    const kind = opts.kind || "image";
    const subdir = opts.subdir || "";
    window.cmsMediaTarget = { textId: textId, previewId: previewId, kind: kind };

    const modal = document.getElementById("cms-media-modal");
    const grid = document.getElementById("cms-media-grid");
    const sessionBlock = document.getElementById("cms-media-session-block");
    const sessionGrid = document.getElementById("cms-media-session-grid");
    const sessionTitle = document.getElementById("cms-media-session-title");
    const allTitle = document.getElementById("cms-media-all-title");
    const status = document.getElementById("cms-media-status");
    const title = document.getElementById("cms-media-modal-title");
    if (!modal || !grid || !status) return;

    if (title) {
      title.textContent =
        kind === "video" ? "انتخاب ویدیو از سرور" : "انتخاب تصویر از سرور";
    }
    modal.hidden = false;
    grid.innerHTML = "";
    if (sessionGrid) sessionGrid.innerHTML = "";
    if (sessionBlock) sessionBlock.hidden = true;
    if (allTitle) allTitle.hidden = true;
    status.textContent = "در حال بارگذاری…";

    const params = new URLSearchParams({ kind: kind });
    if (subdir) params.set("subdir", subdir);

    fetch("media-list.php?" + params.toString(), { credentials: "same-origin" })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        const items = data.items || [];
        const sessionPrefix = data.session_prefix || "";
        const sessionItems = items.filter(function (it) {
          return it.in_session;
        });
        const otherItems = items.filter(function (it) {
          return !it.in_session;
        });

        if (!items.length) {
          status.textContent =
            kind === "video"
              ? "هنوز ویدیویی در این پوشه نیست."
              : "هنوز تصویری در uploads نیست.";
          return;
        }

        if (sessionPrefix) {
          status.textContent =
            "پیشوند نشست: " +
            sessionPrefix +
            " — " +
            items.length +
            (kind === "video" ? " ویدیو" : " تصویر");
        } else {
          status.textContent =
            items.length +
            (kind === "video" ? " ویدیو" : " تصویر") +
            " — یکی را انتخاب کنید";
        }

        if (
          kind === "image" &&
          sessionItems.length &&
          sessionGrid &&
          sessionBlock
        ) {
          sessionBlock.hidden = false;
          if (sessionTitle) {
            sessionTitle.textContent =
              "تصاویر این نشست (" + sessionItems.length + ")";
          }
          sessionItems.forEach(function (it) {
            sessionGrid.appendChild(renderMediaItem(it, kind));
          });
        }

        const listItems =
          kind === "image" && sessionItems.length ? otherItems : items;

        if (kind === "image" && sessionItems.length && allTitle) {
          allTitle.hidden = false;
          allTitle.textContent =
            "همه تصاویر" + (otherItems.length ? " (" + otherItems.length + ")" : "");
        }

        listItems.forEach(function (it) {
          grid.appendChild(renderMediaItem(it, kind));
        });
      })
      .catch(function () {
        status.textContent =
          kind === "video"
            ? "بارگذاری لیست ویدیوها ناموفق بود"
            : "بارگذاری لیست تصاویر ناموفق بود";
      });
  };

  window.cmsPickMedia = function cmsPickMedia(path, url, kind) {
    kind = kind || window.cmsMediaTarget.kind || "image";
    const text = document.getElementById(window.cmsMediaTarget.textId);
    const previewId = window.cmsMediaTarget.previewId;

    if (text) {
      text.value = path;
      delete text.dataset.pendingUpload;
    }

    if (kind === "video") {
      ensureVideoPreview(previewId, url + "?v=" + encodeURIComponent(path));
    } else {
      const img = document.getElementById(previewId);
      const empty = document.getElementById(previewId + "-empty");
      if (img) {
        if (img._cmsObjectUrl) {
          URL.revokeObjectURL(img._cmsObjectUrl);
          img._cmsObjectUrl = null;
        }
        img.style.display = "block";
        img.classList.remove("cms-image-preview--empty");
        img.src = url + "?v=" + encodeURIComponent(path);
      }
      if (empty) empty.style.display = "none";
    }

    cmsCloseMediaPicker();
  };

  window.cmsCloseMediaPicker = function cmsCloseMediaPicker() {
    const modal = document.getElementById("cms-media-modal");
    if (modal) modal.hidden = true;
  };

  document.addEventListener("DOMContentLoaded", function () {
    const modal = document.getElementById("cms-media-modal");
    if (modal) {
      modal.addEventListener("click", function (e) {
        if (e.target === modal) cmsCloseMediaPicker();
      });
    }
  });

  window.cmsOnImageFileSelected = function cmsOnImageFileSelected(
    input,
    previewId,
    textId
  ) {
    if (!input.files || !input.files[0]) return;
    const f = input.files[0];
    const label = fileLabel(input);
    if (label) label.textContent = "انتخاب شد: " + f.name;

    const img = document.getElementById(previewId);
    const empty = document.getElementById(previewId + "-empty");
    if (img) {
      img.style.display = "block";
      img.classList.remove("cms-image-preview--empty");
      if (img._cmsObjectUrl) URL.revokeObjectURL(img._cmsObjectUrl);
      img._cmsObjectUrl = URL.createObjectURL(f);
      img.src = img._cmsObjectUrl;
    }
    if (empty) empty.style.display = "none";

    const text = document.getElementById(textId);
    if (text) text.dataset.pendingUpload = "1";

    cmsStartUpload(input, { kind: "image", textId: textId, previewId: previewId });
  };

  window.cmsOnVideoFileSelected = function cmsOnVideoFileSelected(
    input,
    previewId,
    textId
  ) {
    if (!input.files || !input.files[0]) return;
    const f = input.files[0];
    const label = fileLabel(input);
    if (label) label.textContent = "انتخاب شد: " + f.name;
    const text = document.getElementById(textId);
    if (text) text.dataset.pendingUpload = "1";
    cmsStartUpload(input, {
      kind: "video",
      textId: textId,
      previewId: previewId,
      subdir: input.dataset.cmsUploadSubdir || "about/videos",
    });
  };

  function formHasFiles(form) {
    return Array.prototype.some.call(
      form.querySelectorAll('input[type="file"]'),
      function (input) {
        return input.files && input.files.length > 0;
      }
    );
  }

  document.addEventListener("submit", function (e) {
    const form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    if ((form.enctype || "").toLowerCase() !== "multipart/form-data") return;
    if (form.dataset.cmsNoUploadProgress === "1") return;

    if (window.cmsUploadInProgress > 0) {
      e.preventDefault();
      window.alert("لطفاً تا پایان آپلود فایل‌ها صبر کنید.");
      return;
    }

    if (!formHasFiles(form)) return;

    e.preventDefault();
    const xhr = new XMLHttpRequest();
    const action = form.getAttribute("action") || window.location.href;
    const method = (form.getAttribute("method") || "POST").toUpperCase();
    xhr.open(method, action);
    xhr.withCredentials = true;

    setOverlayProgress(0, "در حال ارسال فرم…");
    xhr.upload.onprogress = function (ev) {
      if (ev.lengthComputable && ev.total > 0) {
        const pct = (ev.loaded / ev.total) * 100;
        setOverlayProgress(pct, "آپلود: " + faDigits(pct) + "٪");
      } else {
        setOverlayProgress(-1, "در حال آپلود…");
      }
    };

    xhr.onload = function () {
      hideOverlay();
      if (xhr.status >= 200 && xhr.status < 400) {
        window.location.href = xhr.responseURL || action;
        return;
      }
      document.open();
      document.write(xhr.responseText);
      document.close();
    };

    xhr.onerror = function () {
      hideOverlay();
      window.alert("ارسال فرم ناموفق بود. دوباره تلاش کنید.");
    };

    xhr.send(new FormData(form));
  });
})();
