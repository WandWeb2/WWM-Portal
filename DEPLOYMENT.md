# WandWeb Portal 2.0 - Production Deployment Guide

## Table of Contents
1. [Prerequisites](#prerequisites)
2. [Server Requirements](#server-requirements)
3. [Installation Steps](#installation-steps)
4. [Configuration](#configuration)
5. [Database Setup](#database-setup)
6. [Third-Party Integrations](#third-party-integrations)
7. [Security Hardening](#security-hardening)
8. [Testing](#testing)
9. [Monitoring & Maintenance](#monitoring--maintenance)
10. [Troubleshooting](#troubleshooting)

---

## Prerequisites

### Required Accounts
- [ ] Domain registered and DNS configured
- [ ] Hosting with PHP 8.0+ support (Plesk recommended)
- [ ] MySQL/MariaDB database (or SQLite for testing)
- [ ] Google Cloud Platform account (for Drive & Gemini AI)
- [ ] Stripe account (for billing)
- [ ] SSL certificate (Let's Encrypt recommended)

### Required Knowledge
- Basic Linux/server administration
- PHP configuration
- Database management
- DNS configuration

---

## Server Requirements

### Minimum Specifications
- **PHP**: 8.0 or higher
- **Memory**: 512 MB RAM (2 GB recommended)
- **Storage**: 10 GB available space
- **Database**: MySQL 5.7+ / MariaDB 10.2+ / SQLite 3.8+

### Required PHP Extensions
```bash
php -m | grep -E 'pdo|json|curl|mbstring|openssl'
```

Required extensions:
- `pdo_mysql` or `pdo_sqlite`
- `json`
- `curl`
- `mbstring`
- `openssl`
- `fileinfo`

### PHP Configuration
Edit `php.ini` and set:
```ini
upload_max_filesize = 100M
post_max_size = 100M
memory_limit = 256M
max_execution_time = 300
display_errors = Off
log_errors = On
error_log = /var/log/php/error.log
```

---

## Installation Steps

### 1. Clone Repository
```bash
cd /var/www
git clone https://github.com/WandWeb2/WWM-Portal.git portal
cd portal
```

### 2. Set Permissions
```bash
# Create necessary directories
mkdir -p data logs uploads private

# Set ownership (replace 'www-data' with your web server user)
chown -R www-data:www-data data logs uploads

# Set permissions
chmod 755 api api/modules
chmod 644 api/*.php api/modules/*.php
chmod 775 data logs uploads
chmod 700 private
```

### 3. Configure Web Server

#### Apache (.htaccess)
```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /
    
    # Force HTTPS
    RewriteCond %{HTTPS} off
    RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
    
    # API routing
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^api/(.*)$ api/portal_api.php [L,QSA]
</IfModule>

# Disable directory browsing
Options -Indexes

# Protect sensitive files
<FilesMatch "^(\.env|secrets\.php)$">
    Order allow,deny
    Deny from all
</FilesMatch>
```

#### Nginx
```nginx
server {
    listen 443 ssl http2;
    server_name yourdomain.com;
    
    root /var/www/portal;
    index index.html;
    
    # SSL configuration
    ssl_certificate /etc/letsencrypt/live/yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/yourdomain.com/privkey.pem;
    
    # API routing
    location /api/ {
        try_files $uri /api/portal_api.php$is_args$args;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
    
    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    
    # Protect sensitive files
    location ~ /\.(env|git) {
        deny all;
    }
    
    location /private/ {
        deny all;
    }
}

# Redirect HTTP to HTTPS
server {
    listen 80;
    server_name yourdomain.com;
    return 301 https://$server_name$request_uri;
}
```

---

## Configuration

### 1. Create Secrets File
```bash
cd /var/www/portal
cp private/secrets.php.example private/secrets.php
chmod 600 private/secrets.php
```

### 2. Edit Configuration
Edit `private/secrets.php` and fill in your actual credentials:

```php
return [
    'DB_HOST' => 'localhost',
    'DB_NAME' => 'wandweb_portal',
    'DB_USER' => 'portal_user',
    'DB_PASS' => 'your_secure_password',
    'JWT_SECRET' => 'generate_with_openssl_rand_base64_32',
    // ... add all other secrets
];
```

### 3. Generate JWT Secret
```bash
openssl rand -base64 32
```
Copy the output to `JWT_SECRET` in secrets.php.

---

## Database Setup

### Option 1: MySQL/MariaDB (Production)

#### Create Database
```sql
CREATE DATABASE wandweb_portal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'portal_user'@'localhost' IDENTIFIED BY 'your_secure_password';
GRANT ALL PRIVILEGES ON wandweb_portal.* TO 'portal_user'@'localhost';
FLUSH PRIVILEGES;
```

#### Update Configuration
Edit `private/secrets.php`:
```php
'DB_HOST' => 'localhost',
'DB_NAME' => 'wandweb_portal',
'DB_USER' => 'portal_user',
'DB_PASS' => 'your_secure_password',
```

### Option 2: SQLite (Development/Testing)

Edit `private/secrets.php`:
```php
'DB_DSN' => 'sqlite:' . __DIR__ . '/../data/portal.sqlite',
```

### Initialize Database Schema
The database schema is automatically created on first API call. To manually initialize:

```bash
# Test database connection
php -r "require 'private/secrets.php'; \$pdo = new PDO('mysql:host=localhost;dbname=wandweb_portal', 'portal_user', 'your_password'); echo 'Database connected successfully!';"
```

---

## Third-Party Integrations

### 1. Google Drive Integration

#### Setup Steps (see GOOGLE_DRIVE_SETUP.md for details)
1. Create project at https://console.cloud.google.com
2. Enable Google Drive API
3. Create OAuth 2.0 credentials
4. Generate refresh token
5. Add to secrets.php:
```php
'GOOGLE_CLIENT_ID' => 'your-id.apps.googleusercontent.com',
'GOOGLE_CLIENT_SECRET' => 'your-secret',
'GOOGLE_REFRESH_TOKEN' => '1//0xxx...',
```

### 2. Stripe Billing

#### Setup Steps
1. Sign up at https://stripe.com
2. Get API keys from https://dashboard.stripe.com/apikeys
3. Add to secrets.php:
```php
'STRIPE_SECRET_KEY' => 'sk_live_xxx', // or sk_test_xxx for testing
'STRIPE_WEBHOOK_SECRET' => 'whsec_xxx',
```

#### Configure Webhook
1. Go to https://dashboard.stripe.com/webhooks
2. Add endpoint: `https://yourdomain.com/api/portal_api.php?action=stripe_webhook`
3. Select events: `invoice.*`, `customer.subscription.*`, `payment_intent.*`
4. Copy webhook secret to secrets.php

### 3. Gemini AI

#### Setup Steps
1. Get API key from https://makersuite.google.com/app/apikey
2. Add to secrets.php:
```php
'GEMINI_API_KEY' => 'your_api_key',
```

### 4. Email Service (Plesk Mail)

#### Setup Steps (Plesk)
1. Go to "Websites & Domains" → "Mail Settings"
2. Check "Activate mail service on this domain"
3. Create email account: `noreply@yourdomain.com`
4. Configure SMTP in secrets.php:
```php
'SMTP_FROM' => 'noreply@yourdomain.com',
'SMTP_FROM_NAME' => 'WandWeb Portal',
```

---

## Security Hardening

### 1. SSL Certificate (Let's Encrypt)

#### Using Plesk
1. Go to "Websites & Domains" → "SSL/TLS Certificates"
2. Click "Install" for Let's Encrypt
3. Enable "Permanent SEO-safe 301 redirect from HTTP to HTTPS"

#### Using Certbot (Manual)
```bash
sudo apt-get install certbot python3-certbot-apache
sudo certbot --apache -d yourdomain.com
```

### 2. File Permissions
```bash
# Restrict access to sensitive files
chmod 600 private/secrets.php
chmod 700 private/
chmod 600 .env 2>/dev/null || true

# Ensure logs are writable but not executable
chmod 664 logs/*.log
```

### 3. Firewall Rules
```bash
# Allow HTTP/HTTPS only
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
```

### 4. Security Headers
Already configured in portal_api.php:
- CORS headers
- JSON content type enforcement
- Error message sanitization

### 5. Database Security
```sql
-- Remove anonymous users
DELETE FROM mysql.user WHERE User='';

-- Remove test database
DROP DATABASE IF EXISTS test;

-- Update root password
ALTER USER 'root'@'localhost' IDENTIFIED BY 'new_secure_password';
FLUSH PRIVILEGES;
```

---

## Testing

### 1. PHP Syntax Check
```bash
cd /var/www/portal
php -l api/portal_api.php
for file in api/modules/*.php; do php -l "$file"; done
```

### 2. Test Database Connection
```bash
curl -X POST https://yourdomain.com/api/portal_api.php \
  -H "Content-Type: application/json" \
  -d '{"action":"debug_test"}'
```

Expected response:
```json
{"status":"success","message":"Debug test successful"}
```

### 3. Test Login
```bash
# First create a test user in the database
curl -X POST https://yourdomain.com/api/portal_api.php \
  -H "Content-Type: application/json" \
  -d '{"action":"login","email":"admin@yourdomain.com","password":"your_password"}'
```

Expected response:
```json
{"status":"success","message":"Login","data":{"token":"xxx","user":{...}}}
```

### 4. Test File Upload
```bash
# Get token from login response
curl -X POST https://yourdomain.com/api/portal_api.php \
  -H "Content-Type: multipart/form-data" \
  -F "action=upload_file" \
  -F "token=YOUR_TOKEN" \
  -F "file=@test.pdf"
```

### 5. Test Integrations

#### Test Google Drive
```bash
# Upload file and verify it appears in Drive console
# Check database: should see external_url starting with "drive:"
```

#### Test Stripe
```bash
# Create test checkout session
curl -X POST https://yourdomain.com/api/portal_api.php \
  -H "Content-Type: application/json" \
  -d '{"action":"create_checkout","token":"YOUR_TOKEN","items":[{"id":"price_xxx","quantity":1}]}'
```

#### Test Gemini AI
```bash
# Test AI project creation
curl -X POST https://yourdomain.com/api/portal_api.php \
  -H "Content-Type: application/json" \
  -d '{"action":"ai_create_project","token":"YOUR_TOKEN","description":"Build a simple website"}'
```

---

## Monitoring & Maintenance

### 1. Error Logging

#### View Logs
```bash
# Application logs
tail -f logs/portal.log

# PHP error logs
tail -f /var/log/php/error.log

# Web server logs
tail -f /var/log/apache2/error.log  # Apache
tail -f /var/log/nginx/error.log    # Nginx
```

#### Log Rotation
Create `/etc/logrotate.d/portal`:
```
/var/www/portal/logs/*.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    create 0664 www-data www-data
}
```

### 2. Database Backups

#### Daily Backup Script
Create `/usr/local/bin/backup-portal-db.sh`:
```bash
#!/bin/bash
BACKUP_DIR="/var/backups/portal"
DATE=$(date +%Y%m%d_%H%M%S)
mkdir -p $BACKUP_DIR

mysqldump -u portal_user -p'password' wandweb_portal | gzip > $BACKUP_DIR/portal_$DATE.sql.gz

# Keep only last 30 days
find $BACKUP_DIR -name "portal_*.sql.gz" -mtime +30 -delete
```

#### Cron Job
```bash
# Run daily at 2 AM
0 2 * * * /usr/local/bin/backup-portal-db.sh
```

### 3. Monitoring

#### Uptime Monitoring
- Use UptimeRobot, Pingdom, or similar service
- Monitor: `https://yourdomain.com/api/portal_api.php?action=health`

#### Performance Monitoring
```bash
# Monitor API response times
curl -w "@curl-format.txt" -o /dev/null -s https://yourdomain.com/api/portal_api.php
```

Create `curl-format.txt`:
```
     time_namelookup:  %{time_namelookup}s\n
        time_connect:  %{time_connect}s\n
     time_appconnect:  %{time_appconnect}s\n
    time_pretransfer:  %{time_pretransfer}s\n
       time_redirect:  %{time_redirect}s\n
  time_starttransfer:  %{time_starttransfer}s\n
                     ----------\n
          time_total:  %{time_total}s\n
```

### 4. Updates

#### Regular Maintenance
```bash
# Update repository
cd /var/www/portal
git pull origin main

# Check for PHP syntax errors
php -l api/portal_api.php

# Restart PHP-FPM
sudo systemctl restart php8.1-fpm
```

---

## Troubleshooting

### Common Issues

#### 1. "Database connection failed"
**Solution:**
```bash
# Check database credentials
php -r "require 'private/secrets.php'; var_dump(\$secrets);"

# Test connection
mysql -u portal_user -p wandweb_portal

# Check if database exists
mysql -u root -p -e "SHOW DATABASES;"
```

#### 2. "Google Drive upload failed"
**Solution:**
- Verify OAuth credentials in secrets.php
- Check refresh token is valid
- Verify Drive API is enabled in Cloud Console
- Check logs: `tail -f logs/portal.log`

#### 3. "File upload too large"
**Solution:**
```bash
# Edit php.ini
sudo nano /etc/php/8.1/apache2/php.ini

# Increase limits
upload_max_filesize = 100M
post_max_size = 100M

# Restart web server
sudo systemctl restart apache2
```

#### 4. "JWT validation failed"
**Solution:**
- Verify JWT_SECRET in secrets.php
- Check token expiry (default 24 hours)
- Regenerate token by logging in again

#### 5. "CORS error in browser"
**Solution:**
- Verify CORS headers in portal_api.php
- Check APP_URL in secrets.php matches domain
- Clear browser cache

### Debug Mode

Enable debug mode temporarily:
```php
// In portal_api.php, change:
ini_set('display_errors', 1);  // Enable error display
error_reporting(E_ALL);

// In secrets.php:
'APP_DEBUG' => 'true',
```

**Important:** Disable debug mode in production!

### Support Resources
- **Documentation:** See all .md files in repository
- **Google Drive Setup:** GOOGLE_DRIVE_SETUP.md
- **Emergency Access:** EMERGENCY_ACCESS_QUICK_REF.md
- **Log Debugging:** LOG_DEBUGGING_GUIDE.md

---

## Production Checklist

Before going live, verify:

- [ ] SSL certificate installed and HTTPS working
- [ ] All secrets configured in private/secrets.php
- [ ] Database connection tested
- [ ] File permissions set correctly
- [ ] Backups configured and tested
- [ ] Error logging working
- [ ] All third-party integrations tested
- [ ] Debug mode disabled (display_errors = Off)
- [ ] CORS configured for production domain
- [ ] Mail service activated (for notifications)
- [ ] Firewall rules configured
- [ ] Monitoring set up
- [ ] Load testing completed
- [ ] Security headers verified
- [ ] Test user created and login works
- [ ] File upload tested (both Drive and local fallback)
- [ ] Stripe webhooks configured
- [ ] Emergency access documented

---

## Rollback Plan

If issues occur after deployment:

1. **Quick Rollback:**
```bash
cd /var/www/portal
git reset --hard PREVIOUS_COMMIT_SHA
sudo systemctl restart php8.1-fpm
```

2. **Database Rollback:**
```bash
# Restore from backup
gunzip < /var/backups/portal/portal_YYYYMMDD_HHMMSS.sql.gz | mysql -u portal_user -p wandweb_portal
```

3. **Notify Users:**
- Update status page
- Send notification emails
- Post on social media if applicable

---

## Next Steps After Deployment

1. Monitor error logs for 24-48 hours
2. Test all critical user journeys
3. Verify all integrations working in production
4. Set up performance monitoring
5. Document any production-specific configurations
6. Train support staff on troubleshooting

---

**Deployment completed?** Mark this task as done:
```bash
echo "$(date): Portal 2.0 deployed successfully" >> /var/www/portal/deployment.log
```

For questions or issues, refer to the documentation or contact the development team.
