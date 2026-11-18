<?php

// Round 45 (ideas #42/#44/#28/#32): catalog cache entries were judged by whole-second file times. An entry rebuilt in
// the same second as the last catalog change was a miss on every read until the second passed (the flaky "G4 facets"
// query counts), and an entry built from rows read before a change but written a second later was served as fresh
// for the whole TTL.
echo "\n--- Audit round 45: catalog cache freshness follows the catalog version, not the second ---\n";

$r45cMarker = (new ReflectionMethod(Cache::class, 'catalogMarker'))->invoke(null, StoreContext::current()->catalogId());
$r45cPath = static fn(string $name): string => Cache::localePath('sk', 'r45-cache/' . $name . '-' . bin2hex(random_bytes(4)) . '.json');
$r45cSetMtime = static function (string $path, int $time): void {
    touch(Cache::path($path), $time);
    clearstatcache();
};

// Same second as the change: the rebuild after the miss is served.
Cache::catalogChanged();
clearstatcache();
$r45cSame = $r45cPath('same');
assert_true(Cache::readCatalogJson($r45cSame, 3600) === null, 'R45 cache: no entry yet is a miss');
Cache::writeJson($r45cSame, ['v' => 'built after the change']);
$r45cSetMtime($r45cSame, (int)filemtime($r45cMarker));
assert_equals(['v' => 'built after the change'], Cache::readCatalogJson($r45cSame, 3600),
    'R45 cache: an entry rebuilt after the last catalog change is served, also within the same second');

// The catalog changes while the entry is being built: a miss, even when it was written a second later.
$r45cRace = $r45cPath('race');
assert_true(Cache::readCatalogJson($r45cRace, 3600) === null, 'R45 cache: the race entry starts as a miss');
Cache::catalogChanged();
clearstatcache();
Cache::writeJson($r45cRace, ['v' => 'old rows']);
$r45cSetMtime($r45cRace, (int)filemtime($r45cMarker) + 1);
assert_true(Cache::readCatalogJson($r45cRace, 3600) === null,
    'R45 cache: an entry built from rows read before a catalog change is a miss, whatever second it was written in');
Cache::writeJson($r45cRace, ['v' => 'new rows']);
assert_equals(['v' => 'new rows'], Cache::readCatalogJson($r45cRace, 3600), 'R45 cache: the rebuild after that miss is served');

// Another change after the rebuild: a miss again.
Cache::catalogChanged();
clearstatcache();
$r45cSetMtime($r45cRace, (int)filemtime($r45cMarker) + 1);
assert_true(Cache::readCatalogJson($r45cRace, 3600) === null, 'R45 cache: a later catalog change makes the rebuilt entry a miss');

// Written without a miss first (cache warm-up, rebuild job): the whole-second rule still applies.
$r45cWarm = $r45cPath('warm');
Cache::writeJson($r45cWarm, ['v' => 'warm']);
$r45cSetMtime($r45cWarm, (int)filemtime($r45cMarker));
assert_true(Cache::readCatalogJson($r45cWarm, 3600) === null, 'R45 cache: an untagged entry from the second of the change is a miss');
$r45cLater = $r45cPath('warm-later');
Cache::writeJson($r45cLater, ['v' => 'warm']);
$r45cSetMtime($r45cLater, (int)filemtime($r45cMarker) + 1);
assert_equals(['v' => 'warm'], Cache::readCatalogJson($r45cLater, 3600), 'R45 cache: an untagged entry written after the change second is served');

// An untagged write replacing a tagged entry drops its stamp: it cannot inherit the earlier build's version.
$r45cSwap = $r45cPath('swap');
Cache::readCatalogJson($r45cSwap, 3600);
Cache::writeJson($r45cSwap, ['v' => 'tagged']);
assert_true(is_file(Cache::path($r45cSwap) . '.catalog'), 'R45 cache: a rebuild after a miss is stamped with the catalog version');
Cache::writeJson($r45cSwap, ['v' => 'untagged']);
assert_true(!is_file(Cache::path($r45cSwap) . '.catalog'), 'R45 cache: an untagged write removes the stamp of the entry it replaces');

// The stamp covers the content: an entry replaced by another writer is not served under someone else's stamp.
$r45cPair = $r45cPath('pair');
Cache::readCatalogJson($r45cPair, 3600);
Cache::writeJson($r45cPair, ['v' => 'one']);
file_put_contents(Cache::path($r45cPair), json_encode(['v' => 'other']));
$r45cSetMtime($r45cPair, (int)filemtime($r45cMarker));
assert_true(Cache::readCatalogJson($r45cPair, 3600) === null, 'R45 cache: an entry that does not match its stamp is a miss');

foreach ([$r45cSame, $r45cRace, $r45cWarm, $r45cLater, $r45cSwap, $r45cPair] as $r45cEntry) {
    @unlink(Cache::path($r45cEntry));
    @unlink(Cache::path($r45cEntry) . '.catalog');
}
