// admin/sw.js — service worker for the installable staff portal.
// Deliberately caches NOTHING: staff pages hold every client's private files,
// so every request always goes to the server, exactly as in a browser tab.
// It exists so phones offer "Install app" / "Add to Home Screen", and so a
// phone with no signal shows a friendly message instead of a browser error.

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('fetch', (event) => {
  if (event.request.mode !== 'navigate') return; // files, API calls: browser default
  event.respondWith(
    fetch(event.request).catch(() => new Response(
      '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">' +
      '<title>No connection · Duque Immigration</title></head>' +
      '<body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:-apple-system,Segoe UI,Arial,sans-serif;background:#F7F7F5;color:#26251F;text-align:center;padding:24px">' +
      '<div><div style="font-size:40px">📶</div><h1 style="font-size:20px;margin:10px 0 6px">You\'re offline</h1>' +
      '<p style="color:#77756F;margin:0 0 18px">The Duque Immigration staff portal needs an internet connection.</p>' +
      '<button onclick="location.reload()" style="border:0;border-radius:10px;background:#DD0000;color:#fff;font-size:15px;font-weight:600;padding:12px 22px">Try again</button></div></body></html>',
      { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
    ))
  );
});
