<?php

/**
 * Script para automatizar la creación y subida masiva de drivers a GitHub.
 * 
 * Uso: php push-drivers.php <github_token> <github_username>
 */

if ($argc < 3) {
    die("Uso: php push-drivers.php <github_token> <github_username>\n");
}

$token = $argv[1];
$user = $argv[2];

$packages = [
    'amazon-hub-driver',
    'bigcommerce-hub-driver',
    'google-hub-driver',
    'klaviyo-hub-driver',
    'linkedin-hub-driver',
    'meta-hub-driver',
    'netsuite-hub-driver',
    'pinterest-hub-driver',
    'shopify-hub-driver',
    'tiktok-hub-driver',
    'triple-whale-hub-driver',
    'x-hub-driver'
];

$basePath = 'D:\\laragon\\www\\';

foreach ($packages as $pkg) {
    $path = $basePath . $pkg;
    echo "--- Procesando $pkg ---\n";

    if (!is_dir($path)) {
        echo "Error: El directorio $path no existe.\n";
        continue;
    }

    // 1. Asegurar Git inicializado y con commit
    if (!is_dir($path . DIRECTORY_SEPARATOR . '.git')) {
        echo "Inicializando Git...\n";
        shell_exec("git -C $path init");
    }
    
    // Forzar add y commit si no hay commits
    shell_exec("git -C $path add .");
    $status = shell_exec("git -C $path status --porcelain");
    if ($status || !shell_exec("git -C $path rev-parse HEAD 2>/dev/null")) {
        echo "Creando commit inicial (sin firma)...\n";
        shell_exec("git -C $path -c commit.gpgsign=false commit -m \"Initial commit: Modular driver structure\"");
    }

    // 2. Crear repo en GitHub vía API
    echo "Creando repositorio en GitHub...\n";
    $ch = curl_init("https://api.github.com/user/repos");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: token $token",
        "User-Agent: PHP-Script",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        "name" => $pkg,
        "description" => "Modular driver for $pkg",
        "private" => false
    ]));
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 201 || $httpCode === 422) { // 422 si ya existe
        echo ($httpCode === 201) ? "Repositorio creado con éxito.\n" : "El repositorio ya existe en GitHub.\n";
        
        // 3. Agregar remote y push
        echo "Subiendo código a GitHub...\n";
        shell_exec("git -C $path remote add origin https://github.com/$user/$pkg.git");
        shell_exec("git -C $path branch -M main");
        // Usar token en la URL para autenticación automática en el push
        $remoteUrl = "https://$user:$token@github.com/$user/$pkg.git";
        shell_exec("git -C $path remote set-url origin $remoteUrl");
        shell_exec("git -C $path push -u origin main");
        echo "Completado.\n";
    } else {
        echo "Error creand repositorio: HTTP $httpCode\n";
        echo $response . "\n";
    }
}

echo "\n¡Proceso finalizado!\n";
