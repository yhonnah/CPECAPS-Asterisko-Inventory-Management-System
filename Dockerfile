FROM php:8.2-apache

# Install MySQL PDO extension for database connectivity
RUN docker-php-ext-install pdo pdo_mysql

# Enable Apache rewrite module
RUN a2enmod rewrite

# Copy all application files to the Apache web directory
COPY . /var/www/html/

# Ensure write permissions for invoice uploads and backups
RUN mkdir -p /var/www/html/uploads/invoices /var/www/html/backups \
    && chown -R www-data:www-data /var/www/html/uploads /var/www/html/backups \
    && chmod -R 775 /var/www/html/uploads /var/www/html/backups

EXPOSE 80
