#!/usr/bin/env node
// BareBones parity comparator: baseline (e.g. live shop) vs candidate (e.g. /test) — visual + semantic.
//
//   node tests/parity/parity.mjs --config=tests/parity/parity.config.json
//
// Exit 0 only when every difference is approved in expected-diffs; the HTML report is the deploy gate.
// See parity.config.example.json for every option.
import fs from 'node:fs/promises';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { chromium } from '@playwright/test';
import { authHeader, collectPaths, mapPath, normalizeBase } from './lib/urls.mjs';
import { compareSemantics, extractSemantics } from './lib/semantic.mjs';
import { pixelDiff } from './lib/pixel.mjs';
import { renderReport } from './lib/report.mjs';

const DEFAULTS = {
  viewports: [{ name: 'desktop', width: 1440, height: 900 }, { name: 'mobile', width: 390, height: 844, mobile: true }],
  pixel: { threshold: 0.1, minor: 0.001, major: 0.01 },
  fullPage: true,
  mask: [],
  urlMap: [],
  flows: [],
  out: 'parity-report',
  timeoutMs: 30000,
  settleMs: 300,
};

export async function loadConfig(file) {
  const config = { ...DEFAULTS, ...JSON.parse(await fs.readFile(file, 'utf8')) };
  config.pixel = { ...DEFAULTS.pixel, ...(config.pixel || {}) };
  if (!config.baseline || !config.candidate) throw new Error('config needs baseline and candidate URLs');
  const dir = path.dirname(path.resolve(file));
  config.out = path.resolve(dir, config.out);
  config.expected = [];
  if (config.expectedDiffs) {
    try { config.expected = JSON.parse(await fs.readFile(path.resolve(dir, config.expectedDiffs), 'utf8')); }
    catch (error) { if (error.code !== 'ENOENT') throw error; }
  }
  return config;
}

/** Approved-difference lookup: {url: regex on baseline path, field: semantic field | "pixel", viewport?, maxRatio?, reason}. */
function approval(config, name, field, viewport = null, ratio = null) {
  return config.expected.find((rule) => new RegExp(rule.url ?? '.*').test(name)
    && [field, '*'].includes(rule.field ?? '*')
    && (!rule.viewport || rule.viewport === viewport)
    && (ratio === null || rule.maxRatio === undefined || ratio <= rule.maxRatio)) || null;
}

async function visit(context, base, target, flow, config) {
  const page = await context.newPage();
  page.setDefaultTimeout(config.timeoutMs);
  let response = null;
  const url = (p) => normalizeBase(base) + p;
  if (flow) {
    for (const step of flow.steps) {
      if (step.goto) response = await page.goto(url(step.goto), { waitUntil: 'networkidle' });
      if (step.click) await page.click(step.click);
      if (step.fill) await page.fill(step.fill, String(step.value ?? ''));
      if (step.select) await page.selectOption(step.select, String(step.value ?? ''));
      if (step.waitFor) await page.waitForSelector(step.waitFor);
      if (step.click || step.fill || step.select) await page.waitForLoadState('networkidle');
    }
  } else {
    response = await page.goto(url(target), { waitUntil: 'networkidle' });
  }
  await page.evaluate(() => document.fonts?.ready).catch(() => {});
  await page.waitForTimeout(config.settleMs);
  return { page, response };
}

async function screenshot(page, config) {
  return page.screenshot({
    fullPage: config.fullPage,
    animations: 'disabled',
    caret: 'hide',
    mask: config.mask.map((selector) => page.locator(selector)),
  });
}

export async function runParity(config) {
  await fs.mkdir(path.join(config.out, 'img'), { recursive: true });
  const targets = [
    ...(await collectPaths(config)).map((p) => ({ name: p, baselinePath: p, candidatePath: mapPath(p, config.urlMap) })),
    ...config.flows.map((flow) => ({ name: `flow:${flow.name}`, flow, candidateFlow: {
      ...flow, steps: flow.steps.map((s) => (s.goto ? { ...s, goto: mapPath(s.goto, config.urlMap) } : s)),
    } })),
  ];
  const browser = await chromium.launch();
  const diffPage = await browser.newPage();
  const pages = [];
  try {
    for (const [index, target] of targets.entries()) {
      const entry = {
        name: target.name,
        baseline_url: normalizeBase(config.baseline) + (target.baselinePath || ''),
        candidate_url: normalizeBase(config.candidate) + (target.candidatePath || ''),
        semantic: [], shots: [], status: 'match',
      };
      try {
        for (const [vIndex, viewport] of config.viewports.entries()) {
          const contextOptions = (auth) => ({
            viewport: { width: viewport.width, height: viewport.height }, isMobile: !!viewport.mobile, hasTouch: !!viewport.mobile,
            deviceScaleFactor: 1, reducedMotion: 'reduce', extraHTTPHeaders: authHeader(auth), locale: config.locale || 'sk-SK',
          });
          const baseContext = await browser.newContext(contextOptions(config.baselineAuth));
          const candContext = await browser.newContext(contextOptions(config.candidateAuth));
          try {
            const a = await visit(baseContext, config.baseline, target.baselinePath, target.flow, config);
            const b = await visit(candContext, config.candidate, target.candidatePath, target.candidateFlow, config);
            if (vIndex === 0) {
              const semA = await extractSemantics(a.page, a.response, config.baseline, config);
              const semB = await extractSemantics(b.page, b.response, config.candidate, config);
              entry.semantic = compareSemantics(semA, semB, mapPath(semA.final_path, config.urlMap)).map((diff) => {
                const rule = approval(config, target.name, diff.field);
                return { ...diff, approved: !!rule, reason: rule?.reason || '' };
              });
            }
            const [shotA, shotB] = [await screenshot(a.page, config), await screenshot(b.page, config)];
            const pixel = await pixelDiff(diffPage, shotA, shotB, config.pixel.threshold);
            const stem = `img/${String(index).padStart(4, '0')}-${viewport.name}`;
            await fs.writeFile(path.join(config.out, `${stem}-baseline.png`), shotA);
            await fs.writeFile(path.join(config.out, `${stem}-candidate.png`), shotB);
            await fs.writeFile(path.join(config.out, `${stem}-diff.png`), Buffer.from(pixel.diff_png, 'base64'));
            const rule = pixel.ratio > config.pixel.minor ? approval(config, target.name, 'pixel', viewport.name, pixel.ratio) : null;
            entry.shots.push({
              viewport: viewport.name, ratio: pixel.ratio, size_changed: pixel.size_changed, approved: !!rule, reason: rule?.reason || '',
              baseline: `${stem}-baseline.png`, candidate: `${stem}-candidate.png`, diff: `${stem}-diff.png`,
            });
          } finally {
            await baseContext.close();
            await candContext.close();
          }
        }
        entry.status = classify(entry, config);
      } catch (error) {
        entry.status = 'error';
        entry.error = String(error?.stack || error);
      }
      pages.push(entry);
      process.stdout.write(`${entry.status.padEnd(5)} ${entry.name}\n`);
    }
  } finally {
    await browser.close();
  }

  const count = (status) => pages.filter((p) => p.status === status).length;
  const unapproved = pages.reduce((sum, p) => sum + (p.status === 'error' ? 1 : 0)
    + p.semantic.filter((d) => !d.approved).length
    + p.shots.filter((s) => s.ratio > config.pixel.minor && !s.approved).length, 0);
  const approved = pages.reduce((sum, p) => sum + p.semantic.filter((d) => d.approved).length + p.shots.filter((s) => s.approved).length, 0);
  const result = {
    generated_at: new Date().toISOString(),
    config: { baseline: config.baseline, candidate: config.candidate, viewports: config.viewports, pixel: config.pixel },
    summary: { pages: pages.length, match: count('match'), minor: count('minor'), major: count('major'), error: count('error'), unapproved, approved },
    pages,
  };
  await fs.writeFile(path.join(config.out, 'report.json'), JSON.stringify(result, null, 2));
  await fs.writeFile(path.join(config.out, 'index.html'), renderReport(result));
  return result;
}

function classify(entry, config) {
  if (entry.semantic.some((d) => !d.approved)) return 'major';
  const open = entry.shots.filter((s) => !s.approved).map((s) => s.ratio);
  if (open.some((r) => r > config.pixel.major)) return 'major';
  if (open.some((r) => r > config.pixel.minor)) return 'minor';
  return 'match';
}

if (import.meta.url === pathToFileURL(process.argv[1] || '').href) {
  const arg = process.argv.find((a) => a.startsWith('--config='));
  const file = arg ? arg.slice(9) : 'tests/parity/parity.config.json';
  loadConfig(file)
    .then(async (config) => {
      const s = (await runParity(config)).summary;
      console.log(`\n${s.pages} pages: ${s.match} match, ${s.minor} minor, ${s.major} major, ${s.error} error; unapproved=${s.unapproved}, approved=${s.approved}`);
      console.log(`Report: ${path.join(config.out, 'index.html')}`);
      process.exit(s.unapproved === 0 ? 0 : 1);
    })
    .catch((error) => { console.error(error); process.exit(2); });
}
