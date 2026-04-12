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
RUN install-php-extensions pdo_mysql pdo_pgsql zip imap redis xdebug swoole

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configurar directorio de trabajo
WORKDIR /app

# Copiar archivos de requerimientos
COPY composer.json composer.lock ./

# Instalar dependencias PHP
RUN composer install --ignore-platform-reqs --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader --verbose

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
CMD []
