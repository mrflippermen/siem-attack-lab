FROM php:8.1-apache

# Driver MySQL para PHP
RUN docker-php-ext-install mysqli && docker-php-ext-enable mysqli

# Activamos rewrite y un log combinado con el formato estandar de Apache
RUN a2enmod rewrite

# Copiamos la aplicacion vulnerable
COPY src/ /var/www/html/

# Aseguramos que Apache escriba el access.log en formato 'combined'
# (incluye Referer y User-Agent -> clave para detectar escaneres)
COPY apache-vhost.conf /etc/apache2/sites-available/000-default.conf

# IMPORTANTE: la imagen base symlinkea access.log -> /dev/stdout y
# error.log -> /dev/stderr (logs van a la consola, NO a un fichero).
# Los eliminamos para que Apache escriba FICHEROS REALES en el volumen
# compartido y Filebeat pueda cosecharlos.
RUN rm -f /var/log/apache2/access.log \
          /var/log/apache2/error.log \
          /var/log/apache2/other_vhosts_access.log

EXPOSE 80
