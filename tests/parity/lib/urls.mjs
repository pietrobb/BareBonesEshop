// URL inventory: fixed paths + baseline sitemap(s), and baseline-path -> candidate-path mapping.

export function normalizeBase(url) {
  return String(url || '').replace(/\/+$/, '');
}

/** Path relative to a site base ("https://x/test" + "/test/a?b" -> "/a?b"). Foreign hosts stay absolute. */
export function relativePath(url, base) {
  const normalizedBase = normalizeBase(base);
  if (!url) return '';
  if (url.startsWith(normalizedBase + '/') || url === normalizedBase) {
    return url.slice(normalizedBase.length) || '/';
  }
  try {
    const parsed = new URL(url, normalizedBase + '/');
    const baseUrl = new URL(normalizedBase + '/');
    if (parsed.origin === baseUrl.origin) {
      const basePath = baseUrl.pathname.replace(/\/$/, '');
      const pathname = basePath && parsed.pathname.startsWith(basePath) ? parsed.pathname.slice(basePath.length) || '/' : parsed.pathname;
      return pathname + parsed.search + parsed.hash;
    }
    return parsed.href;
  } catch {
    return url;
  }
}

export function mapPath(path, urlMap = []) {
  for (const rule of urlMap) {
    const pattern = new RegExp(rule.from);
    if (pattern.test(path)) return path.replace(pattern, rule.to);
  }
  return path;
}

async function fetchText(url, headers) {
  const response = await fetch(url, { headers, redirect: 'follow' });
  if (!response.ok) throw new Error(`${url} -> HTTP ${response.status}`);
  return response.text();
}

/** Paths from a sitemap or sitemap index (one nesting level), relative to the baseline. */
export async function sitemapPaths(sitemapUrl, base, headers = {}, depth = 0) {
  const xml = await fetchText(sitemapUrl, headers);
  const locs = [...xml.matchAll(/<loc>\s*([^<\s]+)\s*<\/loc>/gi)].map((m) => m[1].replace(/&amp;/g, '&'));
  if (/<sitemapindex/i.test(xml) && depth === 0) {
    const nested = [];
    for (const loc of locs) nested.push(...await sitemapPaths(loc, base, headers, 1));
    return nested;
  }
  return locs.map((loc) => relativePath(loc, base));
}

export async function collectPaths(config) {
  const paths = [...(config.paths || [])];
  if (config.sitemap !== false) {
    const sitemapUrl = config.sitemapUrl || `${normalizeBase(config.baseline)}/sitemap.xml`;
    try {
      paths.push(...await sitemapPaths(sitemapUrl, config.baseline, authHeader(config.baselineAuth)));
    } catch (error) {
      if (config.sitemap === true) throw error;
    }
  }
  const unique = [...new Set(paths.filter((p) => typeof p === 'string' && p.startsWith('/')))];
  return unique.slice(0, config.maxUrls || 1000);
}

export function authHeader(auth) {
  if (!auth?.username) return {};
  return { Authorization: 'Basic ' + Buffer.from(`${auth.username}:${auth.password || ''}`).toString('base64') };
}
