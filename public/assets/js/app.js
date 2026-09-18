document.addEventListener('DOMContentLoaded', () => {
  const configurator = document.querySelector('[data-offer-configurator]');
  if (configurator) {
    const base = Number(configurator.dataset.base || 0);
    const total = configurator.querySelector('[data-total]');
    const render = () => {
      let amount = base;
      configurator.querySelectorAll('input[data-price]:checked').forEach(input => amount += Number(input.dataset.price || 0));
      total.textContent = amount.toLocaleString('de-DE', {style:'currency', currency:'EUR'});
    };
    configurator.addEventListener('change', render); render();
  }
  const editor = document.querySelector('[data-option-editor]');
  if (editor) {
    const holder = editor.querySelector('[data-options]');
    let index = holder.children.length;
    editor.querySelector('[data-add-option]')?.addEventListener('click', () => {
      const row = document.createElement('div'); row.className='option-editor-row';
      row.innerHTML = `<label>Name<input name="options[${index}][name]"></label><label>Aufpreis (€)<input type="number" step="0.01" name="options[${index}][price_delta]" value="0"></label><label>Extra-Nachweise/Tag<input type="number" min="0" name="options[${index}][extra_proofs_per_day]" value="0"></label><label>Extra-Tage<input type="number" min="0" name="options[${index}][extra_duration_days]" value="0"></label><label class="wide">Beschreibung<input name="options[${index}][description]"></label><button type="button" class="icon-btn" data-remove-option>×</button>`;
      holder.appendChild(row); index++;
    });
    editor.addEventListener('click', e => { if (e.target.matches('[data-remove-option]')) e.target.closest('.option-editor-row')?.remove(); });
  }
});
