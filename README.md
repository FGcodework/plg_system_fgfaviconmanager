# FG Favicon Manager

[![Joomla](https://img.shields.io/badge/Joomla-5.x%20%7C%206.x-1a3867?logo=joomla&logoColor=white)](https://www.joomla.org)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4?logo=php&logoColor=white)](https://www.php.net)
[![Version](https://img.shields.io/github/v/release/FGcodework/plg_system_fgfaviconmanager?color=ff6b4a)](https://github.com/FGcodework/plg_system_fgfaviconmanager/releases)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)](LICENSE)
[![Ko-fi](https://img.shields.io/badge/Ko--fi-support-ff5e5b?logo=ko-fi&logoColor=white)](https://ko-fi.com/FGcodework)

<img src="assets/logo.png" alt="FG Favicon Manager" width="128" align="right">

Native **Joomla 5 / 6** system plugin that replaces the favicon of your site (and optionally of the administrator) with icons generated automatically from **one source image** - no template editing.

## Features

- All favicon sizes (16/32/48/192/512 PNG), multi-resolution `favicon.ico`, 180x180 Apple touch icon, generated from a single source
- Sources: PNG, ICO, JPEG, WebP, AVIF and **SVG** (vector `<link>` plus PNG/ICO/Apple fallbacks, safety pre-scan of the SVG)
- GD with automatic **Imagick** fallback; contain-fit (non-square sources are never cropped)
- Dark-mode favicon, separate administrator favicon, theme color (light/dark)
- Android / PWA: web app manifest (Off / Generate / Defer), maskable icons with padding and background
- iOS web app: `apple-mobile-web-app-*` tags and 9-profile splash screens
- Template conflict strip (Off / Soft / Aggressive), token-aware and `<head>`-only
- Atomic, self-cleaning icon cache + markup cache; "Clear Icon Cache" button
- Debug mode that explains in an HTML comment why a favicon was not rendered
- Languages: en-GB, sk-SK

## Requirements

Joomla 5.x or 6.x, PHP 8.1+, GD or Imagick. SVG sources need Imagick with the `rsvg-convert` binary (Debian/Ubuntu: `librsvg2-bin`).

## Installation

Download `plg_system_fgfaviconmanager_vX.X.X.zip` from [Releases](https://github.com/FGcodework/plg_system_fgfaviconmanager/releases), install it in *System -> Install -> Extensions* and enable **System - FG Favicon Manager**. Select a square image (ideally 512x512+) as *Favicon Source*, save - done. Updates arrive through Joomla's update system.

> Upgrading from the pre-rename `plg_system_faviconmanager`? It is a separate extension: uninstall it first and re-enter the settings; the old `media/plg_system_faviconmanager/` folder can be deleted.

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

## Development

```
composer install   # or: apt install phpunit
vendor/bin/phpunit
```

41 PHPUnit tests cover path resolution, contain-fit sizing, color normalisation and the SVG safety scan. `tests/`, `phpunit.xml.dist` and `composer.json` are not part of the install ZIP.

## Support this project

The plugin is free. If it saves you time, you can support it voluntarily:

[![ko-fi](https://ko-fi.com/img/githubbutton_sm.svg)](https://ko-fi.com/FGcodework)

## License

GPL-2.0-or-later, see [LICENSE](LICENSE).
