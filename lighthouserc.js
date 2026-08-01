module.exports = {
  ci: {
    collect: {
      startServerCommand: 'php -S 127.0.0.1:8080 -t .',
      startServerReadyPattern: 'Development Server',
      url: [
        'http://127.0.0.1:8080/',
        'http://127.0.0.1:8080/?page=cart',
        'http://127.0.0.1:8080/account.php?p=login',
      ],
      numberOfRuns: 2,
      settings: {
        chromeFlags: '--headless --no-sandbox',
        preset: 'desktop',
      },
    },
    assert: {
      assertions: {
        'categories:accessibility': ['error', { minScore: 0.9 }],
        'categories:best-practices': ['error', { minScore: 0.9 }],
        'categories:performance': ['error', { minScore: 0.75 }],
        'first-contentful-paint': ['error', { maxNumericValue: 2500 }],
        'largest-contentful-paint': ['error', { maxNumericValue: 3500 }],
        'cumulative-layout-shift': ['error', { maxNumericValue: 0.1 }],
        'total-blocking-time': ['error', { maxNumericValue: 300 }]
      },
    },
    upload: { target: 'filesystem', outputDir: '.lighthouseci' },
  },
};
