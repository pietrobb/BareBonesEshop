// Semantic page fingerprint: what search engines, customers and scripts actually depend on.
import { relativePath } from './urls.mjs';

export async function extractSemantics(page, response, base, options = {}) {
  let first = response?.request() || null;
  while (first?.redirectedFrom()) first = first.redirectedFrom();
  const firstResponse = first ? await first.response() : null;

  const raw = await page.evaluate(({ priceSelectors, availabilitySelectors }) => {
    const text = (el) => (el?.textContent || '').replace(/\s+/g, ' ').trim();
    const attr = (selector, name) => document.querySelector(selector)?.getAttribute(name) || '';
    const all = (selectors) => selectors.flatMap((s) => [...document.querySelectorAll(s)])
      .map((el) => (el.getAttribute('content') || el.getAttribute('href') || text(el)).trim())
      .filter(Boolean);
    const jsonld = [...document.querySelectorAll('script[type="application/ld+json"]')].map((s) => {
      try { return JSON.parse(s.textContent || ''); } catch { return { invalid: true }; }
    });
    return {
      title: document.title.trim(),
      description: attr('meta[name="description"]', 'content'),
      canonical: attr('link[rel="canonical"]', 'href'),
      robots: attr('meta[name="robots"]', 'content'),
      hreflang: [...document.querySelectorAll('link[rel="alternate"][hreflang]')]
        .map((l) => `${l.getAttribute('hreflang')}=${l.href}`),
      h1: [...document.querySelectorAll('h1')].map(text),
      prices: all(priceSelectors),
      availability: all(availabilitySelectors),
      jsonld,
      links: [...document.querySelectorAll('a[href]')].map((a) => a.href),
      buttons: [...document.querySelectorAll('button, input[type=submit], [role=button]')]
        .map((b) => (b.value && b.tagName === 'INPUT' ? b.value : text(b))).filter(Boolean),
    };
  }, {
    priceSelectors: options.priceSelectors || ['[itemprop=price]', '.price'],
    availabilitySelectors: options.availabilitySelectors || ['[itemprop=availability]', '.availability'],
  });

  const rel = (u) => relativePath(u, base);
  const links = [...new Set(raw.links.map(rel).filter((u) => !u.startsWith('#')))].sort();
  return {
    status: firstResponse ? firstResponse.status() : (response ? response.status() : 0),
    final_status: response ? response.status() : 0,
    final_path: rel(page.url()),
    title: raw.title,
    description: raw.description,
    canonical: rel(raw.canonical),
    robots: raw.robots,
    hreflang: raw.hreflang.map((h) => { const [lang, ...href] = h.split('='); return `${lang}=${rel(href.join('='))}`; }).sort(),
    h1: raw.h1,
    prices: raw.prices,
    availability: raw.availability,
    jsonld: JSON.parse(JSON.stringify(raw.jsonld).split(base.replace(/\/+$/, '')).join('')),
    link_count: links.length,
    links,
    buttons: raw.buttons,
  };
}

const FIELDS = ['status', 'final_status', 'final_path', 'title', 'description', 'canonical', 'robots', 'hreflang',
  'h1', 'prices', 'availability', 'jsonld', 'link_count', 'links', 'buttons'];

export function compareSemantics(baseline, candidate, mappedFinalPath) {
  const diffs = [];
  for (const field of FIELDS) {
    let expected = baseline[field];
    const actual = candidate[field];
    if (field === 'final_path' && mappedFinalPath) expected = mappedFinalPath;
    if (JSON.stringify(expected) === JSON.stringify(actual)) continue;
    const diff = { field, baseline: expected, candidate: actual };
    if (field === 'links') {
      diff.baseline = expected.filter((l) => !actual.includes(l));
      diff.candidate = actual.filter((l) => !expected.includes(l));
    }
    diffs.push(diff);
  }
  return diffs;
}
