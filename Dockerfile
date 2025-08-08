FROM phpswoole/swoole:php8.2-alpine

# Install necessary PHP extensions and system packages
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions pcntl bcmath zip pdo_mysql pdo_sqlite && \
    apk --no-cache add git mysql-client supervisor

# Set up work directory
WORKDIR /www

# Copy application code
COPY . .

# Install Composer dependencies
RUN composer install --no-dev --optimize-autoloader

# Copy Supervisor configuration
COPY .docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Copy and set permissions for the entrypoint script
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Set permissions for storage and cache
RUN chmod -R 777 /www/storage && \
    chmod -R 777 /www/bootstrap/cache

# Expose port for Octane
EXPOSE 7002

# Set the entrypoint
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
