(function () {
  'use strict';

  var dayLabels = ['Man', 'Tir', 'Ons', 'Tor', 'Fre', 'Lør', 'Søn'];
  var monthNames = ['Januar', 'Februar', 'Marts', 'April', 'Maj', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'December'];

  var state = { year: null, month: null, days: {}, defaultDays: 3, checkIn: null, checkOut: null };

  function pad(n) { return n < 10 ? '0' + n : '' + n; }
  function toISO(y, m, d) { return y + '-' + pad(m + 1) + '-' + pad(d); }
  function addDays(iso, n) {
    var d = new Date(iso + 'T12:00:00');
    d.setDate(d.getDate() + n);
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  }
  function todayISO() {
    var d = new Date();
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  }

  function fetchMonth(year, month, cb) {
    var monthStr = year + '-' + pad(month + 1);
    var url = HKOF_BOOKING.ajaxUrl + '?action=hkof_calendar&nonce=' + encodeURIComponent(HKOF_BOOKING.nonce) + '&month=' + monthStr;
    fetch(url).then(function (r) { return r.json(); }).then(function (res) {
      state.days = (res.success && res.data.days) ? res.data.days : {};
      cb();
    }).catch(function () { state.days = {}; cb(); });
  }

  function dayStatus(iso) {
    if (iso < todayISO()) return 'past';
    return state.days[iso] || 'free';
  }

  function rangeIsFree(startISO, days) {
    for (var i = 0; i < days; i++) {
      var s = dayStatus(addDays(startISO, i));
      if (s === 'booked' || s === 'pending' || s === 'past') return false;
    }
    return true;
  }

  function renderCalendar() {
    var grid = document.getElementById('hkof-cal-grid');
    var title = document.getElementById('hkof-cal-title');
    if (!grid) return;
    title.textContent = monthNames[state.month] + ' ' + state.year;

    var firstOfMonth = new Date(state.year, state.month, 1);
    var startWeekday = (firstOfMonth.getDay() + 6) % 7; // Man=0
    var daysInMonth = new Date(state.year, state.month + 1, 0).getDate();

    var html = '';
    dayLabels.forEach(function (l) { html += '<div class="hkof-cal-daylabel">' + l + '</div>'; });
    for (var i = 0; i < startWeekday; i++) html += '<div class="hkof-cal-day hkof-empty"></div>';

    for (var d = 1; d <= daysInMonth; d++) {
      var iso = toISO(state.year, state.month, d);
      var status = dayStatus(iso);
      var cls = 'hkof-cal-day ' + status;
      if (state.checkIn && iso >= state.checkIn && state.checkOut && iso < state.checkOut) cls += ' in-range';
      if (iso === state.checkIn) cls += ' selected';
      html += '<div class="' + cls + '" data-date="' + iso + '">' + d + '</div>';
    }
    grid.innerHTML = html;

    grid.querySelectorAll('.hkof-cal-day:not(.hkof-empty)').forEach(function (el) {
      el.addEventListener('click', function () {
        var iso = el.getAttribute('data-date');
        var status = dayStatus(iso);
        if (status === 'booked' || status === 'pending' || status === 'past') return;
        if (!rangeIsFree(iso, state.defaultDays)) {
          alert('Perioden på ' + state.defaultDays + ' dage fra denne dato er desværre ikke ledig i sin helhed. Vælg en anden startdato.');
          return;
        }
        state.checkIn = iso;
        state.checkOut = addDays(iso, state.defaultDays - 1);
        document.getElementById('hkof-check-in').value = state.checkIn;
        document.getElementById('hkof-check-out').value = state.checkOut;
        document.getElementById('hkof-range-display').textContent =
          formatDate(state.checkIn) + ' kl. 12.00 – ' + formatDate(state.checkOut) + ' kl. 12.00';
        renderCalendar();
      });
    });
  }

  function formatDate(iso) {
    var parts = iso.split('-');
    return parts[2] + '.' + parts[1] + '.' + parts[0];
  }

  function loadMonth(year, month) {
    state.year = year; state.month = month;
    fetchMonth(year, month, renderCalendar);
  }

  function initCalendar() {
    var calEl = document.getElementById('hkof-calendar');
    if (!calEl) return;
    state.defaultDays = parseInt(calEl.getAttribute('data-days'), 10) || 3;
    var now = new Date();
    loadMonth(now.getFullYear(), now.getMonth());

    document.getElementById('hkof-prev-month').addEventListener('click', function () {
      var m = state.month - 1, y = state.year;
      if (m < 0) { m = 11; y--; }
      loadMonth(y, m);
    });
    document.getElementById('hkof-next-month').addEventListener('click', function () {
      var m = state.month + 1, y = state.year;
      if (m > 11) { m = 0; y++; }
      loadMonth(y, m);
    });
  }

  function initForm() {
    var form = document.getElementById('hkof-booking-form');
    if (!form) return;
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var msgEl = document.getElementById('hkof-form-message');
      msgEl.className = ''; msgEl.textContent = '';

      if (!state.checkIn || !state.checkOut) {
        msgEl.className = 'error';
        msgEl.textContent = 'Vælg venligst en periode i kalenderen først.';
        return;
      }

      var btn = form.querySelector('.hkof-submit-btn');
      btn.disabled = true;
      btn.textContent = 'Sender…';

      var fd = new FormData(form);
      fd.append('action', 'hkof_submit_booking');
      fd.append('nonce', HKOF_BOOKING.nonce);

      fetch(HKOF_BOOKING.ajaxUrl, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res.success) {
            msgEl.className = 'success';
            msgEl.textContent = res.data.message;
            form.reset();
            state.checkIn = null; state.checkOut = null;
            document.getElementById('hkof-range-display').textContent = 'Vælg ankomstdato i kalenderen';
            loadMonth(state.year, state.month);
            btn.textContent = 'Send bookingforespørgsel';
          } else {
            msgEl.className = 'error';
            msgEl.textContent = (res.data && res.data.message) ? res.data.message : 'Der opstod en fejl. Prøv igen.';
            btn.disabled = false;
            btn.textContent = 'Send bookingforespørgsel';
          }
        })
        .catch(function () {
          msgEl.className = 'error';
          msgEl.textContent = 'Der opstod en fejl. Prøv igen.';
          btn.disabled = false;
          btn.textContent = 'Send bookingforespørgsel';
        });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    initCalendar();
    initForm();
  });
})();
