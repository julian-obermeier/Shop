const CACHE='private-ankauf-v1';
const STATIC=['/','/assets/app.css','/assets/app.js','/manifest.webmanifest','/assets/icon.svg'];
self.addEventListener('install',event=>{event.waitUntil(caches.open(CACHE).then(cache=>cache.addAll(STATIC)).catch(()=>{}));self.skipWaiting();});
self.addEventListener('activate',event=>{event.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(k=>k!==CACHE).map(k=>caches.delete(k)))));self.clients.claim();});
self.addEventListener('fetch',event=>{
  const req=event.request;
  if(req.method!=='GET') return;
  const url=new URL(req.url);
  if(url.origin!==location.origin) return;
  if(url.pathname.startsWith('/datei/')||url.pathname.startsWith('/auftrag/')||url.pathname.startsWith('/admin/')||url.pathname.startsWith('/wallet')||url.pathname.startsWith('/profil')) return;
  event.respondWith(fetch(req).then(res=>{const clone=res.clone();caches.open(CACHE).then(c=>c.put(req,clone)).catch(()=>{});return res;}).catch(()=>caches.match(req)));
});