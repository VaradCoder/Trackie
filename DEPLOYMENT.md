# Trackie.in - Deployment Guide

This guide will help you deploy the Trackie.in application to your web server.

## Prerequisites

- Web server with PHP 7.4+ support
- MySQL 5.7+ or MariaDB 10.2+
- Apache with mod_rewrite enabled
- SSL certificate (recommended for production)

## Step 1: Server Setup

### Apache Configuration
Ensure the following Apache modules are enabled:
```bash
sudo a2enmod rewrite
sudo a2enmod headers
sudo a2enmod expires
sudo a2enmod deflate
sudo systemctl restart apache2
```

### PHP Extensions
Install required PHP extensions:
```bash
sudo apt-get install php-mysql php-pdo php-mbstring php-json php-curl
```

## Step 2: Database Setup

1. Create a MySQL database:
```sql
CREATE DATABASE trackie CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'trackie_user'@'localhost' IDENTIFIED BY 'your_secure_password';
GRANT ALL PRIVILEGES ON trackie.* TO 'trackie_user'@'localhost';
FLUSH PRIVILEGES;
```

2. Import the database schema:
```bash
mysql -u trackie_user -p trackie < trackie_in.sql
```

## Step 3: Application Deployment

1. Upload files to your web server:
```bash
# Using SCP
scp -r ./* user@your-server:/var/www/html/trackie/

# Or using SFTP
sftp user@your-server
cd /var/www/html/
put -r ./* trackie/
```

2. Set proper file permissions:
```bash
sudo chown -R www-data:www-data /var/www/html/trackie/
sudo chmod -R 755 /var/www/html/trackie/
sudo chmod -R 777 /var/www/html/trackie/uploads/  # If using file uploads
```

## Step 4: Configuration

1. Update database configuration in `config/database.php`:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'trackie');
define('DB_USER', 'trackie_user');
define('DB_PASS', 'your_secure_password');
```

2. Configure your web server virtual host:
```apache
<VirtualHost *:80>
    ServerName trackie.yourdomain.com
    DocumentRoot /var/www/html/trackie
    
    <Directory /var/www/html/trackie>
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/trackie_error.log
    CustomLog ${APACHE_LOG_DIR}/trackie_access.log combined
</VirtualHost>
```

3. Enable the virtual host:
```bash
sudo a2ensite trackie.conf
sudo systemctl reload apache2
```

## Step 5: SSL Configuration (Production)

1. Install SSL certificate (Let's Encrypt recommended):
```bash
sudo apt-get install certbot python3-certbot-apache
sudo certbot --apache -d trackie.yourdomain.com
```

2. Update `.htaccess` to force HTTPS:
```apache
# Uncomment these lines in .htaccess
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

## Step 6: Security Hardening

1. Create a `.env` file for sensitive data (optional):
```bash
# Create .env file
DB_HOST=localhost
DB_NAME=trackie
DB_USER=trackie_user
DB_PASS=your_secure_password
```

2. Set up firewall rules:
```bash
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw allow 22/tcp
sudo ufw enable
```

3. Configure fail2ban:
```bash
sudo apt-get install fail2ban
sudo systemctl enable fail2ban
sudo systemctl start fail2ban
```

## Step 7: Performance Optimization

1. Enable OPcache:
```bash
sudo apt-get install php-opcache
```

2. Configure OPcache in `php.ini`:
```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=4000
opcache.revalidate_freq=2
opcache.fast_shutdown=1
```

3. Set up Redis for session storage (optional):
```bash
sudo apt-get install redis-server php-redis
```

## Step 8: Monitoring and Logging

1. Set up log rotation:
```bash
sudo nano /etc/logrotate.d/trackie
```

Add:
```
/var/www/html/trackie/logs/*.log {
    daily
    missingok
    rotate 52
    compress
    delaycompress
    notifempty
    create 644 www-data www-data
}
```

2. Monitor application logs:
```bash
tail -f /var/www/html/trackie/logs/app.log
tail -f /var/log/apache2/trackie_error.log
```

## Step 9: Backup Strategy

1. Set up automated database backups:
```bash
#!/bin/bash
# backup.sh
DATE=$(date +%Y%m%d_%H%M%S)
mysqldump -u trackie_user -p trackie > /backups/trackie_$DATE.sql
gzip /backups/trackie_$DATE.sql
find /backups -name "trackie_*.sql.gz" -mtime +7 -delete
```

2. Add to crontab:
```bash
0 2 * * * /path/to/backup.sh
```

## Step 10: Testing

1. Test the application:
- Visit your domain
- Test user registration and login
- Test all major features
- Check error logs

2. Performance testing:
```bash
# Install Apache Bench
sudo apt-get install apache2-utils

# Test performance
ab -n 1000 -c 10 https://trackie.yourdomain.com/
```

## Troubleshooting

### Common Issues

1. **500 Internal Server Error**
   - Check Apache error logs
   - Verify file permissions
   - Check PHP error logs

2. **Database Connection Error**
   - Verify database credentials
   - Check MySQL service status
   - Test database connection manually

3. **404 Not Found**
   - Verify mod_rewrite is enabled
   - Check .htaccess file
   - Verify file paths

4. **Permission Denied**
   - Check file ownership
   - Verify directory permissions
   - Check SELinux settings (if applicable)

### Log Locations

- Apache error log: `/var/log/apache2/error.log`
- PHP error log: `/var/log/php/error.log`
- Application log: `/var/www/html/trackie/logs/app.log`

## Maintenance

### Regular Tasks

1. **Weekly**
   - Check error logs
   - Monitor disk space
   - Review security updates

2. **Monthly**
   - Update PHP and Apache
   - Review backup integrity
   - Performance analysis

3. **Quarterly**
   - Security audit
   - Code updates
   - Database optimization

## Support

For deployment issues:
1. Check the troubleshooting section
2. Review server logs
3. Contact system administrator
4. Create an issue in the repository

## Security Checklist

- [ ] SSL certificate installed
- [ ] Database credentials secured
- [ ] File permissions set correctly
- [ ] Firewall configured
- [ ] Regular backups scheduled
- [ ] Error reporting disabled in production
- [ ] Security headers configured
- [ ] Input validation implemented
- [ ] SQL injection protection enabled
- [ ] XSS protection enabled

---

**Note**: This guide assumes a Linux server with Apache. Adjust commands for your specific server environment. 