(function () {
  'use strict';

  var dayLabels = ['Man', 'Tir', 'Ons', 'Tor', 'Fre', 'Lør', 'Søn'];
  var monthNames = ['Januar', 'Februar', 'Marts', 'April', 'Maj', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'December'];

  var state = {
    year: null, month: null, days: {},
    baseDaysSelskab: 3, baseDaysMoede: 1, extraPrice: 1000,
    priceSelskab: 3500, priceMoede: 1500, priceMiljoe: 450,
    priceType: 'selskab', extraDays: 0,
    checkIn: null, checkOut: null
  };

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

  function baseDays() {
    return (state.priceType === 'moede' || state.priceType === 'begravelse') ? state.baseDaysMoede : state.baseDaysSelskab;
  }
  function totalDays() {
    return baseDays() + state.extraDays;
  }
  function basePrice() {
    return (state.priceType === 'moede' || state.priceType === 'begravelse') ? state.priceMoede : state.priceSelskab;
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

  function updatePriceEstimate() {
    var el = document.getElementById('hkof-price-estimate');
    if (!el) return;
    var rental = basePrice();
    var extraFee = state.extraDays * state.extraPrice;
    var total = rental + extraFee + state.priceMiljoe;
    var html = 'Lejeafgift: ' + rental.toLocaleString('da-DK') + ' kr.';
    if (state.extraDays > 0) {
      html += ' + ' + state.extraDays + ' ekstra dag' + (state.extraDays > 1 ? 'e' : '') + ' (' + extraFee.toLocaleString('da-DK') + ' kr.)';
    }
    html += ' + miljøafgift ' + state.priceMiljoe.toLocaleString('da-DK') + ' kr. = <strong>' + total.toLocaleString('da-DK') + ' kr.</strong>';
    html += '<br><span class="hkof-note">Hertil kommer depositum, som opkræves separat.</span>';
    el.innerHTML = html;
  }

  function clearSelection(message) {
    state.checkIn = null; state.checkOut = null;
    document.getElementById('hkof-check-in').value = '';
    document.getElementById('hkof-check-out').value = '';
    document.getElementById('hkof-range-display').textContent = message || 'Vælg ankomstdato i kalenderen';
  }

  function recalcSelection() {
    // Kaldes når type eller antal ekstra dage ændres, mens en startdato allerede er valgt
    if (!state.checkIn) { updatePriceEstimate(); return; }
    var days = totalDays();
    if (!rangeIsFree(state.checkIn, days)) {
      clearSelection('Perioden er ikke ledig med det valgte antal dage – vælg venligst en ny startdato.');
      renderCalendar();
      updatePriceEstimate();
      return;
    }
    state.checkOut = addDays(state.checkIn, days - 1);
    document.getElementById('hkof-check-out').value = state.checkOut;
    document.getElementById('hkof-range-display').textContent =
      formatDate(state.checkIn) + ' kl. 12.00 – ' + formatDate(state.checkOut) + ' kl. 12.00';
    renderCalendar();
    updatePriceEstimate();
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
        var days = totalDays();
        if (!rangeIsFree(iso, days)) {
          alert('Perioden på ' + days + ' dage fra denne dato er desværre ikke ledig i sin helhed. Vælg en anden startdato, eller vælg færre ekstra dage.');
          return;
        }
        state.checkIn = iso;
        state.checkOut = addDays(iso, days - 1);
        document.getElementById('hkof-check-in').value = state.checkIn;
        document.getElementById('hkof-check-out').value = state.checkOut;
        document.getElementById('hkof-range-display').textContent =
          formatDate(state.checkIn) + ' kl. 12.00 – ' + formatDate(state.checkOut) + ' kl. 12.00';
        renderCalendar();
        updatePriceEstimate();
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
    state.baseDaysSelskab = parseInt(calEl.getAttribute('data-days-selskab'), 10) || 3;
    state.baseDaysMoede = parseInt(calEl.getAttribute('data-days-moede'), 10) || 1;
    state.extraPrice = parseFloat(calEl.getAttribute('data-extra-price')) || 1000;
    state.priceSelskab = parseFloat(calEl.getAttribute('data-price-selskab')) || 3500;
    state.priceMoede = parseFloat(calEl.getAttribute('data-price-moede')) || 1500;
    state.priceMiljoe = parseFloat(calEl.getAttribute('data-price-miljoe')) || 450;

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

    var typeSelect = document.getElementById('hkof-price-type');
    if (typeSelect) {
      state.priceType = typeSelect.value;
      typeSelect.addEventListener('change', function () {
        state.priceType = typeSelect.value;
        recalcSelection();
      });
    }
    var extraSelect = document.getElementById('hkof-extra-days');
    if (extraSelect) {
      state.extraDays = parseInt(extraSelect.value, 10) || 0;
      extraSelect.addEventListener('change', function () {
        state.extraDays = parseInt(extraSelect.value, 10) || 0;
        recalcSelection();
      });
    }
    updatePriceEstimate();
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
            state.priceType = 'selskab'; state.extraDays = 0;
            document.getElementById('hkof-range-display').textContent = 'Vælg ankomstdato i kalenderen';
            loadMonth(state.year, state.month);
            updatePriceEstimate();
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
