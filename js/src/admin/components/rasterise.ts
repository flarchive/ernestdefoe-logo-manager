/**
 * Draw an SVG file to a PNG, in the admin's own browser.
 *
 * Mail clients, `.ico` files and the iOS home screen all need pixels, and the
 * server's image library cannot read SVG. The browser can, so it draws the
 * file once at upload and sends the PNG along; the server decodes and
 * re-encodes it like any other upload.
 *
 * The file is drawn through an `<img>`, where an SVG's scripts never run.
 * Explicit width and height are set on its root first: Firefox refuses to
 * draw an SVG with no intrinsic size onto a canvas at all.
 *
 * Lazy-loaded: only an SVG upload ever fetches this.
 */
export async function rasterise(file: File, size: number, square = false, maxWidth = 1120): Promise<Blob | null> {
  try {
    const doc = new DOMParser().parseFromString(await file.text(), 'image/svg+xml');
    const svg = doc.documentElement;

    if (svg.nodeName.toLowerCase() !== 'svg') return null;

    const box = (svg.getAttribute('viewBox') || '')
      .trim()
      .split(/[\s,]+/)
      .map(Number);
    const [w, h] =
      box.length === 4 && box[2] > 0 && box[3] > 0
        ? [box[2], box[3]]
        : [parseFloat(svg.getAttribute('width') || ''), parseFloat(svg.getAttribute('height') || '')];

    if (!(w > 0 && h > 0)) return null;

    const scale = square ? size / Math.max(w, h) : Math.min(size / h, maxWidth / w);
    const dw = Math.max(1, Math.round(w * scale));
    const dh = Math.max(1, Math.round(h * scale));

    svg.setAttribute('width', String(dw));
    svg.setAttribute('height', String(dh));

    const url = URL.createObjectURL(new Blob([new XMLSerializer().serializeToString(svg)], { type: 'image/svg+xml' }));
    const img = new Image();

    try {
      img.src = url;
      await img.decode();

      const canvas = document.createElement('canvas');
      canvas.width = square ? size : dw;
      canvas.height = square ? size : dh;
      canvas.getContext('2d')!.drawImage(img, (canvas.width - dw) / 2, (canvas.height - dh) / 2, dw, dh);

      return await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/png'));
    } finally {
      URL.revokeObjectURL(url);
    }
  } catch {
    return null;
  }
}
