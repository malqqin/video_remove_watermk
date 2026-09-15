FROM node:22-bookworm-slim AS node
FROM php:8.4-apache-bookworm

COPY --from=node /usr/local/bin/node /usr/local/bin/node
RUN apt-get update \
    && apt-get install -y --no-install-recommends libstdc++6 libatomic1 libcurl4-openssl-dev \
    && if ! php -r 'exit(extension_loaded("curl") ? 0 : 1);'; then docker-php-ext-install curl; fi \
    && rm -rf /var/lib/apt/lists/* \
    && node --version \
    && php -r 'foreach (["curl", "openssl", "json"] as $ext) { if (!extension_loaded($ext)) { fwrite(STDERR, "Missing extension: $ext\n"); exit(1); } }'

WORKDIR /var/www/html
COPY api/ api/
COPY short_videos/ short_videos/
COPY img/ img/
COPY tests/platform-regression.php /tmp/platform-tests/platform-regression.php
COPY deploy/php.ini /usr/local/etc/php/conf.d/video-remove-watermk.ini
COPY deploy/apache.conf /etc/apache2/conf-enabled/video-remove-watermk.conf
COPY deploy/healthcheck.php /usr/local/bin/video-remove-watermk-healthcheck.php

# Keep the regression test's relative imports intact during the build.
RUN mkdir -p tests \
    && cp /tmp/platform-tests/platform-regression.php tests/platform-regression.php \
    && for suite in aggregator weibo pipigx pipixia toutiao zuiyou; do php tests/platform-regression.php "$suite" || exit 1; done \
    && rm tests/platform-regression.php /tmp/platform-tests/platform-regression.php \
    && node --experimental-default-type=module --check 'api/douyin new/cloudflare workers/local-runner.mjs' \
    && apache2ctl configtest

ENV NODE_BINARY=/usr/local/bin/node
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD ["php", "/usr/local/bin/video-remove-watermk-healthcheck.php"]
