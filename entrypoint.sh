#!/bin/sh

# Exit immediately if a command exits with a non-zero status.
set -e

# Check if APP_KEY is set
if [ -z "$APP_KEY" ]; then
    echo "ERROR: APP_KEY environment variable is not set."
    echo "Please set the APP_KEY environment variable in your Northflank service configuration."
    exit 1
fi

# Check if database exists for SQLite, if using SQLite
if [ "$DB_CONNECTION" = "sqlite" ] && [ ! -f "/www/storage/database/database.sqlite" ]; then
    echo "Creating database directory and file..."
    mkdir -p /www/storage/database
    touch /www/storage/database/database.sqlite
fi

# Run database migrations if needed
echo "Running database migrations..."
php /www/artisan migrate --force

# Set admin secure path if provided
if [ -n "$ADMIN_SECURE_PATH" ]; then
    echo "Setting admin secure path..."
    php /www/artisan tinker --execute="\\App\\Models\\Setting::updateOrCreate(['key' => 'secure_path'], ['value' => env('ADMIN_SECURE_PATH')]);"
fi

# Create admin user if credentials are provided
if [ -n "$ADMIN_EMAIL" ] && [ -n "$ADMIN_PASSWORD" ]; then
    echo "Creating admin user..."
    php /www/artisan xboard:create-admin
fi

# Start Supervisor to run Octane and the queue worker.
echo "Starting Supervisor..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
