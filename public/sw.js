// sw.js — 알림 클릭 처리 & Web Push 지원
// v3 — 새 SW 활성화 시 모든 페이지 강제 새로고침
const SW_VERSION = 'v12';

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.map(k => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

// PHP 페이지와 API는 항상 네트워크에서 가져오기 (캐시 무시)
self.addEventListener('fetch', e => {
  const url = e.request.url;
  if (url.includes('.php') || e.request.mode === 'navigate') {
    e.respondWith(
      fetch(e.request, { cache: 'no-store' }).catch(() => caches.match(e.request))
    );
  }
});

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
