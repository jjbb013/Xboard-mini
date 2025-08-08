#!/bin/sh

# Exit immediately if a command exits with a non-zero status.
set -e

# Define paths based on the WORKDIR in Dockerfile
STORAGE_PATH="/www/storage"
LOCK_FILE="$STORAGE_PATH/.installed.lock"
ENV_FILE="/www/.env"
ENV_EXAMPLE_FILE="/www/.env.example"

# Check if the application has been installed
if [ ! -f "$LOCK_FILE" ]; then
    echo "Application not installed. Starting installation..."

    # 1. Create .env file from example
    if [ -f "$ENV_EXAMPLE_FILE" ]; then
        echo "Creating .env file..."
        cp "$ENV_EXAMPLE_FILE" "$ENV_FILE"
    else
        echo "Warning: .env.example not found. Skipping .env creation."
    fi

    # 2. Generate application key
    echo "Generating application key..."
    php artisan key:generate

    # 3. Ensure the database file exists
    echo "Creating database directory..."
    mkdir -p "$STORAGE_PATH/database"
    touch "$STORAGE_PATH/database/database.sqlite"

    # 4. Force clear any cached configurations
    echo "Clearing caches before migration..."
    php artisan config:clear
    php artisan route:clear
    php artisan view:clear

    # 5. Run migrations and initial setup
    echo "Running database migrations..."
    php artisan migrate --force && echo "Migration successful."

    echo "Creating admin user..."
    php artisan xboard:create-admin

    # 6. Final cache clear
    echo "Clearing all caches post-installation..."
    php artisan optimize:clear

    # 7. Create the lock file to prevent re-installation
    echo "Installation complete. Creating lock file."
    touch "$LOCK_FILE"
else
    echo "Application already installed. Skipping installation."
fi

# Start Supervisor to run Octane and the queue worker
echo "Starting Supervisor..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
