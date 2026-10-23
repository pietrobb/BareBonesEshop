# Security policy

BareBones Shop handles payments, customer data and invoices, so security reports are taken seriously.

## Reporting a vulnerability

**Please do not open a public issue for a security problem.**

Report it privately through GitHub: open the repository's **Security** tab and choose
**Report a vulnerability** (private vulnerability reporting). Include:

- the affected version or commit,
- the steps to reproduce (a request, payload or test case is ideal),
- the impact you see (data exposure, payment bypass, privilege escalation, …).

You will get an acknowledgement as soon as possible. Once a fix is released, the advisory is
published with credit to the reporter unless you prefer to stay anonymous.

## Supported versions

Only the `main` branch is supported: security fixes land there and are not backported to
older releases. Fixes reach a tagged release only when the next release is cut from `main`,
so check [CHANGELOG.md](CHANGELOG.md) (**Unreleased** section) for fixes that are not tagged yet.

A release is installable through the updater (`cli/update.php`, see
[README](README.md#production-updates)) only if its GitHub release has a signed update package
attached; the package is verified against the Ed25519 key in `releases/update-public.key`
(rotation: README › *Signing key rotation*). Releases without an attached package — currently
including v2.0.0 — are source-only: deploy them from the tagged commit. Read the
**Upgrade notes** in [CHANGELOG.md](CHANGELOG.md) before any update.

## Scope

In scope: the core (`src/`, `adapters/`, the entry points, `cli/`), the bundled default theme and
the sample deployment files in `deploy/`.

Out of scope: vulnerabilities that need a misconfigured server (for example serving the
repository root without `router.php`, the bundled `.htaccess` or the nginx sample), third-party
extensions, and findings that require an already compromised admin account.
