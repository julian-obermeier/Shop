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


document.addEventListener('change',e=>{
  const master=e.target.closest('[data-select-all]');
  if(!master)return;
  document.querySelectorAll(master.getAttribute('data-select-all')).forEach(el=>{el.checked=master.checked;});
});


function formatTaskCountdown(ms){
  const total=Math.max(0,Math.floor(ms/1000));
  const days=Math.floor(total/86400);
  const hours=Math.floor((total%86400)/3600);
  const minutes=Math.floor((total%3600)/60);
  const seconds=total%60;
  const hh=String(hours).padStart(2,'0');
  const mm=String(minutes).padStart(2,'0');
  const ss=String(seconds).padStart(2,'0');
  return days>0 ? days+'T '+hh+':'+mm+':'+ss : hh+':'+mm+':'+ss;
}

function updateTaskCountdowns(){
  document.querySelectorAll('[data-countdown-target]').forEach(el=>{
    const target=Number(el.dataset.countdownTarget||0);
    if(!target)return;
    const diff=target-Date.now();
    if(diff<=0){
      el.textContent='00:00:00';
      if(el.dataset.countdownReload==='1'&&!el.dataset.reloadQueued){
        el.dataset.reloadQueued='1';
        setTimeout(()=>location.reload(),900);
      }
      return;
    }
    el.textContent=formatTaskCountdown(diff);
  });
}
updateTaskCountdowns();
setInterval(updateTaskCountdowns,1000);


// Mobile app navigation.
const mobileMenu=document.querySelector('.mobile-more-menu');
const mobileBackdrop=document.querySelector('.mobile-menu-backdrop');
const mobileMenuButton=document.querySelector('[data-mobile-menu-open]');
function setMobileMenu(open){
  if(!mobileMenu||!mobileBackdrop||!mobileMenuButton)return;
  mobileMenu.classList.toggle('open',open);
  mobileMenu.setAttribute('aria-hidden',open?'false':'true');
  mobileMenuButton.setAttribute('aria-expanded',open?'true':'false');
  mobileBackdrop.hidden=!open;
  mobileBackdrop.classList.toggle('open',open);
  document.body.classList.toggle('mobile-menu-open',open);
}
document.addEventListener('click',e=>{
  if(e.target.closest('[data-mobile-menu-open]')){
    const open=mobileMenuButton?.getAttribute('aria-expanded')!=='true';
    setMobileMenu(open);
    return;
  }
  if(e.target.closest('[data-mobile-menu-close]'))setMobileMenu(false);
});
document.addEventListener('keydown',e=>{if(e.key==='Escape')setMobileMenu(false);});

// Long page-header explanations stay available, but no longer dominate every screen.
document.querySelectorAll('.page-head > div > p').forEach(p=>{
  const text=(p.textContent||'').trim();
  if(text.length<72||p.closest('.compact-page-help'))return;
  const details=document.createElement('details');
  details.className='compact-page-help';
  const summary=document.createElement('summary');
  summary.textContent='Hinweise';
  p.parentNode.insertBefore(details,p);
  details.append(summary,p);
  details.closest('.page-head')?.classList.add('help-compacted');
});
