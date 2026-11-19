(function () {
  var MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
  var WEEK = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];
  var FA = { '0': '۰', '1': '۱', '2': '۲', '3': '۳', '4': '۴', '5': '۵', '6': '۶', '7': '۷', '8': '۸', '9': '۹' };
  var panel = null;
  var activeInput = null;
  var viewYear = 1404;
  var viewMonth = 1;
  var selected = null;

  function intdiv(a, b) {
    return Math.floor(a / b);
  }

  function gregorianToJalali(gy, gm, gd) {
    var gdm = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    var gy2 = gm > 2 ? gy + 1 : gy;
    var days = 355666 + (365 * gy) + intdiv(gy2 + 3, 4) - intdiv(gy2 + 99, 100)
      + intdiv(gy2 + 399, 400) + gd + gdm[gm - 1];
    var jy = -1595 + (33 * intdiv(days, 12053));
    days %= 12053;
    jy += 4 * intdiv(days, 1461);
    days %= 1461;
    if (days > 365) {
      jy += intdiv(days - 1, 365);
      days = (days - 1) % 365;
    }
    var jm = days < 186 ? 1 + intdiv(days, 31) : 7 + intdiv(days - 186, 30);
    var jd = 1 + (days < 186 ? days % 31 : (days - 186) % 30);
    return [jy, jm, jd];
  }

  function jalaliToGregorian(jy, jm, jd) {
    jy += 1595;
    var days = -355668 + (365 * jy) + (intdiv(jy, 33) * 8) + intdiv((jy % 33) + 3, 4)
      + jd + (jm < 7 ? (jm - 1) * 31 : ((jm - 7) * 30) + 186);
    var gy = 400 * intdiv(days, 146097);
    days %= 146097;
    if (days > 36524) {
      days -= 1;
      gy += 100 * intdiv(days, 36524);
      days %= 36524;
      if (days >= 365) days += 1;
    }
    gy += 4 * intdiv(days, 1461);
    days %= 1461;
    if (days > 365) {
      gy += intdiv(days - 1, 365);
      days = (days - 1) % 365;
    }
    var gd = days + 1;
    var leap = ((gy % 4 === 0 && gy % 100 !== 0) || (gy % 400 === 0)) ? 29 : 28;
    var salA = [0, 31, leap, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    var gm = 1;
    while (gm < 13 && gd > salA[gm]) {
      gd -= salA[gm];
      gm += 1;
    }
    return [gy, gm, gd];
  }

  function faNum(n) {
    return String(n).replace(/\d/g, function (d) { return FA[d] || d; });
  }

  function pad(n) {
    return n < 10 ? '0' + n : String(n);
  }

  function fromPersian(text) {
    return String(text || '').replace(/[۰-۹٠-٩]/g, function (ch) {
      var map = {
        '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4',
        '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9',
        '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4',
        '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9'
      };
      return map[ch] || ch;
    });
  }

  function parseJalali(text) {
    var m = fromPersian(text).trim().match(/^(\d{3,4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/);
    if (!m) return null;
    var jy = parseInt(m[1], 10);
    var jm = parseInt(m[2], 10);
    var jd = parseInt(m[3], 10);
    if (jy < 1200 || jy > 1600 || jm < 1 || jm > 12 || jd < 1 || jd > 31) return null;
    return [jy, jm, jd];
  }

  function daysInMonth(jy, jm) {
    if (jm <= 6) return 31;
    if (jm <= 11) return 30;
    var g = jalaliToGregorian(jy, 12, 30);
    var back = gregorianToJalali(g[0], g[1], g[2]);
    return back[2] === 30 ? 30 : 29;
  }

  function weekdaySat0(jy, jm, jd) {
    var g = jalaliToGregorian(jy, jm, jd);
    var utc = new Date(Date.UTC(g[0], g[1] - 1, g[2]));
    return (utc.getUTCDay() + 1) % 7;
  }

  function todayJalali() {
    var now = new Date();
    return gregorianToJalali(now.getFullYear(), now.getMonth() + 1, now.getDate());
  }

  function formatJalali(jy, jm, jd) {
    return jy + '/' + pad(jm) + '/' + pad(jd);
  }

  function ensurePanel() {
    if (panel) return panel;
    panel = document.createElement('div');
    panel.className = 'cms-jdp';
    panel.hidden = true;
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', 'تقویم شمسی');
    document.body.appendChild(panel);
    panel.addEventListener('mousedown', function (event) {
      event.stopPropagation();
    });
    return panel;
  }

  function hide() {
    if (panel) panel.hidden = true;
    activeInput = null;
  }

  function placePanel(input) {
    var rect = input.getBoundingClientRect();
    var top = rect.bottom + window.scrollY + 6;
    var left = rect.left + window.scrollX;
    var width = 280;
    if (left + width > window.scrollX + document.documentElement.clientWidth - 8) {
      left = window.scrollX + document.documentElement.clientWidth - width - 8;
    }
    panel.style.top = top + 'px';
    panel.style.left = Math.max(8, left) + 'px';
  }

  function render() {
    var dim = daysInMonth(viewYear, viewMonth);
    var start = weekdaySat0(viewYear, viewMonth, 1);
    var today = todayJalali();
    var html = '<div class="cms-jdp__head">';
    html += '<button type="button" class="cms-jdp__nav" data-nav="prev" aria-label="ماه قبل">‹</button>';
    html += '<strong class="cms-jdp__title">' + MONTHS[viewMonth - 1] + ' ' + faNum(viewYear) + '</strong>';
    html += '<button type="button" class="cms-jdp__nav" data-nav="next" aria-label="ماه بعد">›</button>';
    html += '</div><div class="cms-jdp__week">';
    WEEK.forEach(function (d) { html += '<span>' + d + '</span>'; });
    html += '</div><div class="cms-jdp__grid">';
    var i;
    for (i = 0; i < start; i++) html += '<span class="cms-jdp__empty"></span>';
    for (i = 1; i <= dim; i++) {
      var cls = 'cms-jdp__day';
      if (selected && selected[0] === viewYear && selected[1] === viewMonth && selected[2] === i) {
        cls += ' is-selected';
      }
      if (today[0] === viewYear && today[1] === viewMonth && today[2] === i) {
        cls += ' is-today';
      }
      html += '<button type="button" class="' + cls + '" data-day="' + i + '">' + faNum(i) + '</button>';
    }
    html += '</div>';
    panel.innerHTML = html;
    panel.querySelector('[data-nav="prev"]').addEventListener('click', function () {
      viewMonth -= 1;
      if (viewMonth < 1) { viewMonth = 12; viewYear -= 1; }
      render();
    });
    panel.querySelector('[data-nav="next"]').addEventListener('click', function () {
      viewMonth += 1;
      if (viewMonth > 12) { viewMonth = 1; viewYear += 1; }
      render();
    });
    panel.querySelectorAll('[data-day]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var day = parseInt(btn.getAttribute('data-day'), 10);
        if (!activeInput) return;
        activeInput.value = formatJalali(viewYear, viewMonth, day);
        activeInput.dispatchEvent(new Event('input', { bubbles: true }));
        activeInput.dispatchEvent(new Event('change', { bubbles: true }));
        hide();
      });
    });
  }

  function openFor(input) {
    ensurePanel();
    activeInput = input;
    selected = parseJalali(input.value);
    var base = selected || todayJalali();
    viewYear = base[0];
    viewMonth = base[1];
    panel.hidden = false;
    render();
    placePanel(input);
  }

  function bind(input) {
    if (input.dataset.jdpBound === '1') return;
    input.dataset.jdpBound = '1';
    input.setAttribute('autocomplete', 'off');
    input.addEventListener('focus', function () { openFor(input); });
    input.addEventListener('click', function () { openFor(input); });
  }

  document.addEventListener('mousedown', function (event) {
    if (!panel || panel.hidden) return;
    if (activeInput && (event.target === activeInput || activeInput.contains(event.target))) return;
    if (panel.contains(event.target)) return;
    hide();
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') hide();
  });

  window.addEventListener('resize', function () {
    if (panel && !panel.hidden && activeInput) placePanel(activeInput);
  });

  function init() {
    document.querySelectorAll('input.cms-jalali-date').forEach(bind);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
