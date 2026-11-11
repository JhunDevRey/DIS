// js/auth-flag.js
// A waving Canadian flag on a small pole above the headline of the login
// pages' brand panel (.auth-brand-copy). The flag is drawn once to an
// offscreen canvas, then redrawn every frame in 1px-wide vertical strips,
// each shifted by a travelling wave (pinned at the pole, freest at the fly
// end) and shaded by the wave's slope so the folds catch the light.
// Pauses when the panel is hidden (phones) or the tab is in the
// background, and draws a single still frame for "reduce motion" users.
(function () {
  const copy = document.querySelector('.auth-brand .auth-brand-copy');
  if (!copy || !window.requestAnimationFrame || !window.Path2D) return;

  const FLAG_W = 160, FLAG_H = 80;   // CSS px, official 2:1 ratio
  const PAD = 10;                    // room for the wave above and below

  const wrap = document.createElement('div');
  wrap.className = 'auth-flag';
  wrap.setAttribute('aria-hidden', 'true');
  const pole = document.createElement('span');
  pole.className = 'auth-flag-pole';
  const canvas = document.createElement('canvas');
  canvas.className = 'auth-flag-cloth';
  wrap.append(pole, canvas);
  copy.prepend(wrap);

  const ctx = canvas.getContext('2d');
  if (!ctx) return;
  const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const TAU = Math.PI * 2;

  // Flag of Canada, viewBox 0 0 9600 4800.
  const RED = '#D52B1E';
  const LEAF = new Path2D('m4890 4430-45-863a95 95 0 0 1 111-98l859 151-116-320a65 65 0 0 1 20-73l941-762-212-99a65 65 0 0 1-34-79l186-572-542 115a65 65 0 0 1-73-38l-105-247-423 454a65 65 0 0 1-111-57l204-1052-327 189a65 65 0 0 1-91-27l-332-652-332 652a65 65 0 0 1-91 27l-327-189 204 1052a65 65 0 0 1-111 57l-423-454-105 247a65 65 0 0 1-73 38l-542-115 186 572a65 65 0 0 1-34 79l-212 99 941 762a65 65 0 0 1 20 73l-116 320 859-151a95 95 0 0 1 111 98l-45 863z');

  let dpr = 1, src = null, raf = 0, running = false, t0 = 0;

  function build() {
    dpr = Math.min(window.devicePixelRatio || 1, 2);
    canvas.width = Math.round(FLAG_W * dpr);
    canvas.height = Math.round((FLAG_H + PAD * 2) * dpr);
    canvas.style.width = FLAG_W + 'px';
    canvas.style.height = (FLAG_H + PAD * 2) + 'px';

    src = document.createElement('canvas');
    src.width = Math.round(FLAG_W * dpr);
    src.height = Math.round(FLAG_H * dpr);
    const g = src.getContext('2d');
    const s = src.width / 9600;
    g.scale(s, s);
    g.fillStyle = '#fff';
    g.fillRect(0, 0, 9600, 4800);
    g.fillStyle = RED;
    g.fillRect(0, 0, 2400, 4800);
    g.fillRect(7200, 0, 2400, 4800);
    g.fill(LEAF);
  }

  function draw(t) {
    const W = src.width, H = src.height, pad = PAD * dpr;
    const amp = (reduceMotion ? 3 : 5.6) * dpr;
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    for (let x = 0; x < W; x++) {
      const u = x / W;
      const reach = 0.12 + 0.88 * u;                 // pinned at the pole
      const p1 = u * TAU * 1.35 - t * 3.1;
      const p2 = u * TAU * 2.4 - t * 4.7;
      const y = amp * reach * (Math.sin(p1) + 0.35 * Math.sin(p2));
      const slope = reach * (Math.cos(p1) + 0.6 * Math.cos(p2));
      // Fold sag: the cloth shortens a touch where it is most bent.
      const squeeze = 1 - 0.035 * reach * Math.abs(Math.sin(p1));
      const hh = H * squeeze, top = pad + y + (H - hh) / 2;
      ctx.drawImage(src, x, 0, 1, H, x, top, 1, hh);
      const shade = slope * 0.16;
      ctx.fillStyle = shade > 0 ? 'rgba(255,255,255,' + shade.toFixed(3) + ')'
                                : 'rgba(0,0,0,' + (-shade * 1.4).toFixed(3) + ')';
      ctx.fillRect(x, top, 1, hh);
    }
  }

  function frame(now) {
    if (!running) return;
    draw((now - t0) / 1000);
    raf = requestAnimationFrame(frame);
  }
  const visible = () => wrap.offsetParent !== null;
  function start() {
    if (running || reduceMotion || document.hidden || !visible()) return;
    running = true;
    t0 = performance.now();
    raf = requestAnimationFrame(frame);
  }
  function stop() { running = false; cancelAnimationFrame(raf); }

  function refresh() {
    stop();
    build();
    draw(0.6);
    start();
  }
  refresh();

  document.addEventListener('visibilitychange', () => { document.hidden ? stop() : start(); });
  let resizeTimer = 0;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(refresh, 150);
  });
})();
