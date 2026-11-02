/**
 * Zoom/pan controls for the image preview (jpg/png). The image itself
 * still loads as a plain <img> — this only adds the toolbar behavior on
 * top: percentage zoom in/out/reset, Ctrl/Cmd+scroll zoom, and
 * click-and-drag panning once zoomed past what fits the panel.
 */
(function () {
  var MIN_PERCENT = 25;
  var MAX_PERCENT = 400;
  var STEP = 25;

  function initImagePreview(opts) {
    var img = document.getElementById(opts.imgId);
    var shell = document.getElementById(opts.shellId);
    var label = opts.labelId ? document.getElementById(opts.labelId) : null;
    var zoomInBtn = opts.zoomInId ? document.getElementById(opts.zoomInId) : null;
    var zoomOutBtn = opts.zoomOutId ? document.getElementById(opts.zoomOutId) : null;
    var resetBtn = opts.resetId ? document.getElementById(opts.resetId) : null;
    if (!img || !shell) return;

    var percent = 100; // 100% = fit within the shell, via CSS max-width/max-height
    var naturalWidth = null;

    function applyZoom() {
      if (percent === 100) {
        // Let CSS's max-width/max-height do the fitting at the default
        // zoom, so it stays responsive to window resizes instead of
        // being pinned to a stale pixel width computed at load time.
        img.style.width = '';
        img.style.maxWidth = '';
      } else if (naturalWidth) {
        img.style.maxWidth = 'none';
        img.style.width = (naturalWidth * (percent / 100)) + 'px';
      }
      shell.classList.toggle('is-zoomed', percent > 100);
      if (label) label.textContent = percent + '%';
      if (zoomOutBtn) zoomOutBtn.disabled = percent <= MIN_PERCENT;
      if (zoomInBtn) zoomInBtn.disabled = percent >= MAX_PERCENT;
    }

    function setPercent(next) {
      percent = Math.max(MIN_PERCENT, Math.min(MAX_PERCENT, next));
      applyZoom();
    }

    function captureNaturalWidth() {
      if (img.naturalWidth) naturalWidth = img.naturalWidth;
    }
    if (img.complete) captureNaturalWidth();
    img.addEventListener('load', captureNaturalWidth);

    if (zoomInBtn) zoomInBtn.addEventListener('click', function () { setPercent(percent + STEP); });
    if (zoomOutBtn) zoomOutBtn.addEventListener('click', function () { setPercent(percent - STEP); });
    if (resetBtn) resetBtn.addEventListener('click', function () { setPercent(100); });

    // Ctrl/Cmd + scroll to zoom, matching the same convention used in
    // the PDF preview — a plain scroll/trackpad gesture is left alone.
    shell.addEventListener('wheel', function (e) {
      if (!(e.ctrlKey || e.metaKey)) return;
      e.preventDefault();
      setPercent(percent + (e.deltaY < 0 ? STEP : -STEP));
    }, { passive: false });

    // Double-click to jump straight to 200% (or back to 100% if already
    // zoomed) — a quick way in without reaching for the toolbar.
    img.addEventListener('dblclick', function () {
      setPercent(percent > 100 ? 100 : 200);
    });

    // Click-and-drag panning once zoomed in past what fits the shell.
    var isDragging = false, startX = 0, startY = 0, scrollStartX = 0, scrollStartY = 0;
    shell.addEventListener('mousedown', function (e) {
      if (percent <= 100) return;
      isDragging = true;
      startX = e.clientX;
      startY = e.clientY;
      scrollStartX = shell.scrollLeft;
      scrollStartY = shell.scrollTop;
      shell.classList.add('is-dragging');
      e.preventDefault();
    });
    window.addEventListener('mousemove', function (e) {
      if (!isDragging) return;
      shell.scrollLeft = scrollStartX - (e.clientX - startX);
      shell.scrollTop = scrollStartY - (e.clientY - startY);
    });
    window.addEventListener('mouseup', function () {
      isDragging = false;
      shell.classList.remove('is-dragging');
    });

    applyZoom();
  }

  window.initImagePreview = initImagePreview;
})();
