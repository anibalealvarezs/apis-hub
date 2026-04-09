<?php

$dir = 'D:\laragon\www\apis-hub\src\Classes\Requests\\';
$files = glob($dir . '*Requests.php');

foreach ($files as $file) {
    if (!is_file($file)) continue;
    
    $content = file_get_contents($file);
    $basename = basename($file, '.php');
    
    // 1. Remove empty docblocks left over from supportedChannels removal
    $content = preg_replace('/\/\*\*\s+\* @return \\\\Enums\\\\Channel\[\]\s+\*\/\s+(\r?\n)/', '$1', $content);
    $content = preg_replace('/\/\*\*\s+\* @return Channel\[\]\s+\*\/\s+(\r?\n)/', '$1', $content);

    // 2. Simplify Vendor and ProductVariant dynamic calls
    if (in_array($basename, ['VendorRequests', 'ProductVariantRequests', 'DiscountRequests', 'PriceRuleRequests', 'ProductCategoryRequests'])) {
        $pattern = '/if \(method_exists\(self::class, \$method\)\) \{\s+return (self::\$method\([^;]+\);)\s+\}\s+throw new \\\\?Exception\("Channel .*" not supported for .* entities"\);/s';
        $content = preg_replace($pattern, 'return $1', $content);
    }

    file_put_contents($file, $content);
    echo "Refined $basename\n";
}
