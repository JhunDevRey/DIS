// js/auth-embers.js
// Live background for the login pages' brand panel (.auth-brand only):
// glowing embers drift up from the bottom with a gentle sway and flicker,
// a few quick sparks shoot past, and the pointer pushes nearby embers
// aside. Drawn on one canvas behind the panel's text.
// Pauses when the panel is hidden (phones) or the tab is in the
// background, and draws a single still frame for "reduce motion" users.
(function () {
  const brand = document.querySelector('.auth-brand');
  if (!brand || !window.requestAnimationFrame) return;

  const canvas = document.createElement('canvas');
  canvas.className = 'auth-embers';
  canvas.setAttribute('aria-hidden', 'true');
  brand.prepend(canvas);
  const ctx = canvas.getContext('2d');
  if (!ctx) return;

  const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  // Warm brand palette: deep red → orange → pale gold for the hottest.
  const HUES = [[255, 59, 48], [255, 96, 52], [255, 140, 66], [255, 196, 120]];

  let w = 0, h = 0, dpr = 1;
  let embers = [], sparks = [];
  let running = false, last = 0, frame = 0;
  const pointer = { x: -9999, y: -9999, active: false };

  const rand = (a, b) => a + Math.random() * (b - a);

  function makeEmber(anywhere) {
    const hot = Math.random();
    return {
      x: rand(0, w),
      y: anywhere ? rand(0, h) : h + rand(4, 40),
      r: hot > 0.92 ? rand(2.2, 3.4) : rand(0.8, 2.1),
      vy: rand(18, 52),            // px/s upward
      vx: rand(-6, 6),
      swayAmp: rand(6, 22),
      swayFreq: rand(0.4, 1.2),
      phase: rand(0, Math.PI * 2),
      flicker: rand(3, 9),
      life: 0,
      maxLife: rand(6, 13),        // seconds
      color: HUES[Math.min(HUES.length - 1, Math.floor(hot * hot * HUES.length))],
      px: 0, py: 0                 // pointer push offset
    };
  }

  function makeSpark() {
    const angle = rand(-Math.PI * 0.62, -Math.PI * 0.38); // mostly upward
    const speed = rand(160, 280);
    return {
      x: rand(w * 0.1, w * 0.9), y: h + 6,
      vx: Math.cos(angle) * speed, vy: Math.sin(angle) * speed,
      life: 0, maxLife: rand(0.9, 1.6)
    };
  }

  function resize() {
    const rect = brand.getBoundingClientRect();
    dpr = Math.min(window.devicePixelRatio || 1, 2);
    w = rect.width; h = rect.height;
    canvas.width = Math.round(w * dpr);
    canvas.height = Math.round(h * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    const target = Math.round(Math.min(110, Math.max(40, (w * h) / 9000)));
    while (embers.length < target) embers.push(makeEmber(true));
    embers.length = target;
  }

  function drawGlow(t) {
    // Slow "breathing" heat haze along the bottom edge.
    const pulse = 0.55 + 0.45 * Math.sin(t * 0.6);
    const g = ctx.createRadialGradient(w * 0.5, h + h * 0.1, 0, w * 0.5, h + h * 0.1, h * 0.75);
    g.addColorStop(0, `rgba(221, 0, 0, ${0.16 + 0.08 * pulse})`);
    g.addColorStop(0.5, `rgba(160, 20, 20, ${0.06 + 0.03 * pulse})`);
    g.addColorStop(1, 'rgba(0, 0, 0, 0)');
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, w, h);
  }

  function step(dt, t) {
    ctx.clearRect(0, 0, w, h);
    drawGlow(t);
    ctx.globalCompositeOperation = 'lighter';

    for (let i = 0; i < embers.length; i++) {
      let e = embers[i];
      e.life += dt;
      e.y -= e.vy * dt;
      e.x += e.vx * dt;
      if (e.life > e.maxLife || e.y < -20 || e.x < -30 || e.x > w + 30) {
        e = embers[i] = makeEmber(false);
      }

      // Pointer pushes embers away; the push eases back over time.
      if (pointer.active) {
        const dx = e.x + e.px - pointer.x, dy = e.y + e.py - pointer.y;
        const d2 = dx * dx + dy * dy;
        if (d2 < 110 * 110 && d2 > 0.01) {
          const d = Math.sqrt(d2), f = (1 - d / 110) * 140 * dt;
          e.px += (dx / d) * f; e.py += (dy / d) * f;
        }
      }
      e.px *= 0.96; e.py *= 0.96;

      const sway = Math.sin(t * e.swayFreq + e.phase) * e.swayAmp;
      const x = e.x + sway + e.px, y = e.y + e.py;
      const fadeIn = Math.min(1, e.life / 1.2);
      const fadeOut = Math.min(1, (e.maxLife - e.life) / 2.5);
      const height = Math.min(1, Math.max(0, y / h) + 0.25); // cool as they rise
      const flick = 0.7 + 0.3 * Math.sin(t * e.flicker + e.phase * 3);
      const a = Math.max(0, fadeIn * fadeOut * height * flick);
      const [r, g, b] = e.color;

      const glow = e.r * 5;
      const grad = ctx.createRadialGradient(x, y, 0, x, y, glow);
      grad.addColorStop(0, `rgba(${r}, ${g}, ${b}, ${0.55 * a})`);
      grad.addColorStop(1, `rgba(${r}, ${g}, ${b}, 0)`);
      ctx.fillStyle = grad;
      ctx.beginPath(); ctx.arc(x, y, glow, 0, Math.PI * 2); ctx.fill();

      ctx.fillStyle = `rgba(255, ${Math.min(255, g + 90)}, ${Math.min(255, b + 80)}, ${0.95 * a})`;
      ctx.beginPath(); ctx.arc(x, y, e.r * 0.6, 0, Math.PI * 2); ctx.fill();
    }

    // Occasional fast sparks with a short streak behind them.
    if (!reduceMotion && Math.random() < dt * 0.9) sparks.push(makeSpark());
    ctx.lineCap = 'round';
    for (let i = sparks.length - 1; i >= 0; i--) {
      const s = sparks[i];
      s.life += dt;
      if (s.life > s.maxLife) { sparks.splice(i, 1); continue; }
      s.vy += 60 * dt;              // a touch of gravity curves them
      s.x += s.vx * dt; s.y += s.vy * dt;
      const a = 1 - s.life / s.maxLife;
      ctx.strokeStyle = `rgba(255, 170, 90, ${0.8 * a})`;
      ctx.lineWidth = 1.4;
      ctx.beginPath();
      ctx.moveTo(s.x, s.y);
      ctx.lineTo(s.x - s.vx * 0.045, s.y - s.vy * 0.045);
      ctx.stroke();
    }

    ctx.globalCompositeOperation = 'source-over';
  }

  function loop(now) {
    if (!running) return;
    const dt = Math.min(0.05, (now - last) / 1000 || 0.016);
    last = now;
    step(dt, now / 1000);
    frame = requestAnimationFrame(loop);
  }

  function start() {
    if (running || reduceMotion || !w || !h || document.hidden) return;
    running = true;
    last = performance.now();
    frame = requestAnimationFrame(loop);
  }
  function stop() { running = false; cancelAnimationFrame(frame); }

  function refresh() {
    resize();
    if (!w || !h) { stop(); return; }  // panel hidden on small screens
    if (reduceMotion) step(0, 0); else start();
  }

  brand.addEventListener('pointermove', (ev) => {
    const rect = brand.getBoundingClientRect();
    pointer.x = ev.clientX - rect.left; pointer.y = ev.clientY - rect.top;
    pointer.active = true;
  });
  brand.addEventListener('pointerleave', () => { pointer.active = false; });
  document.addEventListener('visibilitychange', () => { document.hidden ? stop() : start(); });

  if ('ResizeObserver' in window) new ResizeObserver(refresh).observe(brand);
  else window.addEventListener('resize', refresh);
  refresh();
})();
