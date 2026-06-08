# Appended after DDEV's own web image (Debian trixie). Binaries used across the project:
# - ffmpeg: podcast audio concat/probe (Plan 5)
# - poppler-utils: PDF text + page rasterisation (Plan 2)
# - chromium: HTML -> PNG rendering via Playwright (Plan 4)
RUN apt-get update && apt-get install -y --no-install-recommends \
        ffmpeg \
        poppler-utils \
        chromium \
        fonts-liberation \
    && rm -rf /var/lib/apt/lists/*

ENV CHROMIUM_PATH=/usr/bin/chromium \
    PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1
