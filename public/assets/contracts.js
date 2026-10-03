// Contracts (2.2): the template builder, the prepare page's live preview, and the signature pad.
(() => {
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const slug = (label, taken) => {
    let base = String(label).toLowerCase().normalize('NFKD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
    if (!/^[a-z]/.test(base)) base = 'field_' + base;
    base = base.slice(0, 34).replace(/_+$/, '') || 'field';
    let k = base;
    for (let i = 2; taken.includes(k); i++) k = base + '_' + i;
    return k;
  };
  const debounce = (fn, ms) => { let t = null; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };
  const csrfOf = (form) => (form.querySelector('[name="_csrf"]') || {}).value || '';
  const restart = (form) => { if (typeof formState === 'function') form.alignStart = formState(form); }; // eslint-disable-line no-undef

  // ---- Signature pad ---------------------------------------------------------------------------------------
  const sigpad = (pad) => {
    const kind = pad.querySelector('[data-sig-kind]');
    const png = pad.querySelector('[data-sig-png]');
    const typed = pad.querySelector('[data-sig-typed]');
    const name = pad.querySelector('[data-sig-name]');
    const canvas = pad.querySelector('[data-sig-canvas]');
    let typedTouched = typed && typed.value !== '';
    if (name && typed) {
      if (!typed.value) typed.value = name.value;
      name.addEventListener('input', () => { if (!typedTouched) typed.value = name.value; });
      typed.addEventListener('input', () => { typedTouched = typed.value !== ''; });
    }
    pad.querySelectorAll('[data-sig-tab]').forEach((b) => b.addEventListener('click', () => {
      kind.value = b.dataset.sigTab;
      pad.querySelectorAll('[data-sig-tab]').forEach((x) => { x.classList.toggle('active', x === b); x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
      pad.querySelectorAll('[data-sig-pane]').forEach((p) => p.classList.toggle('d-none', p.dataset.sigPane !== kind.value));
      if (typed) typed.required = kind.value === 'type';
    }));
    if (!canvas) return;
    const ink = inkPad(canvas);
    pad.querySelector('[data-sig-clear]').addEventListener('click', () => { ink.clear(); png.value = ''; });
    // On submit: the drawing, cropped to the ink
    pad.closest('form').addEventListener('submit', () => { png.value = kind.value === 'draw' ? ink.png() : ''; });
  };

  /** Drawing on a canvas with the mouse, a pen or a finger: clear(), and png() (cropped to the ink, '' if none). */
  const inkPad = (canvas) => {
    const ctx = canvas.getContext('2d');
    let drawing = false, drawn = false, last = null;
    const pos = (ev) => {
      const r = canvas.getBoundingClientRect();
      return { x: (ev.clientX - r.left) * (canvas.width / r.width), y: (ev.clientY - r.top) * (canvas.height / r.height) };
    };
    ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#13235b'; ctx.lineWidth = 5;
    canvas.addEventListener('pointerdown', (ev) => { drawing = true; last = pos(ev); canvas.setPointerCapture(ev.pointerId); ev.preventDefault(); });
    canvas.addEventListener('pointermove', (ev) => {
      if (!drawing) return;
      const p = pos(ev);
      ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke();
      last = p; drawn = true;
    });
    const stop = () => { drawing = false; };
    canvas.addEventListener('pointerup', stop);
    canvas.addEventListener('pointercancel', stop);
    return {
      clear: () => { ctx.clearRect(0, 0, canvas.width, canvas.height); drawn = false; },
      png: () => {
        if (!drawn) return '';
        const d = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
        let x0 = canvas.width, y0 = canvas.height, x1 = 0, y1 = 0;
        for (let y = 0; y < canvas.height; y += 2) {
          for (let x = 0; x < canvas.width; x += 2) {
            if (d[(y * canvas.width + x) * 4 + 3] > 30) { x0 = Math.min(x0, x); y0 = Math.min(y0, y); x1 = Math.max(x1, x); y1 = Math.max(y1, y); }
          }
        }
        if (x1 <= x0) return '';
        const padPx = 12;
        x0 = Math.max(0, x0 - padPx); y0 = Math.max(0, y0 - padPx); x1 = Math.min(canvas.width, x1 + padPx); y1 = Math.min(canvas.height, y1 + padPx);
        const out = document.createElement('canvas');
        out.width = Math.max(60, x1 - x0); out.height = Math.max(30, y1 - y0);
        out.getContext('2d').drawImage(canvas, x0, y0, x1 - x0, y1 - y0, 0, 0, x1 - x0, y1 - y0);
        return out.toDataURL('image/png');
      },
    };
  };

  // ---- Signing a PDF contract, step by step -----------------------------------------------------------------
  // Start / Next go to each box still to do (in page order); a Sign box opens "Your signature", the first Initial box
  // "Your initials" and each Initial box is then clicked; Finish asks for the consent and submits. The boxes write
  // into the hidden inputs of #sign-form (the server checks everything again).
  const guide = (form) => {
    const pv = document.querySelector('.sign-pdf [data-pv]');
    const bar = document.querySelector('[data-guide]');
    const nextBtn = bar.querySelector('[data-guide-next]');
    const status = bar.querySelector('[data-guide-status]');
    const out = (k) => form.querySelector('[data-out="' + k + '"]');
    const modal = (id) => window.bootstrap.Modal.getOrCreateInstance(document.getElementById(id));
    const ini = (n) => n.trim().split(/\s+/).filter(Boolean).map((w) => w[0].toUpperCase()).join('').slice(0, 4);
    let started = false;
    let current = null;
    let adopted = null; // { kind: 'type'|'draw', typed, png }
    let initials = '';
    let pendingIni = null;
    let finishAfterSig = false;
    const steps = () => {
      const items = (pv.alignPdf && pv.alignPdf.items) || [];
      const at = (el) => items.find((it) => it.id === (el.closest('.pv-item') || {}).dataset?.id) || { page: 0, y: 0, x: 0 };
      return Array.from(pv.querySelectorAll('[data-step], .pv-in')).map((el) => ({ el, at: at(el) }))
        .sort((a, b) => a.at.page - b.at.page || Math.round(a.at.y / 6) - Math.round(b.at.y / 6) || a.at.x - b.at.x).map((x) => x.el);
    };
    const required = (el) => el.dataset.step === 'sign' || el.dataset.step === 'initials' || el.required;
    const done = (el) => {
      if (el.dataset.step) return el.classList.contains('done');
      return el.type === 'checkbox' ? el.checked : String(el.value).trim() !== '';
    };
    const update = () => {
      const req = steps().filter(required);
      const left = req.filter((el) => !done(el)).length;
      status.textContent = req.length ? (left ? (req.length - left) + ' of ' + req.length + ' done' : 'All done: press Finish to sign') : '';
      const label = nextBtn.querySelector('span');
      label.textContent = !left ? 'Finish' : (started ? 'Next' : 'Start');
      nextBtn.querySelector('i').className = 'fas ' + (!left ? 'fa-check' : (started ? 'fa-arrow-down' : 'fa-play')) + ' me-1';
    };
    const go = (el) => {
      pv.querySelectorAll('.pv-current').forEach((x) => x.classList.remove('pv-current'));
      current = el;
      (el.closest('.pv-item') || el).classList.add('pv-current'); // on the box's frame, so it isn't clipped
      el.scrollIntoView({ behavior: 'smooth', block: 'center' });
      if (!el.dataset.step) setTimeout(() => el.focus({ preventScroll: true }), 350);
    };
    nextBtn.addEventListener('click', () => {
      started = true;
      const all = steps();
      const todo = all.filter((el) => required(el) && !done(el));
      if (!todo.length) {
        update();
        // a PDF without a Sign box still needs the signature (the certificate records it): ask for it first
        if (!adopted && !pv.querySelector('[data-step="sign"]')) { finishAfterSig = true; openSig(); return; }
        modal('sign-finish').show();
        return;
      }
      const from = current ? all.indexOf(current) : -1;
      go(todo.find((el) => all.indexOf(el) > from) || todo[0]);
      update();
    });
    // Name and title typed on the page (copied to the form, and to the other boxes for the same thing)
    pv.addEventListener('input', (ev) => {
      const el = ev.target;
      if (el.dataset && el.dataset.role) {
        out(el.dataset.role).value = el.value;
        pv.querySelectorAll('[data-role="' + el.dataset.role + '"]').forEach((o) => { if (o !== el) o.value = el.value; });
      }
      update();
    });
    pv.addEventListener('change', update);
    // Your signature
    const sigBody = document.getElementById('adopt-sig');
    const ad = (k) => sigBody.querySelector('[data-ad="' + k + '"]');
    const ink = inkPad(ad('canvas'));
    let adKind = 'type';
    let typedTouched = false;
    sigBody.querySelectorAll('[data-ad-tab]').forEach((b) => b.addEventListener('click', () => {
      adKind = b.dataset.adTab;
      sigBody.querySelectorAll('[data-ad-tab]').forEach((x) => { x.classList.toggle('active', x === b); x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
      sigBody.querySelectorAll('[data-ad-pane]').forEach((p) => p.classList.toggle('d-none', p.dataset.adPane !== adKind));
    }));
    ad('clear').addEventListener('click', () => ink.clear());
    ad('name').addEventListener('input', () => { if (!typedTouched) ad('typed').value = ad('name').value; });
    ad('typed').addEventListener('input', () => { typedTouched = ad('typed').value !== ''; });
    const nameBox = () => pv.querySelector('[data-role="sig_name"]');
    const openSig = () => {
      const typedName = (nameBox() && nameBox().value) || out('sig_name').value;
      if (typedName && !ad('name').value) ad('name').value = typedName;
      if (!typedTouched) ad('typed').value = ad('name').value;
      ad('error').textContent = '';
      modal('adopt-sig').show();
    };
    ad('adopt').addEventListener('click', () => {
      const name = ad('name').value.trim();
      const typed = ad('typed').value.trim();
      const png = adKind === 'draw' ? ink.png() : '';
      if (!name) { ad('error').textContent = 'Please type your full name.'; return; }
      if (adKind === 'type' && !typed) { ad('error').textContent = 'Please type your signature.'; return; }
      if (adKind === 'draw' && !png) { ad('error').textContent = 'Please draw your signature in the box.'; return; }
      adopted = { kind: adKind, typed, png };
      out('sig_name').value = name;
      pv.querySelectorAll('[data-role="sig_name"]').forEach((o) => { if (!o.value) o.value = name; });
      out('sig_kind').value = adKind;
      out('sig_typed').value = adKind === 'type' ? typed : '';
      out('sig_png').value = png;
      pv.querySelectorAll('[data-step="sign"]').forEach((b) => {
        b.classList.add('done');
        b.innerHTML = png ? '<img alt="Your signature" src="' + png + '">' : '<span class="pv-script">' + esc(typed) + '</span>';
        b.title = 'Your signature (click to change it)';
      });
      modal('adopt-sig').hide();
      update();
      if (finishAfterSig) { finishAfterSig = false; modal('sign-finish').show(); }
    });
    // Your initials: adopted once, then clicked into each box
    const iniBody = document.getElementById('adopt-ini');
    const ai = (k) => iniBody.querySelector('[data-ai="' + k + '"]');
    const initialed = out('initialed');
    const apply = (b, on) => {
      b.classList.toggle('done', on);
      b.innerHTML = on ? '<span class="pv-script">' + esc(initials) + '</span>' : 'Initial';
      b.title = on ? 'Initialed (click to take it off)' : '';
      const keep = initialed.querySelector('[value="' + CSS.escape(b.dataset.place) + '"]');
      if (on && !keep) initialed.insertAdjacentHTML('beforeend', '<input type="hidden" name="initialed[]" value="' + esc(b.dataset.place) + '">');
      if (!on && keep) keep.remove();
      if (b.dataset.field) {
        let f = form.querySelector('input[type=hidden][name="' + CSS.escape(b.dataset.field) + '"]');
        if (!f) { form.insertAdjacentHTML('beforeend', '<input type="hidden" name="' + esc(b.dataset.field) + '">'); f = form.lastElementChild; }
        f.value = on ? initials : '';
      }
      update();
    };
    ai('adopt').addEventListener('click', () => {
      const v = ai('initials').value.replace(/[^\p{L}\p{N}.\- ]/gu, '').trim().slice(0, 6);
      if (!v) { ai('error').textContent = 'Please type your initials.'; return; }
      initials = v;
      out('initials').value = v;
      pv.querySelectorAll('[data-step="initials"].done').forEach((b) => apply(b, true)); // new initials everywhere they are
      if (pendingIni) apply(pendingIni, true);
      pendingIni = null;
      modal('adopt-ini').hide();
    });
    pv.addEventListener('click', (ev) => {
      const b = ev.target.closest('[data-step]');
      if (!b) return;
      started = true;
      current = b;
      if (b.dataset.step === 'sign') { openSig(); return; }
      if (!initials) {
        pendingIni = b;
        const n = (nameBox() && nameBox().value) || out('sig_name').value;
        if (!ai('initials').value && n) ai('initials').value = ini(n);
        ai('error').textContent = '';
        modal('adopt-ini').show();
        return;
      }
      apply(b, !b.classList.contains('done'));
    });
    // A sign box with a signature already: click to change it (the modal keeps what was there)
    document.getElementById('adopt-sig').addEventListener('shown.bs.modal', () => (adKind === 'draw' ? ad('canvas') : ad('typed')).focus());
    document.getElementById('adopt-ini').addEventListener('shown.bs.modal', () => ai('initials').focus());
    form.addEventListener('submit', (ev) => {
      const left = steps().filter((el) => required(el) && !done(el));
      if (left.length) { ev.preventDefault(); modal('sign-finish').hide(); go(left[0]); update(); }
    });
    // What they typed before an error comes back
    pv.addEventListener('pv:rendered', () => {
      [['sig_name', form.dataset.keptName], ['sig_title', form.dataset.keptTitle]].forEach(([role, v]) => {
        if (!v) return;
        pv.querySelectorAll('[data-role="' + role + '"]').forEach((o) => { if (!o.value) o.value = v; });
        out(role).value = v;
      });
      update();
    });
    update();
  };

  // ---- Signing page extras: initials from the name, fields left to fill ----------------------------------
  const signPage = (form) => {
    const name = form.querySelector('[data-sig-name]');
    const initials = form.querySelector('[data-initials-main]');
    const inline = document.querySelectorAll('[data-initials]');
    let touched = initials && initials.value !== '';
    const ini = (n) => n.trim().split(/\s+/).filter(Boolean).map((w) => w[0].toUpperCase()).join('').slice(0, 4);
    const fillInitials = () => {
      if (!name) return;
      const v = ini(name.value);
      if (initials && !touched) initials.value = v;
      inline.forEach((i) => { if (!i.dataset.touched) i.value = initials ? initials.value : v; });
    };
    if (initials) initials.addEventListener('input', () => { touched = true; fillInitials(); });
    inline.forEach((i) => i.addEventListener('input', () => { i.dataset.touched = '1'; }));
    if (name) { name.addEventListener('input', fillInitials); fillInitials(); }
    const left = document.querySelector('[data-cf-left]');
    const update = () => {
      if (!left) return;
      const req = Array.from(document.querySelectorAll('[data-cf][required]'));
      const n = req.filter((el) => (el.type === 'checkbox' ? !el.checked : !String(el.value).trim())).length;
      left.textContent = n ? n + (n === 1 ? ' required field left' : ' required fields left') : 'All required fields are filled in';
      left.classList.toggle('text-success', n === 0);
    };
    // The same field in two places: both boxes show what was typed in either
    document.addEventListener('input', (ev) => {
      const el = ev.target;
      if (!el.matches || !el.matches('[data-cf]')) return;
      document.querySelectorAll('[data-cf]').forEach((o) => {
        if (o === el || o.name !== el.name) return;
        if (o.type === 'checkbox') o.checked = el.checked; else o.value = el.value;
      });
    });
    document.addEventListener('input', update);
    document.addEventListener('change', update);
    update();
    const next = document.querySelector('[data-cf-next]');
    if (next) next.addEventListener('click', () => {
      const el = Array.from(document.querySelectorAll('[data-cf][required]')).find((x) => (x.type === 'checkbox' ? !x.checked : !String(x.value).trim()));
      (el || document.getElementById('sign')).scrollIntoView({ behavior: 'smooth', block: 'center' });
      if (el) setTimeout(() => el.focus(), 350);
    });
  };

  // ---- Prepare page: live preview -------------------------------------------------------------------------
  const prepare = (form) => {
    const out = document.querySelector('[data-ct-preview]');
    const state = document.querySelector('[data-ct-preview-state]');
    const refresh = debounce(async () => {
      if (state) state.textContent = 'Updating…';
      try {
        const res = await fetch(form.dataset.preview, { method: 'POST', body: new FormData(form), credentials: 'same-origin' });
        if (res.ok && (res.headers.get('Content-Type') || '').includes('text/html')) {
          const html = await res.text();
          const cur = out.querySelector('[data-pv]');
          const tmp = document.createElement('div');
          tmp.innerHTML = html;
          const next = tmp.querySelector('[data-pv]');
          if (cur && next && cur.alignPdf && cur.dataset.src === next.dataset.src) {
            // the same PDF: only the boxes change (no reloading the pages)
            cur.alignPdf.setItems(JSON.parse(next.querySelector('.pv-data').textContent).items || []);
          } else {
            out.innerHTML = html;
            const pv = out.querySelector('[data-pv]');
            if (pv && window.AlignPdf) window.AlignPdf.mount(pv);
          }
        }
        if (state) state.textContent = '';
      } catch (e) { if (state) state.textContent = 'Preview not updated'; }
    }, 500);
    form.addEventListener('input', refresh);
    form.addEventListener('change', refresh);
    form.querySelectorAll('[data-ct-use]').forEach((b) => b.addEventListener('click', () => {
      const el = document.querySelector(b.dataset.ctUse);
      if (el) { el.value = b.dataset.value; el.dispatchEvent(new Event('input', { bubbles: true })); b.remove(); }
    }));
    const extra = form.querySelector('[data-ct-extra]');
    if (extra) extra.addEventListener('input', (ev) => {
      const row = ev.target.closest('[data-ct-extra-blank]');
      if (!row || !ev.target.name.endsWith('[label]') || ev.target.value.trim() === '') return;
      row.removeAttribute('data-ct-extra-blank');
      const n = extra.querySelectorAll('[data-ct-extra-row]').length;
      const copy = row.cloneNode(true);
      copy.setAttribute('data-ct-extra-blank', '');
      copy.querySelectorAll('[name]').forEach((el) => {
        el.name = el.name.replace(/^extra\[\d+\]/, 'extra[' + n + ']');
        if (el.tagName === 'SELECT') el.value = 'month'; else el.value = el.name.endsWith('[qty]') ? '1' : '';
      });
      extra.appendChild(copy);
    });
  };

  // ---- PDF templates: boxes on the pages ------------------------------------------------------------------
  // A box shows one thing: something Align knows (the client's name, a price, a signature…) or a blank, which the
  // box itself defines: its name, kind and who fills it in. Blanks live in def.fields (so several boxes can share
  // one), but there's no separate list of them: a blank no box uses is dropped.
  const NEW_BLANK = '__new';
  const pdfBoxes = (form, def, meta, h) => {
    const pvEl = form.querySelector('[data-pv]');
    const viewer = window.AlignPdf.mount(pvEl);
    const addSel = form.querySelector('[data-pv-add]');
    const keySel = form.querySelector('[data-pv-key]');
    const ins = {
      none: form.querySelector('[data-pv-none]'), edit: form.querySelector('[data-pv-edit]'), size: form.querySelector('[data-pv-size]'),
      align: form.querySelector('[data-pv-align]'), plain: form.querySelector('[data-pv-plain]'), plainRow: form.querySelector('[data-pv-plain-row]'),
      pos: form.querySelector('[data-pv-pos]'), list: form.querySelector('[data-pv-list]'), count: form.querySelector('[data-pv-count]'),
      checks: form.querySelector('[data-pv-checks]'), hint: form.querySelector('[data-pv-hint]'),
      blank: form.querySelector('[data-pv-blank]'), also: form.querySelector('[data-b-also]'),
    };
    const b = (k) => ins.blank.querySelector('[data-b="' + k + '"]');
    def.places = def.places || [];
    let selected = null;
    let placing = null;
    let suggestions = [];
    const uid = () => 'p' + Math.random().toString(36).slice(2, 10);
    const groupOf = (k) => {
      if (meta.special[k]) return meta.special[k].side;
      if (/^svc\./.test(k) || /_total$/.test(k)) return 'services';
      if (k === 'start_date') return 'provider';
      const f = def.fields.find((x) => x.key === k);
      return f ? f.by : 'auto';
    };
    const options = (current) => {
      const opt = (k) => '<option value="' + esc(k) + '"' + (k === current ? ' selected' : '') + '>' + esc(h.labelOf(k)) + '</option>';
      const special = (side) => Object.keys(meta.special).filter((k) => meta.special[k].side === side);
      const fields = (by) => def.fields.filter((f) => f.by === by).map((f) => f.key);
      const svc = [];
      def.services.rows.forEach((r) => ['qty', 'price', 'total'].forEach((x) => svc.push('svc.' + r.key + '.' + x)));
      const totals = ['monthly_total', 'yearly_total', 'one_time_total'];
      const auto = Object.keys(meta.builtIn).filter((k) => !totals.includes(k) && k !== 'start_date');
      return '<optgroup label="The client">' + [...special('client'), ...fields('client')].map(opt).join('') + '</optgroup>'
        + '<optgroup label="You (' + esc(meta.company || 'your company') + ')">' + [...special('provider'), 'start_date', ...fields('provider')].map(opt).join('') + '</optgroup>'
        + '<optgroup label="Services and totals">' + [...svc, ...totals].map(opt).join('') + '</optgroup>'
        + '<optgroup label="Filled in by Align">' + auto.map(opt).join('') + '</optgroup>'
        + '<optgroup label="Something Align doesn’t know"><option value="' + NEW_BLANK + '">New blank…</option></optgroup>';
    };
    const blankOf = (k) => def.fields.find((f) => f.key === k) || null;
    /** A new blank (you fill it in, text): the box's settings name it. Returns its key. */
    const newBlank = () => {
      const f = { key: h.slug('blank', h.allKeys()), label: 'New blank', type: 'text', by: 'provider', required: false, options: [], default: '', help: '', fresh: true };
      def.fields.push(f);
      return f.key;
    };
    /** Blanks no box uses any more go. */
    const prune = () => { def.fields = def.fields.filter((f) => def.places.some((pl) => pl.key === f.key)); };
    const sizeFor = (k) => {
      if (k === 'sig.client' || k === 'sig.provider') return meta.sizes.sig;
      if (k === 'initials.client') return meta.sizes.initials;
      if (k === 'photo.provider') return meta.sizes.photo;
      if (/^date\.|^svc\.|_total$/.test(k)) return [90, 16];
      return meta.sizes.default;
    };
    const page = (i) => viewer.pages[i];
    const boxStyle = (el, pl) => {
      const p = page(pl.page);
      Object.assign(el.style, { left: (pl.x / p.W * 100) + '%', top: (pl.y / p.H * 100) + '%', width: (pl.w / p.W * 100) + '%', height: (pl.h / p.H * 100) + '%' });
      el.style.setProperty('--fs', pl.size);
    };
    const render = () => {
      if (!viewer.pages.length) return;
      viewer.pages.forEach((p) => { p.layer.innerHTML = ''; });
      def.places.forEach((pl) => {
        if (!page(pl.page)) return;
        const el = document.createElement('div');
        el.className = 'pv-box pv-who-' + groupOf(pl.key) + (selected === pl.id ? ' selected' : '');
        el.dataset.id = pl.id;
        el.tabIndex = 0;
        el.innerHTML = '<span class="pv-box-label pv-a-' + esc(pl.align) + '">' + esc(h.labelOf(pl.key)) + '</span><span class="pv-handle" data-handle aria-hidden="true"></span>';
        boxStyle(el, pl);
        page(pl.page).layer.appendChild(el);
      });
      suggestions.forEach((sg, i) => {
        if (!page(sg.page)) return;
        const el = document.createElement('button');
        el.type = 'button';
        el.className = 'pv-suggest';
        el.dataset.suggest = i;
        const name = sg.key ? h.labelOf(sg.key) : '';
        el.title = 'Add a box here' + (name ? ': ' + name : ' (then choose what it shows)');
        el.innerHTML = '<i class="fas fa-plus"></i>' + (name ? ' <span>' + esc(name) + '</span>' : '');
        boxStyle(el, sg);
        page(sg.page).layer.appendChild(el);
      });
      panel();
    };
    const panel = () => {
      const pl = def.places.find((x) => x.id === selected);
      ins.none.classList.toggle('d-none', !!pl);
      ins.edit.classList.toggle('d-none', !pl);
      if (pl) {
        keySel.innerHTML = options(pl.key);
        ins.size.value = String(Math.round(pl.size));
        ins.align.value = pl.align;
        ins.plain.checked = !!pl.plain;
        ins.plainRow.classList.toggle('d-none', !/^svc\.[^.]+\.(price|total)$|_total$/.test(pl.key));
        ins.pos.textContent = 'Page ' + (pl.page + 1) + ' · ' + Math.round(pl.w) + ' × ' + Math.round(pl.h) + ' pt';
        blankPanel(pl);
      }
      ins.count.textContent = def.places.length;
      const byPage = {};
      def.places.forEach((x) => { (byPage[x.page] = byPage[x.page] || []).push(x); });
      ins.list.innerHTML = Object.keys(byPage).sort((a, b) => a - b).map((pg) => '<div class="mb-1"><b>Page ' + (+pg + 1) + ':</b> '
        + byPage[pg].map((x) => '<a href="#" data-goto="' + esc(x.id) + '" class="pv-who-' + groupOf(x.key) + '-text">' + esc(h.labelOf(x.key)) + '</a>').join(', ') + '</div>').join('') || '<span class="text-muted">None yet.</span>';
      const keys = def.places.map((x) => x.key);
      const warn = [];
      if (!keys.includes('sig.client')) warn.push('No box for the <b>client’s signature</b> yet.');
      if (def.signing.countersign !== 'none' && !keys.includes('sig.provider')) warn.push('No box for <b>your signature</b> yet (or choose "Only the client signs" under Signing).');
      // A date needs room: "September 30, 2026" is about 9.4 times the text size wide in Helvetica
      const isDate = (k) => /^date\.(client|provider)$|^(start|signed)_date$/.test(k) || def.fields.some((f) => f.key === k && f.type === 'date');
      def.places.filter((x) => isDate(x.key) && x.w < x.size * 9.4 * 0.8).forEach((x) => warn.push('The <b>' + esc(h.labelOf(x.key)) + '</b> box on page ' + (x.page + 1)
        + ' is narrow for a date like “September 30, 2026”, so it will print very small. Drag its corner to make it wider, or choose a smaller text size.'));
      ins.checks.innerHTML = warn.length ? '<i class="fas fa-triangle-exclamation text-warning me-1"></i>' + warn.join('<br>') : '<i class="fas fa-circle-check text-success me-1"></i>The signatures have their boxes.';
    };
    // The selected box's blank: what it asks for (filled in from the blank unless you're typing in it)
    const blankPanel = (pl) => {
      const f = blankOf(pl.key);
      ins.blank.classList.toggle('d-none', !f);
      if (!f) return;
      const typing = ins.blank.contains(document.activeElement);
      const set = (k, v) => { const el = b(k); if (!(typing && el === document.activeElement)) { if (el.type === 'checkbox') el.checked = !!v; else el.value = v; } };
      set('label', f.label);
      set('type', f.type);
      set('by', f.type === 'initials' ? 'client' : f.by);
      set('options', (f.options || []).join('\n'));
      set('default', f.default || '');
      set('help', f.help || '');
      set('required', f.required);
      b('by').disabled = f.type === 'initials';
      ins.blank.querySelector('[data-b-row="options"]').classList.toggle('d-none', f.type !== 'choice');
      ins.blank.querySelector('[data-b-row="default"]').classList.toggle('d-none', f.by === 'client' || ['initials', 'checkbox'].includes(f.type));
      const others = def.places.filter((x) => x.key === f.key && x !== pl);
      ins.also.textContent = others.length ? 'Also in ' + others.length + ' other box' + (others.length === 1 ? '' : 'es') + ' (page '
        + [...new Set(others.map((x) => x.page + 1))].join(', ') + '): changes here apply to all of them.' : '';
    };
    const blankEdit = (ev) => {
      const el = ev.target.closest('[data-b]');
      const pl = def.places.find((x) => x.id === selected);
      const f = el && pl ? blankOf(pl.key) : null;
      if (!f) return;
      const k = el.dataset.b;
      if (k === 'required') f.required = el.checked;
      else if (k === 'options') f.options = el.value.split('\n').map((x) => x.trim()).filter(Boolean);
      else f[k] = el.value;
      if (k === 'type' && f.type === 'initials') f.by = 'client';
      // A new blank's key follows its name until it's saved (every box with it follows)
      if (k === 'label' && f.fresh && ev.type === 'change' && el.value.trim()) {
        const old = f.key;
        f.key = h.slug(el.value.trim(), h.allKeys().filter((x) => x !== old));
        def.places.forEach((x) => { if (x.key === old) x.key = f.key; });
      }
      h.changed();
    };
    ins.blank.addEventListener('input', blankEdit);
    ins.blank.addEventListener('change', blankEdit);
    const select = (id, scroll) => {
      selected = id;
      render();
      const el = id && pvEl.querySelector('.pv-box[data-id="' + id + '"]');
      if (el && scroll) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
      if (el) el.focus({ preventScroll: true });
      if (id) { const tab = document.querySelector('[data-bs-target="#ct-tab-box"]'); if (tab && window.bootstrap) window.bootstrap.Tab.getOrCreateInstance(tab).show(); }
    };
    const clamp = (pl) => {
      const p = page(pl.page);
      pl.w = Math.max(6, Math.min(pl.w, p.W));
      pl.h = Math.max(6, Math.min(pl.h, p.H));
      pl.x = Math.max(0, Math.min(pl.x, p.W - pl.w));
      pl.y = Math.max(0, Math.min(pl.y, p.H - pl.h));
      ['x', 'y', 'w', 'h'].forEach((k) => { pl[k] = Math.round(pl[k] * 10) / 10; });
    };
    const add = (key, pg, x, y, w, hh) => {
      const fresh = key === NEW_BLANK;
      if (fresh) key = newBlank();
      const [dw, dh] = sizeFor(key);
      const pl = { id: uid(), page: pg, key, x, y, w: w || dw, h: hh || dh, size: key === 'initials.client' ? 12 : 10, align: 'left', plain: false };
      clamp(pl);
      def.places.push(pl);
      h.changed();
      select(pl.id);
      if (fresh) { const n = b('label'); n.focus(); n.select(); }
      return pl;
    };
    // Placing: choose what it shows, Place it, click the page
    const startPlacing = (key) => {
      placing = key;
      pvEl.classList.add('pv-placing');
      ins.hint.innerHTML = '<b>Click on the page</b> where ' + (key === NEW_BLANK ? 'the new blank' : '"' + esc(h.labelOf(key)) + '"') + ' goes. <a href="#" data-cancel-place>Cancel</a>';
    };
    const stopPlacing = () => {
      placing = null;
      pvEl.classList.remove('pv-placing');
      ins.hint.innerHTML = 'Choose what a box shows (or <b>New blank…</b> for something Align doesn’t know, like an onsite rate), press <b>Place it</b>, then click on the page. Drag a box to move it, drag its corner to resize, and press Delete to remove it. Arrow keys nudge it.';
    };
    form.querySelector('[data-pv-place]').addEventListener('click', () => startPlacing(addSel.value));
    ins.hint.addEventListener('click', (ev) => { if (ev.target.closest('[data-cancel-place]')) { ev.preventDefault(); stopPlacing(); } });
    // Dragging and resizing
    let drag = null;
    pvEl.addEventListener('pointerdown', (ev) => {
      const sg = ev.target.closest('[data-suggest]');
      if (sg) {
        accept(+sg.dataset.suggest);
        ev.preventDefault();
        return;
      }
      const pageEl = ev.target.closest('.pv-page');
      if (!pageEl) return;
      const pg = +pageEl.dataset.page;
      if (placing) {
        const pt = viewer.toPoints(pg, ev.clientX, ev.clientY);
        const [w, hh] = sizeFor(placing);
        add(placing, pg, pt.x, pt.y - hh / 2, w, hh);
        stopPlacing();
        ev.preventDefault();
        return;
      }
      const box = ev.target.closest('.pv-box');
      if (!box) { if (selected) select(null); return; }
      const pl = def.places.find((x) => x.id === box.dataset.id);
      if (selected !== pl.id) select(pl.id);
      const b2 = pvEl.querySelector('.pv-box[data-id="' + pl.id + '"]');
      const start = viewer.toPoints(pg, ev.clientX, ev.clientY);
      drag = { pl, el: b2, pg, sx: start.x, sy: start.y, ox: pl.x, oy: pl.y, ow: pl.w, oh: pl.h, resize: !!ev.target.closest('[data-handle]'), moved: false };
      b2.setPointerCapture(ev.pointerId);
      ev.preventDefault();
    });
    pvEl.addEventListener('pointermove', (ev) => {
      if (!drag) return;
      const pt = viewer.toPoints(drag.pg, ev.clientX, ev.clientY);
      const dx = pt.x - drag.sx;
      const dy = pt.y - drag.sy;
      if (drag.resize) { drag.pl.w = drag.ow + dx; drag.pl.h = drag.oh + dy; } else { drag.pl.x = drag.ox + dx; drag.pl.y = drag.oy + dy; }
      clamp(drag.pl);
      boxStyle(drag.el, drag.pl);
      drag.moved = true;
    });
    const endDrag = () => { if (drag && drag.moved) { h.changed(); panel(); } drag = null; };
    pvEl.addEventListener('pointerup', endDrag);
    pvEl.addEventListener('pointercancel', endDrag);
    document.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape' && placing) { stopPlacing(); return; }
      const pl = def.places.find((x) => x.id === selected);
      if (!pl || (ev.target.closest && ev.target.closest('input, select, textarea, .ql-editor'))) return;
      const step = ev.shiftKey ? 10 : 1;
      const mv = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }[ev.key];
      if (mv) { pl.x += mv[0]; pl.y += mv[1]; clamp(pl); render(); h.changed(); ev.preventDefault(); select(pl.id); }
      if (ev.key === 'Delete' || ev.key === 'Backspace') { def.places = def.places.filter((x) => x !== pl); prune(); selected = null; render(); h.changed(); ev.preventDefault(); }
    });
    // The inspector
    const edit = () => {
      const pl = def.places.find((x) => x.id === selected);
      if (!pl) return;
      const fresh = keySel.value === NEW_BLANK;
      pl.key = fresh ? newBlank() : keySel.value;
      prune();
      pl.size = parseFloat(ins.size.value);
      pl.align = ins.align.value;
      pl.plain = ins.plain.checked;
      render();
      h.changed();
      if (fresh) { const n = b('label'); n.focus(); n.select(); }
    };
    [keySel, ins.size, ins.align, ins.plain].forEach((el) => el.addEventListener('change', edit));
    form.querySelector('[data-pv-delete]').addEventListener('click', () => { def.places = def.places.filter((x) => x.id !== selected); prune(); selected = null; render(); h.changed(); });
    ins.list.addEventListener('click', (ev) => { const a = ev.target.closest('[data-goto]'); if (a) { ev.preventDefault(); select(a.dataset.goto, true); } });
    // Finding the blanks: [   ], ____ lines and "$" with nothing after, with a guess from the words before them
    const guess = (left, sideRight) => {
      const t = left.toLowerCase();
      const side = sideRight ? 'client' : 'provider';
      if (/signature/.test(t)) return { key: 'sig.' + side };
      if (/initial/.test(t)) return { key: 'initials.client' };
      if (/authorized signer|signer|printed name|^\s*name|name:/.test(t)) return { key: 'name.' + side };
      if (/title/.test(t)) return { key: 'title.' + side };
      if (/date/.test(t) && !/effect|start|begin/.test(t)) return { key: 'date.' + side };
      if (/effect on|start|commence|begin/.test(t)) return { key: 'start_date' };
      if (/commitment|total/.test(t)) return { key: 'monthly_total', plain: true };
      if (/between .* and\s*$|client name|company name|\band\s*$/.test(t)) return { key: 'client_name' };
      return {};
    };
    const findBlanks = async () => {
      const found = [];
      for (let i = 0; i < viewer.pages.length; i++) {
        const W = viewer.pages[i].W;
        const items = (await viewer.text(i)).filter((t) => t.str.trim() !== '' || /_/.test(t.str));
        const lineText = (t) => items.filter((o) => Math.abs(o.y - t.y) < t.h * 0.6 && o.x < t.x).sort((a, b) => a.x - b.x).map((o) => o.str).join(' ').slice(-80);
        items.forEach((t) => {
          const perChar = t.w / Math.max(1, t.str.length);
          let m;
          const re = /_{4,}/g;
          while ((m = re.exec(t.str))) {
            const before = (lineText(t) + ' ' + t.str.slice(0, m.index)).trim();
            const x = t.x + perChar * m.index;
            found.push({ page: i, x, y: t.y - t.h * 1.25, w: perChar * m[0].length, h: t.h * 1.5, ...guess(before, x > W * 0.45) });
          }
          const ob = t.str.indexOf('[');
          if (ob >= 0) {
            const cbIn = t.str.indexOf(']', ob);
            let x0 = t.x + perChar * (ob + 1);
            let x1 = null;
            if (cbIn > ob) x1 = t.x + perChar * cbIn;
            else {
              const close = items.find((o) => o !== t && Math.abs(o.y - t.y) < t.h * 0.6 && o.x > t.x && o.str.includes(']'));
              if (close) x1 = close.x + (close.w / Math.max(1, close.str.length)) * close.str.indexOf(']');
            }
            if (x1 !== null && x1 - x0 > 12) {
              const before = (lineText(t) + ' ' + t.str.slice(0, ob)).trim();
              let g = guess(before, x0 > W * 0.45);
              // "Your company:   [          ]" over the signatures: the client's company opposite yours
              if (!g.key && x0 > W * 0.45 && /:\s*$/.test(before)) g = { key: 'client_name' };
              found.push({ page: i, x: x0 + 1, y: t.y - t.h * 1.05, w: x1 - x0 - 2, h: t.h * 1.35, ...g });
            }
          }
          if (/\$\s*$/.test(t.str)) {
            const next = items.find((o) => o !== t && Math.abs(o.y - t.y) < t.h * 0.6 && o.x > t.x + t.w && o.x < t.x + t.w + 40 && o.str.trim() !== '');
            if (!next) {
              const before = lineText(t) + ' ' + t.str;
              const g = guess(before, false);
              found.push({ page: i, x: t.x + t.w + 2, y: t.y - t.h * 1.05, w: 70, h: t.h * 1.35, plain: true, ...(g.key === 'monthly_total' ? g : {}) });
            }
          }
        });
      }
      // Not where a box already is
      return found.filter((f) => !def.places.some((pl) => pl.page === f.page && Math.abs(pl.x - f.x) < 20 && Math.abs(pl.y - f.y) < 10));
    };
    /** Turns a found blank into a box: what Align guessed, or what's chosen under "Add a box". */
    const accept = (i, quiet) => {
      const s0 = suggestions[i];
      suggestions.splice(i, 1);
      const key = s0.key || addSel.value;
      const pl = add(key, s0.page, s0.x, s0.y, s0.w, s0.h);
      pl.plain = !!s0.plain;
      if (!quiet) h.changed();
      return pl;
    };
    const allBtn = form.querySelector('[data-pv-accept-all]');
    if (allBtn) allBtn.addEventListener('click', () => {
      for (let i = suggestions.length - 1; i >= 0; i--) if (suggestions[i].key) accept(i, true);
      selected = null;
      h.changed();
      render();
      allBtn.classList.add('d-none');
    });
    const sugBtn = form.querySelector('[data-pv-suggest]');
    sugBtn.addEventListener('click', async () => {
      if (suggestions.length) { suggestions = []; render(); form.querySelector('[data-pv-suggest-label]').textContent = 'Find the blanks'; if (allBtn) allBtn.classList.add('d-none'); return; }
      sugBtn.disabled = true;
      try { suggestions = await findBlanks(); } finally { sugBtn.disabled = false; }
      form.querySelector('[data-pv-suggest-label]').textContent = suggestions.length ? 'Hide ' + suggestions.length + ' found' : 'Nothing found';
      const named = suggestions.filter((x) => x.key).length;
      if (allBtn) { allBtn.classList.toggle('d-none', !named); allBtn.querySelector('span').textContent = 'Add the ' + named + ' named'; }
      render();
      const first = pvEl.querySelector('.pv-suggest');
      if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
    h.onFields(() => { addSel.innerHTML = options(addSel.value); render(); });
    prune(); // a blank from before, with no box: nothing to show it
    addSel.innerHTML = options('sig.client');
    viewer.ready.then(render);
    window.addEventListener('resize', debounce(render, 250));
    return { place: (key) => { addSel.innerHTML = options(key); startPlacing(key); pvEl.scrollIntoView({ behavior: 'smooth', block: 'start' }); } };
  };

  // ---- Template builder -----------------------------------------------------------------------------------
  const builder = (form) => {
    const def = JSON.parse(document.getElementById('ct-def-json').textContent);
    const meta = JSON.parse(document.getElementById('ct-meta-json').textContent);
    const hidden = document.getElementById('tpl-def');
    const blocksEl = document.getElementById('ct-blocks');
    const preview = form.querySelector('[data-ct-preview]');
    const stateEl = form.querySelector('[data-ct-state]');
    const quills = new Map();
    let lastQuill = null;
    let lastRange = null;
    const uid = () => 'b' + Math.random().toString(36).slice(2, 10);

    const htmlOf = (q) => (q.getLength() <= 1 ? '' : q.getSemanticHTML().replace(/\u00a0/g, ' ').replace(/&nbsp;/g, ' '));
    const sync = () => {
      def.blocks.forEach((b) => { if (b.type === 'text' && quills.has(b.id)) b.html = htmlOf(quills.get(b.id)); });
      hidden.value = JSON.stringify(def);
    };
    const pdfMode = 'pdfBuilder' in form.dataset;
    let pdfApi = null;
    const refresh = debounce(async () => {
      sync();
      if (!form.dataset.preview || !preview) return;
      const body = new FormData();
      body.append('_csrf', csrfOf(form));
      body.append('def', hidden.value);
      try {
        const res = await fetch(form.dataset.preview, { method: 'POST', body, credentials: 'same-origin' });
        if (res.ok && (res.headers.get('Content-Type') || '').includes('text/html')) preview.innerHTML = await res.text();
      } catch (e) { /* the next change tries again */ }
    }, 600);
    const fieldHooks = [];
    const changed = () => { sync(); refresh(); fieldHooks.forEach((fn) => fn()); if (stateEl) stateEl.textContent = 'Not saved yet'; };
    const ask = (o) => (typeof alignConfirm === 'function' ? alignConfirm(o) : Promise.resolve(window.confirm(o.title))); // eslint-disable-line no-undef
    const note = (t) => { if (stateEl) { stateEl.textContent = t; stateEl.classList.add('text-danger'); setTimeout(() => stateEl.classList.remove('text-danger'), 4000); } };

    const allKeys = () => [...Object.keys(meta.builtIn), ...def.fields.map((f) => f.key)];
    const labelOf = (k) => {
      if (meta.special && meta.special[k]) return meta.special[k].label;
      const m = /^svc\.([a-z][a-z0-9_]*)\.(qty|price|total)$/.exec(k);
      if (m) {
        const r = def.services.rows.find((x) => x.key === m[1]);
        return r ? r.label + ': ' + { qty: 'quantity', price: 'price', total: 'total' }[m[2]] : 'Removed service';
      }
      return meta.builtIn[k] ? meta.builtIn[k].label : ((def.fields.find((f) => f.key === k) || {}).label || k);
    };
    const insert = (key) => {
      if (pdfApi) { pdfApi.place(key); return; }
      const q = lastQuill && quills.has(lastQuill) ? quills.get(lastQuill) : quills.values().next().value;
      if (!q) return;
      const at = lastRange ? lastRange.index : q.getLength() - 1;
      q.insertText(at, '{{' + key + '}}', 'user');
      q.setSelection(at + key.length + 4, 0, 'user');
      changed();
    };

    // Blocks
    const blockHead = (b, i) => {
      const t = { text: ['fa-paragraph', 'Text'], services: ['fa-list-ol', 'Services table'], fields: ['fa-table-list', 'Field list'], signatures: ['fa-signature', 'Signatures'], page_break: ['fa-scissors', 'Page break'] }[b.type];
      const secs = def.sections.length ? '<select class="form-select form-select-sm ct-sec-select" data-act="section" aria-label="When this block shows"><option value="">Shows: always</option>'
        + def.sections.map((s) => '<option value="' + esc(s.key) + '"' + (b.section === s.key ? ' selected' : '') + '>Shows: with ' + esc(s.label) + '</option>').join('') + '</select>' : '';
      return '<div class="ct-block-head"><span class="ct-block-type"><i class="fas ' + t[0] + ' me-1"></i>' + t[1] + '</span>' + secs
        + '<span class="ms-auto btn-group btn-group-sm">'
        + (b.type === 'text' ? '<button type="button" class="btn btn-light" data-act="split" title="Split into two blocks at the cursor (to make part of it optional)"><i class="fas fa-scissors"></i><span class="visually-hidden">Split</span></button>' : '')
        + '<button type="button" class="btn btn-light" data-act="up" title="Move up"' + (i === 0 ? ' disabled' : '') + '><i class="fas fa-arrow-up"></i><span class="visually-hidden">Move up</span></button>'
        + '<button type="button" class="btn btn-light" data-act="down" title="Move down"' + (i === def.blocks.length - 1 ? ' disabled' : '') + '><i class="fas fa-arrow-down"></i><span class="visually-hidden">Move down</span></button>'
        + '<button type="button" class="btn btn-light text-danger" data-act="remove" title="Remove"><i class="fas fa-trash"></i><span class="visually-hidden">Remove</span></button></span></div>';
    };
    const renderBlocks = () => {
      if (!blocksEl) return;
      sync();
      quills.clear();
      blocksEl.innerHTML = '';
      if (!def.blocks.length) blocksEl.innerHTML = '<p class="text-muted small mb-0">Empty. Add a text block below, then paste in your contract.</p>';
      def.blocks.forEach((b, i) => {
        const el = document.createElement('div');
        el.className = 'ct-block ct-block-' + b.type + (b.section ? ' ct-block-optional' : '');
        el.dataset.id = b.id;
        el.innerHTML = blockHead(b, i);
        const body = document.createElement('div');
        body.className = 'ct-block-body';
        if (b.type === 'text') {
          const host = document.createElement('div');
          body.appendChild(host);
          el.appendChild(body);
          blocksEl.appendChild(el);
          const q = new Quill(host, { theme: 'snow', placeholder: 'Type or paste your contract wording…', modules: { toolbar: [
            [{ header: [1, 2, 3, false] }], ['bold', 'italic', 'underline'], [{ list: 'ordered' }, { list: 'bullet' }], [{ indent: '-1' }, { indent: '+1' }, { align: [] }], ['link', 'clean'],
          ], history: { delay: 1000, maxStack: 200, userOnly: true } } });
          q.setContents(q.clipboard.convert({ html: b.html || '' }), 'silent');
          q.history.clear();
          q.on('text-change', () => { const r = q.getSelection(); if (r) { lastQuill = b.id; lastRange = r; } changed(); });
          q.on('selection-change', (r) => { if (r) { lastQuill = b.id; lastRange = r; } });
          quills.set(b.id, q);
          return;
        }
        if (b.type === 'fields') {
          body.innerHTML = '<input class="form-control form-control-sm mb-2" data-act="title" maxlength="120" placeholder="Heading (e.g. Billing details)" value="' + esc(b.title || '') + '">'
            + '<div class="ct-field-picks">' + allKeys().map((k) => '<label class="form-check form-check-inline small"><input class="form-check-input" type="checkbox" data-act="key" value="' + esc(k) + '"'
              + ((b.keys || []).includes(k) ? ' checked' : '') + '> ' + esc(labelOf(k)) + '</label>').join('') + '</div>';
        } else if (b.type === 'services') {
          body.innerHTML = '<div class="small text-muted">The services table with totals. Change its rows and prices in the <b>Services</b> tab.</div>';
        } else if (b.type === 'signatures') {
          body.innerHTML = '<div class="small text-muted">Signature boxes for the client' + (def.signing.countersign === 'none' ? '' : ' and you') + ', with names, titles and dates. Set who signs in <b>Look &amp; signing</b>.</div>';
        } else {
          body.innerHTML = '<div class="small text-muted text-center">The next block starts on a new page.</div>';
        }
        el.appendChild(body);
        blocksEl.appendChild(el);
      });
    };
    if (blocksEl) blocksEl.addEventListener('click', async (ev) => {
      const btn = ev.target.closest('[data-act]');
      if (!btn || btn.tagName !== 'BUTTON') return;
      const id = btn.closest('.ct-block').dataset.id;
      const i = def.blocks.findIndex((b) => b.id === id);
      sync();
      const act = btn.dataset.act;
      if (act === 'up' && i > 0) [def.blocks[i - 1], def.blocks[i]] = [def.blocks[i], def.blocks[i - 1]];
      if (act === 'down' && i < def.blocks.length - 1) [def.blocks[i + 1], def.blocks[i]] = [def.blocks[i], def.blocks[i + 1]];
      if (act === 'remove') {
        const b = def.blocks[i];
        if (b.type === 'text' && (b.html || '').replace(/<[^>]+>/g, '').trim() !== ''
          && !(await ask({ title: 'Remove this text block?', text: 'Its wording is removed too.', ok: 'Remove', danger: true }))) return;
        def.blocks.splice(def.blocks.indexOf(b), 1);
      }
      if (act === 'split') {
        const q = quills.get(id);
        const r = lastQuill === id && lastRange ? lastRange.index : null;
        if (!q || r === null || r <= 0 || r >= q.getLength() - 1) { note('Click in the text where the new block should start, then split.'); return; }
        const tail = q.getContents(r);
        const tmp = document.createElement('div');
        const tq = new Quill(tmp);
        tq.setContents(tail);
        const tailHtml = htmlOf(tq);
        q.deleteText(r, q.getLength() - r, 'silent');
        def.blocks[i].html = htmlOf(q);
        def.blocks.splice(i + 1, 0, { id: uid(), type: 'text', section: def.blocks[i].section, html: tailHtml });
      }
      renderBlocks();
      changed();
    });
    if (blocksEl) blocksEl.addEventListener('change', (ev) => {
      const el = ev.target.closest('[data-act]');
      if (!el) return;
      const b = def.blocks.find((x) => x.id === el.closest('.ct-block').dataset.id);
      if (el.dataset.act === 'section') { b.section = el.value; el.closest('.ct-block').classList.toggle('ct-block-optional', !!el.value); }
      if (el.dataset.act === 'key') b.keys = Array.from(el.closest('.ct-field-picks').querySelectorAll('input:checked')).map((x) => x.value);
      changed();
    });
    if (blocksEl) blocksEl.addEventListener('input', (ev) => {
      const el = ev.target.closest('[data-act="title"]');
      if (!el) return;
      def.blocks.find((x) => x.id === el.closest('.ct-block').dataset.id).title = el.value;
      changed();
    });
    form.querySelectorAll('[data-ct-add]').forEach((b) => b.addEventListener('click', () => {
      const type = b.dataset.ctAdd;
      if (type === 'services' && def.blocks.some((x) => x.type === 'services')) { note('A contract has one services table. Move it with the arrows.'); return; }
      sync();
      def.blocks.push({ id: uid(), type, section: '', html: '', title: '', keys: [] });
      renderBlocks();
      changed();
      blocksEl.lastElementChild.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }));

    // Fields (written templates; a PDF template's blanks are edited on their boxes)
    const fieldsEl = document.getElementById('ct-fields');
    const renderFields = () => {
      if (!fieldsEl) return;
      fieldsEl.innerHTML = def.fields.length ? '' : '<p class="small text-muted">No fields yet.</p>';
      def.fields.forEach((f, i) => {
        const el = document.createElement('div');
        el.className = 'ct-item';
        el.dataset.i = i;
        el.innerHTML = '<div class="d-flex align-items-center mb-1">'
          + '<button type="button" class="btn btn-sm btn-outline-primary me-1" data-f="insert" title="Put {{' + esc(f.key) + '}} where the cursor is"><i class="fas fa-arrow-left"></i><span class="visually-hidden">Insert</span></button>'
          + '<input class="form-control form-control-sm me-1" data-f="label" maxlength="120" value="' + esc(f.label) + '" aria-label="Field label">'
          + '<button type="button" class="btn btn-sm btn-light text-danger" data-f="remove" title="Remove"><i class="fas fa-trash"></i><span class="visually-hidden">Remove</span></button></div>'
          + '<div class="d-flex flex-wrap gap-1 align-items-center small">'
          + '<code class="me-1">{{' + esc(f.key) + '}}</code>'
          + '<select class="form-select form-select-sm w-auto" data-f="type" aria-label="Type">' + Object.entries(meta.types).map(([k, l]) => '<option value="' + k + '"' + (f.type === k ? ' selected' : '') + '>' + l + '</option>').join('') + '</select>'
          + '<select class="form-select form-select-sm w-auto" data-f="by" aria-label="Who fills it in"' + (f.type === 'initials' ? ' disabled' : '') + '><option value="provider"' + (f.by === 'provider' ? ' selected' : '') + '>You fill in</option><option value="client"' + (f.by === 'client' ? ' selected' : '') + '>Client fills in</option></select>'
          + '<label class="form-check mb-0 ms-1"><input class="form-check-input" type="checkbox" data-f="required"' + (f.required ? ' checked' : '') + '> Required</label></div>'
          + (f.type === 'choice' ? '<textarea class="form-control form-control-sm mt-1" rows="2" data-f="options" placeholder="One choice per line">' + esc((f.options || []).join('\n')) + '</textarea>' : '')
          + '<div class="d-flex gap-1 mt-1">' + (f.by === 'provider' && f.type !== 'initials' ? '<input class="form-control form-control-sm" data-f="default" maxlength="500" placeholder="Default value" value="' + esc(f.default || '') + '">' : '')
          + '<input class="form-control form-control-sm" data-f="help" maxlength="200" placeholder="Hint (optional)" value="' + esc(f.help || '') + '"></div>';
        fieldsEl.appendChild(el);
      });
    };
    if (fieldsEl) fieldsEl.addEventListener('click', async (ev) => {
      const b = ev.target.closest('button[data-f]');
      if (!b) return;
      const i = +b.closest('.ct-item').dataset.i;
      if (b.dataset.f === 'insert') { delete def.fields[i].fresh; insert(def.fields[i].key); }
      if (b.dataset.f === 'remove') {
        const f = def.fields[i];
        const k = f.key;
        const boxes = (def.places || []).filter((p) => p.key === k).length;
        const text = pdfMode ? (boxes ? 'Its ' + boxes + ' box' + (boxes === 1 ? '' : 'es') + ' on the PDF go too.' : 'It isn’t on the PDF.') : 'Any {{' + k + '}} left in the text will print empty.';
        if (!(await ask({ title: 'Remove the field "' + f.label + '"?', text, ok: 'Remove', danger: true }))) return;
        def.fields.splice(def.fields.indexOf(f), 1);
        def.blocks.forEach((x) => { if (x.keys) x.keys = x.keys.filter((y) => y !== k); });
        if (def.places) def.places = def.places.filter((p) => p.key !== k);
        renderFields(); renderBlocks(); changed();
      }
    });
    const fieldEdit = (ev) => {
      const el = ev.target.closest('[data-f]');
      if (!el || el.tagName === 'BUTTON') return;
      const f = def.fields[+el.closest('.ct-item').dataset.i];
      const k = el.dataset.f;
      if (k === 'required') f.required = el.checked;
      else if (k === 'options') f.options = el.value.split('\n').map((x) => x.trim()).filter(Boolean);
      else f[k] = el.value;
      // A new field's placeholder name follows its label until it's used
      if (k === 'label' && f.fresh && ev.type === 'change' && el.value.trim()) {
        f.key = slug(el.value.trim(), allKeys().filter((x) => x !== f.key));
        renderFields();
      }
      if (k === 'type' && f.type === 'initials') f.by = 'client';
      if ((k === 'type' || k === 'by') && ev.type === 'change') renderFields();
      if (k === 'label' && ev.type === 'change') renderBlocks();
      changed();
    };
    if (fieldsEl) {
      fieldsEl.addEventListener('input', fieldEdit);
      fieldsEl.addEventListener('change', fieldEdit);
    }
    const addField = form.querySelector('[data-ct-add-field]');
    if (addField) addField.addEventListener('click', () => {
      def.fields.push({ key: slug('new_field', allKeys()), label: 'New field', type: 'text', by: 'provider', required: false, options: [], default: '', help: '', fresh: true });
      renderFields(); renderBlocks(); changed();
      const inp = fieldsEl.lastElementChild.querySelector('[data-f="label"]');
      inp.focus(); inp.select();
    });
    const builtins = document.getElementById('ct-builtins');
    if (builtins) builtins.innerHTML = Object.entries(meta.builtIn).map(([k, b]) => '<div class="d-flex align-items-center small mb-1"><button type="button" class="btn btn-xs btn-outline-secondary me-2" data-insert-key="' + esc(k) + '" title="Insert"><i class="fas fa-arrow-left"></i><span class="visually-hidden">Insert</span></button>'
      + '<span class="me-auto"><b>' + esc(b.label) + '</b> <span class="text-muted">' + esc(b.help) + '</span></span><code>{{' + esc(k) + '}}</code></div>').join('');
    if (builtins) builtins.addEventListener('click', (ev) => { const b = ev.target.closest('[data-insert-key]'); if (b) insert(b.dataset.insertKey); });

    // Services
    const svcEl = document.getElementById('ct-services');
    const svcTitle = form.querySelector('[data-ct-svc-title]');
    svcTitle.value = def.services.title || '';
    svcTitle.addEventListener('input', () => { def.services.title = svcTitle.value; changed(); });
    const renderServices = () => {
      svcEl.innerHTML = def.services.rows.length ? '' : '<p class="small text-muted">No services yet.</p>';
      def.services.rows.forEach((r, i) => {
        const el = document.createElement('div');
        el.className = 'ct-item';
        el.dataset.i = i;
        el.innerHTML = '<div class="d-flex mb-1"><input class="form-control form-control-sm fw-semibold me-1" data-s="label" maxlength="120" value="' + esc(r.label) + '" aria-label="Service">'
          + '<span class="btn-group btn-group-sm"><button type="button" class="btn btn-light" data-s="up"' + (i === 0 ? ' disabled' : '') + ' title="Move up"><i class="fas fa-arrow-up"></i></button><button type="button" class="btn btn-light" data-s="down"' + (i === def.services.rows.length - 1 ? ' disabled' : '') + ' title="Move down"><i class="fas fa-arrow-down"></i></button><button type="button" class="btn btn-light text-danger" data-s="remove" title="Remove"><i class="fas fa-trash"></i></button></span></div>'
          + '<input class="form-control form-control-sm mb-1" data-s="description" maxlength="300" placeholder="Description (optional)" value="' + esc(r.description) + '">'
          + '<div class="row g-1 small">'
          + '<div class="col-4"><div class="input-group input-group-sm"><span class="input-group-text">' + esc(meta.currency) + '</span><input type="number" step="any" min="0" class="form-control" data-s="price" value="' + esc(r.price) + '" aria-label="Price"></div></div>'
          + '<div class="col-4"><select class="form-select form-select-sm" data-s="period" aria-label="Billed">' + Object.entries(meta.periods).map(([k, l]) => '<option value="' + k + '"' + (r.period === k ? ' selected' : '') + '>' + l + '</option>').join('') + '</select></div>'
          + '<div class="col-4"><input class="form-control form-control-sm" data-s="unit" maxlength="40" placeholder="Unit (per user)" value="' + esc(r.unit) + '" aria-label="Unit"></div>'
          + '<div class="col-8"><select class="form-select form-select-sm" data-s="auto" aria-label="Quantity from"><option value="">Quantity: type it in</option>' + Object.entries(meta.auto).map(([k, l]) => '<option value="' + k + '"' + (r.auto === k ? ' selected' : '') + '>Count: ' + esc(l) + '</option>').join('') + '</select></div>'
          + '<div class="col-4"><input type="number" step="any" min="0" class="form-control form-control-sm" data-s="qty" value="' + esc(r.qty) + '" title="Quantity to start with (for new clients)" aria-label="Default quantity"></div>'
          + '</div><label class="form-check small mt-1 mb-0"><input class="form-check-input" type="checkbox" data-s="optional"' + (r.optional ? ' checked' : '') + '> Optional (unticked unless Align counts some)</label>';
        svcEl.appendChild(el);
      });
    };
    svcEl.addEventListener('click', async (ev) => {
      const b = ev.target.closest('button[data-s]');
      if (!b) return;
      const i = +b.closest('.ct-item').dataset.i;
      const rows = def.services.rows;
      if (b.dataset.s === 'up' && i > 0) [rows[i - 1], rows[i]] = [rows[i], rows[i - 1]];
      if (b.dataset.s === 'down' && i < rows.length - 1) [rows[i + 1], rows[i]] = [rows[i], rows[i + 1]];
      if (b.dataset.s === 'remove') {
        // its quantity, price and total boxes go with it
        const prefix = 'svc.' + rows[i].key + '.';
        const boxes = (def.places || []).filter((p) => p.key.startsWith(prefix)).length;
        if (boxes && !(await ask({ title: 'Remove "' + rows[i].label + '"?', text: 'Its ' + boxes + ' box' + (boxes === 1 ? '' : 'es') + ' on the PDF go too.', ok: 'Remove', danger: true }))) return;
        rows.splice(i, 1);
        if (def.places) def.places = def.places.filter((p) => !p.key.startsWith(prefix));
      }
      renderServices(); changed();
    });
    const svcEdit = (ev) => {
      const el = ev.target.closest('[data-s]');
      if (!el || el.tagName === 'BUTTON') return;
      const r = def.services.rows[+el.closest('.ct-item').dataset.i];
      const k = el.dataset.s;
      r[k] = k === 'optional' ? el.checked : (k === 'price' || k === 'qty' ? parseFloat(el.value || '0') : el.value);
      changed();
    };
    svcEl.addEventListener('input', svcEdit);
    svcEl.addEventListener('change', svcEdit);
    form.querySelector('[data-ct-add-row]').addEventListener('click', () => {
      def.services.rows.push({ key: slug('service', def.services.rows.map((r) => r.key)), label: 'New service', description: '', unit: '', price: 0, period: 'month', qty: 1, auto: '', optional: false });
      renderServices(); changed();
      svcEl.lastElementChild.querySelector('[data-s="label"]').select();
    });

    // Sections
    const secEl = document.getElementById('ct-sections');
    const renderSections = () => {
      if (!secEl) return;
      secEl.innerHTML = def.sections.length ? '' : '<p class="small text-muted">No optional sections.</p>';
      def.sections.forEach((s, i) => {
        const n = def.blocks.filter((b) => b.section === s.key).length;
        const el = document.createElement('div');
        el.className = 'ct-item d-flex align-items-center';
        el.dataset.i = i;
        el.innerHTML = '<input class="form-control form-control-sm me-2" data-x="label" maxlength="120" value="' + esc(s.label) + '" aria-label="Section name">'
          + '<label class="form-check form-switch small mb-0 me-2 text-nowrap"><input class="form-check-input" type="checkbox" data-x="on"' + (s.on ? ' checked' : '') + '> On by default</label>'
          + '<span class="small text-muted me-2 text-nowrap">' + n + ' block' + (n === 1 ? '' : 's') + '</span>'
          + '<button type="button" class="btn btn-sm btn-light text-danger" data-x="remove" title="Remove"><i class="fas fa-trash"></i></button>';
        secEl.appendChild(el);
      });
    };
    if (secEl) secEl.addEventListener('click', (ev) => {
      const b = ev.target.closest('button[data-x="remove"]');
      if (!b) return;
      const s = def.sections.splice(+b.closest('.ct-item').dataset.i, 1)[0];
      def.blocks.forEach((x) => { if (x.section === s.key) x.section = ''; });
      renderSections(); renderBlocks(); changed();
    });
    const secEdit = (ev) => {
      const el = ev.target.closest('[data-x]');
      if (!el || el.tagName === 'BUTTON') return;
      const s = def.sections[+el.closest('.ct-item').dataset.i];
      if (el.dataset.x === 'on') s.on = el.checked; else s.label = el.value;
      if (ev.type === 'change') renderBlocks();
      changed();
    };
    if (secEl) secEl.addEventListener('input', secEdit);
    if (secEl) secEl.addEventListener('change', secEdit);
    if (secEl) form.querySelector('[data-ct-add-section]').addEventListener('click', () => {
      def.sections.push({ key: slug('section', def.sections.map((x) => x.key)), label: 'New section', on: true });
      renderSections(); renderBlocks(); changed();
      const inp = secEl.lastElementChild.querySelector('[data-x="label"]');
      inp.focus(); inp.select();
    });
    const secTab = document.querySelector('[data-bs-target="#ct-tab-sections"]');
    if (secTab) secTab.addEventListener('shown.bs.tab', renderSections);

    // Look & signing
    form.querySelectorAll('[data-ct-style]').forEach((el) => {
      const ev = el.type === 'checkbox' || el.tagName === 'SELECT' ? 'change' : 'input';
      el.addEventListener(ev, () => {
        const k = el.dataset.ctStyle;
        def.style[k] = el.type === 'checkbox' ? el.checked : (k === 'size' ? parseInt(el.value, 10) : el.value);
        changed();
      });
    });
    const color = form.querySelector('[data-ct-color]');
    const brand = form.querySelector('[data-ct-brand]');
    const setColor = () => { def.style.color = brand.checked ? '' : color.value; changed(); };
    if (color && brand) {
      color.addEventListener('input', () => { brand.checked = false; setColor(); });
      brand.addEventListener('change', setColor);
    }
    form.querySelectorAll('[data-ct-signing]').forEach((el) => {
      const ev = el.type === 'checkbox' || el.type === 'radio' ? 'change' : 'input';
      el.addEventListener(ev, () => {
        const k = el.dataset.ctSigning;
        if (el.type === 'radio' && !el.checked) return;
        def.signing[k] = el.type === 'checkbox' ? el.checked : (k === 'link_days' ? parseInt(el.value || '30', 10) : el.value);
        if (k === 'countersign') renderBlocks();
        changed();
      });
    });

    form.addEventListener('submit', () => { sync(); form.alignSubmitting = true; });
    renderBlocks();
    renderFields();
    renderServices();
    renderSections();
    if (pdfMode && window.AlignPdf) {
      pdfApi = pdfBoxes(form, def, meta, { changed, labelOf, slug, allKeys, onFields: (fn) => { fieldHooks.push(fn); } });
    }
    sync();
    restart(form);
    refresh();
  };

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-sigpad]').forEach(sigpad);
    const sign = document.getElementById('sign-form');
    if (sign && 'guided' in sign.dataset) guide(sign);
    else if (sign) signPage(sign);
    const prep = document.querySelector('form[data-ct-prepare]');
    if (prep) prepare(prep);
    const b = document.querySelector('form[data-ct-builder]');
    if (b && ('pdfBuilder' in b.dataset || window.Quill)) builder(b); // a written contract needs Quill; a PDF one doesn't load it
    const m = document.querySelector('[data-open-on-hash]');
    if (m && location.hash === '#' + m.dataset.openOnHash && window.bootstrap) {
      window.bootstrap.Modal.getOrCreateInstance(m).show();
      history.replaceState(null, '', location.pathname + location.search);
    }
  });
})();
