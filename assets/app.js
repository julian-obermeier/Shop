document.addEventListener('submit',e=>{
  const b=e.submitter;
  if(b&&b.classList.contains('danger')&&!confirm('Diese Aktion wirklich ausführen?'))e.preventDefault();
});

document.addEventListener('click',async e=>{
  const b=e.target.closest('[data-copy-target]');
  if(!b)return;
  const el=document.querySelector(b.getAttribute('data-copy-target'));
  if(!el)return;
  const value=el.value||el.textContent||'';
  try{
    await navigator.clipboard.writeText(value);
    const old=b.textContent;
    b.textContent='Kopiert ✓';
    setTimeout(()=>b.textContent=old,1600);
  }catch{
    if(el.select)el.select();
    document.execCommand('copy');
  }
});
