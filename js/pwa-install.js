// js/pwa-install.js
// "Add … to your home screen" banner, for both installable apps: the client
// portal (portal/_pwa_head.php, defaults below) and the staff admin
// (admin/_pwa_head.php, which passes its own data-title / data-blurb /
// data-icon on the <script> tag). Shown on signed-in pages only, never once
// installed, and stays hidden for 30 days after "Not now".
//   Android / Chrome / Edge: the browser's own install prompt.
//   iPhone / iPad Safari: short "Share → Add to Home Screen" instructions
//   (Apple offers no install button that a page can trigger).
(function () {
  const opts = (document.currentScript && document.currentScript.dataset) || {};
  const TITLE = opts.title || 'Add Duque Portal to your home screen';
  const BLURB = opts.blurb || 'Open your messages and documents in one tap, like an app.';
  const ICON = opts.icon || 'images/app-icon-192.png';
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
    banner.innerHTML = `
      <img class="pwa-banner-icon" alt="">
      <div class="pwa-banner-text"><strong></strong><span></span></div>
      <div class="pwa-banner-actions">
        ${mode === 'prompt' ? '<button type="button" class="btn btn-gold btn-sm" data-pwa-install>Install</button>' : ''}
        <button type="button" class="btn btn-ghost btn-sm" data-pwa-later>Not now</button>
      </div>`;
    banner.querySelector('.pwa-banner-icon').src = ICON;
    banner.querySelector('.pwa-banner-text strong').textContent = TITLE;
    const how = banner.querySelector('.pwa-banner-text span');
    if (mode === 'ios') {
      how.innerHTML = 'Tap <span class="pwa-share"><i class="fa-solid fa-arrow-up-from-bracket"></i> Share</span>, then <strong>Add to Home Screen</strong>.';
    } else {
      how.textContent = BLURB;
    }
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
