FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive
WORKDIR /app

# Install PHP 8 + SQLite/cURL/mbstring + Xvfb + xauth + dependencies for Google Chrome
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates \
    curl \
    gnupg \
    php-cli \
    php-sqlite3 \
    php-curl \
    php-mbstring \
    php-xml \
    xvfb \
    xauth \
    procps \
    && mkdir -p /etc/apt/keyrings \
    && curl -fsSL https://dl.google.com/linux/linux_signing_key.pub | gpg --dearmor -o /etc/apt/keyrings/google-chrome.gpg \
    && echo "deb [arch=amd64 signed-by=/etc/apt/keyrings/google-chrome.gpg] http://dl.google.com/linux/chrome/deb/ stable main" > /etc/apt/sources.list.d/google-chrome.list \
    && apt-get update && apt-get install -y --no-install-recommends google-chrome-stable \
    && rm -rf /var/lib/apt/lists/*

COPY . .
RUN mkdir -p /app/static/uploads /app/fb_profile

ENV PORT=10000
ENV HOST=0.0.0.0
ENV DISPLAY=:99
ENV CHROME_BIN=/usr/bin/google-chrome

EXPOSE 10000

CMD ["sh", "-c", "rm -f /tmp/.X99-lock && (Xvfb :99 -screen 0 1280x880x24 -ac +extension GLX +render -noreset >/dev/null 2>&1 &) && exec php -S 0.0.0.0:${PORT:-10000} -t /app /app/index.php"]
