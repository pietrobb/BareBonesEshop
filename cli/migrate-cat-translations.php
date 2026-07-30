<?php
require_once __DIR__ . '/cli-only.php';

/**
 * BareBones Shop — Category Translations Migration
 *
 * Migrates the hardcoded $catTranslations hash from index.php into the
 * categories.translations JSON column. Idempotent — merges into existing
 * translations; existing locale entries take precedence (we don't overwrite
 * DB-curated translations with the bundled defaults).
 *
 * Usage: php cli/migrate-cat-translations.php [--force]
 *   --force   overwrite existing locale entries in translations JSON
 */
require_once __DIR__ . '/../src/autoload.php';

/**
 * Run the migration. Returns [updated, skipped, noMatch].
 * @param bool $force  Overwrite existing locale entries in translations JSON.
 */
function migrateCatTranslations(bool $force = false): array {
    $translations = migrateCatTranslationsData();
    $db = Db::get();
    $cats = $db->query("SELECT id, name, translations FROM categories")->fetchAll();
    $updated = 0; $skipped = 0; $noMatch = 0;
    $updateStmt = $db->prepare("UPDATE categories SET translations = :tr WHERE id = :id");

    foreach ($cats as $cat) {
        $existing = json_decode($cat['translations'] ?? '{}', true) ?: [];
        $newTranslations = $existing;
        $hasChanges = false;

        foreach ($translations as $locale => $map) {
            if (!isset($map[$cat['name']])) continue;
            $translated = $map[$cat['name']];

            if (!isset($newTranslations[$locale])) {
                $newTranslations[$locale] = [];
            }
            if (!empty($newTranslations[$locale]['name']) && !$force) {
                continue;
            }
            if (($newTranslations[$locale]['name'] ?? null) !== $translated) {
                $newTranslations[$locale]['name'] = $translated;
                $hasChanges = true;
            }
        }

        if ($hasChanges) {
            $updateStmt->execute([
                ':tr' => json_encode($newTranslations, JSON_UNESCAPED_UNICODE),
                ':id' => $cat['id'],
            ]);
            $updated++;
        } elseif (isset($translations['sk'][$cat['name']]) || isset($translations['de'][$cat['name']])) {
            $skipped++;
        } else {
            $noMatch++;
        }
    }
    return [$updated, $skipped, $noMatch];
}

function migrateCatTranslationsData(): array {
    return [
    'sk' => [
        'Body' => 'Karoséria', 'Brake System' => 'Brzdový systém', 'Cooling System' => 'Chladiaci systém',
        'Lights' => 'Osvetlenie', 'Wheel Suspension' => 'Zavesenie kolies', 'Air Supply' => 'Prívod vzduchu',
        'Mixture Formation' => 'Tvorba zmesi', 'Cylinder Head' => 'Hlava valcov', 'Lock System' => 'Zámkový systém',
        'Exhaust System' => 'Výfukový systém', 'Engine Timing' => 'Rozvod motora', 'Lubrication' => 'Mazanie',
        'Steering' => 'Riadenie', 'Fuel Supply System' => 'Palivový systém', 'Clutch' => 'Spojka',
        'Crankshaft Drive' => 'Kľukový hriadeľ', 'Belt Drive' => 'Remeňový pohon', 'Ignition System' => 'Zapaľovanie',
        'Heating / Ventilation' => 'Kúrenie / Ventilácia', 'Air Conditioning' => 'Klimatizácia',
        'Starter System' => 'Štartovací systém', 'Electric Universal Parts' => 'Elektrické diely',
        'Interior Equipment' => 'Interiérové vybavenie', 'Safety Systems' => 'Bezpečnostné systémy',
        'Comfort Systems' => 'Komfortné systémy', 'Signal System' => 'Signalizačný systém',
        'Central Electrics' => 'Centrálna elektrika', 'Instruments' => 'Prístroje',
        'Window Cleaning' => 'Čistenie okien', 'Axle Drive' => 'Nápravový pohon',
        'Manual Transmission' => 'Manuálna prevodovka', 'Automatic Transmission' => 'Automatická prevodovka',
        'Wheels' => 'Kolesá', 'Suspension' => 'Odpruženie', 'Accessories' => 'Príslušenstvo',
        'Filter Set' => 'Sada filtrov', 'Service/Maintenance' => 'Servis / Údržba',
        'Compressed-air System' => 'Pneumatický systém', 'Other' => 'Ostatné',
        'Secondary Air Injection' => 'Sekundárny prívod vzduchu',
        'Exhaust Gas Recirculation (EGR)' => 'Recirkulácia výfukových plynov (EGR)',
        'Trailer Hitch' => 'Ťažné zariadenie', 'Final Drive' => 'Konečný prevod',
        'Brake Disc' => 'Brzdový kotúč', 'Brake Pad Set, disc brake' => 'Brzdové platničky',
        'Brake Caliper' => 'Brzdový strmeň', 'Brake Drum' => 'Brzdový bubon',
        'Brake Shoe Set' => 'Brzdové čeľuste', 'Brake Hose' => 'Brzdová hadica',
        'Brake Master Cylinder' => 'Hlavný brzdový valec', 'Brake Booster' => 'Posilňovač bŕzd',
        'Brake Light Switch' => 'Spínač brzdových svetiel',
        'Shock Absorber' => 'Tlmič pérovaní', 'Coil Spring' => 'Pružina',
        'Track Control Arm' => 'Rameno zavesenia', 'Ball Joint' => 'Guľový čap',
        'Rod/Strut, stabiliser' => 'Tyčka stabilizátora', 'Wheel Bearing' => 'Ložisko kolesa',
        'Wheel Bearing Kit' => 'Sada ložiska kolesa', 'Wheel Hub' => 'Náboj kolesa',
        'Top Strut Mounting' => 'Horné uloženie tlmiča', 'Tie Rod End' => 'Čap spojovacej tyče',
        'Tie Rod Axle Joint' => 'Axiálny kĺb riadenia', 'Rod Assembly' => 'Spojovacie tyče',
        'Stub Axle, wheel suspension' => 'Čap náboja kolesa',
        'Drive Shaft' => 'Hnací hriadeľ', 'Joint Kit, drive shaft' => 'Sada kĺbu hriadeľa',
        'Bellow Set, drive shaft' => 'Manžeta hriadeľa',
        'Headlight' => 'Predný svetlomet', 'Combination Rearlight' => 'Zadné svetlo',
        'Fog Light' => 'Hmlové svetlo', 'Indicator' => 'Smerovka',
        'Radiator, engine cooling' => 'Chladič motora', 'Water Pump' => 'Vodné čerpadlo',
        'Thermostat, coolant' => 'Termostat', 'Radiator Hose' => 'Hadica chladiča',
        'Fan, radiator' => 'Ventilátor chladiča', 'Expansion Tank, coolant' => 'Expanzná nádobka',
        'Alternator' => 'Alternátor', 'Starter' => 'Štartér',
        'Ignition Coil' => 'Zapaľovacia cievka', 'Ignition Cable Kit' => 'Zapaľovacie káble',
        'Timing Belt' => 'Rozvodový remeň', 'Timing Belt Set' => 'Sada rozvodového remeňa',
        'Timing Chain' => 'Rozvodová reťaz', 'Timing Chain Kit' => 'Sada rozvodovej reťaze',
        'Oil Filter' => 'Olejový filter', 'Air Filter' => 'Vzduchový filter',
        'Fuel filter' => 'Palivový filter', 'Filter, interior air' => 'Filter kabíny',
        'Fuel Pump' => 'Palivové čerpadlo', 'Injector' => 'Vstrekovač',
        'Lambda Sensor' => 'Lambda sonda', 'Throttle body' => 'Škrtiaca klapka',
        'Clutch Kit' => 'Sada spojky', 'Clutch Disc' => 'Lamela spojky',
        'Clutch Pressure Plate' => 'Prítlačný tanier spojky', 'Clutch Releaser' => 'Vyraďovacie ložisko',
        'Flywheel' => 'Zotrvačník', 'Clutch Cable' => 'Lanko spojky',
        'Engine Mounting' => 'Silentblok motora', 'Oil Pump' => 'Olejové čerpadlo',
        'Oil sump' => 'Olejová vaňa', 'Gasket Set, cylinder head' => 'Sada tesnení hlavy',
        'Gasket, cylinder head' => 'Tesnenie hlavy valcov', 'Gasket, cylinder head cover' => 'Tesnenie ventilového krytu',
        'Cylinder Head Cover' => 'Kryt hlavy valcov', 'Piston' => 'Piest',
        'Piston Ring Kit' => 'Sada piestnych krúžkov', 'Camshaft' => 'Vačkový hriadeľ',
        'EGR Valve' => 'EGR ventil', 'Compressor, air conditioning' => 'Kompresor klimatizácie',
        'Condenser, air conditioning' => 'Kondenzátor klimatizácie',
        'Heat Exchanger, interior heating' => 'Výmenník tepla (kúrenie)',
        'Interior Blower' => 'Ventilátor kúrenia',
        'Bumper' => 'Nárazník', 'Front Cowling' => 'Predná maska',
        'Radiator Grille' => 'Mriežka chladiča', 'Outside Mirror' => 'Vonkajšie zrkadlo',
        'Door Handle' => 'Kľučka dverí', 'Door Lock' => 'Zámok dverí',
        'Window Regulator' => 'Sťahovačka okien',
        'Wiper Blade' => 'Stierač', 'Wiper Motor' => 'Motor stieračov',
        'Hydraulic Pump, steering system' => 'Čerpadlo posilňovača riadenia',
        'Steering Gear' => 'Riadenie - prevodka',
        'Holder, exhaust system' => 'Držiak výfuku',
        'Headlight Cleaning' => 'Čistenie svetlometov',
        'Gas Spring, bonnet' => 'Plynová vzpera kapoty',
        'Gas Spring, boot-/cargo area' => 'Plynová vzpera kufra',
        'Spoiler' => 'Spojler', 'Mudguard' => 'Blatník',
        'Cover, outside mirror' => 'Kryt zrkadla',
        'Sensor, wheel speed' => 'Snímač otáčok kolesa (ABS)',
        'Sensor, parking assist' => 'Parkovací senzor',
        'Warning Contact, brake pad wear' => 'Snímač opotrebenia brzdového obloženia',
        'Wheel Brake Cylinder' => 'Brzdový valček',
        'Repair Kit, brake caliper' => 'Opravná sada strmeňa',
        'Splash Panel, brake disc' => 'Ochranný plech kotúča',
        'Vacuum Pump, brake system' => 'Vákuové čerpadlo bŕzd',
        'Control Arm-/Trailing Arm Bush' => 'Silentblok ramena',
        'Rubber Buffer, suspension' => 'Gumový doraz pérovaní',
        'Stabiliser Mounting' => 'Silentblok stabilizátora',
        'Protective Cap/Bellow, shock absorber' => 'Manžeta tlmiča',
        'Dust Cover Kit, shock absorber' => 'Sada krytu tlmiča',
        'Spring Cap' => 'Podložka pružiny',
        'Charger, charging system' => 'Turbodúchadlo',
        'Charger Air Hose' => 'Hadica turbodúchadla',
        'Intercooler, charger' => 'Medzichladič',
        'High Pressure Pump' => 'Vysokotlakové čerpadlo',
        'Air Mass Sensor' => 'Snímač hmotnosti vzduchu',
        'Sensor, camshaft position' => 'Snímač polohy vačkového hriadeľa',
        'Sensor, crankshaft pulse' => 'Snímač otáčok kľukového hriadeľa',
        'Sensor, coolant temperature' => 'Snímač teploty chladiacej kvapaliny',
        'Knock Sensor' => 'Snímač klepania',
        'Coolant Flange' => 'Príruba chladiacej kvapaliny',
        'Coolant Tube' => 'Rúrka chladiacej kvapaliny',
        'Cooler, exhaust gas recirculation' => 'Chladič EGR',
        'V-Ribbed Belts' => 'Drážkové remene', 'V-Belt' => 'Klinový remeň',
        'Belt Tensioner, v-ribbed belt' => 'Napínač remeňa',
        'Tensioner Pulley, v-ribbed belt' => 'Napínacia kladka remeňa',
        'Deflection/Guide Pulley, v-ribbed belt' => 'Vodiaca kladka remeňa',
        'Belt Pulley, crankshaft' => 'Remenica kľukového hriadeľa',
        'Tensioner Pulley, timing belt' => 'Napínacia kladka rozvodu',
        'Deflection/Guide Pulley, timing belt' => 'Vodiaca kladka rozvodu',
        'Tensioner, timing chain' => 'Napínač reťaze', 'Guides, timing chain' => 'Vodítko reťaze',
        'Alternator Freewheel Clutch' => 'Voľnobežka alternátora',
        'Shaft Seal, crankshaft' => 'Gufero kľukového hriadeľa',
        'Camshaft Adjuster' => 'Nastavovač vačkového hriadeľa',
        'Central Valve, camshaft adjustment' => 'Riadiaci ventil nastavovača',
        'Gasket, exhaust manifold' => 'Tesnenie výfukového potrubia',
        'Gasket, intake manifold' => 'Tesnenie sacieho potrubia',
        'Gasket, oil sump' => 'Tesnenie olejovej vane',
        'Inlet Valve' => 'Sacie ventily', 'Outlet valve' => 'Výfukové ventily',
        'Cam Follower' => 'Zdvihátko ventilov', 'Finger Follower, engine timing' => 'Vahadlo ventilov',
        'Cover, timing belt' => 'Kryt rozvodového remeňa',
    ],
    'de' => [
        'Body' => 'Karosserie', 'Brake System' => 'Bremsanlage', 'Cooling System' => 'Kühlsystem',
        'Lights' => 'Beleuchtung', 'Wheel Suspension' => 'Radaufhängung', 'Air Supply' => 'Luftversorgung',
        'Mixture Formation' => 'Gemischaufbereitung', 'Cylinder Head' => 'Zylinderkopf', 'Lock System' => 'Schließanlage',
        'Exhaust System' => 'Abgasanlage', 'Engine Timing' => 'Motorsteuerung', 'Lubrication' => 'Schmierung',
        'Steering' => 'Lenkung', 'Fuel Supply System' => 'Kraftstoffförderanlage', 'Clutch' => 'Kupplung',
        'Crankshaft Drive' => 'Kurbeltrieb', 'Belt Drive' => 'Riementrieb', 'Ignition System' => 'Zündanlage',
        'Heating / Ventilation' => 'Heizung / Belüftung', 'Air Conditioning' => 'Klimaanlage',
        'Starter System' => 'Startanlage', 'Electric Universal Parts' => 'Elektrische Universalteile',
        'Interior Equipment' => 'Innenausstattung', 'Safety Systems' => 'Sicherheitssysteme',
        'Comfort Systems' => 'Komfortsysteme', 'Signal System' => 'Signalanlage',
        'Central Electrics' => 'Zentralelektrik', 'Instruments' => 'Instrumente',
        'Window Cleaning' => 'Scheibenreinigung', 'Axle Drive' => 'Achsantrieb',
        'Manual Transmission' => 'Schaltgetriebe', 'Automatic Transmission' => 'Automatikgetriebe',
        'Wheels' => 'Räder', 'Suspension' => 'Federung', 'Accessories' => 'Zubehör',
        'Filter Set' => 'Filtersatz', 'Service/Maintenance' => 'Service / Wartung',
        'Compressed-air System' => 'Druckluftanlage', 'Other' => 'Sonstiges',
        'Secondary Air Injection' => 'Sekundärlufteinblasung',
        'Exhaust Gas Recirculation (EGR)' => 'Abgasrückführung (AGR)',
        'Trailer Hitch' => 'Anhängevorrichtung', 'Final Drive' => 'Achsgetriebe',
        'Brake Disc' => 'Bremsscheibe', 'Brake Pad Set, disc brake' => 'Bremsbelagsatz',
        'Brake Caliper' => 'Bremssattel', 'Shock Absorber' => 'Stoßdämpfer',
        'Track Control Arm' => 'Lenker', 'Wheel Bearing' => 'Radlager',
        'Headlight' => 'Hauptscheinwerfer', 'Combination Rearlight' => 'Heckleuchte',
        'Radiator, engine cooling' => 'Kühler, Motorkühlung', 'Water Pump' => 'Wasserpumpe',
        'Thermostat, coolant' => 'Thermostat', 'Alternator' => 'Lichtmaschine', 'Starter' => 'Starter',
        'Timing Belt' => 'Zahnriemen', 'Timing Chain' => 'Steuerkette',
        'Oil Filter' => 'Ölfilter', 'Air Filter' => 'Luftfilter',
        'Fuel filter' => 'Kraftstofffilter', 'Filter, interior air' => 'Innenraumfilter',
        'Clutch Kit' => 'Kupplungssatz', 'Engine Mounting' => 'Motorlager',
        'Wiper Blade' => 'Wischblatt', 'Bumper' => 'Stoßfänger',
        'Outside Mirror' => 'Außenspiegel', 'Door Handle' => 'Türgriff',
        'Window Regulator' => 'Fensterheber',
        'Charger, charging system' => 'Turbolader',
        'Compressor, air conditioning' => 'Klimakompressor',
        'Crankcase' => 'Kurbelgehäuse',
    ],
];
}

// CLI entry point (only when invoked as script, not when required from tests)
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $force = in_array('--force', $argv, true);
    $db = Db::get();
    $total = (int)$db->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    echo "Found {$total} categories in DB.\n";
    [$updated, $skipped, $noMatch] = migrateCatTranslations($force);
    echo "Updated:  {$updated}\n";
    echo "Skipped:  {$skipped} (already had translations; use --force to overwrite)\n";
    echo "No match: {$noMatch} (category name not in bundled defaults)\n";
    echo "Done.\n";
}
