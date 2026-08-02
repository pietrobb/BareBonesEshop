<?php
/** Shared storefront document renderer. Included in request scope. */
// Layout
// ============================================================
// Build hreflang alternates from stores.json — one per store url_prefix
$hreflangData = Bootstrap::hreflangAlternates();
$hreflangs = $hreflangData['alternates'];
$pathNoPrefix = $hreflangData['pathNoPrefix'];
$themeAssets = Template::assets();
$managedNavigation = ContentService::menu('primary', $lang);

// Structured data (JSON-LD): Organization + WebSite searchbox on the homepage,
// BreadcrumbList on category pages.
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$jsonLd = '';
$nonce = Security::cspNonce();
if ($page === 'home') {
    $jsonLd  = JsonLd::render(JsonLd::organization($base, Bootstrap::stores()), $nonce);
    $jsonLd .= JsonLd::render(JsonLd::webSite($base, Bootstrap::stores()), $nonce);
} elseif ($page === 'category' && !empty($cat)) {
    $crumbItems = [['name' => $t('home'), 'url' => '/']];
    foreach (Categories::breadcrumb((int)$cat['id']) as $crumbCat) {
        $crumbItems[] = ['name' => $catName($crumbCat['name']), 'url' => '/category/' . $crumbCat['slug']];
    }
    $jsonLd = JsonLd::render(JsonLd::breadcrumbList($crumbItems, $base), $nonce);
}
ob_start();
?>
<?php foreach ($hreflangs as $loc => $pfx): ?>
<link rel="alternate" hreflang="<?= $e($loc) ?>" href="<?= $e($pfx . $pathNoPrefix) ?>">
<?php endforeach; ?>
<link rel="alternate" hreflang="x-default" href="<?= $e($hreflangData['xDefaultPrefix'] . $pathNoPrefix) ?>">
<?php if ($jsonLd) echo $jsonLd . "\n"; ?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600&display=swap');
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f5f5f5;color:#333;line-height:1.5;font-size:14px}
a{color:#333;text-decoration:none}a:hover{color:#a82d5c}
h1,h2,h3,h4,h5,.section-title{font-family:'Oswald',georgia,serif;font-weight:500}
.wrap{max-width:1280px;margin:0 auto;padding:0 16px}

/* Header */
header{background:#333;color:#fff;padding:0}
.header-top{background:#f5f5f5;padding:6px 0;font-size:.8em;color:#777;border-bottom:1px solid #e5e5e5}
.header-top .wrap{display:flex;justify-content:flex-end;gap:16px;align-items:center}
.header-top a{color:#777;font-size:.8em}.header-top a:hover{color:#a82d5c}
.header-main{background:#fff;padding:12px 0;border-bottom:1px solid #e5e5e5}
.header-main .wrap{display:flex;align-items:center;gap:24px;flex-wrap:wrap}
header .logo{white-space:nowrap}
header .logo img{height:40px;display:block}
header .logo a{display:block}
.header-nav{background:#333;padding:0;position:relative;z-index:100}
.header-nav .wrap{display:flex;align-items:stretch;gap:0;flex-wrap:wrap}
.header-nav > .wrap > a,.nav-item > a{color:#fff;font-family:'Oswald',georgia,serif;font-size:15px;text-transform:uppercase;padding:14px 18px;display:block;transition:background .2s}
.header-nav > .wrap > a:hover,.nav-item:hover > a{background:#a82d5c;color:#fff}
.nav-item{position:relative}
.nav-dropdown{display:none;position:absolute;top:100%;left:0;background:#fff;min-width:260px;box-shadow:0 4px 16px rgba(0,0,0,.15);border-radius:0 0 4px 4px;z-index:200;padding:8px 0}
.nav-item:hover .nav-dropdown{display:block}
.nav-dropdown a{color:#333;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;font-size:13px;text-transform:none;padding:7px 20px;display:block;transition:background .15s,color .15s}
.nav-dropdown a:hover{background:#f5f5f5;color:#a82d5c}
.nav-dropdown .sub-heading{font-family:'Oswald',georgia,serif;font-size:13px;font-weight:600;color:#333;padding:10px 20px 4px;text-transform:uppercase;border-top:1px solid #eee;margin-top:4px}
.nav-dropdown .sub-heading:first-child{border-top:none;margin-top:0}
.search-bar{display:flex;gap:0;margin-left:auto}
.search-bar input{padding:8px 14px;border:1px solid #e5e5e5;border-right:none;border-radius:4px 0 0 4px;font-size:.9em;width:220px;background:#fff;color:#333}
.search-bar button{background:#a82d5c;border:none;color:#fff;padding:8px 16px;border-radius:0 4px 4px 0;cursor:pointer;font-weight:600}

/* Main */
main{padding:24px 0;min-height:60vh}
.breadcrumb{margin-bottom:16px;font-size:.85em;color:#666}
.breadcrumb a{color:#333;text-decoration:underline}.breadcrumb a:hover{color:#a82d5c}

/* Grid */
.grid{display:grid;gap:16px}
.grid-4{grid-template-columns:repeat(auto-fill,minmax(240px,1fr))}
.grid-3{grid-template-columns:repeat(auto-fill,minmax(300px,1fr))}

/* Cards */
.card{background:#fff;border-radius:8px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,.08);transition:box-shadow .2s}
.card:hover{box-shadow:0 4px 12px rgba(0,0,0,.12)}
.card h3{font-size:1em;margin-bottom:6px}
.card .price{font-size:1.2em;font-weight:700;color:#725be7}
.card .old-price{text-decoration:line-through;color:#666;font-size:.85em;margin-left:6px}
.card .supplier{font-size:.8em;color:#888;margin-bottom:4px}
.card .sku{font-size:.75em;color:#aaa}
.card .badge{display:inline-block;background:#e8f5e9;color:#2e7d32;padding:2px 8px;border-radius:10px;font-size:.75em}

/* Category cards */
.cat-card{display:flex;align-items:center;gap:12px;background:#fff;border-radius:8px;padding:14px 18px;box-shadow:0 1px 3px rgba(0,0,0,.08)}
.cat-card:hover{box-shadow:0 4px 12px rgba(0,0,0,.12)}
.cat-card .count{background:#a82d5c;color:#fff;border-radius:20px;padding:2px 10px;font-size:.8em;font-weight:600;white-space:nowrap}

/* Layout with sidebar */
.layout-sidebar{display:grid;grid-template-columns:280px 1fr;gap:24px}
@media(max-width:800px){.layout-sidebar{grid-template-columns:1fr}}

/* Sidebar filters */
.sidebar{background:#fff;border-radius:8px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,.08);align-self:start;position:sticky;top:16px;max-height:90vh;overflow-y:auto}
.sidebar h3{font-size:.9em;margin:12px 0 6px;color:#555;border-bottom:1px solid #eee;padding-bottom:4px}
.sidebar h3:first-child{margin-top:0}
.sidebar label{display:block;font-size:.85em;padding:2px 0;cursor:pointer}
.sidebar label:hover{color:#a82d5c}
.sidebar input[type=checkbox]{margin-right:4px}
.sidebar .cnt{color:#aaa;font-size:.8em}
.sidebar .filter-actions{margin-top:12px;display:flex;gap:8px}
.sidebar .btn-apply{background:#a82d5c;color:#fff;border:none;padding:8px 16px;border-radius:4px;cursor:pointer;font-weight:600;font-size:.85em;width:100%}
.sidebar .btn-clear{background:#eee;color:#555;border:none;padding:8px 16px;border-radius:4px;cursor:pointer;font-size:.85em}

/* Hero / Slider */
.hero{position:relative;border-radius:0;margin-bottom:24px;overflow:hidden;max-height:400px}
.hero img{width:100%;display:block;object-fit:cover;max-height:400px}
.hero-overlay{position:absolute;bottom:0;left:0;right:0;padding:30px 40px;background:linear-gradient(transparent,rgba(0,0,0,.6));color:#fff}
.hero-overlay h1{font-size:2em;margin-bottom:8px;text-shadow:0 2px 4px rgba(0,0,0,.5)}
.hero-overlay .btn-hero{display:inline-block;background:#a82d5c;color:#fff;padding:10px 24px;border-radius:4px;font-weight:600;margin-top:8px;transition:background .2s}
.hero-overlay .btn-hero:hover{background:#852247;color:#fff}

/* Tabs */
.tabs{display:flex;gap:0;border-bottom:2px solid #eee;margin-bottom:16px}
.tab{padding:10px 20px;cursor:pointer;font:inherit;font-size:.9em;border:0;border-bottom:2px solid transparent;background:none;margin-bottom:-2px;color:#666}
.tab.active{color:#333;border-bottom-color:#a82d5c;font-weight:600}
.tab-content{display:none}.tab-content.active{display:block}

/* Tables */
.spec-table{width:100%;border-collapse:collapse}
.spec-table tr:nth-child(even){background:#f9f9f9}
.spec-table td{padding:6px 12px;border-bottom:1px solid #eee;font-size:.9em}
.spec-table td:first-child{color:#666;width:40%}

/* Pagination */
.pagination{display:flex;gap:4px;margin-top:20px;justify-content:center}
.pagination a,.pagination span{padding:6px 12px;border-radius:4px;font-size:.9em}
.pagination a{background:#fff;border:1px solid #ddd}.pagination a:hover{border-color:#a82d5c}
.pagination .current{background:#333;color:#fff}

/* Sort bar */
.sort-bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px}
.sort-bar .total{font-size:.9em;color:#666}
.sort-bar select{padding:6px;border:1px solid #ddd;border-radius:4px;font-size:.85em}

/* Cart icon */
.cart-icon{position:relative;font-size:1.4em;color:#fff;text-decoration:none;padding:4px 8px}
.cart-icon:hover{color:#a82d5c}
.cart-badge{position:absolute;top:-4px;right:-4px;background:#a82d5c;color:#fff;font-size:.5em;font-weight:700;border-radius:50%;width:20px;height:20px;display:flex;align-items:center;justify-content:center}

/* Add to cart button */
.btn-cart{background:#a82d5c;color:#fff;border:none;padding:8px 14px;border-radius:4px;cursor:pointer;font-weight:600;font-size:.85em;width:100%;margin-top:8px;transition:background .2s}
.btn-cart:hover{background:#852247}
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
.btn-checkout:hover{background:#a82d5c;color:#fff}

/* Checkout form */
.checkout-form{background:#fff;border-radius:8px;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,.08)}
.checkout-form label{display:block;font-size:.85em;font-weight:600;color:#555;margin:12px 0 4px}
.checkout-form label:first-child{margin-top:0}
.checkout-form input,.checkout-form textarea,.checkout-form select{width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;font-size:.95em}
.checkout-form textarea{height:80px;resize:vertical}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.shipping-options,.payment-options{display:grid;gap:8px;margin:8px 0 16px}
.shipping-option,.payment-option{display:flex;justify-content:space-between;gap:12px;padding:10px;border:1px solid #ddd;border-radius:6px;cursor:pointer}
.shipping-option:has(input:checked),.payment-option:has(input:checked){border-color:#a82d5c;background:#fff7fb}
.shipping-option small,.payment-option small{display:block;color:#666;margin-top:2px}
.shipping-loading,.shipping-empty{font-size:.9em;color:#777;padding:8px 0}

/* Order done */
.order-done{text-align:center;padding:60px 20px;background:#fff;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08)}
.order-done h1{color:#2e7d32;margin-bottom:12px}

/* Language switcher */
.lang-switch{display:flex;gap:4px;align-items:center}
.lang-switch a{color:#555;font-size:.75em;padding:2px 6px;border-radius:3px;border:1px solid transparent}
.lang-switch a:hover{color:#a82d5c;border-color:#a82d5c}
.lang-switch a.active{color:#a82d5c;border-color:#a82d5c;font-weight:700}

/* Product image in cards */
.card-img{width:100%;aspect-ratio:1;object-fit:contain;background:#fff;border-radius:4px;margin-bottom:8px}
.card-img-placeholder{width:100%;aspect-ratio:1;background:#f0f0f0;border-radius:4px;margin-bottom:8px;display:flex;align-items:center;justify-content:center;color:#ccc;font-size:2em}

/* Sale / New stickers */
.sticker{position:absolute;top:8px;right:8px;padding:3px 10px;border-radius:3px;font-size:.75em;font-weight:700;color:#fff;text-transform:uppercase}
.sticker-new{background:#b2da24}
.sticker-sale{background:#a93d00}

/* Legal pages */
.legal-page{max-width:900px;margin:0 auto}.legal-page h1{margin-bottom:16px}.legal-page h2{margin:22px 0 8px}.legal-page p,.legal-page li{margin:8px 0}.legal-page ul{margin-left:22px}

/* Footer */
footer{background:#333;color:#bbb;padding:0;margin-top:40px;font-size:.85em}
.footer-main{background:#333;padding:30px 0}
.footer-main .wrap{display:flex;gap:40px;flex-wrap:wrap}
.footer-col{flex:1;min-width:200px}
.footer-col h4{color:#fff;font-family:'Oswald',georgia,serif;margin-bottom:12px;font-size:1em;text-transform:uppercase}
.footer-col a{display:block;color:#bbb;padding:3px 0;font-size:.9em}.footer-col a:hover{color:#fff}
.footer-bottom{background:#222;padding:16px 0;text-align:center;color:#999;font-size:.8em}
</style>
<?php
$seoHead = ob_get_clean();
ob_start();
?>
<a class="skip-link" href="#main-content"><?= $t('skip_to_content') ?></a>
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
   <?php if ($managedNavigation): ?>
   <?php foreach ($managedNavigation as $item): ?><a href="<?= $e($item['url']) ?>"><?= $e($item['label']) ?></a><?php endforeach; ?>
   <?php else: ?>
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
     <a href="?page=category&slug=<?= $e($rc['slug']) ?>" style="font-weight:600;color:#a82d5c"><?= $lang==='en'?'View all...':($lang==='de'?'Alle anzeigen...':'Zobraziť všetko...') ?></a>
     <?php endif; ?>
    </div>
   </div>
   <?php else: ?>
   <a href="?page=category&slug=<?= $e($rc['slug']) ?>"><?= $e($catName($rc['name'])) ?></a>
   <?php endif; ?>
   <?php endforeach; ?>
   <?php endif; ?>
  </div>
 </div>
</header>

<main id="main-content" tabindex="-1"><div class="wrap">
<?= $content ?>
</div></main>

<footer>
 <div class="footer-main"><div class="wrap">
  <div class="footer-col">
   <h4>woodle.sk</h4>
   <p style="color:#bbb;font-size:.9em"><?= $lang==='en'?'Sauna & wooden products':($lang==='de'?'Sauna & Holzprodukte':'Sauna & drevené výrobky') ?></p>
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
<?php
$bodyHtml = ob_get_clean();
ob_start();
?>
<script nonce="<?= Security::cspNonce() ?>">
function setTab(id){
 document.querySelectorAll('[data-product-tab]').forEach(t=>{
  const active=t.dataset.productTab===id;
  t.classList.toggle('active',active);
  t.setAttribute('aria-selected',active?'true':'false');
 });
 document.querySelectorAll('.tab-content').forEach(c=>c.classList.toggle('active',c.id==='tab-'+id));
}
document.addEventListener('click',e=>{
 const cartButton=e.target.closest('[data-storefront-action="cart-add"]');
 if(cartButton){
  const quantityInput=cartButton.dataset.quantityInput?document.getElementById(cartButton.dataset.quantityInput):null;
  addToCart(cartButton.dataset.productId,quantityInput?quantityInput.value:cartButton.dataset.quantity,cartButton);
  return;
 }
 const tab=e.target.closest('[data-product-tab]');
 if(tab)setTab(tab.dataset.productTab);
});
document.addEventListener('change',e=>{
 const control=e.target.closest('[data-submit-on-change]');
 if(control&&control.form)control.form.requestSubmit();
});
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
<script nonce="<?= Security::cspNonce() ?>">
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
    fd.append('csrf_token','<?= Security::csrfToken() ?>');
    fetch('api.php?action=cookie_consent',{method:'POST',body:fd}).catch(function(){});
    b.parentNode.removeChild(b);
  }
  document.getElementById('cc-accept').onclick=function(){send({necessary:true,analytics:true,marketing:true},'banner_accept_all');};
  document.getElementById('cc-reject').onclick=function(){send({necessary:true,analytics:false,marketing:false},'banner_reject_all');};
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
<?php
$bodyEnd = ob_get_clean();
echo Template::document($title, '', [
    'seo_head' => $seoHead,
    'body_html' => $bodyHtml,
    'body_end' => $bodyEnd,
    'assets' => $themeAssets,
    'base_css' => '',
    'shop_js' => '',
]);

