FROM php:8.4-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    supervisor \
    nginx \
    libzip-dev \
    procps \
    ffmpeg

# Clear cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip

# Install Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Configure OS file descriptor limits for high concurrency
RUN echo "* soft nofile 65535" >> /etc/security/limits.conf && \
    echo "* hard nofile 65535" >> /etc/security/limits.conf && \
    echo "root soft nofile 65535" >> /etc/security/limits.conf && \
    echo "root hard nofile 65535" >> /etc/security/limits.conf && \
    echo "www-data soft nofile 65535" >> /etc/security/limits.conf && \
    echo "www-data hard nofile 65535" >> /etc/security/limits.conf

# Configure custom PHP file upload limits
COPY docker/app/uploads.ini $PHP_INI_DIR/conf.d/uploads.ini
COPY docker/app/99-uploads.ini $PHP_INI_DIR/conf.d/99-uploads.ini
COPY docker/app/zz-docker.conf /usr/local/etc/php-fpm.d/zz-docker.conf

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www

# Copy existing application directory contents
COPY . /var/www

# Expose ports for php-fpm (9000) and Reverb (8080)
EXPOSE 9000 8080

# Run supervisor
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
