document.addEventListener('submit',e=>{const b=e.submitter;if(b&&b.classList.contains('danger')&&!confirm('Diese Aktion wirklich ausführen?'))e.preventDefault();});
