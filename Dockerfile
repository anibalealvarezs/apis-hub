FROM php:8.3-cli

# Instalar dependencias del sistema operativo y Node.js
RUN apt-get update && apt-get install -y \
    unzip \
    git \
    cron \
    curl \
    gnupg \
    && curl -fsSL https://download.docker.com/linux/debian/gpg | gpg --dearmor -o /usr/share/keyrings/docker-archive-keyring.gpg \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/docker-archive-keyring.gpg] https://download.docker.com/linux/debian $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | tee /etc/apt/sources.list.d/docker.list > /dev/null \
    && apt-get update && apt-get install -y docker-ce-cli \
    && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && rm -rf /var/lib/apt/lists/*


# Obtener el instalador de extensiones profesional
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# Instalar extensiones PHP (maneja automáticamente dependencias complejas como IMAP)
RUN install-php-extensions pdo_mysql pdo_pgsql zip imap redis xdebug

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configurar directorio de trabajo
WORKDIR /app

# Copiar archivos de requerimientos
COPY composer.json composer.lock ./

# Limpiar repositorios de tipo 'path' y sus requerimientos asociados que no existen en el contexto de Docker
# Esto evita errores de 'could not be found' durante la construcción de la imagen.
RUN php -r '$j=json_decode(file_get_contents("composer.json"), true); \
    $toRemove = []; \
    if(isset($j["repositories"])) { \
        foreach($j["repositories"] as $r) { \
            if($r["type"] === "path") { \
                /* Extraer el nombre del paquete si es posible o simplemente identificar el path */ \
                /* Para simplificar, buscaremos los que coinciden con nuestros hub-drivers y apis actuales */ \
                $toRemove[] = "anibalealvarezs/api-client-skeleton"; \
                $toRemove[] = "anibalealvarezs/facebook-graph-api"; \
                $toRemove[] = "anibalealvarezs/meta-hub-driver"; \
                $toRemove[] = "anibalealvarezs/google-api"; \
                $toRemove[] = "anibalealvarezs/google-hub-driver"; \
                $toRemove[] = "anibalealvarezs/shopify-api"; \
                $toRemove[] = "anibalealvarezs/klaviyo-api"; \
                $toRemove[] = "anibalealvarezs/amazon-api"; \
                $toRemove[] = "anibalealvarezs/netsuite-api"; \
                $toRemove[] = "anibalealvarezs/shipstation-api"; \
                $toRemove[] = "anibalealvarezs/triple-whale-api"; \
                $toRemove[] = "anibalealvarezs/mailchimp-api"; \
                /* Also variants with -anibal used in path repositories */ \
                $toRemove[] = "anibalealvarezs/shopify-api-anibal"; \
                $toRemove[] = "anibalealvarezs/klaviyo-api-anibal"; \
                $toRemove[] = "anibalealvarezs/amazon-api-anibal"; \
                $toRemove[] = "anibalealvarezs/netsuite-api-anibal"; \
                $toRemove[] = "anibalealvarezs/shipstation-api-anibal"; \
                $toRemove[] = "anibalealvarezs/mailchimp-api-anibal"; \
            } \
        } \
        $j["repositories"] = array_filter($j["repositories"], function($r){ return $r["type"] !== "path"; }); \
        $j["repositories"] = array_values($j["repositories"]); \
    } \
    foreach($toRemove as $pkg) { unset($j["require"][$pkg]); } \
    file_put_contents("composer.json", json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));' \
    && rm -f composer.lock

# Instalar dependencias PHP
RUN composer install --no-scripts --no-interaction --prefer-dist --optimize-autoloader --verbose

# Copiar el resto de la aplicación
COPY . /app

# Ejecutar scripts de composer que faltaron
RUN composer dump-autoload --optimize

# Instalar dependencias del servidor MCP
RUN cd mcp-server && npm install --omit=dev


# Configurar el entrypoint
RUN chmod +x /app/entrypoint.sh

# Cloud Run escucha por defecto en el puerto 8080 (o donde le mande la variable $PORT)
ENV PORT=8080

# Al levantar el contenedor, ejecutar el script de entrada usando bash
# para evitar problemas de permisos de ejecución en volúmenes montados.
ENTRYPOINT ["bash", "/app/entrypoint.sh"]

# El servidor PHP se ejecuta en el entrypoint.sh final.
# El servidor PHP se ejecuta en el entrypoint.sh final, pero por consistencia el CMD default será este:
CMD ["php", "-S", "0.0.0.0:8080", "-t", ".", "bin/index.php"]
