# Image for running the end-to-end suites (tests/e2e/run.sh) on a machine without PHP 8.4, e.g. an Ubuntu 24.04
# host with PHP 8.3 (2.38.3). The packages are the ones the CI job installs (.github/workflows/tests.yml); built and
# run by tests/docker/e2e.sh, which mounts the checkout, so the image holds no app code and no secrets.
#
# BASE: debian:trixie (as CI and production) or another release with PHP 8.4 packages (ubuntu:25.10).
# HTTPS_PROXY / proxy-ca.crt: only for networks that allow nothing but an HTTPS proxy that intercepts TLS; apt then
# uses https:// sources through it, trusting that CA (the base images have no CA store yet, so the CA is needed with
# the proxy). e2e.sh always passes a proxy-ca.crt, empty when there is none. HTTPS_PROXY is Docker's predefined build
# argument (not declared here, so it isn't kept in the image history) and the apt proxy setting is removed afterwards;
# the CA certificate (not a secret) stays in the image's trust store.
# BROWSER=1 downloads Playwright's Chromium into the image; 0 when the host's browsers are mounted instead.
ARG BASE=debian:trixie
FROM ${BASE}
ARG BROWSER=1
ARG DEBIAN_FRONTEND=noninteractive
COPY proxy-ca.crt /usr/local/share/ca-certificates/
RUN set -e; \
    CA=/usr/local/share/ca-certificates/proxy-ca.crt; [ -s "$CA" ] || rm -f "$CA"; \
    if [ -n "$HTTPS_PROXY" ]; then \
      sed -i 's#http://#https://#g' /etc/apt/sources.list.d/*.sources; \
      printf 'Acquire::https::Proxy "%s";\n' "$HTTPS_PROXY" > /etc/apt/apt.conf.d/90proxy; \
      if [ -s "$CA" ]; then printf 'Acquire::https::CaInfo "%s";\n' "$CA" >> /etc/apt/apt.conf.d/90proxy; fi; \
    fi; \
    apt-get update -qq; \
    apt-get install -y -qq php-cli php-mysql php-curl php-mbstring php-xml php-intl php-gd php-zip \
      mariadb-server mariadb-client tzdata git openssh-client rsync ca-certificates curl openssl age procps \
      python3 python3-pip python3-markdown nodejs >/dev/null; \
    update-ca-certificates >/dev/null 2>&1 || true; \
    for pair in mysql:mariadb mysqldump:mariadb-dump mysqladmin:mariadb-admin; do \
      command -v "${pair%%:*}" >/dev/null || ln -s "$(command -v "${pair#*:}")" "/usr/local/bin/${pair%%:*}"; \
    done; \
    if [ -s "$CA" ]; then export PIP_CERT="$CA" NODE_EXTRA_CA_CERTS="$CA"; fi; \
    pip3 install --break-system-packages -q requests pymysql playwright==1.63.0 openapi-spec-validator; \
    python3 -m playwright install-deps chromium >/dev/null; \
    if [ "$BROWSER" = 1 ]; then python3 -m playwright install chromium >/dev/null; fi; \
    rm -f /etc/apt/apt.conf.d/90proxy; rm -rf /var/lib/apt/lists/*; \
    git config --global --add safe.directory '*'; \
    php -v | head -1
