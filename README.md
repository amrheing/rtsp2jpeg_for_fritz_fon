# rtsp2jpeg_for_fritz_fon

![CI](https://github.com/amrheing/rtsp2jpeg_for_fritz_fon/actions/workflows/ci.yml/badge.svg)

Low-latency RTSP snapshot + MJPEG stream service optimized for AVM FritzFon handsets. Provides:

- Fast snapshot endpoint with cached frame producer (1 FPS configurable)
- Vertical slicing or fixed cropping via `slice=parts:from-to` or `crop=WxH`
- Fritz mode: auto scale, quality adjust, short cache TTL
- Clean URLs: `/snapshot.jpg`, `/image.jpg`, `/fritz.jpg`, `/stream.mjpeg`
- Systemd frame producer script using `ffmpeg`
- Environment-driven secrets (no plaintext credentials in repo)
- Optional `.env` support + Apache `SetEnv` override
- Status endpoint (`/status.json`) for basic health metrics

## Directory Structure
```
webcam.php            # Main application
frame_producer.sh     # Continuous single-frame updater
status.php            # JSON status endpoint
.env.example          # Example environment file (copy to .env)
.gitignore            # Excludes secrets/logs/frame
/deploy/apache/*.example   # Apache vhost template
/deploy/systemd/*.example  # systemd service template
```

## Requirements
- ffmpeg (with h264 decoder; hardware accel optional)
- Apache (mod_rewrite, mod_headers enabled) or another web server that can route requests
- PHP (CLI functions enabled for shell_exec)

## Quick Setup
1. Copy files to `/var/www/webcam` (or your chosen docroot).
2. Create environment file:
   ```bash
   cp .env.example .env
   sed -i 's/CHANGE_ME/yourSecretPassword/' .env
   sed -i 's/CHANGE_ME_STATUS/yourStatusToken/' .env
   ```
3. (Apache) Enable vhost using `deploy/apache/webcam.conf.example` as a template.
4. Enable required Apache modules:
   ```bash
   sudo a2enmod rewrite headers
   sudo systemctl reload apache2
   ```
5. (Optional) systemd frame producer:
   ```bash
   sudo cp deploy/systemd/webcam-frame.service.example /etc/systemd/system/webcam-frame.service
   sudo systemctl daemon-reload
   sudo systemctl enable --now webcam-frame.service
   ```
6. Visit `http://host/snapshot.jpg` or `http://host/fritz.jpg`.

## Environment Variables
| Variable | Purpose | Default |
|----------|---------|---------|
| RTSP_USER | Camera username | streamer |
| RTSP_PASS | Camera password | CHANGE_ME |
| RTSP_HOST | Camera host/IP | 172.25.10.218 |
| RTSP_PATH | RTSP path | /Preview_05_sub |
| STATUS_TOKEN | Auth token for `/status.json` | (none) |

Additional tuning inside `webcam.php` config array: slicing defaults, Fritz settings, cache ages.

## Slicing / Cropping
- `?slice=5:3-4` keeps vertical slices 3 and 4 of 5 total parts.
- `?crop=800x300` center crops to width 800, height 300.
- Defaults can be set in config for `/snapshot.jpg` and `/fritz.jpg`.

## Status Endpoint
`/status.json?token=STATUS_TOKEN` returns JSON with frame age/size and selected env (no password exposure).
If `STATUS_TOKEN` is unset, endpoint is public.

## Security Notes
- Do NOT commit `.env`.
- Always replace `CHANGE_ME` placeholders.
- Consider restricting Apache vhost by network or auth if exposed externally.

## Development
Fast frame producer writes a scaled JPEG to `frame.jpg`. PHP applies slicing/scaling transforms when serving cache.

## FritzFon Integration
See `docs/FritzFon.md` for handset configuration, performance tuning, and troubleshooting tips specific to AVM FritzFon devices.

## Troubleshooting
| Issue | Cause | Remedy |
|-------|-------|--------|
| Slow first image | Producer cold start | Wait for first frame (~1s) then subsequent requests are instant. |
| Stale image | Producer stopped | `systemctl restart webcam-frame.service`; check logs `journalctl -u webcam-frame.service`. |
| High CPU usage | Too large scale or high FPS | Reduce output width or adjust producer sleep/FPS. |
| Blank on FritzFon | Cached error / wrong URL | Re-open Live Image; verify `/fritz.jpg` loads in browser. |
| 403/404 errors | Rewrite or vhost misconfig | Confirm Apache mods enabled and vhost active. |
| Secrets in repo warning (CI) | Placeholder left | Remove secret or replace with env var; update `.env`. |

## License
MIT. See `LICENSE`.

## Contributing
See `CONTRIBUTING.md` for guidelines. PRs welcome (create issues for feature requests or bug reports).

## Changelog
See `CHANGELOG.md` for release history.

---
Generated scaffold; adjust values before production use.
