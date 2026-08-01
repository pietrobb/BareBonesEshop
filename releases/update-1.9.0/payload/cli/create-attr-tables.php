<?php
require_once __DIR__ . '/cli-only.php';

/** Create attribute tables in existing DB without dropping anything. */
$db = new PDO('sqlite:' . __DIR__ . '/../db/shop.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$db->exec("CREATE TABLE IF NOT EXISTS attribute_definitions (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    type TEXT NOT NULL DEFAULT 'enum',
    unit TEXT DEFAULT '',
    sort_order INTEGER DEFAULT 0
)");
echo "[OK] attribute_definitions\n";

$db->exec("CREATE TABLE IF NOT EXISTS attribute_options (
    id INTEGER PRIMARY KEY,
    attribute_id INTEGER NOT NULL,
    value TEXT NOT NULL,
    sort_order INTEGER DEFAULT 0,
    FOREIGN KEY (attribute_id) REFERENCES attribute_definitions(id) ON DELETE CASCADE
)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_attropt_attr ON attribute_options(attribute_id, sort_order)");
echo "[OK] attribute_options\n";

$db->exec("CREATE TABLE IF NOT EXISTS category_attributes (
    category_id INTEGER NOT NULL,
    attribute_id INTEGER NOT NULL,
    PRIMARY KEY (category_id, attribute_id),
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    FOREIGN KEY (attribute_id) REFERENCES attribute_definitions(id) ON DELETE CASCADE
)");
echo "[OK] category_attributes\n";
echo "Done.\n";
