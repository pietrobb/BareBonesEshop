<?php
/**
 * Backup storage for an operator-mounted off-site filesystem.
 *
 * Authentication and transport are owned by the host mount (SFTP, NFS, SMB,
 * encrypted object-storage mount); no credentials enter the application.
 * The sentinel prevents a missing mount from silently writing to local disk.
 */
class MountedBackupStorage extends LocalBackupStorage
{
    private const SENTINEL = '.bbe-offsite-backup-target';

    public function __construct(string $root)
    {
        if (!is_dir($root) || !is_file(rtrim($root, "/\\") . DIRECTORY_SEPARATOR . self::SENTINEL)) {
            throw new RuntimeException(
                'MountedBackupStorage: off-site mount is unavailable or missing ' . self::SENTINEL
            );
        }
        parent::__construct($root);
    }

    public function put(string $localPath, string $remotePath, array $meta): void
    {
        $this->assertMounted();
        parent::put($localPath, $remotePath, $meta);
    }

    public function get(string $remotePath, string $localPath): void
    {
        $this->assertMounted();
        parent::get($remotePath, $localPath);
    }

    public function delete(string $remotePath): void
    {
        $this->assertMounted();
        parent::delete($remotePath);
    }

    public function list(string $prefix = ''): array
    {
        $this->assertMounted();
        return parent::list($prefix);
    }

    private function assertMounted(): void
    {
        if (!is_file($this->root() . DIRECTORY_SEPARATOR . self::SENTINEL)) {
            throw new RuntimeException('MountedBackupStorage: off-site mount sentinel disappeared');
        }
    }
}
