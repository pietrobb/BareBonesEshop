<?php

// Review round 13 (open from round 3): T6 update signing key rotation without an independent anchor and without
// revoking the old key; T7 restore as root chmod/chown by path (follows a symlink the web user swapped in);
// T8 backups without stores.json, the merchant's legal texts and the sold e-book files.
echo "\n--- Audit round 13: signing key rotation, restore ownership, backup completeness ---\n";

$r13oRm = static function (string $path) use (&$r13oRm): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') $r13oRm($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
};
$r13oRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bbe-r13o-' . bin2hex(random_bytes(4));
@mkdir($r13oRoot, 0700, true);
$r13oError = static function (callable $call): string {
    try { $call(); } catch (Throwable $e) { return $e->getMessage(); }
    return '';
};

// T6. The old key endorses the new one; a shop accepts only that, and revokes the old key on rotation.
$r13oHasRotation = class_exists('UpdateKeyRotation') && function_exists('sodium_crypto_sign_keypair');
assert_true($r13oHasRotation, 'R13 T6: update key rotation with an endorsement by the trusted key exists');
if ($r13oHasRotation) {
    $r13oPair = static function (): array {
        $pair = sodium_crypto_sign_keypair();
        return ['public' => 'base64:' . base64_encode(sodium_crypto_sign_publickey($pair)), 'secret' => 'base64:' . base64_encode(sodium_crypto_sign_secretkey($pair))];
    };
    $r13oOld = $r13oPair();
    $r13oNew = $r13oPair();
    $r13oEvil = $r13oPair();
    $r13oState = $r13oRoot . '/updates';
    mkdir($r13oState, 0700, true);
    try {
        foreach (['base' => "before\n", 'target' => "after\n"] as $r13oTree => $r13oContent) {
            mkdir($r13oRoot . "/{$r13oTree}/src", 0700, true);
            file_put_contents($r13oRoot . "/{$r13oTree}/src/Core.php", $r13oContent);
        }
        UpdatePackageBuilder::build($r13oRoot . '/base', $r13oRoot . '/target', $r13oRoot . '/pkg-old', '13.0.1', '13.0.0', $r13oOld['secret']);
        UpdatePackageBuilder::build($r13oRoot . '/base', $r13oRoot . '/target', $r13oRoot . '/pkg-new', '13.0.1', '13.0.0', $r13oNew['secret']);
        assert_equals('', $r13oError(static fn() => new UpdatePackage($r13oRoot . '/pkg-old', $r13oOld['public'], false, $r13oState)),
            'R13 T6: before the rotation a package signed with the trusted key verifies');

        // Whoever controls the repository can publish a key and a fake endorsement; the trusted key did not sign it.
        $r13oForged = UpdateKeyRotation::endorse($r13oEvil['secret'], $r13oEvil['public']);
        assert_true(str_contains($r13oError(static fn() => UpdateKeyRotation::rotate($r13oOld['public'], $r13oEvil['public'], $r13oForged, $r13oState)), 'not endorsed'),
            'R13 T6: a new key not endorsed by the trusted key is refused');
        $r13oSwapped = UpdateKeyRotation::endorse($r13oOld['secret'], $r13oNew['public']);
        assert_true($r13oError(static fn() => UpdateKeyRotation::rotate($r13oOld['public'], $r13oEvil['public'], $r13oSwapped, $r13oState)) !== '',
            'R13 T6: an endorsement of another key does not endorse the published one');
        assert_true(!UpdateKeyRotation::isRevoked($r13oOld['public'], $r13oState), 'R13 T6: a refused rotation revokes nothing');

        $r13oRotation = UpdateKeyRotation::rotate($r13oOld['public'], $r13oNew['public'], $r13oSwapped, $r13oState);
        assert_equals(UpdateKeyRotation::fingerprint($r13oNew['public']), $r13oRotation['new'] ?? null, 'R13 T6: the endorsed key is accepted');
        assert_true(UpdateKeyRotation::isRevoked($r13oOld['public'], $r13oState), 'R13 T6: the rotation revokes the old key on this shop');
        assert_true(str_contains($r13oError(static fn() => new UpdatePackage($r13oRoot . '/pkg-old', $r13oOld['public'], false, $r13oState)), 'revoked'),
            'R13 T6: a package signed with the revoked key is refused even while that key is still configured');
        assert_equals('', $r13oError(static fn() => new UpdatePackage($r13oRoot . '/pkg-new', $r13oNew['public'], false, $r13oState)),
            'R13 T6: a package signed with the new key verifies');
        assert_true($r13oError(static fn() => UpdateKeyRotation::rotate($r13oNew['public'], $r13oOld['public'], UpdateKeyRotation::endorse($r13oNew['secret'], $r13oOld['public']), $r13oState)) !== '',
            'R13 T6: a revoked key cannot be rotated back in');

        // The key file update_public_key points to is replaced; an inline key is left to the operator.
        $r13oKeyFile = $r13oRoot . '/update-public.key';
        file_put_contents($r13oKeyFile, $r13oOld['public'] . "\n");
        assert_equals($r13oKeyFile, UpdateKeyRotation::installKey($r13oKeyFile, $r13oNew['public']), 'R13 T6: rotate-key writes the new key into the configured key file');
        assert_equals($r13oNew['public'], trim((string)file_get_contents($r13oKeyFile)), 'R13 T6: the key file holds the new key');
        assert_equals(null, UpdateKeyRotation::installKey($r13oOld['public'], $r13oNew['public']), 'R13 T6: an inline configured key is not rewritten');

        // The v1.9.0 key whose secret was lost ships revoked; doctor fails on a revoked key.
        assert_true(UpdateKeyRotation::isRevoked('base64:l1tEO6agf753QrSbw7mD53WgZiAj+aSGAHxrqW4smhc=', null), 'R13 T6: the lost v1.9.0 key ships revoked');
        mkdir($r13oRoot . '/installed/src', 0700, true);
        $r13oDoctor = (new Updater($r13oRoot . '/installed', $r13oState))->doctor($r13oOld['public'], null, false, []);
        $r13oKeyCheck = array_values(array_filter($r13oDoctor['checks'] ?? [], static fn(array $c): bool => ($c['name'] ?? '') === 'public_key'))[0] ?? [];
        assert_true(($r13oKeyCheck['level'] ?? '') === 'fail' && str_contains((string)($r13oKeyCheck['message'] ?? ''), 'revoked'),
            'R13 T6: update doctor fails a revoked configured key (' . ($r13oKeyCheck['message'] ?? '') . ')');
    } catch (Throwable $e) {
        assert_true(false, 'R13 T6: key rotation (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    }
}

// T7. As root a restore handed files to the shop's owner with chown/chmod by path, in directories the web user
// owns: a symlink swapped in between two calls made root chown e.g. /etc/shadow. The staged file is now created
// exclusively with its mode, and owned through lchown on the same inode.
$r13oBackupSource = (string)file_get_contents(dirname(__DIR__, 3) . '/src/BackupService.php');
assert_true(!preg_match('/@?\b(chown|chgrp)\s*\(/', $r13oBackupSource) && str_contains($r13oBackupSource, 'lchown('),
    'R13 T7: restores never chown/chgrp by path (only lchown/lchgrp on the inode they created)');
$r13oHasStage = method_exists('BackupService', 'stageExclusive') && method_exists('BackupService', 'assertSameInode');
assert_true($r13oHasStage, 'R13 T7: restore stages files exclusively and checks the inode before owning it');
if ($r13oHasStage && DIRECTORY_SEPARATOR === '/') {
    $r13oStage = new ReflectionMethod('BackupService', 'stageExclusive');
    $r13oSame = new ReflectionMethod('BackupService', 'assertSameInode');
    $r13oDir = $r13oRoot . '/t7';
    mkdir($r13oDir, 0700, true);
    file_put_contents($r13oDir . '/source', 'backup copy');
    file_put_contents($r13oDir . '/victim', 'system file');
    symlink($r13oDir . '/victim', $r13oDir . '/staged.tmp');
    assert_equals(null, $r13oStage->invoke(null, $r13oDir . '/source', $r13oDir . '/staged.tmp', 0640), 'R13 T7: staging refuses a name a symlink already took');
    assert_equals('system file', file_get_contents($r13oDir . '/victim'), 'R13 T7: the symlink target is not written');
    unlink($r13oDir . '/staged.tmp');
    $r13oInode = $r13oStage->invoke(null, $r13oDir . '/source', $r13oDir . '/staged.tmp', 0640);
    clearstatcache();
    assert_true(is_int($r13oInode) && (fileperms($r13oDir . '/staged.tmp') & 0777) === 0640, 'R13 T7: the staged copy is created with its final mode (no chmod by path)');
    $r13oSwap = static fn(): string => $r13oError(static fn() => $r13oSame->invoke(null, $r13oDir . '/staged.tmp', $r13oInode));
    assert_equals('', $r13oSwap(), 'R13 T7: the staged file itself passes the inode check');
    unlink($r13oDir . '/staged.tmp');
    symlink($r13oDir . '/victim', $r13oDir . '/staged.tmp');
    assert_true(str_contains($r13oSwap(), 'replaced'), 'R13 T7: a symlink swapped in for the staged file is refused before ownership changes');
    unlink($r13oDir . '/staged.tmp');
    link($r13oDir . '/victim', $r13oDir . '/staged.tmp');
    assert_true(str_contains($r13oError(static fn() => $r13oSame->invoke(null, $r13oDir . '/staged.tmp', null)), 'replaced'), 'R13 T7: a hard link to another file is refused');
}

// T8. stores.json, the merchant's legal texts and the sold e-book files are backed up and restored.
$r13oHasSources = method_exists('BackupService', 'defaultFileSources');
assert_true($r13oHasSources, 'R13 T8: the backup file sources come from config.php');
if ($r13oHasSources) {
    $r13oData = $r13oRoot . '/data';
    $r13oShop = $r13oRoot . '/shop';
    @mkdir($r13oData . '/legal/default', 0700, true);
    @mkdir($r13oData . '/downloads/ebooks', 0700, true);
    @mkdir($r13oShop, 0700, true);
    file_put_contents($r13oData . '/stores.json', '{"stores":{"default":{"name":"R13 Books"}}}');
    file_put_contents($r13oData . '/legal/default/terms.sk.html', '<p>Obchodné podmienky R13</p>');
    file_put_contents($r13oData . '/downloads/ebooks/novel.epub', 'EPUBDATA');
    $r13oSources = BackupService::defaultFileSources(['private_runtime_dir' => $r13oData, 'digital_assets_base_dir' => $r13oData . '/downloads',
        'invoice_storage_dir' => $r13oData . '/invoices', 'uploads_dir' => $r13oShop . '/uploads', 'gdpr_salt_file' => $r13oData . '/gdpr_salt'], $r13oShop);
    assert_equals([$r13oData . '/stores.json', $r13oData . '/legal', $r13oData . '/downloads'],
        [$r13oSources['stores'] ?? null, $r13oSources['legal'] ?? null, $r13oSources['downloads'] ?? null],
        'R13 T8: stores.json, the legal texts and the digital files are backup sources');
    $r13oPdo = new PDO('sqlite:' . $r13oRoot . '/live.db', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $r13oPdo->exec('CREATE TABLE x (id INTEGER PRIMARY KEY)');
    try {
        BackupService::init($r13oPdo, ['enabled' => true, 'storage' => 'local', 'compression' => false, 'auto_gc_on_backup' => false,
            'retention' => ['daily' => 0, 'weekly' => 0, 'monthly' => 0, 'minimum_kept' => 1], 'storage_dir' => $r13oRoot . '/backups', 'files' => $r13oSources], $r13oShop);
        $r13oMeta = BackupService::create(['label' => 'manual']);
        unlink($r13oData . '/stores.json');
        unlink($r13oData . '/legal/default/terms.sk.html');
        unlink($r13oData . '/downloads/ebooks/novel.epub');
        BackupService::restoreFiles((string)$r13oMeta['remote_path']);
        clearstatcache();
        assert_equals(['{"stores":{"default":{"name":"R13 Books"}}}', '<p>Obchodné podmienky R13</p>', 'EPUBDATA'], [
            @file_get_contents($r13oData . '/stores.json'), @file_get_contents($r13oData . '/legal/default/terms.sk.html'),
            @file_get_contents($r13oData . '/downloads/ebooks/novel.epub')], 'R13 T8: a files restore brings back the stores, legal texts and e-book files');
    } catch (Throwable $e) {
        assert_true(false, 'R13 T8: backup and restore of the merchant files (' . get_class($e) . ': ' . $e->getMessage() . ')');
    } finally {
        BackupService::reset();
        $r13oPdo = null;
        gc_collect_cycles();
    }
}
$r13oRm($r13oRoot);
