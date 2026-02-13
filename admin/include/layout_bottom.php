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
</body>
</html>
