/* domain.com — menu + accessibility options. No tracking, no cookies.
   Accessibility choices are remembered in this browser only (localStorage). */
(function () {
  'use strict';
  var root = document.documentElement;
  var KEY = 'a11y-options';

  /* ---------- GDPR consent (first-party cookie "cookie_consent", no third parties) ---------- */
  var CONSENT = 'cookie_consent';
  function readConsent() {
    var m = document.cookie.match(/(?:^|; )cookie_consent=([^;]*)/);
    var v = m ? decodeURIComponent(m[1]) : null;
    if (!v) { try { v = localStorage.getItem(CONSENT); } catch (e) { v = null; } }
    if (!v) return null;
    var parts = v.split('|');               // v1|functional,preferences|timestamp
    return parts[0] === 'v1' ? (parts[1] || 'functional').split(',') : null;
  }
  function writeConsent(cats, days) {
    var v = 'v1|' + cats.join(',') + '|' + Date.now();
    var secure = location.protocol === 'https:' ? '; Secure' : '';
    try { document.cookie = CONSENT + '=' + encodeURIComponent(v) + '; Max-Age=' + (days * 86400) + '; Path=/; SameSite=Lax' + secure; } catch (e) {}
    try { localStorage.setItem(CONSENT, v); } catch (e) {}
  }
  function allowed(cat) { var c = readConsent(); return cat === 'functional' || (!!c && c.indexOf(cat) !== -1); }
  window.siteConsent = { allowed: allowed };

  /* Scripts added later can wait for consent: <script type="text/plain" data-consent="statistics" data-src="/assets/js/stats.js"></script> */
  function activateScripts() {
    document.querySelectorAll('script[type="text/plain"][data-consent]').forEach(function (old) {
      if (!allowed(old.getAttribute('data-consent')) || !old.getAttribute('data-src')) return;
      var sc = document.createElement('script');
      sc.src = old.getAttribute('data-src');
      old.replaceWith(sc);
    });
  }

  function load() {
    if (!allowed('preferences')) return {};
    try { return JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { return {}; }
  }
  function save(o) {
    if (!allowed('preferences')) return; // settings still apply on this page, but aren't stored without consent
    try { localStorage.setItem(KEY, JSON.stringify(o)); } catch (e) { /* storage blocked */ }
  }
  var opts = load();
  function apply() { Object.keys(opts).forEach(function (k) { root.classList.toggle('a11y-' + k, !!opts[k]); }); }
  apply();

  /* Swap images that fail to load for a tidy placeholder instead of a broken-image icon. */
  function monogram(text) {
    var words = String(text || '').replace(/[“”"'’()]/g, '').split(/\s+/).filter(function (w) { return /^[\p{L}\d]/u.test(w); });
    return words.slice(0, 2).map(function (w) { return w.charAt(0).toUpperCase(); }).join('') || '·';
  }
  function placeholder(img) {
    if (img.dataset.ph) return;
    img.dataset.ph = '1';
    var ph = document.createElement('div');
    ph.className = 'img-ph ' + img.className;
    ph.setAttribute('role', 'img');
    var label = img.alt || (img.closest('a') && img.closest('a').getAttribute('aria-label')) || '';
    var card = img.closest('.card');
    if (!label && card) { var h = card.querySelector('h3'); if (h) label = h.textContent; }
    var inHero = img.closest('.hero-img, .about-img');
    if (inHero) label = document.querySelector('.brand') ? document.querySelector('.brand').textContent : label;
    ph.setAttribute('aria-label', label || 'Image');
    ph.textContent = monogram(label);
    if (label && !inHero && !img.closest('.cert')) {
      var s = document.createElement('small');
      s.textContent = label.length > 70 ? label.slice(0, 68) + '…' : label;
      ph.appendChild(s);
    }
    img.replaceWith(ph);
  }
  function watchImages() {
    document.querySelectorAll('img').forEach(function (img) {
      if (img.complete && img.naturalWidth === 0 && img.getAttribute('src')) placeholder(img);
      else img.addEventListener('error', function () { placeholder(img); }, { once: true });
    });
  }

  /* Copy promo code */
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-copy]');
    if (!b) return;
    var code = b.getAttribute('data-copy');
    var orig = b.getAttribute('data-label') || b.textContent;
    b.setAttribute('data-label', orig);
    function done(ok) {
      b.textContent = ok ? (b.getAttribute('data-copied') || 'Copied') : 'Select & copy';
      setTimeout(function () { b.textContent = orig; }, 1800);
    }
    function selectCode() {
      var el = b.parentNode.querySelector('.promo-code');
      if (!el) return;
      var r = document.createRange(); r.selectNodeContents(el);
      var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(r);
    }
    try {
      navigator.clipboard.writeText(code).then(function () { done(true); }, function () { selectCode(); done(false); });
    } catch (err) { selectCode(); done(false); }
  });

  function initConsent() {
    var box = document.querySelector('[data-consent]');
    if (!box) return;
    var days = parseInt(box.getAttribute('data-days'), 10) || 365;
    var cats = box.querySelector('[data-consent-cats]');
    var save = box.querySelector('[data-consent-save]');
    var prefsBtn = box.querySelector('[data-consent-prefs]');
    var fab = document.querySelector('[data-consent-fab]');
    var opener = null;
    function boxes() { return Array.prototype.slice.call(box.querySelectorAll('input[data-cat]:not([disabled])')); }
    // focusFirst: only when the visitor opened it; the banner shown on page load doesn't steal focus
    // (and doesn't force a layout before the first paint).
    function show(withPrefs, focusFirst) {
      var current = readConsent() || ['functional'];
      boxes().forEach(function (b) { b.checked = current.indexOf(b.getAttribute('data-cat')) !== -1; });
      cats.hidden = !withPrefs; save.hidden = !withPrefs; prefsBtn.hidden = !!withPrefs;
      opener = document.activeElement && document.activeElement !== document.body ? document.activeElement : null;
      box.hidden = false;
      if (fab) fab.hidden = true;
      if (focusFirst !== false) { var first = box.querySelector('button:not([hidden])'); if (first) first.focus(); }
    }
    function done(list) {
      writeConsent(list, days);
      close();
      if (list.indexOf('preferences') === -1) { try { localStorage.removeItem('a11y-options'); } catch (e) {} }
      activateScripts();
      document.dispatchEvent(new CustomEvent('consentchange', { detail: list }));
    }
    function close() {
      box.hidden = true;
      if (fab) fab.hidden = false;
      // Put keyboard focus back where it was (or on the consent button), never lost on <body>.
      var back = opener && document.contains(opener) && opener.offsetParent !== null ? opener : fab;
      if (back) back.focus();
      opener = null;
    }
    // Esc closes the dialog, but only once a choice exists (the first time a choice is required).
    box.addEventListener('keydown', function (ev) {
      if ((ev.key === 'Escape' || ev.key === 'Esc') && readConsent()) { ev.preventDefault(); close(); }
    });
    box.querySelector('[data-consent-accept]').addEventListener('click', function () { done(['functional', 'preferences', 'statistics', 'marketing']); });
    box.querySelector('[data-consent-deny]').addEventListener('click', function () { done(['functional']); });
    prefsBtn.addEventListener('click', function () { show(true); });
    save.addEventListener('click', function () {
      done(['functional'].concat(boxes().filter(function (b) { return b.checked; }).map(function (b) { return b.getAttribute('data-cat'); })));
    });
    document.querySelectorAll('[data-consent-open]').forEach(function (b) { b.addEventListener('click', function () { show(true); }); });
    if (!readConsent()) show(false, false); else { if (fab) fab.hidden = false; activateScripts(); }
  }

  /* Reading progress on blog posts: how far the article text has been scrolled through. */
  function initReadProgress() {
    var body = document.querySelector('[data-read-body]');
    if (!body) return;
    var bar = document.querySelector('[data-read-progress]');
    var pct = document.querySelector('[data-read-pct]');
    var fill = document.querySelector('[data-read-fill]');
    var last = -1, ticking = false;
    function update() {
      ticking = false;
      var r = body.getBoundingClientRect();
      var total = r.height - window.innerHeight * 0.6;
      var done = total > 0 ? (-r.top + window.innerHeight * 0.4) / total : 1;
      var p = Math.round(Math.max(0, Math.min(1, done)) * 100);
      if (p === last) return;
      last = p;
      if (bar) { bar.value = p; bar.textContent = p + '%'; }
      if (pct) pct.textContent = p;
      if (fill) fill.style.transform = 'scaleX(' + (p / 100) + ')';
    }
    function req() { if (!ticking) { ticking = true; window.requestAnimationFrame(update); } }
    window.addEventListener('scroll', req, { passive: true });
    window.addEventListener('resize', req);
    req();
  }

  document.addEventListener('DOMContentLoaded', function () {
    watchImages();
    initReadProgress();
    initConsent();
    var btn = document.querySelector('.menu-btn');
    var nav = document.getElementById('mainnav');
    if (btn && nav) {
      btn.addEventListener('click', function () {
        var open = btn.getAttribute('aria-expanded') !== 'true';
        btn.setAttribute('aria-expanded', String(open));
        nav.classList.toggle('open', open);
      });
    }

    var aBtn = document.querySelector('.a11y-btn');
    var panel = document.getElementById('a11ypanel');
    if (!aBtn || !panel) return;
    function setOpen(open) { aBtn.setAttribute('aria-expanded', String(open)); panel.hidden = !open; }
    aBtn.addEventListener('click', function () { setOpen(panel.hidden); if (!panel.hidden) panel.querySelector('input').focus(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hidden) { setOpen(false); aBtn.focus(); } });
    document.addEventListener('click', function (e) { if (!panel.hidden && !e.target.closest('[data-a11y]')) setOpen(false); });

    panel.querySelectorAll('[data-a11y-opt]').forEach(function (box) {
      var k = box.getAttribute('data-a11y-opt');
      box.checked = !!opts[k];
      box.addEventListener('change', function () { opts[k] = box.checked; save(opts); apply(); });
    });
    panel.querySelector('[data-a11y-reset]').addEventListener('click', function () {
      Object.keys(opts).forEach(function (k) { root.classList.remove('a11y-' + k); });
      opts = {}; save(opts);
      panel.querySelectorAll('[data-a11y-opt]').forEach(function (b) { b.checked = false; });
    });
  });
})();

/* Language: header menu, remembered in a functional cookie "site_lang" (set only when a visitor picks a language). */
(function () {
  'use strict';
  var COOKIE = 'site_lang';
  function get() { var m = document.cookie.match(/(?:^|; )site_lang=([a-z]{2})/); return m ? m[1] : null; }
  function set(l) {
    var secure = location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = COOKIE + '=' + l + '; Max-Age=31536000; Path=/; SameSite=Lax' + secure;
  }
  var here = (document.documentElement.getAttribute('lang') || 'en').slice(0, 2);

  // Coming back: open the page in the language chosen before, if this page has that version.
  var want = get();
  if (want && want !== here) {
    var alt = document.querySelector('link[rel="alternate"][hreflang^="' + want + '"]');
    if (alt) {
      var to = new URL(alt.href, location.href);   // same site and host, just the language's path
      if (to.pathname !== location.pathname) { location.replace(to.pathname + location.search + location.hash); return; }
    }
  }

  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[data-lang]');
    if (!a) return;
    set(a.getAttribute('data-lang'));
  });

  var box = document.querySelector('[data-lang-switch]');
  if (!box) return;
  var btn = box.querySelector('.lang-btn'), menu = box.querySelector('.lang-menu');
  function open(on) { btn.setAttribute('aria-expanded', String(on)); menu.hidden = !on; }
  btn.addEventListener('click', function () {
    var on = menu.hidden; open(on);
    if (on) { var cur = menu.querySelector('[aria-current]') || menu.querySelector('a'); if (cur) cur.focus(); }
  });
  box.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !menu.hidden) { open(false); btn.focus(); }
    if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && !menu.hidden) {
      var links = Array.prototype.slice.call(menu.querySelectorAll('a')), i = links.indexOf(document.activeElement);
      e.preventDefault();
      links[(i + (e.key === 'ArrowDown' ? 1 : links.length - 1)) % links.length].focus();
    }
  });
  document.addEventListener('click', function (e) { if (!menu.hidden && !e.target.closest('[data-lang-switch]')) open(false); });
})();


/* Light / dark switch, remembered in a functional cookie "site_theme" (set only when a visitor presses the button). */
(function () {
  var btn = document.querySelector('[data-theme-toggle]');
  if (!btn) return;
  var d = document.documentElement;
  function sync() {
    var light = d.getAttribute('data-theme') === 'light';
    var label = btn.getAttribute(light ? 'data-label-dark' : 'data-label-light');
    btn.setAttribute('aria-pressed', light ? 'true' : 'false');
    btn.setAttribute('aria-label', label); btn.setAttribute('title', label);
  }
  btn.addEventListener('click', function () {
    var next = d.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
    d.setAttribute('data-theme', next);
    document.cookie = 'site_theme=' + next + '; Max-Age=31536000; Path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
    sync();
  });
  sync();
})();

/* Lightbox: images in page text open large in a dialog. Not for title/header images, SVGs, small icons,
   or images that link somewhere other than an image file. Keyboard: ←/→ to browse, Esc to close. */
(function () {
  var main = document.querySelector('main');
  if (!main || typeof HTMLDialogElement === 'undefined') return;
  var L = (document.body.getAttribute('data-lb') || 'Close|Previous image|Next image|Enlarge image|Image {n} of {total}').split('|');
  var IMG = /\.(jpe?g|png|webp|gif|avif)([?#].*)?$/i;
  var items = [];

  Array.prototype.forEach.call(main.querySelectorAll('.prose img'), function (img) {
    var src = img.getAttribute('src') || '';
    if (/\.svg([?#].*)?$/i.test(src) || /^data:/.test(src)) return;
    if (img.closest('.post-hero, .hero-img, .sc-img, .about-img, .contact-grid, .share, .thumb, [data-no-lightbox]')) return;
    if (img.getAttribute('width') && +img.getAttribute('width') < 160) return;
    var a = img.closest('a');
    if (a && !IMG.test(a.getAttribute('href') || '')) return;           // links to a page: leave the link alone
    var item = { img: img, full: a ? a.href : img.getAttribute('src'), trigger: a };   // full size: the linked file, else the original
    if (!a) {                                                           // make it reachable by keyboard
      var b = document.createElement('button'), box = img.closest('picture') || img;
      b.type = 'button'; b.className = 'lb-trigger';
      b.setAttribute('aria-label', L[3] + (img.alt ? ': ' + img.alt : ''));
      box.parentNode.insertBefore(b, box); b.appendChild(box);
      item.trigger = b;
    } else if (!a.getAttribute('aria-label')) {
      a.setAttribute('aria-label', L[3] + (img.alt ? ': ' + img.alt : ''));
    }
    item.trigger.classList.add('lb-zoom');
    items.push(item);
  });
  if (!items.length) return;

  var dlg = document.createElement('dialog');
  dlg.className = 'lightbox';
  dlg.setAttribute('aria-label', L[3]);
  dlg.innerHTML = '<figure class="lb-fig"><img class="lb-img" alt=""><figcaption class="lb-cap"></figcaption></figure>'
    + '<p class="lb-count" aria-live="polite"></p>'
    + '<button type="button" class="lb-btn lb-close"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18"/></svg></button>'
    + '<button type="button" class="lb-btn lb-prev"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M15 5l-7 7 7 7"/></svg></button>'
    + '<button type="button" class="lb-btn lb-next"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 5l7 7-7 7"/></svg></button>';
  document.body.appendChild(dlg);
  var big = dlg.querySelector('.lb-img'), cap = dlg.querySelector('.lb-cap'), count = dlg.querySelector('.lb-count');
  var prev = dlg.querySelector('.lb-prev'), next = dlg.querySelector('.lb-next'), close = dlg.querySelector('.lb-close');
  close.setAttribute('aria-label', L[0]); prev.setAttribute('aria-label', L[1]); next.setAttribute('aria-label', L[2]);
  prev.hidden = next.hidden = items.length < 2;
  var cur = 0, opener = null;
  // Full-size picture in the best format this browser can show: the largest AVIF/WebP copy of the same image, else the original.
  var can = { avif: false, webp: false };
  ['avif', 'webp'].forEach(function (f) { var t = new Image(); t.onload = function () { can[f] = t.width > 0; }; t.src = f === 'avif'
    ? 'data:image/avif;base64,AAAAIGZ0eXBhdmlmAAAAAGF2aWZtaWYxbWlhZk1BMUIAAADybWV0YQAAAAAAAAAoaGRscgAAAAAAAAAAcGljdAAAAAAAAAAAAAAAAGxpYmF2aWYAAAAADnBpdG0AAAAAAAEAAAAeaWxvYwAAAABEAAABAAEAAAABAAABGgAAAB0AAAAoaWluZgAAAAAAAQAAABppbmZlAgAAAAABAABhdjAxQ29sb3IAAAAAamlwcnAAAABLaXBjbwAAABRpc3BlAAAAAAAAAAIAAAACAAAAEHBpeGkAAAAAAwgICAAAAAxhdjFDgQ0MAAAAABNjb2xybmNseAACAAIAAYAAAAAXaXBtYQAAAAAAAAABAAEEAQKDBAAAACVtZGF0EgAKCBgANogQEAwgMg8f8D///8WfhwB8+ErK42A='
    : 'data:image/webp;base64,UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA'; });
  function best(it) {
    var pic = it.img.closest('picture');
    if (pic && it.full === it.img.getAttribute('src') || (pic && it.full && it.full.replace(location.origin, '') === it.img.getAttribute('src'))) {
      var pick = null;
      Array.prototype.forEach.call(pic.querySelectorAll('source'), function (s) {
        var f = (s.getAttribute('type') || '').replace('image/', '');
        if (pick || !can[f]) return;
        var top = (s.getAttribute('srcset') || '').split(',').map(function (x) { var p = x.trim().split(/\s+/); return { u: p[0], w: parseInt(p[1], 10) || 0 }; })
          .sort(function (a, b) { return b.w - a.w; })[0];
        if (top) pick = top.u;
      });
      if (pick) return pick;
    }
    return it.full || it.img.currentSrc || it.img.src;
  }

  function show(i) {
    cur = (i + items.length) % items.length;
    var it = items[cur], fig = it.img.closest('figure'), fc = fig && fig.querySelector('figcaption');
    big.classList.add('loading');
    big.alt = it.img.alt || '';
    big.src = best(it);
    var text = fc ? fc.textContent.trim() : '';
    cap.textContent = text || it.img.alt || '';
    cap.hidden = !cap.textContent;
    count.textContent = items.length > 1 ? L[4].replace('{n}', cur + 1).replace('{total}', items.length) : '';
  }
  big.addEventListener('load', function () { big.classList.remove('loading'); });
  big.addEventListener('error', function () { var it = items[cur]; big.classList.remove('loading'); if (big.src !== it.img.src) big.src = it.img.src; });

  items.forEach(function (it, i) {
    it.trigger.addEventListener('click', function (e) {
      if (e.ctrlKey || e.metaKey || e.shiftKey || e.button > 0) return;   // new tab / window still works
      if (it.img.naturalWidth && it.img.naturalWidth < 160) return;        // tiny image: nothing to enlarge
      e.preventDefault();
      opener = it.trigger;
      show(i);
      dlg.showModal();
    });
  });
  prev.addEventListener('click', function () { show(cur - 1); });
  next.addEventListener('click', function () { show(cur + 1); });
  close.addEventListener('click', function () { dlg.close(); });
  dlg.addEventListener('click', function (e) { if (e.target === dlg || e.target.classList.contains('lb-fig')) dlg.close(); });
  dlg.addEventListener('keydown', function (e) {
    if (items.length < 2) return;
    if (e.key === 'ArrowLeft') { e.preventDefault(); show(cur - 1); }
    if (e.key === 'ArrowRight') { e.preventDefault(); show(cur + 1); }
  });
  var x0 = null;                                                          // swipe on phones
  dlg.addEventListener('pointerdown', function (e) { if (e.pointerType !== 'mouse') x0 = e.clientX; });
  dlg.addEventListener('pointerup', function (e) {
    if (x0 === null || items.length < 2) return;
    var dx = e.clientX - x0; x0 = null;
    if (Math.abs(dx) > 50) show(dx < 0 ? cur + 1 : cur - 1);
  });
  dlg.addEventListener('close', function () { big.removeAttribute('src'); if (opener) opener.focus(); });
})();
