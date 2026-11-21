<?php
$pdo ??= test_commerce_fixture();

// Review round 21 (R51, medium): a store sharing another store's shipping and payment methods (provider_scope) was
// offered only its own further languages for the methods' names, so a German store on a Slovak store's methods could
// not enter German at all (the German page showed "GLS kuriér SK"), and saving a method from one store dropped the
// translations entered from the other.
echo "\n--- Audit round 51: method translations in stores sharing their methods ---\n";

$r51iStoresProperty = new ReflectionProperty(Bootstrap::class, 'stores');
$r51iSavedStores = $r51iStoresProperty->getValue();
$r51iSavedStore = StoreContext::hasCurrent() ? StoreContext::current() : null;
try {
    $r51iStores = Bootstrap::stores();
    $r51iStores['stores']['r51sk'] = ['name' => 'R51 SK', 'currency' => 'eur', 'default_locale' => 'sk', 'languages' => ['sk', 'en'], 'url_prefix' => '/r51sk'];
    $r51iStores['stores']['r51de'] = ['name' => 'R51 DE', 'currency' => 'eur', 'default_locale' => 'de', 'languages' => ['de'], 'url_prefix' => '/r51de', 'provider_scope' => 'r51sk'];
    $r51iStores['stores']['r51cz'] = ['name' => 'R51 CZ', 'currency' => 'eur', 'default_locale' => 'cs', 'languages' => ['cs'], 'url_prefix' => '/r51cz', 'provider_scope' => 'r51sk'];
    $r51iStoresProperty->setValue(null, $r51iStores);

    Bootstrap::initStoreContext('/r51sk/', '', 'r51sk');
    $r51iFromSk = MethodLabels::translationLanguages();
    sort($r51iFromSk);
    assert_equals(['cs', 'de', 'en'], $r51iFromSk, 'R51 method names: the Slovak store offers German and Czech for the methods its other stores share (was: only en)');
    Bootstrap::initStoreContext('/r51de/', '', 'r51de');
    assert_equals('r51sk', StoreContext::current()->providerId(), 'R51 method names: the German store shares the Slovak store\'s methods');
    $r51iFromDe = MethodLabels::translationLanguages();
    sort($r51iFromDe);
    assert_equals(['cs', 'de', 'en'], $r51iFromDe, 'R51 method names: the German store sharing them can enter German (was: none, the method names are Slovak)');

    // Saved from the German store with only German posted: the Czech and English texts entered before stay.
    $r51iSaved = '{"cs":{"name":"GLS kurýr CZ"},"en":{"name":"GLS courier"},"pl":{"name":"Kurier GLS"}}';
    assert_equals('{"cs":{"name":"GLS kurýr CZ"},"de":{"name":"GLS Kurier"},"en":{"name":"GLS courier"},"pl":{"name":"Kurier GLS"}}',
        MethodLabels::encodeTranslations(['de' => ['name' => 'GLS Kurier'], 'cs' => ['name' => 'GLS kurýr CZ'], 'en' => ['name' => 'GLS courier']], ['de', 'cs', 'en'], $r51iSaved),
        'R51 method names: a save keeps the translations of languages the form did not offer and sets the posted ones');
    assert_equals('{"de":{"name":"GLS Kurier"},"pl":{"name":"Kurier GLS"}}',
        MethodLabels::encodeTranslations(['de' => ['name' => 'GLS Kurier'], 'cs' => ['name' => ' ']], ['de', 'cs', 'en'], $r51iSaved),
        'R51 method names: a language the form offered and the merchant emptied is removed');

    $pdo->prepare("DELETE FROM shipping_methods WHERE store_id = 'r51sk'")->execute();
    $pdo->prepare("INSERT INTO shipping_methods (store_id, code, carrier, name, description, service_type, active, sort_order, translations) VALUES ('r51sk', 'r51_gls', 'gls', 'GLS kuriér SK', '', 'parcel', 1, 900, ?)")
        ->execute([MethodLabels::encodeTranslations(['de' => ['name' => 'GLS Kurier']], MethodLabels::translationLanguages())]);
    MethodLabels::forget();
    assert_equals(['GLS Kurier', 'GLS kuriér SK'], [MethodLabels::name('shipping', 'r51_gls', 'GLS kuriér SK', 'de'), MethodLabels::name('shipping', 'r51_gls', 'GLS kuriér SK', 'sk')],
        'R51 method names: the German page of the store sharing the method shows its German name; the Slovak one the entered name');

    $r51iOperations = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Operations.php');
    assert_equals(2, substr_count($r51iOperations, "MethodLabels::encodeTranslations(\$_POST['i18n'] ?? null, MethodLabels::translationLanguages(), \$old['translations'] ?? null)"),
        'R51 method names: the shipping and payment method forms save the posted languages over the saved translations');
} catch (Throwable $e) {
    assert_true(false, 'R51 method names (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    try { $pdo->prepare("DELETE FROM shipping_methods WHERE store_id = 'r51sk'")->execute(); } catch (PDOException) {}
    $r51iStoresProperty->setValue(null, $r51iSavedStores);
    MethodLabels::forget();
    if ($r51iSavedStore !== null) StoreContext::activate($r51iSavedStore);
    else StoreContext::reset();
}
