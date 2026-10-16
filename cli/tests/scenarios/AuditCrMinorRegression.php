<?php

// CR-D (code review 2026-10-15): small findings that need no suite database.
echo "\n--- CR-D: minor code review findings ---\n";
$crdRoot = test_fixture_path('cr-minor');
mkdir($crdRoot, 0700, true);
$crdRun = static function (array $args) use ($crdRoot): array {
    $process = proc_open(array_merge([PHP_BINARY], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $crdRoot);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out];
};

// Unknown admin usernames were verified against a hard-coded cost-10 bcrypt hash while real
// accounts use PASSWORD_DEFAULT (cost 12 on PHP 8.4+): a ~4x faster "no such user" answer.
$crdDummy = new ReflectionMethod(AdminIdentity::class, 'dummyPasswordHash');
$crdDummyInfo = password_get_info($crdDummy->invoke(null));
$crdRealInfo = password_get_info(password_hash('cr-d-probe', PASSWORD_DEFAULT));
assert_equals($crdRealInfo['algo'], $crdDummyInfo['algo'], 'CR-D admin login: unknown-user dummy hash uses the PASSWORD_DEFAULT algorithm');
assert_equals($crdRealInfo['options'], $crdDummyInfo['options'], 'CR-D admin login: unknown-user dummy hash has the same cost as real admin hashes');
assert_true(!password_needs_rehash($crdDummy->invoke(null), PASSWORD_DEFAULT), 'CR-D admin login: dummy hash would not be rehashed by PASSWORD_DEFAULT');

// The worker opened its lock with fopen('w+') BEFORE flock: a second worker that lost the race
// had already truncated the running worker's PID, and the exit path unlinked the lock file.
// Run the real cli/worker.php (isolated copy, own jobs_dir) while this process holds the lock.
mkdir($crdRoot . '/worker/cli', 0700, true);
mkdir($crdRoot . '/worker/src', 0700, true);
mkdir($crdRoot . '/worker/jobs', 0700, true);
file_put_contents($crdRoot . '/worker/config.php', '<?php return ' . var_export(['jobs_dir' => $crdRoot . '/worker/jobs', 'queue' => ['backend' => 'file']], true) . ';');
file_put_contents($crdRoot . '/worker/src/autoload.php', '<?php require ' . var_export(dirname(TEST_CLI_DIR) . '/src/autoload.php', true) . ';'
    . ' (new ReflectionProperty(JobQueue::class, "forceFile"))->setValue(null, true);');
copy(TEST_CLI_DIR . '/cli-only.php', $crdRoot . '/worker/cli/cli-only.php');
copy(TEST_CLI_DIR . '/worker.php', $crdRoot . '/worker/cli/worker.php');
$crdLockFile = $crdRoot . '/worker/jobs/worker.lock';
$crdHeld = fopen($crdLockFile, 'c+');
flock($crdHeld, LOCK_EX);
fwrite($crdHeld, '424242');
fflush($crdHeld);
[$crdExit, $crdOut] = $crdRun([$crdRoot . '/worker/cli/worker.php', '--once', '--verbose']);
assert_true($crdExit === 0 && str_contains($crdOut, 'already running'), 'CR-D worker lock: a second worker backs off while the lock is held');
rewind($crdHeld);
assert_equals('424242', (string)stream_get_contents($crdHeld), 'CR-D worker lock: the losing worker does not wipe the running worker PID');
flock($crdHeld, LOCK_UN);
fclose($crdHeld);
[$crdExit, $crdOut] = $crdRun([$crdRoot . '/worker/cli/worker.php', '--once', '--verbose']);
assert_true($crdExit === 0 && str_contains($crdOut, 'Worker done'), 'CR-D worker lock: the worker runs once the lock is free');
assert_true(is_file($crdLockFile), 'CR-D worker lock: the lock file is kept (unlinking it lets two workers hold "the" lock)');
$crdProbe = fopen($crdLockFile, 'c+');
assert_true(flock($crdProbe, LOCK_EX | LOCK_NB), 'CR-D worker lock: the finished worker released the lock');
fclose($crdProbe);

// Cache::write ignored a short write (disk full) and renamed the truncated .tmp over the last good
// entry. An in-memory stream wrapper that accepts only a few bytes per write simulates a full disk.
if (!class_exists('CrdFullDiskStream', false)) {
    final class CrdFullDiskStream {
        public static array $files = [];
        public static ?int $budget = null;
        public $context;
        private string $path = '';
        private string $mode = '';
        private int $pos = 0;
        public function stream_open(string $path, string $mode): bool { $this->path = $path; $this->mode = $mode; if ($mode[0] !== 'r') self::$files[$path] = ''; elseif (!isset(self::$files[$path])) return false; return true; }
        public function stream_write(string $data): int { $n = self::$budget === null ? strlen($data) : max(0, min(strlen($data), self::$budget - strlen(self::$files[$this->path]))); self::$files[$this->path] .= substr($data, 0, $n); return $n; }
        public function stream_read(int $count): string { $chunk = substr(self::$files[$this->path], $this->pos, $count); $this->pos += strlen($chunk); return $chunk; }
        public function stream_eof(): bool { return $this->pos >= strlen(self::$files[$this->path]); }
        public function stream_lock(int $operation): bool { return true; }
        public function stream_truncate(int $size): bool { self::$files[$this->path] = substr(self::$files[$this->path], 0, $size); return true; }
        public function stream_set_option(int $option, int $arg1, ?int $arg2): bool { return false; }
        public function stream_flush(): bool { return true; }
        public function stream_close(): void {}
        public function stream_stat(): array { return ['size' => strlen(self::$files[$this->path]), 'mode' => 0100644, 'mtime' => time()]; }
        public function url_stat(string $path, int $flags): array|false {
            if (isset(self::$files[$path])) return ['size' => strlen(self::$files[$path]), 'mode' => 0100644, 'mtime' => time()];
            foreach (array_keys(self::$files) as $file) if (str_starts_with($file, rtrim($path, '/') . '/')) return ['mode' => 040755, 'mtime' => time()];
            return false;
        }
        public function mkdir(string $path, int $mode, int $options): bool { return true; }
        public function rename(string $from, string $to): bool { if (!isset(self::$files[$from])) return false; self::$files[$to] = self::$files[$from]; unset(self::$files[$from]); return true; }
        public function unlink(string $path): bool { unset(self::$files[$path]); return true; }
    }
}
stream_wrapper_register('crdfull', CrdFullDiskStream::class);
$crdCacheDir = new ReflectionProperty(Cache::class, 'cacheDir');
$crdOldCacheDir = $crdCacheDir->getValue();
try {
    Cache::setDir('crdfull://c');
    Cache::write('cr-d/entry.json', '{"good":true,"items":[1,2,3]}');
    assert_equals('{"good":true,"items":[1,2,3]}', Cache::read('cr-d/entry.json'), 'CR-D cache: fixture entry written through the simulated disk');
    CrdFullDiskStream::$budget = 8;
    $crdFullDiskWarning = null;
    try {
        Cache::write('cr-d/entry.json', '{"good":true,"items":[1,2,3,4,5,6,7,8,9]}');
    } catch (ErrorException $crdFullDiskException) {
        $crdFullDiskWarning = $crdFullDiskException->getMessage();
    }
    CrdFullDiskStream::$budget = null;
    assert_equals('{"good":true,"items":[1,2,3]}', Cache::read('cr-d/entry.json'), 'CR-D cache: a short write (disk full) keeps the last good entry instead of a truncated one');
    assert_equals([], array_values(array_filter(array_keys(CrdFullDiskStream::$files), static fn(string $f): bool => str_contains($f, '.tmp.'))), 'CR-D cache: a failed write leaves no .tmp file behind');
    assert_equals(null, $crdFullDiskWarning, 'CR-D cache: a failed cache write is logged, not raised into the request');
} finally {
    $crdCacheDir->setValue(null, $crdOldCacheDir);
    stream_wrapper_unregister('crdfull');
}

// SmtpMail sent no Message-ID, put bodies on the wire as 8bit with whatever line ends the
// template had (bare LF is refused by Postfix/Exchange) and one-line HTML longer than the
// 998-octet SMTP limit, and wrote the subject as a single unbounded encoded-word.
// A local fake SMTP server records exactly what goes over the socket.
file_put_contents($crdRoot . '/smtp.php', <<<'PHP'
<?php
[$_, $portFile, $dataFile] = $argv;
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
file_put_contents($portFile, (string)parse_url('tcp://' . stream_socket_get_name($server, false), PHP_URL_PORT));
$conn = stream_socket_accept($server, 20);
if (!$conn) exit(1);
fwrite($conn, "220 crd ESMTP\r\n");
while (($line = fgets($conn)) !== false) {
    $verb = strtoupper(substr(trim($line), 0, 4));
    if ($verb === 'EHLO' || $verb === 'MAIL' || $verb === 'RCPT') { fwrite($conn, "250 ok\r\n"); continue; }
    if ($verb === 'QUIT') { fwrite($conn, "221 bye\r\n"); break; }
    if ($verb === 'DATA') {
        fwrite($conn, "354 go\r\n");
        $raw = '';
        while (!str_ends_with($raw, "\r\n.\r\n") && ($chunk = fgets($conn)) !== false) $raw .= $chunk;
        file_put_contents($dataFile, $raw);
        fwrite($conn, "250 queued\r\n");
    }
}
PHP);
$crdSmtpPortFile = $crdRoot . '/smtp.port';
$crdSmtpData = $crdRoot . '/smtp.data';
$crdSmtp = proc_open([PHP_BINARY, $crdRoot . '/smtp.php', $crdSmtpPortFile, $crdSmtpData], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $crdSmtpPipes);
for ($i = 0; $i < 100 && !is_file($crdSmtpPortFile); $i++) usleep(50000);
$crdSubject = 'Potvrdenie objednávky č. 2026-000123 — ďakujeme za nákup v obchode BareBones, tešíme sa na ďalšiu návštevu';
$crdHtml = '<html><body>' . str_repeat('<p>Položka Žltý kôň • 12,90 €</p>', 80) . "</body></html>\n";
$crdText = "Dobrý deň,\nďakujeme.\n.\nRiadok s bodkou vyššie.\n";
$crdSent = (new SmtpMail(['smtp_host' => '127.0.0.1', 'smtp_port' => (int)@file_get_contents($crdSmtpPortFile), 'smtp_encryption' => 'none', 'smtp_timeout' => 5]))
    ->send(['to' => 'buyer@example.test', 'from' => 'shop@shop.example.test', 'subject' => $crdSubject, 'html' => $crdHtml, 'text' => $crdText]);
fclose($crdSmtpPipes[0]);
stream_get_contents($crdSmtpPipes[1]);
fclose($crdSmtpPipes[1]);
fclose($crdSmtpPipes[2]);
proc_close($crdSmtp);
$crdWire = (string)@file_get_contents($crdSmtpData);
assert_equals('sent', $crdSent['status'] ?? null, 'CR-D smtp: message accepted by the local SMTP server');
[$crdWireHead, $crdWireBody] = array_pad(explode("\r\n\r\n", $crdWire, 2), 2, '');
assert_true(preg_match('/^Message-ID: <[0-9a-f]{32}@shop\.example\.test>\r$/mi', $crdWireHead) === 1, 'CR-D smtp: a Message-ID with the sender domain is generated');
assert_equals(0, preg_match('/(?<!\r)\n/', $crdWire), 'CR-D smtp: every line on the wire ends with CRLF (no bare LF)');
assert_equals(0, count(array_filter(explode("\r\n", $crdWire), static fn(string $l): bool => strlen($l) > 998)), 'CR-D smtp: no line exceeds the 998-octet SMTP limit');
assert_equals(0, count(array_filter(explode("\r\n", $crdWireHead), static fn(string $l): bool => strlen($l) > 78)), 'CR-D smtp: header lines are folded (long UTF-8 subject)');
$crdDecodedSubject = '';
if (preg_match('/^Subject: ([^\r\n]*(?:\r\n[ \t][^\r\n]*)*)/m', $crdWireHead, $crdSubjectMatch)) $crdDecodedSubject = (string)iconv_mime_decode('Subject: ' . $crdSubjectMatch[1], 0, 'UTF-8');
$crdDecodedSubject = substr($crdDecodedSubject, strlen('Subject: '));
assert_equals($crdSubject, $crdDecodedSubject, 'CR-D smtp: folded subject decodes back to the original');
$crdParts = [];
foreach (preg_split('/\r\n--bbe-alt-[0-9a-f]+(?:--)?\r\n/', "\r\n" . $crdWireBody) as $crdPart) {
    if (!preg_match('/^Content-Type: text\/(plain|html); charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n(.*)$/s', $crdPart, $m)) continue;
    $crdParts[$m[1]] = quoted_printable_decode(str_replace("\r\n..", "\r\n.", $m[2]));
}
assert_equals(str_replace("\n", "\r\n", $crdText), $crdParts['plain'] ?? null, 'CR-D smtp: plain text part decodes back exactly (CRLF line ends)');
assert_equals(str_replace("\n", "\r\n", $crdHtml), $crdParts['html'] ?? null, 'CR-D smtp: HTML part decodes back exactly');

// Updater moved the live file aside (rename target -> .old) and only then renamed the staged file
// in: in between, live requests found no file. A reader process polls the target while files are
// committed; on the old code it saw the file missing (Linux: most polls; Windows: ~4%).
$crdUpdDir = $crdRoot . '/updater';
mkdir($crdUpdDir, 0700, true);
file_put_contents($crdUpdDir . '/live.php', '<?php return -1;');
file_put_contents($crdRoot . '/reader.php', '<?php $t = $argv[1] . "/live.php"; $d = $argv[1] . "/done"; $n = 0; $miss = 0;'
    . ' while (!is_file($d)) { $n++; clearstatcache(); if (!is_file($t)) $miss++; } echo "$n $miss";');
$crdReader = proc_open([PHP_BINARY, $crdRoot . '/reader.php', $crdUpdDir], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $crdReaderPipes);
usleep(200000);
$crdUpdater = (new ReflectionClass(Updater::class))->newInstanceWithoutConstructor();
$crdCommit = new ReflectionMethod(Updater::class, 'commitStagedFile');
$crdCommitErrors = 0;
for ($i = 0; $i < 300; $i++) {
    $crdTmp = $crdUpdDir . '/.live.php.update-' . bin2hex(random_bytes(6)) . '.tmp';
    file_put_contents($crdTmp, "<?php return {$i};");
    try { $crdCommit->invoke($crdUpdater, $crdTmp, $crdUpdDir . '/live.php'); } catch (RuntimeException $e) { $crdCommitErrors++; }
}
touch($crdUpdDir . '/done');
fclose($crdReaderPipes[0]);
[$crdChecks, $crdMissing] = array_map('intval', explode(' ', trim((string)stream_get_contents($crdReaderPipes[1])) . ' -1 -1'));
fclose($crdReaderPipes[1]);
fclose($crdReaderPipes[2]);
proc_close($crdReader);
assert_equals(0, $crdCommitErrors, 'CR-D updater: every staged file is committed');
assert_true($crdChecks > 0 && $crdMissing === 0, "CR-D updater: the live file never disappears while updates are committed ({$crdMissing} of {$crdChecks} polls missed it)");
assert_equals('<?php return 299;', (string)file_get_contents($crdUpdDir . '/live.php'), 'CR-D updater: the last committed file is live');
assert_equals(['done', 'live.php'], array_values(array_diff(scandir($crdUpdDir), ['.', '..'])), 'CR-D updater: no .old or .tmp files are left next to the live file');
