# Homelab deployment notes

Production runs an image **built from this fork**: `homelab/Dockerfile` takes the
upstream Docker image `f0rc3/barcodebuddy` (runtime: nginx, php8, redis, evtest,
supervisor) and replaces the application tree `/app/bbuddy` with this repository,
re-applying the small edits the upstream Dockerfile makes. Everything on `master`
is therefore live: the auto-create plugin, Purchase as base mode, the settings
labels, the `BBUDDY_*` env-override fix and the repaired Plus Supermarkt lookup
provider (upstream still points at the decommissioned host
`pls-sprmrkt-mw.prd.vdc1.plus.nl`; the fork uses the PLUS app endpoint
`apiframna.app.plus.nl/api/app/v1/products_by_barcode/<ean>`).

Build on the Docker host (no local checkout needed):

    docker build -t barcodebuddy:homelab -f homelab/Dockerfile https://github.com/roosmsg/barcodebuddy.git#master

then redeploy the stack (`homelab/docker-compose.yml`, host paths under `/opt/barcodebuddy/`).
The image is local, so Watchtower does not update it; updating means: merge
`upstream/master` into `master` (upstream master must match the release the f0rc3
image was built from), rebuild, redeploy, check the log and the plugin.

`homelab/patch-default-purchase.sh` is kept only as the runtime alternative
(apply the base-mode change to the unmodified upstream image via `command:`);
it is not used when running this image.
