<?php
/**
 * Extract product specifications from names/descriptions into product_specifications (cli/extract-specs.php).
 * Discovered specs become sidebar filters automatically via Products::specFilterValues().
 *
 * A run replaces only its own rows (source 'extracted'): specifications entered in the admin or imported are kept,
 * and a criterion a product already has from such a source is not extracted for it again.
 */
final class SpecExtraction
{
    public const SOURCE = 'extracted';

    private const BRANDS = '/\b(Harvia|SENTIOTEC|Sentiotec|SAWO|Sawo|DragonFire|Helo|Tylö|EOS|Narvi|Kastor)\b/i';
    private const BRAND_CASE = ['Sentiotec' => 'Sentiotec', 'Sawo' => 'Sawo', 'Dragonfire' => 'DragonFire', 'Eos' => 'EOS'];
    // All Slovak grammatical forms + English
    private const COLORS = [
        'čierna' => 'Čierna', 'čierny' => 'Čierna', 'čierne' => 'Čierna', 'čiernej' => 'Čierna', 'black' => 'Čierna',
        'biela' => 'Biela', 'biely' => 'Biela', 'biele' => 'Biela', 'bielej' => 'Biela', 'white' => 'Biela',
        'červen' => 'Červená', 'red' => 'Červená',
        'modra' => 'Modrá', 'modrá' => 'Modrá', 'modrý' => 'Modrá', 'modré' => 'Modrá', 'blue' => 'Modrá',
        'sivá' => 'Sivá', 'sivý' => 'Sivá', 'sivé' => 'Sivá', 'grey' => 'Sivá', 'gray' => 'Sivá',
        'ružov' => 'Ružová', 'pink' => 'Ružová',
        'nerez' => 'Nerez', 'steel' => 'Nerez',
    ];

    /** @return array<string, int> inserted specs per kind, plus 'total' and 'kept' (criteria left to another source) */
    public static function run(PDO $db): array
    {
        $stats = ['total' => 0, 'kept' => 0, 'power' => 0, 'brand' => 0, 'type' => 0, 'controller' => 0, 'placement' => 0, 'material' => 0, 'color' => 0];
        $db->prepare('DELETE FROM product_specifications WHERE source = :source')->execute([':source' => self::SOURCE]);
        $taken = [];
        foreach ($db->query("SELECT product_id, criteria_name FROM product_specifications WHERE lang = 'sk'")->fetchAll(PDO::FETCH_NUM) as [$productId, $criterion]) {
            $taken[(int)$productId . "\0" . $criterion] = true;
        }
        $insert = $db->prepare("INSERT INTO product_specifications (product_id, criteria_name, criteria_value, numeric_val, lang, source)
            VALUES (:pid, :name, :value, :num, 'sk', :source)");
        $add = static function(int $pid, string $name, string $value, ?float $numeric, ?string $kind) use ($insert, $taken, &$stats): void {
            if (isset($taken[$pid . "\0" . $name])) {
                $stats['kept']++;
                return;
            }
            $insert->execute([':pid' => $pid, ':name' => $name, ':value' => $value, ':num' => $numeric, ':source' => self::SOURCE]);
            $stats['total']++;
            if ($kind !== null) $stats[$kind]++;
        };

        $products = $db->query("SELECT p.id, p.name, p.description, p.attrs, c.path AS cat_path
            FROM products p JOIN categories c ON c.id = p.category_id WHERE p.status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($products as $p) {
            $name = (string)$p['name'];
            $desc = (string)($p['description'] ?? '');
            $catPath = (string)($p['cat_path'] ?? '');
            $pid = (int)$p['id'];

            // Power (kW) — from name, then description
            $kw = null;
            if (preg_match('/(\d+[.,]?\d*)\s*kW/i', $name, $m) || preg_match('/výkon[:\s]*(\d+[.,]?\d*)\s*kW/i', $desc, $m)) {
                $kw = (float)str_replace(',', '.', $m[1]);
            }
            if ($kw !== null && $kw > 0 && $kw < 100) $add($pid, 'Výkon (kW)', str_replace(',', '.', (string)$kw) . ' kW', $kw, 'power');

            // Brand — first known brand found in name
            if (preg_match(self::BRANDS, $name, $m)) {
                $brand = ucfirst(strtolower($m[1]));
                $add($pid, 'Značka', self::BRAND_CASE[$brand] ?? $brand, null, 'brand');
            }

            // Only extract stove-specific specs for stove categories
            if (str_starts_with($catPath, 'saunove-pece')) {
                if (stripos($name, 'combi') !== false || stripos($name, 'výparník') !== false) {
                    $add($pid, 'Typ pece', 'Kombi (s výparníkom)', null, 'type');
                } elseif (stripos($name, 'parná') !== false || stripos($name, 'parn') !== false || stripos($name, 'geyser') !== false) {
                    $add($pid, 'Typ pece', 'Parná', null, 'type');
                }

                if (stripos($name, 's ovládačom') !== false || stripos($name, 's ovládacom') !== false) {
                    $add($pid, 'Ovládač', 'Vstavaný', null, 'controller');
                } elseif (preg_match('/\bE\b/', $name) && stripos($catPath, 'elektricke') !== false) {
                    // "E" suffix typically means external controller needed
                    $add($pid, 'Ovládač', 'Externý (nutné dokúpiť)', null, 'controller');
                }

                if (stripos($name, 'rohov') !== false || stripos($name, 'corner') !== false) {
                    $add($pid, 'Umiestnenie', 'Rohová', null, 'placement');
                } elseif (stripos($name, 'wall') !== false && stripos($name, 'wall tower') === false) {
                    $add($pid, 'Umiestnenie', 'Nástenná', null, 'placement');
                } elseif (stripos($name, 'tower') !== false) {
                    $add($pid, 'Umiestnenie', 'Tower (stĺpová)', null, 'placement');
                }

                if (preg_match('/(\d+[.,]?\d*)\s*[-–]\s*(\d+[.,]?\d*)\s*m[³3]/iu', $desc, $m)) {
                    $volMin = (float)str_replace(',', '.', $m[1]);
                    $volMax = (float)str_replace(',', '.', $m[2]);
                    $add($pid, 'Objem sauny (m³)', "{$volMin}-{$volMax} m³", $volMax, null);
                }
                if (preg_match('/kapacita\s+kameňov[:\s]*(\d+)\s*kg/i', $desc, $m)) {
                    $add($pid, 'Kapacita kameňov', $m[1] . ' kg', (float)$m[1], null);
                }
            }

            $attrs = json_decode((string)($p['attrs'] ?? '{}'), true);
            if (is_array($attrs) && !empty($attrs['wood']) && is_scalar($attrs['wood'])) $add($pid, 'Materiál', (string)$attrs['wood'], null, 'material');

            foreach (self::COLORS as $pattern => $colorName) {
                if (stripos($name, $pattern) !== false) {
                    $add($pid, 'Farba', $colorName, null, 'color');
                    break;
                }
            }
        }
        return $stats;
    }
}
