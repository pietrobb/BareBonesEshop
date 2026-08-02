<?php
/**
 * BareBones Shop — Storefront
 * Generic catalog with conditional TecDoc auto-parts features.
 */
require_once dirname(__DIR__, 2) . '/src/autoload.php';

require_once __DIR__ . '/Storefront/CatalogPages.php';
require_once __DIR__ . '/Storefront/CheckoutPages.php';
Security::headers();
Security::sessionStart();
Customers::isLoggedIn();
if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];

// Locale from URL — canonical, no session override
$lang = Bootstrap::initLocale();

// Legacy category-name fallback for categories not yet migrated to
// categories.translations JSON (see data/category-translations.php).
$catTranslations = Bootstrap::catTranslations();

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
$_catalog = json_decode(file_get_contents(dirname(__DIR__, 2) . '/catalog.json'), true);
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


require __DIR__ . '/Storefront/Layout.php';
