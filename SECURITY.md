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

Security fixes go to the `main` branch and the latest release. Update through the signed update
packages (`cli/update.php`, see [README](README.md#production-updates)) and read the
**Upgrade notes** in [CHANGELOG.md](CHANGELOG.md) before applying an update.

## Scope

In scope: the core (`src/`, `adapters/`, the entry points, `cli/`), the bundled default theme and
the sample deployment files in `deploy/`.

Out of scope: vulnerabilities that need a misconfigured server (for example serving the
repository root without `router.php`, the bundled `.htaccess` or the nginx sample), third-party
extensions, and findings that require an already compromised admin account.
