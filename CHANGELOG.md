# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2025-08-18
### Added
- Initial public release: RTSP snapshot + MJPEG streaming optimized for FritzFon.
- Vertical slicing and center cropping via query parameters.
- Environment variable configuration with `.env` support.
- Persistent `ffmpeg` frame producer script + systemd service template.
- Apache vhost template with security headers and rewrite rules.
- Status endpoint returning JSON health info.
- README, LICENSE, .gitattributes, .gitignore.
- CI workflow (PHP lint, ShellCheck, basic secret scan).

### Security
- No hardcoded credentials; `.env` ignored by git.

[0.1.0]: https://github.com/amrheing/rtsp2jpeg_for_fritz_fon/releases/tag/v0.1.0
