# MSP Align container image (1.44). The same Debian 13 packages as a dedicated install (install.sh):
# Apache with mod_php, PHP 8.4 from Debian, the MariaDB client and age for backups. One container runs the
# web app, the scheduled jobs (instead of systemd timers) and the backup agent. See docs/DOCKER.md.
#
# Security: no secrets are used or kept at build time (they come from the environment and volumes at run time, see
# docker/entrypoint.sh). The app's files are root's and read-only to www-data; Apache and the app's jobs run as
# www-data, while the entrypoint and the backup agent run as root, as on a dedicated server. Only port 80 (plain
# HTTP for the reverse proxy in front) is exposed.
ARG BASE=debian:trixie-slim
FROM ${BASE}

ARG DEBIAN_FRONTEND=noninteractive
RUN apt-get update -qq \
 && apt-get install -y -qq --no-install-recommends \
      apache2 libapache2-mod-php php-cli php-mysql php-curl php-mbstring php-xml php-intl php-gd \
      mariadb-client age ca-certificates curl tzdata tini util-linux gzip tar \
 && PHPV=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;') \
 && { php -r 'exit(function_exists("opcache_get_status") ? 0 : 1);' || php -m | grep -qi opcache || apt-get install -y -qq --no-install-recommends "php$PHPV-opcache"; } \
 && rm -rf /var/lib/apt/lists/* \
 && php -r 'exit(function_exists("sodium_crypto_secretbox") ? 0 : 1);'

# PHP and Apache settings (kept in step with install.sh), and the site on port 80 (HTTPS comes from a reverse proxy)
COPY docker/php.ini /tmp/99-msp-align.ini
COPY docker/php-performance.ini /tmp/99-msp-align-performance.ini
COPY docker/apache.conf /etc/apache2/sites-available/msp-align.conf
RUN PHPV=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;') \
 && cp /tmp/99-msp-align.ini /tmp/99-msp-align-performance.ini "/etc/php/$PHPV/apache2/conf.d/" \
 && printf 'memory_limit = 512M\nexpose_php = Off\n' >"/etc/php/$PHPV/cli/conf.d/99-msp-align.ini" \
 && rm /tmp/99-msp-align*.ini \
 && a2enmod -q headers rewrite deflate reqtimeout >/dev/null \
 && a2dissite -q 000-default >/dev/null && a2ensite -q msp-align >/dev/null \
 && sed -i 's/^Listen 443/# Listen 443/' /etc/apache2/ports.conf \
 && ln -sf /dev/stdout /var/log/apache2/msp-align-access.log && ln -sf /dev/stderr /var/log/apache2/msp-align-error.log

# .dockerignore keeps .git, .env and the tests out of the image
COPY --chown=root:root . /opt/msp-align
# `align` runs the command line as www-data (bin/align refuses root)
RUN chmod 755 /opt/msp-align/docker/*.sh \
 && ln -s /opt/msp-align/docker/entrypoint.sh /usr/local/bin/msp-align-entrypoint \
 && printf '#!/bin/sh\nexec runuser -u www-data -- php /opt/msp-align/bin/align "$@"\n' >/usr/local/bin/align && chmod 755 /usr/local/bin/align

ENV ALIGN_DOCKER=1 \
    ALIGN_SYSTEMCTL=none \
    ALIGN_RUNAS=www-data \
    ALIGN_PRIVKEY_FILE=/etc/msp-align/backup-key.txt
EXPOSE 80
VOLUME ["/etc/msp-align", "/var/lib/msp-align", "/var/lib/msp-align-agent"]
# The sign-in page answers only when Apache and PHP work (asked from 127.0.0.1, so it stays out of the access log)
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 CMD curl -fsS -o /dev/null http://127.0.0.1/login || exit 1

LABEL org.opencontainers.image.title="MSP Align" \
      org.opencontainers.image.description="Self-hosted vCIO tool for managed service providers" \
      org.opencontainers.image.source="https://github.com/MSP-ALIGN/MSP-ALIGN" \
      org.opencontainers.image.url="https://mspalign.org" \
      org.opencontainers.image.licenses="AGPL-3.0-or-later"

ENTRYPOINT ["/usr/bin/tini", "--", "/opt/msp-align/docker/entrypoint.sh"]
CMD ["web"]
