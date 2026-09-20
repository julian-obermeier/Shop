const CACHE='private-ankauf-v1-static';
const ASSETS=['/assets/app.css','/assets/app.js','/assets/icon.svg'];
self.addEventListener('install',event=>{event.waitUntil(caches.open(CACHE).then(c=>c.addAll(ASSETS)).catch(()=>{}));self.skipWaiting();});
self.addEventListener('activate',event=>{event.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(k=>k!==CACHE).map(k=>caches.delete(k)))));self.clients.claim();});
self.addEventListener('fetch',event=>{if(event.request.method!=='GET')return;const u=new URL(event.request.url);if(u.origin!==location.origin||!ASSETS.includes(u.pathname))return;event.respondWith(caches.match(event.request).then(r=>r||fetch(event.request)));});
