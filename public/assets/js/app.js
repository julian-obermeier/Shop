document.addEventListener('DOMContentLoaded', () => {
  const configurator = document.querySelector('[data-offer-configurator]');
  if (configurator) {
    const base = Number(configurator.dataset.base || 0);
    const total = configurator.querySelector('[data-total]');
    const inputs = [...configurator.querySelectorAll('input[data-price]')];
    const byId = new Map(inputs.map(input => [String(input.value), input]));

    const parseIds = value => {
      try { return JSON.parse(value || '[]').map(String); }
      catch { return []; }
    };

    const enforce = changed => {
      if (changed?.checked) {
        parseIds(changed.dataset.requires).forEach(id => {
          const required = byId.get(id);
          if (required) required.checked = true;
        });
      }

      inputs.forEach(input => { input.disabled = false; });

      inputs.filter(input => input.checked).forEach(input => {
        parseIds(input.dataset.excludes).forEach(id => {
          const excluded = byId.get(id);
          if (excluded && !excluded.checked) excluded.disabled = true;
        });
      });

      let amount = base;
      inputs.filter(input => input.checked).forEach(input => amount += Number(input.dataset.price || 0));
      total.textContent = amount.toLocaleString('de-DE', {style:'currency', currency:'EUR'});
    };

    configurator.addEventListener('change', event => {
      if (event.target.matches('input[data-price]')) enforce(event.target);
    });
    enforce();
  }

  const editor = document.querySelector('[data-option-editor]');
  if (editor) {
    const holder = editor.querySelector('[data-options]');
    let index = holder.children.length;
    editor.querySelector('[data-add-option]')?.addEventListener('click', () => {
      const row = document.createElement('div');
      row.className='option-editor-row';
      row.style.gridTemplateColumns='1.1fr .7fr .7fr .7fr .7fr 1.1fr 1.1fr auto';
      row.innerHTML = `
        <label>Name<input name="options[${index}][name]"></label>
        <label>Aufpreis (€)<input type="number" step="0.01" name="options[${index}][price_delta]" value="0"></label>
        <label>Extra-Nachweise/Tag<input type="number" min="0" name="options[${index}][extra_proofs_per_day]" value="0"></label>
        <label>Extra-Tage<input type="number" min="0" name="options[${index}][extra_duration_days]" value="0"></label>
        <label>Mindestlaufzeit<input type="number" min="0" name="options[${index}][min_duration_days]" value="0"></label>
        <label>Benötigt Optionen<input name="options[${index}][requires_names]" placeholder="z. B. Sport"></label>
        <label>Schließt Optionen aus<input name="options[${index}][excludes_names]" placeholder="z. B. Schlafen"></label>
        <label class="check"><input type="checkbox" name="options[${index}][required]" value="1"><span>Pflicht</span></label>
        <label class="wide" style="grid-column:1/-2">Beschreibung<input name="options[${index}][description]"></label>
        <button type="button" class="icon-btn" data-remove-option>×</button>`;
      holder.appendChild(row); index++;
    });
    editor.addEventListener('click', e => {
      if (e.target.matches('[data-remove-option]')) e.target.closest('.option-editor-row')?.remove();
    });
  }
});
