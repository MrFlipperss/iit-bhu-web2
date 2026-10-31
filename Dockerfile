# One image for Cloud Run (and local docker compose): Apache + PHP 8.3 + Python 3 + OR-Tools.
FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends python3 python3-venv \
 && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-install pdo_mysql \
 && python3 -m venv /opt/venv \
 && /opt/venv/bin/pip install --no-cache-dir ortools==9.15.6755 \
 && a2enmod headers

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/cit.ini
RUN sed -i 's/^Listen 80$/Listen ${PORT}/' /etc/apache2/ports.conf

WORKDIR /var/www/app
COPY . /var/www/app
RUN rm -f .env && mkdir -p exports tmp && chown -R www-data:www-data exports tmp

ENV PORT=8080 \
    PYTHON_BIN=/opt/venv/bin/python
EXPOSE 8080
CMD ["/var/www/app/docker/entrypoint.sh"]
