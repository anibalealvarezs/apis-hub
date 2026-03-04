FROM php:8.3-cli

# Instalar dependencias del sistema operativo
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    unzip \
    git \
    && rm -rf /var/lib/apt/lists/*

# Instalar extensiones PHP nativas
RUN docker-php-ext-install \
    pdo_mysql \
    pdo_pgsql \
    zip

# Instalar y habilitar extensión Redis de PECL
RUN pecl install redis && docker-php-ext-enable redis

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configurar directorio de trabajo
WORKDIR /app

# Copiar archivos de requerimientos primero para optimizar capa de caché de Docker
COPY composer.json composer.lock ./

# Instalar dependencias PHP (sin paquetes de desarrollo para mayor seguridad/rapidez)
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader

# Copiar el resto de la aplicación
COPY . /app

# Ejecutar scripts de composer que faltaron
RUN composer dump-autoload --optimize

# Cloud Run escucha por defecto en el puerto 8080 (o donde le mande la variable $PORT)
ENV PORT=8080

# Al levantar el contenedor, ejecutar el servidor web de PHP embebido
# Cloud Run se encargará de levantar y matar este proceso al no usarse.
CMD php -S 0.0.0.0:${PORT} -t bin/
