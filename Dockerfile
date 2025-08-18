# Minimal Dockerfile for rtsp2jpeg_for_fritz_fon
FROM debian:stable-slim

ARG DEBIAN_FRONTEND=noninteractive
RUN apt-get update && apt-get install -y --no-install-recommends \
    ffmpeg php-cli ca-certificates curl bash coreutils procps \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY webcam.php frame_producer.sh status.php metrics.php .env.example ./
COPY deploy ./deploy
COPY watchdog.sh ./

# Non-root user
RUN useradd -r -s /bin/bash appuser && chown -R appuser:appuser /app
USER appuser

ENV RTSP_USER=streamer \
    RTSP_PASS=CHANGE_ME \
    RTSP_HOST=172.25.10.218 \
    RTSP_PATH=/Preview_05_sub \
    PRODUCER_FPS=1 \
    PRODUCER_JPEG_QUALITY=7 \
    PRODUCER_SCALE_WIDTH=800

# Simple entrypoint: run producer in background then serve snapshots via PHP built-in server
EXPOSE 8080
ENTRYPOINT ["bash","-c","./frame_producer.sh & php -S 0.0.0.0:8080 webcam.php"]
