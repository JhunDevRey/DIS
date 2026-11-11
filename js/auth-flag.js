// js/auth-flag.js
// A large waving Canadian flag as the back layer of the login pages' brand
// panel (.auth-brand), behind the ember canvas (js/auth-embers.js) so the
// embers drift in front of it.
//
// The flag texture (with a canvas pole sleeve, sewn seams, hems and a fine
// fabric weave) is drawn once offscreen. Every frame the cloth is a mesh:
// each point gets a ripple depth from diagonal travelling waves plus a
// corner flutter (pinned at the pole, freest at the fly end), driven by a
// gusty wind that changes their strength and speed. Along each row the
// cloth keeps its length, so deep folds pull the fly end in. The mesh is
// turned away from the viewer, projected with perspective, and lit per
// cell: a key light from the upper left (with a soft fabric sheen and the
// back of the cloth showing in deep folds) and warm firelight from below
// to match the embers. The lighting is baked into the frame's texture at
// low resolution and smoothed, then each cell is mapped onto its quad.
// Pauses when the panel is hidden (phones) or the tab is in the
// background, and draws a single still frame for "reduce motion" users.
(function () {
  const brand = document.querySelector('.auth-brand');
  if (!brand || !window.requestAnimationFrame || !window.Path2D) return;

  const canvas = document.createElement('canvas');
  canvas.className = 'auth-flag';
  canvas.setAttribute('aria-hidden', 'true');
  brand.prepend(canvas);   // loaded after auth-embers.js, so it lands behind the ember canvas
  const ctx = canvas.getContext('2d');
  if (!ctx) return;

  const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const TAU = Math.PI * 2;
  const ROWS = 16;
  const SLEEVE = 0.035;   // share of the texture width taken by the pole sleeve

  // Flag of Canada, viewBox 0 0 9600 4800.
  const RED = '#D52B1E';
  const LEAF = new Path2D('m4890 4430-45-863a95 95 0 0 1 111-98l859 151-116-320a65 65 0 0 1 20-73l941-762-212-99a65 65 0 0 1-34-79l186-572-542 115a65 65 0 0 1-73-38l-105-247-423 454a65 65 0 0 1-111-57l204-1052-327 189a65 65 0 0 1-91-27l-332-652-332 652a65 65 0 0 1-91 27l-327-189 204 1052a65 65 0 0 1-111 57l-423-454-105 247a65 65 0 0 1-73 38l-542-115 186 572a65 65 0 0 1-34 79l-212 99 941 762a65 65 0 0 1 20 73l-116 320 859-151a95 95 0 0 1 111 98l-45 863z');

  const norm = (x, y, z) => { const n = Math.hypot(x, y, z); return [x / n, y / n, z / n]; };
  // Directions toward the lights (x right, y down, z away from the viewer).
  const KEY = norm(-0.55, -0.65, -1);      // upper left, in front
  const FIRE = norm(0.15, 1, -0.45);       // below, in front
  const HALF = norm(KEY[0], KEY[1], KEY[2] - 1);   // key/viewer half vector for the sheen

  let dpr = 1, W = 0, H = 0, src = null, raf = 0, running = false;
  let geo = null, cols = 0, px = null, py = null, zl = null;
  let lit = null, litCtx = null;
  let shadeMap = null, shadeCtx = null, shadeData = null;
  let fireMap = null, fireCtx = null, fireData = null;
  let last = 0, clock = 0, phase = 0;

  function smallCanvas(w, h) {
    const c = document.createElement('canvas');
    c.width = w; c.height = h;
    const g = c.getContext('2d');
    return [c, g, g.createImageData(w, h)];
  }

  function buildTexture(w, h) {
    src = document.createElement('canvas');
    src.width = w; src.height = h;
    const g = src.getContext('2d');
    const sl = Math.round(w * SLEEVE);

    // Sleeve: heavy off-white canvas wrapped round the pole.
    g.fillStyle = '#E9E5DC';
    g.fillRect(0, 0, sl, h);

    g.save();
    g.translate(sl, 0);
    const s = (w - sl) / 9600;
    g.scale(s, h / 4800);
    g.fillStyle = '#fff';
    g.fillRect(0, 0, 9600, 4800);
    g.fillStyle = RED;
    g.fillRect(0, 0, 2400, 4800);
    g.fillRect(7200, 0, 2400, 4800);
    g.fill(LEAF);
    g.restore();

    // Seams between the panels, hems along the edges, sleeve stitching.
    const line = Math.max(1, Math.round(h / 260));
    g.fillStyle = 'rgba(0,0,0,.16)';
    const fx = (x) => sl + (w - sl) * x / 9600;
    g.fillRect(Math.round(fx(2400)) - line, 0, line, h);
    g.fillRect(Math.round(fx(7200)), 0, line, h);
    g.fillStyle = 'rgba(0,0,0,.12)';
    const hem = Math.round(h * 0.022);
    g.fillRect(sl, hem, w - sl, line);
    g.fillRect(sl, h - hem - line, w - sl, line);
    g.fillRect(w - hem - line, 0, line, h);
    g.fillStyle = 'rgba(0,0,0,.22)';
    g.fillRect(sl - line, 0, line * 1.5, h);
    g.setLineDash([line * 3, line * 2]);
    g.strokeStyle = 'rgba(0,0,0,.18)';
    g.lineWidth = line;
    g.beginPath();
    g.moveTo(sl * 0.82, 0); g.lineTo(sl * 0.82, h);
    g.stroke();

    // Fabric weave: a faint over-under thread pattern plus fibre noise.
    const img = g.getImageData(0, 0, w, h), d = img.data;
    for (let y = 0; y < h; y++) {
      const rowN = (Math.random() - 0.5) * 0.03;   // uneven threads
      for (let x = 0; x < w; x++) {
        const k = (y * w + x) * 4;
        const weave = ((x + (y >> 1)) & 1) ? 0.025 : -0.025;
        const f = 1 + weave + rowN + (Math.random() - 0.5) * 0.06;
        d[k] = Math.min(255, d[k] * f);
        d[k + 1] = Math.min(255, d[k + 1] * f);
        d[k + 2] = Math.min(255, d[k + 2] * f);
      }
    }
    g.putImageData(img, 0, 0);
  }

  function build() {
    const rect = brand.getBoundingClientRect();
    W = rect.width; H = rect.height;
    if (!W || !H) return false;
    dpr = Math.min(window.devicePixelRatio || 1, 2);
    canvas.width = Math.round(W * dpr);
    canvas.height = Math.round(H * dpr);

    // Flag placement in panel px: big, upper right, running off the edge a little.
    const fw = Math.min(W * 0.95, H * 1.3);
    const fh = fw / 2;
    geo = {
      fw, fh,
      poleX: W * 0.16,
      top: H * 0.13,
      amp: fw * (reduceMotion ? 0.04 : 0.065),
      yaw: 0.5,                // radians the fly end turns away from the viewer
      focal: Math.max(W, H) * 1.1,
      cx: W * 0.5, cy: H * 0.45
    };
    cols = Math.max(40, Math.min(100, Math.round(fw / 6)));
    px = new Float32Array((cols + 1) * (ROWS + 1));
    py = new Float32Array(px.length);
    zl = new Float32Array(px.length);
    [shadeMap, shadeCtx, shadeData] = smallCanvas(cols, ROWS);
    [fireMap, fireCtx, fireData] = smallCanvas(cols, ROWS);

    buildTexture(Math.round(fw * dpr), Math.round(fh * dpr));
    lit = document.createElement('canvas');
    lit.width = src.width; lit.height = src.height;
    litCtx = lit.getContext('2d');
    return true;
  }

  // Gusty wind, 0.6..1.1: a few slow incommensurate swells.
  function windAt(t) {
    if (reduceMotion) return 0.85;
    return 0.85 + 0.12 * Math.sin(t * 0.31) + 0.08 * Math.sin(t * 0.83 + 2.1) + 0.05 * Math.sin(t * 1.9 + 0.7);
  }

  // Ripple depth of the cloth at (u, v), both 0..1. Positive = away from the viewer.
  function depth(u, v, ph, wind) {
    const reach = 0.06 + 0.94 * Math.pow(u, 1.15);
    return geo.amp * wind * reach * (
      Math.sin(TAU * (1.2 * u - 0.25 * v) - ph * 2.6) +
      0.45 * Math.sin(TAU * (2.2 * u + 0.15 * v) - ph * 3.7 + 1.3) +
      0.18 * Math.sin(TAU * (4.0 * u - 0.45 * v) - ph * 6.1) +
      0.4 * u * u * u * Math.sin(TAU * (1.3 * v + 2.5 * u) - ph * 8.3)   // corner flutter
    );
  }

  function drawPole() {
    const x = geo.poleX, r = Math.max(3, geo.fw * 0.011);
    const top = geo.top - r * 3;
    ctx.save();
    ctx.scale(dpr, dpr);
    const g = ctx.createLinearGradient(x - r, 0, x + r, 0);
    g.addColorStop(0, '#3A3D44');
    g.addColorStop(0.35, '#E9ECF1');
    g.addColorStop(0.55, '#B9BEC7');
    g.addColorStop(1, '#2A2C31');
    ctx.fillStyle = g;
    ctx.fillRect(x - r, top, r * 2, H - top);
    // Warm firelight on the lower pole.
    const fl = ctx.createLinearGradient(0, H * 0.55, 0, H);
    fl.addColorStop(0, 'rgba(255,110,40,0)');
    fl.addColorStop(1, 'rgba(255,110,40,.35)');
    ctx.fillStyle = fl;
    ctx.fillRect(x - r, H * 0.55, r * 2, H * 0.45);
    // Gold finial.
    const fr = r * 2.1;
    const fg = ctx.createRadialGradient(x - fr * 0.35, top - fr * 0.9, fr * 0.1, x, top - fr * 0.6, fr);
    fg.addColorStop(0, '#FFF4CF');
    fg.addColorStop(0.45, '#E0B13A');
    fg.addColorStop(1, '#6E5108');
    ctx.fillStyle = fg;
    ctx.beginPath();
    ctx.arc(x, top - fr * 0.6, fr, 0, TAU);
    ctx.fill();
    ctx.restore();
  }

  function draw(t, ph) {
    const { fw, fh, poleX, top, yaw, focal, cx, cy } = geo;
    const cosY = Math.cos(yaw), sinY = Math.sin(yaw);
    const du = 1 / cols, dv = 1 / ROWS, stepX = fw * du, stepY = fh * dv;
    const wind = windAt(t);
    const droop = (1.15 - wind) * 0.12 * fh;
    const flicker = 0.85 + 0.1 * Math.sin(t * 2.3) + 0.05 * Math.sin(t * 7.1);
    const C = cols + 1;

    // Mesh points: ripple depth, length-preserving run along each row,
    // turn about the pole, perspective projection.
    for (let j = 0; j <= ROWS; j++) {
      const v = j * dv;
      let xl = 0, zPrev = 0;
      for (let i = 0; i <= cols; i++) {
        const u = i * du;
        const z = depth(u, v, ph, wind);
        if (i > 0) {
          const s = (z - zPrev) / stepX;
          xl += stepX / Math.sqrt(1 + s * s);
        }
        zPrev = z;
        const k = j * C + i;
        zl[k] = z;
        const X = poleX + xl * cosY - z * sinY;
        const Z = xl * sinY + z * cosY;
        const Y = top + v * fh + u * u * droop;
        const p = focal / (focal + Z);
        px[k] = (cx + (X - cx) * p) * dpr;
        py[k] = (cy + (Y - cy) * p) * dpr;
      }
    }

    // Per-cell lighting from the surface normal.
    const flat = norm(sinY, 0, -cosY);
    const base = flat[0] * KEY[0] + flat[2] * KEY[2];
    const sd = shadeData.data, fd = fireData.data;
    for (let j = 0; j < ROWS; j++) {
      for (let i = 0; i < cols; i++) {
        const k = j * C + i;
        const fx = ((zl[k + 1] - zl[k]) + (zl[k + C + 1] - zl[k + C])) / (2 * stepX);
        const fy = ((zl[k + C] - zl[k]) + (zl[k + C + 1] - zl[k + 1])) / (2 * stepY);
        // Normal toward the viewer, turned with the flag.
        let n = norm(fx * cosY + sinY, fy, fx * sinY - cosY);
        const back = n[2] > 0;               // fold showing its back to the viewer
        if (back) n = [-n[0], -n[1], -n[2]];
        const diff = Math.max(0, n[0] * KEY[0] + n[1] * KEY[1] + n[2] * KEY[2]);
        const spec = Math.pow(Math.max(0, n[0] * HALF[0] + n[1] * HALF[1] + n[2] * HALF[2]), 18) * 0.35;
        let val = (diff / base - 1) * 1.1 + spec - (back ? 0.12 : 0);
        const o = (j * cols + i) * 4;
        const c = val > 0 ? 255 : 0;
        sd[o] = sd[o + 1] = sd[o + 2] = c;
        sd[o + 3] = 255 * (val > 0 ? Math.min(0.6, val) : Math.min(0.78, -val * 1.6));

        const fire = Math.max(0, n[0] * FIRE[0] + n[1] * FIRE[1] + n[2] * FIRE[2]);
        const near = (j + 0.5) * dv;         // brighter toward the bottom edge
        fd[o] = 255; fd[o + 1] = 105; fd[o + 2] = 35;
        fd[o + 3] = 255 * Math.min(0.5, fire * flicker * (0.15 + 0.45 * near));
      }
    }

    // Bake this frame's lit texture: weave texture, smoothed shading, then
    // firelight added on top.
    shadeCtx.putImageData(shadeData, 0, 0);
    fireCtx.putImageData(fireData, 0, 0);
    litCtx.globalCompositeOperation = 'copy';
    litCtx.drawImage(src, 0, 0);
    litCtx.globalCompositeOperation = 'source-over';
    litCtx.imageSmoothingEnabled = true;
    litCtx.drawImage(shadeMap, 0, 0, cols, ROWS, 0, 0, lit.width, lit.height);
    litCtx.globalCompositeOperation = 'lighter';
    litCtx.drawImage(fireMap, 0, 0, cols, ROWS, 0, 0, lit.width, lit.height);

    ctx.setTransform(1, 0, 0, 1, 0, 0);
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    drawPole();

    // Each cell is mapped onto the parallelogram spanned by its top-left,
    // top-right and bottom-left mesh points, so edges follow the folds
    // smoothly; cells overlap by about a pixel to hide seams.
    const sw = lit.width / cols, sh = lit.height / ROWS, ov = 1.25;
    for (let j = 0; j < ROWS; j++) {
      for (let i = 0; i < cols; i++) {
        const a = j * C + i, b = a + C;
        const x0 = px[a], y0 = py[a];
        ctx.setTransform(
          (px[a + 1] - x0) / sw, (py[a + 1] - y0) / sw,
          (px[b] - x0) / sh, (py[b] - y0) / sh,
          x0, y0
        );
        ctx.drawImage(lit, i * sw, j * sh, sw, sh, 0, 0, sw + ov, sh + ov);
      }
    }
    ctx.setTransform(1, 0, 0, 1, 0, 0);
  }

  function frame(now) {
    if (!running) return;
    const dt = Math.min(0.05, (now - last) / 1000 || 0.016);
    last = now;
    clock += dt;
    // The waves travel faster in a stronger wind.
    phase += dt * (0.55 + 0.6 * windAt(clock));
    draw(clock, phase);
    raf = requestAnimationFrame(frame);
  }
  function start() {
    if (running || reduceMotion || document.hidden || !W || !H) return;
    running = true;
    last = performance.now();
    raf = requestAnimationFrame(frame);
  }
  function stop() { running = false; cancelAnimationFrame(raf); }

  function refresh() {
    stop();
    if (!build()) return;   // panel hidden on small screens
    if (!clock) { clock = 0.6; phase = 0.6; }
    draw(clock, phase);
    start();
  }

  document.addEventListener('visibilitychange', () => { document.hidden ? stop() : start(); });
  if ('ResizeObserver' in window) new ResizeObserver(refresh).observe(brand);
  else window.addEventListener('resize', refresh);
  refresh();
})();
