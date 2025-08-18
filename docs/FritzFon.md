# FritzFon Integration Guide

This project is optimized for use with AVM FritzFon handsets (e.g. C6, C5) connected to a FRITZ!Box router. The phone's live view feature expects a simple JPEG endpoint refreshed periodically.

## Recommended Endpoint
Use `/fritz.jpg` which applies Fritz-specific scaling and quality settings defined in `webcam.php`.

## Adding the Camera to FritzFon
1. Open the FRITZ!Box web interface.
2. Navigate to: Home Network > Smart Home (or appropriate menu for images) – for some firmware versions: Telephony > Telephone Devices > (Select FritzFon) > Live Image.
3. Enter the URL (adjust host):
   ```
   http://your-pi-local-ip/fritz.jpg
   ```
4. Set refresh interval to 1s or 2s depending on network and CPU load.
5. Save and test on the handset: Menu > Live Image.

## Cropping and Slicing
If you want only a portion of the frame:
- Add query parameter: `http://host/fritz.jpg?slice=5:2-3` (keeps middle vertical band)
- Or center crop: `http://host/fritz.jpg?crop=800x300`

You can bake defaults into `webcam.php` config so the FritzFon URL stays simple.

## Performance Tips
- Ensure the frame producer script runs continuously (`systemctl status webcam-frame.service`).
- Keep resolution modest (e.g. scale to width 480-640) for fast handset refresh.
- Avoid hardware decoding instability; software decode is often stable enough on Pi 5.
- Use wired Ethernet if possible for consistent latency.

## Troubleshooting
| Symptom | Possible Cause | Fix |
|--------|----------------|-----|
| Black or blank image | FritzFon cached error | Tap Back and re-enter Live Image; verify direct browser snapshot works. |
| Slow refresh (>2s) | High CPU load / large frame size | Reduce scaling width, increase JPEG quality number (lower quality). |
| Stale image (not updating) | Producer stopped | `journalctl -u webcam-frame.service -e` and restart service. |
| 403 or 404 errors | Wrong path or rewrite missing | Confirm Apache vhost and mod_rewrite enabled. |
| Credentials prompt | Basic auth or network issue | Ensure endpoint not behind extra auth unless handset supports it. |

## Security Considerations
- If FritzFon is on trusted LAN, exposing plain HTTP may be acceptable; otherwise add network restrictions.
- Use a separate low-privilege camera account (read-only) via `RTSP_USER` / `RTSP_PASS`.

## Advanced
- Multiple FritzFons: They all fetch the same cache frame; negligible extra CPU.
- Alternate crops per handset: Create separate endpoints via simple wrapper PHP adjusting slice parameters (future enhancement).

## Verification Checklist
- Browser loads `/fritz.jpg` quickly (<300ms served) after first warm frame.
- FritzFon displays updated image at selected interval.
- CPU usage stable (check with `top` or `htop`).

---
Feel free to open issues for model-specific quirks or improvements.
