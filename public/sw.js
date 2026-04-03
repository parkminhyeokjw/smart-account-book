// sw.js — 알림 클릭 처리 & Web Push 지원
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));

self.addEventListener('push', e => {
  let title = '마이가계부 📒';
  let body  = '오늘도 가계부 작성하셔야죠! ✏️';
  try {
    const d = e.data ? e.data.json() : {};
    if (d.title) title = d.title;
    if (d.body)  body  = d.body;
  } catch (_) {}
  e.waitUntil(
    self.registration.showNotification(title, {
      body,
      icon: 'icon-192.png',
    })
  );
});

self.addEventListener('notificationclick', e => {
  e.notification.close();
  e.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
      for (const c of list) {
        if (c.url.includes('/public/') && 'focus' in c) return c.focus();
      }
      return self.clients.openWindow('./');
    })
  );
});
