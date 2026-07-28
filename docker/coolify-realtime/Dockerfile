# Versions
# https://github.com/soketi/soketi/releases
ARG SOKETI_VERSION=1.6-16-alpine
# https://github.com/cloudflare/cloudflared/releases
ARG CLOUDFLARED_VERSION=2025.7.0

FROM quay.io/soketi/soketi:${SOKETI_VERSION}

ARG TARGETPLATFORM
ARG CLOUDFLARED_VERSION

WORKDIR /terminal

# --- Optional extra root CAs for build environments behind TLS-intercepting
# proxies (e.g. corporate egress). Drop *.pem files into docker/certs/
# (gitignored) — an empty directory makes this a no-op.
COPY docker/certs/ /usr/local/share/ca-certificates/extra/
RUN set -eux; \
    : > /usr/local/share/ca-certificates/extra-cas.crt; \
    for pem in /usr/local/share/ca-certificates/extra/*.pem; do \
        [ -f "$pem" ] || continue; \
        cat "$pem" >> /usr/local/share/ca-certificates/extra-cas.crt; \
    done; \
    cat /usr/local/share/ca-certificates/extra-cas.crt >> /etc/ssl/certs/ca-certificates.crt
ENV NODE_EXTRA_CA_CERTS=/usr/local/share/ca-certificates/extra-cas.crt

RUN apk upgrade --no-cache && \
    apk add --no-cache openssh-client make g++ python3 curl
COPY docker/coolify-realtime/package*.json ./
RUN npm ci
RUN npm rebuild node-pty --update-binary
COPY docker/coolify-realtime/soketi-entrypoint.sh /soketi-entrypoint.sh
COPY docker/coolify-realtime/terminal-server.js /terminal/terminal-server.js
COPY docker/coolify-realtime/terminal-utils.js /terminal/terminal-utils.js

# Install Cloudflared based on architecture
RUN if [ "${TARGETPLATFORM}" = "linux/amd64" ]; then \
        curl -sSL "https://github.com/cloudflare/cloudflared/releases/download/${CLOUDFLARED_VERSION}/cloudflared-linux-amd64" -o /usr/local/bin/cloudflared; \
    elif [ "${TARGETPLATFORM}" = "linux/arm64" ]; then \
        curl -sSL "https://github.com/cloudflare/cloudflared/releases/download/${CLOUDFLARED_VERSION}/cloudflared-linux-arm64" -o /usr/local/bin/cloudflared; \
    fi && \
    chmod +x /usr/local/bin/cloudflared

ENTRYPOINT ["/bin/sh", "/soketi-entrypoint.sh"]
