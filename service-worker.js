const CACHE='private-ankauf-v1-static';
const ASSET_PATHS=['assets/app.css','assets/app.js','assets/icon.svg'];

function scopedUrl(path){
  return new URL(path,self.registration.scope).href;
}

self.addEventListener('install',event=>{
  event.waitUntil(
    caches.open(CACHE)
      .then(cache=>cache.addAll(ASSET_PATHS.map(scopedUrl)))
      .catch(()=>{})
  );
  self.skipWaiting();
});

self.addEventListener('activate',event=>{
  event.waitUntil(
    caches.keys().then(keys=>Promise.all(keys.filter(key=>key!==CACHE).map(key=>caches.delete(key))))
  );
  self.clients.claim();
});

self.addEventListener('fetch',event=>{
  if(event.request.method!=='GET') return;
  const requestUrl=new URL(event.request.url);
  const allowed=new Set(ASSET_PATHS.map(scopedUrl));
  if(requestUrl.origin!==self.location.origin || !allowed.has(requestUrl.href)) return;

  event.respondWith(
    caches.match(event.request).then(hit=>hit || fetch(event.request).then(response=>{
      const copy=response.clone();
      caches.open(CACHE).then(cache=>cache.put(event.request,copy));
      return response;
    }))
  );
});
