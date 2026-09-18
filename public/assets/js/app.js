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


document.addEventListener('DOMContentLoaded', () => {
  const proofEditor=document.querySelector('[data-proof-window-editor]');
  if(!proofEditor) return;
  const holder=proofEditor.querySelector('[data-proof-windows]');
  let index=holder?.children.length || 0;

  proofEditor.querySelector('[data-add-proof-window]')?.addEventListener('click',()=>{
    const row=document.createElement('div');
    row.className='option-editor-row';
    row.innerHTML=`
      <label>Interner Schlüssel<input name="proof_windows[${index}][key]" placeholder="z. B. morgens"></label>
      <label>Bezeichnung<input name="proof_windows[${index}][label]" required></label>
      <label>Von<input type="time" name="proof_windows[${index}][start]" value="08:00" required></label>
      <label>Bis<input type="time" name="proof_windows[${index}][end]" value="10:00" required></label>
      <label>Bilder<input type="number" min="1" max="20" name="proof_windows[${index}][required_images]" value="1" required></label>
      <label class="check"><input type="checkbox" name="proof_windows[${index}][text_required]" value="1"><span>Text Pflicht</span></label>
      <label class="check"><input type="checkbox" name="proof_windows[${index}][face_required]" value="1"><span>Gesicht Pflicht</span></label>
      <button type="button" class="icon-btn" data-remove-proof-window>×</button>`;
    holder.appendChild(row);
    index++;
  });

  proofEditor.addEventListener('click',event=>{
    if(event.target.matches('[data-remove-proof-window]')){
      const rows=holder.querySelectorAll('.option-editor-row');
      if(rows.length>1) event.target.closest('.option-editor-row')?.remove();
    }
  });
});


document.addEventListener('submit', async event => {
  const form=event.target.closest?.('[data-proof-upload]');
  if(!form || form.dataset.overlayProcessed==='1') return;

  const toggle=form.querySelector('[data-overlay-code]');
  const input=form.querySelector('[data-proof-file]');
  if(!toggle?.checked || !input?.files?.[0]) return;

  event.preventDefault();

  const file=input.files[0];
  const code=form.dataset.code || '';
  if(!code){
    form.submit();
    return;
  }

  try{
    const bitmap=await createImageBitmap(file);
    const canvas=document.createElement('canvas');
    canvas.width=bitmap.width;
    canvas.height=bitmap.height;
    const ctx=canvas.getContext('2d');
    ctx.drawImage(bitmap,0,0);

    const fontSize=Math.max(28,Math.round(canvas.width*0.055));
    const padding=Math.max(18,Math.round(fontSize*0.5));
    ctx.font=`700 ${fontSize}px sans-serif`;
    const label=`Wear&Earn · Code ${code}`;
    const textWidth=ctx.measureText(label).width;
    const boxWidth=Math.min(canvas.width,textWidth+padding*2);
    const boxHeight=fontSize+padding*2;
    const x=Math.max(0,canvas.width-boxWidth);
    const y=Math.max(0,canvas.height-boxHeight);

    ctx.fillStyle='rgba(0,0,0,.72)';
    ctx.fillRect(x,y,boxWidth,boxHeight);
    ctx.fillStyle='#fff';
    ctx.textBaseline='middle';
    ctx.fillText(label,x+padding,y+boxHeight/2,boxWidth-padding*2);

    const blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/jpeg',0.94));
    if(!blob) throw new Error('overlay conversion failed');

    const replacement=new File([blob],file.name.replace(/\.[^.]+$/, '')+'-code.jpg',{type:'image/jpeg',lastModified:Date.now()});
    const transfer=new DataTransfer();
    transfer.items.add(replacement);
    input.files=transfer.files;
    form.dataset.overlayProcessed='1';
    form.requestSubmit();
  }catch(error){
    form.dataset.overlayProcessed='1';
    form.requestSubmit();
  }
});


document.addEventListener('DOMContentLoaded',async()=>{
  const manager=document.querySelector('[data-push-manager]');
  if(!manager) return;

  const button=manager.querySelector('[data-push-toggle]');
  const status=manager.querySelector('[data-push-status]');
  const publicKey=manager.dataset.publicKey;
  const csrf=document.querySelector('meta[name="csrf-token"]')?.content || '';

  const toUint8=value=>{
    const padding='='.repeat((4-value.length%4)%4);
    const base64=(value+padding).replace(/-/g,'+').replace(/_/g,'/');
    const raw=atob(base64);
    return Uint8Array.from([...raw].map(ch=>ch.charCodeAt(0)));
  };

  if(!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)){
    button.disabled=true;
    button.textContent='Push nicht unterstützt';
    status.textContent='Dieser Browser bzw. diese Installationsart unterstützt Browser-Push nicht.';
    return;
  }

  try{
    const registration=await navigator.serviceWorker.register('/service-worker.js');
    let subscription=await registration.pushManager.getSubscription();

    const render=()=>{
      button.textContent=subscription?'Push auf diesem Gerät deaktivieren':'Push auf diesem Gerät aktivieren';
      status.textContent=subscription?'Push ist auf diesem Gerät aktiviert.':'Push ist auf diesem Gerät nicht aktiviert.';
    };
    render();

    button.addEventListener('click',async()=>{
      button.disabled=true;
      try{
        if(subscription){
          const endpoint=subscription.endpoint;
          await fetch(manager.dataset.destroyUrl,{
            method:'DELETE',
            headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf,'Accept':'application/json'},
            body:JSON.stringify({endpoint}),
          });
          await subscription.unsubscribe();
          subscription=null;
        }else{
          const permission=await Notification.requestPermission();
          if(permission!=='granted') throw new Error('Die Benachrichtigungsberechtigung wurde nicht erteilt.');

          subscription=await registration.pushManager.subscribe({
            userVisibleOnly:true,
            applicationServerKey:toUint8(publicKey),
          });

          const payload=subscription.toJSON();
          payload.contentEncoding=(PushManager.supportedContentEncodings||[])[0]||'aes128gcm';

          const response=await fetch(manager.dataset.storeUrl,{
            method:'POST',
            headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf,'Accept':'application/json'},
            body:JSON.stringify(payload),
          });
          if(!response.ok) throw new Error('Die Push-Registrierung konnte nicht gespeichert werden.');
        }
        render();
      }catch(error){
        status.textContent=error?.message || 'Push konnte nicht geändert werden.';
      }finally{
        button.disabled=false;
      }
    });
  }catch(error){
    button.disabled=true;
    button.textContent='Push nicht verfügbar';
    status.textContent=error?.message || 'Der Service Worker konnte nicht eingerichtet werden.';
  }
});


document.addEventListener('DOMContentLoaded',()=>{
  document.querySelectorAll('input[data-live-camera]').forEach(input=>{
    input.style.display='none';

    const wrapper=document.createElement('div');
    wrapper.className='live-camera';
    wrapper.innerHTML=`
      <div class="notice" data-camera-status>Für diesen Nachweis ist eine Live-Aufnahme erforderlich. Eine Galerie- oder Dateiauswahl wird nicht angeboten.</div>
      <button type="button" class="btn secondary" data-camera-start>Kamera öffnen</button>
      <div data-camera-stage hidden style="margin-top:10px">
        <video data-camera-video autoplay playsinline muted style="width:100%;max-height:420px;object-fit:cover;border-radius:14px;background:#111"></video>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
          <button type="button" class="btn primary" data-camera-capture>Foto aufnehmen</button>
          <button type="button" class="btn secondary" data-camera-cancel>Abbrechen</button>
        </div>
      </div>
      <img data-camera-preview alt="Aufnahmevorschau" hidden style="width:100%;max-height:420px;object-fit:contain;border-radius:14px;margin-top:10px">
      <button type="button" class="btn secondary" data-camera-retake hidden style="margin-top:8px">Neu aufnehmen</button>
    `;

    input.insertAdjacentElement('afterend',wrapper);

    const status=wrapper.querySelector('[data-camera-status]');
    const start=wrapper.querySelector('[data-camera-start]');
    const stage=wrapper.querySelector('[data-camera-stage]');
    const video=wrapper.querySelector('[data-camera-video]');
    const capture=wrapper.querySelector('[data-camera-capture]');
    const cancel=wrapper.querySelector('[data-camera-cancel]');
    const preview=wrapper.querySelector('[data-camera-preview]');
    const retake=wrapper.querySelector('[data-camera-retake]');
    let stream=null;

    const stop=()=>{
      if(stream){
        stream.getTracks().forEach(track=>track.stop());
        stream=null;
      }
      video.srcObject=null;
      stage.hidden=true;
    };

    const open=async()=>{
      if(!navigator.mediaDevices?.getUserMedia){
        status.textContent='Dieses Gerät stellt der Webanwendung keine Live-Kamera bereit. Für diesen Pflichtnachweis ist ein kompatibles Gerät mit Kamera und HTTPS erforderlich.';
        start.disabled=true;
        return;
      }

      try{
        stream=await navigator.mediaDevices.getUserMedia({
          video:{facingMode:{ideal:'environment'}},
          audio:false,
        });
        video.srcObject=stream;
        stage.hidden=false;
        start.hidden=true;
        preview.hidden=true;
        retake.hidden=true;
        status.textContent='Kamera aktiv. Achte darauf, dass der erforderliche Code sichtbar ist, sofern du ihn nicht per digitalem Overlay einblendest.';
      }catch(error){
        status.textContent='Kamerazugriff wurde nicht freigegeben oder ist technisch nicht verfügbar. Ohne Live-Kamera kann dieser Nachweis nicht eingereicht werden.';
      }
    };

    start.addEventListener('click',open);
    retake.addEventListener('click',open);

    cancel.addEventListener('click',()=>{
      stop();
      start.hidden=false;
      status.textContent='Live-Aufnahme abgebrochen.';
    });

    capture.addEventListener('click',async()=>{
      if(!stream || !video.videoWidth || !video.videoHeight) return;

      const canvas=document.createElement('canvas');
      canvas.width=video.videoWidth;
      canvas.height=video.videoHeight;
      const ctx=canvas.getContext('2d');
      ctx.drawImage(video,0,0,canvas.width,canvas.height);

      const blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/jpeg',0.95));
      if(!blob) return;

      const file=new File([blob],`live-${Date.now()}.jpg`,{type:'image/jpeg',lastModified:Date.now()});
      const transfer=new DataTransfer();
      transfer.items.add(file);
      input.files=transfer.files;

      preview.src=URL.createObjectURL(blob);
      preview.hidden=false;
      retake.hidden=false;
      start.hidden=true;
      stop();
      status.textContent='Live-Aufnahme gespeichert. Du kannst sie jetzt einreichen oder neu aufnehmen.';
    });

    input.form?.addEventListener('reset',()=>{
      stop();
      input.value='';
      preview.hidden=true;
      retake.hidden=true;
      start.hidden=false;
    });

    window.addEventListener('pagehide',stop,{once:true});
  });
});
