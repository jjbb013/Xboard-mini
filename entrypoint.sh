#!/bin/sh

# Define the lock file path in the persistent storage
LOCK_FILE="/app/storage/.installed.lock"

# Check if the application has been installed
if [ ! -f "$LOCK_FILE" ]; then
    echo "Application not installed. Starting installation..."

    # Ensure the database file exists
    mkdir -p /app/storage/database
    touch /app/storage/database/database.sqlite

    # Generate application key if not set
    if [ -z "$APP_KEY" ]; then
        echo "Generating application key..."
        php artisan key:generate
    fi

    # Run migrations and initial setup
    echo "Running database migrations..."
    php artisan cache:table
    php artisan migrate --force

    echo "Installing application..."
    php artisan xboard:install

    echo "Clearing caches..."
    php artisan optimize:clear

    # Create the lock file to prevent re-installation
    echo "Installation complete. Creating lock file."
    touch "$LOCK_FILE"
else
    echo "Application already installed. Skipping installation."
fi

# Start Supervisor to run Octane and the queue worker
echo "Starting Supervisor..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
