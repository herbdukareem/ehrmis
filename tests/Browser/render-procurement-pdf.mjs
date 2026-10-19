import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

const tools = resolve('storage/app/private/ui-checks/pdf-renderer/node_modules');
const canvas = await import(pathToFileURL(resolve(tools, '@napi-rs/canvas/index.js')));
Object.assign(globalThis, { DOMMatrix: canvas.DOMMatrix, ImageData: canvas.ImageData, Path2D: canvas.Path2D });
const pdfjs = await import(pathToFileURL(resolve(tools, 'pdfjs-dist/legacy/build/pdf.mjs')));
const file = resolve(process.argv[2] || 'storage/app/private/ui-checks/budget-procurement/supplier-balances.pdf');
const task = pdfjs.getDocument({ data: new Uint8Array(await readFile(file)), isEvalSupported: false });
const pdf = await task.promise;
for (let number = 1; number <= pdf.numPages; number++) {
    const page = await pdf.getPage(number);
    const viewport = page.getViewport({ scale: 1.25 });
    const bitmap = canvas.createCanvas(Math.ceil(viewport.width), Math.ceil(viewport.height));
    await page.render({ canvasContext: bitmap.getContext('2d'), viewport }).promise;
    const text = (await page.getTextContent()).items.map((item) => item.str).join(' ');
    assert.ok(text.includes(`Page ${number} of ${pdf.numPages}`), 'Missing page number');
    assert.ok(text.includes('Budget & procurement'), 'Missing report footer');
    const output = file.replace(/\.pdf$/i, `-page-${number}.png`);
    await writeFile(output, await bitmap.encode('png'));
    console.log(JSON.stringify({ page: number, width: bitmap.width, height: bitmap.height, output }));
    page.cleanup();
}
await task.destroy();
