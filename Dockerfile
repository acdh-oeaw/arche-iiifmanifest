FROM php:8.2-apache
MAINTAINER Mateusz Żółtak <mzoltak@oeaw.ac.at>
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions &&\
    install-php-extensions @composer sockets &&\
    mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" &&\
    apt update && apt upgrade -y && apt install -y libimage-exiftool-perl && apt clean
COPY * /var/www/html/
RUN cd /var/www/html && composer update -o --no-dev && chown -R www-data:www-data /var/www/html
EXPOSE 80
