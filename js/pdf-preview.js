/**
 * Renders a PDF entirely as <canvas> pages using PDF.js.
 *
 * Why this exists: the plain <iframe src="…pdf"> approach hands the PDF
 * response to the browser's own PDF handling, and on some Mac setups
 * that handling (or a browser extension hooked into it, e.g. an Adobe
 * Acrobat extension) forces a download instead of displaying it inline
 * — even with correct "Content-Disposition: inline" headers. Rendering
 * with PDF.js sidesteps that completely: PDF.js fetches the bytes
 * itself and draws each page onto a canvas, so the browser never treats
 * the response as "a PDF to open" and nothing gets a chance to
 * intercept it.
 */
(function () {
  var MIN_ZOOM = 0.5;
  var MAX_ZOOM = 3;
  var ZOOM_STEP = 0.25;

  function renderPdfPreview(opts) {
    var url = opts.url;
    var pagesEl = document.getElementById(opts.pagesId);
    var statusEl = document.getElementById(opts.statusId);
    var pageIndicatorEl = document.getElementById(opts.pageIndicatorId);
    var zoomInBtn = opts.zoomInId ? document.getElementById(opts.zoomInId) : null;
    var zoomOutBtn = opts.zoomOutId ? document.getElementById(opts.zoomOutId) : null;
    var zoomLabelEl = opts.zoomLabelId ? document.getElementById(opts.zoomLabelId) : null;

    function setStatus(message, isError) {
      if (!statusEl) return;
      statusEl.textContent = message || '';
      statusEl.style.display = message ? 'block' : 'none';
      statusEl.classList.toggle('is-error', !!isError);
    }

    function fail(message) {
      setStatus(message, true);
      if (typeof opts.onError === 'function') opts.onError();
    }

    if (!pagesEl) return;
    setStatus('Loading document…', false);

    if (!window.pdfjsLib) {
      fail('Preview failed to load — use the download link below.');
      return;
    }

    var dpr = window.devicePixelRatio || 1;
    // zoomLevel of 1 = "fit to the panel's width" (the previous fixed
    // behavior); zoom in/out just multiplies on top of that baseline,
    // so 100% in the toolbar always means "fits the panel," not "actual
    // PDF point size" — matching what a person expects a fresh preview
    // to open at.
    var zoomLevel = 1;
    var pdfDocRef = null;
    var pageEls = [];
    var renderToken = 0; // invalidates any in-flight render chain from a previous zoom change

    function updateZoomLabel() {
      if (zoomLabelEl) zoomLabelEl.textContent = Math.round(zoomLevel * 100) + '%';
      if (zoomOutBtn) zoomOutBtn.disabled = zoomLevel <= MIN_ZOOM;
      if (zoomInBtn) zoomInBtn.disabled = zoomLevel >= MAX_ZOOM;
    }

    function renderPage(pdfDoc, num, containerWidth) {
      return pdfDoc.getPage(num).then(function (page) {
        var unscaledViewport = page.getViewport({ scale: 1 });
        var fitScale = containerWidth / unscaledViewport.width;
        var viewport = page.getViewport({ scale: fitScale * zoomLevel * dpr });

        var canvas = document.createElement('canvas');
        canvas.className = 'pdfjs-page';
        canvas.width = viewport.width;
        canvas.height = viewport.height;
        canvas.style.width = (viewport.width / dpr) + 'px';
        canvas.style.height = (viewport.height / dpr) + 'px';
        pagesEl.appendChild(canvas);

        var ctx = canvas.getContext('2d');
        return page.render({ canvasContext: ctx, viewport: viewport }).promise.then(function () {
          return canvas;
        });
      });
    }

    function renderAllPages(pdfDoc) {
      var myToken = ++renderToken;
      // Fixed width basis (the panel's own width, not the current page
      // width), independent of zoomLevel, so re-rendering at a new zoom
      // scales from the same 100% baseline every time instead of
      // compounding against whatever the previous zoom left behind.
      var containerWidth = Math.max(pagesEl.clientWidth - 32, 240);
      var total = pdfDoc.numPages;
      pageEls = [];

      Array.prototype.forEach.call(pagesEl.querySelectorAll('canvas.pdfjs-page'), function (c) { c.remove(); });

      var chain = renderPage(pdfDoc, 1, containerWidth).then(function (canvas) {
        if (myToken !== renderToken) return; // a newer zoom change superseded this one
        pageEls[0] = canvas;
        var rest = [];
        for (var i = 2; i <= total; i++) rest.push(i);
        return rest.reduce(function (p, num) {
          return p.then(function () {
            if (myToken !== renderToken) return;
            return renderPage(pdfDoc, num, containerWidth).then(function (canvas) {
              pageEls[num - 1] = canvas;
            });
          });
        }, Promise.resolve());
      });

      chain.catch(function (err) {
        console.error('PDF page render failed:', err);
      });

      return chain;
    }

    function setZoom(next) {
      var clamped = Math.max(MIN_ZOOM, Math.min(MAX_ZOOM, next));
      if (clamped === zoomLevel || !pdfDocRef) return;
      // Re-render at the new scale from the same relative scroll
      // position, so zooming in doesn't silently jump the view back to
      // the top of a multi-page document.
      var ratio = pagesEl.scrollHeight > 0 ? pagesEl.scrollTop / pagesEl.scrollHeight : 0;
      zoomLevel = clamped;
      updateZoomLabel();
      renderAllPages(pdfDocRef).then(function () {
        pagesEl.scrollTop = ratio * pagesEl.scrollHeight;
      });
    }

    if (zoomInBtn) zoomInBtn.addEventListener('click', function () { setZoom(zoomLevel + ZOOM_STEP); });
    if (zoomOutBtn) zoomOutBtn.addEventListener('click', function () { setZoom(zoomLevel - ZOOM_STEP); });

    // Ctrl/Cmd + scroll to zoom — the common convention in PDF and image
    // viewers — without hijacking a plain scroll, which should just move
    // through the document normally.
    pagesEl.addEventListener('wheel', function (e) {
      if (!(e.ctrlKey || e.metaKey)) return;
      e.preventDefault();
      setZoom(zoomLevel + (e.deltaY < 0 ? ZOOM_STEP : -ZOOM_STEP));
    }, { passive: false });

    updateZoomLabel();

    window.pdfjsLib.getDocument({ url: url }).promise.then(function (pdfDoc) {
      pdfDocRef = pdfDoc;
      setStatus('', false);
      var total = pdfDoc.numPages;
      if (pageIndicatorEl) pageIndicatorEl.textContent = '1 / ' + total;

      renderAllPages(pdfDoc);

      if (pageIndicatorEl) {
        pagesEl.addEventListener('scroll', function () {
          var mid = pagesEl.scrollTop + pagesEl.clientHeight / 2;
          var current = 1;
          for (var i = 0; i < pageEls.length; i++) {
            if (pageEls[i] && pageEls[i].offsetTop <= mid) current = i + 1;
          }
          pageIndicatorEl.textContent = current + ' / ' + total;
        });
      }
    }).catch(function (err) {
      console.error('PDF preview failed:', err);
      fail('This document could not be previewed — use the download link below.');
    });
  }

  window.renderPdfPreview = renderPdfPreview;
})();
