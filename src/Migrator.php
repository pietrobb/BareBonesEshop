<?php
/**
 * BareBones Shop — Database Migrations
 *
 * Forward-only schema migration runner.
 *
 * Files: migrations/<TIMESTAMP>_<snake_name>.php — each returns an anonymous
 * class with: public function up(PDO $db, bool $isSqlite): void.
 *
 * State: schema_migrations(name UNIQUE, checksum, applied_at, duration_ms). Extension migrations
 * (extensions/<code>/migrations) use the same runner with their own table and a "<code>/" name prefix.
 *
 * Concurrency: file lock on db/.migrations.lock (flock, 10s timeout). When db/ is not writable
 * (read-only code mount, e.g. deploy/docker-compose.yml) the lock lives in the private runtime
 * directory (config private_runtime_dir), else in the system temp directory.
 *
 * Failure: SQLite wraps each migration in BEGIN/ROLLBACK. MySQL-family DDL
 * commits implicitly; on every driver the marker row is inserted only on success.
 *
 * Checksum mismatch (file changed after being applied) is a hard error.
 */
class Migrator {

    public const LOCK_TIMEOUT_SECONDS = 10;
    public const LOCK_FILENAME        = '.migrations.lock';

    private const LEGACY_CHECKSUMS = [
        '20260424000000_baseline' => [
            '0733ee8ec1141fe01a63fc86ff0f8a0efff517b7873f847759bcfe0e63500b29' => ['7a5bd29a8a0ee1100a0149e16a706ba95075f4fb75baa7a81b5f9304b0b8421f', '01d474a45922dd8f1de1f1f9adb1a62e85a8e5f8564c4024d8a954a3a7aacc28'],
            'b77aaf955c42756c146d861e36c942b6f6eba00b639846571dbb69b0c860700d' => ['7a5bd29a8a0ee1100a0149e16a706ba95075f4fb75baa7a81b5f9304b0b8421f', '01d474a45922dd8f1de1f1f9adb1a62e85a8e5f8564c4024d8a954a3a7aacc28'],
            '198e229704609cf3a2fd5943cb2a50a8b31872892e72e476ece36e64c1ccb832' => ['7a5bd29a8a0ee1100a0149e16a706ba95075f4fb75baa7a81b5f9304b0b8421f', '01d474a45922dd8f1de1f1f9adb1a62e85a8e5f8564c4024d8a954a3a7aacc28'],
            'a3ca08f0ad32a92cfa750126363c55ff7b6504e0c0b2819d05c5e8be227e248e' => ['7a5bd29a8a0ee1100a0149e16a706ba95075f4fb75baa7a81b5f9304b0b8421f', '01d474a45922dd8f1de1f1f9adb1a62e85a8e5f8564c4024d8a954a3a7aacc28'],
        ],
        '20260424221721_create_invoices' => [
            '5f3ae8cbe4351aa66dcf3950298614af9a931466cd5f89d75bb30abcce796e84' => ['9426aba6dde40d3b086f1ee3f6664fed9bd7235ef905dc2c230c8e8a127705d2', '39b27f592f626e102c1e01c09e3320b4e72d90b9a05dd8c790d8b54618221431'],
            '72f897ada616001f367632189e50c54b85e33c8915e2bc3b673afc1ff77d35dc' => ['9426aba6dde40d3b086f1ee3f6664fed9bd7235ef905dc2c230c8e8a127705d2', '39b27f592f626e102c1e01c09e3320b4e72d90b9a05dd8c790d8b54618221431'],
            'd723987d9ae0d85b069c69b5503e212afef19d4ddffda920fe916a69b6c1c919' => ['9426aba6dde40d3b086f1ee3f6664fed9bd7235ef905dc2c230c8e8a127705d2', '39b27f592f626e102c1e01c09e3320b4e72d90b9a05dd8c790d8b54618221431'],
            '77a943c7490abb854a2af0b107def2b54d1629a4dd6f2e3b9a83b4cde2613e90' => ['9426aba6dde40d3b086f1ee3f6664fed9bd7235ef905dc2c230c8e8a127705d2', '39b27f592f626e102c1e01c09e3320b4e72d90b9a05dd8c790d8b54618221431'],
        ],
        '20260425013405_add_vat_columns' => [
            'a1d5a426999bca90c2ffbe4b3e7c1f8bad06cf70d415a58d77ef6d137b44dce5' => ['e81e9601d9beae3ba845ea8d94624776cf96d8d6fe9a894f6e4b90e8b47ef475', '62c13dc02a6f69606a30847578783c9cb2f45b1f14cf643b55bbd47a04bd4e2c'],
            'e1d674c6cb8160bd322648d561030e52fdd3babfc340478bb74a51be1c1bd164' => ['e81e9601d9beae3ba845ea8d94624776cf96d8d6fe9a894f6e4b90e8b47ef475', '62c13dc02a6f69606a30847578783c9cb2f45b1f14cf643b55bbd47a04bd4e2c'],
        ],
        '20260425033813_add_gdpr_tables' => [
            'da4fb344cd73b8f42184691e276d2193fb5c8d3ecfec26714fd8ffc956f3238f' => ['2b8698f5da52f19fc93347b10e53fe76231c21c5aa801c0ed61849b1c8b345e1', '29e96c2aba08e105bb4c45b13222d7af6163a91c28eda7d7296d8c9283b5c543'],
            '8e7c68e6aa60d169b983ce37203206b05864e7c198dfeba4fe66cd41b9ce7af4' => ['2b8698f5da52f19fc93347b10e53fe76231c21c5aa801c0ed61849b1c8b345e1', '29e96c2aba08e105bb4c45b13222d7af6163a91c28eda7d7296d8c9283b5c543'],
        ],
        '20260425064000_shipping_methods_pricing_engine' => [
            '0b8f42a4a9049e68b01c265abc95fd78cb5ce1c598b7bc41f68c6891190cb18d' => ['7f108c8a25117d216a54f65e55789c5190830debac8535875b706835ba1a427b', 'e63f2701cbd3d93d9a915cfee251adea1262d2804941560061487e67ed4ebdb8'],
            '694ff0930cf8124307428f7105cbbfc8400675f05b6c2660beb62ef536e5baf5' => ['7f108c8a25117d216a54f65e55789c5190830debac8535875b706835ba1a427b', 'e63f2701cbd3d93d9a915cfee251adea1262d2804941560061487e67ed4ebdb8'],
            '94b031011374ad9de5e6d83673fe756d30005187ee0caab6d1dbde73b5fc83e3' => ['7f108c8a25117d216a54f65e55789c5190830debac8535875b706835ba1a427b', 'e63f2701cbd3d93d9a915cfee251adea1262d2804941560061487e67ed4ebdb8'],
            '4e7b78bf38e1fa90911645064a3bca1e74931bcca4e067e1a1f9718572b233a8' => ['7f108c8a25117d216a54f65e55789c5190830debac8535875b706835ba1a427b', 'e63f2701cbd3d93d9a915cfee251adea1262d2804941560061487e67ed4ebdb8'],
        ],
        '20260425071000_payment_methods_pricing_engine' => [
            '3c18e9bf7cf11472c3cf673db4727687b7c032e549143a73d5617ff72d9490f5' => ['6521e702499a264796764e29f7a486ed33a815174ed2ee82bf2a5e2652fa2a1c', 'b6728bb1845653db6e1ec5ea9f99f4a4745904de580562d93d2f787ff136605b'],
            '893fe8b1cbcfb6ba9776cf6532e4204d193d98c269d7d49f377b9a0a32be4354' => ['6521e702499a264796764e29f7a486ed33a815174ed2ee82bf2a5e2652fa2a1c', 'b6728bb1845653db6e1ec5ea9f99f4a4745904de580562d93d2f787ff136605b'],
            'c2ef36962e255089187c153531a9f099f5a32d0b42810b3e0d70dc1ee97c8d0e' => ['6521e702499a264796764e29f7a486ed33a815174ed2ee82bf2a5e2652fa2a1c', 'b6728bb1845653db6e1ec5ea9f99f4a4745904de580562d93d2f787ff136605b'],
            '82f59571d4625b7a4c93306505447efeec4a2bf5f8fd2e7ae5dc02b32f0592c7' => ['6521e702499a264796764e29f7a486ed33a815174ed2ee82bf2a5e2652fa2a1c', 'b6728bb1845653db6e1ec5ea9f99f4a4745904de580562d93d2f787ff136605b'],
        ],
        '20260426090000_create_product_reviews' => [
            'e0f7600c4d4f970dc8814bdb9972e779eb689862c1af0bf15f67817bb2de4524' => ['102aa40f08e1bc2807be64cb64200fa7bdb0613dc9996e43a3d82d5d36e52c6d', 'bde17066ffe3259c51ec2f0f31cec94a6fd38fab0bbe55ad9582b604e11a9efc'],
            '05da7bb75b1cc8e355565baeb3ba3d975b058c4a7145d69bbad32c545a5dab26' => ['102aa40f08e1bc2807be64cb64200fa7bdb0613dc9996e43a3d82d5d36e52c6d', 'bde17066ffe3259c51ec2f0f31cec94a6fd38fab0bbe55ad9582b604e11a9efc'],
            '8aeb582aa036dbea9f39545dc91510bc36c8660659943ebff6cd17fccaf1a1fb' => ['102aa40f08e1bc2807be64cb64200fa7bdb0613dc9996e43a3d82d5d36e52c6d', 'bde17066ffe3259c51ec2f0f31cec94a6fd38fab0bbe55ad9582b604e11a9efc'],
            '2062d966bd199d0e13043ae9c6c4854f315ff8c95d63a5d28bc7aa7b9de568cf' => ['102aa40f08e1bc2807be64cb64200fa7bdb0613dc9996e43a3d82d5d36e52c6d', 'bde17066ffe3259c51ec2f0f31cec94a6fd38fab0bbe55ad9582b604e11a9efc'],
        ],
        '20260426093000_create_wishlist_items' => [
            'a16b8762e4c656ac945ee4039367037eac23d08f5a76093662ad1f8549ec028f' => ['1d14ab07178305da1af1d8873decfadc46ccd53f5f05beb2b42f83d36e021a52', '0122c697ee59d7159ed1ae28f88e425dc731fa4d07fb88c2c30779585428acec'],
            '3ac343201b6b64efe8857f7333fbd3276a6fd33f3826c30948a56d29e6b3aec1' => ['1d14ab07178305da1af1d8873decfadc46ccd53f5f05beb2b42f83d36e021a52', '0122c697ee59d7159ed1ae28f88e425dc731fa4d07fb88c2c30779585428acec'],
        ],
        '20260426100000_create_rma' => [
            'ca2f151aa638e6cbdd484901d7650aff28890c62031067d1f41665aae1540d2e' => ['0b0d4537d06dfb0151bed02c03fcba9f86775f558a505948ece9a8f8f2e8fd03', '618122227d2494b7cde2a9ad7a2d563532cb6e3d8f4ad9bb2fe9e7cd8e275d81'],
            'e969d966581bb935484f1861a023d1fff5f3706c5be86df49e538c002c604017' => ['0b0d4537d06dfb0151bed02c03fcba9f86775f558a505948ece9a8f8f2e8fd03', '618122227d2494b7cde2a9ad7a2d563532cb6e3d8f4ad9bb2fe9e7cd8e275d81'],
            'a1fe6b14bee90fd52e63d45eb3ad16e03f370f0e1f15b013cd22a7e8e878971b' => ['0b0d4537d06dfb0151bed02c03fcba9f86775f558a505948ece9a8f8f2e8fd03', '618122227d2494b7cde2a9ad7a2d563532cb6e3d8f4ad9bb2fe9e7cd8e275d81'],
            'e2d9ab647dd5d932b9235606c365dfef1dc1f4f59780816c8c5fd2319eb7f0de' => ['0b0d4537d06dfb0151bed02c03fcba9f86775f558a505948ece9a8f8f2e8fd03', '618122227d2494b7cde2a9ad7a2d563532cb6e3d8f4ad9bb2fe9e7cd8e275d81'],
        ],
        '20260611213000_add_stock_restored_column' => [
            'a4803eb5d0df8cfaade336b4d7a3d6e32c08ab4b98f5978efd97847e257ff723' => ['31beb9b95a29f873f7e0249f3da76b58835865ed1e06bc1267abe86568c61ed7', 'b937a90ea83bfe5cb364f0f3bc6ca624735769793cc55f6bd4fad6fe4a3df7cc'],
            'b6886c3132db22443fd82679686eae92bdd0d6254c9aacb888176bbd0fbaf76a' => ['31beb9b95a29f873f7e0249f3da76b58835865ed1e06bc1267abe86568c61ed7', 'b937a90ea83bfe5cb364f0f3bc6ca624735769793cc55f6bd4fad6fe4a3df7cc'],
        ],
        '20260611220000_add_invoices_order_unique' => [
            '1bc4df38ae75bd7e3f93286d77142982db3a164d56a3c76e18efe79e6614cb5c' => ['cf8642838380d52c95cb0cd0479d6cc5dae6521c7eb4f98367b54b2c01cd7fdf', '3d3f4d14399840e1b063829b911ab7ccbf28941818e553bbf00ddaab1ff31d66'],
            'f84893a7eeea3a9c37c68f525d7633973c053b1da3da82d7ea7f4f97cfe5188b' => ['cf8642838380d52c95cb0cd0479d6cc5dae6521c7eb4f98367b54b2c01cd7fdf', '3d3f4d14399840e1b063829b911ab7ccbf28941818e553bbf00ddaab1ff31d66'],
        ],
        '20260801152000_store_context_scope' => [
            '0b588ab31c46c2f7422c8e1025b54b2412516c78f98a195d0ccb7d5a43b3bc5d' => ['01b933cc091825b5c2e40ba372b2c50b14ada9d3ea204192c60eb3995222361a', '217003f0fa321e85947b5940de5ba5c7759889961f5cd283c5c31ac1fbf8e791'],
            '1e283a525cc44f8fa6d74021c5b4dcf0b8aaa65d5f9796dc96f40123f540845a' => ['01b933cc091825b5c2e40ba372b2c50b14ada9d3ea204192c60eb3995222361a', '217003f0fa321e85947b5940de5ba5c7759889961f5cd283c5c31ac1fbf8e791'],
        ],
        '20260801190000_catalog_pricing_b2b' => [
            '9fa541278b995f2c0bf503e3618c7d49cfb2fa598b488f15b5003e71271a3fc5' => ['e67c01f7fb754fb70697eb3d2987f236037cc6c215f07d8d5ba3849696c04db3', 'f68cf24641e6b3dd1fd746eec588d7c7da60b10bb79452e6530c2707c15a0bb9'],
            '701e48346103c2aa044573a8d6533118c86f68f8e42514edd6265c516259487b' => ['e67c01f7fb754fb70697eb3d2987f236037cc6c215f07d8d5ba3849696c04db3', 'f68cf24641e6b3dd1fd746eec588d7c7da60b10bb79452e6530c2707c15a0bb9'],
        ],
        '20260801200000_inventory_ledger_fulfillment' => [
            'b23fb0394cefa71f14946a2bd798a8c741b3cac969a324dfb13446e98e69cb1e' => ['ac80c16baa1863e6544d9f8ad1526bd10f0f5ca135292471b6ae699ee6237552', '7868d06fd0f5e6a45a6c389dac99e6bd08b00fb01d69ae254be5655400694b59'],
            '390c4e1ff90cf07bc7c4a88a035424b5a55e3b8347dc37bdcca431c04ad2e6e5' => ['ac80c16baa1863e6544d9f8ad1526bd10f0f5ca135292471b6ae699ee6237552', '7868d06fd0f5e6a45a6c389dac99e6bd08b00fb01d69ae254be5655400694b59'],
        ],
        '20260801210000_returns_credit_notes' => [
            '82095206a469fa8ac4e4c3283efb69746ec16ec520d5c526a8ce42dcf17b58ba' => ['b0408cc34f360434f0e46a1508d0b619c793145dedff71e532b0d77d72c67e9e', '85c86823b2089908d631cb5711bf03dfff64b3221dafd84f79f9742dbaa7f1c6'],
            '3308eac2c61b07ebd9210611c2e8b11624fbe8ba545f5100d9dbb5404ae52526' => ['b0408cc34f360434f0e46a1508d0b619c793145dedff71e532b0d77d72c67e9e', '85c86823b2089908d631cb5711bf03dfff64b3221dafd84f79f9742dbaa7f1c6'],
        ],
        '20260801220000_admin_identity_queue_operations' => [
            'ba952316c8f6fe2b58408ccfb9bdef6f8d9bc0b058edb4ba0d88156a64c7b14a' => ['8f6343ed1384cf04a0f8cc2a52b29135038bdbf4a7751e82692c578868824703', '4637094322fef224a59c6e16e49aa92cb838c275a76af6f98645d3e66d48afd3'],
            '51135d0ae99b19b442c1b4a78e08bcd60692bfd1ed1163a8158977be6ac1e63b' => ['8f6343ed1384cf04a0f8cc2a52b29135038bdbf4a7751e82692c578868824703', '4637094322fef224a59c6e16e49aa92cb838c275a76af6f98645d3e66d48afd3'],
        ],
        '20260801230000_theme_content_marketing' => [
            '25b037d8ebf85a7ba7671fc8ee3ac169aba9523402b0cfb385a4d1b3b9e620ee' => ['18521c5bb67c210913edf59671785fbdc698a6d02f48697555c4e6862375d75f', '4f67a797a2e3ad34c4dc84694f306c679775ad1f8df0fdf3f4ee8993fabf4ad1'],
            '2d0fc8d86beed79179a3a06ea6d5b0d8d6e3d9c48cb3e2e684fd2f31e7e2f6e4' => ['18521c5bb67c210913edf59671785fbdc698a6d02f48697555c4e6862375d75f', '4f67a797a2e3ad34c4dc84694f306c679775ad1f8df0fdf3f4ee8993fabf4ad1'],
        ],
    ];

    private PDO    $db;
    private bool   $isSqlite;
    private string $migrationsDir;
    private string $lockFile;
    private string $table;
    private string $namePrefix;
    /** @var resource|null */
    private $lockHandle = null;

    public function __construct(?PDO $db = null, ?string $migrationsDir = null, ?string $lockFile = null, string $table = 'schema_migrations', string $namePrefix = '') {
        if (!preg_match('/^[a-z_]+$/', $table)) throw new \InvalidArgumentException('invalid migrations table name');
        $this->table         = $table;
        $this->namePrefix    = $namePrefix;
        $this->db            = $db ?? Db::get();
        $this->isSqlite      = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $this->migrationsDir = $migrationsDir ?? (dirname(__DIR__) . '/migrations');
        $this->lockFile = $lockFile ?? self::defaultLockFile();
    }

    /** First writable of: <app>/db, the private runtime directory, the system temp directory. */
    public static function defaultLockFile(?string $appRoot = null, ?array $config = null): string {
        $appRoot ??= dirname(__DIR__);
        $dbDir = $appRoot . '/db';
        if (!is_dir($dbDir)) @mkdir($dbDir, 0750, true);
        if (is_dir($dbDir) && is_writable($dbDir)) return $dbDir . '/' . self::LOCK_FILENAME;
        if ($config === null) {
            $config = is_file($appRoot . '/config.php') ? (array)(require $appRoot . '/config.php') : [];
        }
        $runtimeDir = rtrim((string)($config['private_runtime_dir'] ?? ''), '/\\');
        if ($runtimeDir !== '' && is_dir($runtimeDir) && is_writable($runtimeDir)) {
            return $runtimeDir . '/' . self::LOCK_FILENAME;
        }
        // One lock per installation, so installations sharing the temp directory do not block each other.
        return rtrim(sys_get_temp_dir(), '/\\') . '/bbeshop-' . substr(hash('sha256', $appRoot), 0, 16) . self::LOCK_FILENAME;
    }

    // ------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------

    /**
     * Apply all pending migrations in timestamp order.
     * Returns array of applied names.
     *
     * Optional `$preApplyHook` is called once with the array of pending names
     * BEFORE any migration runs (and only if there ARE pending). Throwing from
     * the hook aborts the apply() call without modifying any state. Used by
     * cli/migrate.php to take a pre-migration safety backup.
     */
    public function apply(?callable $logger = null, ?callable $preApplyHook = null): array {
        $logger = $logger ?? static fn(string $m) => null;
        $this->lock();
        try {
            $this->ensureMigrationsTable();
            $this->verifyChecksums();
            $pending = $this->pendingFiles();
            if ($pending && $preApplyHook) {
                $preApplyHook(array_keys($pending), $logger);
            }
            $applied = [];
            foreach ($pending as $name => $path) {
                $start    = microtime(true);
                $checksum = $this->fileChecksum($path);
                $logger("  [..] {$name}");
                $migration = $this->loadMigration($path);
                if ($this->isSqlite) {
                    $this->db->beginTransaction();
                }
                try {
                    $migration->up($this->db, $this->isSqlite);
                    $duration = (int)round((microtime(true) - $start) * 1000);
                    $stmt = $this->db->prepare(
                        "INSERT INTO {$this->table} (name, checksum, duration_ms) VALUES (?, ?, ?)"
                    );
                    $stmt->execute([$name, $checksum, $duration]);
                    if ($this->db->inTransaction()) {
                        $this->db->commit();
                    }
                    $applied[] = $name;
                    $logger("  [OK] {$name} ({$duration} ms)");
                } catch (\Throwable $e) {
                    if ($this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    $logger("  [FAIL] {$name}: " . $e->getMessage());
                    throw $e;
                }
            }
            return $applied;
        } finally {
            $this->unlock();
        }
    }

    /**
     * Mark every existing migration file as applied — without running them.
     * Used once on existing production DB to "seed" schema_migrations so
     * subsequent runs only see new files as pending.
     *
     * Idempotent: existing rows are not duplicated.
     */
    public function baseline(?callable $logger = null): array {
        $logger = $logger ?? static fn(string $m) => null;
        $this->lock();
        try {
            $this->ensureMigrationsTable();
            $marked = [];
            foreach ($this->allFiles() as $name => $path) {
                $exists = $this->db->prepare("SELECT 1 FROM {$this->table} WHERE name = ?");
                $exists->execute([$name]);
                if ($exists->fetchColumn()) {
                    $logger("  [skip] {$name} (already recorded)");
                    continue;
                }
                $checksum = $this->fileChecksum($path);
                $stmt = $this->db->prepare(
                    "INSERT INTO {$this->table} (name, checksum, duration_ms) VALUES (?, ?, 0)"
                );
                $stmt->execute([$name, $checksum]);
                $marked[] = $name;
                $logger("  [seed] {$name}");
            }
            return $marked;
        } finally {
            $this->unlock();
        }
    }

    /**
     * Return status array with: applied (with checksum_ok flag), pending, missing.
     *  - applied: name → ['applied_at', 'checksum_ok' bool|null]
     *  - pending: list of file names not in DB
     *  - missing: rows in DB whose file is gone
     */
    public function status(): array {
        $this->ensureMigrationsTable();
        $rows = $this->ownRows($this->db->query(
            "SELECT name, checksum, applied_at FROM {$this->table} ORDER BY name"
        )->fetchAll(PDO::FETCH_ASSOC) ?: []);
        $files = $this->allFiles();

        $applied = [];
        foreach ($rows as $r) {
            $name = $r['name'];
            $ok   = null;
            if (isset($files[$name])) {
                $ok = $this->checksumMatches($name, $r['checksum'], $this->fileChecksum($files[$name]));
            }
            $applied[$name] = [
                'applied_at'  => $r['applied_at'],
                'checksum_ok' => $ok,
            ];
        }

        $pending = [];
        foreach ($files as $name => $_) {
            if (!isset($applied[$name])) $pending[] = $name;
        }

        $missing = [];
        foreach ($applied as $name => $_) {
            if (!isset($files[$name])) $missing[] = $name;
        }

        return ['applied' => $applied, 'pending' => $pending, 'missing' => $missing];
    }

    /**
     * Scaffold a new migration file. Returns absolute path of the created file.
     */
    public function create(string $name): string {
        $slug = preg_replace('/[^a-z0-9]+/i', '_', strtolower(trim($name)));
        $slug = trim($slug, '_');
        if ($slug === '') {
            throw new \InvalidArgumentException('Migration name cannot be empty after slugifying');
        }
        $ts   = gmdate('YmdHis');
        $file = "{$ts}_{$slug}.php";
        if (!is_dir($this->migrationsDir)) {
            @mkdir($this->migrationsDir, 0750, true);
        }
        $path = $this->migrationsDir . '/' . $file;

        $stub = <<<'PHP'
<?php
/**
 * Migration: %NAME%
 * Generated: %TS%
 */
return new class {
    public function up(PDO $db, bool $isSqlite): void {
        // $now    = $isSqlite ? "datetime('now')" : "NOW()";
        // $autoinc = $isSqlite ? '' : ' AUTO_INCREMENT';
        // $db->exec("CREATE TABLE example (id INTEGER PRIMARY KEY{$autoinc})");
    }
};

PHP;
        $stub = str_replace(['%NAME%', '%TS%'], [$slug, gmdate('c')], $stub);
        file_put_contents($path, $stub);
        return $path;
    }

    // ------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------

    public function ensureMigrationsTable(): void {
        $autoinc = $this->isSqlite ? '' : ' AUTO_INCREMENT';
        $now     = $this->isSqlite ? "datetime('now')" : 'NOW()';
        $short   = MigrationSql::short($this->isSqlite);
        $date    = MigrationSql::datetime($this->isSqlite);
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS {$this->table} (
                id          INTEGER PRIMARY KEY{$autoinc},
                name        {$short} NOT NULL UNIQUE,
                checksum    {$short} NOT NULL,
                applied_at  {$date} NOT NULL DEFAULT ({$now}),
                duration_ms INTEGER NOT NULL DEFAULT 0
            )"
        );
    }

    /**
     * @return array<string,string> name => path, sorted by name (timestamp prefix)
     */
    public function allFiles(): array {
        if (!is_dir($this->migrationsDir)) return [];
        $files = glob($this->migrationsDir . '/*.php') ?: [];
        $out = [];
        foreach ($files as $f) {
            $name = $this->namePrefix . basename($f, '.php');
            $out[$name] = $f;
        }
        ksort($out);
        return $out;
    }

    /** @return array<string,string> name => path of files not yet applied */
    public function pendingFiles(): array {
        $rows = $this->db->query("SELECT name FROM {$this->table}")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $applied = array_flip($rows);
        $out = [];
        foreach ($this->allFiles() as $name => $path) {
            if (!isset($applied[$name])) $out[$name] = $path;
        }
        return $out;
    }

    private function loadMigration(string $path): object {
        $obj = require $path;
        if (!is_object($obj) || !method_exists($obj, 'up')) {
            throw new \RuntimeException("Migration {$path} must return an object with up() method");
        }
        return $obj;
    }

    public function fileChecksum(string $path): string {
        return hash_file('sha256', $path);
    }

    /**
     * Hard-fail if any applied migration's file checksum no longer matches
     * what was recorded — indicates the migration was edited after being run,
     * which would silently desync the DB.
     */
    private function verifyChecksums(): void {
        $rows = $this->ownRows($this->db->query(
            "SELECT name, checksum FROM {$this->table}"
        )->fetchAll(PDO::FETCH_ASSOC) ?: []);
        $files = $this->allFiles();
        foreach ($rows as $r) {
            $name = $r['name'];
            if (!isset($files[$name])) continue; // 'missing' handled separately
            $actual = $this->fileChecksum($files[$name]);
            if (!$this->checksumMatches($name, $r['checksum'], $actual)) {
                throw new \RuntimeException(
                    "Migration {$name} was modified after being applied " .
                    "(expected checksum {$r['checksum']}, got {$actual}). " .
                    "Refusing to continue. Restore the original file or write a new migration."
                );
            }
        }
    }

    /** Rows of this runner only: an extension's runner shares its table with other extensions. */
    private function ownRows(array $rows): array {
        if ($this->namePrefix === '') return $rows;
        return array_values(array_filter($rows, fn(array $row): bool => str_starts_with((string)$row['name'], $this->namePrefix)));
    }

    private function checksumMatches(string $name, string $recorded, string $actual): bool {
        if ($recorded === $actual) return true;
        return in_array($actual, self::LEGACY_CHECKSUMS[$name][$recorded] ?? [], true);
    }

    private function lock(): void {
        $this->lockHandle = fopen($this->lockFile, 'c');
        if (!$this->lockHandle) {
            throw new \RuntimeException("Cannot open lock file: {$this->lockFile}");
        }
        $deadline = microtime(true) + self::LOCK_TIMEOUT_SECONDS;
        while (true) {
            if (flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
                return;
            }
            if (microtime(true) >= $deadline) {
                fclose($this->lockHandle);
                $this->lockHandle = null;
                throw new \RuntimeException(
                    "Could not acquire migrations lock within " . self::LOCK_TIMEOUT_SECONDS .
                    "s — another migrate process running?"
                );
            }
            usleep(100_000);
        }
    }

    private function unlock(): void {
        if ($this->lockHandle) {
            @flock($this->lockHandle, LOCK_UN);
            @fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }
}
