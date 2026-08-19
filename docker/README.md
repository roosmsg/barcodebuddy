# Docker deployment notes

Production uses the self-contained image defined by `docker/Dockerfile`. The
image is built directly from this fork; it does not inherit from the upstream
`f0rc3/barcodebuddy` image. The runtime consists of Alpine 3.24.1, PHP 8.3 FPM,
Nginx, Redis, Supervisor and the USB scanner utilities required by
`example/grabInput.sh`.

Everything on `master` is copied into the image: the auto-create plugin, the
configurable purchase base mode, the `BBUDDY_*` environment override fix, the
repaired PLUS and Albert Heijn providers, the Zooplus product-feed provider and
the overview mode controls. During the build, the Docker-specific source changes
are verified, the required PHP extensions are checked and every PHP file is
linted.

Build on the Docker VM, using the repository as remote build context:

    docker build --pull -t barcodebuddy:docker -f docker/Dockerfile https://github.com/roosmsg/barcodebuddy.git#master

Then redeploy the `barcodebuddy` Portainer stack from
`docker/docker-compose.yml`. The `/config` volume remains compatible with the
existing deployment. Port 80 is exposed internally; TLS remains terminated by
the NPM reverse proxy.

The entrypoint supports the existing `PUID`, `PGID`, `TZ`,
`ATTACH_BARCODESCANNER`, `IGNORE_SSL_CA`, `IGNORE_SSL_HOST` and `BBUDDY_*`
environment variables. Supervisor runs Redis, PHP-FPM, Nginx, the websocket
server, the two-minute Barcode Buddy cron loop and, when enabled, the scanner
input process. The image includes an HTTP health check on `/login.php`.

The image is local and therefore stays outside Watchtower. Updating means:
merge `upstream/master` into `master`, rebuild the image, redeploy the stack and
check the health status, container log, scanner input, lookup providers and
auto-create plugin. Refresh `/srv/homelab-images/barcodebuddy-docker.tar` with
the VM's `save-images.sh` after a successful deployment.
