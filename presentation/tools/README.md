# Client PDFs

Build every PDF with the safe converter, never with a plain `page.pdf()`:

```bash
node presentation/tools/topdf.cjs path/to/index.html presentation/Name.pdf
```

It removes the effects that the iPhone PDF viewer draws as **gray rectangles** around
pictures (blurred shadows, filters, blend modes, see-through gradients, transparent
images), then runs `pdfcheck.py` and fails if any soft mask is left.

When designing a PDF page:
- no `box-shadow`, `filter`, `backdrop-filter`, `text-shadow` or `opacity` tricks for depth: use borders;
- gradients only with opaque colours (no `rgba(...)` or `transparent` stops);
- decorative stripes and icons in CSS (borders, `repeating-linear-gradient`), not SVG/PNG backgrounds;
- transparent logos: add `data-bg="#colour-behind"` so they are flattened on the right colour.

Check an existing file: `python3 presentation/tools/pdfcheck.py file.pdf`.
