self.addEventListener('push',event=>{
  let payload={title:'Wear&Earn',body:'Neue Benachrichtigung',url:'/benachrichtigungen'};
  try{
    if(event.data) payload={...payload,...event.data.json()};
  }catch(e){
    if(event.data) payload.body=event.data.text();
  }

  event.waitUntil(
    self.registration.showNotification(payload.title||'Wear&Earn',{
      body:payload.body||'',
      data:{url:payload.url||'/benachrichtigungen'},
      icon:'/favicon.ico',
      badge:'/favicon.ico',
      tag:(payload.data&&payload.data.notification_id)?String(payload.data.notification_id):undefined,
    })
  );
});

self.addEventListener('notificationclick',event=>{
  event.notification.close();
  const url=event.notification.data?.url || '/benachrichtigungen';
  event.waitUntil(
    clients.matchAll({type:'window',includeUncontrolled:true}).then(list=>{
      for(const client of list){
        if('focus' in client){
          client.navigate(url);
          return client.focus();
        }
      }
      return clients.openWindow(url);
    })
  );
});
