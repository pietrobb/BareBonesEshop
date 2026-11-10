<?php
const BBE_CLI_KEEP_ROOT = true; // R27: CI runs the root paths' scenarios as root; cli-only.php drops root for every step
require_once __DIR__ . '/cli-only.php';

/** BareBones Shop — thin test runner entrypoint. */
require __DIR__ . '/TestSuite.php';
