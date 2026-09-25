# Pet Marketplace - Deployment Guide

This guide outlines the steps required to deploy the Pet Marketplace application to a production environment.

## 1. System Requirements
- PHP 8.1 or higher
- MySQL 8.0 or MariaDB 10.5+
- Apache (with mod_rewrite enabled) or Nginx
- Composer (optional, for future dependencies)

## 2. Initial Setup

### 2.1. Clone the Repository
Clone the repository to your web server's document root (e.g., `/var/www/html/pet_marketplace`).

### 2.2. Environment Configuration
1. Copy the example configuration file:
   `cp .env.example .env`
2. Open `.env` and configure your production settings:
   - `APP_ENV=production`
   - `APP_DEBUG=false`
   - `APP_URL=https://yourdomain.com`
   - Set the `DB_HOST`, `DB_USER`, `DB_PASS`, and `DB_NAME` matching your production database.

### 2.3. Database Setup
1. Create a new, empty database on your production MySQL server.
2. Import the schema to create the tables:
   `mysql -u [user] -p [database_name] < database_migrations/database_schema.sql`
3. Import the default settings and categories (optional but recommended):
   `mysql -u [user] -p [database_name] < database_migrations/initial_data.sql`
4. **Create an Admin User:** You must register an account normally through the UI and manually set `role = 'admin'` directly in the database for the first user.

### 2.4. Directory Permissions
Ensure your web server (e.g., `www-data` or `apache`) has write permissions to the following directories:
- `assets/uploads/products/`
- `assets/images/payments/`
- `assets/images/profiles/` (if implemented)

Run the following commands (Linux):
```bash
chmod -R 775 assets/uploads/products/
chmod -R 775 assets/images/payments/
chown -R www-data:www-data assets/uploads/ assets/images/payments/
```

## 3. Security Considerations
- **Debug Mode:** Always ensure `APP_DEBUG=false` in production. This prevents sensitive stack traces and database credentials from being displayed to users.
- **Upload Directories:** `.htaccess` files are already included in the upload directories to prevent PHP execution. Ensure your web server allows `.htaccess` overrides (`AllowOverride All` in Apache). If using Nginx, configure location blocks to block PHP execution in these folders.
- **HTTPS:** Ensure the site is served over HTTPS to protect session cookies and CSRF tokens.

## 4. Backup & Recovery

### Database Backup
Set up a daily cron job to back up the database:
```bash
mysqldump -u [user] -p[password] [database_name] | gzip > /path/to/backups/db_backup_$(date +%F).sql.gz
```

### File Backup
Back up the `assets/uploads/` and `assets/images/payments/` directories regularly:
```bash
tar -czvf /path/to/backups/uploads_backup_$(date +%F).tar.gz /var/www/html/pet_marketplace/assets/
```

### Recovery Procedure
1. Extract file backups over the web root.
2. Drop all tables in the database and import the database backup:
   `gunzip < /path/to/backups/db_backup_YYYY-MM-DD.sql.gz | mysql -u [user] -p [database_name]`

