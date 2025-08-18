#!/usr/bin/env bash
# Watchdog: checks frame age; restarts producer service if stale
# Env: WATCHDOG_MAX_AGE (seconds), WATCHDOG_SERVICE name

FRAME="${FRAME_OUTPUT:-/var/www/webcam/frame.jpg}"
MAX_AGE="${WATCHDOG_MAX_AGE:-15}"    # seconds
SERVICE="${WATCHDOG_SERVICE:-webcam-frame.service}"
NOW=$(date +%s)
if [[ -f "$FRAME" ]]; then
  AGE=$(( NOW - $(stat -c %Y "$FRAME") ))
else
  AGE=$MAX_AGE
fi
if (( AGE > MAX_AGE )); then
  systemctl restart "$SERVICE" && echo "[watchdog] restarted $SERVICE (age=$AGE > $MAX_AGE)" || echo "[watchdog] failed to restart $SERVICE" >&2
else
  echo "[watchdog] ok (age=$AGE <= $MAX_AGE)"
fi
