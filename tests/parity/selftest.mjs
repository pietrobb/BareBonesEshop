#!/usr/bin/env node
// Self-test for the parity comparator: two local fixture sites with known differences.
//   node tests/parity/selftest.mjs
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import { loadConfig, runParity } from './parity.mjs';

const page = ({ title, price = '10,00 €', color = '#336699', extraLink = '' }) => `<!doctype html><html lang="sk"><head>
<title>${title}</title><meta name="description" content="Popis"><link rel="canonical" href="/kniha">
<script type="application/ld+json">{"@type":"Product","name":"Kniha","offers":{"price":"10.00"}}</script></head>
<body style="margin:0;font:16px sans-serif"><h1>Kniha</h1><span class="price">${price}</span>
<div style="width:300px;height:120px;background:${color}"></div><time data-parity-mask>${Date.now()}</time>
<a href="/">Domov</a><a href="/kosik">Košík</a>${extraLink}<button>Do košíka</button></body></html>`;

function site(prefix, variant) {
  const pages = {
    '/': page({ title: 'Domov' }),
    '/kniha': page({ title: variant === 'candidate' ? 'Kniha — nový názov' : 'Kniha', price: variant === 'candidate' ? '12,00 €' : '10,00 €' }),
    '/vizual': page({ title: 'Vizuál', color: variant === 'candidate' ? '#cc3333' : '#336699' }),
    '/nova': page({ title: 'Nová' }),
  };
  return http.createServer((req, res) => {
    let url = req.url.startsWith(prefix) ? req.url.slice(prefix.length) || '/' : req.url;
    if (url === '/sitemap.xml') {
      res.writeHead(200, { 'Content-Type': 'application/xml' });
      res.end(`<urlset>${['/', '/kniha', '/vizual', '/stara'].map((p) => `<url><loc>http://${req.headers.host}${prefix}${p}</loc></url>`).join('')}</urlset>`);
      return;
    }
    if (url === '/stara') { res.writeHead(301, { Location: `${prefix}/nova` }); res.end(); return; }
    if (!pages[url]) { res.writeHead(404, { 'Content-Type': 'text/html' }); res.end('<title>404</title><h1>Nenájdené</h1>'); return; }
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end(pages[url]);
  });
}

const listen = (server) => new Promise((resolve) => server.listen(0, '127.0.0.1', () => resolve(server.address().port)));
const baseline = site('', 'baseline');
const candidate = site('/test', 'candidate');
const [portA, portB] = [await listen(baseline), await listen(candidate)];
const dir = await fs.mkdtemp(path.join(os.tmpdir(), 'bb-parity-'));
try {
  await fs.writeFile(path.join(dir, 'expected-diffs.json'), JSON.stringify([
    { url: '^/kniha$', field: 'title', reason: 'nový názov produktu je zámerný' },
  ]));
  await fs.writeFile(path.join(dir, 'parity.json'), JSON.stringify({
    baseline: `http://127.0.0.1:${portA}`,
    candidate: `http://127.0.0.1:${portB}/test`,
    sitemap: true,
    paths: ['/chyba-404'],
    viewports: [{ name: 'desktop', width: 1440, height: 900 }, { name: 'mobile', width: 390, height: 844, mobile: true }],
    mask: ['[data-parity-mask]'],
    expectedDiffs: 'expected-diffs.json',
    out: 'report',
  }));
  const result = await runParity(await loadConfig(path.join(dir, 'parity.json')));
  const byName = Object.fromEntries(result.pages.map((p) => [p.name, p]));

  assert.equal(byName['/'].status, 'match', 'identical page matches on both viewports');
  assert.equal(byName['/chyba-404'].status, 'match', '404 page parity (status compared)');
  assert.equal(byName['/stara'].status, 'match', 'legacy redirect keeps status 301 and mapped target');
  const kniha = byName['/kniha'];
  assert.equal(kniha.status, 'major', 'price change is a major semantic difference');
  assert.ok(kniha.semantic.find((d) => d.field === 'title')?.approved, 'title difference is approved by expected-diffs');
  assert.ok(kniha.semantic.find((d) => d.field === 'prices' && !d.approved), 'price difference stays unapproved');
  assert.equal(byName['/vizual'].semantic.length, 0, 'visual-only change has no semantic difference');
  assert.ok(byName['/vizual'].shots.every((s) => s.ratio > 0.01), 'visual-only change is caught by the pixel diff');
  assert.equal(byName['/vizual'].status, 'major', 'large pixel change is major');
  assert.equal(result.summary.approved, 1, 'approved differences are counted separately');
  assert.ok(result.summary.unapproved >= 3, 'unapproved differences fail the gate');
  const html = await fs.readFile(path.join(dir, 'report', 'index.html'), 'utf8');
  assert.ok(html.includes('ČERVENÉ') && html.includes('schválené: nový názov produktu je zámerný'), 'HTML report shows verdict and approvals');
  await fs.access(path.join(dir, 'report', 'img', '0000-mobile-diff.png'));
  console.log(`parity selftest passed (${result.summary.pages} pages)`);
} finally {
  baseline.close();
  candidate.close();
  await fs.rm(dir, { recursive: true, force: true });
}
