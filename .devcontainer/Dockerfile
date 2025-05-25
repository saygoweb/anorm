FROM php:7.4-apache

# Install git, tmux, unzip, zip, and libzip-dev
RUN apt-get update && apt-get install -y git tmux unzip zip libzip-dev

# Install necessary PHP extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql zip

# Install Xdebug (compatible version for PHP 7.4)
RUN pecl install xdebug-3.1.6 \
    && docker-php-ext-enable xdebug

# Copy the development php.ini as the default configuration
RUN cp /usr/local/etc/php/php.ini-development /usr/local/etc/php/php.ini

# Enable code coverage settings in php.ini
RUN echo "zend_extension=$(find /usr/local/lib/php/extensions/ -name xdebug.so)" >> /usr/local/etc/php/php.ini \
    && echo "xdebug.mode=coverage" >> /usr/local/etc/php/php.ini \
    && echo "xdebug.start_with_request=yes" >> /usr/local/etc/php/php.ini \
    && echo "xdebug.discover_client_host=true" >> /usr/local/etc/php/php.ini

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Install Composer globally
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Set the working directory
WORKDIR /workspace

# Expose port 80
EXPOSE 80

# Add vscode user with passwordless sudo
RUN apt-get update && apt-get install -y sudo \
    && useradd -m -s /bin/bash vscode \
    && echo "vscode ALL=(ALL) NOPASSWD:ALL" >> /etc/sudoers.d/nopasswd \
    && chmod 0440 /etc/sudoers.d/nopasswd

USER vscode