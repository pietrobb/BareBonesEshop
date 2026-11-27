# R60 review — repro scripts (main @ 1e5c1c0)

Review-only material for the instance that fixes the R60 findings. **Do not merge this folder into main**: it is not
part of the shop, `eslint .` fails on `acc/*.js`, and the scripts carry hard-coded local paths. Copy what you need
into a scratch directory and run it from there.

## Conventions

- Most PHP scripts take the shop tree as `$argv[1]` (`git archive <commit>` + `vendor/bbf` + `config.php` from
  `config.example.php`) and require `$argv[1]/src/autoload.php`.
- MySQL in the review ran in docker (`bbe-mysql`, 127.0.0.1:3306, root/`update-root`, user `bbe_ci`/`bbe-ci-pass`,
  DBs prefixed `bbe_r60_*`). These are throwaway local test credentials.
  `ops/bin/mysql`, `ops/bin/mysqldump` and `ops/bin/dockwrap.py` wrap `docker exec -i bbe-mysql …`.
- Some scripts have the review's scratch path hard-coded:
  `/tmp/claude-0/-home-user-BareBonesEshop/0d748840-4251-5787-841e-a11f82aad9b2/scratchpad/r60/<area>/`.
  Replace it with your own directory, e.g.
  `grep -rl /tmp/claude-0 . | xargs sed -i 's#/tmp/claude-0/[^ ]*/scratchpad/r60#'"$PWD"'#g'`.
- `brief.md` is the brief the review agents worked from (setup, rules, the R59 items claimed fixed).
- In `sec/logmask.php` the fake Stripe key literal is split (`'sk_' . 'live_…'`) only so that secret scanners do not
  flag it; it is not a real key.

## Finding → script

| # | Severity | Finding | Where | Repro |
|---|---|---|---|---|
| 1 | MEDIUM | A forged files backup restores `stores/stores.json` in place (attacker IBAN shown in bank-transfer instructions); `shipping.json` and `gdpr_salt` likewise. `stores` is not in `ASIDE_SOURCES`. | `src/BackupService.php:1516`, `:1885` | `sec/scen2.sh` + `sec/craft2.py` (spec with `stores/stores.json` holding `legal.iban`), restore `--files-only --yes` onto a shop without stores.json |
| 2 | LOW | Credit-note VAT 1 cent above the invoice's VAT: `capToUncredited()` caps net but never VAT. | `src/InvoiceService.php` ~395–416 | `money/rp60_vatover2.php` (SQLite + MySQL, deterministic; same output on 561b3e5); property test `money/cn_prop.php` seed 4, `money/cn_prop60.php` + `money/run_cnp60.sh`; checker `money/cn_check.php` |
| 3 | LOW | Admin order edit is now the deadlock victim vs. stock writes (13 % of edits fail); `ManualOrder::editItems` has no deadlock retry. | `src/Orders.php:300`, `src/Inventory.php:138`, `src/ManualOrder.php:147` | `money/dl60.sh <db> 30 diff:1 stock:2 edit:2` (driver `money/dl60.php`; also `editlc`, `hook` modes) |
| 4 | LOW | Shop-level copies of `account-page.html` lose all invoice links: view-model key `invoice` became `invoices`, no alias, no CHANGELOG note; theme-check says GO. | `src/AccountViewModel.php:113`, `:222` | Manual: put the 561b3e5 `themes/default/blocks/storefront/account-page.html` into `blocks/stores/default/storefront/`, open account orders/detail (`acc/` Playwright helpers: `acc/lib.js`, `acc/order.js`, `acc/ret.js`) |
| 5 | LOW | 2 MB stores.json cap only in `Bootstrap::stores()`; `I18n::stores()`, `StoreSettings::load()`, `Orders.php:134`, `MailService.php:365` read it whole. | `src/I18n.php:255` etc. | `ops/lab/storescap.php` (3.1 MB stores.json) |
| 6 | LOW | `shipping-import` always warns "zone eu: its countries differ" for a real 2.1.x file (its eu zone has no SK); the warning also points to Admin → zones, which cannot set these countries. Regression test builds its "2.1.2 file" from the new file. | `src/Shipping.php` `importSettings()` (~148), `cli/tests/scenarios/AuditRound60ShippingRegression.php:52-58` | `git show 9a02915:shipping.json` (hash d15f…), change one price, `php cli/setup.php shipping-import --from=…`; upgrade flow `ops/scripts/updsetup.sh` |
| 7 | LOW | `shipping-import` takes free-text delivery days that Admin → Shipping then refuses on every save. | `Shipping::methodSettings()` vs regex in `src/Entrypoints/Admin/ShippingFile.php` | `ops/lab/shipsave.php` |
| 8 | LOW | Legacy names with TAB/U+2028 or text delivery days lock the record; message says "code and name required". | `src/Entrypoints/Admin/Operations.php` `postedMethodName()` (538/557/586/660) | `ops/lab/names.php`, `acc/rawpost.js` |
| 9 | LOW | Doctor check `shipping.file_edited` has no `admin.doctor.*` key and no `FIX_PAGES` entry (raw English on the dashboard); `admin.shipping.file_edited` says "Standard/Express" in every language. | `src/Setup.php:929`, `src/SetupChecklist.php:11` | Edit shipping.json, open the admin dashboard in sk; `ops/lab/shippage.php` |
| 10 | LOW | Domain probe can exceed its 8 s budget (deadline checked only between domains, DNS lookups unbounded), now on every save while a domain fails. | `src/StoreSettings.php:362-366`, 398-435 | `ops/lab/domprobe.php` (resolver hanging 6 s → 12 s per save) |
| 11 | LOW | SQLite: a killed restore leaves `shop.sqlite.restore-<hex>.tmp` (full DB copy) next to the live DB; not swept. MySQL: `restore-recovery/pre-restore-*.sql` (personal data) never reported. | `src/BackupService.php:983`, `:1013` | `ops/scripts/killat.py`, `ops/scripts/killrestore.sh` |
| 12 | LOW | Restore UX: CLI-only English warning with a count, no file names/destinations; `written` counts set-aside files; `pruneAsideRuns` keeps 5 runs (set-aside DR jobs vanish). | `cli/restore.php:283`, `BackupService::pruneAsideRuns()` | `sec/legit.php`, `sec/scen2.sh` |
| 13 | LOW | Small items: "Refunded in full" button shows no amount; 100 % refunded by hand stays `partially_refunded`; return closed without payout leaves 5 % 16 cents short; hu privacy date "2026. 11. 26.."; staging sweep does not check uid/mode. | various | `money/rp60_keepmanual.php`, `money/rp60_short.php`, `acc/legal.js`, `sec/sweeptest.php` |

## Checks that passed (useful as regression guards)

- Cancel-rest VAT: `money/rp59_cancelrest.php`, `money/rp60_cancelmulti.php`.
- Capture under lock: `money/race_paid.php`, `money/race_stripe60.php`, `money/dl60.sh … hook:2`.
- Keep-outside refunds never over-refund: `money/cn_prop60.php`, `money/conc_close60.sh`, `money/rp60_keepmanual.php`.
- sales_daily: `money/sd59_big.php`, `money/sd59_partial.php`, `money/sd60_backdate.php`, `money/sd60_window.php`,
  `money/sd_prop.php`; MySQL perf `ops/scripts/perf-dash.php`, `ops/scripts/sd-reset.php`, seeds `ops/scripts/seed200k.sql`, `ops/scripts/seedsq.php`.
- Restore set-aside of mail.json/jobs: `sec/scen2.sh`, `sec/craft2.py`, `sec/mailcfg.php`; log masking `sec/lm.php`, `sec/logmask.php`.
- Shipping zones/prices: `acc/fship.js`, `acc/co.js`, `acc/coval.js`.
