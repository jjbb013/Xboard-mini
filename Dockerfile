FROM phpswoole/swoole:php8.2-alpine

# Install necessary PHP extensions and system packages
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions pcntl bcmath zip pdo_mysql pdo_sqlite && \
    apk --no-cache add git mysql-client supervisor

# Set up work directory and user
WORKDIR /www
RUN addgroup -S -g 1000 www && adduser -S -G www -u 1000 www

# Copy application code
COPY . .

# Copy Supervisor configuration
COPY .docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Set permissions
RUN chown -R www:www /www && \
    chmod -R 755 /www/storage && \
    chmod -R 755 /www/bootstrap/cache

# Switch to non-root user
USER www

# Expose port for Octane
EXPOSE 7002

# Start Supervisor
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
