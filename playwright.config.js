const { defineConfig, devices } = require('@playwright/test');

const baseURL = process.env.E2E_BASE_URL || 'http://127.0.0.1:8080';
const gateway = new URL(process.env.E2E_GATEWAY_URL || 'http://127.0.0.1:8099');

module.exports = defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  forbidOnly: Boolean(process.env.CI),
  retries: 0,
  workers: 1,
  reporter: process.env.CI ? [['line'], ['html', { open: 'never' }]] : 'line',
  timeout: 45_000,
  expect: { timeout: 10_000 },
  use: {
    baseURL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  // The checkout critical path must pass in every engine customers use (P0 2026-10-13:
  // the failing customer was on iPhone Safari = WebKit). `--project=<name>` narrows a local run.
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    // Admin specs consume one-shot seeded state (fulfillment, RMA) and target desktop, so they run once
    // in Chromium; iOS Safari does not Tab to links, so the keyboard-bypass check is desktop-only.
    { name: 'webkit-iphone', use: { ...devices['iPhone 13'] }, grepInvert: /\badmin \w|keyboard bypass/ },
    { name: 'firefox', use: { ...devices['Desktop Firefox'] }, grepInvert: /\badmin \w/ },
  ],
  webServer: [
    process.env.E2E_EXTERNAL_SERVER === '1' ? null : {
      command: 'php -S 127.0.0.1:8080 -t .',
      url: baseURL,
      reuseExistingServer: !process.env.CI,
      timeout: 30_000,
      stdout: 'pipe',
      stderr: 'pipe',
    },
    // Hosted payment page on a second origin (tests/e2e/mock-gateway.php).
    {
      command: `php -S ${gateway.host} tests/e2e/mock-gateway.php`,
      url: `${gateway.origin}/checkout`,
      reuseExistingServer: !process.env.CI,
      timeout: 30_000,
    },
  ].filter(Boolean),
});
