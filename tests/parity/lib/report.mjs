// Single self-contained HTML report (images referenced from ./img).

const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const show = (value) => esc(typeof value === 'string' ? value : JSON.stringify(value, null, 1));
const LABEL = { match: 'zhoda / match', minor: 'malý rozdiel / minor', major: 'veľký rozdiel / major', error: 'chyba / error' };

export function renderReport(result) {
  const { summary, pages, config } = result;
  const rows = pages.map((p, i) => `<tr class="${p.status}"><td><a href="#p${i}">${esc(p.name)}</a></td><td>${esc(LABEL[p.status])}</td>`
    + `<td>${p.semantic.filter((d) => !d.approved).length}</td>`
    + `<td>${p.shots.map((s) => `${esc(s.viewport)} ${(s.ratio * 100).toFixed(3)}%${s.approved ? ' ✓' : ''}`).join('<br>')}</td></tr>`).join('');
  const sections = pages.map((p, i) => `<section id="p${i}" class="${p.status}"><h2>${esc(p.name)} — ${esc(LABEL[p.status])}</h2>`
    + `<p class="muted">${esc(p.baseline_url)} → ${esc(p.candidate_url)}</p>`
    + (p.error ? `<pre class="err">${esc(p.error)}</pre>` : '')
    + (p.semantic.length ? `<table><tr><th>Pole</th><th>Ostrý web</th><th>Kandidát</th><th></th></tr>${p.semantic.map((d) => `<tr><td>${esc(d.field)}</td><td><pre>${show(d.baseline)}</pre></td><td><pre>${show(d.candidate)}</pre></td><td>${d.approved ? 'schválené: ' + esc(d.reason) : '<b>neschválené</b>'}</td></tr>`).join('')}</table>` : '<p>Sémantika: zhoda.</p>')
    + p.shots.map((s) => `<h3>${esc(s.viewport)} — ${(s.ratio * 100).toFixed(3)}% pixelov${s.size_changed ? ', iná veľkosť' : ''}${s.approved ? ' (schválené)' : ''}</h3>`
      + `<div class="shots"><figure><img loading="lazy" src="${esc(s.baseline)}"><figcaption>ostrý web</figcaption></figure>`
      + `<figure><img loading="lazy" src="${esc(s.candidate)}"><figcaption>kandidát</figcaption></figure>`
      + `<figure><img loading="lazy" src="${esc(s.diff)}"><figcaption>rozdiel</figcaption></figure></div>`).join('')
    + '</section>').join('');
  return `<!doctype html><html lang="sk"><head><meta charset="utf-8"><title>Parity report</title><style>
body{font:14px system-ui,sans-serif;margin:24px;color:#222}table{border-collapse:collapse;width:100%;margin:8px 0}
td,th{border:1px solid #ddd;padding:4px 6px;vertical-align:top;text-align:left}pre{margin:0;white-space:pre-wrap;max-height:240px;overflow:auto;font-size:12px}
.match td:nth-child(2){color:#1b5e20}.minor td:nth-child(2){color:#b45309}.major td:nth-child(2),.error td:nth-child(2){color:#b91c1c;font-weight:600}
.shots{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}.shots img{width:100%;border:1px solid #ccc}figure{margin:0}.muted{color:#666}
.verdict{font-size:20px;padding:12px;border-radius:6px}.ok{background:#e8f5e9}.ko{background:#fdecea}section{border-top:2px solid #eee;margin-top:24px}
</style></head><body><h1>Porovnanie: ostrý web vs. kandidát</h1>
<p class="muted">${esc(config.baseline)} → ${esc(config.candidate)} · ${esc(result.generated_at)}</p>
<p class="verdict ${summary.unapproved === 0 ? 'ok' : 'ko'}">${summary.unapproved === 0 ? 'ZELENÉ — 0 neschválených rozdielov' : `ČERVENÉ — ${summary.unapproved} neschválených rozdielov`}</p>
<p>Stránky: ${summary.pages} · zhoda ${summary.match} · malý rozdiel ${summary.minor} · veľký rozdiel ${summary.major} · chyby ${summary.error} · schválené rozdiely ${summary.approved}</p>
<table><tr><th>Stránka</th><th>Výsledok</th><th>Neschválené sémantické</th><th>Pixely</th></tr>${rows}</table>${sections}</body></html>`;
}
