// Bookshop package acceptance against an already running install target (tests/bookshop/run.sh starts it).
const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
  testDir: __dirname,
  fullyParallel: false,
  forbidOnly: Boolean(process.env.CI),
  retries: 0,
  workers: 1,
  reporter: process.env.CI ? [['line'], ['html', { open: 'never', outputFolder: '../../playwright-report/bookshop' }]] : 'line',
  timeout: 90_000,
  expect: { timeout: 10_000 },
  outputDir: '../../test-results/bookshop',
  use: {
    baseURL: process.env.BOOKSHOP_URL || 'http://127.0.0.1:8740',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
