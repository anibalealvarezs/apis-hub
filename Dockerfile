FROM php:8.3-cli

# Instalar dependencias del sistema operativo
RUN apt-get update && apt-get install -y \
    unzip \
    git \
    cron \
    && rm -rf /var/lib/apt/lists/*

# Obtener el instalador de extensiones profesional
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# Instalar extensiones PHP (maneja automáticamente dependencias complejas como IMAP)
RUN install-php-extensions pdo_mysql pdo_pgsql zip imap redis

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configurar directorio de trabajo
WORKDIR /app

# Copiar archivos de requerimientos
COPY composer.json composer.lock ./

# Limpiar repositorios de tipo 'path' que no existen en el contexto de Docker
# Y borrar el composer.lock para forzar el uso de Satis/Packagist
RUN php -r '$j=json_decode(file_get_contents("composer.json"), true); if(isset($j["repositories"])) { $j["repositories"] = array_filter($j["repositories"], function($r){ return $r["type"] !== "path"; }); $j["repositories"] = array_values($j["repositories"]); } file_put_contents("composer.json", json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));' \
    && rm -f composer.lock

# Instalar dependencias PHP
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader --verbose

# Copiar el resto de la aplicación
COPY . /app

# Ejecutar scripts de composer que faltaron
RUN composer dump-autoload --optimize

# Configurar el entrypoint
RUN chmod +x /app/entrypoint.sh

# Cloud Run escucha por defecto en el puerto 8080 (o donde le mande la variable $PORT)
ENV PORT=8080

# Al levantar el contenedor, ejecutar el script de entrada
ENTRYPOINT ["/app/entrypoint.sh"]

# El servidor PHP se ejecuta en el entrypoint.sh final.
CMD ["php", "-S", "0.0.0.0:8080", "-t", "bin/"]
