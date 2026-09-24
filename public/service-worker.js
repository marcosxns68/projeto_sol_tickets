const CACHE='sutoorii-tickets-v3';
const ASSETS=['/manifest.webmanifest','/icons/icon.svg'];

self.addEventListener('install',event=>{
  self.skipWaiting();
  event.waitUntil(caches.open(CACHE).then(cache=>cache.addAll(ASSETS)));
});

self.addEventListener('activate',event=>{
  event.waitUntil(Promise.all([
    caches.keys().then(keys=>Promise.all(keys.filter(key=>key!==CACHE).map(key=>caches.delete(key)))),
    self.clients.claim(),
  ]));
});

self.addEventListener('fetch',event=>{
  if(event.request.method!=='GET') return;
  const url=new URL(event.request.url);
  if(url.origin!==location.origin) return;

  event.respondWith(
    fetch(event.request).catch(()=>caches.match(event.request))
  );
});

// O evento push chega diretamente ao Service Worker quando o PWA não está
// aberto. Não depende de setInterval, sessão ativa ou aba do navegador.
self.addEventListener('push',event=>{
  event.waitUntil((async()=>{
    let payload={};
    try { payload=event.data ? event.data.json() : {}; } catch(_) {}
    const url=typeof payload.url==='string' && /^\\/tickets\\/[0-9]+$/.test(payload.url)
      ? payload.url : '/notificacoes';
    await self.registration.showNotification(
      typeof payload.title==='string' ? payload.title.slice(0,140) : 'Novidade no Sutoorii Tickets',
      {
        body: typeof payload.body==='string' ? payload.body.slice(0,220) : 'Você tem uma nova notificação.',
        icon: '/icons/icon.svg',
        badge: '/icons/icon.svg',
        tag: typeof payload.tag==='string' ? payload.tag.slice(0,120) : 'sutoorii-ticket',
        data: {url},
        renotify: true,
      }
    );
  })());
});

self.addEventListener('notificationclick',event=>{
  event.notification.close();
  const target=event.notification.data?.url;
  if(!target) return;
  event.waitUntil((async()=>{
    const url=new URL(target,self.location.origin);
    if(url.origin!==self.location.origin) return;
    const tabs=await self.clients.matchAll({type:'window',includeUncontrolled:true});
    for(const tab of tabs){
      if(tab.url.startsWith(self.location.origin) && 'focus' in tab){
        await tab.navigate(url.href);return tab.focus();
      }
    }
    return self.clients.openWindow(url.href);
  })());
});
