<?php
require_once __DIR__ . '/cli-only.php';

/**
 * Fix product and category slugs — re-slugify with proper diacritics handling. The old URLs keep working:
 * the storefront redirects each replaced slug (301) to the new one (SlugRepair).
 */
require_once __DIR__ . '/../src/autoload.php';

$result = SlugRepair::run(Db::get());
foreach ($result['log'] as $line) echo $line, "\n";
echo "Fixed {$result['products']} product slugs out of {$result['product_total']} total.\n";
echo "Fixed {$result['categories']} category slugs out of {$result['category_total']} total.\n";
echo "\nDone!\n";
