// js/auth-flag.js
// A large waving Canadian flag as the back layer of the login pages' brand
// panel (.auth-brand), behind the ember canvas (js/auth-embers.js) so the
// embers drift in front of it.
//
// The flag texture (with a canvas hoist header, sewn seams, hems and a fine
// fabric weave) is drawn once offscreen. Every frame the cloth is a mesh:
// each point gets a ripple depth from diagonal travelling waves plus a
// corner flutter (pinned at the pole, freest at the fly end), driven by a
// strong, steady wind that keeps it at full stretch while its swells vary
// the waves' strength and speed. Along each row the
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
  const SLEEVE = 0.014;   // share of the texture width taken by the hoist header strip

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
  let lit = null, litCtx = null, cloth = null, clothCtx = null;
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

    // Hoist header: heavy off-white canvas strip carrying the grommets.
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

    // Seams between the panels, hems along the edges, header stitching.
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

    // Framing, matched to the reference photo — every value is a fraction
    // of the panel, so it scales with it. The flag is big, seen from below
    // with a long lens: turned well away from the viewer (yaw), so it looks
    // about 1.5:1 instead of 2:1, with the fly end about as tall as the
    // hoist, and both edges climbing steeply toward the fly (tilt).
    const FRAME = {
      poleX: 0.035,    // pole, from the panel's left edge (share of width)
      top: 0.2,        // hoist top, just under the finial (share of height)
      width: 1.2,      // flag cloth length (share of width) ...
      maxH: 1.15,      // ... capped at this share of the height on short panels
      yaw: 0.72,       // radians the fly end turns away from the viewer
      tilt: 0.15,      // rise of the edges per unit of cloth length, toward the fly
      lens: 8          // focal length (share of the panel's long side): longer = flatter perspective
    };
    const fw = Math.min(W * FRAME.width, H * FRAME.maxH);
    const fh = fw / 2;
    const top = Math.max(H * FRAME.top, 118);   // finial clears the logo row
    geo = {
      fw, fh, top,
      poleX: W * FRAME.poleX,
      poleR: Math.max(2.5, W * 0.011),
      amp: fw * (reduceMotion ? 0.03 : 0.045),
      yaw: FRAME.yaw,
      tilt: FRAME.tilt,
      focal: Math.max(W, H) * FRAME.lens,
      cx: W * 0.5, cy: top + fh / 2   // perspective centred on the flag
    };
    cols = Math.max(40, Math.min(100, Math.round(fw / 6)));
    px = new Float32Array((cols + 1) * (ROWS + 1));
    py = new Float32Array(px.length);
    zl = new Float32Array(px.length);
    [shadeMap, shadeCtx, shadeData] = smallCanvas(cols, ROWS);
    [fireMap, fireCtx, fireData] = smallCanvas(cols, ROWS);

    buildTexture(Math.round(fw * dpr), Math.round(fh * dpr));
    cloth = document.createElement('canvas');
    cloth.width = canvas.width; cloth.height = canvas.height;
    clothCtx = cloth.getContext('2d');
    lit = document.createElement('canvas');
    lit.width = src.width; lit.height = src.height;
    litCtx = lit.getContext('2d');
    return true;
  }

  // A strong, steady wind (0.9..1.1) that keeps the flag fully unfurled,
  // with a few slow incommensurate swells.
  function windAt(t) {
    if (reduceMotion) return 1;
    return 1 + 0.05 * Math.sin(t * 0.31) + 0.03 * Math.sin(t * 0.83 + 2.1) + 0.02 * Math.sin(t * 1.9 + 0.7);
  }

  // Ripple depth of the cloth at (u, v), both 0..1. Positive = away from the viewer.
  function depth(u, v, ph, wind) {
    const reach = 0.04 * u + 0.96 * Math.pow(u, 1.15);   // 0 at the hoist: held still by the clips
    return geo.amp * wind * reach * (
      Math.sin(TAU * (1.2 * u - 0.25 * v) - ph * 2.6) +
      0.45 * Math.sin(TAU * (2.2 * u + 0.15 * v) - ph * 3.7 + 1.3) +
      0.18 * Math.sin(TAU * (4.0 * u - 0.45 * v) - ph * 6.1) +
      0.3 * u * u * u * Math.sin(TAU * (1.3 * v + 2.5 * u) - ph * 8.3)   // corner flutter
    );
  }

  function drawPole() {
    const x = geo.poleX, r = geo.poleR;
    const top = geo.top - r * 2.2;   // full-staff: the flag's top edge sits just under the finial
    ctx.save();
    ctx.scale(dpr, dpr);
    const g = ctx.createLinearGradient(x - r, 0, x + r, 0);
    g.addColorStop(0, '#6F747C');
    g.addColorStop(0.35, '#FFFFFF');
    g.addColorStop(0.6, '#D9DDE3');
    g.addColorStop(1, '#5A5E66');
    ctx.fillStyle = g;
    ctx.fillRect(x - r, top, r * 2, H - top);
    // Warm firelight on the lower pole.
    const fl = ctx.createLinearGradient(0, H * 0.55, 0, H);
    fl.addColorStop(0, 'rgba(255,110,40,0)');
    fl.addColorStop(1, 'rgba(255,110,40,.35)');
    ctx.fillStyle = fl;
    ctx.fillRect(x - r, H * 0.55, r * 2, H * 0.45);
    // Truck: a gold band, then a small silver dome finial.
    const cg = ctx.createLinearGradient(x - r * 1.3, 0, x + r * 1.3, 0);
    cg.addColorStop(0, '#6E5108'); cg.addColorStop(0.4, '#F3D27A'); cg.addColorStop(1, '#5A4206');
    ctx.fillStyle = cg;
    ctx.fillRect(x - r * 1.3, top - r * 1.1, r * 2.6, r * 1.1);
    const fr = r * 1.45;
    const fy = top - r * 1.1 - fr * 0.7;
    const fg = ctx.createRadialGradient(x - fr * 0.4, fy - fr * 0.4, fr * 0.1, x, fy, fr);
    fg.addColorStop(0, '#FFFFFF');
    fg.addColorStop(0.5, '#C9CED6');
    fg.addColorStop(1, '#4E525A');
    ctx.fillStyle = fg;
    ctx.beginPath();
    ctx.arc(x, fy, fr, 0, TAU);
    ctx.fill();
    ctx.fillRect(x - r * 0.25, fy - fr * 1.6, r * 0.5, fr * 0.8);   // little spire
    ctx.restore();
  }

  function draw(t, ph) {
    const { fw, fh, poleX, poleR, top, yaw, tilt, focal, cx, cy } = geo;
    const hoistX = poleX + poleR * 2.6;    // hoist edge, clipped to the halyard beside the pole
    const cosY = Math.cos(yaw), sinY = Math.sin(yaw);
    const du = 1 / cols, dv = 1 / ROWS, stepX = fw * du, stepY = fh * dv;
    const wind = windAt(t);
    const droop = Math.max(0, 1.02 - wind) * 0.1 * fh;   // only a hint of sag in the lulls
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
        const X = hoistX + xl * cosY - z * sinY;
        const Z = xl * sinY + z * cosY;
        const Y = top + v * fh + u * u * droop - xl * tilt;
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
        let val = (diff - base) * 1.8 + spec - (back ? 0.12 : 0);
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

    // The cloth is dimmed so the brand copy on top stays readable; the
    // pole and the clips holding the hoist are drawn brighter so the
    // attachment is clear. The cloth goes to its own layer first so the
    // overlapping cells don't double up when it is faded.
    clothCtx.setTransform(1, 0, 0, 1, 0, 0);
    clothCtx.clearRect(0, 0, cloth.width, cloth.height);
    // Each cell is mapped onto the parallelogram spanned by its top-left,
    // top-right and bottom-left mesh points, so edges follow the folds
    // smoothly; cells overlap by about a pixel to hide seams.
    const sw = lit.width / cols, sh = lit.height / ROWS, ov = 1.25;
    for (let j = 0; j < ROWS; j++) {
      for (let i = 0; i < cols; i++) {
        const a = j * C + i, b = a + C;
        const x0 = px[a], y0 = py[a];
        clothCtx.setTransform(
          (px[a + 1] - x0) / sw, (py[a + 1] - y0) / sw,
          (px[b] - x0) / sh, (py[b] - y0) / sh,
          x0, y0
        );
        clothCtx.drawImage(lit, i * sw, j * sh, sw, sh, 0, 0, sw + ov, sh + ov);
      }
    }

    ctx.setTransform(1, 0, 0, 1, 0, 0);
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.globalAlpha = 0.7;
    drawPole();
    ctx.globalAlpha = 0.42;
    ctx.drawImage(cloth, 0, 0);
    ctx.globalAlpha = 0.85;
    drawFittings(py[0] / dpr, py[ROWS * C] / dpr, hoistX);
    ctx.globalAlpha = 1;
  }

  // How the hoist is held, as in the reference: a rope halyard running down
  // beside the pole, and a snap hook on it clipped through a brass grommet
  // at the hoist's top and bottom corners.
  function drawFittings(y0, y1, hoistX) {
    const r = geo.poleR, ropeX = geo.poleX + r * 1.45;
    ctx.save();
    ctx.scale(dpr, dpr);
    ctx.lineCap = 'round';
    // Halyard.
    ctx.strokeStyle = 'rgba(225,228,232,.9)';
    ctx.lineWidth = Math.max(1, r * 0.35);
    ctx.beginPath();
    ctx.moveTo(geo.poleX + r * 0.9, geo.top - r * 2.2);
    ctx.lineTo(ropeX, y0);
    ctx.lineTo(ropeX, H);
    ctx.stroke();
    // Snap hooks and grommets.
    [y0 + r * 1.1, y1 - r * 1.1].forEach((y) => {
      const gx = hoistX + r * 0.9;
      ctx.strokeStyle = '#B9BEC7';
      ctx.lineWidth = Math.max(1.2, r * 0.4);
      ctx.beginPath();
      ctx.ellipse((ropeX + gx) / 2, y, (gx - ropeX) / 2 + r * 0.3, r * 0.45, 0, 0, TAU);
      ctx.stroke();
      ctx.strokeStyle = '#D9B44A';
      ctx.lineWidth = Math.max(1.2, r * 0.35);
      ctx.beginPath();
      ctx.arc(gx, y, r * 0.5, 0, TAU);
      ctx.stroke();
    });
    ctx.restore();
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
