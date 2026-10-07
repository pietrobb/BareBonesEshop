// Pixel diff computed in a headless page canvas — no native image dependencies.

export async function pixelDiff(page, baselinePng, candidatePng, threshold = 0.1) {
  return page.evaluate(async ({ a, b, threshold }) => {
    const load = (src) => new Promise((resolve, reject) => {
      const img = new Image();
      img.onload = () => resolve(img);
      img.onerror = reject;
      img.src = 'data:image/png;base64,' + src;
    });
    const [imgA, imgB] = await Promise.all([load(a), load(b)]);
    const width = Math.max(imgA.width, imgB.width);
    const height = Math.max(imgA.height, imgB.height);
    const pixels = (img) => {
      const canvas = new OffscreenCanvas(width, height);
      const ctx = canvas.getContext('2d');
      ctx.drawImage(img, 0, 0);
      return ctx.getImageData(0, 0, width, height).data;
    };
    const pa = pixels(imgA);
    const pb = pixels(imgB);
    const out = new OffscreenCanvas(width, height);
    const octx = out.getContext('2d');
    const diff = octx.createImageData(width, height);
    const limit = threshold * 255;
    let changed = 0;
    for (let i = 0; i < pa.length; i += 4) {
      const inA = (i / 4) % width < imgA.width && Math.floor(i / 4 / width) < imgA.height;
      const inB = (i / 4) % width < imgB.width && Math.floor(i / 4 / width) < imgB.height;
      const delta = Math.max(Math.abs(pa[i] - pb[i]), Math.abs(pa[i + 1] - pb[i + 1]), Math.abs(pa[i + 2] - pb[i + 2]));
      if (inA !== inB || delta > limit) {
        changed++;
        diff.data.set([255, 0, 64, 255], i);
      } else {
        const gray = (pa[i] + pa[i + 1] + pa[i + 2]) / 3;
        diff.data.set([gray, gray, gray, 60], i);
      }
    }
    octx.putImageData(diff, 0, 0);
    const blob = await out.convertToBlob({ type: 'image/png' });
    const bytes = new Uint8Array(await blob.arrayBuffer());
    let binary = '';
    for (let i = 0; i < bytes.length; i += 0x8000) binary += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
    return {
      ratio: width * height ? changed / (width * height) : 0,
      changed,
      width,
      height,
      size_changed: imgA.width !== imgB.width || imgA.height !== imgB.height,
      diff_png: btoa(binary),
    };
  }, { a: baselinePng.toString('base64'), b: candidatePng.toString('base64'), threshold });
}
