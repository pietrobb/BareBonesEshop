<?php
/**
 * BareBones Shop — Storefront
 * Generic catalog with conditional TecDoc auto-parts features.
 */
require_once __DIR__ . '/src/autoload.php';

Security::headers();
Security::sessionStart();
if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];

// Locale from URL — canonical, no session override
$uri = $_SERVER['REQUEST_URI'] ?? '';
$lang = I18n::detectLocale($uri);
I18n::setLocale($lang);

// Legacy fallback for category names not yet migrated to categories.translations JSON
// (run `php cli/migrate-cat-translations.php` to persist these into the DB).
$catTranslations = [
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
        // Subcategories
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
        'Headlight Base' => 'Pätica svetlometu',
        'Auxiliary Indicator' => 'Prídavná smerovka',
        'Reflex Reflector' => 'Odrazka',
        'Cover, light' => 'Kryt svetla',
        'Wiper Linkage' => 'Mechanizmus stieračov',
        'Wiper Arm, window cleaning' => 'Rameno stierača',
        'Wiper Switch' => 'Páčka stieračov',
        'Washer Fluid Jet, windscreen' => 'Tryska ostrekovača',
        'Washer Fluid Jet, headlight cleaning' => 'Tryska ostrekovača svetlometu',
        'Washer Fluid Tank, window cleaning' => 'Nádržka ostrekovačov',
        'Water Pump, window cleaning' => 'Čerpadlo ostrekovačov',
        'Fanfare Horn' => 'Klaxón',
        'Hazard Light Switch' => 'Spínač výstražných svetiel',
        'Steering Column Switch' => 'Prepínač pod volantom',
        'Multi-Function Switch' => 'Multifunkčný prepínač',
        'Switch, headlight' => 'Spínač svetiel',
        'Switch, window regulator' => 'Spínač sťahovačky okien',
        'Switch, mirror adjustment' => 'Spínač nastavenia zrkadla',
        'Switch, door lock system' => 'Spínač zámku dverí',
        'Mirror Glass, outside mirror' => 'Sklo zrkadla',
        'Steering Lock' => 'Zámok volantu',
        'Control, central locking system' => 'Riadenie centrálneho zamykania',
        'Ignition-/Starter Switch' => 'Spínacia skrinka',
        'Boot Lock' => 'Zámok kufra', 'Bonnet Lock' => 'Zámok kapoty',
        'Tailgate Lock' => 'Zámok zadných dverí',
        'Door Catch' => 'Západka dverí',
        'Safety Hook, bonnet lock' => 'Bezpečnostný háčik kapoty',
        'Hinge, bonnet' => 'Pánty kapoty',
        'Sunroof' => 'Strešné okno',
        'Oil Cooler, engine oil' => 'Chladič oleja',
        'Oil Pressure Switch' => 'Spínač tlaku oleja',
        'Oil Dipstick' => 'Mierka oleja',
        'Housing, oil filter' => 'Kryt olejového filtra',
        'Seal, oil filter housing' => 'Tesnenie krytu olejového filtra',
        'Oil Trap, crankcase breather' => 'Odlučovač oleja',
        'Hose, crankcase breather' => 'Hadica odvzdušnenia',
        'Hose, cylinder head cover breather' => 'Hadica odvzdušnenia krytu',
        'Regulator, passenger compartment fan' => 'Regulátor ventilátora kúrenia',
        'Resistor, interior blower' => 'Odpor ventilátora kúrenia',
        'Sealing Cap, coolant tank' => 'Uzáver expanznej nádobky',
        'Fan Wheel, engine cooling' => 'Koleso ventilátora chladenia',
        'Temperature Switch, radiator fan' => 'Teplotný spínač ventilátora',
        'Mounting, radiator' => 'Uloženie chladiča',
        'Relay, radiator fan castor' => 'Relé ventilátora chladiča',
        'Sensor, exhaust gas temperature' => 'Snímač teploty výfukových plynov',
        'Sensor, exhaust pressure' => 'Snímač tlaku výfukových plynov',
        'Sensor, fuel pressure' => 'Snímač tlaku paliva',
        'Sensor, intake manifold pressure' => 'Snímač tlaku v sacom potrubí',
        'Sensor, engine oil level' => 'Snímač hladiny oleja',
        'Sensor, headlight range adjustment' => 'Snímač regulácie sklonu svetiel',
        'Control Unit, fuel pump' => 'Riadiaca jednotka palivového čerpadla',
        'Sender Unit, fuel tank' => 'Snímač hladiny paliva',
        'Valve, activated carbon filter' => 'Ventil palivového filtra',
        'Valve, engine block breather' => 'Ventil odvzdušnenia bloku',
        'Valve, secondary air pump system' => 'Ventil sekundárneho vzduchu',
        'Control Valve, air intake' => 'Regulačný ventil prívodu vzduchu',
        'Control Valve, coolant' => 'Regulačný ventil chladiacej kvapaliny',
        'Boost Pressure Control Valve' => 'Regulačný ventil tlaku turba',
        'Intake Manifold, air supply' => 'Sacie potrubie',
        'Intake Hose, air filter' => 'Hadica vzduchového filtra',
        'Hose, fuel overflow' => 'Hadica paliva (spiatočka)',
        'Hydraulic Filter, automatic transmission' => 'Filter automatickej prevodovky',
        'Oil Cooler, automatic transmission' => 'Chladič oleja prevodovky',
        'Oil sump, automatic transmission' => 'Olejová vaňa prevodovky',
        'Propshaft, axle drive' => 'Kardanový hriadeľ',
        'Shaft Seal, differential' => 'Gufero diferenciálu',
        'Mounting, axle beam' => 'Silentblok nápravy',
        'Axle Beam' => 'Nápravnica',
        'Support Frame/Engine Carrier' => 'Pomocný rám',
        'Repair Kit, stabilizer suspension' => 'Opravná sada stabilizátora',
        'Anti-Friction Bearing, suspension strut support mounting' => 'Ložisko horného uloženia',
        'Air Spring Strut' => 'Vzduchová pružina',
        'Mounting, stabilizer coupling rod' => 'Silentblok spoj. tyčky stabilizátora',
        'Additional Water Pump' => 'Prídavné vodné čerpadlo',
        'Mounting Kit, charger' => 'Montážna sada turba',
        'Gasket, timing case cover' => 'Tesnenie krytu rozvodu',
        'Crankshaft Bearings' => 'Ložiská kľukového hriadeľa',
        'Big End Bearings' => 'Ojničné ložiská',
        'Crankcase' => 'Blok motora',
        'Car Cover' => 'Autoplachta',
        'Accelerator Cable' => 'Lanko plynu',
        'Bonnet Cable' => 'Lanko kapoty',
        'Cable, parking brake' => 'Lanko ručnej brzdy',
        'Cable, door release' => 'Lanko otvorenia dverí',
        'Cable, manual transmission' => 'Lanko prevodovky',
        'Gear Knob' => 'Hlavica radiacej páky',
        'Gear Lever' => 'Radiaca páka',
        'Roller Guide, sliding door' => 'Vodítko posuvných dverí',
        'Trim/Protective Strip, bumper' => 'Lišta nárazníka',
        'Trim/Protective Strip, door' => 'Lišta dverí',
        'Ventilation Grille, bumper' => 'Vetracia mriežka nárazníka',
        'Impact Absorber, bumper' => 'Absorbér nárazu',
        'Support, bumper' => 'Nosník nárazníka',
        'Mounting Bracket, bumper' => 'Konzola nárazníka',
        'Flap, tow hook' => 'Krytka ťažného háku',
        'Inner Wing Panel' => 'Vnútorný blatník',
        'Inner Door-sill' => 'Vnútorný prah dverí',
        'Panelling, mudguard' => 'Obloženie blatníka',
        'Mounting, mudguard holder' => 'Držiak blatníka',
        'Support, radiator grille' => 'Nosník mriežky chladiča',
        'Licence Plate Holder' => 'Držiak ŠPZ',
        'Holder' => 'Držiak',
        'Silencing Material, engine bay' => 'Izolačný materiál motora',
        'Compressed-air System' => 'Pneumatický systém',
        'Compressor, compressed air system' => 'Kompresor pneum. systému',
        'Control, seat adjustment' => 'Ovládanie sedadla',
        'Clockspring, airbag' => 'Krúžok airbagu',
        'Relay, main current' => 'Hlavné relé',
        'Distributor, ignition' => 'Rozdeľovač zapaľovania',
        'Distributor Cap' => 'Viečko rozdeľovača',
        'Freewheel Gear, starter' => 'Voľnobežka štartéra',
        'Carburettor' => 'Karburátor',
        'Expansion Tank, power steering hydraulic oil' => 'Nádržka posilňovača riadenia',
        'Bellow, steering' => 'Manžeta riadenia',
        'Valve' => 'Ventil',
        'Selector-/Shift Rod' => 'Radiaca tyč',
        'Bush, shift rod' => 'Puzdro radiacej tyče',
        'Bearing, manual transmission' => 'Ložisko prevodovky',
        'Gear Wheel, transmission input shaft' => 'Ozubené koleso vstupného hriadeľa',
        'Repair Kit, gear lever' => 'Opravná sada radiacej páky',
        'Master Cylinder, clutch' => 'Hlavný valec spojky',
        'Slave Cylinder, clutch' => 'Pracovný valec spojky',
        'Suction Pipe, oil pump' => 'Sacie potrubie olejového čerpadla',
        'Funnel, oil dipstick' => 'Lievík mierky oleja',
        'Cover, oil filter housing' => 'Kryt olejového filtra',
        'Gasket Set, brake caliper' => 'Sada tesnení brzdového strmeňa',
        'Carrier, brake caliper' => 'Nosič brzdového strmeňa',
        'Guide Sleeve Kit, brake caliper' => 'Vodiace čapy strmeňa',
        'Vacuum Hose, brake system' => 'Vákuová hadica bŕzd',
        'Switch, park brake actuation' => 'Spínač ručnej brzdy',
        'Switch, reverse light' => 'Spínač cúvacích svetiel',
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
        'Thermostat, coolant' => 'Thermostat', 'Alternator' => 'Generator', 'Starter' => 'Starter',
        'Timing Belt' => 'Zahnriemen', 'Timing Chain' => 'Steuerkette',
        'Oil Filter' => 'Ölfilter', 'Air Filter' => 'Luftfilter',
        'Fuel filter' => 'Kraftstofffilter', 'Filter, interior air' => 'Innenraumfilter',
        'Clutch Kit' => 'Kupplungssatz', 'Engine Mounting' => 'Motorlager',
        'Wiper Blade' => 'Wischblatt', 'Bumper' => 'Stoßfänger',
        'Outside Mirror' => 'Außenspiegel', 'Door Handle' => 'Türgriff',
        'Window Regulator' => 'Fensterheber', 'Alternator' => 'Lichtmaschine',
        'Charger, charging system' => 'Turbolader',
        'Compressor, air conditioning' => 'Klimakompressor',
        'Crankcase' => 'Kurbelgehäuse',
    ],
];
// Build DB-backed name→locale→translated lookup from categories.translations JSON
// (populated via `php cli/migrate-cat-translations.php`). Falls back to hardcoded
// $catTranslations array for categories not yet migrated.
$catTranslationsDb = [];
try {
    $catRows = Db::get()->query("SELECT name, translations FROM categories WHERE translations IS NOT NULL AND translations != '' AND translations != '{}'")->fetchAll();
    foreach ($catRows as $cr) {
        $tr = json_decode($cr['translations'] ?? '{}', true) ?: [];
        foreach ($tr as $lc => $data) {
            if (!empty($data['name'])) {
                $catTranslationsDb[$lc][$cr['name']] = $data['name'];
            }
        }
    }
} catch (\Throwable $ignore) { /* table missing during install */ }

$catName = function(string $name) use ($catTranslations, $catTranslationsDb, $lang): string {
    return $catTranslationsDb[$lang][$name] ?? $catTranslations[$lang][$name] ?? $name;
};

// UI strings — minimal set for multilingual frontend
$ui = [
    'sk' => [
        'home'=>'Domov','search_placeholder'=>'Hľadať produkty...','search'=>'Hľadať','cart'=>'Košík',
        'categories'=>'Kategórie','latest'=>'Najnovšie produkty','add_to_cart'=>'Do košíka',
        'vehicle_parts'=>'Produkty','parts_count'=>'produktov','results_for'=>'výsledkov pre',
        'search_results'=>'Výsledky hľadania','oe_search'=>'Hľadanie podľa OE čísla',
        'oe_placeholder'=>'Hľadať podľa OE čísla (napr. 1K0 615 301)','oe_search_btn'=>'OE Hľadať',
        'in_stock'=>'Skladom','out_of_stock'=>'Nedostupné','supplier'=>'Dodávateľ',
        'compatible_with'=>'Kompatibilné s','vehicles'=>'vozidlami',
        'specs'=>'Špecifikácie','oe_numbers'=>'OE čísla','vehicles_tab'=>'Vozidlá',
        'no_specs'=>'Žiadne špecifikácie.','no_oe'=>'Žiadne OE čísla.','no_vehicles'=>'Žiadne vozidlá.',
        'no_results'=>'Žiadne výsledky.','no_parts_filter'=>'Žiadne diely pre zvolené filtre.',
        'subcategories'=>'Podkategórie',
        'price'=>'Cena','sort'=>'Zoradiť:','filter'=>'Filtrovať','clear_filter'=>'Zrušiť',
        'price_asc'=>'Cena ↑','price_desc'=>'Cena ↓','name_asc'=>'Názov A-Z','name_desc'=>'Názov Z-A','newest'=>'Najnovšie',
        'cart_title'=>'Nákupný košík','cart_empty'=>'Váš košík je prázdny','continue_shopping'=>'Pokračovať v nákupe',
        'product'=>'Produkt','quantity'=>'Množstvo','total'=>'Spolu','remove'=>'Odstrániť',
        'order_summary'=>'Súhrn objednávky','subtotal'=>'Medzisúčet','shipping'=>'Doprava',
        'free_shipping'=>'Zadarmo','free_over'=>'Doprava zadarmo od','total_price'=>'Celkom',
        'to_checkout'=>'Pokračovať k objednávke','clear_cart'=>'Vyprázdniť košík',
        'checkout_title'=>'Dokončiť objednávku','contact'=>'Kontaktné údaje',
        'name'=>'Meno a priezvisko','email'=>'E-mail','phone'=>'Telefón',
        'address'=>'Doručovacia adresa','street'=>'Ulica a číslo','city'=>'Mesto','zip'=>'PSČ',
        'payment'=>'Platba','payment_method'=>'Spôsob platby',
        'cod'=>'Dobierka (+1,50 €)','transfer'=>'Bankový prevod','card'=>'Kartou online',
        'notes'=>'Poznámka k objednávke','notes_placeholder'=>'Voliteľné...',
        'submit_order'=>'Odoslať objednávku','your_order'=>'Vaša objednávka',
        'order_sent'=>'Objednávka odoslaná!','order_thank'=>'Ďakujeme za vašu objednávku.',
        'order_num'=>'Číslo objednávky','order_email'=>'Potvrdenie sme poslali na váš e-mail.',
        'mfr'=>'— Výrobca —','model'=>'— Model —','engine'=>'— Typ motora —','show_parts'=>'Zobraziť diely',
        'cat_not_found'=>'Kategória nenájdená.','product_not_found'=>'Produkt nenájdený.',
    ],
    'en' => [
        'home'=>'Home','search_placeholder'=>'Search products...','search'=>'Search','cart'=>'Cart',
        'categories'=>'Categories','latest'=>'Latest Products','add_to_cart'=>'Add to Cart',
        'vehicle_parts'=>'Products','parts_count'=>'products','results_for'=>'results for',
        'search_results'=>'Search Results','oe_search'=>'OE Number Search',
        'oe_placeholder'=>'Search by OE number (e.g. 1K0 615 301)','oe_search_btn'=>'OE Search',
        'in_stock'=>'In Stock','out_of_stock'=>'Out of Stock','supplier'=>'Supplier',
        'compatible_with'=>'Compatible with','vehicles'=>'vehicles',
        'specs'=>'Specifications','oe_numbers'=>'OE Numbers','vehicles_tab'=>'Vehicles',
        'no_specs'=>'No specifications.','no_oe'=>'No OE numbers.','no_vehicles'=>'No vehicles.',
        'no_results'=>'No results.','no_parts_filter'=>'No parts for selected filters.',
        'subcategories'=>'Subcategories',
        'price'=>'Price','sort'=>'Sort:','filter'=>'Filter','clear_filter'=>'Clear',
        'price_asc'=>'Price ↑','price_desc'=>'Price ↓','name_asc'=>'Name A-Z','name_desc'=>'Name Z-A','newest'=>'Newest',
        'cart_title'=>'Shopping Cart','cart_empty'=>'Your cart is empty','continue_shopping'=>'Continue Shopping',
        'product'=>'Product','quantity'=>'Quantity','total'=>'Total','remove'=>'Remove',
        'order_summary'=>'Order Summary','subtotal'=>'Subtotal','shipping'=>'Shipping',
        'free_shipping'=>'Free','free_over'=>'Free shipping over','total_price'=>'Total',
        'to_checkout'=>'Proceed to Checkout','clear_cart'=>'Clear Cart',
        'checkout_title'=>'Complete Order','contact'=>'Contact Details',
        'name'=>'Full Name','email'=>'E-mail','phone'=>'Phone',
        'address'=>'Delivery Address','street'=>'Street','city'=>'City','zip'=>'ZIP Code',
        'payment'=>'Payment','payment_method'=>'Payment Method',
        'cod'=>'Cash on Delivery (+€1.50)','transfer'=>'Bank Transfer','card'=>'Card Online',
        'notes'=>'Order Notes','notes_placeholder'=>'Optional...',
        'submit_order'=>'Submit Order','your_order'=>'Your Order',
        'order_sent'=>'Order Submitted!','order_thank'=>'Thank you for your order.',
        'order_num'=>'Order Number','order_email'=>'Confirmation sent to your e-mail.',
        'mfr'=>'— Manufacturer —','model'=>'— Model —','engine'=>'— Engine Type —','show_parts'=>'Show Parts',
        'cat_not_found'=>'Category not found.','product_not_found'=>'Product not found.',
    ],
    'de' => [
        'home'=>'Startseite','search_placeholder'=>'Produkte suchen...','search'=>'Suchen','cart'=>'Warenkorb',
        'categories'=>'Kategorien','latest'=>'Neueste Produkte','add_to_cart'=>'In den Warenkorb',
        'vehicle_parts'=>'Produkte','parts_count'=>'Produkte','results_for'=>'Ergebnisse für',
        'search_results'=>'Suchergebnisse','oe_search'=>'OE-Nummernsuche',
        'oe_placeholder'=>'Nach OE-Nummer suchen (z.B. 1K0 615 301)','oe_search_btn'=>'OE Suchen',
        'in_stock'=>'Auf Lager','out_of_stock'=>'Nicht verfügbar','supplier'=>'Lieferant',
        'compatible_with'=>'Kompatibel mit','vehicles'=>'Fahrzeugen',
        'specs'=>'Spezifikationen','oe_numbers'=>'OE-Nummern','vehicles_tab'=>'Fahrzeuge',
        'no_specs'=>'Keine Spezifikationen.','no_oe'=>'Keine OE-Nummern.','no_vehicles'=>'Keine Fahrzeuge.',
        'no_results'=>'Keine Ergebnisse.','no_parts_filter'=>'Keine Teile für gewählte Filter.',
        'subcategories'=>'Unterkategorien',
        'price'=>'Preis','sort'=>'Sortieren:','filter'=>'Filtern','clear_filter'=>'Zurücksetzen',
        'price_asc'=>'Preis ↑','price_desc'=>'Preis ↓','name_asc'=>'Name A-Z','name_desc'=>'Name Z-A','newest'=>'Neueste',
        'cart_title'=>'Warenkorb','cart_empty'=>'Ihr Warenkorb ist leer','continue_shopping'=>'Weiter einkaufen',
        'product'=>'Produkt','quantity'=>'Menge','total'=>'Gesamt','remove'=>'Entfernen',
        'order_summary'=>'Bestellübersicht','subtotal'=>'Zwischensumme','shipping'=>'Versand',
        'free_shipping'=>'Kostenlos','free_over'=>'Kostenloser Versand ab','total_price'=>'Gesamt',
        'to_checkout'=>'Zur Kasse','clear_cart'=>'Warenkorb leeren',
        'checkout_title'=>'Bestellung abschließen','contact'=>'Kontaktdaten',
        'name'=>'Vor- und Nachname','email'=>'E-Mail','phone'=>'Telefon',
        'address'=>'Lieferadresse','street'=>'Straße und Hausnummer','city'=>'Stadt','zip'=>'PLZ',
        'payment'=>'Zahlung','payment_method'=>'Zahlungsart',
        'cod'=>'Nachnahme (+1,50 €)','transfer'=>'Banküberweisung','card'=>'Karte online',
        'notes'=>'Bestellnotiz','notes_placeholder'=>'Optional...',
        'submit_order'=>'Bestellung absenden','your_order'=>'Ihre Bestellung',
        'order_sent'=>'Bestellung aufgegeben!','order_thank'=>'Vielen Dank für Ihre Bestellung.',
        'order_num'=>'Bestellnummer','order_email'=>'Bestätigung wurde an Ihre E-Mail gesendet.',
        'mfr'=>'— Hersteller —','model'=>'— Modell —','engine'=>'— Motortyp —','show_parts'=>'Teile anzeigen',
        'cat_not_found'=>'Kategorie nicht gefunden.','product_not_found'=>'Produkt nicht gefunden.',
    ],
];
$t = function(string $key) use ($ui, $lang): string {
    return $ui[$lang][$key] ?? $ui['sk'][$key] ?? $key;
};

// Cart actions (POST) — handle before any output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['cart_action'])) {
    if (!Security::csrfValidate()) {
        http_response_code(403);
        exit('CSRF token mismatch');
    }
    $pid = Security::int($_POST['product_id'] ?? 0);
    $qty = max(0, Security::int($_POST['quantity'] ?? 1));
    switch ($_POST['cart_action']) {
        case 'add':
            if ($pid > 0 && $qty > 0) {
                $_SESSION['cart'][$pid] = ($_SESSION['cart'][$pid] ?? 0) + $qty;
            }
            break;
        case 'update':
            if ($pid > 0) {
                if ($qty > 0) $_SESSION['cart'][$pid] = $qty;
                else unset($_SESSION['cart'][$pid]);
            }
            break;
        case 'remove':
            unset($_SESSION['cart'][$pid]);
            break;
        case 'clear':
            $_SESSION['cart'] = [];
            break;
    }
    // AJAX request → return JSON
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['count' => array_sum($_SESSION['cart']), 'cart' => $_SESSION['cart']]);
        exit;
    }
    // Regular POST → redirect back (validate: only relative URLs)
    $redirect = $_POST['redirect'] ?? '?';
    if (!str_starts_with($redirect, '?') && !str_starts_with($redirect, '/')) {
        $redirect = '?';
    }
    header('Location: ' . $redirect);
    exit;
}

$cartCount = array_sum($_SESSION['cart']);

// Detect catalog type — conditionally enable TecDoc features
$_catalog = json_decode(file_get_contents(__DIR__ . '/catalog.json'), true);
$hasTecDoc = isset($_catalog['product_types']['auto_part']);

// Route: support both ?page=X and URL-rewritten ?category=slug
$page = Security::clean($_GET['page'] ?? '');
if (!$page && !empty($_GET['category'])) $page = 'category';
if (!$page) $page = 'home';

$e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$price = fn(int $cents) => I18n::price($cents, 'eur');
$csrfField = '<input type="hidden" name="csrf_token" value="' . Security::csrfToken() . '">';

// ============================================================
// Route & fetch data
// ============================================================
$title = 'BareBones Shop';
$content = '';

switch ($page) {
    case 'category':
        $slug = Security::clean($_GET['slug'] ?? $_GET['category'] ?? '');
        $cat = Categories::bySlug($slug);
        if (!$cat) { $content = '<p>' . $t('cat_not_found') . '</p>'; break; }
        $title = $e($catName($cat['name'])) . ' — BareBones Shop';
        $content = renderCategory($cat);
        break;

    case 'product':
        $slug = Security::clean($_GET['slug'] ?? '');
        $product = Products::bySlug($slug);
        if (!$product) { $content = '<p>' . $t('product_not_found') . '</p>'; break; }
        if ($hasTecDoc) $product = TecDoc::enrichProduct($product, $lang);
        $product = Products::enrich($product);
        $title = $e($product['name']) . ' — BareBones Shop';
        $content = renderProduct($product);
        break;

    case 'vehicle':
        if (!$hasTecDoc) { $content = '<p>Not available</p>'; break; }
        $ktype = Security::int($_GET['ktype'] ?? 0);
        $catId = !empty($_GET['cat']) ? Security::int($_GET['cat']) : null;
        $pg = max(1, Security::int($_GET['pg'] ?? 1));
        $result = TecDoc::partsByKtype($ktype, $pg, 24, $catId);
        $cats = TecDoc::categoriesForKtype($ktype);
        $title = $t('vehicle_parts') . ' — BareBones Shop';
        $content = renderVehicle($ktype, $result, $cats, $catId);
        break;

    case 'search':
        $q = Security::clean($_GET['q'] ?? '');
        $pg = max(1, Security::int($_GET['pg'] ?? 1));
        $result = Adapters::search()->search($q, ['page' => $pg, 'per_page' => 24]);
        $title = "{$t('search')}: {$e($q)} — BareBones Shop";
        $content = renderSearch($q, $result);
        break;

    case 'oe':
        if (!$hasTecDoc) { $content = '<p>Not available</p>'; break; }
        $q = Security::clean($_GET['q'] ?? '');
        $pg = max(1, Security::int($_GET['pg'] ?? 1));
        $result = TecDoc::searchByOE($q, $pg, 24);
        $title = "OE: {$e($q)} — BareBones Shop";
        $content = renderOE($q, $result);
        break;

    case 'privacy':
    case 'terms':
    case 'cookies':
        $legalTitles = ['privacy' => 'Privacy Policy', 'terms' => 'Terms & Conditions', 'cookies' => 'Cookie Policy'];
        $title = $legalTitles[$page] . ' — BareBones Shop';
        $content = renderLegalPage($page);
        break;

    case 'cart':
        $title = $t('cart') . ' — BareBones Shop';
        $content = renderCart();
        break;

    case 'checkout':
        $title = $t('checkout') . ' — BareBones Shop';
        $content = renderCheckout();
        break;

    case 'order-done':
        $ordNum = Security::clean($_GET['order'] ?? '');
        $title = $t('order_placed') . ' — BareBones Shop';
        $content = renderOrderDone($ordNum);
        break;

    default: // home
        $content = renderHome();
        break;
}

// ============================================================
// Layout
// ============================================================
// Build hreflang alternates from stores.json — one per store url_prefix
$storesCfg = @json_decode((string)file_get_contents(__DIR__ . '/stores.json'), true) ?: [];
$hreflangs = [];
foreach ($storesCfg['stores'] ?? [] as $store) {
    $locale = $store['default_locale'] ?? 'en';
    $prefix = $store['url_prefix'] ?? '';
    $hreflangs[$locale] = $prefix;
}
$pathNoPrefix = $_SERVER['REQUEST_URI'] ?? '/';
foreach ($hreflangs as $p) {
    if ($p !== '' && str_starts_with($pathNoPrefix, $p . '/')) {
        $pathNoPrefix = substr($pathNoPrefix, strlen($p));
        break;
    }
    if ($p !== '' && $pathNoPrefix === $p) { $pathNoPrefix = '/'; break; }
}
if ($pathNoPrefix === '') $pathNoPrefix = '/';
?><!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $title ?></title>
<?php foreach ($hreflangs as $loc => $pfx): ?>
<link rel="alternate" hreflang="<?= $e($loc) ?>" href="<?= $e($pfx . $pathNoPrefix) ?>">
<?php endforeach; ?>
<link rel="alternate" hreflang="x-default" href="<?= $e(($storesCfg['stores'][$storesCfg['default_store'] ?? ''] ?? ['url_prefix' => ''])['url_prefix'] . $pathNoPrefix) ?>">
<style>
@import url('https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600&display=swap');
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f5f5f5;color:#333;line-height:1.5;font-size:14px}
a{color:#333;text-decoration:none}a:hover{color:#cc3872}
h1,h2,h3,h4,h5,.section-title{font-family:'Oswald',georgia,serif;font-weight:500}
.wrap{max-width:1280px;margin:0 auto;padding:0 16px}

/* Header */
header{background:#333;color:#fff;padding:0}
.header-top{background:#f5f5f5;padding:6px 0;font-size:.8em;color:#777;border-bottom:1px solid #e5e5e5}
.header-top .wrap{display:flex;justify-content:flex-end;gap:16px;align-items:center}
.header-top a{color:#777;font-size:.8em}.header-top a:hover{color:#cc3872}
.header-main{background:#fff;padding:12px 0;border-bottom:1px solid #e5e5e5}
.header-main .wrap{display:flex;align-items:center;gap:24px;flex-wrap:wrap}
header .logo{white-space:nowrap}
header .logo img{height:40px;display:block}
header .logo a{display:block}
.header-nav{background:#333;padding:0;position:relative;z-index:100}
.header-nav .wrap{display:flex;align-items:stretch;gap:0;flex-wrap:wrap}
.header-nav > .wrap > a,.nav-item > a{color:#fff;font-family:'Oswald',georgia,serif;font-size:15px;text-transform:uppercase;padding:14px 18px;display:block;transition:background .2s}
.header-nav > .wrap > a:hover,.nav-item:hover > a{background:#cc3872;color:#fff}
.nav-item{position:relative}
.nav-dropdown{display:none;position:absolute;top:100%;left:0;background:#fff;min-width:260px;box-shadow:0 4px 16px rgba(0,0,0,.15);border-radius:0 0 4px 4px;z-index:200;padding:8px 0}
.nav-item:hover .nav-dropdown{display:block}
.nav-dropdown a{color:#333;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;font-size:13px;text-transform:none;padding:7px 20px;display:block;transition:background .15s,color .15s}
.nav-dropdown a:hover{background:#f5f5f5;color:#cc3872}
.nav-dropdown .sub-heading{font-family:'Oswald',georgia,serif;font-size:13px;font-weight:600;color:#333;padding:10px 20px 4px;text-transform:uppercase;border-top:1px solid #eee;margin-top:4px}
.nav-dropdown .sub-heading:first-child{border-top:none;margin-top:0}
.search-bar{display:flex;gap:0;margin-left:auto}
.search-bar input{padding:8px 14px;border:1px solid #e5e5e5;border-right:none;border-radius:4px 0 0 4px;font-size:.9em;width:220px;background:#fff;color:#333}
.search-bar button{background:#cc3872;border:none;color:#fff;padding:8px 16px;border-radius:0 4px 4px 0;cursor:pointer;font-weight:600}

/* Main */
main{padding:24px 0;min-height:60vh}
.breadcrumb{margin-bottom:16px;font-size:.85em;color:#666}
.breadcrumb a{color:#555}.breadcrumb a:hover{color:#cc3872}

/* Grid */
.grid{display:grid;gap:16px}
.grid-4{grid-template-columns:repeat(auto-fill,minmax(240px,1fr))}
.grid-3{grid-template-columns:repeat(auto-fill,minmax(300px,1fr))}

/* Cards */
.card{background:#fff;border-radius:8px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,.08);transition:box-shadow .2s}
.card:hover{box-shadow:0 4px 12px rgba(0,0,0,.12)}
.card h3{font-size:1em;margin-bottom:6px}
.card .price{font-size:1.2em;font-weight:700;color:#725be7}
.card .old-price{text-decoration:line-through;color:#999;font-size:.85em;margin-left:6px}
.card .supplier{font-size:.8em;color:#888;margin-bottom:4px}
.card .sku{font-size:.75em;color:#aaa}
.card .badge{display:inline-block;background:#e8f5e9;color:#2e7d32;padding:2px 8px;border-radius:10px;font-size:.75em}

/* Category cards */
.cat-card{display:flex;align-items:center;gap:12px;background:#fff;border-radius:8px;padding:14px 18px;box-shadow:0 1px 3px rgba(0,0,0,.08)}
.cat-card:hover{box-shadow:0 4px 12px rgba(0,0,0,.12)}
.cat-card .count{background:#cc3872;color:#fff;border-radius:20px;padding:2px 10px;font-size:.8em;font-weight:600;white-space:nowrap}

/* Layout with sidebar */
.layout-sidebar{display:grid;grid-template-columns:280px 1fr;gap:24px}
@media(max-width:800px){.layout-sidebar{grid-template-columns:1fr}}

/* Sidebar filters */
.sidebar{background:#fff;border-radius:8px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,.08);align-self:start;position:sticky;top:16px;max-height:90vh;overflow-y:auto}
.sidebar h3{font-size:.9em;margin:12px 0 6px;color:#555;border-bottom:1px solid #eee;padding-bottom:4px}
.sidebar h3:first-child{margin-top:0}
.sidebar label{display:block;font-size:.85em;padding:2px 0;cursor:pointer}
.sidebar label:hover{color:#cc3872}
.sidebar input[type=checkbox]{margin-right:4px}
.sidebar .cnt{color:#aaa;font-size:.8em}
.sidebar .filter-actions{margin-top:12px;display:flex;gap:8px}
.sidebar .btn-apply{background:#cc3872;color:#fff;border:none;padding:8px 16px;border-radius:4px;cursor:pointer;font-weight:600;font-size:.85em;width:100%}
.sidebar .btn-clear{background:#eee;color:#555;border:none;padding:8px 16px;border-radius:4px;cursor:pointer;font-size:.85em}

/* Hero / Slider */
.hero{position:relative;border-radius:0;margin-bottom:24px;overflow:hidden;max-height:400px}
.hero img{width:100%;display:block;object-fit:cover;max-height:400px}
.hero-overlay{position:absolute;bottom:0;left:0;right:0;padding:30px 40px;background:linear-gradient(transparent,rgba(0,0,0,.6));color:#fff}
.hero-overlay h1{font-size:2em;margin-bottom:8px;text-shadow:0 2px 4px rgba(0,0,0,.5)}
.hero-overlay .btn-hero{display:inline-block;background:#cc3872;color:#fff;padding:10px 24px;border-radius:4px;font-weight:600;margin-top:8px;transition:background .2s}
.hero-overlay .btn-hero:hover{background:#a82d5c;color:#fff}

/* Tabs */
.tabs{display:flex;gap:0;border-bottom:2px solid #eee;margin-bottom:16px}
.tab{padding:10px 20px;cursor:pointer;font-size:.9em;border-bottom:2px solid transparent;margin-bottom:-2px;color:#666}
.tab.active{color:#333;border-bottom-color:#cc3872;font-weight:600}
.tab-content{display:none}.tab-content.active{display:block}

/* Tables */
.spec-table{width:100%;border-collapse:collapse}
.spec-table tr:nth-child(even){background:#f9f9f9}
.spec-table td{padding:6px 12px;border-bottom:1px solid #eee;font-size:.9em}
.spec-table td:first-child{color:#666;width:40%}

/* Pagination */
.pagination{display:flex;gap:4px;margin-top:20px;justify-content:center}
.pagination a,.pagination span{padding:6px 12px;border-radius:4px;font-size:.9em}
.pagination a{background:#fff;border:1px solid #ddd}.pagination a:hover{border-color:#cc3872}
.pagination .current{background:#333;color:#fff}

/* Sort bar */
.sort-bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px}
.sort-bar .total{font-size:.9em;color:#666}
.sort-bar select{padding:6px;border:1px solid #ddd;border-radius:4px;font-size:.85em}

/* Cart icon */
.cart-icon{position:relative;font-size:1.4em;color:#fff;text-decoration:none;padding:4px 8px}
.cart-icon:hover{color:#cc3872}
.cart-badge{position:absolute;top:-4px;right:-4px;background:#cc3872;color:#fff;font-size:.5em;font-weight:700;border-radius:50%;width:20px;height:20px;display:flex;align-items:center;justify-content:center}

/* Add to cart button */
.btn-cart{background:#cc3872;color:#fff;border:none;padding:8px 14px;border-radius:4px;cursor:pointer;font-weight:600;font-size:.85em;width:100%;margin-top:8px;transition:background .2s}
.btn-cart:hover{background:#a82d5c}
.btn-cart-lg{font-size:1em;padding:12px 24px;width:auto}

/* Cart page */
.cart-table{width:100%;border-collapse:collapse;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.08)}
.cart-table th{background:#f0f0f0;padding:12px 16px;text-align:left;font-size:.85em;color:#555}
.cart-table td{padding:12px 16px;border-bottom:1px solid #eee;vertical-align:middle}
.cart-table .prod-name{font-weight:600}
.cart-table .prod-sku{font-size:.8em;color:#888}
.qty-input{width:60px;padding:6px;border:1px solid #ddd;border-radius:4px;text-align:center;font-size:.9em}
.btn-remove{background:none;border:none;color:#c62828;cursor:pointer;font-size:1.1em;padding:4px 8px}
.btn-remove:hover{color:#f44336}
.cart-summary{background:#fff;border-radius:8px;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,.08);align-self:start;position:sticky;top:16px}
.cart-summary h3{margin-bottom:12px}
.cart-summary .row{display:flex;justify-content:space-between;padding:6px 0;font-size:.95em}
.cart-summary .total-row{font-size:1.2em;font-weight:700;border-top:2px solid #eee;padding-top:12px;margin-top:8px}
.btn-checkout{display:block;background:#333;color:#fff;text-align:center;padding:14px;border-radius:6px;font-size:1em;font-weight:600;margin-top:16px;text-decoration:none;border:none;width:100%;cursor:pointer}
.btn-checkout:hover{background:#cc3872;color:#fff}

/* Checkout form */
.checkout-form{background:#fff;border-radius:8px;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,.08)}
.checkout-form label{display:block;font-size:.85em;font-weight:600;color:#555;margin:12px 0 4px}
.checkout-form label:first-child{margin-top:0}
.checkout-form input,.checkout-form textarea,.checkout-form select{width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;font-size:.95em}
.checkout-form textarea{height:80px;resize:vertical}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.shipping-options,.payment-options{display:grid;gap:8px;margin:8px 0 16px}
.shipping-option,.payment-option{display:flex;justify-content:space-between;gap:12px;padding:10px;border:1px solid #ddd;border-radius:6px;cursor:pointer}
.shipping-option:has(input:checked),.payment-option:has(input:checked){border-color:#cc3872;background:#fff7fb}
.shipping-option small,.payment-option small{display:block;color:#777;margin-top:2px}
.shipping-loading,.shipping-empty{font-size:.9em;color:#777;padding:8px 0}

/* Order done */
.order-done{text-align:center;padding:60px 20px;background:#fff;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08)}
.order-done h1{color:#2e7d32;margin-bottom:12px}

/* Language switcher */
.lang-switch{display:flex;gap:4px;align-items:center}
.lang-switch a{color:#888;font-size:.75em;padding:2px 6px;border-radius:3px;border:1px solid transparent}
.lang-switch a:hover{color:#cc3872;border-color:#cc3872}
.lang-switch a.active{color:#cc3872;border-color:#cc3872;font-weight:700}

/* Product image in cards */
.card-img{width:100%;aspect-ratio:1;object-fit:contain;background:#fff;border-radius:4px;margin-bottom:8px}
.card-img-placeholder{width:100%;aspect-ratio:1;background:#f0f0f0;border-radius:4px;margin-bottom:8px;display:flex;align-items:center;justify-content:center;color:#ccc;font-size:2em}

/* Sale / New stickers */
.sticker{position:absolute;top:8px;right:8px;padding:3px 10px;border-radius:3px;font-size:.75em;font-weight:700;color:#fff;text-transform:uppercase}
.sticker-new{background:#b2da24}
.sticker-sale{background:#e95d0f}

/* Legal pages */
.legal-page{max-width:900px;margin:0 auto}.legal-page h1{margin-bottom:16px}.legal-page h2{margin:22px 0 8px}.legal-page p,.legal-page li{margin:8px 0}.legal-page ul{margin-left:22px}

/* Footer */
footer{background:#333;color:#bbb;padding:0;margin-top:40px;font-size:.85em}
.footer-main{background:#333;padding:30px 0}
.footer-main .wrap{display:flex;gap:40px;flex-wrap:wrap}
.footer-col{flex:1;min-width:200px}
.footer-col h4{color:#fff;font-family:'Oswald',georgia,serif;margin-bottom:12px;font-size:1em;text-transform:uppercase}
.footer-col a{display:block;color:#999;padding:3px 0;font-size:.9em}.footer-col a:hover{color:#fff}
.footer-bottom{background:#222;padding:16px 0;text-align:center;color:#999;font-size:.8em}
</style>
</head>
<body>
<header>
 <div class="header-top">
  <div class="wrap">
   <div class="lang-switch">
    <?php
    // Build switcher from stores.json — each store exposes default_locale + url_prefix
    $langParams = $_GET;
    foreach ($hreflangs as $lc => $pfx):
        $langParams['lang'] = $lc;
    ?>
    <a href="<?= $e($pfx . $pathNoPrefix) ?>" class="<?= $lang===$lc?'active':'' ?>"><?= strtoupper($e($lc)) ?></a>
    <?php endforeach; ?>
   </div>
   <?php
   $loggedCust = !empty($_SESSION['customer_id']) ? (int)$_SESSION['customer_id'] : 0;
   if ($loggedCust > 0): ?>
   <a href="account.php" title="<?= $t('account.title') ?>" style="color:#555;font-size:.95em;margin-right:12px">👤 <?= $t('account.title') ?></a>
   <?php else: ?>
   <a href="account.php?p=login" title="<?= $t('account.login') ?>" style="color:#555;font-size:.95em;margin-right:12px"><?= $t('account.login') ?></a>
   <?php endif; ?>
   <a href="?page=cart" class="cart-icon" title="<?= $t('cart') ?>" style="color:#555;font-size:1.1em">
    🛒<?php if ($cartCount > 0): ?><span class="cart-badge"><?= $cartCount ?></span><?php endif; ?>
   </a>
  </div>
 </div>
 <div class="header-main">
  <div class="wrap">
   <div class="logo"><a href="?"><img src="assets/img/logo.png" alt="woodle.sk"></a></div>
   <form class="search-bar" method="get">
    <input type="hidden" name="page" value="search">
    <input type="text" name="q" placeholder="<?= $t('search_placeholder') ?>" value="<?= $e($_GET['q'] ?? '') ?>">
    <button type="submit"><?= $t('search') ?></button>
   </form>
  </div>
 </div>
 <div class="header-nav">
  <div class="wrap">
   <a href="?"><?= $t('home') ?></a>
   <?php
   $navCats = Categories::roots();
   usort($navCats, fn($a,$b) => (int)$b['product_count'] - (int)$a['product_count']);
   $navCats = array_filter($navCats, fn($c) => (int)$c['product_count'] > 0 && $c['slug'] !== 'uncategorized');
   $navShown = 0;
   foreach ($navCats as $rc):
       if ($navShown >= 8) break;
       $navShown++;
       $subCats = Categories::children((int)$rc['id']);
       usort($subCats, fn($a,$b) => (int)$b['product_count'] - (int)$a['product_count']);
       $subCats = array_filter($subCats, fn($c) => (int)$c['product_count'] > 0);
       if (!empty($subCats)):
   ?>
   <div class="nav-item">
    <a href="?page=category&slug=<?= $e($rc['slug']) ?>"><?= $e($catName($rc['name'])) ?></a>
    <div class="nav-dropdown">
     <?php foreach (array_slice($subCats, 0, 12) as $sc): ?>
     <a href="?page=category&slug=<?= $e($sc['slug']) ?>"><?= $e($catName($sc['name'])) ?></a>
     <?php endforeach; ?>
     <?php if (count($subCats) > 12): ?>
     <a href="?page=category&slug=<?= $e($rc['slug']) ?>" style="font-weight:600;color:#cc3872"><?= $lang==='en'?'View all...':($lang==='de'?'Alle anzeigen...':'Zobraziť všetko...') ?></a>
     <?php endif; ?>
    </div>
   </div>
   <?php else: ?>
   <a href="?page=category&slug=<?= $e($rc['slug']) ?>"><?= $e($catName($rc['name'])) ?></a>
   <?php endif; ?>
   <?php endforeach; ?>
  </div>
 </div>
</header>

<main><div class="wrap">
<?= $content ?>
</div></main>

<footer>
 <div class="footer-main"><div class="wrap">
  <div class="footer-col">
   <h4>woodle.sk</h4>
   <p style="color:#999;font-size:.9em"><?= $lang==='en'?'Sauna & wooden products':($lang==='de'?'Sauna & Holzprodukte':'Sauna & drevené výrobky') ?></p>
  </div>
  <div class="footer-col">
   <h4><?= $t('categories') ?></h4>
   <?php
   $fCats = Categories::roots();
   usort($fCats, fn($a,$b) => (int)$b['product_count'] - (int)$a['product_count']);
   $fCats = array_filter($fCats, fn($c) => (int)$c['product_count'] > 0 && $c['slug'] !== 'uncategorized');
   $fShown = 0;
   foreach ($fCats as $fc):
       if ($fShown >= 6) break; $fShown++;
   ?>
   <a href="?page=category&slug=<?= $e($fc['slug']) ?>"><?= $e($catName($fc['name'])) ?></a>
   <?php endforeach; ?>
  </div>
  <div class="footer-col">
   <h4><?= $lang==='en'?'Information':($lang==='de'?'Informationen':'Informácie') ?></h4>
   <a href="?"><?= $lang==='en'?'About us':($lang==='de'?'Über uns':'O nás') ?></a>
   <a href="?"><?= $lang==='en'?'Contact':($lang==='de'?'Kontakt':'Kontakt') ?></a>
   <a href="?page=privacy"><?= $lang==='en'?'Privacy Policy':($lang==='de'?'Datenschutz':'Ochrana osobných údajov') ?></a>
   <a href="?page=terms"><?= $lang==='en'?'Terms & Conditions':($lang==='de'?'AGB':'Obchodné podmienky') ?></a>
   <a href="?page=cookies"><?= $lang==='en'?'Cookie Policy':($lang==='de'?'Cookie-Richtlinie':'Pravidlá cookies') ?></a>
  </div>
 </div></div>
 <div class="footer-bottom"><div class="wrap">woodle.sk &copy; <?= date('Y') ?> — Powered by BareBones E-shop</div></div>
</footer>

<script>
function setTab(id){
 document.querySelectorAll('.tab').forEach(t=>t.classList.toggle('active',t.dataset.tab===id));
 document.querySelectorAll('.tab-content').forEach(c=>c.classList.toggle('active',c.id==='tab-'+id));
}
// Vehicle cascade AJAX
async function loadModels(mfr){
 const mdlSel=document.getElementById('sel-mdl');
 const typSel=document.getElementById('sel-typ');
 const goBtn=document.getElementById('btn-go');
 if(!mdlSel)return;
 mdlSel.innerHTML='<option value="">— Model —</option>';
 if(typSel){typSel.innerHTML='<option value="">— Typ motora —</option>';typSel.style.display='none';}
 if(goBtn)goBtn.style.display='none';
 if(!mfr){mdlSel.style.display='none';return;}
 mdlSel.style.display='';
 const r=await fetch('api.php?action=vehicle_models&manufacturer='+encodeURIComponent(mfr));
 const d=await r.json();
 (d.models||[]).forEach(m=>{const o=document.createElement('option');o.value=m;o.textContent=m;mdlSel.appendChild(o);});
}
async function loadTypes(mfr,mdl){
 const typSel=document.getElementById('sel-typ');
 const goBtn=document.getElementById('btn-go');
 if(!typSel)return;
 typSel.innerHTML='<option value="">— Typ motora —</option>';
 if(goBtn)goBtn.style.display='none';
 if(!mdl){typSel.style.display='none';return;}
 typSel.style.display='';
 const r=await fetch('api.php?action=vehicle_types&manufacturer='+encodeURIComponent(mfr)+'&model='+encodeURIComponent(mdl));
 const d=await r.json();
 (d.types||[]).forEach(t=>{const o=document.createElement('option');o.value=t.ktype;o.textContent=t.type_name+' '+t.hp+'HP '+(t.fuel_type||'');typSel.appendChild(o);});
}
function showGo(ktype){
 const goBtn=document.getElementById('btn-go');
 if(goBtn)goBtn.style.display=ktype?'':'none';
}
function goVehicle(){
 const k=document.getElementById('sel-typ');
 if(k&&k.value)window.location='?page=vehicle&ktype='+k.value;
}
async function addToCart(pid,qty,btn){
 const orig=btn.textContent;btn.disabled=true;btn.textContent='<?= $lang==='en'?'Added ✓':($lang==='de'?'Hinzugefügt ✓':'Pridané ✓') ?>';
 const fd=new FormData();fd.append('cart_action','add');fd.append('product_id',pid);fd.append('quantity',qty||1);fd.append('csrf_token','<?= Security::csrfToken() ?>');
 const r=await fetch('',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}});
 const d=await r.json();
 const b=document.querySelector('.cart-badge');
 if(b)b.textContent=d.count;
 else{const i=document.querySelector('.cart-icon');if(i){const s=document.createElement('span');s.className='cart-badge';s.textContent=d.count;i.appendChild(s);}}
 setTimeout(()=>{btn.disabled=false;btn.textContent=orig;},1200);
}
async function refreshCheckoutShipping(){
 const country=document.getElementById('checkout-country');
 const box=document.getElementById('shipping-options');
 const summaryShip=document.getElementById('summary-shipping');
 const subtotalEl=document.getElementById('checkout-subtotal');
 if(!country||!box||!summaryShip||!subtotalEl)return;
 const subtotal=parseInt(subtotalEl.value||'0',10);
 box.innerHTML='<div class="shipping-loading">Loading shipping rates…</div>';
 const params=new URLSearchParams({action:'shipping_rates',country:country.value,subtotal:String(subtotal)});
 const r=await fetch('api.php?'+params.toString());
 const d=await r.json();
 const methods=d.methods||[];
 if(!methods.length){box.innerHTML='<div class="shipping-empty">Doručenie nie je dostupné.</div>';summaryShip.textContent='—';await refreshCheckoutPayments('');return;}
 box.innerHTML='';
 methods.forEach((m,i)=>{
  const label=document.createElement('label');label.className='shipping-option';
  const methodCode=m.method_code||m.key||'';
  const price=parseInt(m.price||0,10);
  label.innerHTML='<span><input type="radio" name="shipping_method" value="'+escapeHtml(methodCode)+'" '+(i===0?'checked':'')+'> '+escapeHtml(m.label||methodCode)+'<small>'+escapeHtml(m.delivery_days||'')+'</small></span><strong>'+formatCents(price)+'</strong>';
  label.querySelector('input').addEventListener('change',()=>refreshCheckoutPayments(methodCode));
  box.appendChild(label);
 });
 await refreshCheckoutPayments(methods[0].method_code||methods[0].key||'');
}
async function refreshCheckoutPayments(shippingMethod){
 const country=document.getElementById('checkout-country');
 const box=document.getElementById('payment-options');
 const subtotalEl=document.getElementById('checkout-subtotal');
 if(!country||!box||!subtotalEl)return;
 const subtotal=parseInt(subtotalEl.value||'0',10);
 const checkedShipping=document.querySelector('input[name="shipping_method"]:checked');
 const selectedShipping=shippingMethod||(checkedShipping?checkedShipping.value:'');
 const shippingLabel=checkedShipping?checkedShipping.closest('label'):null;
 const shippingPrice=shippingLabel?parseMoney(shippingLabel.querySelector('strong').textContent):0;
 if(!selectedShipping){box.innerHTML='<div class="payment-empty">Najprv vyberte dopravu.</div>';updateCheckoutTotal(shippingPrice,0,subtotal);return;}
 box.innerHTML='<div class="payment-loading">Loading payment methods…</div>';
 const params=new URLSearchParams({action:'payment_methods',country:country.value,currency:'eur',amount:String(subtotal+shippingPrice),shipping_method:selectedShipping});
 const r=await fetch('api.php?'+params.toString());
 const d=await r.json();
 const methods=d.methods||[];
 if(!methods.length){box.innerHTML='<div class="payment-empty">Platba nie je dostupná.</div>';updateCheckoutTotal(shippingPrice,0,subtotal);return;}
 box.innerHTML='';
 methods.forEach((m,i)=>{
  const label=document.createElement('label');label.className='payment-option';
  const fee=parseInt(m.fee||0,10);
  label.innerHTML='<span><input type="radio" name="payment" value="'+escapeHtml(m.code||'')+'" '+(i===0?'checked':'')+'> '+escapeHtml(m.label||m.code||'')+'<small>'+escapeHtml(m.description||m.provider||'')+'</small></span><strong>'+(fee===0?'<?= $t('free_shipping') ?>':formatCents(fee))+'</strong>';
  label.querySelector('input').addEventListener('change',()=>updateCheckoutTotal(shippingPrice,fee,subtotal));
  box.appendChild(label);
 });
 updateCheckoutTotal(shippingPrice,parseInt(methods[0].fee||0,10),subtotal);
}
function updateCheckoutTotal(shipping,paymentFee,subtotal){
 document.getElementById('summary-shipping').textContent=formatCents(shipping);
 const pay=document.getElementById('summary-payment');
 if(pay)pay.textContent=paymentFee===0?'<?= $t('free_shipping') ?>':formatCents(paymentFee);
 document.getElementById('summary-total').textContent=formatCents(subtotal+shipping+paymentFee);
}
function parseMoney(text){if(String(text).indexOf('<?= $t('free_shipping') ?>')!==-1)return 0;const n=String(text).replace(/[^0-9,.-]/g,'').replace(',','.');return Math.round((parseFloat(n)||0)*100);}
function formatCents(cents){return (cents/100).toLocaleString('sk-SK',{style:'currency',currency:'EUR'});}
function escapeHtml(s){return String(s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));}
document.addEventListener('DOMContentLoaded',()=>{
 const country=document.getElementById('checkout-country');
 if(country)country.addEventListener('change',refreshCheckoutShipping);
 if(document.getElementById('shipping-options'))refreshCheckoutShipping();
});
</script>

<?php
// ============================================================
// GDPR Cookie consent banner (CSS-only, opt-in default OFF)
// Hidden when consent cookie 'cc_v1' is set. Buttons set the cookie
// (12-month expiry) and POST to /api.php?action=cookie_consent
// ============================================================
$ccCookie = (string)($_COOKIE['cc_v1'] ?? '');
if ($ccCookie === ''):
    $ccTitle = htmlspecialchars(I18n::t('cookie_banner.title'), ENT_QUOTES, 'UTF-8');
    $ccBody  = htmlspecialchars(I18n::t('cookie_banner.body'),  ENT_QUOTES, 'UTF-8');
    $ccAccept= htmlspecialchars(I18n::t('cookie_banner.accept_all'),       ENT_QUOTES, 'UTF-8');
    $ccReject= htmlspecialchars(I18n::t('cookie_banner.reject_all'),       ENT_QUOTES, 'UTF-8');
    $ccPrefs = htmlspecialchars(I18n::t('cookie_banner.preferences'),      ENT_QUOTES, 'UTF-8');
    $ccSave  = htmlspecialchars(I18n::t('cookie_banner.save_preferences'), ENT_QUOTES, 'UTF-8');
    $ccPolicy= htmlspecialchars(I18n::t('cookie_banner.policy_link'),      ENT_QUOTES, 'UTF-8');
    $ccNec   = htmlspecialchars(I18n::t('account.gdpr_cookies_necessary'), ENT_QUOTES, 'UTF-8');
    $ccAna   = htmlspecialchars(I18n::t('account.gdpr_cookies_analytics'), ENT_QUOTES, 'UTF-8');
    $ccMkt   = htmlspecialchars(I18n::t('account.gdpr_cookies_marketing'), ENT_QUOTES, 'UTF-8');
?>
<style>
#cc-banner{position:fixed;left:16px;right:16px;bottom:16px;max-width:680px;margin:0 auto;background:#fff;color:#222;border:1px solid #ddd;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.18);padding:16px 18px;z-index:9999;font-size:14px;line-height:1.45}
#cc-banner h3{margin:0 0 6px;font-size:15px}
#cc-banner p{margin:0 0 12px}
#cc-banner .cc-btns{display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;align-items:center}
#cc-banner button,#cc-banner .cc-link{font:inherit;padding:8px 14px;border-radius:6px;border:1px solid #999;background:#f7f7f7;cursor:pointer}
#cc-banner button.primary{background:#222;color:#fff;border-color:#222}
#cc-banner button.danger{background:#fff;color:#222}
#cc-banner .cc-link{background:transparent;border:none;color:#06c;text-decoration:underline;padding:8px 4px}
#cc-prefs{display:none;margin:8px 0 12px;border-top:1px solid #eee;padding-top:10px}
#cc-banner.show-prefs #cc-prefs{display:block}
#cc-prefs label{display:flex;align-items:flex-start;gap:8px;margin:6px 0;font-size:13px}
#cc-prefs input[disabled]{cursor:not-allowed}
@media(max-width:520px){#cc-banner{left:8px;right:8px;bottom:8px;padding:14px}#cc-banner .cc-btns{justify-content:stretch}#cc-banner button{flex:1 1 45%}}
</style>
<div id="cc-banner" role="dialog" aria-labelledby="cc-title" aria-describedby="cc-body">
  <h3 id="cc-title"><?= $ccTitle ?></h3>
  <p id="cc-body"><?= $ccBody ?> <a href="?page=privacy" class="cc-link"><?= $ccPolicy ?></a></p>
  <div id="cc-prefs">
    <label><input type="checkbox" checked disabled> <?= $ccNec ?></label>
    <label><input type="checkbox" id="cc-ana"> <?= $ccAna ?></label>
    <label><input type="checkbox" id="cc-mkt"> <?= $ccMkt ?></label>
  </div>
  <div class="cc-btns">
    <button type="button" class="cc-link" id="cc-prefs-toggle"><?= $ccPrefs ?></button>
    <button type="button" class="danger" id="cc-reject"><?= $ccReject ?></button>
    <button type="button" class="primary" id="cc-accept"><?= $ccAccept ?></button>
    <button type="button" class="primary" id="cc-save" style="display:none"><?= $ccSave ?></button>
  </div>
</div>
<script>
(function(){
  var b=document.getElementById('cc-banner');
  if(!b)return;
  var oneYear=60*60*24*365;
  function setCookie(v){
    document.cookie='cc_v1='+encodeURIComponent(v)+';path=/;max-age='+oneYear+';SameSite=Lax';
  }
  function send(cats,method){
    setCookie(JSON.stringify(cats));
    var fd=new FormData();
    fd.append('necessary',cats.necessary?1:0);
    fd.append('analytics',cats.analytics?1:0);
    fd.append('marketing',cats.marketing?1:0);
    fd.append('method',method);
    fetch('api.php?action=cookie_consent',{method:'POST',body:fd}).catch(function(){});
    b.parentNode.removeChild(b);
  }
  document.getElementById('cc-accept').onclick=function(){send({necessary:true,analytics:true,marketing:true},'accept_all');};
  document.getElementById('cc-reject').onclick=function(){send({necessary:true,analytics:false,marketing:false},'reject_all');};
  document.getElementById('cc-prefs-toggle').onclick=function(){
    b.classList.add('show-prefs');
    document.getElementById('cc-accept').style.display='none';
    document.getElementById('cc-reject').style.display='none';
    document.getElementById('cc-save').style.display='';
  };
  document.getElementById('cc-save').onclick=function(){
    send({
      necessary:true,
      analytics:document.getElementById('cc-ana').checked,
      marketing:document.getElementById('cc-mkt').checked
    },'preferences');
  };
})();
</script>
<?php endif; ?>
</body></html>
<?php
// ============================================================
// Render functions
// ============================================================

function renderLegalPage(string $page): string {
    $allowed = ['privacy', 'terms', 'cookies'];
    if (!in_array($page, $allowed, true)) {
        return '<p>Not found.</p>';
    }

    $locale = I18n::locale();
    $tpl = __DIR__ . '/templates/legal/' . $locale . '/' . $page . '.html';
    if (!is_file($tpl)) {
        $tpl = __DIR__ . '/templates/legal/en/' . $page . '.html';
    }

    $stores = json_decode((string)file_get_contents(__DIR__ . '/stores.json'), true) ?: [];
    $legal = [];
    foreach ($stores['stores'] ?? [] as $store) {
        if (($store['default_locale'] ?? '') === $locale) {
            $legal = $store['legal'] ?? [];
            break;
        }
    }
    if (!$legal) {
        $defaultStore = $stores['default_store'] ?? 'sk';
        $legal = $stores['stores'][$defaultStore]['legal'] ?? ($stores['stores']['sk']['legal'] ?? []);
    }

    $vars = [];
    foreach ($legal as $k => $v) {
        $vars['{{' . $k . '}}'] = htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
    $vars['{{updated_at}}'] = date('Y-m-d');

    $html = (string)file_get_contents($tpl);
    return '<div class="card legal-page">' . strtr($html, $vars) . '</div>';
}

function renderHome(): string {
    global $e, $price, $catName, $t, $lang;

    ob_start(); ?>
    <div class="hero">
     <img src="assets/img/slider1.jpg" alt="woodle.sk">
     <div class="hero-overlay">
      <h1><?= $t('hero_title') ?></h1>
      <a href="?page=category&slug=<?= $e((Categories::roots()[0] ?? [])['slug'] ?? '') ?>" class="btn-hero"><?= $t('shop_now') ?></a>
     </div>
    </div>

    <h2 class="section-title" style="margin-bottom:16px;font-size:1.4em"><?= $t('categories') ?></h2>
    <div class="grid grid-3">
    <?php
    $roots = Categories::roots();
    usort($roots, fn($a,$b) => (int)$b['product_count'] - (int)$a['product_count']);
    $roots = array_filter($roots, fn($c) => (int)$c['product_count'] >= 5 && $c['slug'] !== 'uncategorized');
    foreach ($roots as $rc):
    ?>
     <a href="?page=category&slug=<?= $e($rc['slug']) ?>" class="cat-card">
      <span style="flex:1;font-weight:600"><?= $e($catName($rc['name'])) ?></span>
      <span class="count"><?= number_format((int)$rc['product_count']) ?></span>
     </a>
    <?php endforeach; ?>
    </div>

    <?php
    $latest = Products::latest(12);
    if ($latest): ?>
    <h2 class="section-title" style="margin:32px 0 16px;font-size:1.4em"><?= $t('latest') ?></h2>
    <div class="grid grid-4">
    <?php foreach ($latest as $p): $p = Products::enrich($p); ?>
     <div class="card" style="position:relative">
      <a href="?page=product&slug=<?= $e($p['slug']) ?>">
       <?php
       $imgs = is_string($p['images'] ?? null) ? json_decode($p['images'], true) : ($p['images'] ?? []);
       $firstImg = $imgs[0] ?? null;
       if ($firstImg): ?>
       <img class="card-img" src="<?= $e($firstImg) ?>" alt="<?= $e($p['name']) ?>" loading="lazy">
       <?php else: ?>
       <div class="card-img-placeholder">📷</div>
       <?php endif; ?>
       <?php if (!empty($p['compare_price']) && $p['compare_price'] > $p['price']): ?>
       <span class="sticker sticker-sale">SALE</span>
       <?php endif; ?>
       <h3><?= $e($p['name']) ?></h3>
       <div class="price"><?= $price($p['price']) ?>
        <?php if (!empty($p['compare_price'])): ?><span class="old-price"><?= $price($p['compare_price']) ?></span><?php endif; ?>
       </div>
      </a>
      <button class="btn-cart" onclick="addToCart(<?= (int)$p['id'] ?>,1,this)"><?= $t('add_to_cart') ?></button>
     </div>
    <?php endforeach; ?>
    </div>
    <?php endif;

    return ob_get_clean();
}

function renderCategory(array $cat): string {
    global $e, $price, $catName, $t, $lang;
    $catId = (int)$cat['id'];

    // Breadcrumb
    $bc = Categories::breadcrumb($catId);
    ob_start();
    echo '<div class="breadcrumb"><a href="?">' . $t('home') . '</a>';
    foreach ($bc as $b) echo ' &rsaquo; <a href="?page=category&slug=' . $e($b['slug']) . '">' . $e($catName($b['name'])) . '</a>';
    echo '</div>';

    // Subcategories — used for sidebar filters on parent categories
    $children = Categories::children($catId);
    $hasChildren = !empty($children);

    // Get all descendant category IDs for product search
    $allCatIds = Categories::descendantIds($catId);

    // Parse subcategory filter from URL
    $selectedSubcats = [];
    if ($hasChildren && !empty($_GET['subcat']) && is_array($_GET['subcat'])) {
        $selectedSubcats = array_map('intval', $_GET['subcat']);
    }

    // If subcategories selected, narrow search to their descendants only
    if (!empty($selectedSubcats)) {
        $searchCatIds = [];
        foreach ($selectedSubcats as $scId) {
            $searchCatIds = array_merge($searchCatIds, Categories::descendantIds($scId));
        }
        $searchCatIds = array_unique($searchCatIds);
    } else {
        $searchCatIds = $allCatIds;
    }

    // Parametric search with filters (works for both parent and leaf categories)
    $pg = max(1, Security::int($_GET['pg'] ?? 1));
    $sort = Security::clean($_GET['sort'] ?? 'price_asc');
    $searchLang = $lang;

    // Parse spec filters from URL
    $specFilters = [];
    if (!empty($_GET['spec']) && is_array($_GET['spec'])) {
        foreach ($_GET['spec'] as $name => $val) {
            $name = Security::clean($name);
            if (is_array($val)) {
                $specFilters[$name] = array_map([Security::class, 'clean'], $val);
            } else {
                $specFilters[$name] = [Security::clean($val)];
            }
        }
    }

    $priceMin = !empty($_GET['pmin']) ? Security::int($_GET['pmin']) * 100 : null;
    $priceMax = !empty($_GET['pmax']) ? Security::int($_GET['pmax']) * 100 : null;

    // Parse numeric range filters: rmin[Výkon (kW)]=3&rmax[Výkon (kW)]=9
    $rangeFilters = [];
    if (!empty($_GET['rmin']) && is_array($_GET['rmin'])) {
        foreach ($_GET['rmin'] as $name => $val) {
            $name = Security::clean($name);
            if ($val !== '') $rangeFilters[$name]['min'] = (float)$val;
        }
    }
    if (!empty($_GET['rmax']) && is_array($_GET['rmax'])) {
        foreach ($_GET['rmax'] as $name => $val) {
            $name = Security::clean($name);
            if ($val !== '') $rangeFilters[$name]['max'] = (float)$val;
        }
    }

    $result = TecDoc::parametricSearch($searchCatIds, $pg, 24, $sort, $specFilters, $priceMin, $priceMax, $searchLang, $rangeFilters);
    $filters = $result['filters'];

    echo '<h1 style="margin-bottom:4px">' . $e($catName($cat['name'])) . '</h1>';
    echo '<div class="sort-bar"><span class="total">' . number_format($result['total']) . ' ' . $t('parts_count') . '</span>';
    echo '<form method="get" style="display:flex;gap:8px;align-items:center">';
    echo '<input type="hidden" name="page" value="category"><input type="hidden" name="slug" value="' . $e($cat['slug']) . '">';
    // Preserve subcategory + spec filters in sort form
    foreach ($selectedSubcats as $scId) echo '<input type="hidden" name="subcat[]" value="' . (int)$scId . '">';
    foreach ($specFilters as $fn => $fvs) {
        foreach ($fvs as $fv) echo '<input type="hidden" name="spec[' . $e($fn) . '][]" value="' . $e($fv) . '">';
    }
    if ($priceMin) echo '<input type="hidden" name="pmin" value="' . (int)($priceMin / 100) . '">';
    if ($priceMax) echo '<input type="hidden" name="pmax" value="' . (int)($priceMax / 100) . '">';
    foreach ($rangeFilters as $fn => $rv) {
        if (isset($rv['min'])) echo '<input type="hidden" name="rmin[' . $e($fn) . ']" value="' . $e((string)$rv['min']) . '">';
        if (isset($rv['max'])) echo '<input type="hidden" name="rmax[' . $e($fn) . ']" value="' . $e((string)$rv['max']) . '">';
    }
    echo '<label>' . $t('sort') . '</label><select name="sort" onchange="this.form.submit()">';
    foreach (['price_asc'=>$t('price_asc'),'price_desc'=>$t('price_desc'),'name_asc'=>$t('name_asc'),'name_desc'=>$t('name_desc'),'newest'=>$t('newest')] as $k=>$v) {
        echo '<option value="' . $k . '"' . ($sort===$k?' selected':'') . '>' . $v . '</option>';
    }
    echo '</select></form></div>';

    echo '<div class="layout-sidebar">';

    // Sidebar filters
    echo '<form class="sidebar" method="get">';
    echo '<input type="hidden" name="page" value="category"><input type="hidden" name="slug" value="' . $e($cat['slug']) . '">';
    echo '<input type="hidden" name="sort" value="' . $e($sort) . '">';

    $hasActiveFilters = !empty($specFilters) || $priceMin || $priceMax || !empty($selectedSubcats) || !empty($rangeFilters);

    // Subcategory filter (only for parent categories)
    if ($hasChildren) {
        $childrenWithProducts = array_filter($children, fn($ch) => (int)$ch['product_count'] > 0);
        if (!empty($childrenWithProducts)) {
            usort($childrenWithProducts, fn($a,$b) => (int)$b['product_count'] - (int)$a['product_count']);
            echo '<h3>' . $t('subcategories');
            if (!empty($selectedSubcats)) echo ' <span style="color:#cc3872">●</span>';
            echo '</h3>';
            foreach ($childrenWithProducts as $ch) {
                $checked = in_array((int)$ch['id'], $selectedSubcats) ? ' checked' : '';
                echo '<label><input type="checkbox" name="subcat[]" value="' . (int)$ch['id'] . '"' . $checked . '> ';
                echo $e($catName($ch['name'])) . ' <span class="cnt">(' . (int)$ch['product_count'] . ')</span></label>';
            }
        }
    }

    // Price range
    echo '<h3>' . $t('price') . '</h3>';
    echo '<div style="display:flex;gap:4px;align-items:center">';
    $pminDisplay = $priceMin ? (int)($priceMin / 100) : '';
    $pmaxDisplay = $priceMax ? (int)($priceMax / 100) : '';
    echo '<input type="number" name="pmin" placeholder="' . $t('ph_from') . '" value="' . $pminDisplay . '" style="width:80px;padding:4px;border:1px solid #ddd;border-radius:3px;font-size:.85em">';
    echo '<span>—</span>';
    echo '<input type="number" name="pmax" placeholder="' . $t('ph_to') . '" value="' . $pmaxDisplay . '" style="width:80px;padding:4px;border:1px solid #ddd;border-radius:3px;font-size:.85em">';
    echo '<span style="font-size:.85em;color:#888">€</span></div>';

    foreach ($filters as $critName => $values) {
        $activeVals = $specFilters[$critName] ?? [];
        // Detect numeric filter: all values have numeric_val and there are many distinct values
        $allNumeric = count($values) > 5 && !empty($values[0]['numeric']);
        if ($allNumeric) {
            foreach ($values as $v) { if ($v['numeric'] === null) { $allNumeric = false; break; } }
        }

        echo '<h3>' . $e($critName);
        $rangeKey = 'range_' . md5($critName);
        $activeMin = $specFilters[$critName . '__min'] ?? ($_GET['rmin'][$critName] ?? '');
        $activeMax = $specFilters[$critName . '__max'] ?? ($_GET['rmax'][$critName] ?? '');
        if ($allNumeric && ($activeMin !== '' || $activeMax !== '')) echo ' <span style="color:#cc3872">●</span>';
        elseif (!$allNumeric && !empty($activeVals)) echo ' <span style="color:#cc3872">●</span>';
        echo '</h3>';

        if ($allNumeric) {
            // Range filter (min-max inputs) for numeric specs like kW
            $numVals = array_map(fn($v) => (float)$v['numeric'], $values);
            $minVal = min($numVals);
            $maxVal = max($numVals);
            $unit = '';
            if (preg_match('/\(([^)]+)\)/', $critName, $um)) $unit = $um[1];
            echo '<div style="display:flex;gap:4px;align-items:center">';
            echo '<input type="number" name="rmin[' . $e($critName) . ']" placeholder="' . $minVal . '" value="' . $e((string)$activeMin) . '" step="0.1" style="width:70px;padding:4px;border:1px solid #ddd;border-radius:3px;font-size:.85em">';
            echo '<span>—</span>';
            echo '<input type="number" name="rmax[' . $e($critName) . ']" placeholder="' . $maxVal . '" value="' . $e((string)$activeMax) . '" step="0.1" style="width:70px;padding:4px;border:1px solid #ddd;border-radius:3px;font-size:.85em">';
            if ($unit) echo '<span style="font-size:.85em;color:#888">' . $e($unit) . '</span>';
            echo '</div>';
        } else {
            // Checkbox filter for discrete values
            foreach ($values as $v) {
                $checked = in_array($v['value'], $activeVals) ? ' checked' : '';
                echo '<label><input type="checkbox" name="spec[' . $e($critName) . '][]" value="' . $e($v['value']) . '"' . $checked . '> ';
                echo $e($v['value']) . ' <span class="cnt">(' . $v['count'] . ')</span></label>';
            }
        }
    }

    echo '<div class="filter-actions">';
    echo '<button type="submit" class="btn-apply">' . $t('filter') . '</button>';
    if ($hasActiveFilters) echo '<a href="?page=category&slug=' . $e($cat['slug']) . '" class="btn-clear">' . $t('clear_filter') . '</a>';
    echo '</div></form>';

    // Product listing
    echo '<div>';
    if (empty($result['products'])) {
        echo '<p style="padding:40px;text-align:center;color:#888">' . $t('no_parts_filter') . '</p>';
    } else {
        echo '<div class="grid grid-4">';
        foreach ($result['products'] as $p) {
            $p = Products::enrich($p);
            $imgs = is_string($p['images'] ?? null) ? json_decode($p['images'], true) : ($p['images'] ?? []);
            $firstImg = $imgs[0] ?? null;
            echo '<div class="card" style="position:relative">';
            echo '<a href="?page=product&slug=' . $e($p['slug']) . '">';
            if ($firstImg) {
                echo '<img class="card-img" src="' . $e($firstImg) . '" alt="' . $e($p['name']) . '" loading="lazy">';
            } else {
                echo '<div class="card-img-placeholder">📷</div>';
            }
            if (!empty($p['compare_price']) && $p['compare_price'] > $p['price']) {
                echo '<span class="sticker sticker-sale">SALE</span>';
            }
            echo '<h3>' . $e($p['name']) . '</h3>';
            echo '<div class="price">' . I18n::price($p['price'], 'eur');
            if (!empty($p['compare_price'])) echo '<span class="old-price">' . I18n::price($p['compare_price'], 'eur') . '</span>';
            echo '</div>';
            if ($p['stock'] > 0) echo '<div class="badge">' . $t('in_stock') . '</div>';
            echo '</a>';
            echo '<button class="btn-cart" onclick="addToCart(' . (int)$p['id'] . ',1,this)">' . $t('add_to_cart') . '</button>';
            echo '</div>';
        }
        echo '</div>';
        echo renderPagination($result['page'], $result['pages'], buildCategoryUrl($cat['slug'], $sort, $specFilters, $priceMin, $priceMax, $selectedSubcats, $rangeFilters));
    }
    echo '</div></div>';
    return ob_get_clean();
}

function renderProduct(array $p): string {
    global $e, $price, $catName, $t, $lang;
    $catId = (int)$p['category_id'];
    $bc = Categories::breadcrumb($catId);

    ob_start();
    echo '<div class="breadcrumb"><a href="?">' . $t('home') . '</a>';
    foreach ($bc as $b) echo ' &rsaquo; <a href="?page=category&slug=' . $e($b['slug']) . '">' . $e($catName($b['name'])) . '</a>';
    echo ' &rsaquo; ' . $e($p['name']) . '</div>';

    $attrs = is_array($p['attrs']) ? $p['attrs'] : json_decode($p['attrs'] ?? '{}', true);
    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:32px;margin-bottom:24px">';
    echo '<div>';
    if (!empty($p['images']) && !empty($p['images'][0]) && $p['images'][0] !== 'placeholder.jpg') {
        echo '<img src="' . $e($p['images'][0]) . '" alt="" style="max-width:100%;border-radius:8px">';
    } else {
        echo '<div style="background:#eee;height:300px;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#aaa;font-size:3em">📦</div>';
    }
    echo '</div><div>';
    echo '<h1 style="margin-bottom:8px">' . $e($p['name']) . '</h1>';
    echo '<p style="color:#888;margin-bottom:4px">SKU: ' . $e($p['sku']) . '</p>';
    if (!empty($attrs['supplier'])) echo '<p style="color:#888;margin-bottom:12px">' . $t('supplier') . ': <strong>' . $e($attrs['supplier']) . '</strong></p>';
    echo '<div class="price" style="font-size:1.6em;margin-bottom:12px">' . $price($p['price']);
    if (!empty($p['compare_price'])) echo '<span class="old-price" style="font-size:.6em">' . $price($p['compare_price']) . '</span>';
    echo '</div>';
    echo $p['stock'] > 0 ? '<div class="badge" style="font-size:.9em;margin-bottom:12px">✓ ' . $t('in_stock') . ' (' . $p['stock'] . ' ks)</div>'
                         : '<div style="color:#c62828;font-size:.9em;margin-bottom:12px">✗ ' . $t('out_of_stock') . '</div>';
    if (!empty($p['summary'])) echo '<p style="margin-top:12px;color:#555">' . $e($p['summary']) . '</p>';
    echo '<p style="margin-top:8px;color:#888;font-size:.85em">' . $t('compatible_with') . ' ' . $p['vehicle_count'] . ' ' . $t('vehicles') . '</p>';
    // Add to cart
    echo '<div style="margin-top:20px;display:flex;gap:8px;align-items:center">';
    echo '<input type="number" value="1" min="1" max="99" class="qty-input" id="qty-' . (int)$p['id'] . '">';
    echo '<button class="btn-cart btn-cart-lg" onclick="addToCart(' . (int)$p['id'] . ',document.getElementById(\'qty-' . (int)$p['id'] . '\').value,this)">' . $t('add_to_cart') . '</button>';
    echo '</div>';
    echo '</div></div>';

    // Tabs
    echo '<div class="tabs">';
    echo '<div class="tab active" data-tab="specs" onclick="setTab(\'specs\')">' . $t('specs') . ' (' . count($p['specifications']) . ')</div>';
    echo '<div class="tab" data-tab="oe" onclick="setTab(\'oe\')">' . $t('oe_numbers') . ' (' . count($p['oe_numbers']) . ')</div>';
    echo '<div class="tab" data-tab="vehicles" onclick="setTab(\'vehicles\')">' . $t('vehicles_tab') . ' (' . $p['vehicle_count'] . ')</div>';
    echo '</div>';

    // Specs tab
    echo '<div class="tab-content active" id="tab-specs"><table class="spec-table">';
    foreach ($p['specifications'] as $s) {
        echo '<tr><td>' . $e($s['criteria_name']) . '</td><td><strong>' . $e($s['criteria_value']) . '</strong></td></tr>';
    }
    if (empty($p['specifications'])) echo '<tr><td colspan="2" style="color:#888">' . $t('no_specs') . '</td></tr>';
    echo '</table></div>';

    // OE tab
    echo '<div class="tab-content" id="tab-oe"><table class="spec-table">';
    $lastBrand = '';
    foreach ($p['oe_numbers'] as $oe) {
        if ($oe['oem_brand'] !== $lastBrand) {
            $lastBrand = $oe['oem_brand'];
            echo '<tr><td colspan="2" style="font-weight:700;background:#f0f0f0;padding:8px 12px">' . $e($lastBrand) . '</td></tr>';
        }
        echo '<tr><td></td><td><a href="?page=oe&q=' . urlencode($oe['oem_number']) . '">' . $e($oe['oem_number']) . '</a></td></tr>';
    }
    if (empty($p['oe_numbers'])) echo '<tr><td colspan="2" style="color:#888">' . $t('no_oe') . '</td></tr>';
    echo '</table></div>';

    // Vehicles tab
    $vehicles = TecDoc::vehiclesForProduct((int)$p['id'], 100);
    echo '<div class="tab-content" id="tab-vehicles"><table class="spec-table">';
    $vehHeader = $lang === 'en' ? 'Manufacturer / Model' : ($lang === 'de' ? 'Hersteller / Modell' : 'Výrobca / Model');
    $engHeader = $lang === 'en' ? 'Engine Type' : ($lang === 'de' ? 'Motortyp' : 'Typ motora');
    echo '<tr style="background:#f0f0f0;font-weight:600"><td>' . $vehHeader . '</td><td>' . $engHeader . '</td></tr>';
    foreach ($vehicles as $v) {
        echo '<tr><td>' . $e($v['manufacturer']) . ' ' . $e($v['model']) . '</td><td>' . $e($v['type_name']) . '</td></tr>';
    }
    if (empty($vehicles)) echo '<tr><td colspan="2" style="color:#888">' . $t('no_vehicles') . '</td></tr>';
    echo '</table></div>';

    return ob_get_clean();
}

function renderVehicle(int $ktype, array $result, array $cats, ?int $activeCatId): string {
    global $e, $price, $catName, $t;
    ob_start();
    echo '<div class="breadcrumb"><a href="?">' . $t('home') . '</a> &rsaquo; ' . $t('vehicle_parts') . ' (ktype: ' . $ktype . ')</div>';
    echo '<h1 style="margin-bottom:16px">' . $t('vehicle_parts') . '</h1>';

    if (!empty($cats)) {
        echo '<div class="grid grid-3" style="margin-bottom:24px">';
        foreach ($cats as $c) {
            $active = $activeCatId === (int)$c['id'] ? 'border:2px solid #cc3872;' : '';
            echo '<a href="?page=vehicle&ktype=' . $ktype . '&cat=' . (int)$c['id'] . '" class="cat-card" style="' . $active . '">';
            echo '<span style="flex:1">' . $e($catName($c['name'])) . '</span><span class="count">' . (int)$c['part_count'] . '</span></a>';
        }
        echo '</div>';
    }

    echo '<div class="sort-bar"><span class="total">' . number_format($result['total']) . ' ' . $t('parts_count') . '</span></div>';
    echo '<div class="grid grid-4">';
    foreach ($result['products'] as $p) {
        $p = Products::enrich($p);
        $imgs = is_string($p['images'] ?? null) ? json_decode($p['images'], true) : ($p['images'] ?? []);
        $firstImg = $imgs[0] ?? null;
        echo '<div class="card" style="position:relative">';
        echo '<a href="?page=product&slug=' . $e($p['slug']) . '">';
        if ($firstImg) {
            echo '<img class="card-img" src="' . $e($firstImg) . '" alt="' . $e($p['name']) . '" loading="lazy">';
        } else {
            echo '<div class="card-img-placeholder">📷</div>';
        }
        echo '<h3>' . $e($p['name']) . '</h3>';
        echo '<div class="price">' . $price($p['price']) . '</div>';
        echo '</a>';
        echo '<button class="btn-cart" onclick="addToCart(' . (int)$p['id'] . ',1,this)">' . $t('add_to_cart') . '</button>';
        echo '</div>';
    }
    echo '</div>';
    $baseUrl = '?page=vehicle&ktype=' . $ktype . ($activeCatId ? '&cat=' . $activeCatId : '');
    echo renderPagination($result['page'], $result['pages'], $baseUrl);
    return ob_get_clean();
}

function renderSearch(string $q, array $result): string {
    global $e, $price, $t;
    ob_start();
    echo '<div class="breadcrumb"><a href="?">' . $t('home') . '</a> &rsaquo; ' . $t('search') . ': <strong>' . $e($q) . '</strong></div>';
    echo '<h1 style="margin-bottom:4px">' . $t('search_results') . '</h1>';
    echo '<div class="sort-bar"><span class="total">' . number_format($result['total']) . ' ' . $t('results_for') . ' "' . $e($q) . '"</span></div>';
    echo renderProductGrid($result['products']);
    echo renderPagination($result['page'], $result['pages'], '?page=search&q=' . urlencode($q));
    return ob_get_clean();
}

function renderOE(string $q, array $result): string {
    global $e, $price, $t;
    ob_start();
    echo '<div class="breadcrumb"><a href="?">' . $t('home') . '</a> &rsaquo; OE: <strong>' . $e($q) . '</strong></div>';
    echo '<h1 style="margin-bottom:4px">' . $t('oe_search') . '</h1>';
    echo '<div class="sort-bar"><span class="total">' . number_format($result['total']) . ' ' . $t('results_for') . ' OE "' . $e($q) . '"</span></div>';
    echo renderProductGrid($result['products']);
    echo renderPagination($result['page'], $result['pages'], '?page=oe&q=' . urlencode($q));
    return ob_get_clean();
}

function renderProductGrid(array $products): string {
    global $e, $price, $t;
    ob_start();
    echo '<div class="grid grid-4">';
    foreach ($products as $p) {
        $p = Products::enrich($p);
        $imgs = is_string($p['images'] ?? null) ? json_decode($p['images'], true) : ($p['images'] ?? []);
        $firstImg = $imgs[0] ?? null;
        echo '<div class="card" style="position:relative">';
        echo '<a href="?page=product&slug=' . $e($p['slug']) . '">';
        if ($firstImg) {
            echo '<img class="card-img" src="' . $e($firstImg) . '" alt="' . $e($p['name']) . '" loading="lazy">';
        } else {
            echo '<div class="card-img-placeholder">📷</div>';
        }
        if (!empty($p['compare_price']) && $p['compare_price'] > $p['price']) {
            echo '<span class="sticker sticker-sale">SALE</span>';
        }
        echo '<h3>' . $e($p['name']) . '</h3>';
        echo '<div class="price">' . $price($p['price']);
        if (!empty($p['compare_price'])) echo '<span class="old-price">' . $price($p['compare_price']) . '</span>';
        echo '</div>';
        echo '</a>';
        echo '<button class="btn-cart" onclick="addToCart(' . (int)$p['id'] . ',1,this)">' . $t('add_to_cart') . '</button>';
        echo '</div>';
    }
    if (empty($products)) echo '<p style="padding:40px;text-align:center;color:#888;grid-column:1/-1">' . $t('no_results') . '</p>';
    echo '</div>';
    return ob_get_clean();
}

function renderPagination(int $page, int $pages, string $baseUrl): string {
    if ($pages <= 1) return '';
    $sep = str_contains($baseUrl, '?') ? '&' : '?';
    $html = '<div class="pagination">';
    if ($page > 1) $html .= '<a href="' . $baseUrl . $sep . 'pg=' . ($page-1) . '">&laquo;</a>';
    $start = max(1, $page - 3);
    $end = min($pages, $page + 3);
    for ($i = $start; $i <= $end; $i++) {
        $html .= $i === $page
            ? '<span class="current">' . $i . '</span>'
            : '<a href="' . $baseUrl . $sep . 'pg=' . $i . '">' . $i . '</a>';
    }
    if ($page < $pages) $html .= '<a href="' . $baseUrl . $sep . 'pg=' . ($page+1) . '">&raquo;</a>';
    $html .= '</div>';
    return $html;
}

function buildCategoryUrl(string $slug, string $sort, array $specFilters, ?int $pmin, ?int $pmax, array $subcats = [], array $rangeFilters = []): string {
    $url = '?page=category&slug=' . urlencode($slug) . '&sort=' . urlencode($sort);
    foreach ($subcats as $scId) $url .= '&subcat[]=' . (int)$scId;
    foreach ($specFilters as $fn => $fvs) {
        foreach ($fvs as $fv) $url .= '&spec[' . urlencode($fn) . '][]=' . urlencode($fv);
    }
    if ($pmin) $url .= '&pmin=' . (int)($pmin / 100);
    if ($pmax) $url .= '&pmax=' . (int)($pmax / 100);
    foreach ($rangeFilters as $fn => $rv) {
        if (isset($rv['min'])) $url .= '&rmin[' . urlencode($fn) . ']=' . urlencode((string)$rv['min']);
        if (isset($rv['max'])) $url .= '&rmax[' . urlencode($fn) . ']=' . urlencode((string)$rv['max']);
    }
    return $url;
}

function renderCart(): string {
    global $e, $price, $t, $csrfField;
    $cart = $_SESSION['cart'] ?? [];
    ob_start();

    echo '<div class="breadcrumb"><a href="?">' . $t('home') . '</a> &rsaquo; ' . $t('cart') . '</div>';
    echo '<h1 style="margin-bottom:20px">' . $t('cart_title') . '</h1>';

    if (empty($cart)) {
        echo '<div style="text-align:center;padding:60px;background:#fff;border-radius:8px">';
        echo '<p style="font-size:1.2em;color:#888;margin-bottom:16px">' . $t('cart_empty') . '</p>';
        echo '<a href="?" style="color:#cc3872;font-weight:600">' . $t('continue_shopping') . '</a>';
        echo '</div>';
        return ob_get_clean();
    }

    // Load products
    $ids = array_keys($cart);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = Db::get()->prepare("SELECT * FROM products WHERE id IN ({$ph})");
    $stmt->execute($ids);
    $products = [];
    foreach ($stmt->fetchAll() as $p) $products[(int)$p['id']] = $p;

    $subtotal = 0;
    echo '<div style="display:grid;grid-template-columns:1fr 320px;gap:24px">';

    // Cart table
    echo '<div><table class="cart-table">';
    echo '<tr><th>' . $t('product') . '</th><th>' . $t('price') . '</th><th>' . $t('quantity') . '</th><th>' . $t('total') . '</th><th></th></tr>';
    foreach ($cart as $pid => $qty) {
        $p = $products[$pid] ?? null;
        if (!$p) continue;
        $p = Products::enrich($p);
        $lineTotal = $p['price'] * $qty;
        $subtotal += $lineTotal;
        $attrs = is_array($p['attrs']) ? $p['attrs'] : json_decode($p['attrs'] ?? '{}', true);
        echo '<tr>';
        echo '<td><a href="?page=product&slug=' . $e($p['slug']) . '" class="prod-name">' . $e($p['name']) . '</a>';
        echo '<div class="prod-sku">' . $e($p['sku']) . '</div></td>';
        echo '<td>' . $price($p['price']) . '</td>';
        echo '<td><form method="post" style="display:flex;gap:4px">';
        echo $csrfField;
        echo '<input type="hidden" name="cart_action" value="update">';
        echo '<input type="hidden" name="product_id" value="' . $pid . '">';
        echo '<input type="hidden" name="redirect" value="?page=cart">';
        echo '<input type="number" name="quantity" value="' . $qty . '" min="1" max="99" class="qty-input" onchange="this.form.submit()">';
        echo '</form></td>';
        echo '<td style="font-weight:600">' . $price($lineTotal) . '</td>';
        echo '<td><form method="post">' . $csrfField . '<input type="hidden" name="cart_action" value="remove"><input type="hidden" name="product_id" value="' . $pid . '"><input type="hidden" name="redirect" value="?page=cart">';
        echo '<button type="submit" class="btn-remove" title="' . $t('remove') . '">✕</button></form></td>';
        echo '</tr>';
    }
    echo '</table></div>';

    // Summary sidebar
    $shipping = $subtotal >= 5000 ? 0 : 499; // Free shipping over 50€
    $total = $subtotal + $shipping;

    echo '<div class="cart-summary">';
    echo '<h3>' . $t('order_summary') . '</h3>';
    echo '<div class="row"><span>' . $t('subtotal') . '</span><span>' . $price($subtotal) . '</span></div>';
    echo '<div class="row"><span>' . $t('shipping') . '</span><span>' . ($shipping === 0 ? '<span style="color:#2e7d32">' . $t('free_shipping') . '</span>' : $price($shipping)) . '</span></div>';
    if ($shipping > 0) echo '<div style="font-size:.75em;color:#888">' . $t('free_over') . ' ' . $price(5000) . '</div>';
    echo '<div class="row total-row"><span>' . $t('total_price') . '</span><span>' . $price($total) . '</span></div>';
    echo '<a href="?page=checkout" class="btn-checkout">' . $t('to_checkout') . '</a>';
    echo '<form method="post" style="margin-top:8px">' . $csrfField . '<input type="hidden" name="cart_action" value="clear"><input type="hidden" name="redirect" value="?page=cart">';
    echo '<button type="submit" style="background:none;border:none;color:#888;cursor:pointer;font-size:.85em;width:100%;text-align:center">' . $t('clear_cart') . '</button></form>';
    echo '</div>';

    echo '</div>';
    return ob_get_clean();
}

function renderCheckout(): string {
    global $e, $price, $t, $csrfField;
    $cart = $_SESSION['cart'] ?? [];

    if (empty($cart)) {
        return '<div class="breadcrumb"><a href="?">' . $t('home') . '</a> &rsaquo; ' . $t('checkout_title') . '</div>
                <p>' . $t('cart_empty') . '. <a href="?" style="color:#cc3872">' . $t('continue_shopping') . '</a></p>';
    }

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['checkout'])) {
        return processCheckout();
    }

    // Load products
    $ids = array_keys($cart);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = Db::get()->prepare("SELECT * FROM products WHERE id IN ({$ph})");
    $stmt->execute($ids);
    $products = [];
    foreach ($stmt->fetchAll() as $p) $products[(int)$p['id']] = $p;

    $subtotal = 0;
    foreach ($cart as $pid => $qty) {
        if (isset($products[$pid])) $subtotal += $products[$pid]['price'] * $qty;
    }
    $country = strtoupper($_POST['country'] ?? 'SK');
    $weightG = 0;
    foreach ($cart as $pid => $qty) {
        if (isset($products[$pid])) $weightG += (int)($products[$pid]['weight_g'] ?? 0) * (int)$qty;
    }
    $shippingRates = ShippingService::calculateRates($country, $weightG, $subtotal, false, 0);
    $selectedShipping = $_POST['shipping_method'] ?? ($shippingRates[0]['method_code'] ?? '');
    $selectedRate = $shippingRates[0] ?? null;
    foreach ($shippingRates as $rate) {
        if (($rate['method_code'] ?? '') === $selectedShipping) { $selectedRate = $rate; break; }
    }
    $shipping = $selectedRate ? (int)$selectedRate['price'] : 0;
    $currency = 'eur';
    $paymentMethods = $selectedRate ? PaymentService::availableMethods($country, $currency, $subtotal + $shipping, (string)$selectedRate['method_code']) : [];
    $selectedPayment = $paymentMethods[0] ?? null;
    $paymentFee = $selectedPayment ? (int)$selectedPayment['fee'] : 0;
    $total = $subtotal + $shipping + $paymentFee;

    ob_start();
    echo '<div class="breadcrumb"><a href="?">' . $t('home') . '</a> &rsaquo; <a href="?page=cart">' . $t('cart') . '</a> &rsaquo; ' . $t('checkout_title') . '</div>';
    echo '<h1 style="margin-bottom:20px">' . $t('checkout_title') . '</h1>';

    echo '<div style="display:grid;grid-template-columns:1fr 340px;gap:24px">';

    echo '<form method="post" class="checkout-form">';
    echo $csrfField . '<input type="hidden" name="checkout" value="1">';
    echo '<h3>' . $t('contact') . '</h3>';
    echo '<label>' . $t('name') . '</label><input name="name" required>';
    echo '<div class="form-row"><div><label>' . $t('email') . '</label><input type="email" name="email" required></div><div><label>' . $t('phone') . '</label><input name="phone"></div></div>';
    echo '<h3 style="margin-top:20px">' . $t('address') . '</h3>';
    echo '<label>' . $t('street') . '</label><input name="street" required>';
    echo '<div class="form-row"><div><label>' . $t('city') . '</label><input name="city" required></div><div><label>' . $t('zip') . '</label><input name="zip" required></div></div>';
    echo '<label>Country</label><select name="country" id="checkout-country"><option value="SK">Slovensko</option><option value="CZ">Česko</option><option value="DE">Deutschland</option><option value="AT">Österreich</option><option value="HU">Magyarország</option><option value="PL">Polska</option></select>';
    echo '<h3 style="margin-top:20px">' . $t('shipping') . '</h3><div class="shipping-options" id="shipping-options">';
    foreach ($shippingRates as $i => $rate) {
        $checked = (($rate['method_code'] ?? '') === $selectedShipping || ($i === 0 && $selectedShipping === '')) ? ' checked' : '';
        echo '<label class="shipping-option"><span><input type="radio" name="shipping_method" value="' . $e($rate['method_code']) . '"' . $checked . '> ' . $e($rate['label']) . '<small>' . $e((string)($rate['delivery_days'] ?? '')) . '</small></span><strong>' . ($rate['price'] === 0 ? $t('free_shipping') : $price((int)$rate['price'])) . '</strong></label>';
    }
    if (empty($shippingRates)) echo '<p style="color:#c62828">Doručenie nie je dostupné.</p>';
    echo '</div>';
    echo '<h3 style="margin-top:20px">' . $t('payment') . '</h3><div class="payment-options" id="payment-options">';
    foreach ($paymentMethods as $i => $method) {
        echo '<label class="payment-option"><span><input type="radio" name="payment" value="' . $e($method['code']) . '"' . ($i === 0 ? ' checked' : '') . '> ' . $e($method['label']) . '<small>' . $e((string)($method['description'] ?: $method['provider'])) . '</small></span><strong>' . ((int)$method['fee'] === 0 ? $t('free_shipping') : $price((int)$method['fee'])) . '</strong></label>';
    }
    if (empty($paymentMethods)) echo '<p style="color:#c62828">Platba nie je dostupná.</p>';
    echo '</div>';
    echo '<label>' . $t('notes') . '</label><textarea name="notes" placeholder="' . $t('notes_placeholder') . '"></textarea>';
    echo '<button class="btn-checkout" type="submit">' . $t('submit_order') . '</button>';
    echo '</form>';

    // Order summary sidebar
    echo '<div class="cart-summary">';
    echo '<h3>' . $t('your_order') . '</h3>';
    foreach ($cart as $pid => $qty) {
        $p = $products[$pid] ?? null;
        if (!$p) continue;
        echo '<div class="row" style="font-size:.85em"><span>' . $e($p['name']) . ' × ' . $qty . '</span><span>' . $price($p['price'] * $qty) . '</span></div>';
    }
    echo '<input type="hidden" id="checkout-subtotal" value="' . (int)$subtotal . '">';
    echo '<div class="row" style="margin-top:8px"><span>' . $t('subtotal') . '</span><span>' . $price($subtotal) . '</span></div>';
    echo '<div class="row"><span>' . $t('shipping') . '</span><span id="summary-shipping">' . ($shipping === 0 ? $t('free_shipping') : $price($shipping)) . '</span></div>';
    echo '<div class="row"><span>' . $t('payment') . '</span><span id="summary-payment">' . ($paymentFee === 0 ? $t('free_shipping') : $price($paymentFee)) . '</span></div>';
    echo '<div class="row total-row"><span>' . $t('total_price') . '</span><span id="summary-total">' . $price($total) . '</span></div>';
    echo '</div>';

    echo '</div>';
    return ob_get_clean();
}

function processCheckout(): string {
    global $e, $price, $t;

    if (!Security::csrfValidate()) {
        return '<div class="breadcrumb"><a href="?">Domov</a> &rsaquo; Chyba</div><p style="color:#c62828">CSRF token mismatch.</p>';
    }

    $cart = $_SESSION['cart'] ?? [];
    if (empty($cart)) {
        return '<div class="breadcrumb"><a href="?">Domov</a> &rsaquo; Chyba</div><p style="color:#c62828">Košík je prázdny.</p>';
    }

    $db = Db::get();

    // Load products
    $ids = array_keys($cart);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT * FROM products WHERE id IN ({$ph}) AND status = 'active'");
    $stmt->execute($ids);
    $products = [];
    foreach ($stmt->fetchAll() as $p) $products[(int)$p['id']] = $p;

    $payment = Security::clean($_POST['payment'] ?? 'cod');
    $country = strtoupper(Security::clean($_POST['country'] ?? 'SK'));
    $shippingMethod = Security::slug($_POST['shipping_method'] ?? '');
    $addr = json_encode([
        'street' => Security::clean($_POST['street'] ?? ''),
        'city'   => Security::clean($_POST['city'] ?? ''),
        'zip'    => Security::clean($_POST['zip'] ?? ''),
        'country'=> $country,
    ]);

    // Convert index.php cart format to Orders::create format
    $cartItems = [];
    foreach ($cart as $pid => $qty) {
        $p = $products[$pid] ?? null;
        if (!$p) continue;
        $cartItems[] = [
            'product_id' => (int)$pid,
            'variant_id' => null,
            'qty'        => (int)$qty,
            'price'      => (int)$p['price'],
            'sku'        => $p['sku'],
            'name'       => $p['name'],
        ];
    }

    $subtotal = array_sum(array_map(fn($i) => $i['price'] * $i['qty'], $cartItems));
    $weightG = 0;
    foreach ($cartItems as $item) $weightG += (int)($products[$item['product_id']]['weight_g'] ?? 0) * (int)$item['qty'];
    $selectedRate = ShippingService::selectRate($shippingMethod, $country, $weightG, $subtotal, false, 0);
    if (!$selectedRate) {
        return '<div class="breadcrumb"><a href="?">Domov</a> &rsaquo; Chyba</div><p style="color:#c62828">Doručenie nie je dostupné do zvolenej krajiny.</p>';
    }
    $shippingCost = (int)$selectedRate['price'];
    $currency = 'eur';
    $selectedPayment = PaymentService::selectMethod($payment, $country, $currency, $subtotal + $shippingCost, (string)$selectedRate['method_code']);
    if (!$selectedPayment) {
        return '<div class="breadcrumb"><a href="?">Domov</a> &rsaquo; Chyba</div><p style="color:#c62828">Platba nie je dostupná pre zvolenú krajinu alebo dopravu.</p>';
    }
    $paymentFee = (int)$selectedPayment['fee'];

    $loggedInCustomerId = !empty($_SESSION['customer_id']) ? (int)$_SESSION['customer_id'] : null;
    $customer = [
        'customer_id' => $loggedInCustomerId,
        'name'  => Security::clean($_POST['name'] ?? ''),
        'email' => Security::clean($_POST['email'] ?? ''),
        'phone' => Security::clean($_POST['phone'] ?? ''),
        'shipping_addr' => $addr,
        'notes' => Security::clean($_POST['notes'] ?? ''),
        'shipping_cost' => $shippingCost,
        'shipping_method' => $selectedRate['method_code'],
        'shipping_zone' => $selectedRate['zone_code'] ?? '',
        'shipping_weight_g' => $weightG,
        'shipping_rate_snapshot' => json_encode($selectedRate, JSON_UNESCAPED_UNICODE),
        'payment_fee' => $paymentFee,
        'payment_method_snapshot' => json_encode($selectedPayment, JSON_UNESCAPED_UNICODE),
    ];

    try {
        // Orders::create handles atomic stock reservation
        $orderId = Orders::create($cartItems, $customer, $payment);
        $_SESSION['cart'] = [];
        $order = Orders::byId($orderId);
        header('Location: ?page=order-done&order=' . urlencode($order['order_number']));
        exit;
    } catch (\RuntimeException $ex) {
        // Out of stock
        return '<div class="breadcrumb"><a href="?">Domov</a> &rsaquo; Chyba</div><p style="color:#c62828">' . esc($ex->getMessage()) . '</p>';
    } catch (\Exception $ex) {
        return '<div class="breadcrumb"><a href="?">Domov</a> &rsaquo; Chyba</div><p style="color:#c62828">Chyba pri spracovaní objednávky. Skúste to znova.</p>';
    }
}

function renderOrderDone(string $orderNum): string {
    global $e, $price, $t;
    ob_start();
    echo '<div class="order-done">';
    echo '<div style="font-size:3em;margin-bottom:16px">✓</div>';
    echo '<h1>' . $t('order_sent') . '</h1>';
    echo '<p style="font-size:1.1em;color:#555;margin:12px 0">' . $t('order_num') . ': <strong>' . $e($orderNum) . '</strong></p>';
    echo '<p style="color:#888;margin-bottom:24px">' . $t('order_email') . '</p>';
    echo '<a href="?" style="color:#cc3872;font-weight:600;font-size:1.1em">' . $t('continue_shopping') . '</a>';
    echo '</div>';
    return ob_get_clean();
}
