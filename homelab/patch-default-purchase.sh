#!/bin/sh
# Barcode Buddy: make PURCHASE the base mode instead of CONSUME.
# Runs at every container start (stack `command:` = this script, then exec /app/supervisor),
# so it re-applies itself after image updates. Every substitution is fail-soft: if upstream
# changed the code and a pattern no longer matches, the file stays untouched and Barcode Buddy
# simply keeps its stock behaviour (base = Consume). Result is printed to the container log.
#
# Behaviour after the patch (mirror image of upstream):
#   - idle/base mode is Purchase; Purchase never times out;
#   - Consume stays active until REVERT_TIME minutes pass or another mode is scanned;
#   - Consume (spoiled), Consume all and Open revert to Purchase after one scan when REVERT_SINGLE is on;
#   - the time-out (REVERT_TIME) of any mode returns to Purchase.
# Source-level version of the same change: github.com/roosmsg/barcodebuddy (master).
INCL=/app/bbuddy/incl
DB=$INCL/db.inc.php
PR=$INCL/processing.inc.php
ST=/app/bbuddy/menu/settings.php

if grep -q 'if ($state == STATE_CONSUME || $this->revertBackToConsume($since))' "$DB"; then
    sed -i \
        -e 's/if ($state == STATE_CONSUME || $this->revertBackToConsume($since))/if ($state == STATE_PURCHASE || $this->revertBackToConsume($since))/' \
        -e '/if ($state == STATE_PURCHASE || $this->revertBackToConsume($since))/{n;s/return STATE_CONSUME;/return STATE_PURCHASE;/}' \
        "$DB"
    echo "[default-purchase] db.inc.php patched: base state = Purchase"
elif grep -q 'if ($state == STATE_PURCHASE || $this->revertBackToConsume($since))' "$DB"; then
    echo "[default-purchase] db.inc.php already patched"
else
    echo "[default-purchase] WARNING: pattern not found in db.inc.php - upstream changed, base state left as-is"
fi

if grep -q 'Reverting back to Consume' "$PR"; then
    sed -i \
        -e 's/$db->saveLog("Reverting back to Consume", true);/$db->saveLog("Reverting back to Purchase", true);/' \
        -e '/Reverting back to Purchase/{n;s/setTransactionState(STATE_CONSUME)/setTransactionState(STATE_PURCHASE)/}' \
        "$PR"
    echo "[default-purchase] processing.inc.php patched: single-scan modes revert to Purchase ($(grep -c 'setTransactionState(STATE_PURCHASE)' "$PR") sites)"
elif grep -q 'Reverting back to Purchase' "$PR"; then
    echo "[default-purchase] processing.inc.php already patched"
else
    echo "[default-purchase] WARNING: pattern not found in processing.inc.php - upstream changed, revert target left as-is"
fi

# Settings page: the labels of the two revert options must say Purchase, not Consume.
# (In sed replacements '&' means "the match", hence the escaped \& in &quot;.)
if grep -q 'Revert state to &quot;Consume&quot; after time passed in minutes' "$ST"; then
    sed -i \
        -e 's/Revert state to &quot;Consume&quot; after time passed in minutes/Revert state to \&quot;Purchase\&quot; after time passed in minutes/' \
        -e 's/Revert after single item scan in &quot;Open&quot; or &quot;Spoiled&quot; mode/Revert to \&quot;Purchase\&quot; after single item scan in \&quot;Open\&quot;, \&quot;Spoiled\&quot; or \&quot;Consume all\&quot; mode/' \
        "$ST"
    echo "[default-purchase] settings.php patched: revert labels say Purchase"
elif grep -q 'Revert state to &quot;Purchase&quot;' "$ST"; then
    echo "[default-purchase] settings.php already patched"
else
    echo "[default-purchase] WARNING: pattern not found in settings.php - labels left as-is"
fi

php -l "$DB" >/dev/null 2>&1 || echo "[default-purchase] ERROR: db.inc.php has a syntax error after patching"
php -l "$PR" >/dev/null 2>&1 || echo "[default-purchase] ERROR: processing.inc.php has a syntax error after patching"
php -l "$ST" >/dev/null 2>&1 || echo "[default-purchase] ERROR: settings.php has a syntax error after patching"
exit 0
