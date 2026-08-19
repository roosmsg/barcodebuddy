# Barcode Buddy image built from this fork, on top of the upstream Docker image.
# The upstream image (f0rc3/barcodebuddy) already contains the runtime: nginx, php8,
# redis, evtest, the Go supervisor and all Docker glue. This Dockerfile only replaces
# the application tree /app/bbuddy with the source of this repository and re-applies
# the small edits the upstream Dockerfile makes to that tree.
#
# Build (context = repository root):
#   docker build -t barcodebuddy:homelab -f docker/Dockerfile https://github.com/roosmsg/barcodebuddy.git#master
# Upstream base version and this repository must match (upstream master == the release
# the f0rc3 image was built from); when upstream releases a new version, merge
# upstream/master into master first, then rebuild.
FROM f0rc3/barcodebuddy:latest

COPY . /app/bbuddy/

RUN sed -i 's/[[:blank:]]*const[[:blank:]]*IS_DOCKER[[:blank:]]*=[[:blank:]]*false;/const IS_DOCKER = true;/g' /app/bbuddy/config-dist.php && \
    sed -i 's/SCRIPT_LOCATION=.*/SCRIPT_LOCATION="\/app\/bbuddy\/index.php"/g' /app/bbuddy/example/grabInput.sh && \
    sed -i 's/WWW_USER=.*/WWW_USER="barcodebuddy"/g' /app/bbuddy/example/grabInput.sh && \
    sed -i 's/IS_DOCKER=.*/IS_DOCKER=true/g' /app/bbuddy/example/grabInput.sh && \
    sed -i 's/const DEFAULT_USE_REDIS =.*/const DEFAULT_USE_REDIS = "1";/g' /app/bbuddy/incl/db.inc.php && \
    rm -rf /app/bbuddy/docker /app/bbuddy/.dockerignore && \
    chown -R barcodebuddy:barcodebuddy /app/bbuddy && \
    php -l /app/bbuddy/incl/db.inc.php && php -l /app/bbuddy/incl/processing.inc.php && \
    php -l /app/bbuddy/incl/configProcessing.inc.php && php -l /app/bbuddy/menu/settings.php && \
    php -l /app/bbuddy/plugins/EventReceiver.php

LABEL org.opencontainers.image.source="https://github.com/roosmsg/barcodebuddy" \
      org.opencontainers.image.description="Barcode Buddy (roosmsg fork: purchase as base mode, auto-create Grocy products, env-override fix)"
