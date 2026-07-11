FROM php:8.1-apache

# Driver MySQL para PHP
RUN docker-php-ext-install mysqli && docker-php-ext-enable mysqli

# mod_rewrite + mod_headers (headers -> cabecera-trampa anti-IA del vhost)
RUN a2enmod rewrite headers

# sudo 1.9.16p1 compilado desde fuente = vulnerable a CVE-2025-32463 (junio
# 2025, "sudo chroot" LPE). gcc/make/libc6-dev quedan para compilar el
# exploit de escalada on-box. La flag root solo se lee siendo root real.
RUN apt-get update && apt-get install -y --no-install-recommends \
        gcc make libc6-dev wget ca-certificates libpam0g-dev \
    && cd /usr/src \
    && wget -q https://www.sudo.ws/dist/sudo-1.9.16p1.tar.gz \
    && tar xzf sudo-1.9.16p1.tar.gz \
    && cd sudo-1.9.16p1 && ./configure --prefix=/usr/local >/dev/null \
    && make -j"$(nproc)" >/dev/null && make install >/dev/null \
    && cd / && rm -rf /usr/src/sudo-1.9.16p1* /var/lib/apt/lists/*

# Copiamos la aplicacion vulnerable
COPY src/ /var/www/html/

# Aseguramos que Apache escriba el access.log en formato 'combined'
# (incluye Referer y User-Agent -> clave para detectar escaneres)
COPY apache-vhost.conf /etc/apache2/sites-available/000-default.conf

# ---------------------------------------------------------------------
#  CTF: dos flags dentro del contenedor.
#   USER  -> /var/www/user_flag.txt (legible por www-data; la muestra panel.php)
#   ROOT  -> /root/root.txt (root, chmod 600). www-data/LFI/webshell NO pueden
#            leerla: hay que escalar a ROOT REAL explotando el sudo vulnerable
#            (CVE-2025-32463) desde la shell de www-data.
# ---------------------------------------------------------------------
RUN echo 'FLAG{us3r_4dm1n_p4n3l_4cc3ss_2026}' > /var/www/user_flag.txt \
    && chown www-data:www-data /var/www/user_flag.txt && chmod 640 /var/www/user_flag.txt \
    && echo 'FLAG{r00t_cv3_2025_32463_sud0_pwn3d}' > /root/root.txt \
    && chown root:root /root/root.txt && chmod 600 /root/root.txt

# Directorio de subidas escribible por Apache (destino del webshell .phtml)
RUN mkdir -p /var/www/html/uploads \
    && chown -R www-data:www-data /var/www/html/uploads \
    && chmod 775 /var/www/html/uploads

# IMPORTANTE: la imagen base symlinkea access.log -> /dev/stdout y
# error.log -> /dev/stderr (logs van a la consola, NO a un fichero).
# Los eliminamos para que Apache escriba FICHEROS REALES en el volumen
# compartido y Filebeat pueda cosecharlos.
RUN rm -f /var/log/apache2/access.log \
          /var/log/apache2/error.log \
          /var/log/apache2/other_vhosts_access.log

EXPOSE 80
