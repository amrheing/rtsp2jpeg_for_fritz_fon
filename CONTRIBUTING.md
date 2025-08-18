# Contributing

Thanks for considering contributing!

## Ways to Help
- Bug reports (include logs, PHP errors, ffmpeg command line if relevant)
- Performance tuning suggestions (frame latency, CPU usage)
- New feature ideas (authentication, metrics, multi-camera support)
- Documentation improvements

## Development Environment
1. Clone the repo.
2. Copy `.env.example` to `.env` and fill in camera credentials.
3. Ensure `ffmpeg`, `php`, and (optionally) `apache2` are installed.
4. Run the frame producer script manually for local testing:
   ```bash
   ./frame_producer.sh
   ```
5. Access `webcam.php` through your local web server.

## Coding Guidelines
- Keep dependencies minimal; prefer POSIX shell in scripts.
- Avoid hardcoding secrets—always pull from env.
- Keep functions small and focused; prefer early returns on error.
- New PHP code should pass `php -l` (syntax check) and remain framework-free.
- Shell scripts must pass `shellcheck` with no errors (warnings acceptable if justified via comment).

## Commit Messages
Follow conventional commit style where practical:
- feat: add new feature
- fix: correct a bug
- perf: performance improvement
- docs: documentation only
- chore: maintenance tasks

## Testing
Currently lightweight. Suggested manual checks before PR:
- `/snapshot.jpg` returns a fresh frame quickly.
- `/snapshot.jpg?slice=5:2-3` produces expected crop.
- `/fritz.jpg` works on FritzFon.
- `/stream.mjpeg` serves multipart stream (if implemented in the future).
- `/status.json?token=...` returns valid JSON.

## Pull Request Checklist
- [ ] No secrets or private hostnames in diff
- [ ] Added/updated README or CHANGELOG if user-facing change
- [ ] CI passes
- [ ] Tested on Raspberry Pi (if performance-related)

## Release Process
1. Update `CHANGELOG.md` with new version section.
2. Bump tag: `git tag -a vX.Y.Z -m "Release vX.Y.Z"` and push tag.
3. Create GitHub Release with notes (paste CHANGELOG section).

## License
By contributing, you agree your contributions are licensed under the MIT License.
