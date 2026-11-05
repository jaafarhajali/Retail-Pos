// Export the open printout as a PDF file (2026-11-05, Aya): the page is drawn by the browser itself
// (html2canvas), so the PDF looks exactly like the paper — Arabic names included — and jsPDF wraps the
// picture in a file. 'roll' keeps a receipt as one tall 80 mm page, like the paper roll; anything else
// becomes A4 pages. Returns the promise of the jsPDF document, so it can be checked by a test.
function exportPdf(selector, filename, mode) {
  var el = document.querySelector(selector);
  if (!el || typeof html2canvas === 'undefined' || !window.jspdf) { return Promise.reject(new Error('nothing to export')); }
  var hidden = document.querySelectorAll('.no-print');
  hidden.forEach(function (t) { t.style.visibility = 'hidden'; });
  return html2canvas(el, { scale: 2, backgroundColor: '#ffffff' }).then(function (canvas) {
    hidden.forEach(function (t) { t.style.visibility = ''; });
    var img = canvas.toDataURL('image/jpeg', 0.92), doc;
    if (mode === 'roll') {
      var wMm = 80, hMm = Math.max(60, wMm * canvas.height / canvas.width);
      doc = new window.jspdf.jsPDF({ unit: 'mm', format: [wMm, hMm] });
      doc.addImage(img, 'JPEG', 0, 0, wMm, wMm * canvas.height / canvas.width);
    } else {
      doc = new window.jspdf.jsPDF({ unit: 'mm', format: 'a4' });
      var margin = 8, imgW = 210 - 2 * margin, imgH = imgW * canvas.height / canvas.width, innerH = 297 - 2 * margin;
      var pages = Math.max(1, Math.ceil(imgH / innerH));
      for (var i = 0; i < pages; i++) {
        if (i > 0) { doc.addPage(); }
        doc.addImage(img, 'JPEG', margin, margin - i * innerH, imgW, imgH);
      }
    }
    doc.save((filename || 'document') + '.pdf');
    return doc;
  }, function (e) { hidden.forEach(function (t) { t.style.visibility = ''; }); throw e; });
}
