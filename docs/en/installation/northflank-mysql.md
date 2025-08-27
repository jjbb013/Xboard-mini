# Configuring MySQL for Northflank Deployment

This guide explains how to configure Xboard to work with Northflank's MySQL addon.

## Northflank MySQL Environment Variables

When using Northflank's MySQL addon, the following environment variables are provided:

```
# Connection details for external connections (e.g., from your local machine)
EXTERNAL_CONNECT_COMMAND: mysql --host=primary.mysql--mj57f6pmr24b.addon.code.run --user=2a93b6663c8690ea --password=2d3baede4e48a5bf8eb09a2632c6bc 7fd31670971e --port 28905
EXTERNAL_MYSQL_CONNECTOR_URI: server=primary.mysql--mj57f6pmr24b.addon.code.run:28905;uid=2a93b6663c8690ea;password=2d3baede4e48a5bf8eb09a2632c6bc;database=7fd31670971e
EXTERNAL_MYSQL_JDBC_URI: jdbc:mysql://2a93b6663c8690ea:2d3baede4e48a5bf8eb09a2632c6bc@primary.mysql--mj57f6pmr24b.addon.code.run:28905/7fd31670971e

# Connection details for internal connections (e.g., from your Northflank services)
CONNECT_COMMAND: mysql --host=primary.mysql--mj57f6pmr24b.addon.code.run --user=2a93b6663c8690ea --password=2d3baede4e48a5bf8eb09a2632c6bc 7fd31670971e
HOST: primary.mysql--mj57f6pmr24b.addon.code.run
PORT: 3306
DATABASE: 7fd31670971e
USERNAME: 2a93b6663c8690ea
PASSWORD: 2d3baede4e48a5bf8eb09a2632c6bc
MYSQL_JDBC_URI: jdbc:mysql://2a93b6663c8690ea:2d3baede4e48a5bf8eb09a2632c6bc@primary.mysql--mj57f6pmr24b.addon.code.run:3306/7fd31670971e
MYSQL_CONNECTOR_URI: server=primary.mysql--mj57f6pmr24b.addon.code.run:3306;uid=2a93b6663c8690ea;password=2d3baede4e48a5bf8eb09a2632c6bc;database=7fd31670971e

# SSL Configuration
TLS_ENABLED: true
```

## Configuring Xboard for Northflank MySQL

To configure Xboard to work with Northflank's MySQL addon, you need to manually set the following environment variables in your Xboard service:

1. In your Northflank service configuration, you can use either the standard Laravel environment variables or the Northflank-specific variables that are automatically provided:

   **Option A - Using standard Laravel variables:**
   ```
   APP_NAME=XBoard
   APP_ENV=production
   APP_KEY= # Generate this using `php artisan key:generate --show`
   APP_DEBUG=false
   APP_URL=http://localhost
   
   LOG_CHANNEL=stack
   
   DB_CONNECTION=mysql
   DB_HOST=primary.mysql--m2cwcvt4zs8p.addon.code.run
   DB_PORT=3306
   DB_DATABASE=4253b11c37b7
   DB_USERNAME=926fc090d2189763
   DB_PASSWORD=e5d90badf402f0d81cc77b7790766f
   DB_SSL_VERIFY_SERVER_CERT=true
   
   BROADCAST_DRIVER=log
   CACHE_DRIVER=database
   QUEUE_CONNECTION=database
   SESSION_DRIVER=database
   
   MAIL_DRIVER=smtp
   MAIL_HOST=smtp.mailtrap.io
   MAIL_PORT=2525
   MAIL_USERNAME=null
   MAIL_PASSWORD=null
   MAIL_ENCRYPTION=null
   MAIL_FROM_ADDRESS=null
   MAIL_FROM_NAME=null
   ```

   **Option B - Using Northflank automatic variables (recommended):**
   If you're using Northflank's MySQL addon, the platform automatically provides the following variables:
   - `NF_MYSQL_HOST`
   - `NF_MYSQL_DATABASE`
   - `NF_MYSQL_USERNAME`
   - `NF_MYSQL_PASSWORD`
   
   In this case, you only need to add these additional variables:
   ```
   APP_NAME=XBoard
   APP_ENV=production
   APP_KEY= # Generate this using `php artisan key:generate --show`
   APP_DEBUG=false
   APP_URL=http://localhost
   
   LOG_CHANNEL=stack
   
   DB_CONNECTION=mysql
   DB_PORT=3306
   DB_SSL_VERIFY_SERVER_CERT=true
   DB_SSL_CIPHER=DHE-RSA-AES256-SHA:AES128-SHA
   
   BROADCAST_DRIVER=log
   CACHE_DRIVER=database
   QUEUE_CONNECTION=database
   SESSION_DRIVER=database
   
   MAIL_DRIVER=smtp
   MAIL_HOST=smtp.mailtrap.io
   MAIL_PORT=2525
   MAIL_USERNAME=null
   MAIL_PASSWORD=null
   MAIL_ENCRYPTION=null
   MAIL_FROM_ADDRESS=null
   MAIL_FROM_NAME=null
   
   # Admin Configuration (Optional)
   ADMIN_EMAIL=admin@example.com
   ADMIN_PASSWORD=your_secure_password
   ADMIN_SECURE_PATH= # Leave empty to use auto-generated path, or set custom path
   ```

2. **Important**: You must manually set the `APP_KEY` as an environment variable in Northflank. You can either:
   - Generate it using the command `php artisan key:generate --show` and copy the value, or
   - Create your own 32-character random string prefixed with `base64:` (e.g., `base64:your-32-char-random-string-here`)
   
   The application will not start without this key.

3. If you need to use a specific SSL certificate, you can also set:
   ```
   MYSQL_ATTR_SSL_CA=/path/to/ca-cert.pem
   ```

## SSL Certificate Verification

Northflank's MySQL addon has TLS enabled. Xboard is configured to verify the server certificate by default. This is the recommended setting for production environments.

If you encounter SSL verification issues, you can disable verification by setting:
```
DB_SSL_VERIFY_SERVER_CERT=false
```

However, this is not recommended for production use as it reduces the security of your database connection.

## Testing the Connection

To test the connection from your Northflank service, you can use the following command in the terminal:
```bash
mysql --host=primary.mysql--mj57f6pmr24b.addon.code.run --user=2a93b6663c8690ea --password=2d3baede4e48a5bf8eb09a2632c6bc 7fd31670971e
```

## Troubleshooting

If you encounter connection issues:

1. Verify that all environment variables are correctly set
2. Check that your Northflank service has network access to the MySQL addon
3. Ensure that the MySQL addon is running and healthy
4. Confirm that the SSL settings are correctly configured