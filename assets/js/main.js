/* Data Academy — shared behaviour (EN + AR) */
(function () {
  'use strict';

  var html = document.documentElement;
  var lang = html.getAttribute('lang') || 'en';
  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------- Header: scrolled state + mobile nav ---------- */
  var header = document.querySelector('.header');
  var nav = document.querySelector('.nav');
  var toggle = document.querySelector('.nav-toggle');

  function onScroll() {
    if (!header) return;
    header.classList.toggle('is-scrolled', window.scrollY > 8);
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  if (toggle && nav) {
    toggle.addEventListener('click', function () {
      var open = toggle.getAttribute('aria-expanded') === 'true';
      toggle.setAttribute('aria-expanded', String(!open));
      nav.classList.toggle('is-open', !open);
    });
    nav.querySelectorAll('a').forEach(function (a) {
      a.addEventListener('click', function () {
        toggle.setAttribute('aria-expanded', 'false');
        nav.classList.remove('is-open');
      });
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && nav.classList.contains('is-open')) {
        toggle.setAttribute('aria-expanded', 'false');
        nav.classList.remove('is-open');
        toggle.focus();
      }
    });
  }

  /* ---------- Active nav link while scrolling ---------- */
  var navLinks = Array.prototype.slice.call(document.querySelectorAll('.nav a[href^="#"]'));
  var sections = navLinks.map(function (a) { return document.querySelector(a.getAttribute('href')); }).filter(Boolean);
  if ('IntersectionObserver' in window && sections.length) {
    var activeObs = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          navLinks.forEach(function (a) {
            a.classList.toggle('is-active', a.getAttribute('href') === '#' + entry.target.id);
          });
        }
      });
    }, { rootMargin: '-40% 0px -55% 0px', threshold: 0 });
    sections.forEach(function (s) { activeObs.observe(s); });
  }

  /* ---------- Reveal on scroll ---------- */
  var revealEls = document.querySelectorAll('.reveal');
  if (reduceMotion || !('IntersectionObserver' in window)) {
    revealEls.forEach(function (el) { el.classList.add('is-visible'); });
  } else {
    var revealObs = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-visible');
          revealObs.unobserve(entry.target);
        }
      });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
    revealEls.forEach(function (el) { revealObs.observe(el); });
    // Safety net: never leave content hidden if the observer stalls (e.g. background tabs).
    setTimeout(function () { revealEls.forEach(function (el) { el.classList.add('is-visible'); }); }, 3500);
  }

  /* ---------- Animated bars (width/height from data attributes) ---------- */
  var animBars = document.querySelectorAll('[data-w], [data-h]');
  function fillBar(el) {
    if (el.hasAttribute('data-w')) el.style.setProperty('--w', el.getAttribute('data-w'));
    if (el.hasAttribute('data-h')) el.style.setProperty('--h', el.getAttribute('data-h'));
  }
  var barGroups = document.querySelectorAll('.bars, .vbars');
  if (reduceMotion || !('IntersectionObserver' in window) || !barGroups.length) {
    animBars.forEach(fillBar);
  } else {
    animBars.forEach(function (el) {
      // Bars start collapsed; the group container (not the zero-size bar) is observed.
      if (el.closest('.bars, .vbars')) el.style.setProperty(el.hasAttribute('data-w') ? '--w' : '--h', '0%');
      else fillBar(el);
    });
    var barObs = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.querySelectorAll('[data-w], [data-h]').forEach(fillBar);
          barObs.unobserve(entry.target);
        }
      });
    }, { threshold: 0.2 });
    barGroups.forEach(function (g) { barObs.observe(g); });
    setTimeout(function () { animBars.forEach(fillBar); }, 3500);
  }

  /* ---------- Count-up statistics ---------- */
  var counters = document.querySelectorAll('[data-count]');
  // Western digits on both pages (matches the brochure and the rest of the page); no grouping in Arabic.
  var fmt = new Intl.NumberFormat('en-US', { useGrouping: lang !== 'ar' });
  function runCounter(el) {
    var target = parseInt(el.getAttribute('data-count'), 10);
    if (reduceMotion || isNaN(target)) { el.textContent = fmt.format(target); return; }
    var start = null, dur = 1100;
    function step(ts) {
      if (!start) start = ts;
      var p = Math.min((ts - start) / dur, 1);
      var eased = 1 - Math.pow(1 - p, 3);
      el.textContent = fmt.format(Math.round(target * eased));
      if (p < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }
  if ('IntersectionObserver' in window) {
    var cObs = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) { runCounter(entry.target); cObs.unobserve(entry.target); }
      });
    }, { threshold: 0.5 });
    counters.forEach(function (el) { cObs.observe(el); });
  } else {
    counters.forEach(runCounter);
  }

  /* ---------- Program table filter ---------- */
  var filterBar = document.querySelector('[data-filters]');
  var table = document.querySelector('[data-programs]');
  if (filterBar && table) {
    var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-sector]'));
    var countEl = document.querySelector('[data-count-visible]');
    var hoursEl = document.querySelector('[data-hours-visible]');
    var emptyEl = table.querySelector('.table-empty');
    function applyFilter(sector) {
      var visible = 0, hours = 0;
      rows.forEach(function (tr) {
        var show = sector === 'all' || tr.getAttribute('data-sector') === sector;
        tr.hidden = !show;
        if (show) { visible++; hours += parseInt(tr.getAttribute('data-hours'), 10) || 0; }
      });
      if (countEl) countEl.textContent = fmt.format(visible);
      if (hoursEl) hoursEl.textContent = fmt.format(hours);
      if (emptyEl) emptyEl.hidden = visible !== 0;
    }
    filterBar.addEventListener('click', function (e) {
      var btn = e.target.closest('.filter');
      if (!btn) return;
      filterBar.querySelectorAll('.filter').forEach(function (b) { b.setAttribute('aria-pressed', 'false'); });
      btn.setAttribute('aria-pressed', 'true');
      applyFilter(btn.getAttribute('data-sector'));
    });
    applyFilter('all');
  }

  /* ---------- Contact form ---------- */
  var form = document.querySelector('#contact-form');
  if (form) {
    var status = form.querySelector('.form__status');
    var submitBtn = form.querySelector('button[type="submit"]');
    var msgs = form.getAttribute('data-messages') ? JSON.parse(form.getAttribute('data-messages')) : {};

    function setError(field, hasError) {
      var wrap = field.closest('.field');
      if (!wrap) return;
      wrap.classList.toggle('has-error', hasError);
      field.setAttribute('aria-invalid', hasError ? 'true' : 'false');
    }
    function validate() {
      var ok = true, first = null;
      form.querySelectorAll('[required]').forEach(function (f) {
        var valid = f.checkValidity() && f.value.trim() !== '';
        setError(f, !valid);
        if (!valid) { ok = false; if (!first) first = f; }
      });
      if (first) first.focus();
      return ok;
    }
    form.querySelectorAll('[required]').forEach(function (f) {
      f.addEventListener('input', function () { if (f.closest('.field').classList.contains('has-error')) setError(f, !(f.checkValidity() && f.value.trim() !== '')); });
    });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      status.className = 'form__status';
      if (!validate()) return;

      submitBtn.classList.add('is-loading');
      submitBtn.disabled = true;

      var data = new FormData(form);
      data.append('lang', lang);

      fetch(form.getAttribute('action'), { method: 'POST', body: data, headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json().catch(function () { throw new Error('bad-json'); }); })
        .then(function (json) {
          if (json && json.ok) {
            status.textContent = msgs.success || 'Thank you. Your request has been received; our team will contact you shortly.';
            status.classList.add('is-ok');
            form.reset();
          } else {
            throw new Error(json && json.error ? json.error : 'server');
          }
        })
        .catch(function () {
          status.textContent = msgs.error || 'We could not send your request right now. Please email team@thedatacademy.com or call us.';
          status.classList.add('is-err');
        })
        .finally(function () {
          submitBtn.classList.remove('is-loading');
          submitBtn.disabled = false;
          status.focus && status.setAttribute('tabindex', '-1');
          status.focus();
        });
    });
  }

  /* ---------- Footer year ---------- */
  document.querySelectorAll('[data-year]').forEach(function (el) { el.textContent = new Date().getFullYear(); });
})();
