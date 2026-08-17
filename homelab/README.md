# Homelab deployment notes (branch `homelab`)

Production runs the **unmodified upstream Docker image** `f0rc3/barcodebuddy:latest`
(Watchtower keeps it updated). The changes on this branch are applied to that image
at runtime instead of building our own image:

| Change on this branch | How it reaches the running container |
| --- | --- |
| `plugins/EventReceiver.php` — auto-create Grocy products for unknown barcodes | bind-mounted over `/app/bbuddy/plugins/EventReceiver.php` (not read-only: the supervisor chowns `/app`) |
| Purchase as base mode (`incl/db.inc.php`, `incl/processing.inc.php`) | `patch-default-purchase.sh` (this folder) is bind-mounted and run via the stack's `command:` before `/app/supervisor`; the same `sed` substitutions as the source change, fail-soft |
| BBUDDY_* env override fix for null-default settings (`incl/configProcessing.inc.php`) | not applied at runtime; the value is set directly as `const EXTERNAL_GROCY_URL` in `/config/data/config.php` |

`docker-compose.yml` is the stack as deployed in Portainer (host paths under
`/opt/barcodebuddy/`). Keep `master` equal to upstream; rebase this branch on it
when syncing.
