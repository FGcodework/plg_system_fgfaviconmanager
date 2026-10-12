<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.Fgfaviconmanager
 *
 * @copyright   Copyright (C) 2026. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace FG\Plugin\System\Fgfaviconmanager\Helper;


defined('_JEXEC') or die;

/**
 * Generates cached, on-demand favicon assets (multiple PNG sizes, a multi-resolution
 * favicon.ico and an Apple touch icon) from a single source image. Raster sources
 * (PNG/JPG/GIF/WEBP) are resized via GD. SVG sources are kept as the primary vector
 * icon and, when Imagick with SVG support is available, additionally rasterised into
 * the same full PNG fallback set (for older browsers, Apple touch icon and the PWA
 * manifest, which requires PNG).
 *
 * Results are cached on disk in a hash-named subfolder. The hash is derived from the
 * source file path, modification time and size, so uploading a new/changed source image
 * automatically invalidates the cache (cache-safe, no manual purge required).
 *
 * @since  1.0.0
 */
class IconGenerator
{
    /**
     * Standard raster sizes generated for every source image.
     *
     * @var int[]
     */
    private const SIZES = [16, 32, 48, 192, 512];

    /**
     * Apple touch icon size.
     *
     * @var int
     */
    private const APPLE_SIZE = 180;

    /**
     * Sizes generated for Android maskable (adaptive) icons.
     *
     * @var int[]
     */
    private const MASKABLE_SIZES = [192, 512];

    /**
     * iOS splash screen profiles as [CSS width, CSS height, device pixel ratio],
     * portrait orientation only. iOS matches apple-touch-startup-image tags against
     * exact device-width/device-height/-webkit-device-pixel-ratio, so there is no
     * single "one size fits all" splash - this covers the current mainstream iPhone
     * and iPad screens; a device not in this list simply gets none (falls back to a
     * blank screen during launch, same as before this feature existed - never worse).
     * Landscape is intentionally not covered, to keep generation and cache size bounded.
     *
     * @var array<int, array{0:int,1:int,2:int}>
     */
    private const SPLASH_SCREENS = [
        [375, 667, 2],   // iPhone SE (2nd/3rd gen), 6/7/8
        [390, 844, 3],   // iPhone 12/13/14
        [393, 852, 3],   // iPhone 14 Pro / 15
        [428, 926, 3],   // iPhone 12/13/14 Pro Max
        [430, 932, 3],   // iPhone 14/15 Pro Max
        [744, 1133, 2],  // iPad Mini
        [810, 1080, 2],  // iPad 10.2"
        [834, 1194, 2],  // iPad Pro 11"
        [1024, 1366, 2], // iPad Pro 12.9"
    ];

    /**
     * Processes a source image (SVG passthrough or raster resize set) and returns the
     * public URLs of the generated assets. Nothing is regenerated if a valid cache
     * already exists for the current source file.
     *
     * @param   string  $sourcePath   Absolute filesystem path to the source image.
     * @param   string  $cacheRootFs  Absolute filesystem path to the cache root folder.
     * @param   string  $cacheRootUrl Public URL (root-relative) of the cache root folder.
     *
     * @return  array|null  Null on failure (missing file, unsupported type, GD unavailable).
     *
     * @since   1.0.0
     */
    public function process(string $sourcePath, string $cacheRootFs, string $cacheRootUrl): ?array
    {
        if (!is_file($sourcePath)) {
            $this->errors[] = 'source file not found: ' . $sourcePath;

            return null;
        }

        if (!is_readable($sourcePath)) {
            $this->errors[] = 'source file not readable (check permissions): ' . $sourcePath;

            return null;
        }

        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));

        // Two-part cache key: a stable hash of the source PATH, plus a hash of its
        // current VERSION (mtime + size). Keeping the path part stable and separate is
        // what lets us find and delete this source's previous versions below, without
        // maintaining any index or scanning file contents.
        $pathHash    = $this->pathHash($sourcePath);
        $versionHash = $this->versionHash($sourcePath);
        $hash        = $pathHash . '-' . $versionHash;

        $dirFs  = rtrim($cacheRootFs, '/') . '/' . $hash;
        $dirUrl = rtrim($cacheRootUrl, '/') . '/' . $hash;

        $existed = is_dir($dirFs);

        $result = $ext === 'svg'
            ? $this->processSvg($sourcePath, $dirFs, $dirUrl, $hash)
            : $this->processRaster($sourcePath, $dirFs, $dirUrl, $hash);

        // A new version was just built - drop this source's older version folders so
        // the cache does not grow forever with every logo change.
        if ($result && !$existed) {
            $this->pruneOldVersions($cacheRootFs, $pathHash . '-', $hash);
            $this->pruneStaleBuilds($cacheRootFs);
        }

        return $result;
    }

    /**
     * Reasons why an operation failed, collected for the plugin's debug mode.
     *
     * @var string[]
     */
    private array $errors = [];

    /**
     * Returns the failure reasons collected during this instance's work.
     *
     * @return  string[]
     *
     * @since   1.7.1
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Creates a folder (recursively) with plain PHP, so the plugin does not depend on the
     * deprecated Joomla\CMS\Filesystem\Folder class (scheduled for removal in Joomla 7).
     * Returns true when the folder exists afterwards, including when a concurrent request
     * created it first.
     */
    public static function makeDir(string $dir): bool
    {
        return is_dir($dir) || @mkdir($dir, 0755, true) || is_dir($dir);
    }

    /**
     * Recursively deletes a folder with plain PHP. Symlinks are unlinked, never followed.
     */
    public static function removeTree(string $dir): bool
    {
        if (is_link($dir) || !is_dir($dir)) {
            return is_link($dir) ? @unlink($dir) : false;
        }

        $items = scandir($dir);

        if ($items === false) {
            return false;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path) && !is_link($path)) {
                self::removeTree($path);
            } else {
                @unlink($path);
            }
        }

        return @rmdir($dir);
    }

    /**
     * Clears PHP's last-error slot so a following suppressed call can be attributed
     * accurately rather than picking up something older.
     *
     * @since  1.7.1
     */
    private function clearLastError(): void
    {
        error_clear_last();
    }

    /**
     * Records why a suppressed operation failed.
     *
     * The "@" on those calls is deliberate - this runs inside onAfterRender, so an
     * unsuppressed warning would be printed straight into the page's HTML. Suppression
     * only hides the MESSAGE though, not the failure itself: every one of those calls is
     * already checked by return value. This recovers the message from PHP's last-error
     * slot so debug mode can report the actual cause ("Permission denied", "No space left
     * on device", an open_basedir restriction) instead of a generic failure.
     *
     * @since  1.7.1
     */
    private function recordError(string $context): void
    {
        $last   = error_get_last();
        $detail = $last['message'] ?? 'no further detail available';

        // PHP prefixes the function name; keep the useful remainder.
        $detail = preg_replace('#^[a-z_]+\(.*?\):\s*#i', '', $detail) ?: $detail;

        $this->errors[] = $context . ': ' . $detail;
    }

    /**
     * Hard ceiling on the size of an SVG we are willing to hand to ImageMagick.
     * Favicon artwork is tiny; anything past this is either machine-generated or an
     * attempt to burn CPU/RAM in the rasteriser.
     *
     * @var int
     */
    private const MAX_SVG_BYTES = 2097152;

    /**
     * Decides whether an SVG is safe to hand to the ImageMagick/librsvg rasteriser.
     *
     * SVG is an active format: it can pull in external resources, declare XML entities
     * (billion-laughs / XXE) and embed scripts. The rasteriser runs server-side, so a
     * hostile or merely pathological file becomes our CPU, our RAM and potentially our
     * outbound network requests. librsvg blocks most of this by default and the source is
     * chosen by an administrator, but neither is a reason to hand over a file unchecked -
     * the delegate actually used depends on the server's ImageMagick build and policy.xml,
     * and older builds may fall back to ImageMagick's own MSVG renderer.
     *
     * On rejection we simply skip RASTERISATION: the vector icon is still published, so
     * the visible outcome is the same as on a server without Imagick.
     *
     * Note this does not change the file's exposure: the source already sits under the
     * site's images folder, served from the same origin, so copying it to the cache adds
     * no reachability it did not already have.
     *
     * @since  1.7.0
     */
    private function isSvgSafeToRasterize(string $path): bool
    {
        $size = @filesize($path);

        if ($size === false || $size > self::MAX_SVG_BYTES) {
            return false;
        }

        $data = @file_get_contents($path);

        if ($data === false) {
            return false;
        }

        // DOMDocument is preferred: unlike a regex scan of the raw bytes, the parser
        // actually resolves character references as part of normal XML parsing (so
        // href="&#104;ttp://evil" reads back as "http://evil" - a regex over raw bytes
        // never sees the decoded form), and it lets us reason about actual elements and
        // attributes instead of pattern-matching text. Falls back to the older regex
        // heuristic only if ext-dom is somehow unavailable (rare - practically always
        // bundled with PHP), so the pre-scan still degrades rather than disappearing.
        if (\class_exists(\DOMDocument::class)) {
            return $this->isSvgSafeViaDom($data);
        }

        return $this->isSvgSafeViaRegex($data);
    }

    /**
     * DOM-based SVG safety check: rejects anything that could make the rasteriser fetch
     * a remote/local resource, run script, or embed foreign content - without needing an
     * explicit allowed-element list (which is unnecessary precision for a favicon: we
     * only need to rule out the handful of genuinely risky constructs, not validate the
     * artwork itself).
     *
     * Deliberately conservative in one direction: any DOCTYPE at all is rejected outright
     * rather than specifically hunting for `<!ENTITY` declarations. A legitimate SVG
     * favicon never needs one, and this is structurally what prevents custom entity
     * declarations (XXE, billion-laughs) in the first place, rather than pattern-matching
     * for one particular declaration syntax.
     *
     * @since  1.13.0
     */
    private function isSvgSafeViaDom(string $data): bool
    {
        $previousErrorSetting = libxml_use_internal_errors(true);

        try {
            $doc = new \DOMDocument();

            // LIBXML_NONET: never let the parser itself fetch anything over the network,
            // as defense in depth alongside the element/attribute checks below.
            $loaded = $doc->loadXML($data, \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOWARNING);

            if (!$loaded || $doc->documentElement === null) {
                return false;
            }

            if ($doc->doctype !== null) {
                return false;
            }

            $root = strtolower($doc->documentElement->localName ?? $doc->documentElement->nodeName);

            if ($root !== 'svg') {
                return false;
            }

            $blockedElements = ['script', 'foreignobject', 'iframe', 'embed', 'object', 'audio', 'video'];

            foreach ($doc->getElementsByTagName('*') as $element) {
                /** @var \DOMElement $element */
                $localName = strtolower($element->localName ?? $element->nodeName);

                if (\in_array($localName, $blockedElements, true)) {
                    return false;
                }

                if ($localName === 'style' && $this->cssReferencesExternalResource((string) $element->textContent)) {
                    return false;
                }

                if (!$element->hasAttributes()) {
                    continue;
                }

                foreach ($element->attributes as $attr) {
                    /** @var \DOMAttr $attr */
                    $attrLocalName = strtolower($attr->localName ?? $attr->nodeName);

                    // Inline event handlers (onload, onerror, onclick, ...) - script
                    // execution vector regardless of a <script> element being present.
                    if (str_starts_with($attrLocalName, 'on')) {
                        return false;
                    }

                    if (
                        ($attrLocalName === 'href' || str_ends_with($attrLocalName, ':href'))
                        && $this->isExternalReference($attr->value)
                    ) {
                        return false;
                    }

                    if ($attrLocalName === 'style' && $this->cssReferencesExternalResource($attr->value)) {
                        return false;
                    }
                }
            }

            return true;
        } catch (\Throwable $e) {
            return false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorSetting);
        }
    }

    /**
     * True if a URL/reference (already entity-decoded by the caller, since it came from
     * a DOM attribute value) points outside the document: absolute with a network-capable
     * scheme, or protocol-relative. A bare fragment ("#id") or a data: URI is NOT
     * external - both are common, safe SVG techniques (reusing a <symbol>, embedding a
     * raster sub-image) that resolve without any additional fetch.
     *
     * @since  1.13.0
     */
    private function isExternalReference(string $value): bool
    {
        $value = trim($value);

        if ($value === '' || $value[0] === '#') {
            return false;
        }

        return (bool) preg_match('#^(?:https?|ftp|file):|^//#i', $value);
    }

    /**
     * True if a block of CSS (a <style> element's content, or a style="" attribute
     * value) contains an external url(...) or @import - the vector a plain href/script
     * scan misses entirely, since neither a <style> element nor a style attribute is
     * script or a link in the DOM sense.
     *
     * @since  1.13.0
     */
    private function cssReferencesExternalResource(string $css): bool
    {
        if (preg_match_all('#url\(\s*[\'"]?([^\'")]+)#i', $css, $matches)) {
            foreach ($matches[1] as $ref) {
                if ($this->isExternalReference($ref)) {
                    return true;
                }
            }
        }

        return (bool) preg_match('#@import\b#i', $css);
    }

    /**
     * Original raw-byte regex heuristic, kept only as a fallback for the (rare) case
     * ext-dom is unavailable. Known to be less precise than isSvgSafeViaDom() - notably
     * it cannot see through numeric/named character-reference obfuscation of a URL scheme
     * (href="&#104;ttp://...") and does not inspect CSS - which is exactly why the DOM
     * path is preferred whenever available.
     *
     * @since  1.13.0
     */
    private function isSvgSafeViaRegex(string $data): bool
    {
        if (stripos($data, '<!ENTITY') !== false) {
            return false;
        }

        if (preg_match('#(?:xlink:)?href\s*=\s*["\']?\s*(?:https?:|ftp:|file:|//)#i', $data)) {
            return false;
        }

        if (preg_match('#<\s*(?:script|foreignObject|iframe)\b#i', $data)) {
            return false;
        }

        return true;
    }

    /**
     * Applies conservative Imagick resource limits around SVG rasterisation and returns
     * the previous values so the caller can restore them.
     *
     * Scope, measured rather than assumed: these bound work ImageMagick does ITSELF -
     * its internal MSVG renderer, resampling, buffer allocation. When SVG decoding is
     * handed to an external delegate (rsvg-convert), that process runs outside
     * ImageMagick's resource accounting, and testing confirms neither the TIME nor the
     * AREA limit aborts it - AREA merely pushes ImageMagick from memory to a disk-backed
     * cache. Bounding a delegate is the server's job, via ImageMagick's policy.xml.
     *
     * They are kept because they cost nothing, they do apply on builds that render SVG
     * internally (the riskier path), and they cover the raster steps either way. The
     * plugin's effective control over hostile SVG is the pre-scan in
     * isSvgSafeToRasterize(), not these limits.
     *
     * Imagick's resource limits are PROCESS-GLOBAL, so leaving ours in place would
     * silently constrain any other image work later in the same request (Joomla's own
     * thumbnailing, another extension). They are therefore always restored afterwards.
     *
     * @return  array<int, int>  Previous limits, keyed by resource type.
     *
     * @since   1.7.0
     */
    private function applyImagickLimits(): array
    {
        $limits = [
            \Imagick::RESOURCETYPE_MEMORY => 268435456,  // 256 MB
            \Imagick::RESOURCETYPE_MAP    => 536870912,  // 512 MB
            \Imagick::RESOURCETYPE_AREA   => 134217728,  // 128 MP
            \Imagick::RESOURCETYPE_TIME   => 20,         // seconds
        ];

        $previous = [];

        foreach ($limits as $type => $value) {
            try {
                $previous[$type] = \Imagick::getResourceLimit($type);
                \Imagick::setResourceLimit($type, $value);
            } catch (\Throwable $e) {
                // Not every build exposes every resource type - skip the ones it lacks.
                unset($previous[$type]);
            }
        }

        return $previous;
    }

    /**
     * Restores Imagick resource limits saved by applyImagickLimits().
     *
     * @since  1.7.0
     */
    private function restoreImagickLimits(array $previous): void
    {
        foreach ($previous as $type => $value) {
            try {
                \Imagick::setResourceLimit($type, $value);
            } catch (\Throwable $e) {
                // Best effort.
            }
        }
    }

    /**
     * Creates a private staging folder for building an icon set.
     *
     * Everything is generated here first and only becomes visible under its final name
     * via an atomic rename in publishBuild(). This guarantees that a request (or the web
     * server serving an icon URL) never sees a half-written file: published files are
     * never rewritten in place.
     *
     * The name is dot-prefixed so it can never be matched by the version-pruning glob,
     * and carries a random suffix so concurrent builds never share a staging folder.
     *
     * @since  1.5.0
     */
    private function beginBuild(string $cacheRootFs, string $finalDirName): ?string
    {
        try {
            $suffix = bin2hex(random_bytes(4));
        } catch (\Throwable $e) {
            $suffix = (string) mt_rand(100000, 999999);
        }

        $tmp = rtrim($cacheRootFs, '/') . '/.tmp-' . $finalDirName . '-' . $suffix;

        if (is_dir($tmp)) {
            return null;
        }

        $this->clearLastError();

        if (!self::makeDir($tmp)) {
            $this->recordError('cannot create cache folder in ' . $cacheRootFs . ' (check write permissions)');

            return null;
        }

        return $tmp;
    }

    /**
     * Atomically moves a completed staging folder into its final location.
     *
     * Handles the two cases a plain rename() cannot: a concurrent request that published
     * the same version first (we simply keep theirs), and an existing stale/incomplete
     * folder of the same name (renaming onto a non-empty directory fails on POSIX, so the
     * old one is swapped out first and removed afterwards).
     *
     * $isComplete re-checks the EXISTING final folder at publish time: callers decided to
     * build because the cache looked incomplete, but a concurrent request may have
     * published a complete set while this one was generating. Swapping that one out would
     * discard good work and open a short window where its URLs return 404 (between the
     * two rename() calls), so a complete existing folder is kept and our staging copy is
     * dropped. The swap is only used for a genuinely incomplete or damaged folder.
     *
     * @param   string         $tmp         Completed staging folder.
     * @param   string         $finalDir    Final cache folder.
     * @param   callable|null  $isComplete  fn(string $dir): bool - true if $dir is a usable set.
     *
     * @since  1.5.0
     */
    private function publishBuild(string $tmp, string $finalDir, ?callable $isComplete = null): bool
    {
        if (!is_dir($finalDir)) {
            if (@rename($tmp, $finalDir)) {
                return true;
            }

            // Lost the race: another request published this exact version meanwhile.
            if (is_dir($finalDir)) {
                $this->discardBuild($tmp);

                return true;
            }

            $this->discardBuild($tmp);

            return false;
        }

        if ($isComplete !== null && $isComplete($finalDir)) {
            $this->discardBuild($tmp);

            return true;
        }

        // Replace an existing (stale or incomplete) folder, keeping the window in which
        // the final name does not exist as short as possible.
        $trash = \dirname($finalDir) . '/.old-' . basename($finalDir) . '-' . mt_rand(100000, 999999);

        if (@rename($finalDir, $trash)) {
            if (@rename($tmp, $finalDir)) {
                $this->discardBuild($trash);

                return true;
            }

            // Could not publish - put the previous folder back rather than lose it.
            @rename($trash, $finalDir);
            $this->discardBuild($tmp);

            return false;
        }

        $this->discardBuild($tmp);

        return is_dir($finalDir);
    }

    /**
     * Removes a staging or superseded folder, ignoring failures.
     *
     * @since  1.5.0
     */
    private function discardBuild(string $dir): void
    {
        try {
            self::removeTree($dir);
        } catch (\Throwable $e) {
            // Best effort only.
        }
    }

    /**
     * Removes staging/trash folders left behind by a crashed or timed-out request.
     * Only touches folders older than an hour, so builds in progress are never disturbed.
     *
     * @since  1.5.0
     */
    private function pruneStaleBuilds(string $cacheRootFs): void
    {
        $root = rtrim($cacheRootFs, '/');

        foreach ([$root . '/.tmp-*', $root . '/.old-*'] as $pattern) {
            foreach ((array) @glob($pattern, GLOB_ONLYDIR) as $dir) {
                if (time() - (int) @filemtime($dir) > 3600) {
                    $this->discardBuild($dir);
                }
            }
        }
    }

    /**
     * Generates iOS splash screen images (apple-touch-startup-image) for the device
     * profiles in SPLASH_SCREENS: the icon centred on a solid background, sized to
     * each device's exact CSS width/height x pixel ratio.
     *
     * Uses the same GD-backed loader as everything else (loadSourceAsGd(), which
     * already handles SVG by rasterising through Imagick internally), so this needs no
     * separate Imagick code path - without GD available the feature is simply skipped,
     * same graceful-degradation policy as the rest of the plugin.
     *
     * @param   string  $sourcePath    Absolute filesystem path to the source image.
     * @param   string  $cacheRootFs   Absolute filesystem path to the cache root folder.
     * @param   string  $cacheRootUrl  Public URL (root-relative) of the cache root folder.
     * @param   string  $background    Hex background colour (splash screens must be
     *                                 opaque - a "transparent" request falls back to white).
     *
     * @return  array<int, array{width:int,height:int,scale:int,url:string}>|null
     *
     * @since   1.8.0
     */
    public function generateSplashScreens(
        string $sourcePath,
        string $cacheRootFs,
        string $cacheRootUrl,
        string $background
    ): ?array {
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            $this->errors[] = 'splash: source file missing or unreadable: ' . $sourcePath;

            return null;
        }

        $bgHex = $this->normalizeColor($background) ?? '#ffffff';

        $pathHash = 'splash-' . $this->pathHash($sourcePath);
        $hash     = $pathHash . '-' . $this->versionHash($sourcePath, $bgHex);

        $dirFs  = rtrim($cacheRootFs, '/') . '/' . $hash;
        $dirUrl = rtrim($cacheRootUrl, '/') . '/' . $hash;

        $specs = [];

        foreach (self::SPLASH_SCREENS as [$w, $h, $scale]) {
            $file           = 'splash-' . $w . 'x' . $h . '@' . $scale . 'x.png';
            $specs[] = [
                'width'  => $w,
                'height' => $h,
                'scale'  => $scale,
                'file'   => $file,
                'url'    => $dirUrl . '/' . $file,
            ];
        }

        if ($this->hasCompleteFileSet($dirFs, array_column($specs, 'file'))) {
            return $this->stripFileKey($specs);
        }

        $tmp = $this->beginBuild($cacheRootFs, $hash);

        if ($tmp === null) {
            return null;
        }

        $src = $this->loadSourceAsGd($sourcePath);

        if (!$src) {
            $this->discardBuild($tmp);

            return null;
        }

        [$r, $g, $b] = $this->parseColor($bgHex);

        try {
            foreach ($specs as $spec) {
                $pxW = $spec['width'] * $spec['scale'];
                $pxH = $spec['height'] * $spec['scale'];

                $canvas = imagecreatetruecolor($pxW, $pxH);
                imagefilledrectangle($canvas, 0, 0, $pxW, $pxH, imagecolorallocate($canvas, $r, $g, $b));

                // The mark occupies roughly 40% of the shorter screen dimension, centred -
                // matches the proportions of Apple's own splash screen generator tooling.
                $markBox = (int) round(min($pxW, $pxH) * 0.4);
                [$dw, $dh] = $this->containSize(imagesx($src), imagesy($src), $markBox, $markBox);
                $dx = (int) round(($pxW - $dw) / 2);
                $dy = (int) round(($pxH - $dh) / 2);

                imagecopyresampled($canvas, $src, $dx, $dy, 0, 0, $dw, $dh, imagesx($src), imagesy($src));

                $this->clearLastError();
                // Compression level 6 rather than the 9 used elsewhere: on these large,
                // mostly solid-colour canvases 9 costs roughly twice the CPU time for a
                // near-identical file size (measured: ~33KB vs ~34KB on a 2048x2732 canvas,
                // level 9 taking ~40% longer) - generation runs synchronously on first
                // render, so that time is directly felt by whichever visitor triggers it.
                $ok = imagepng($canvas, $tmp . '/' . $spec['file'], 6);
                imagedestroy($canvas);

                if (!$ok) {
                    $this->recordError('splash: cannot write ' . $spec['file']);
                    imagedestroy($src);
                    $this->discardBuild($tmp);

                    return null;
                }
            }
        } catch (\Throwable $e) {
            imagedestroy($src);
            $this->discardBuild($tmp);

            return null;
        }

        imagedestroy($src);

        if (!$this->hasCompleteFileSet($tmp, array_column($specs, 'file'))) {
            $this->discardBuild($tmp);

            return null;
        }

        if (!$this->publishBuild($tmp, $dirFs, fn (string $dir): bool => $this->hasCompleteFileSet($dir, array_column($specs, 'file')))) {
            return null;
        }

        $this->pruneOldVersions($cacheRootFs, $pathHash . '-', $hash);

        return $this->stripFileKey($specs);
    }

    /**
     * True when every named file exists in $dirFs and is non-empty. Generic sibling of
     * hasCompleteRasterCache() / hasCompleteMaskableCache() for feature sets (like splash
     * screens) whose file list isn't a fixed pair of constants.
     *
     * @since  1.8.0
     */
    private function hasCompleteFileSet(string $dirFs, array $fileNames): bool
    {
        foreach ($fileNames as $name) {
            $path = $dirFs . '/' . $name;

            if (!is_file($path) || filesize($path) === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Drops the internal 'file' key (a staging-relative filename) before returning specs
     * to the caller, which only needs the public 'url'.
     *
     * @since  1.8.0
     */
    private function stripFileKey(array $specs): array
    {
        return array_map(static function (array $spec): array {
            unset($spec['file']);

            return $spec;
        }, $specs);
    }

    /**
     * True only when every asset of the raster set exists AND is non-empty.
     *
     * Checking just favicon.ico + apple-touch-icon is not enough: a silent write failure
     * (disk full, memory_limit hit mid-generation) can leave a zero-byte or missing PNG
     * while the later-written files succeed, producing a cache that looks valid but
     * serves broken icons. Zero-length files are treated as absent for the same reason.
     *
     * @since  1.4.1
     */
    private function hasCompleteRasterCache(string $dirFs): bool
    {
        $required = [$dirFs . '/favicon.ico', $dirFs . '/apple-touch-icon.png'];

        foreach (self::SIZES as $size) {
            $required[] = $dirFs . '/icon-' . $size . '.png';
        }

        foreach ($required as $file) {
            if (!is_file($file) || filesize($file) === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * True only when every maskable asset exists and is non-empty.
     *
     * @since  1.4.1
     */
    private function hasCompleteMaskableCache(string $dirFs): bool
    {
        foreach (self::MASKABLE_SIZES as $size) {
            $file = $dirFs . '/maskable-' . $size . '.png';

            if (!is_file($file) || filesize($file) === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Stable hash identifying a source FILE PATH (not its content).
     *
     * @since  1.4.0
     */
    private function pathHash(string $sourcePath): string
    {
        return substr(md5($sourcePath), 0, 8);
    }

    /**
     * Hash identifying the current VERSION of a source file.
     *
     * Uses modification time + size rather than a content hash: for the normal flows
     * (Media Manager upload, FTP overwrite) any change updates mtime, so this is
     * reliable without reading the file. Note the known edge case - restoring a file
     * with preserved timestamps AND an identical size (e.g. `rsync -a`) will not
     * invalidate the cache; re-saving or touching the file fixes it.
     *
     * @since  1.4.0
     */
    private function versionHash(string $sourcePath, string $extra = ''): string
    {
        return substr(md5(filemtime($sourcePath) . '|' . filesize($sourcePath) . '|' . $extra), 0, 8);
    }

    /**
     * Deletes cache folders belonging to the same source but to an older version,
     * i.e. siblings sharing $prefix but not named $keep.
     *
     * Folders touched very recently are skipped: a concurrent request may be midway
     * through writing one, and deleting it underneath would produce broken icons.
     *
     * @since  1.4.0
     */
    private function pruneOldVersions(string $cacheRootFs, string $prefix, string $keep): void
    {
        $dirs = @glob(rtrim($cacheRootFs, '/') . '/' . $prefix . '*', GLOB_ONLYDIR);

        if (!$dirs) {
            return;
        }

        foreach ($dirs as $dir) {
            if (basename($dir) === $keep) {
                continue;
            }

            if (time() - (int) @filemtime($dir) < 60) {
                continue;
            }

            try {
                self::removeTree($dir);
            } catch (\Throwable $e) {
                // Cache cleanup is best-effort; never let it break icon delivery.
            }
        }
    }

    /**
     * Generates Android "maskable" icons: the mark is scaled down inside a safe zone and
     * the surrounding padding is filled with a solid background colour, so that when
     * Android clips the icon into a circle / squircle / rounded square (adaptive icons),
     * nothing important gets cut off.
     *
     * These are generated as a SEPARATE set from the normal icons, cached in their own
     * hash folder (the hash includes padding and background colour, so changing either
     * regenerates automatically).
     *
     * @param   string  $sourcePath      Absolute filesystem path to the source image.
     * @param   string  $cacheRootFs     Absolute filesystem path to the cache root folder.
     * @param   string  $cacheRootUrl    Public URL (root-relative) of the cache root folder.
     * @param   int     $paddingPercent  Safe-zone padding per side, in percent (0-40).
     * @param   string  $background      Hex background colour, e.g. "#ffffff".
     *
     * @return  array|null  Map of size => public URL, or null on failure.
     *
     * @since   1.2.0
     */
    public function processMaskable(
        string $sourcePath,
        string $cacheRootFs,
        string $cacheRootUrl,
        int $paddingPercent,
        string $background
    ): ?array {
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            $this->errors[] = 'maskable: source file missing or unreadable: ' . $sourcePath;

            return null;
        }

        $paddingPercent = max(0, min(40, $paddingPercent));

        // Same two-part scheme as process(): stable path part, then a version part that
        // ALSO covers padding and background, so changing either rebuilds and prunes.
        $pathHash = 'mask-' . $this->pathHash($sourcePath);
        $hash     = $pathHash . '-' . $this->versionHash($sourcePath, $paddingPercent . '|' . strtolower($background));

        $dirFs  = rtrim($cacheRootFs, '/') . '/' . $hash;
        $dirUrl = rtrim($cacheRootUrl, '/') . '/' . $hash;

        $urls = [];

        foreach (self::MASKABLE_SIZES as $size) {
            $urls[$size] = $dirUrl . '/maskable-' . $size . '.png';
        }

        if ($this->hasCompleteMaskableCache($dirFs)) {
            return $urls;
        }

        $tmp = $this->beginBuild($cacheRootFs, $hash);

        if ($tmp === null) {
            return null;
        }

        $src = $this->loadSourceAsGd($sourcePath);

        if (!$src) {
            // No GD (or GD could not decode this source) - composite via Imagick instead.
            if (!$this->generateMaskableImagick($sourcePath, $tmp, $paddingPercent, $background)) {
                $this->discardBuild($tmp);

                return null;
            }

            if (!$this->publishBuild($tmp, $dirFs, fn (string $dir): bool => $this->hasCompleteMaskableCache($dir))) {
                return null;
            }

            $this->pruneOldVersions($cacheRootFs, $pathHash . '-', $hash);

            return $urls;
        }

        try {
            foreach (self::MASKABLE_SIZES as $size) {
                $this->writeMaskable($src, $size, $paddingPercent, $background, $tmp . '/maskable-' . $size . '.png');
            }
        } catch (\Throwable $e) {
            imagedestroy($src);
            $this->discardBuild($tmp);

            return null;
        }

        imagedestroy($src);

        if (!$this->hasCompleteMaskableCache($tmp)) {
            $this->discardBuild($tmp);

            return null;
        }

        if (!$this->publishBuild($tmp, $dirFs, fn (string $dir): bool => $this->hasCompleteMaskableCache($dir))) {
            return null;
        }

        $this->pruneOldVersions($cacheRootFs, $pathHash . '-', $hash);

        return $urls;
    }

    /**
     * Imagick fallback for maskable icon compositing (used when GD is unavailable):
     * a solid background canvas with the mark scaled into the safe zone and centred.
     *
     * @since  1.3.0
     */
    private function generateMaskableImagick(
        string $sourcePath,
        string $dirFs,
        int $paddingPercent,
        string $background
    ): bool {
        if (!\class_exists(\Imagick::class)) {
            return false;
        }

        $isSvg = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION)) === 'svg';
        $bgHex = $this->normalizeColor($background) ?? 'transparent';

        if ($isSvg && !$this->isSvgSafeToRasterize($sourcePath)) {
            return false;
        }

        $previousLimits = $isSvg ? $this->applyImagickLimits() : [];

        try {
            foreach (self::MASKABLE_SIZES as $size) {
                $inner = max(1, (int) round($size * (1 - (2 * $paddingPercent / 100))));

                $mark = new \Imagick();
                $mark->setBackgroundColor(new \ImagickPixel('transparent'));

                if ($isSvg) {
                    // Render the vector natively at the inner size for crisp edges.
                    $mark->setResolution(300, 300);
                    $mark->setSize($inner, $inner);
                }

                $mark->readImage($sourcePath);
                $mark->setImageFormat('png32');
                // bestfit without fill: contained within the safe zone, aspect preserved,
                // so the resulting dimensions may be non-square - centre by measuring them.
                $mark->thumbnailImage($inner, $inner, true, false);

                $markW  = $mark->getImageWidth();
                $markH  = $mark->getImageHeight();
                $offsetX = (int) round(($size - $markW) / 2);
                $offsetY = (int) round(($size - $markH) / 2);

                $canvas = new \Imagick();
                $canvas->newImage($size, $size, new \ImagickPixel($bgHex), 'png32');
                $canvas->compositeImage($mark, \Imagick::COMPOSITE_OVER, $offsetX, $offsetY);
                $canvas->writeImage($dirFs . '/maskable-' . $size . '.png');

                $mark->clear();
                $mark->destroy();
                $canvas->clear();
                $canvas->destroy();
            }
        } catch (\Throwable $e) {
            return false;
        } finally {
            $this->restoreImagickLimits($previousLimits);
        }

        return $this->hasCompleteMaskableCache($dirFs);
    }

    /**
     * Composites one maskable icon: solid background canvas + the source mark scaled
     * to fit inside the safe zone, centred.
     *
     * @since  1.2.0
     */
    private function writeMaskable(
        \GdImage $src,
        int $size,
        int $paddingPercent,
        string $background,
        string $outFile
    ): void {
        $canvas = imagecreatetruecolor($size, $size);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);

        [$r, $g, $b, $alpha] = $this->parseColor($background);
        $bgColor = imagecolorallocatealpha($canvas, $r, $g, $b, $alpha);
        imagefilledrectangle($canvas, 0, 0, $size, $size, $bgColor);

        // Safe zone: the mark must fit inside this box, scaled without distortion.
        $inner = (int) round($size * (1 - (2 * $paddingPercent / 100)));
        $inner = max(1, $inner);

        [$dw, $dh] = $this->containSize(imagesx($src), imagesy($src), $inner, $inner);
        $dx = (int) round(($size - $dw) / 2);
        $dy = (int) round(($size - $dh) / 2);

        imagealphablending($canvas, true);
        imagecopyresampled(
            $canvas,
            $src,
            $dx,
            $dy,
            0,
            0,
            $dw,
            $dh,
            imagesx($src),
            imagesy($src)
        );
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);

        $ok = imagepng($canvas, $outFile, 9);
        imagedestroy($canvas);

        if (!$ok) {
            throw new \RuntimeException('Failed to write maskable icon: ' . $outFile);
        }
    }

    /**
     * Normalises a colour option into a canonical "#rrggbb" string, or null when the
     * user explicitly asked for transparency.
     *
     * One policy shared by both imaging engines:
     *  - the literal "transparent" is the ONLY way to get a transparent canvas;
     *  - an empty or unparseable value falls back to opaque white.
     *
     * Empty deliberately does NOT mean transparent here: this colour is only ever used
     * as a maskable icon background, and maskable icons must not be transparent - Android
     * composites them into its own shape, so transparency shows through as a black or
     * otherwise unintended backdrop.
     *
     * Sharing this also removes a divergence between the engines: GD silently fell back
     * to white on a malformed value, while ImagickPixel throws on one, which our catch
     * turned into "maskable icons skipped entirely" on servers without GD.
     *
     * @since  1.6.1
     */
    private function normalizeColor(string $color): ?string
    {
        $color = trim($color);

        if (strtolower($color) === 'transparent') {
            return null;
        }

        $hex = ltrim($color, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return '#ffffff';
        }

        return '#' . strtolower($hex);
    }

    /**
     * Parses a colour option into [r, g, b, gdAlpha] for GD.
     *
     * @since  1.2.0
     * @since  1.6.1  Delegates to normalizeColor() so GD and Imagick agree.
     */
    private function parseColor(string $color): array
    {
        $hex = $this->normalizeColor($color);

        if ($hex === null) {
            return [0, 0, 0, 127];
        }

        $hex = ltrim($hex, '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
            0,
        ];
    }

    /**
     * Loads any supported source (raster via GD, SVG via Imagick) into a GD image
     * resource at a workable resolution, so downstream compositing is format-agnostic.
     *
     * @since  1.2.0
     */
    private function loadSourceAsGd(string $sourcePath, int $svgRenderSize = 512): ?\GdImage
    {
        if (!\function_exists('imagecreatefromstring')) {
            return null;
        }

        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));

        if ($ext === 'svg') {
            if (!\class_exists(\Imagick::class) || !$this->isSvgSafeToRasterize($sourcePath)) {
                return null;
            }

            $previousLimits = $this->applyImagickLimits();

            try {
                $mark = new \Imagick();
                $mark->setBackgroundColor(new \ImagickPixel('transparent'));
                $mark->setResolution(300, 300);
                $mark->setSize($svgRenderSize, $svgRenderSize);
                $mark->readImage($sourcePath);
                $mark->setImageFormat('png32');
                // bestfit (no fill): aspect preserved, may land smaller than the render
                // box on one axis for a non-square SVG. Composited onto a transparent
                // square canvas below rather than force-fit, or the distortion would be
                // baked into this "square" blob before it ever reaches the caller's own
                // (correct) contain-fit logic - which, seeing an already-square image,
                // would have no distortion left to detect or correct.
                $mark->thumbnailImage($svgRenderSize, $svgRenderSize, true, false);

                $offsetX = (int) round(($svgRenderSize - $mark->getImageWidth()) / 2);
                $offsetY = (int) round(($svgRenderSize - $mark->getImageHeight()) / 2);

                $canvas = new \Imagick();
                $canvas->newImage($svgRenderSize, $svgRenderSize, new \ImagickPixel('transparent'), 'png32');
                $canvas->compositeImage($mark, \Imagick::COMPOSITE_OVER, $offsetX, $offsetY);
                $blob = $canvas->getImageBlob();

                $mark->clear();
                $mark->destroy();
                $canvas->clear();
                $canvas->destroy();
            } catch (\Throwable $e) {
                return null;
            } finally {
                $this->restoreImagickLimits($previousLimits);
            }

            $img = @imagecreatefromstring($blob);

            return $img ?: null;
        }

        $data = @file_get_contents($sourcePath);
        $img  = $data !== false ? @imagecreatefromstring($data) : false;

        return $img ?: null;
    }

    /**
     * SVG sources are always copied through as-is (modern browsers render vector icons
     * natively, at any size, so this remains the primary icon reference).
     *
     * When the Imagick extension is available and its SVG delegate works, a full PNG
     * fallback set (all SIZES + favicon.ico + Apple touch icon) is additionally
     * rasterised from the SVG so that: older browsers, the Apple touch icon and the
     * PWA manifest (which requires PNG, not SVG) all still get a proper icon.
     * If Imagick is missing or SVG rasterisation fails, this fails silently and only
     * the vector icon is returned — the plugin never breaks a page because of it.
     *
     * @since  1.0.0 (SVG passthrough)
     * @since  1.1.0 (Imagick PNG fallback generation)
     */
    private function processSvg(string $sourcePath, string $dirFs, string $dirUrl, string $hash): ?array
    {
        $target   = $dirFs . '/icon.svg';
        $svgReady = is_file($target) && filesize($target) > 0;

        // The raster fallback is optional: without Imagick the folder legitimately holds
        // only icon.svg, so requiring a complete raster set would rebuild on every request.
        $canRasterize   = \class_exists(\Imagick::class);
        $rasterReady    = $this->hasCompleteRasterCache($dirFs);
        $needsRebuild   = !$svgReady || ($canRasterize && !$rasterReady);

        if ($needsRebuild) {
            $tmp = $this->beginBuild(\dirname($dirFs), $hash);

            if ($tmp === null) {
                return null;
            }

            $this->clearLastError();

            if (!@copy($sourcePath, $tmp . '/icon.svg')) {
                $this->recordError('cannot copy SVG source into cache');
                $this->discardBuild($tmp);

                return null;
            }

            // Failure here is tolerated - we still publish the vector-only folder.
            $this->rasterizeSvgFallback($tmp . '/icon.svg', $tmp, $dirUrl);

            if (!$this->publishBuild($tmp, $dirFs, static fn (string $dir): bool => is_file($dir . '/icon.svg') && filesize($dir . '/icon.svg') > 0)) {
                return null;
            }
        }

        $result = [
            'hash' => $hash,
            'type' => 'svg',
            'svg'  => $dirUrl . '/icon.svg',
        ];

        if ($this->hasCompleteRasterCache($dirFs)) {
            // Vector icon stays primary ('svg' key above); these are additive fallbacks.
            $result += $this->buildRasterUrls($dirUrl);
        }

        return $result;
    }

    /**
     * Rasterises an SVG source into the full PNG icon set via Imagick, using setSize()
     * per target dimension so each size is rendered natively (crisp), not scaled down
     * from a single bitmap. Returns null if Imagick / its SVG delegate is unavailable
     * or rasterisation fails for any reason.
     *
     * @since  1.1.0
     */
    private function rasterizeSvgFallback(string $svgFilePath, string $dirFs, string $dirUrl): ?array
    {
        $icoFile   = $dirFs . '/favicon.ico';
        $appleFile = $dirFs . '/apple-touch-icon.png';

        if ($this->hasCompleteRasterCache($dirFs)) {
            return $this->buildRasterUrls($dirUrl);
        }

        if (!\class_exists(\Imagick::class)) {
            return null;
        }

        if (!$this->isSvgSafeToRasterize($svgFilePath)) {
            $this->errors[] = 'SVG rejected by safety pre-scan (a DOCTYPE, an external '
                . 'reference in href/CSS, an inline event handler, script/foreignObject/iframe/'
                . 'embed/object/audio/video, or over 2 MB) - vector icon still published';

            return null;
        }

        $previousLimits = $this->applyImagickLimits();

        try {
            $pngFiles = [];

            foreach (self::SIZES as $size) {
                $pngFiles[$size] = $dirFs . '/icon-' . $size . '.png';
                $this->rasterizeSvgToPng($svgFilePath, $size, $size, $pngFiles[$size]);
            }

            $this->rasterizeSvgToPng($svgFilePath, self::APPLE_SIZE, self::APPLE_SIZE, $appleFile);
            $this->writeIco([$pngFiles[16], $pngFiles[32], $pngFiles[48]], $icoFile);
        } catch (\Throwable $e) {
            // Imagick present but SVG delegate (rsvg-convert / librsvg2-bin) missing or
            // broken on this server, or a resource limit was hit. Skip the raster fallback.
            return null;
        } finally {
            $this->restoreImagickLimits($previousLimits);
        }

        if (!$this->hasCompleteRasterCache($dirFs)) {
            return null;
        }

        return $this->buildRasterUrls($dirUrl);
    }

    /**
     * Renders one SVG -> PNG at an exact target resolution using Imagick::setSize(),
     * which for vector delegates controls the render resolution (not a post-resize),
     * keeping edges crisp instead of blurred. Reads from a real file path (not a blob)
     * since the rsvg-convert delegate reliably needs a filename to detect the SVG format.
     *
     * Uses "contain" fit (bestfit, aspect ratio preserved) composited onto a transparent
     * $width x $height canvas, centred - matching the GD raster path's resize(). A plain
     * thumbnailImage($width, $height, false, false) forces the exact target dimensions
     * regardless of the source's aspect ratio, which visibly distorts any non-square SVG
     * (all our targets happen to be square, so this shows up as squashing into a square,
     * not classic crop - but the fix is the same either way: never force-fit, always
     * contain-and-centre).
     *
     * @since  1.1.0
     * @since  1.10.0  Preserve aspect ratio instead of force-fitting.
     */
    private function rasterizeSvgToPng(string $svgFilePath, int $width, int $height, string $outFile): void
    {
        $mark = new \Imagick();
        $mark->setBackgroundColor(new \ImagickPixel('transparent'));
        $mark->setResolution(300, 300);
        $mark->setSize($width, $height);
        $mark->readImage($svgFilePath);
        $mark->setImageFormat('png32');
        // bestfit (no fill): scaled to fit within width x height, aspect preserved - may
        // come out smaller than the target box on one axis for a non-square source.
        $mark->thumbnailImage($width, $height, true, false);

        $offsetX = (int) round(($width - $mark->getImageWidth()) / 2);
        $offsetY = (int) round(($height - $mark->getImageHeight()) / 2);

        $canvas = new \Imagick();
        $canvas->newImage($width, $height, new \ImagickPixel('transparent'), 'png32');
        $canvas->compositeImage($mark, \Imagick::COMPOSITE_OVER, $offsetX, $offsetY);
        $canvas->writeImage($outFile);

        $mark->clear();
        $mark->destroy();
        $canvas->clear();
        $canvas->destroy();
    }

    /**
     * Builds the public URL map for an already-generated raster set (shared between
     * the pure-raster path and the SVG-with-fallback path).
     *
     * @since  1.1.0
     */
    private function buildRasterUrls(string $dirUrl): array
    {
        $png = [];

        foreach (self::SIZES as $size) {
            $png[$size] = $dirUrl . '/icon-' . $size . '.png';
        }

        return [
            'ico'   => $dirUrl . '/favicon.ico',
            'png'   => $png,
            'apple' => $dirUrl . '/apple-touch-icon.png',
        ];
    }

    /**
     * Generates the full raster icon set (PNG sizes, favicon.ico, apple touch icon).
     *
     * GD is preferred (faster and lighter for simple resizing). If GD is not compiled in,
     * or it cannot decode this particular source, Imagick is used as a fallback, so the
     * plugin still works on servers that ship only one of the two imaging extensions.
     *
     * @since  1.0.0
     * @since  1.3.0  Imagick fallback for raster sources.
     */
    private function processRaster(string $sourcePath, string $dirFs, string $dirUrl, string $hash): ?array
    {
        if (!$this->hasCompleteRasterCache($dirFs)) {
            $tmp = $this->beginBuild(\dirname($dirFs), $hash);

            if ($tmp === null) {
                return null;
            }

            $icoFile   = $tmp . '/favicon.ico';
            $appleFile = $tmp . '/apple-touch-icon.png';

            if (
                !$this->generateRasterSetGd($sourcePath, $tmp, $icoFile, $appleFile)
                && !$this->generateRasterSetImagick($sourcePath, $tmp, $icoFile, $appleFile)
            ) {
                $this->discardBuild($tmp);

                return null;
            }

            if (!$this->publishBuild($tmp, $dirFs, fn (string $dir): bool => $this->hasCompleteRasterCache($dir))) {
                return null;
            }
        }

        return array_merge(
            ['hash' => $hash, 'type' => 'raster'],
            $this->buildRasterUrls($dirUrl)
        );
    }

    /**
     * Generates the raster icon set using GD. Returns false (without side effects that
     * matter) when GD is unavailable or cannot decode the source, so the caller can
     * fall back to Imagick.
     *
     * @since  1.3.0
     */
    private function generateRasterSetGd(string $sourcePath, string $dirFs, string $icoFile, string $appleFile): bool
    {
        if (!\function_exists('imagecreatefromstring')) {
            $this->errors[] = 'GD: extension not available';

            return false;
        }

        $this->clearLastError();
        $data = @file_get_contents($sourcePath);

        if ($data === false) {
            $this->recordError('GD: cannot read source ' . basename($sourcePath));

            return false;
        }

        $this->clearLastError();
        $src = @imagecreatefromstring($data);

        if (!$src) {
            $this->recordError('GD: cannot decode source ' . basename($sourcePath));

            return false;
        }

        try {
            $pngFiles = [];

            foreach (self::SIZES as $size) {
                $resized = $this->resize($src, $size, $size);
                $file    = $dirFs . '/icon-' . $size . '.png';
                $this->clearLastError();
                $ok      = imagepng($resized, $file, 9);
                imagedestroy($resized);

                // A failed write (disk full, permissions, memory) must abort immediately
                // rather than leave a half-built set that later looks cached.
                if (!$ok) {
                    $this->recordError('GD: cannot write icon-' . $size . '.png');
                    imagedestroy($src);

                    return false;
                }

                $pngFiles[$size] = $file;
            }

            $apple = $this->resize($src, self::APPLE_SIZE, self::APPLE_SIZE);
            $this->clearLastError();
            $ok    = imagepng($apple, $appleFile, 9);
            imagedestroy($apple);

            if (!$ok) {
                $this->recordError('GD: cannot write apple-touch-icon.png');
                imagedestroy($src);

                return false;
            }

            $this->writeIco([$pngFiles[16], $pngFiles[32], $pngFiles[48]], $icoFile);
        } catch (\Throwable $e) {
            imagedestroy($src);

            return false;
        }

        imagedestroy($src);

        return $this->hasCompleteRasterCache($dirFs);
    }

    /**
     * Generates the raster icon set using Imagick — the fallback path for servers
     * without GD, or for sources GD refuses to decode.
     *
     * @since  1.3.0
     */
    private function generateRasterSetImagick(string $sourcePath, string $dirFs, string $icoFile, string $appleFile): bool
    {
        if (!\class_exists(\Imagick::class)) {
            $this->errors[] = 'Imagick: extension not available';

            return false;
        }

        try {
            $base = new \Imagick();
            $base->setBackgroundColor(new \ImagickPixel('transparent'));
            $base->readImage($sourcePath);

            $pngFiles = [];

            foreach (self::SIZES as $size) {
                $file = $dirFs . '/icon-' . $size . '.png';
                $this->writeImagickThumb($base, $size, $file);
                $pngFiles[$size] = $file;
            }

            $this->writeImagickThumb($base, self::APPLE_SIZE, $appleFile);

            $base->clear();
            $base->destroy();

            $this->writeIco([$pngFiles[16], $pngFiles[32], $pngFiles[48]], $icoFile);
        } catch (\Throwable $e) {
            $this->errors[] = 'Imagick: ' . $e->getMessage();

            return false;
        }

        return $this->hasCompleteRasterCache($dirFs);
    }

    /**
     * Writes one square PNG thumbnail from an Imagick source, preserving transparency.
     *
     * @since  1.3.0
     */
    private function writeImagickThumb(\Imagick $base, int $size, string $outFile): void
    {
        $copy = clone $base;
        $copy->setImageBackgroundColor(new \ImagickPixel('transparent'));
        // bestfit + fill: scale within the box keeping the aspect ratio, then pad out to
        // the exact square with transparency instead of stretching the image.
        $copy->thumbnailImage($size, $size, true, true);
        $copy->setImageFormat('png32');
        $copy->writeImage($outFile);
        $copy->clear();
        $copy->destroy();
    }

    /**
     * Scales an image into the target box WITHOUT distorting it ("contain" fit): the
     * source keeps its aspect ratio and is centred, with the leftover area transparent.
     *
     * Stretching a non-square logo into a square icon visibly deforms it; padding it
     * instead is always the lesser evil. For a square source - the recommended case -
     * this is identical to a plain resize.
     *
     * @since  1.0.0
     * @since  1.6.0  Aspect ratio is preserved instead of stretched.
     */
    private function resize(\GdImage $src, int $width, int $height): \GdImage
    {
        $sw  = imagesx($src);
        $sh  = imagesy($src);
        $dst = imagecreatetruecolor($width, $height);

        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $width, $height, $transparent);

        [$dw, $dh] = $this->containSize($sw, $sh, $width, $height);
        $dx = (int) round(($width - $dw) / 2);
        $dy = (int) round(($height - $dh) / 2);

        imagealphablending($dst, true);
        imagecopyresampled($dst, $src, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);

        return $dst;
    }

    /**
     * Returns the largest [width, height] that fits inside the given box while keeping
     * the source aspect ratio.
     *
     * @since  1.6.0
     */
    private function containSize(int $srcW, int $srcH, int $boxW, int $boxH): array
    {
        if ($srcW < 1 || $srcH < 1) {
            return [$boxW, $boxH];
        }

        $scale = min($boxW / $srcW, $boxH / $srcH);

        return [
            max(1, (int) round($srcW * $scale)),
            max(1, (int) round($srcH * $scale)),
        ];
    }

    /**
     * Builds a modern multi-resolution favicon.ico by embedding PNG images
     * directly in the ICO container (valid since Windows Vista, supported by
     * all current browsers).
     *
     * @param   string[]  $pngFiles  Absolute paths to the 16/32/48 px PNG files, in order.
     * @param   string    $outFile   Absolute path to write the resulting .ico file to.
     *
     * @since   1.0.0
     */
    private function writeIco(array $pngFiles, string $outFile): void
    {
        $images = [];

        foreach ($pngFiles as $file) {
            if (!is_file($file)) {
                continue;
            }

            $data = file_get_contents($file);
            $size = getimagesizefromstring($data);

            if (!$size) {
                continue;
            }

            $images[] = ['data' => $data, 'w' => $size[0], 'h' => $size[1]];
        }

        if (!$images) {
            return;
        }

        $count  = count($images);
        $header = pack('vvv', 0, 1, $count);
        $dir    = '';
        $data   = '';
        $offset = 6 + ($count * 16);

        foreach ($images as $image) {
            $w    = $image['w'] >= 256 ? 0 : $image['w'];
            $h    = $image['h'] >= 256 ? 0 : $image['h'];
            $size = strlen($image['data']);

            $dir  .= pack('CCCCvvVV', $w, $h, 0, 0, 1, 32, $size, $offset);
            $data .= $image['data'];
            $offset += $size;
        }

        $this->clearLastError();

        if (@file_put_contents($outFile, $header . $dir . $data) === false) {
            $this->recordError('cannot write favicon.ico');
            // Leave no half-written ICO behind for the completeness check to trust.
            @unlink($outFile);
        }
    }
}
