/*
 * HTML -> PDF for client documents, safe for every PDF viewer (iPhone included).
 * Usage: node presentation/tools/topdf.cjs page.html out.pdf
 *
 * Why: Chrome writes blurred shadows, filters, blend modes, see-through gradients and
 * transparent PNGs as "soft masks". Desktop viewers blend them; the iPhone viewer shows
 * them as gray rectangles around pictures. This script removes those effects before
 * printing, flattens transparent images on white, then checks the PDF and fails if any
 * soft mask is left (presentation/tools/pdfcheck.py).
 */
const { chromium } = require('playwright');
const path = require('path');
const { execFileSync } = require('child_process');

const SAFE_CSS = `*, *::before, *::after {
  box-shadow: none !important; text-shadow: none !important; filter: none !important;
  backdrop-filter: none !important; -webkit-backdrop-filter: none !important;
  mix-blend-mode: normal !important; mask-image: none !important; -webkit-mask-image: none !important;
}`;

(async () => {
  const [src, out] = process.argv.slice(2);
  if (!src || !out) { console.error('usage: topdf.cjs page.html out.pdf'); process.exit(2); }
  const b = await chromium.launch({ proxy: process.env.HTTPS_PROXY ? { server: process.env.HTTPS_PROXY } : undefined });
  const p = await b.newPage();
  await p.goto('file://' + path.resolve(src), { waitUntil: 'networkidle' });
  await p.addStyleTag({ content: SAFE_CSS });
  await p.evaluate(() => document.fonts.ready);
  // Transparent images (PNG, WebP, GIF): flatten on white (done here: the page cannot read local files).
  // Each image is flattened on its data-bg colour (default white), e.g. <img src="logo.png" data-bg="#0f3324">.
  const srcs = await p.evaluate(() => [...new Set([...document.images].filter((i) => i.src.startsWith('file://') && !/\.jpe?g$/i.test(i.src)).map((i) => i.src + '|' + (i.dataset.bg || '#ffffff')))]);
  for (const key of srcs) {
    const [s, bgc] = key.split('|');
    const file = decodeURIComponent(new URL(s).pathname);
    const b64 = execFileSync('python3', ['-I', '-c', 'import sys,io,base64\nfrom PIL import Image\nim=Image.open(sys.argv[1]).convert("RGBA")\nbg=Image.new("RGB",im.size,sys.argv[2]);bg.paste(im,mask=im.split()[3])\nb=io.BytesIO();bg.save(b,"JPEG",quality=94);print(base64.b64encode(b.getvalue()).decode())', file, bgc], { maxBuffer: 64 << 20 }).toString().trim();
    await p.evaluate(([s, bgc, d]) => Promise.all([...document.images].filter((i) => i.src === s && (i.dataset.bg || '#ffffff') === bgc).map((i) => { i.src = d; return i.decode().catch(() => {}); })), [s, bgc, 'data:image/jpeg;base64,' + b64]);
  }
  const report = await p.evaluate(async () => {
    const out = { gradients: [], overflow: [] };
    // Gradients with see-through colours also become soft masks.
    for (const el of document.querySelectorAll('*')) {
      for (const pseudo of [null, '::before', '::after']) {
        const bg = getComputedStyle(el, pseudo).backgroundImage;
        if (/gradient/.test(bg) && /rgba\([^)]*,\s*0?\.\d+\)|transparent/.test(bg)) out.gradients.push((el.className || el.tagName) + (pseudo || ''));
      }
    }
    // Content running into the footer.
    document.querySelectorAll('.page').forEach((pg, i) => {
      const r = pg.getBoundingClientRect();
      const last = [...pg.children].filter((c) => !c.classList.contains('pf') && getComputedStyle(c).position !== 'absolute').pop();
      if (last && last.getBoundingClientRect().bottom > r.bottom - 50) out.overflow.push(i + 1);
    });
    return out;
  });
  if (report.gradients.length) { console.error('See-through gradients (make the colours opaque):', [...new Set(report.gradients)].join(', ')); process.exit(1); }
  await p.pdf({ path: out, format: 'A4', printBackground: true, preferCSSPageSize: true });
  await b.close();
  console.log('images flattened on white:', srcs.length, '| pages running into the footer:', report.overflow.join(',') || 'none');
  try {
    console.log(execFileSync('python3', [path.join(__dirname, 'pdfcheck.py'), out]).toString().trim());
  } catch (e) {
    console.error(String(e.stdout || e.message));
    process.exit(1);
  }
})();
