// js/auth-embers.js
// Live background for the login pages' brand panel (.auth-brand only),
// in the style of the Dota 2 menu: embers carried up on a turbulent
// diagonal wind at three depths (big soft out-of-focus embers up close,
// small sharp ones far back), streaked by their speed, with tumbling ash,
// slow smoke, a flickering fire glow from below and the odd gust that
// throws out a burst of sparks. The pointer stirs the air.
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
  const rand = (a, b) => a + Math.random() * (b - a);
  const TAU = Math.PI * 2;

  // Pre-rendered soft glow sprites — far cheaper than a gradient per ember.
  function sprite(size, stops) {
    const c = document.createElement('canvas');
    c.width = c.height = size;
    const g = c.getContext('2d');
    const grad = g.createRadialGradient(size / 2, size / 2, 0, size / 2, size / 2, size / 2);
    stops.forEach(([o, col]) => grad.addColorStop(o, col));
    g.fillStyle = grad;
    g.fillRect(0, 0, size, size);
    return c;
  }
  const GLOWS = [
    sprite(64, [[0, 'rgba(255,235,190,1)'], [0.12, 'rgba(255,170,70,.9)'], [0.4, 'rgba(255,80,20,.35)'], [1, 'rgba(200,20,0,0)']]),  // hot gold
    sprite(64, [[0, 'rgba(255,210,160,1)'], [0.12, 'rgba(255,110,40,.9)'], [0.4, 'rgba(230,40,10,.35)'], [1, 'rgba(160,0,0,0)']]),   // orange
    sprite(64, [[0, 'rgba(255,170,140,1)'], [0.12, 'rgba(240,50,30,.85)'], [0.4, 'rgba(190,10,10,.3)'], [1, 'rgba(120,0,0,0)']])     // deep red
  ];
  const SMOKE = sprite(128, [[0, 'rgba(70,30,30,.55)'], [0.5, 'rgba(45,20,22,.25)'], [1, 'rgba(20,10,12,0)']]);

  // Depth layers: far = small, slow, dim, sharp; near = big, fast, soft.
  const LAYERS = [
    { share: 0.55, size: [0.9, 1.6], speed: 0.55, alpha: 0.95, blur: 1.0 },
    { share: 0.33, size: [1.3, 2.4], speed: 1.0,  alpha: 1.0,  blur: 1.6 },
    { share: 0.12, size: [3.0, 6.0], speed: 1.6,  alpha: 0.35, blur: 4.2 }  // out-of-focus bokeh
  ];

  let w = 0, h = 0, dpr = 1;
  let embers = [], ash = [], smoke = [], sparks = [];
  let running = false, last = 0, frame = 0, time = 0;
  let gust = 0, nextGust = rand(4, 8);
  const pointer = { x: 0, y: 0, vx: 0, vy: 0, active: false };

  // Smooth pseudo-turbulence: a few layered sines over position and time.
  function wind(x, y, t) {
    const n = Math.sin(x * 0.011 + t * 0.7) + Math.sin(y * 0.017 - t * 0.9) * 0.7 + Math.sin((x + y) * 0.006 + t * 0.35) * 1.2;
    const m = Math.cos(y * 0.013 + t * 0.6) + Math.cos(x * 0.009 - t * 0.45) * 0.8;
    return [n * 14, m * 9];
  }

  function pickLayer() {
    let r = Math.random();
    for (let i = 0; i < LAYERS.length; i++) { if ((r -= LAYERS[i].share) <= 0) return i; }
    return 0;
  }

  function makeEmber(anywhere, burst) {
    const li = burst ? 1 + (Math.random() < 0.25 ? 1 : 0) : pickLayer();
    const L = LAYERS[li];
    return {
      layer: li, burst: !!burst,
      x: burst ? rand(w * 0.05, w * 0.6) : rand(-w * 0.25, w),
      y: anywhere ? rand(0, h) : h + rand(6, 60),
      vx: rand(20, 45) * L.speed,                 // drift right…
      vy: -rand(35, 85) * L.speed * (burst ? 1.8 : 1),  // …and up
      r: rand(L.size[0], L.size[1]),
      glow: GLOWS[Math.random() < 0.25 ? 0 : Math.random() < 0.6 ? 1 : 2],
      flicker: rand(6, 16), phase: rand(0, TAU),
      life: 0, maxLife: burst ? rand(1.5, 3) : rand(5, 11)
    };
  }

  function makeAsh(anywhere) {
    return {
      x: rand(-w * 0.2, w), y: anywhere ? rand(0, h) : h + rand(5, 40),
      vx: rand(15, 35), vy: -rand(12, 30),
      size: rand(1.5, 3.5), rot: rand(0, TAU), spin: rand(-3, 3),
      life: 0, maxLife: rand(7, 14), shade: rand(70, 120) | 0
    };
  }

  function makeSmoke(anywhere) {
    const s = rand(h * 0.35, h * 0.7);
    return {
      x: anywhere ? rand(-s * 0.3, w) : -s * 0.6, y: rand(h * 0.35, h * 1.05),
      vx: rand(8, 18), vy: -rand(3, 9), s,
      life: anywhere ? rand(0, 10) : 0, maxLife: rand(22, 34)
    };
  }

  function makeSpark(x, y) {
    const a = rand(-Math.PI * 0.45, -Math.PI * 0.12);   // up and to the right
    const v = rand(220, 420);
    return { x, y, vx: Math.cos(a) * v, vy: Math.sin(a) * v, life: 0, maxLife: rand(0.5, 1.1) };
  }

  function resize() {
    const rect = brand.getBoundingClientRect();
    dpr = Math.min(window.devicePixelRatio || 1, 2);
    w = rect.width; h = rect.height;
    canvas.width = Math.round(w * dpr);
    canvas.height = Math.round(h * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    const area = (w * h) / 10000;
    fill(embers, Math.round(Math.min(160, Math.max(60, area * 1.6))), makeEmber);
    fill(ash, Math.round(Math.min(40, Math.max(14, area * 0.4))), makeAsh);
    fill(smoke, 5, makeSmoke);
  }
  function fill(list, n, make) {
    while (list.length < n) list.push(make(true));
    list.length = n;
  }

  function drawFireGlow(t) {
    // Irregular flicker, like firelight just out of frame.
    const f = 0.75 + 0.12 * Math.sin(t * 2.3) + 0.08 * Math.sin(t * 5.1 + 1) + 0.05 * Math.sin(t * 11.7);
    const g = ctx.createRadialGradient(w * 0.35, h * 1.08, 0, w * 0.35, h * 1.08, h * 0.85);
    g.addColorStop(0, `rgba(255, 70, 10, ${0.30 * f + gust * 0.08})`);
    g.addColorStop(0.35, `rgba(200, 20, 10, ${0.13 * f})`);
    g.addColorStop(1, 'rgba(0, 0, 0, 0)');
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, w, h);
  }

  function step(dt, t) {
    // Gusts: every few seconds the wind picks up and throws a burst.
    if (!reduceMotion) {
      nextGust -= dt;
      if (nextGust <= 0) {
        nextGust = rand(5, 10);
        gust = 1;
        const bx = rand(w * 0.1, w * 0.5);
        for (let i = 0; i < 14; i++) sparks.push(makeSpark(bx + rand(-40, 40), h + 4));
        for (let i = 0; i < 18; i++) embers.push(makeEmber(false, true));
      }
      gust = Math.max(0, gust - dt * 0.45);
    }
    const gustX = 1 + gust * 1.6;

    ctx.clearRect(0, 0, w, h);

    // Smoke (normal blending so it darkens/tints slightly).
    for (let i = 0; i < smoke.length; i++) {
      let s = smoke[i];
      s.life += dt;
      s.x += s.vx * gustX * dt; s.y += s.vy * dt;
      if (s.life > s.maxLife || s.x - s.s > w) s = smoke[i] = makeSmoke(false);
      const a = Math.min(1, s.life / 5, (s.maxLife - s.life) / 5) * 0.5;
      ctx.globalAlpha = Math.max(0, a);
      ctx.drawImage(SMOKE, s.x - s.s, s.y - s.s, s.s * 2, s.s * 2);
    }
    ctx.globalAlpha = 1;

    drawFireGlow(t);

    // Ash: dark tumbling flecks, drawn before the bright embers.
    for (let i = 0; i < ash.length; i++) {
      let a = ash[i];
      a.life += dt;
      const [wx, wy] = wind(a.x, a.y, t);
      a.x += (a.vx * gustX + wx) * dt; a.y += (a.vy + wy * 0.6) * dt;
      a.rot += a.spin * dt;
      if (a.life > a.maxLife || a.y < -10 || a.x > w + 10) a = ash[i] = makeAsh(false);
      const al = Math.min(1, a.life / 1.5, (a.maxLife - a.life) / 2) * 0.55;
      ctx.save();
      ctx.translate(a.x, a.y); ctx.rotate(a.rot);
      ctx.scale(1, Math.abs(Math.cos(a.rot * 1.7)) * 0.8 + 0.2);  // tumbling flake
      ctx.fillStyle = `rgba(${a.shade}, ${a.shade * 0.85 | 0}, ${a.shade * 0.8 | 0}, ${Math.max(0, al)})`;
      ctx.fillRect(-a.size / 2, -a.size / 2, a.size, a.size * 0.6);
      ctx.restore();
    }

    ctx.globalCompositeOperation = 'lighter';

    // Embers: far layer first so near bokeh sits on top.
    const pr = 130;
    for (let li = 0; li < LAYERS.length; li++) {
      const L = LAYERS[li];
      for (let i = embers.length - 1; i >= 0; i--) {
        const e = embers[i];
        if (e.layer !== li) continue;
        e.life += dt;
        const [wx, wy] = wind(e.x, e.y, t);
        let vx = e.vx * gustX + wx * L.speed, vy = e.vy - gust * 25 + wy * L.speed;

        // Pointer stirs the air: push away plus a bit of its motion.
        if (pointer.active) {
          const dx = e.x - pointer.x, dy = e.y - pointer.y, d2 = dx * dx + dy * dy;
          if (d2 < pr * pr && d2 > 1) {
            const d = Math.sqrt(d2), f = (1 - d / pr);
            vx += (dx / d) * f * 160 + pointer.vx * f * 0.5;
            vy += (dy / d) * f * 160 + pointer.vy * f * 0.5;
          }
        }

        e.x += vx * dt; e.y += vy * dt;
        if (e.life > e.maxLife || e.y < -30 || e.x > w + 40) {
          if (e.burst) { embers.splice(i, 1); continue; }  // gust extras don't respawn
          embers[i] = makeEmber(false);
          continue;
        }

        const fade = Math.min(1, e.life / 0.8, (e.maxLife - e.life) / 2);
        const cool = Math.min(1, Math.max(0, e.y / h) * 0.8 + 0.35);   // dim as they rise
        const flick = 0.65 + 0.35 * Math.sin(t * e.flicker + e.phase) * Math.sin(t * e.flicker * 0.37 + 2);
        const alpha = Math.max(0, fade * cool * flick * L.alpha);
        if (alpha < 0.01) continue;

        // Motion streak: stretch the glow along the velocity.
        const speed = Math.hypot(vx, vy);
        const stretch = Math.min(3.2, 1 + speed / 120);
        const size = e.r * 2 * L.blur * 2.2;
        ctx.save();
        ctx.translate(e.x, e.y);
        ctx.rotate(Math.atan2(vy, vx));
        ctx.globalAlpha = alpha;
        ctx.drawImage(e.glow, -size * stretch / 2, -size / 2, size * stretch, size);
        ctx.restore();
      }
    }

    // Sparks: thin bright streaks that arc and burn out.
    ctx.lineCap = 'round';
    for (let i = sparks.length - 1; i >= 0; i--) {
      const s = sparks[i];
      s.life += dt;
      if (s.life > s.maxLife) { sparks.splice(i, 1); continue; }
      s.vx += 40 * dt; s.vy += 90 * dt;
      s.vx *= 0.985; s.vy *= 0.985;
      s.x += s.vx * dt; s.y += s.vy * dt;
      const a = 1 - s.life / s.maxLife;
      ctx.globalAlpha = 1;
      ctx.strokeStyle = `rgba(255, ${190 + 50 * a | 0}, ${120 + 80 * a | 0}, ${0.9 * a})`;
      ctx.lineWidth = 1.3;
      ctx.beginPath();
      ctx.moveTo(s.x, s.y);
      ctx.lineTo(s.x - s.vx * 0.05, s.y - s.vy * 0.05);
      ctx.stroke();
    }

    ctx.globalAlpha = 1;
    ctx.globalCompositeOperation = 'source-over';
    pointer.vx *= 0.9; pointer.vy *= 0.9;
  }

  function loop(now) {
    if (!running) return;
    const dt = Math.min(0.05, (now - last) / 1000 || 0.016);
    last = now; time += dt;
    step(dt, time);
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
    const x = ev.clientX - rect.left, y = ev.clientY - rect.top;
    if (pointer.active) { pointer.vx = (x - pointer.x) * 6; pointer.vy = (y - pointer.y) * 6; }
    pointer.x = x; pointer.y = y; pointer.active = true;
  });
  brand.addEventListener('pointerleave', () => { pointer.active = false; });
  document.addEventListener('visibilitychange', () => { document.hidden ? stop() : start(); });

  if ('ResizeObserver' in window) new ResizeObserver(refresh).observe(brand);
  else window.addEventListener('resize', refresh);
  refresh();
})();
