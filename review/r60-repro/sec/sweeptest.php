<?php
require $argv[1].'/src/autoload.php';
$tmp = $argv[2];
putenv("TMPDIR=$tmp"); $_SERVER['TMPDIR']=$tmp;
// make two leftover dirs, mtime old
foreach (['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'] as $h){
  $d="$tmp/bbe-backup-$h"; @mkdir($d,0700); file_put_contents("$d/.owner",''); touch($d, time()-120); touch("$d/.owner", time()-120);
}
// Hold LOCK_EX on dir A's .owner in THIS process (simulates a running restore)
$held = fopen("$tmp/bbe-backup-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/.owner",'rb'); flock($held, LOCK_EX|LOCK_NB);
// Run sweep via reflection
$m = new ReflectionMethod('BackupService','sweepStaging'); $m->setAccessible(true); $m->invoke(null);
echo "A (locked) exists: ", is_dir("$tmp/bbe-backup-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa")?"YES":"NO", "\n";
echo "B (free)   exists: ", is_dir("$tmp/bbe-backup-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb")?"YES":"NO", "\n";
