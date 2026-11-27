<?php
$root = $argv[1]; chdir($root); require $root . '/src/autoload.php';
$f = DataOverrides::storesPath(); echo basename(dirname($f)), '/', basename($f), ' ', filesize($f), " bytes\n";
$t = hrtime(true); $b = Bootstrap::stores(); printf("Bootstrap::stores(): %d stores, %.1f ms\n", count($b['stores'] ?? []), (hrtime(true)-$t)/1e6);
$t = hrtime(true); $l = I18n::detectLocale('/de/'); printf("I18n::detectLocale('/de/'): %s, %.1f ms (reads the file whole)\n", $l, (hrtime(true)-$t)/1e6);
$t = hrtime(true); $s = StoreSettings::load(); printf("StoreSettings::load(): %d stores, %.1f ms\n", count($s['stores'] ?? []), (hrtime(true)-$t)/1e6);
printf("peak %.0f MB\n", memory_get_peak_usage()/1048576);
