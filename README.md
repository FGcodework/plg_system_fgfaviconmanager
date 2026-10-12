<p align="center">
  <img src="assets/logo.png" alt="FG Favicon Manager" width="140">
</p>

<h1 align="center">FG Favicon Manager</h1>

<p align="center">
  Native <b>Joomla 5 / 6</b> system plugin that turns <b>one source image</b> into every favicon your site needs.<br>
  No template editing.
</p>

<p align="center">
  <a href="https://www.joomla.org"><img alt="Joomla" src="https://img.shields.io/badge/Joomla-5.x%20%7C%206.x-1a3867?logo=joomla&logoColor=white"></a>
  <a href="https://www.php.net"><img alt="PHP" src="https://img.shields.io/badge/PHP-8.1%2B-777bb4?logo=php&logoColor=white"></a>
  <a href="https://github.com/FGcodework/plg_system_fgfaviconmanager/releases"><img alt="Release" src="https://img.shields.io/github/v/release/FGcodework/plg_system_fgfaviconmanager?color=ff6b4a"></a>
  <a href="LICENSE"><img alt="License" src="https://img.shields.io/badge/license-GPL--2.0--or--later-blue"></a>
  <a href="https://ko-fi.com/FGcodework"><img alt="Ko-fi" src="https://img.shields.io/badge/Ko--fi-support-ff5e5b?logo=ko-fi&logoColor=white"></a>
</p>

---

## Quick start

1. Download `plg_system_fgfaviconmanager_vX.X.X.zip` from [Releases](https://github.com/FGcodework/plg_system_fgfaviconmanager/releases) and install it in **System → Install → Extensions**.
2. Enable **System - FG Favicon Manager**.
3. Open the plugin, pick a square image (ideally 512×512 or larger) as **Favicon Source**, save.

Everything else is optional and has sensible defaults. Updates arrive through Joomla's update system.

## Features

| | |
|---|---|
| **Icons** | 16 / 32 / 48 / 192 / 512 px PNG, multi-resolution `favicon.ico`, 180×180 Apple touch icon |
| **Sources** | PNG, ICO, JPEG, WebP, AVIF and **SVG** (vector `<link>` with PNG/ICO/Apple fallbacks, SVG safety pre-scan) |
| **Image engine** | GD with automatic Imagick fallback; contain-fit, so non-square sources are never cropped |
| **Themes** | Dark-mode favicon, theme color (light / dark), separate administrator favicon |
| **Android / PWA** | Web app manifest (Off / Generate / Defer), maskable icons with padding and background |
| **iOS web app** | `apple-mobile-web-app-*` tags and 9-profile splash screens |
| **Conflicts** | Template favicon strip (Off / Soft / Aggressive), token-aware and `<head>`-only |
| **Caching** | Atomic, self-cleaning icon cache and markup cache, "Clear Icon Cache" button |
| **Debugging** | Debug mode explains in an HTML comment why a favicon was not rendered |
| **Languages** | en-GB, sk-SK |

## Requirements

- Joomla 5.x or 6.x, PHP 8.1+
- GD or Imagick
- For SVG sources: Imagick with the `rsvg-convert` binary (Debian/Ubuntu: `apt install librsvg2-bin`)

## Plugin settings

| Tab | Content |
|---|---|
| Plugin | Plugin info and support note |
| Frontend Favicon | Favicon source, theme color |
| Advanced | Dark favicon, dark theme color, Apple touch icon override, template conflict strip, debug mode |
| Administrator Favicon | Admin favicon, dark variant, admin theme color |
| Android / PWA Manifest | Manifest mode, names, colors, maskable icons |
| iOS / Apple Web App | Web-app tags, status bar, splash screens |
| Cache Maintenance | Clear Icon Cache |
| Info | Quick start and technical reference |

## Upgrading from `plg_system_faviconmanager`

The plugin was renamed to FG Favicon Manager and is a separate extension. Uninstall the old one first (otherwise both would inject favicons) and re-enter the settings. The old `media/plg_system_faviconmanager/` folder can be deleted.

## Development

```bash
composer install        # or: apt install phpunit
vendor/bin/phpunit
```

44 PHPUnit tests cover path resolution, contain-fit sizing, color normalisation, the SVG safety scan and the filesystem helpers. `tests/`, `phpunit.xml.dist` and `composer.json` are not part of the install ZIP.

## Support this project

The plugin is free. If it saves you time, you can support it voluntarily:

[![ko-fi](https://ko-fi.com/img/githubbutton_sm.svg)](https://ko-fi.com/FGcodework)

## License

GPL-2.0-or-later, see [LICENSE](LICENSE).
