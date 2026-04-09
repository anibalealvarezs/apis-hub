<?php

$dir = 'D:\laragon\www\apis-hub\src\Classes\Requests\\';
$files = glob($dir . '*Requests.php');

foreach ($files as $file) {
    if (!is_file($file)) continue;
    
    $content = file_get_contents($file);
    
    // Pattern to catch the common if (Channel === X) { return ... } throw Exception; block
    // We want to keep the return part but remove the if and the throw.
    
    // This is tricky because the return might be different.
    // Let's try to find if there's only one 'if' block that wraps the only return.
    
    $newContent = $content;

    // Specific fix for Meta entities (AdGroup, Ad, Campaign, Creative, Page, Post)
    $metaEntities = ['AdGroup', 'Ad', 'Campaign', 'Creative', 'Page', 'Post'];
    $basename = basename($file, '.php');
    $entityName = str_replace('Requests', '', $basename);
    
    if (in_array($entityName, $metaEntities)) {
        // Pattern for the Meta standard getList
        $pattern = '/if \(\$chanEnum === Channel::facebook_(?:marketing|organic)\) \{\s+return (\\\\Classes\\\\Services\\\\Sync\\\\Facebook\\\\FacebookEntitySync::sync[^\(]+\([^;]+\);)\s+\}\s+throw new \\\\Exception\("[^"]+"\);/s';
        $newContent = preg_replace($pattern, 'return $1', $content);
    }
    
    // For others like VendorRequests, ProductVariantRequests which also have this
    if ($newContent === $content) {
        $pattern = '/if \(\$chanEnum === Channel::(?:shopify|facebook_marketing|google_search_console)\) \{\s+return (.*?;)\s+\}\s+throw new \\\\Exception\("[^"]+"\);/s';
        $newContent = preg_replace($pattern, 'return $1', $content);
    }

    if ($newContent !== $content) {
        file_put_contents($file, $newContent);
        echo "Simplified getList in $basename\n";
    }
}
