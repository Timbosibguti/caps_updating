FROM php:8.2-fpm

# Устанавливаем зависимости
RUN apt-get update && apt-get install -y \
    libzip-dev unzip libonig-dev libcurl4-openssl-dev pkg-config libpq-dev \
    supervisor cron \
    && docker-php-ext-install zip pdo_mysql mbstring curl \
    && docker-php-ext-install pgsql pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

# Устанавливаем Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Устанавливаем рабочую директорию
WORKDIR /var/www/html

# Копируем проект
COPY . /var/www/html/

# Устанавливаем зависимости composer внутри контейнера
RUN composer install --no-dev --optimize-autoloader

# Создаем директорию cache и устанавливаем права
RUN mkdir -p /var/www/html/cache && \
    chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html/cache

# Проверяем установку расширений PostgreSQL
RUN php -m | grep -E "(pgsql|pdo_pgsql)" || echo "PostgreSQL extensions not found"

# Копируем конфиг supervisor
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Копируем entrypoint скрипт
COPY docker-entrypoint.sh /docker-entrypoint.sh
RUN chmod +x /docker-entrypoint.sh

# Запуск entrypoint
ENTRYPOINT ["/docker-entrypoint.sh"]
