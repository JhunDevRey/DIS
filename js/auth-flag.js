// js/auth-flag.js
// A large waving Canadian flag as the back layer of the login pages' brand
// panel (.auth-brand), behind the ember canvas (js/auth-embers.js) so the
// embers drift in front of it. The flag is drawn once to an offscreen
// texture, then every frame as a mesh of small cells: each mesh point gets
// a depth from diagonal travelling waves (pinned at the pole, freest at the
// fly end) plus a slight turn away from the viewer, is projected with
// perspective, and each cell is lit from the upper left by its surface
// normal so the folds catch light and fall into shadow. The pole is a
// shaded cylinder with a gold finial.
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

  // Flag of Canada, viewBox 0 0 9600 4800.
  const RED = '#D52B1E';
  const LEAF = new Path2D('m4890 4430-45-863a95 95 0 0 1 111-98l859 151-116-320a65 65 0 0 1 20-73l941-762-212-99a65 65 0 0 1-34-79l186-572-542 115a65 65 0 0 1-73-38l-105-247-423 454a65 65 0 0 1-111-57l204-1052-327 189a65 65 0 0 1-91-27l-332-652-332 652a65 65 0 0 1-91 27l-327-189 204 1052a65 65 0 0 1-111 57l-423-454-105 247a65 65 0 0 1-73 38l-542-115 186 572a65 65 0 0 1-34 79l-212 99 941 762a65 65 0 0 1 20 73l-116 320 859-151a95 95 0 0 1 111 98l-45 863z');

  // Light comes from the upper left, in front of the flag.
  const L = (() => { const x = -0.55, y = -0.65, z = -1, n = Math.hypot(x, y, z); return [x / n, y / n, z / n]; })();
  const FLAT = -L[2];   // brightness of an unrippled surface facing the viewer

  let dpr = 1, W = 0, H = 0, src = null, raf = 0, running = false, t0 = 0;
  let geo = null, cols = 0, px = null, py = null;
  let lit = null, litCtx = null, lmap = null, lmapCtx = null, lmapData = null;

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
      amp: fw * (reduceMotion ? 0.035 : 0.06),
      yaw: 0.5,                // radians the fly end turns away from the viewer
      focal: Math.max(W, H) * 1.1,
      cx: W * 0.5, cy: H * 0.45
    };
    cols = Math.max(40, Math.min(100, Math.round(fw / 6)));
    px = new Float32Array((cols + 1) * (ROWS + 1));
    py = new Float32Array(px.length);
    lmap = document.createElement('canvas');
    lmap.width = cols; lmap.height = ROWS;
    lmapCtx = lmap.getContext('2d');
    lmapData = lmapCtx.createImageData(cols, ROWS);

    src = document.createElement('canvas');
    src.width = Math.round(fw * dpr);
    src.height = Math.round(fh * dpr);
    const g = src.getContext('2d');
    const s = src.width / 9600;
    g.scale(s, s);
    g.fillStyle = '#fff';
    g.fillRect(0, 0, 9600, 4800);
    g.fillStyle = RED;
    g.fillRect(0, 0, 2400, 4800);
    g.fillRect(7200, 0, 2400, 4800);
    g.fill(LEAF);

    lit = document.createElement('canvas');
    lit.width = src.width; lit.height = src.height;
    litCtx = lit.getContext('2d');
    return true;
  }

  // Depth of the cloth at (u, v), both 0..1. Positive = away from the viewer.
  function depth(u, v, t) {
    const reach = 0.1 + 0.9 * u;
    return geo.amp * reach * (
      Math.sin(TAU * (1.25 * u - 0.28 * v) - t * 2.6) +
      0.45 * Math.sin(TAU * (2.3 * u + 0.18 * v) - t * 3.9 + 1.3) +
      0.18 * Math.sin(TAU * (4.1 * u - 0.5 * v) - t * 6.2)
    );
  }

  function project(x, y, z, i) {
    const s = geo.focal / (geo.focal + z);
    px[i] = (geo.cx + (x - geo.cx) * s) * dpr;
    py[i] = (geo.cy + (y - geo.cy) * s) * dpr;
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

  function draw(t) {
    const { fw, fh, poleX, top, yaw } = geo;
    const cy = Math.cos(yaw), sy = Math.sin(yaw);
    const du = 1 / cols, dv = 1 / ROWS;

    for (let j = 0; j <= ROWS; j++) {
      const v = j * dv;
      for (let i = 0; i <= cols; i++) {
        const u = i * du;
        const z = depth(u, v, t);
        // Turn the whole flag about the pole, then add the ripple depth.
        const x = poleX + u * fw * cy;
        const zz = u * fw * sy + z;
        // The cloth droops a touch toward the fly end.
        const y = top + v * fh + u * u * fh * 0.04;
        project(x, y, zz, j * (cols + 1) + i);
      }
    }
    for (let j = 0; j < ROWS; j++) {
      const v = (j + 0.5) * dv;
      for (let i = 0; i < cols; i++) {
        const u = (i + 0.5) * du;
        const e = 0.004;
        const dzx = (depth(u + e, v, t) - depth(u - e, v, t)) / (2 * e * fw);
        const dzy = (depth(u, v + e, t) - depth(u, v - e, t)) / (2 * e * fh);
        const n = Math.hypot(dzx, dzy, 1);
        const s = ((dzx * L[0] + dzy * L[1] - L[2]) / n) / FLAT - 1;   // >0 highlight, <0 shadow
        const k = (j * cols + i) * 4, c = s > 0 ? 255 : 0;
        lmapData.data[k] = lmapData.data[k + 1] = lmapData.data[k + 2] = c;
        lmapData.data[k + 3] = 255 * (s > 0 ? Math.min(0.55, s * 1.3) : Math.min(0.7, -s * 1.9));
      }
    }
    // Bake the lighting into this frame's texture: the small light map is
    // scaled up with smoothing, so the shading is continuous across cells.
    lmapCtx.putImageData(lmapData, 0, 0);
    litCtx.globalCompositeOperation = 'copy';
    litCtx.drawImage(src, 0, 0);
    litCtx.globalCompositeOperation = 'source-atop';
    litCtx.imageSmoothingEnabled = true;
    litCtx.drawImage(lmap, 0, 0, cols, ROWS, 0, 0, lit.width, lit.height);

    ctx.clearRect(0, 0, canvas.width, canvas.height);
    drawPole();

    // Each cell is mapped onto the parallelogram spanned by its top-left,
    // top-right and bottom-left mesh points, so edges follow the folds
    // smoothly; cells overlap by a hair to hide seams.
    const sw = src.width / cols, sh = src.height / ROWS, ov = 1.25;
    for (let j = 0; j < ROWS; j++) {
      for (let i = 0; i < cols; i++) {
        const a = j * (cols + 1) + i, b = a + cols + 1;
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
    draw((now - t0) / 1000);
    raf = requestAnimationFrame(frame);
  }
  function start() {
    if (running || reduceMotion || document.hidden || !W || !H) return;
    running = true;
    t0 = performance.now() - 600;
    raf = requestAnimationFrame(frame);
  }
  function stop() { running = false; cancelAnimationFrame(raf); }

  function refresh() {
    stop();
    if (!build()) return;   // panel hidden on small screens
    draw(0.6);
    start();
  }

  document.addEventListener('visibilitychange', () => { document.hidden ? stop() : start(); });
  if ('ResizeObserver' in window) new ResizeObserver(refresh).observe(brand);
  else window.addEventListener('resize', refresh);
  refresh();
})();
