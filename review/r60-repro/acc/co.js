// INST=xx node co.js ship pay [qty] [cheapest|priciest]
const L = require('./lib.js'); const cp = require('node:child_process');
const [ship, pay, qty, which] = process.argv.slice(2);
const slug = L.sql(`select slug from products where status='active' and slug not like '%ebook%' order by price ${which==='priciest'?'desc':'asc'} limit 1`).trim().split('\n')[1];
const zips = { sk: '81101', cs: '60200', hu: '1011', pl: '30-001' };
const cc = { sk: 'SK', cs: 'CZ', hu: 'HU', pl: 'PL' }[L.INST];
const o = { items: [['/product/' + slug, null, Number(qty||1)]], ship, pay, email: 'jana@example.com', name: 'Jana Test', city: 'Mesto', street: 'Ulica 5', phone: '+421905123456', overflow: 1, shot: process.env.SHOT || '', dry: !!process.env.DRY, country: process.env.CC || cc, zip: process.env.ZIP || zips[L.INST] };
const out = cp.spawnSync('node', [__dirname + '/order.js', JSON.stringify(o)], { encoding: 'utf8', env: process.env });
console.log(out.stdout.split('\n').filter(l => /PRESELECT|SHIP OPTS|SUMMARY|SHIP\/PAY|SHOWN|OVERFLOW|AFTER|DB LAST|^\d+\t|console|pageerror|http [45]|pickup field/.test(l)).join('\n')); console.log(out.stderr.slice(0, 1500));
