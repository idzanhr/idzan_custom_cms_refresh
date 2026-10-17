/* CMS admin helpers — no external libraries. */
(function () {
  'use strict';
  var body = document.body;
  var CSRF = body.dataset.csrf || '';
  var UPLOAD = body.dataset.upload || '';

  /* ---- confirm buttons ---- */
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-confirm]');
    if (b && !window.confirm(b.getAttribute('data-confirm'))) e.preventDefault();
  });

  /* ---- mobile nav ---- */
  var tog = document.querySelector('.nav-toggle');
  if (tog) tog.addEventListener('click', function () {
    var open = tog.getAttribute('aria-expanded') !== 'true';
    tog.setAttribute('aria-expanded', String(open));
    document.getElementById('adminnav').classList.toggle('open', open);
  });

  /* ---- slug autogeneration ---- */
  function slugify(s) {
    var map = { 'č': 'c', 'ć': 'c', 'đ': 'd', 'š': 's', 'ž': 'z', 'ä': 'ae', 'ö': 'oe', 'ü': 'ue', 'ß': 'ss' };
    s = s.toLowerCase().replace(/[čćđšžäöüß]/g, function (c) { return map[c]; });
    s = s.normalize('NFD').replace(/[̀-ͯ]/g, '');
    return s.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 180);
  }
  document.querySelectorAll('[data-slug-from]').forEach(function (slug) {
    var src = document.getElementById(slug.dataset.slugFrom);
    if (!src) return;
    var touched = slug.value !== '';
    slug.addEventListener('input', function () { touched = slug.value !== ''; });
    src.addEventListener('input', function () { if (!touched) slug.placeholder = slugify(src.value) || 'generated automatically'; });
  });

  /* ---- "ongoing" disables end date ---- */
  document.querySelectorAll('[data-disabled-by]').forEach(function (inp) {
    var box = document.getElementById(inp.dataset.disabledBy);
    if (!box) return;
    function sync() {
      inp.disabled = box.checked;
      inp.closest('.field').classList.toggle('is-off', box.checked);
      if (box.checked) inp.value = '';
    }
    box.addEventListener('change', sync);
    sync();
  });

  /* ---- field shown only for a select value (e.g. promo code only when "Has a promo code") ---- */
  document.querySelectorAll('[data-enabled-when]').forEach(function (inp) {
    var sel = document.getElementById(inp.dataset.enabledWhen);
    if (!sel) return;
    var field = inp.closest('.field');
    function sync() {
      var on = sel.value === inp.dataset.enabledValue;
      inp.disabled = !on;
      inp.required = on;
      field.hidden = !on;
      if (on && document.activeElement === sel) inp.focus();
    }
    sel.addEventListener('change', sync);
    sync();
  });

  /* ---- color picker <-> hex text ---- */
  document.querySelectorAll('input[type=color][data-pair]').forEach(function (picker) {
    var txt = document.getElementById(picker.dataset.pair);
    picker.addEventListener('input', function () { txt.value = picker.value.toUpperCase(); txt.dispatchEvent(new Event('input', { bubbles: true })); });
    txt.addEventListener('input', function () { if (/^#[0-9a-f]{6}$/i.test(txt.value)) picker.value = txt.value; });
  });

  /* ---- image field preview ---- */
  document.querySelectorAll('.imgfield').forEach(function (wrap) {
    var file = wrap.querySelector('input[type=file]');
    var prev = wrap.querySelector('img.preview');
    var text = wrap.querySelector('input[type=text]');
    file.addEventListener('change', function () {
      if (file.files[0]) { prev.src = URL.createObjectURL(file.files[0]); prev.hidden = false; }
    });
    text.addEventListener('change', function () {
      var v = text.value.trim();
      if (!v) { prev.hidden = true; prev.removeAttribute('src'); return; }
      prev.src = /^https?:\/\//i.test(v) || v[0] === '/' ? v : (document.querySelector('link[rel=stylesheet]').href.split('/assets/')[0] + '/' + v);
      prev.hidden = false;
    });
    var rm = document.createElement('button');
    rm.type = 'button'; rm.className = 'btn small'; rm.textContent = 'Remove image';
    rm.addEventListener('click', function () { text.value = ''; file.value = ''; prev.hidden = true; markDirty(); });
    wrap.appendChild(rm);
  });

  /* ---- unsaved changes warning ---- */
  var dirty = false;
  function markDirty() { dirty = true; }
  document.querySelectorAll('form[data-unsaved]').forEach(function (f) {
    f.addEventListener('input', markDirty);
    f.addEventListener('change', markDirty);
    f.addEventListener('submit', function () { dirty = false; });
  });
  window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  /* ---- rich text editor ---- */
  function cleanPasted(html) {
    var doc = new DOMParser().parseFromString(html, 'text/html');
    doc.querySelectorAll('script,style,meta,link,title,o\\:p').forEach(function (n) { n.remove(); });
    doc.body.querySelectorAll('*').forEach(function (n) {
      ['style', 'class', 'id', 'dir', 'lang', 'face', 'color', 'size', 'align'].forEach(function (a) { n.removeAttribute(a); });
    });
    doc.body.querySelectorAll('span,font').forEach(function (n) {
      while (n.firstChild) n.parentNode.insertBefore(n.firstChild, n);
      n.remove();
    });
    return doc.body.innerHTML;
  }

  function upload(fileObj, hint) {
    var fd = new FormData();
    fd.append('image', fileObj);
    fd.append('hint', hint || fileObj.name);
    fd.append('_csrf', CSRF);
    return fetch(UPLOAD, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.error || 'Upload failed'); return j; }); });
  }

  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  document.querySelectorAll('textarea.rte').forEach(function (ta) {
    var wrap = document.createElement('div');
    wrap.className = 'rte-wrap';
    var bar = document.createElement('div');
    bar.className = 'rte-bar';
    bar.setAttribute('role', 'toolbar');
    var ed = document.createElement('div');
    ed.className = 'rte-area prose';
    ed.contentEditable = 'true';
    ed.setAttribute('role', 'textbox');
    ed.setAttribute('aria-multiline', 'true');
    ed.setAttribute('aria-labelledby', ta.id + '_lbl');
    var lbl = document.querySelector('label[for="' + ta.id + '"]');
    if (lbl) lbl.id = ta.id + '_lbl';
    ed.innerHTML = ta.value || '<p><br></p>';

    var tools = [
      ['P', 'Paragraph', function () { cmd('formatBlock', '<p>'); }],
      ['H2', 'Heading', function () { cmd('formatBlock', '<h2>'); }],
      ['H3', 'Sub-heading', function () { cmd('formatBlock', '<h3>'); }],
      ['|'],
      ['<b>B</b>', 'Bold', function () { cmd('bold'); }],
      ['<i>I</i>', 'Italic', function () { cmd('italic'); }],
      ['<u>U</u>', 'Underline', function () { cmd('underline'); }],
      ['|'],
      ['• List', 'Bulleted list', function () { cmd('insertUnorderedList'); }],
      ['1. List', 'Numbered list', function () { cmd('insertOrderedList'); }],
      ['❝', 'Quote', function () { cmd('formatBlock', '<blockquote>'); }],
      ['{ }', 'Code block', function () { cmd('formatBlock', '<pre>'); }],
      ['|'],
      ['<span class="sw sw-info"></span>Info', 'Info box (light blue): put the selected paragraphs in a box', function () { box('info'); }],
      ['<span class="sw sw-notice"></span>Notice', 'Notice box (blue, white text)', function () { box('notice'); }],
      ['<span class="sw sw-warning"></span>Warning', 'Warning box (red, white text)', function () { box('warning'); }],
      ['No box', 'Take the selected text out of its box', function () { box(null); }],
      ['|'],
      ['Link', 'Insert link', function () {
        var u = window.prompt('Link address (https://…)', 'https://');
        if (u && u !== 'https://') cmd('createLink', u);
      }],
      ['Unlink', 'Remove link', function () { cmd('unlink'); }],
      ['|'],
      ['🖼 Image', 'Upload image', function () { picker.click(); }],
      ['▶ Video', 'Embed YouTube video (privacy mode)', function () {
        var u = window.prompt('YouTube link');
        var m = u && u.match(/(?:youtu\.be\/|v=|embed\/|shorts\/)([\w-]{11})/);
        if (!m) return;
        cmd('insertHTML', '<p><iframe src="https://www.youtube-nocookie.com/embed/' + m[1] + '" title="YouTube video" width="560" height="315" allowfullscreen></iframe></p><p><br></p>');
      }],
      ['―', 'Divider', function () { cmd('insertHorizontalRule'); }],
      ['|'],
      ['⟲', 'Undo', function () { cmd('undo'); }],
      ['⟳', 'Redo', function () { cmd('redo'); }],
      ['Clear', 'Clear formatting', function () { cmd('removeFormat'); }],
      ['&lt;/&gt;', 'Edit HTML', function () { toggleSource(); }],
    ];

    var saved = null;
    function remember() {
      var sel = window.getSelection();
      if (sel.rangeCount && ed.contains(sel.anchorNode)) saved = sel.getRangeAt(0).cloneRange();
    }
    function restore() {
      ed.focus();
      if (saved) { var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(saved); }
    }
    function cmd(name, val) { restore(); document.execCommand(name, false, val || null); sync(); }
    function sync() { ta.value = ed.innerHTML; markDirty(); }

    /* Boxes: wrap the selected top-level blocks in <div class="box box-…">, change a box's colour, or remove it. */
    function topBlock(n) {
      while (n && n.parentNode !== ed) n = n.parentNode;
      return n;
    }
    function box(kind) {
      restore();
      var sel = window.getSelection();
      if (!sel.rangeCount) return;
      var r = sel.getRangeAt(0);
      var a = topBlock(r.startContainer), b = topBlock(r.endContainer);
      if (!a || !b) return;
      if (a === b && a.classList && a.classList.contains('box')) {           // selection inside one box
        if (kind) a.className = 'box box-' + kind;
        else { while (a.firstChild) ed.insertBefore(a.firstChild, a); a.remove(); }
        sync(); return;
      }
      if (!kind) return;
      var div = document.createElement('div');
      div.className = 'box box-' + kind;
      ed.insertBefore(div, a);
      var n = a, stop = b.nextSibling;
      while (n && n !== stop) {
        var next = n.nextSibling;
        if (n.nodeType === 3 && !n.textContent.trim()) { n.remove(); }
        else if (n.classList && n.classList.contains('box')) { while (n.firstChild) div.appendChild(n.firstChild); n.remove(); }
        else div.appendChild(n);
        n = next;
      }
      if (!div.nextSibling) ed.appendChild(Object.assign(document.createElement('p'), { innerHTML: '<br>' }));
      sync();
    }

    tools.forEach(function (t) {
      if (t[0] === '|') { var s = document.createElement('span'); s.className = 'sep'; bar.appendChild(s); return; }
      var b = document.createElement('button');
      b.type = 'button';
      b.innerHTML = t[0];
      b.title = t[1];
      b.setAttribute('aria-label', t[1]);
      b.addEventListener('mousedown', function (e) { e.preventDefault(); });
      b.addEventListener('click', t[2]);
      bar.appendChild(b);
    });

    var picker = document.createElement('input');
    picker.type = 'file';
    picker.accept = 'image/jpeg,image/png,image/webp,image/gif,image/avif';
    picker.hidden = true;
    picker.addEventListener('change', function () {
      var f = picker.files[0];
      if (!f) return;
      var alt = window.prompt('Describe the image (alt text):', '') || '';
      var cap = window.prompt('Caption (optional):', '') || '';
      ed.classList.add('busy');
      upload(f, alt).then(function (j) {
        var img = '<img src="' + esc(j.url) + '" alt="' + esc(alt) + '"' + (j.width ? ' width="' + j.width + '" height="' + j.height + '"' : '') + '>';
        var html = cap ? '<figure>' + img + '<figcaption>' + esc(cap) + '</figcaption></figure>' : '<p>' + img + '</p>';
        cmd('insertHTML', html + '<p><br></p>');
      }).catch(function (err) { window.alert(err.message); })
        .finally(function () { ed.classList.remove('busy'); picker.value = ''; });
    });

    var source = false;
    function toggleSource() {
      source = !source;
      if (source) { ta.value = ed.innerHTML; ta.hidden = false; ed.hidden = true; ta.focus(); }
      else { ed.innerHTML = ta.value; ta.hidden = true; ed.hidden = false; ed.focus(); }
      bar.querySelectorAll('button').forEach(function (b) { if (b.title !== 'Edit HTML') b.disabled = source; });
    }

    ed.addEventListener('input', sync);
    ed.addEventListener('keyup', remember);
    ed.addEventListener('mouseup', remember);
    ed.addEventListener('blur', remember);
    ed.addEventListener('paste', function (e) {
      var cd = e.clipboardData;
      if (!cd) return;
      if (cd.files && cd.files.length && cd.files[0].type.indexOf('image/') === 0) {
        e.preventDefault();
        remember();
        ed.classList.add('busy');
        upload(cd.files[0], 'pasted-image').then(function (j) {
          cmd('insertHTML', '<p><img src="' + esc(j.url) + '" alt=""></p>');
        }).catch(function (err) { window.alert(err.message); }).finally(function () { ed.classList.remove('busy'); });
        return;
      }
      var html = cd.getData('text/html');
      if (html) { e.preventDefault(); document.execCommand('insertHTML', false, cleanPasted(html)); sync(); }
    });
    ed.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && e.key === 'k') { e.preventDefault(); remember(); tools[13][2](); }
    });

    document.execCommand('defaultParagraphSeparator', false, 'p');
    ta.hidden = true;
    ta.parentNode.insertBefore(wrap, ta);
    wrap.appendChild(bar);
    wrap.appendChild(ed);
    wrap.appendChild(ta);
    wrap.appendChild(picker);
    ta.addEventListener('input', markDirty);

    ta.form.addEventListener('submit', function () { if (!source) ta.value = ed.innerHTML; });
  });
})();

/* ---- media library: copy URL, drop zone, "Choose from Media" picker ---- */
(function () {
  'use strict';
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-copy]');
    if (!b) return;
    var done = function () { var t = b.textContent; b.textContent = 'Copied ✓'; setTimeout(function () { b.textContent = t; }, 1500); };
    if (navigator.clipboard) navigator.clipboard.writeText(b.getAttribute('data-copy')).then(done, function () { window.prompt('Copy this address:', b.getAttribute('data-copy')); });
    else window.prompt('Copy this address:', b.getAttribute('data-copy'));
  });

  document.querySelectorAll('.dropzone').forEach(function (z) {
    var input = z.querySelector('input[type=file]'), label = z.querySelector('span'), orig = label.innerHTML;
    ['dragenter', 'dragover'].forEach(function (ev) { z.addEventListener(ev, function () { z.classList.add('over'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { z.addEventListener(ev, function () { z.classList.remove('over'); }); });
    input.addEventListener('change', function () {
      var n = input.files.length;
      label.textContent = n ? n + ' image' + (n > 1 ? 's' : '') + ' selected — click Upload' : '';
      if (!n) label.innerHTML = orig;
    });
  });

  var feed = document.body.dataset.media;
  var fields = document.querySelectorAll('.imgfield');
  if (!feed || !fields.length || typeof HTMLDialogElement === 'undefined') return;

  var dlg = document.createElement('dialog');
  dlg.className = 'picker';
  dlg.setAttribute('aria-labelledby', 'picker-title');
  dlg.innerHTML = '<div class="picker-head"><h2 id="picker-title">Choose from Media</h2>'
    + '<input type="search" placeholder="Search file name" aria-label="Search media">'
    + '<button type="button" class="btn small" data-close>Close</button></div><ul class="picker-grid" aria-live="polite"></ul>';
  document.body.appendChild(dlg);
  var grid = dlg.querySelector('.picker-grid'), search = dlg.querySelector('input'), items = null, target = null;
  dlg.querySelector('[data-close]').addEventListener('click', function () { dlg.close(); });
  dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });

  function render() {
    var q = search.value.trim().toLowerCase();
    grid.innerHTML = '';
    var shown = (items || []).filter(function (it) { return !q || it.name.toLowerCase().indexOf(q) !== -1; }).slice(0, 200);
    if (!shown.length) { grid.innerHTML = '<li class="muted">No images found.</li>'; return; }
    shown.forEach(function (it) {
      var li = document.createElement('li'), b = document.createElement('button'), img = document.createElement('img'), s = document.createElement('span');
      b.type = 'button'; b.setAttribute('aria-label', 'Use ' + it.name);
      img.src = it.url; img.alt = ''; img.loading = 'lazy'; s.textContent = it.name;
      b.appendChild(img); b.appendChild(s); li.appendChild(b); grid.appendChild(li);
      b.addEventListener('click', function () { choose(it); });
    });
  }
  function choose(it) {
    if (!target) return;
    var texts = target.querySelectorAll('input[type=text]'), text = texts[0], file = target.querySelector('input[type=file]'), prev = target.querySelector('img.preview');
    text.value = it.path; if (file) file.value = '';
    if (texts[1] && !texts[1].value && it.alt) texts[1].value = it.alt;   // the image's alt text from Media
    prev.src = it.url; prev.hidden = false;
    text.dispatchEvent(new Event('input', { bubbles: true }));
    dlg.close();
  }
  search.addEventListener('input', render);

  fields.forEach(function (wrap) {
    var b = document.createElement('button');
    b.type = 'button'; b.className = 'btn small'; b.textContent = 'Choose from Media';
    b.addEventListener('click', function () {
      target = wrap;
      dlg.showModal();
      search.focus();
      if (items) { render(); return; }
      grid.innerHTML = '<li class="muted">Loading…</li>';
      fetch(feed, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (data) { items = data; render(); })
        .catch(function () { grid.innerHTML = '<li class="muted">Could not load the media library.</li>'; });
    });
    var file = wrap.querySelector('input[type=file]');
    file.insertAdjacentElement('afterend', b);
  });
})();

/* ---- Languages: run translations one item at a time, pause on rate limits ---- */
(function () {
  'use strict';
  var box = document.querySelector('[data-tr-runner]');
  if (!box) return;
  var url = box.getAttribute('data-url'), csrf = document.body.dataset.csrf;
  var bar = box.querySelector('[data-tr-bar]'), text = box.querySelector('[data-tr-text]'), log = box.querySelector('[data-tr-log]');
  var wrap = box.querySelector('.tr-progress'), pauseBtn = box.querySelector('[data-tr-pause]'), langSel = box.querySelector('[data-tr-lang]');
  var jobs = [], i = 0, ok = 0, failed = 0, paused = false, running = false, waitTimer = null;
  var names = { hr: 'Hrvatski', bs: 'Bosanski', sr: 'Српски', sl: 'Slovenščina' };

  function post(data) {
    var fd = new FormData(); fd.append('_csrf', csrf);
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); });
  }
  function line(msg, cls) { var li = document.createElement('li'); li.textContent = msg; if (cls) li.className = cls; log.insertBefore(li, log.firstChild); }
  function buttons(on) { box.querySelectorAll('[data-tr-start]').forEach(function (b) { b.disabled = on; }); pauseBtn.hidden = !on; }
  function finish() {
    running = false; buttons(false);
    text.textContent = 'Finished: ' + ok + ' translated' + (failed ? ', ' + failed + ' failed (see the list; you can run it again)' : '') + '. Rebuilding the site…';
    post({ action: 'finish' }).then(function () { text.textContent = text.textContent.replace('Rebuilding the site…', 'Site rebuilt.'); setTimeout(function () { location.reload(); }, 2500); });
  }
  function step() {
    if (paused) return;
    if (i >= jobs.length) return finish();
    var j = jobs[i];
    bar.value = Math.round(i / jobs.length * 100);
    text.textContent = (i + 1) + ' of ' + jobs.length + ': ' + (names[j.l] || j.l) + ' · ' + j.label;
    post({ action: 'run', e: j.e, id: j.id, l: j.l }).then(function (r) {
      if (r.ok) { if (r.backup) line((names[j.l] || j.l) + ' · ' + j.label + ': translated with the backup, ' + r.backup, 'warn'); ok++; i++; return step(); }
      if (r.retry) {                     // rate limit: wait, then try the same job again
        var s = r.retry;
        line('Waiting ' + s + ' s: ' + r.error, 'warn');
        waitTimer = setInterval(function () {
          s--; text.textContent = 'Service limit reached, continuing in ' + s + ' s… (' + i + ' of ' + jobs.length + ' done)';
          if (s <= 0) { clearInterval(waitTimer); step(); }
        }, 1000);
        return;
      }
      failed++; line((names[j.l] || j.l) + ' · ' + j.label + ': ' + r.error, 'err'); i++; step();
    }).catch(function () { failed++; line('Connection problem at “' + j.label + '”, retrying in 20 s', 'err'); setTimeout(step, 20000); });
  }
  box.querySelectorAll('[data-tr-start]').forEach(function (b) {
    b.addEventListener('click', function () {
      if (running) return;
      wrap.hidden = false; log.innerHTML = ''; text.textContent = 'Collecting what needs translating…';
      post({ action: 'queue', mode: b.getAttribute('data-tr-start'), lang: langSel.value }).then(function (r) {
        jobs = r.jobs || []; i = 0; ok = 0; failed = 0; paused = false;
        if (!jobs.length) { text.textContent = 'Nothing to translate — everything is up to date.'; return; }
        running = true; buttons(true); pauseBtn.textContent = 'Pause'; step();
      }).catch(function () { text.textContent = 'Could not start (are you still signed in?).'; });
    });
  });
  pauseBtn.addEventListener('click', function () {
    paused = !paused;
    pauseBtn.textContent = paused ? 'Continue' : 'Pause';
    if (paused) { clearInterval(waitTimer); text.textContent = 'Paused after ' + i + ' of ' + jobs.length + '.'; }
    else step();
  });
  window.addEventListener('beforeunload', function (e) { if (running && !paused) { e.preventDefault(); e.returnValue = ''; } });
})();

/* ---- character counters (search title / description, alt text) and "file name from alt text" ---- */
(function () {
  'use strict';
  document.querySelectorAll('[data-counter]').forEach(function (inp) {
    var max = parseInt(inp.getAttribute('data-counter'), 10), out = document.createElement('p');
    out.className = 'counter small'; out.setAttribute('aria-live', 'polite');
    function upd() {
      var n = inp.value.trim().length;
      out.textContent = n + ' / ' + max + (n > max ? ' — too long, search engines cut it off' : '');
      out.classList.toggle('over', n > max);
    }
    inp.insertAdjacentElement('afterend', out);
    inp.addEventListener('input', upd); upd();
  });
  document.querySelectorAll('[data-name-from]').forEach(function (b) {
    b.addEventListener('click', function () {
      var src = document.getElementById(b.getAttribute('data-name-from')), dst = document.getElementById(b.getAttribute('data-name-to'));
      var map = { 'č': 'c', 'ć': 'c', 'đ': 'd', 'š': 's', 'ž': 'z' };
      var v = src.value.toLowerCase().replace(/[čćđšž]/g, function (c) { return map[c]; }).normalize('NFD').replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 80).replace(/-+$/, '');
      if (v) { dst.value = v; dst.dispatchEvent(new Event('input', { bubbles: true })); dst.focus(); }
    });
  });
})();

/* ---- Media: "Make the missing copies now" (AVIF/WebP), in short rounds until done ---- */
(function () {
  'use strict';
  var b = document.querySelector('[data-convert]');
  if (!b) return;
  var status = document.querySelector('[data-convert-status]'), count = document.querySelector('[data-convert-count]');
  function round() {
    var fd = new FormData(); fd.append('_csrf', document.body.dataset.csrf); fd.append('action', 'convert');
    fetch(b.getAttribute('data-convert'), { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        count.textContent = j.done + ' of ' + j.total;
        if (j.done >= j.total) { status.textContent = 'All copies made. They appear on the site with the next rebuild.'; return; }
        if (!j.made) { status.textContent = 'Some images could not be converted (see the server error log).'; b.disabled = false; return; }
        status.textContent = 'Working… ' + j.made + ' copies made in the last round (keep this page open)'; round();
      })
      .catch(function () { status.textContent = 'Connection problem — click again to continue.'; b.disabled = false; });
  }
  b.addEventListener('click', function () { b.disabled = true; status.textContent = 'Working… (keep this page open)'; round(); });
})();
