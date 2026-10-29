# UnDash - Docker image. The same PHP code as the plugin, in "api" mode: the server is read and managed through the
# official Unraid GraphQL API with an API key. No Docker socket, no privileged mode, no host mounts.

# ---- stage 1: check the code and run the test suite; the image is only built when they pass ----
FROM php:8.3-cli-alpine AS check
ARG VERSION=docker-dev
WORKDIR /build
COPY src/ src/
COPY tests/ tests/
RUN set -e; \
    for f in $(find src tests -name '*.php'); do php -l "$f" > /dev/null; done; \
    php tests/run.php; php tests/api_mode_test.php; php tests/router_test.php; \
    sed -i "s/@@VERSION@@/${VERSION}/" src/include/lib.php

# ---- stage 2: the runtime image ----
FROM php:8.3-cli-alpine
ARG VERSION=docker-dev
LABEL org.opencontainers.image.title="UnDash" \
      org.opencontainers.image.description="Server dashboard for Unraid using the official Unraid API" \
      org.opencontainers.image.source="https://github.com/Stiphr/UnDash" \
      org.opencontainers.image.version="${VERSION}" \
      org.opencontainers.image.licenses="Apache-2.0"
# tini forwards signals (graceful stop) and reaps the web server's worker processes
RUN apk add --no-cache tini \
 && adduser -D -H -u 99 -G users -s /sbin/nologin undash \
 && mkdir -p /config /app /tmp/undash \
 && chown 99:100 /config /tmp/undash
COPY --from=check /build/src/ /app/
COPY assets/icon.png /app/images/undash.png
COPY docker/entrypoint.sh docker/firstrun.php /usr/local/bin/
RUN chmod 755 /usr/local/bin/entrypoint.sh && find /app -type d -exec chmod 755 {} + && find /app -type f -exec chmod 644 {} +

ENV UNDASH_MODE=api \
    UNDASH_CONFIG=/config \
    UNDASH_PORT=9099 \
    PHP_CLI_SERVER_WORKERS=4
VOLUME /config
EXPOSE 9099
USER 99:100
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 CMD wget -q -O /dev/null "http://127.0.0.1:${UNDASH_PORT}/health" || exit 1
STOPSIGNAL SIGTERM
ENTRYPOINT ["/sbin/tini", "-g", "--", "/usr/local/bin/entrypoint.sh"]
