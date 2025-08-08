#!/bin/sh

# Exit immediately if a command exits with a non-zero status.
set -e

# Directly start Supervisor to run Octane and the queue worker.
# This assumes the database has been manually migrated and all setup is complete.
echo "Skipping all setup steps. Starting Supervisor directly..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
