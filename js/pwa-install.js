// js/pwa-install.js
// "Add Duque Portal to your home screen" banner for the client portal
// (loaded by portal/_pwa_head.php). Shown on signed-in portal pages only,
// never once installed, and stays hidden for 30 days after "Not now".
//   Android / Chrome / Edge: the browser's own install prompt.
//   iPhone / iPad Safari: short "Share → Add to Home Screen" instructions
//   (Apple offers no install button that a page can trigger).
(function () {
  const HIDE_KEY = 'duquePwaBannerHiddenUntil';
  const main = document.querySelector('main.main');
  if (!main) return; // login page etc.

  const standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  if (standalone) return;
  try {
    if (Number(localStorage.getItem(HIDE_KEY) || 0) > Date.now()) return;
  } catch (e) { /* storage blocked — just show it */ }

  const ua = navigator.userAgent;
  const isIos = /iphone|ipad|ipod/i.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const isIosSafari = isIos && /safari/i.test(ua) && !/crios|fxios|edgios/i.test(ua);
  let deferredPrompt = null;

  function hide(days) {
    banner?.remove();
    try { localStorage.setItem(HIDE_KEY, String(Date.now() + days * 86400000)); } catch (e) {}
  }

  let banner = null;
  function show(mode) {
    if (banner) return;
    banner = document.createElement('div');
    banner.className = 'pwa-banner';
    banner.setAttribute('role', 'region');
    banner.setAttribute('aria-label', 'Add the portal to your home screen');
    const how = mode === 'ios'
      ? 'Tap <span class="pwa-share"><i class="fa-solid fa-arrow-up-from-bracket"></i> Share</span>, then <strong>Add to Home Screen</strong>.'
      : 'Open your messages and documents in one tap, like an app.';
    banner.innerHTML = `
      <img class="pwa-banner-icon" src="images/app-icon-192.png" alt="">
      <div class="pwa-banner-text"><strong>Add Duque Portal to your home screen</strong><span>${how}</span></div>
      <div class="pwa-banner-actions">
        ${mode === 'prompt' ? '<button type="button" class="btn btn-gold btn-sm" data-pwa-install>Install</button>' : ''}
        <button type="button" class="btn btn-ghost btn-sm" data-pwa-later>Not now</button>
      </div>`;
    main.prepend(banner);
    banner.querySelector('[data-pwa-later]').addEventListener('click', () => hide(30));
    banner.querySelector('[data-pwa-install]')?.addEventListener('click', async () => {
      if (!deferredPrompt) return;
      deferredPrompt.prompt();
      const choice = await deferredPrompt.userChoice;
      deferredPrompt = null;
      hide(choice.outcome === 'accepted' ? 3650 : 30);
    });
  }

  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault(); // show our own banner instead of the browser mini-bar
    deferredPrompt = e;
    show('prompt');
  });
  window.addEventListener('appinstalled', () => hide(3650));

  if (isIosSafari) show('ios');
})();
