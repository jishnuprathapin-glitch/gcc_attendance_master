<?php
?>
  </div>
  <?php include 'include/footer.php'; ?>
</div>

<?php include 'include/js.php'; ?>

<style>
  .work-code-ac {
    position: fixed;
    z-index: 2147483000;
    background: #fff;
    border: 1px solid rgba(15, 23, 42, 0.18);
    border-radius: 10px;
    box-shadow: 0 18px 40px rgba(15, 23, 42, 0.18);
    max-height: 260px;
    overflow: auto;
    min-width: 180px;
    padding: 6px;
  }
  .work-code-ac[aria-hidden="true"] {
    display: none;
  }
  .work-code-ac__item {
    width: 100%;
    display: flex;
    gap: 10px;
    align-items: baseline;
    text-align: left;
    border: 0;
    background: transparent;
    padding: 8px 10px;
    border-radius: 8px;
    cursor: pointer;
    color: #0f172a;
  }
  .work-code-ac__item:hover,
  .work-code-ac__item.is-active {
    background: rgba(14, 165, 233, 0.12);
  }
  .work-code-ac__code {
    font-weight: 700;
    letter-spacing: 0.02em;
    min-width: 56px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
  }
  .work-code-ac__desc {
    color: rgba(15, 23, 42, 0.72);
    font-size: 0.92em;
    line-height: 1.2;
    white-space: normal;
  }
  .work-code-ac__empty {
    padding: 10px;
    color: rgba(15, 23, 42, 0.6);
    font-size: 0.92em;
  }
</style>
<script>
  (function () {
    const raw = window.WORK_CODE_OPTIONS;
    if (!raw || typeof raw !== 'object') {
      return;
    }

    const normalize = (value) => String(value || '').trim().toUpperCase();

    const list = [];
    const map = new Map();
    Object.entries(raw).forEach(([code, desc]) => {
      const cleanCode = normalize(code);
      if (!cleanCode) {
        return;
      }
      const cleanDesc = String(desc || '').trim();
      map.set(cleanCode, cleanDesc);
      list.push({
        code: cleanCode,
        desc: cleanDesc,
        search: (cleanCode + ' ' + cleanDesc).toLowerCase(),
      });
    });
    if (!list.length) {
      return;
    }

    const dropdown = document.createElement('div');
    dropdown.className = 'work-code-ac';
    dropdown.setAttribute('role', 'listbox');
    dropdown.setAttribute('aria-hidden', 'true');
    document.body.appendChild(dropdown);

    let activeInput = null;
    let activeResults = [];
    let activeIndex = -1;

    const closeDropdown = () => {
      activeResults = [];
      activeIndex = -1;
      activeInput = null;
      dropdown.innerHTML = '';
      dropdown.setAttribute('aria-hidden', 'true');
    };

    const positionDropdown = () => {
      if (!activeInput) {
        return;
      }
      const rect = activeInput.getBoundingClientRect();
      dropdown.style.left = rect.left + 'px';
      dropdown.style.top = rect.bottom + 6 + 'px';
      dropdown.style.minWidth = rect.width + 'px';
    };

    const setActiveItem = (index) => {
      const items = Array.from(dropdown.querySelectorAll('.work-code-ac__item'));
      items.forEach((el) => el.classList.remove('is-active'));
      if (index < 0 || index >= items.length) {
        return;
      }
      items[index].classList.add('is-active');
      items[index].scrollIntoView({ block: 'nearest' });
    };

    const applySelection = (input, code) => {
      if (!input) {
        return;
      }
      const cleanCode = normalize(code);
      const desc = map.get(cleanCode) || '';
      input.value = cleanCode;
      input.title = desc;
      input.setCustomValidity('');
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
      closeDropdown();
    };

    const validateInput = (input) => {
      if (!input || input.disabled || input.readOnly) {
        return true;
      }
      const cleanCode = normalize(input.value);
      if (!cleanCode) {
        input.title = '';
        input.setCustomValidity('');
        return true;
      }
      if (!map.has(cleanCode)) {
        input.setCustomValidity('Invalid work code. Choose from the list.');
        return false;
      }
      input.value = cleanCode;
      input.title = map.get(cleanCode) || '';
      input.setCustomValidity('');
      return true;
    };

    const renderResults = (query) => {
      if (!activeInput) {
        return;
      }
      const q = String(query || '').trim().toLowerCase();
      const results = [];
      for (let i = 0; i < list.length; i++) {
        const item = list[i];
        if (!q || item.search.includes(q)) {
          results.push(item);
        }
        if (results.length >= 60) {
          break;
        }
      }

      dropdown.innerHTML = '';
      if (!results.length) {
        const empty = document.createElement('div');
        empty.className = 'work-code-ac__empty';
        empty.textContent = 'No matching work codes.';
        dropdown.appendChild(empty);
        activeResults = [];
        activeIndex = -1;
        return;
      }

      results.forEach((item) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'work-code-ac__item';
        btn.setAttribute('role', 'option');
        btn.dataset.code = item.code;

        const codeEl = document.createElement('span');
        codeEl.className = 'work-code-ac__code';
        codeEl.textContent = item.code;

        const descEl = document.createElement('span');
        descEl.className = 'work-code-ac__desc';
        descEl.textContent = item.desc || '';

        btn.appendChild(codeEl);
        btn.appendChild(descEl);
        dropdown.appendChild(btn);
      });

      activeResults = results;
      activeIndex = 0;
      setActiveItem(activeIndex);
    };

    const openDropdown = (input) => {
      if (!input || input.disabled || input.readOnly) {
        return;
      }
      activeInput = input;
      positionDropdown();
      dropdown.setAttribute('aria-hidden', 'false');
      renderResults(input.value);
    };

    dropdown.addEventListener('mousedown', (e) => {
      // Prevent input blur before we apply the selection.
      e.preventDefault();
      const btn = e.target && e.target.closest ? e.target.closest('.work-code-ac__item') : null;
      if (!btn || !activeInput) {
        return;
      }
      applySelection(activeInput, btn.dataset.code || '');
      activeInput.focus();
    });

    document.addEventListener('focusin', (e) => {
      const input = e.target && e.target.classList && e.target.classList.contains('js-work-code') ? e.target : null;
      if (!input) {
        return;
      }
      openDropdown(input);
    });

    document.addEventListener('input', (e) => {
      if (!activeInput || e.target !== activeInput) {
        return;
      }
      positionDropdown();
      renderResults(activeInput.value);
    });

    document.addEventListener('keydown', (e) => {
      if (!activeInput || e.target !== activeInput) {
        return;
      }
      if (dropdown.getAttribute('aria-hidden') === 'true') {
        return;
      }
      if (e.key === 'Escape') {
        closeDropdown();
        return;
      }
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        activeIndex = Math.min(activeIndex + 1, activeResults.length - 1);
        setActiveItem(activeIndex);
        return;
      }
      if (e.key === 'ArrowUp') {
        e.preventDefault();
        activeIndex = Math.max(activeIndex - 1, 0);
        setActiveItem(activeIndex);
        return;
      }
      if (e.key === 'Enter') {
        if (activeIndex >= 0 && activeIndex < activeResults.length) {
          e.preventDefault();
          applySelection(activeInput, activeResults[activeIndex].code);
        }
      }
    });

    document.addEventListener('click', (e) => {
      if (!activeInput) {
        return;
      }
      if (e.target === activeInput) {
        return;
      }
      if (dropdown.contains(e.target)) {
        return;
      }
      closeDropdown();
    }, true);

    document.addEventListener('focusout', (e) => {
      const input = e.target && e.target.classList && e.target.classList.contains('js-work-code') ? e.target : null;
      if (!input) {
        return;
      }
      validateInput(input);
      closeDropdown();
    });

    document.addEventListener('scroll', () => {
      if (!activeInput || dropdown.getAttribute('aria-hidden') === 'true') {
        return;
      }
      positionDropdown();
    }, true);

    window.addEventListener('resize', () => {
      if (!activeInput || dropdown.getAttribute('aria-hidden') === 'true') {
        return;
      }
      positionDropdown();
    });

    document.addEventListener('submit', (e) => {
      const form = e.target;
      if (!form || typeof form.querySelectorAll !== 'function') {
        return;
      }
      const inputs = Array.from(form.querySelectorAll('input.js-work-code'));
      if (!inputs.length) {
        return;
      }
      let firstInvalid = null;
      inputs.forEach((input) => {
        if (input.disabled || input.readOnly) {
          return;
        }
        const ok = validateInput(input);
        if (!ok && !firstInvalid) {
          firstInvalid = input;
        }
      });
      if (firstInvalid) {
        e.preventDefault();
        e.stopPropagation();
        firstInvalid.focus();
        firstInvalid.reportValidity();
      }
    }, true);

    // Initial tooltip sync (do not mark invalid until the user edits/submits).
    window.addEventListener('load', () => {
      document.querySelectorAll('input.js-work-code').forEach((input) => {
        const cleanCode = normalize(input.value);
        if (cleanCode && map.has(cleanCode)) {
          input.value = cleanCode;
          input.title = map.get(cleanCode) || '';
        }
      });
    });
  })();
</script>

<style>
  :root {
    --att-popup-ink: #0f172a;
    --att-popup-shadow: 0 24px 56px rgba(15, 23, 42, 0.32), 0 8px 20px rgba(15, 23, 42, 0.2);
  }
  .att-popup-layer {
    position: fixed;
    inset: 0;
    z-index: 3400;
    display: flex;
    align-items: center;
    justify-content: center;
    pointer-events: none;
    padding: 14px;
  }
  .att-popup {
    position: relative;
    width: min(560px, calc(100vw - 28px));
    display: grid;
    grid-template-columns: 42px minmax(0, 1fr);
    gap: 12px;
    align-items: flex-start;
    border-radius: 18px;
    border: 1px solid rgba(15, 23, 42, 0.12);
    padding: 14px 16px;
    color: var(--att-popup-ink);
    box-shadow: var(--att-popup-shadow);
    backdrop-filter: blur(9px) saturate(1.05);
    opacity: 0;
    transform: translateY(14px) scale(0.97);
    transition: opacity 0.24s ease, transform 0.24s ease;
    pointer-events: auto;
    overflow: hidden;
  }
  .att-popup::after {
    content: '';
    position: absolute;
    inset: 0;
    pointer-events: none;
    background: radial-gradient(circle at top right, rgba(255, 255, 255, 0.34), transparent 58%);
  }
  .att-popup.is-visible {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
  .att-popup.is-hide {
    opacity: 0;
    transform: translateY(-10px) scale(0.98);
  }
  .att-popup__icon,
  .att-popup__content {
    position: relative;
    z-index: 1;
  }
  .att-popup__icon {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    font-weight: 800;
    line-height: 1;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.4);
  }
  .att-popup__title {
    margin: 0 0 3px;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
  }
  .att-popup__message {
    margin: 0;
    font-size: 14px;
    font-weight: 600;
    line-height: 1.42;
    word-break: break-word;
  }
  .att-popup.is-success {
    color: #14532d;
    border-color: rgba(22, 163, 74, 0.42);
    background: linear-gradient(145deg, rgba(220, 252, 231, 0.98), rgba(187, 247, 208, 0.95));
  }
  .att-popup.is-success .att-popup__icon {
    background: linear-gradient(145deg, rgba(240, 253, 244, 0.98), rgba(187, 247, 208, 0.86));
    border: 1px solid rgba(34, 197, 94, 0.35);
    color: #166534;
  }
  .att-popup.is-success .att-popup__title {
    color: #166534;
  }
  .att-popup.is-warning {
    color: #7c2d12;
    border-color: rgba(234, 88, 12, 0.45);
    background: linear-gradient(145deg, rgba(255, 247, 237, 0.98), rgba(254, 215, 170, 0.95));
  }
  .att-popup.is-warning .att-popup__icon {
    background: linear-gradient(145deg, rgba(255, 251, 235, 0.98), rgba(254, 215, 170, 0.88));
    border: 1px solid rgba(251, 146, 60, 0.4);
    color: #9a3412;
  }
  .att-popup.is-warning .att-popup__title {
    color: #9a3412;
  }
  .att-popup.is-danger {
    color: #7f1d1d;
    border-color: rgba(239, 68, 68, 0.44);
    background: linear-gradient(145deg, rgba(255, 241, 242, 0.98), rgba(254, 226, 226, 0.95));
  }
  .att-popup.is-danger .att-popup__icon {
    background: linear-gradient(145deg, rgba(254, 242, 242, 0.98), rgba(254, 202, 202, 0.9));
    border: 1px solid rgba(239, 68, 68, 0.4);
    color: #991b1b;
  }
  .att-popup.is-danger .att-popup__title {
    color: #991b1b;
  }
  .att-popup.is-info {
    color: #1e3a8a;
    border-color: rgba(37, 99, 235, 0.44);
    background: linear-gradient(145deg, rgba(239, 246, 255, 0.98), rgba(191, 219, 254, 0.95));
  }
  .att-popup.is-info .att-popup__icon {
    background: linear-gradient(145deg, rgba(239, 246, 255, 0.98), rgba(191, 219, 254, 0.88));
    border: 1px solid rgba(59, 130, 246, 0.38);
    color: #1d4ed8;
  }
  .att-popup.is-info .att-popup__title {
    color: #1d4ed8;
  }

  .campboss-toast-layer {
    z-index: 3300 !important;
    padding: 14px !important;
  }
  .campboss-toast {
    position: relative !important;
    width: min(560px, calc(100vw - 28px)) !important;
    border-radius: 18px !important;
    padding: 14px 16px 14px 64px !important;
    text-align: left !important;
    font-weight: 600 !important;
    line-height: 1.42 !important;
    letter-spacing: 0.01em;
    border: 1px solid rgba(15, 23, 42, 0.14) !important;
    box-shadow: var(--att-popup-shadow) !important;
    backdrop-filter: blur(9px) saturate(1.04);
    overflow: hidden;
  }
  .campboss-toast::before {
    content: '!';
    position: absolute;
    left: 16px;
    top: 14px;
    width: 34px;
    height: 34px;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    font-weight: 800;
    line-height: 1;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.42);
  }
  .campboss-toast.is-success {
    background: linear-gradient(145deg, rgba(220, 252, 231, 0.98), rgba(187, 247, 208, 0.95)) !important;
    border-color: rgba(22, 163, 74, 0.42) !important;
    color: #14532d !important;
  }
  .campboss-toast.is-success::before {
    content: '\2713';
    color: #166534;
    border: 1px solid rgba(34, 197, 94, 0.35);
    background: linear-gradient(145deg, rgba(240, 253, 244, 0.98), rgba(187, 247, 208, 0.86));
  }
  .campboss-toast.is-warning {
    background: linear-gradient(145deg, rgba(255, 247, 237, 0.98), rgba(254, 215, 170, 0.95)) !important;
    border-color: rgba(234, 88, 12, 0.45) !important;
    color: #7c2d12 !important;
  }
  .campboss-toast.is-warning::before {
    color: #9a3412;
    border: 1px solid rgba(251, 146, 60, 0.4);
    background: linear-gradient(145deg, rgba(255, 251, 235, 0.98), rgba(254, 215, 170, 0.88));
  }
  .campboss-toast.is-danger {
    background: linear-gradient(145deg, rgba(255, 241, 242, 0.98), rgba(254, 226, 226, 0.95)) !important;
    border-color: rgba(239, 68, 68, 0.44) !important;
    color: #7f1d1d !important;
  }
  .campboss-toast.is-danger::before {
    color: #991b1b;
    border: 1px solid rgba(239, 68, 68, 0.4);
    background: linear-gradient(145deg, rgba(254, 242, 242, 0.98), rgba(254, 202, 202, 0.9));
  }
  .campboss-toast.is-info {
    background: linear-gradient(145deg, rgba(239, 246, 255, 0.98), rgba(191, 219, 254, 0.95)) !important;
    border-color: rgba(37, 99, 235, 0.44) !important;
    color: #1e3a8a !important;
  }
  .campboss-toast.is-info::before {
    content: 'i';
    color: #1d4ed8;
    border: 1px solid rgba(59, 130, 246, 0.38);
    background: linear-gradient(145deg, rgba(239, 246, 255, 0.98), rgba(191, 219, 254, 0.88));
  }

  .no-punch-toast {
    box-shadow: var(--att-popup-shadow) !important;
    width: min(560px, calc(100vw - 28px)) !important;
    max-width: 560px !important;
  }
  .no-punch-toast.is-centered {
    max-height: calc(100vh - 24px);
    overflow: auto;
  }

  .campboss-medical-modal__backdrop {
    background:
      radial-gradient(circle at top right, rgba(248, 113, 113, 0.2), transparent 45%),
      radial-gradient(circle at top left, rgba(56, 189, 248, 0.2), transparent 45%),
      rgba(15, 23, 42, 0.68) !important;
    backdrop-filter: blur(5px);
  }
  .campboss-medical-modal__dialog {
    border-radius: 20px !important;
    border: 1px solid rgba(148, 163, 184, 0.36) !important;
    background: linear-gradient(145deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.95)) !important;
    box-shadow: 0 26px 58px rgba(15, 23, 42, 0.34) !important;
  }

  .export-overlay {
    background:
      radial-gradient(circle at top, rgba(56, 189, 248, 0.24), transparent 44%),
      radial-gradient(circle at bottom, rgba(251, 146, 60, 0.22), transparent 44%),
      rgba(8, 12, 20, 0.78) !important;
    backdrop-filter: blur(8px) saturate(1.08);
  }
  .export-modal {
    border-radius: 24px !important;
    border: 1px solid rgba(148, 163, 184, 0.28) !important;
    background: linear-gradient(145deg, #0f172a, #132340 55%, #0c4a6e) !important;
    box-shadow: 0 28px 64px rgba(0, 0, 0, 0.5) !important;
  }
  .export-title {
    font-size: 0.78rem !important;
    font-weight: 800 !important;
    letter-spacing: 0.1em !important;
    text-transform: uppercase;
    color: #93c5fd;
  }
  .export-status {
    font-size: 0.94rem !important;
    font-weight: 600;
    color: #e2e8f0 !important;
  }
  .export-ring {
    box-shadow: 0 0 28px rgba(14, 165, 233, 0.42) !important;
  }
  .export-ring-inner {
    border: 1px solid rgba(148, 163, 184, 0.24);
    background: rgba(11, 18, 32, 0.96) !important;
  }

  @media (max-width: 576px) {
    .att-popup {
      grid-template-columns: 36px minmax(0, 1fr);
      gap: 10px;
      border-radius: 14px;
      padding: 12px 12px;
    }
    .att-popup__icon {
      width: 36px;
      height: 36px;
      border-radius: 10px;
      font-size: 17px;
    }
    .att-popup__title {
      font-size: 10px;
    }
    .att-popup__message {
      font-size: 13px;
    }
    .campboss-toast {
      border-radius: 14px !important;
      padding: 12px 12px 12px 52px !important;
      font-size: 13px !important;
    }
    .campboss-toast::before {
      left: 12px;
      top: 12px;
      width: 30px;
      height: 30px;
      font-size: 17px;
      border-radius: 9px;
    }
  }
</style>

<script>
  (function () {
    if (window.AttendancePopup && typeof window.AttendancePopup.show === 'function') {
      return;
    }

    function normalizeType(type) {
      var value = String(type || '').trim().toLowerCase();
      if (value === 'error' || value === 'danger') return 'danger';
      if (value === 'warn') return 'warning';
      if (value === 'success' || value === 'warning' || value === 'info') return value;
      return 'info';
    }

    function getMeta(type) {
      if (type === 'success') {
        return { title: 'Success', icon: '\u2713' };
      }
      if (type === 'warning') {
        return { title: 'Action Required', icon: '!' };
      }
      if (type === 'danger') {
        return { title: 'Submission Blocked', icon: '!' };
      }
      return { title: 'Notice', icon: 'i' };
    }

    function ensureLayer() {
      var layer = document.getElementById('attPopupLayer');
      if (layer) {
        return layer;
      }
      layer = document.createElement('div');
      layer.id = 'attPopupLayer';
      layer.className = 'att-popup-layer';
      layer.setAttribute('aria-live', 'polite');
      layer.setAttribute('aria-atomic', 'true');
      document.body.appendChild(layer);
      return layer;
    }

    function buildPopup(message, title, type, iconText) {
      var popup = document.createElement('div');
      popup.className = 'att-popup is-' + type;
      popup.setAttribute('role', type === 'danger' || type === 'warning' ? 'alert' : 'status');

      var icon = document.createElement('span');
      icon.className = 'att-popup__icon';
      icon.setAttribute('aria-hidden', 'true');
      icon.textContent = iconText;

      var content = document.createElement('div');
      content.className = 'att-popup__content';

      var heading = document.createElement('div');
      heading.className = 'att-popup__title';
      heading.textContent = title;

      var body = document.createElement('div');
      body.className = 'att-popup__message';
      body.textContent = message;

      content.appendChild(heading);
      content.appendChild(body);
      popup.appendChild(icon);
      popup.appendChild(content);

      return popup;
    }

    function show(options, type, durationMs) {
      var opts = {};
      if (typeof options === 'object' && options !== null) {
        opts = options;
      } else {
        opts.message = options;
        opts.type = type;
        opts.durationMs = durationMs;
      }

      var message = String(opts.message || '').trim();
      if (message === '') {
        return null;
      }

      var resolvedType = normalizeType(opts.type);
      var meta = getMeta(resolvedType);
      var title = String(opts.title || '').trim();
      if (title === '') {
        title = meta.title;
      }

      var dismissMs = Number(opts.durationMs);
      if (!Number.isFinite(dismissMs) || dismissMs <= 0) {
        dismissMs = resolvedType === 'success' ? 2200 : 3400;
      }

      var layer = ensureLayer();
      var popup = buildPopup(message, title, resolvedType, meta.icon);
      layer.appendChild(popup);

      var closed = false;
      function closePopup() {
        if (closed) return;
        closed = true;
        popup.classList.remove('is-visible');
        popup.classList.add('is-hide');
        window.setTimeout(function () {
          if (popup.parentNode) {
            popup.parentNode.removeChild(popup);
          }
        }, 240);
      }

      popup.addEventListener('click', closePopup);
      requestAnimationFrame(function () {
        popup.classList.add('is-visible');
      });

      window.setTimeout(closePopup, dismissMs);

      return {
        close: closePopup,
        element: popup
      };
    }

    function notify(message, type, durationMs) {
      return show({
        message: message,
        type: type,
        durationMs: durationMs
      });
    }

    function clear() {
      var layer = document.getElementById('attPopupLayer');
      if (!layer) {
        return;
      }
      while (layer.firstChild) {
        layer.removeChild(layer.firstChild);
      }
    }

    window.AttendancePopup = {
      show: show,
      notify: notify,
      clear: clear
    };
  })();
</script>
</body>
</html>
