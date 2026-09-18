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
      if (total) total.textContent = amount.toLocaleString('de-DE', {style:'currency', currency:'EUR'});
    };

    configurator.addEventListener('change', event => {
      if (event.target.matches('input[data-price]')) enforce(event.target);
    });
    enforce();
  }

  const editor = document.querySelector('[data-option-editor]');
  if (editor) {
    const holder = editor.querySelector('[data-options]');
    let index = holder?.children.length || 0;

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
        <label>Benötigt Optionen<input name="options[${index}][requires_names]"></label>
        <label>Schließt Optionen aus<input name="options[${index}][excludes_names]"></label>
        <label class="check"><input type="checkbox" name="options[${index}][required]" value="1"><span>Pflicht</span></label>
        <label class="wide" style="grid-column:1/-2">Beschreibung<input name="options[${index}][description]"></label>
        <button type="button" class="icon-btn" data-remove-option>×</button>`;
      holder.appendChild(row);
      index++;
    });

    editor.addEventListener('click', event => {
      if (event.target.matches('[data-remove-option]')) {
        event.target.closest('.option-editor-row')?.remove();
      }
    });
  }

  const fieldEditor = document.querySelector('[data-field-editor]');
  if (fieldEditor) {
    const holder = fieldEditor.querySelector('[data-fields]');
    let index = holder?.children.length || 0;

    fieldEditor.querySelector('[data-add-field]')?.addEventListener('click', () => {
      const row=document.createElement('div');
      row.className='option-editor-row';
      row.innerHTML=`
        <label>Bezeichnung<input name="fields[${index}][label]"></label>
        <label>Typ<select name="fields[${index}][type]">
          <option value="text">Text</option><option value="textarea">Textbereich</option>
          <option value="number">Zahl</option><option value="select">Auswahl</option>
          <option value="radio">Radio</option><option value="checkbox">Ja/Nein</option>
        </select></label>
        <label class="wide">Hilfetext<input name="fields[${index}][help_text]"></label>
        <label class="wide">Auswahlwerte – eine pro Zeile<textarea name="fields[${index}][options_text]" rows="3"></textarea></label>
        <label class="check"><input type="checkbox" name="fields[${index}][required]" value="1"><span>Pflichtfeld</span></label>
        <button type="button" class="icon-btn" data-remove-field>×</button>`;
      holder.appendChild(row);
      index++;
    });

    fieldEditor.addEventListener('click', event => {
      if(event.target.matches('[data-remove-field]')){
        event.target.closest('.option-editor-row')?.remove();
      }
    });
  }
});
