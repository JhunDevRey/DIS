// js/pwa-install.js
// Installing the portal as an app, for both installable apps: the client
// portal (portal/_pwa_head.php, defaults below) and the staff admin
// (admin/_pwa_head.php, which passes data-name / data-title / data-blurb /
// data-icon on the <script> tag). Two ways in, sharing one install flow:
//   • "Install app" in the sidebar (#navInstallApp) — always there unless
//     the page is already running as the installed app.
//   • A one-time banner at the top of the page — hidden for 30 days after
//     "Not now", for good once installed.
// Chrome / Edge (desktop + Android) get the browser's own install dialog.
// Where a page can't trigger that (iPhone/iPad, Mac Safari, or Chrome when
// it isn't offering it right now), the button explains the steps instead.
(function () {
  const opts = (document.currentScript && document.currentScript.dataset) || {};
  const NAME = opts.name || 'Duque Portal';
  const TITLE = opts.title || 'Add Duque Portal to your home screen';
  const BLURB = opts.blurb || 'Open your messages and documents in one tap, like an app.';
  const ICON = opts.icon || 'images/app-icon-round-192.png';
  const HIDE_KEY = 'duquePwaBannerHiddenUntil';

  const standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  if (standalone) return; // already the installed app: no button, no banner

  const ua = navigator.userAgent;
  const isIos = /iphone|ipad|ipod/i.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const isIosSafari = isIos && /safari/i.test(ua) && !/crios|fxios|edgios/i.test(ua);
  const isAndroid = /android/i.test(ua);
  const isFirefox = /firefox|fxios/i.test(ua);
  const isMacSafari = !isIos && /macintosh/i.test(ua) && /safari/i.test(ua) && !/chrome|chromium|edg\//i.test(ua);
  let deferredPrompt = null;
  let banner = null;

  // ---------- Shared install flow ----------

  function steps() {
    if (isIos) {
      return 'On iPhone or iPad:\n1. Tap the Share button (the square with an arrow' + (isIosSafari ? ', at the bottom of Safari' : '') + ').\n2. Scroll down and tap “Add to Home Screen”.\n3. Tap “Add”.\n\nThe ' + NAME + ' icon then appears on your home screen.';
    }
    if (isAndroid) {
      return 'On Android:\n1. Open the browser menu (⋮ at the top right).\n2. Tap “Install app” or “Add to Home screen”.\n\nThe ' + NAME + ' icon then appears on your home screen.';
    }
    if (isMacSafari) {
      return 'In Safari on a Mac:\nchoose File → Add to Dock in the menu bar.\n\n' + NAME + ' then opens from your Dock like any other app.';
    }
    if (isFirefox) {
      return 'Firefox can’t install web apps.\nOpen this page in Chrome, Edge or Safari, then click “Install app” again.';
    }
    return 'In Chrome or Edge:\nclick the install icon at the right end of the address bar, or open the browser menu (⋮ or …) and choose “Install ' + NAME + '”.\n\nIf you don’t see it, the app may already be installed — look for ' + NAME + ' in your Start menu, Applications folder or desktop.';
  }

  function markInstalled() {
    hideBanner(3650);
    const btn = document.getElementById('navInstallApp');
    if (btn) btn.hidden = true;
  }

  async function install() {
    if (deferredPrompt) {
      const prompt = deferredPrompt;
      deferredPrompt = null;
      prompt.prompt();
      const choice = await prompt.userChoice;
      if (choice.outcome === 'accepted') markInstalled();
      return;
    }
    if (typeof showNotice === 'function') {
      showNotice(steps(), { title: 'Install ' + NAME });
    }
  }

  // ---------- Sidebar button ----------

  function wireNavButton() {
    const btn = document.getElementById('navInstallApp');
    if (!btn) return;
    btn.hidden = false;
    btn.addEventListener('click', install);
  }

  // ---------- Banner ----------

  function hideBanner(days) {
    banner?.remove();
    banner = null;
    try { localStorage.setItem(HIDE_KEY, String(Date.now() + days * 86400000)); } catch (e) {}
  }

  function bannerSnoozed() {
    try { return Number(localStorage.getItem(HIDE_KEY) || 0) > Date.now(); } catch (e) { return false; }
  }

  function showBanner(mode) {
    const main = document.querySelector('main.main');
    if (banner || !main || bannerSnoozed()) return; // no banner on the login page
    banner = document.createElement('div');
    banner.className = 'pwa-banner';
    banner.setAttribute('role', 'region');
    banner.setAttribute('aria-label', TITLE);
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
    banner.querySelector('[data-pwa-later]').addEventListener('click', () => hideBanner(30));
    banner.querySelector('[data-pwa-install]')?.addEventListener('click', install);
  }

  // ---------- Start ----------

  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault(); // use our button/banner instead of the browser mini-bar
    deferredPrompt = e;
    showBanner('prompt');
  });
  window.addEventListener('appinstalled', markInstalled);

  const start = () => {
    wireNavButton();
    if (isIosSafari) showBanner('ios');
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
