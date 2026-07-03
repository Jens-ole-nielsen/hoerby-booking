/**
 * Genbrugelig kalender-forhåndsvisning til wp-admin "Rediger booking" og
 * front-end admin-dashboardet. Bruger samme AJAX-endpoint og CSS-klasser
 * som den offentlige booking-kalender (assets/css/style.css), så visningen
 * ser identisk ud med forsiden - men markerer den booking man kigger på
 * som "valgt periode" (blå) i stedet for rødt/orange, og udelader den fra
 * selve optagetheds-tjekket (exclude_id) så den ikke fremstår som en
 * konflikt med sig selv.
 */
(function () {
  'use strict';

  window.HKOF_AdminCalendar = function (opts) {
    var dayLabels = ['Man', 'Tir', 'Ons', 'Tor', 'Fre', 'Lør', 'Søn'];
    var monthNames = ['Januar', 'Februar', 'Marts', 'April', 'Maj', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'December'];

    var grid = document.getElementById(opts.gridId);
    var title = document.getElementById(opts.titleId);
    var prevBtn = document.getElementById(opts.prevId);
    var nextBtn = document.getElementById(opts.nextId);
    var warningEl = opts.warningId ? document.getElementById(opts.warningId) : null;
    var checkInInput = opts.checkInInputId ? document.getElementById(opts.checkInInputId) : null;
    var checkOutInput = opts.checkOutInputId ? document.getElementById(opts.checkOutInputId) : null;

    if (!grid) return;

    var days = {};
    var view = { year: null, month: null };

    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function toISO(y, m, d) { return y + '-' + pad(m + 1) + '-' + pad(d); }

    function currentRange() {
      if (opts.editable) {
        var ci = checkInInput ? checkInInput.value : null;
        var co = checkOutInput ? checkOutInput.value : null;
        if (ci && co) return { checkIn: ci, checkOut: co };
        return null;
      }
      return (opts.checkIn && opts.checkOut) ? { checkIn: opts.checkIn, checkOut: opts.checkOut } : null;
    }

    function fetchMonth(year, month, cb) {
      var monthStr = year + '-' + pad(month + 1);
      var url = opts.ajaxUrl + '?action=hkof_calendar&nonce=' + encodeURIComponent(opts.nonce) + '&month=' + monthStr + '&exclude_id=' + encodeURIComponent(opts.excludeId || 0);
      fetch(url).then(function (r) { return r.json(); }).then(function (res) {
        days = (res.success && res.data.days) ? res.data.days : {};
        cb();
      }).catch(function () { days = {}; cb(); });
    }

    function dayInfo(iso) {
      var d = days[iso];
      if (!d) return { status: 'free', half: null };
      return { status: d.status, half: d.half || null };
    }

    function updateWarning() {
      if (!warningEl) return;
      var range = currentRange();
      if (!range) { warningEl.style.display = 'none'; return; }
      var conflict = false;
      var cursor = new Date(range.checkIn + 'T12:00:00');
      var stop = new Date(range.checkOut + 'T12:00:00');
      while (cursor <= stop) {
        var iso = cursor.getFullYear() + '-' + pad(cursor.getMonth() + 1) + '-' + pad(cursor.getDate());
        var st = dayInfo(iso).status;
        if (st === 'booked' || st === 'pending') { conflict = true; break; }
        cursor.setDate(cursor.getDate() + 1);
      }
      warningEl.style.display = conflict ? '' : 'none';
    }

    function render() {
      title.textContent = monthNames[view.month] + ' ' + view.year;
      var firstOfMonth = new Date(view.year, view.month, 1);
      var startWeekday = (firstOfMonth.getDay() + 6) % 7;
      var daysInMonth = new Date(view.year, view.month + 1, 0).getDate();
      var range = currentRange();

      var html = '';
      dayLabels.forEach(function (l) { html += '<div class="hkof-cal-daylabel">' + l + '</div>'; });
      for (var i = 0; i < startWeekday; i++) html += '<div class="hkof-cal-day hkof-empty"></div>';

      for (var d = 1; d <= daysInMonth; d++) {
        var iso = toISO(view.year, view.month, d);
        var info = dayInfo(iso);
        var cls = 'hkof-cal-day ' + info.status;
        if (info.half) cls += ' half-' + info.half;

        if (range) {
          if (iso === range.checkIn && iso === range.checkOut) cls += ' hkof-range-single';
          else if (iso === range.checkIn) cls += ' hkof-range-start';
          else if (iso === range.checkOut) cls += ' hkof-range-end';
          else if (iso > range.checkIn && iso < range.checkOut) cls += ' hkof-range-middle';
        }
        html += '<div class="' + cls + '" data-date="' + iso + '">' + d + '</div>';
      }
      grid.innerHTML = html;

      if (opts.editable && checkInInput) {
        grid.querySelectorAll('.hkof-cal-day:not(.hkof-empty)').forEach(function (el) {
          el.addEventListener('click', function () {
            var iso = el.getAttribute('data-date');
            var status = dayInfo(iso).status;
            if (status === 'booked' || status === 'pending') return;
            checkInInput.value = iso;
            checkInInput.dispatchEvent(new Event('change', { bubbles: true }));
            render();
            updateWarning();
          });
        });
      }
      updateWarning();
    }

    function goToMonth(year, month) {
      view.year = year; view.month = month;
      fetchMonth(year, month, render);
    }

    if (prevBtn) prevBtn.addEventListener('click', function () {
      var m = view.month - 1, y = view.year;
      if (m < 0) { m = 11; y--; }
      goToMonth(y, m);
    });
    if (nextBtn) nextBtn.addEventListener('click', function () {
      var m = view.month + 1, y = view.year;
      if (m > 11) { m = 0; y++; }
      goToMonth(y, m);
    });
    if (opts.editable && checkInInput) checkInInput.addEventListener('change', function () { render(); });
    if (opts.editable && checkOutInput) checkOutInput.addEventListener('change', function () { render(); });

    var startISO = (opts.editable ? (checkInInput && checkInInput.value) : opts.checkIn) || null;
    var startDate = startISO ? new Date(startISO + 'T12:00:00') : new Date();
    goToMonth(startDate.getFullYear(), startDate.getMonth());

    return { refresh: render };
  };
})();
