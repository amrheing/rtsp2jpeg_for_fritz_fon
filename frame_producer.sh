#!/usr/bin/env bash
# Continuous frame producer for webcam snapshot cache
# Writes latest JPEG frame to frame.jpg for fast retrieval.
# Adjust FPS, quality, filters or crop here; main PHP will still apply slice/crop if needed.

set -u
URL="rtsp://streamer:Scotti.01@172.25.10.218/Preview_05_sub"
OUT="/var/www/webcam/frame.jpg"
LOG_DIR="/var/log/webcam"
LOG="$LOG_DIR/frame_producer.log"
FPS="1"            # frames per second to extract
QUALITY="7"        # ffmpeg -q:v value (2=better,bigger; higher=smaller)
TRANSPORT="tcp"    # preferred initial transport (tcp or udp or empty for auto)

# Hardware decode disabled (permissions/device not ready). Enable later if needed.
HW_DEC=""

# Single-frame mode: grab one frame each loop; sleep interval derived from FPS
INTERVAL=$(awk -v f="$FPS" 'BEGIN { if (f>0) printf "%.3f", 1.0/f; else print 1 }')

log_init() {
  mkdir -p "$LOG_DIR" 2>/dev/null || true
  chown www-data:www-data "$LOG_DIR" 2>/dev/null || true
  local target="$1"
  if ! : > "$target" 2>/dev/null; then
    echo "[frame_producer][warn] Cannot write to $target; falling back to /tmp/frame_producer.log" >&2
    target="/tmp/frame_producer.log"
    : > "$target" 2>/dev/null || echo "[frame_producer][fatal] Cannot create /tmp/frame_producer.log" >&2
  fi
  echo "$target"
}

LOG=$(log_init "$LOG")
log() { echo "$(date '+%Y-%m-%d %H:%M:%S') $*" >> "$LOG"; }

log "[frame_producer] starting (fps=$FPS q=$QUALITY transport=$TRANSPORT user=$(id -u -n))"

# Capture a single frame each iteration via a short ffmpeg run.

FIRST=1
FAILS=0
while true; do
  TMP_OUT="${OUT}.tmp"
  if [[ $FIRST -eq 1 ]]; then
    log "[frame_producer] first capture attempt (software, verbose)"
    VERB_LEVEL="info"
  else
    VERB_LEVEL="error"
  fi
  # Build command parts
  CMD=(ffmpeg -y -hide_banner -loglevel "$VERB_LEVEL")
  if [[ -n "$TRANSPORT" ]]; then
    CMD+=( -rtsp_transport "$TRANSPORT" )
  fi
  # Scale to width 800 (maintain aspect, enforce even dimensions) then output one JPEG
  CMD+=( $HW_DEC -i "$URL" -frames:v 1 -vf "scale=800:-2" -q:v "$QUALITY" -f image2 "$TMP_OUT" )
  log "[frame_producer] running: ${CMD[*]}"
  # Execute
  "${CMD[@]}" 2>>"$LOG" || {
     log "[frame_producer] ffmpeg run failed (transport=$TRANSPORT)"; ((FAILS++));
     # Simple transport fallback after a few failures
     if (( FAILS == 3 )) && [[ "$TRANSPORT" == "tcp" ]]; then
       TRANSPORT="udp"; log "[frame_producer] switching transport to udp after failures"; FAILS=0; fi
     if (( FAILS == 3 )) && [[ "$TRANSPORT" == "udp" ]]; then
       TRANSPORT=""; log "[frame_producer] removing explicit transport after failures"; FAILS=0; fi
  }
  if [[ -s "$TMP_OUT" ]]; then
    mv -f "$TMP_OUT" "$OUT" 2>/dev/null || cp "$TMP_OUT" "$OUT" 2>/dev/null
    SIZE=$(stat -c %s "$OUT" 2>/dev/null)
    log "[frame_producer] frame updated size=$SIZE"
    FIRST=0
    FAILS=0
  fi
  sleep "$INTERVAL"
done
