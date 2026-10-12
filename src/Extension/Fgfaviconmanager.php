<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.Fgfaviconmanager
 *
 * @copyright   Copyright (C) 2026. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace FG\Plugin\System\Fgfaviconmanager\Extension;

use Joomla\CMS\Event\Result\ResultAwareInterface;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use FG\Plugin\System\Fgfaviconmanager\Helper\IconGenerator;

defined('_JEXEC') or die;

/**
 * System plugin that injects a fully custom favicon set (frontend, administrator,
 * dark-mode variant, Apple touch icon, PWA manifest, theme-color) into every page,
 * without requiring any template edits, and strips the template's own favicon tags.
 *
 * @since  1.0.0
 */
class Fgfaviconmanager extends CMSPlugin implements SubscriberInterface
{
    /**
     * Load the plugin language file automatically.
     *
     * @var    boolean
     * @since  1.0.0
     */
    protected $autoloadLanguage = true;

    /**
     * The generator used for the current request, kept so debug mode can read its
     * collected failure reasons after ALL generation (including maskable icons) is done.
     *
     * @var    IconGenerator|null
     * @since  1.7.1
     */
    private ?IconGenerator $generator = null;

    /**
     * Relative (from JPATH_ROOT) path of the on-disk / public cache folder.
     *
     * @var  string
     * @since  1.0.0
     */
    private const CACHE_RELATIVE = 'media/plg_system_fgfaviconmanager/cache';

    /**
     * @return  array<string, string>
     *
     * @since   1.0.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterRender'        => 'injectFavicons',
            'onExtensionAfterSave' => 'validateSources',
            'onAjaxFgfaviconmanager' => 'onAjaxFgfaviconmanager',
        ];
    }

    /**
     * Joomla 6's media form field stores extra metadata appended after a "#" fragment,
     * e.g. "images/logo.png#joomlaImage://local-images/logo.png?width=54&height=117".
     * Only the part before "#" is an actual filesystem path.
     *
     * @since  1.0.2
     */
    private function sanitizeMediaValue(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $hashPos = strpos($value, '#');

        if ($hashPos !== false) {
            $value = substr($value, 0, $hashPos);
        }

        return trim($value);
    }

    /**
     * Rewrites the final rendered HTML: strips template-provided favicon tags (optional)
     * and injects the configured favicon/icon/manifest/theme-color markup before </head>.
     *
     * Runs on both the site and administrator applications since onAfterRender fires
     * for both. Fails silently on any error so a misconfiguration can never break a page.
     *
     * @param   Event  $event  The event.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function injectFavicons(Event $event): void
    {
        $app = $this->getApplication();

        if ($app === null || !method_exists($app, 'getBody') || !method_exists($app, 'setBody')) {
            return;
        }

        $isAdmin = $app->isClient('administrator');
        $debug   = (int) $this->params->get('debug_mode', 0) === 1;

        // Cheapest possible check first, before touching the response body at all: on a
        // very-high-traffic site this method runs on every single page view, so an
        // unconfigured plugin (no source set yet, or the admin source cleared) should
        // cost next to nothing. This also fixes a latent correctness bug: stripping used
        // to run unconditionally, BEFORE this check existed, so a freshly installed but
        // not-yet-configured plugin (strip mode defaults to "soft") would remove the
        // template's own favicon and provide no replacement, leaving the site without
        // any favicon at all until a source was configured.
        if (!$this->hasAnySourceConfigured($isAdmin)) {
            if ($debug) {
                $this->injectDebugComment(
                    $app,
                    'Skipped: no favicon source configured for this context (isAdmin=' . ($isAdmin ? '1' : '0') . ').'
                );
            }

            return;
        }

        // Strip competing tags FIRST, so the foreign-manifest detection below reflects
        // what will actually remain in the document (aggressive stripping removes it).
        $body      = $app->getBody();
        $stripMode = $this->getStripMode();

        if ($stripMode !== 'off') {
            $body = $this->stripDefaultFavicons($body, $stripMode);
        }

        $foreignManifest = $this->hasManifestLink($body);

        // The assembled <link>/<meta> block is identical for every visitor until the
        // plugin's settings or a source file change - only whether a foreign manifest is
        // present on THIS page can vary it further. Rather than re-resolving paths,
        // re-checking icon cache completeness and rebuilding the string on every request,
        // a small on-disk cache keyed by everything that can affect the output lets a
        // high-traffic site skip straight to the cached string. Debug mode always
        // bypasses this, so its diagnostics stay live and never claim a fresh generation
        // that did not actually happen.
        $cacheKey = null;

        if (!$debug) {
            $cacheKey = $this->computeMarkupCacheKey($isAdmin, $foreignManifest);
            $head     = $cacheKey !== null ? $this->readMarkupCache($isAdmin, $cacheKey) : null;
        } else {
            $head = null;
        }

        $cacheHit = $head !== null;

        if ($head === null) {
            try {
                $head = $this->buildHeadMarkup($isAdmin, $foreignManifest);
            } catch (\Throwable $e) {
                if ($debug) {
                    $this->injectDebugComment($app, 'EXCEPTION: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
                }

                return;
            }

            if ($head === '') {
                if ($debug) {
                    $this->injectDebugComment($app, 'buildHeadMarkup() returned empty string (no source configured, or icon generation failed).');
                }

                return;
            }

            if (!$debug && $cacheKey !== null) {
                $this->writeMarkupCache($isAdmin, $cacheKey, $head);
            }
        }

        if ($debug) {
            $reasons = $this->generator ? $this->generator->getErrors() : [];
            $notes   = $reasons ? ' | notes: ' . implode(' ;; ', $reasons) : '';

            $head .= "\n<!-- Fgfaviconmanager DEBUG: markup " . ($cacheHit ? 'served from cache' : 'generated fresh') . ' ('
                . substr_count($head, '<link') . ' links, ' . substr_count($head, '<meta') . ' metas), stripMode='
                . $stripMode . ', foreignManifest=' . ($foreignManifest ? 'yes' : 'no') . $notes . ' -->';
        }

        if (stripos($body, '</head>') !== false) {
            $body = preg_replace('#</head>#i', $head . "\n</head>", $body, 1);
            $app->setBody($body);
        }
    }

    /**
     * Cheap pre-check: is there any source configured that would produce output at all?
     * Avoids the body-stripping regexes and the full markup build entirely when not -
     * the common case for a just-installed, not-yet-configured plugin.
     *
     * @since  1.11.0
     */
    private function hasAnySourceConfigured(bool $isAdmin): bool
    {
        $main = $this->sanitizeMediaValue((string) $this->params->get('favicon_source', ''));

        if ($isAdmin) {
            $admin = $this->sanitizeMediaValue((string) $this->params->get('admin_favicon_source', ''));

            return $main !== '' || $admin !== '';
        }

        return $main !== '';
    }

    /**
     * Builds the cache key for the assembled <link>/<meta> markup: every plugin option
     * that affects the OUTPUT (not the ones only affecting body-stripping, which is a
     * separate step), plus each configured source file's resolved path, mtime and size, and the site's
     * root URL / base path, so replacing or swapping an
     * image automatically invalidates the cached markup - no manual step needed for the
     * common case. Returns null if a configured source cannot be resolved (path escapes
     * JPATH_ROOT, missing, etc.) so the caller falls through to the normal build/error path.
     *
     * Shares the same mtime+size limitation as the icon file cache (see
     * IconGenerator::versionHash()): a restore that preserves both won't be detected here
     * either. The existing "Clear Icon Cache" button already covers this cache too, since
     * it deletes every top-level entry under the cache folder, including these files.
     *
     * @since  1.11.0
     */
    private function computeMarkupCacheKey(bool $isAdmin, bool $foreignManifest): ?string
    {
        $outputParams = [
            'theme_color', 'theme_color_dark', 'admin_theme_color', 'admin_use_dark_mode',
            'manifest_mode', 'enable_pwa_manifest', 'manifest_name', 'manifest_short_name', 'manifest_background_color',
            'enable_maskable', 'maskable_padding', 'maskable_background',
            'apple_web_app_capable', 'apple_status_bar_style', 'apple_web_app_title',
            'enable_apple_splash', 'apple_splash_background',
        ];

        $app = $this->getApplication();

        $bits   = [$isAdmin ? 'admin' : 'site', 'foreignManifest=' . ($foreignManifest ? '1' : '0')];
        $bits[] = 'sitename=' . ($app !== null ? (string) $app->get('sitename', '') : '');

        // The cached markup embeds absolute/root-relative icon URLs and (via the manifest)
        // id / start_url / scope, so a domain change or a move into/out of a subfolder must
        // never serve the old output.
        $bits[] = 'root=' . Uri::root();
        $bits[] = 'base=' . Uri::root(true);

        foreach ($outputParams as $key) {
            $bits[] = $key . '=' . (string) $this->params->get($key, '');
        }

        foreach (['favicon_source', 'favicon_dark_source', 'admin_favicon_source', 'apple_touch_icon'] as $key) {
            $raw = $this->sanitizeMediaValue((string) $this->params->get($key, ''));

            if ($raw === '') {
                $bits[] = $key . ':none';

                continue;
            }

            $abs = $this->resolveSourcePath($raw);

            if ($abs === null) {
                // Configured but unresolvable - do not cache a state we cannot represent
                // reliably; let the normal build path run and produce its own diagnostics.
                return null;
            }

            // Defensive against PHP's per-process stat cache: irrelevant for the typical
            // one-process-per-request hosting this plugin targets, but costs one syscall
            // and removes any risk of a stale mtime under a persistent PHP worker model.
            clearstatcache(true, $abs);

            // The resolved path is part of the key: switching a slot from file A to file B
            // must invalidate the cache even when both files happen to share size and mtime
            // (e.g. exported together by the same tool or restored from one backup).
            $bits[] = $key . ':' . $abs . ':' . filemtime($abs) . ':' . filesize($abs);
        }

        return md5(implode('|', $bits));
    }

    /**
     * Reads the cached markup for the given key, if present. The cache file holds a small
     * array of recent {key, html} entries (not just one) because $foreignManifest - part
     * of the key - can genuinely differ between pages on the same site (e.g. only some
     * pages carry a manifest link from an unrelated extension); a single-entry cache would
     * otherwise thrash between two states instead of caching either one.
     *
     * @since  1.11.0
     */
    private function readMarkupCache(bool $isAdmin, string $key): ?string
    {
        $file = $this->markupCacheFile($isAdmin);

        if (!is_file($file)) {
            return null;
        }

        $json = @file_get_contents($file);
        $data = $json !== false ? json_decode($json, true) : null;

        if (!\is_array($data)) {
            return null;
        }

        foreach ($data as $entry) {
            if (\is_array($entry) && ($entry['key'] ?? null) === $key && isset($entry['html'])) {
                return (string) $entry['html'];
            }
        }

        return null;
    }

    /**
     * Stores a markup cache entry, keeping at most the 4 most recently used variants
     * (see readMarkupCache() for why more than one may legitimately be needed) and
     * writing via a temp-file-then-rename so a concurrent read never sees a partial file.
     *
     * @since  1.11.0
     */
    private function writeMarkupCache(bool $isAdmin, string $key, string $html): void
    {
        $file = $this->markupCacheFile($isAdmin);
        $dir  = \dirname($file);

        if (!is_dir($dir) && !IconGenerator::makeDir($dir)) {
            return;
        }

        $existing = [];
        $current  = is_file($file) ? @file_get_contents($file) : false;
        $decoded  = $current !== false ? json_decode($current, true) : null;

        if (\is_array($decoded)) {
            foreach ($decoded as $entry) {
                if (\is_array($entry) && ($entry['key'] ?? null) !== $key) {
                    $existing[] = $entry;
                }
            }
        }

        array_unshift($existing, ['key' => $key, 'html' => $html]);
        $existing = \array_slice($existing, 0, 4);

        $json = json_encode($existing, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            return;
        }

        $tmp = $file . '.tmp-' . mt_rand(100000, 999999);

        if (@file_put_contents($tmp, $json) !== false) {
            @rename($tmp, $file);
        } else {
            @unlink($tmp);
        }
    }

    /**
     * Absolute path of the markup cache file for the given client.
     *
     * @since  1.11.0
     */
    private function markupCacheFile(bool $isAdmin): string
    {
        return JPATH_ROOT . '/' . self::CACHE_RELATIVE . '/markup-' . ($isAdmin ? 'admin' : 'site') . '.json';
    }

    /**
     * Resolves the effective strip mode: off | soft | aggressive.
     *
     * Falls back to the pre-1.2.0 boolean option (remove_default_favicons) when the
     * newer strip_mode param has not been saved yet, so upgrading keeps existing behaviour.
     *
     * @since  1.2.0
     */
    private function getStripMode(): string
    {
        $mode = strtolower(trim((string) $this->params->get('strip_mode', '')));

        if (\in_array($mode, ['off', 'soft', 'aggressive'], true)) {
            return $mode;
        }

        // Legacy fallback: old yes/no switch mapped onto the closest new behaviour.
        return (int) $this->params->get('remove_default_favicons', 1) === 1 ? 'soft' : 'off';
    }

    /**
     * Resolves the effective manifest mode: off | generate | defer.
     *
     * Falls back to the pre-1.2.0 boolean option (enable_pwa_manifest) when the newer
     * manifest_mode param has not been saved yet.
     *
     * @since  1.2.0
     */
    private function getManifestMode(): string
    {
        $mode = strtolower(trim((string) $this->params->get('manifest_mode', '')));

        if (\in_array($mode, ['off', 'generate', 'defer'], true)) {
            return $mode;
        }

        return (int) $this->params->get('enable_pwa_manifest', 1) === 1 ? 'generate' : 'off';
    }

    /**
     * Resolves a media-field value into an absolute filesystem path, guaranteed to sit
     * inside JPATH_ROOT.
     *
     * The value comes from this plugin's own saved params, chosen by an administrator
     * through the Media Manager picker - not attacker-controlled input in the usual
     * sense. Still, resolving it with plain string concatenation and trusting it is a
     * needless assumption: a value such as "../../../../etc/passwd" (however it got
     * there - a hand-edited params blob, a config restored from another site, a bug
     * elsewhere) would be read and copied into a publicly served cache folder.
     *
     * realpath() collapses ".." segments and symlinks and returns false for anything
     * that does not exist, so the containment check below is against the actual
     * resolved location, not the literal string.
     *
     * @return  string|null  Absolute path, or null when missing or escaping JPATH_ROOT.
     *
     * @since   1.7.2
     */
    private function resolveSourcePath(string $relative): ?string
    {
        $relative = ltrim($relative, '/');

        if ($relative === '') {
            return null;
        }

        $resolved = realpath(JPATH_ROOT . '/' . $relative);
        $rootReal = realpath(JPATH_ROOT);

        if ($resolved === false || $rootReal === false) {
            return null;
        }

        // Require the resolved path to be JPATH_ROOT itself or a genuine descendant of
        // it (root + separator prefix) - a prefix-only string check would wrongly accept
        // a sibling directory that merely starts with the same characters, e.g. JPATH_ROOT
        // "/var/www/site" matching "/var/www/site-other".
        if ($resolved !== $rootReal && strpos($resolved, $rootReal . \DIRECTORY_SEPARATOR) !== 0) {
            return null;
        }

        if (!is_file($resolved)) {
            return null;
        }

        return $resolved;
    }

    /**
     * Handles the "Clear icon cache" button on this plugin's own configuration screen,
     * dispatched via com_ajax (index.php?option=com_ajax&plugin=fgfaviconmanager&group=system).
     *
     * Exists because automatic cache invalidation is based on the source file's mtime and
     * size, not its content (see IconGenerator::versionHash() for why). That is reliable
     * for the normal Media Manager upload or FTP-overwrite flow, but not for a restore
     * that preserves both the timestamp and the file size - e.g. `rsync -a`, or a backup
     * restore tool that sets mtime back to the original. This button is the manual
     * fallback for that case.
     *
     * Under Joomla's modern event system a listener's plain `return` value is NOT what
     * com_ajax collects - the result has to be placed on the Event object itself, via
     * ResultAwareInterface::addResult() (current convention) or the older
     * getArgument('result')/setArgument('result', ...) accumulator pattern, exactly as
     * Joomla's own "Ajax Plugin for Ad Hoc Jobs" example documents. Both are handled here
     * so this works whichever the running Joomla version expects; the return value is also
     * kept as a harmless no-op fallback for direct/unit-test invocation.
     *
     * Deliberately never throws: an uncaught exception here would make com_ajax return a
     * non-JSON error page, which the button's fetch() call cannot parse. Every outcome -
     * including auth or CSRF failure - is instead encoded in the result array's "ok" and
     * "error" keys, for the button's JS to display.
     *
     * @param   Event|null  $event  The AJAX event (null when called directly, e.g. tests).
     *
     * @return  array{ok: bool, removed?: int, error?: string}
     *
     * @since   1.9.0
     * @since   1.9.2  Deliver the result via the Event object, not just a return value.
     */
    public function onAjaxFgfaviconmanager(?Event $event = null): array
    {
        $app = $this->getApplication();

        if ($app === null || !$app->isClient('administrator')) {
            $result = ['ok' => false, 'error' => 'Forbidden'];
            $this->deliverAjaxResult($event, $result);

            return $result;
        }

        if (!Session::checkToken('request')) {
            $result = ['ok' => false, 'error' => 'Invalid or expired security token - please reload the page and try again.'];
            $this->deliverAjaxResult($event, $result);

            return $result;
        }

        $user = $app->getIdentity();

        if ($user === null || !$user->authorise('core.edit', 'com_plugins')) {
            $result = ['ok' => false, 'error' => 'You are not authorised to manage plugins.'];
            $this->deliverAjaxResult($event, $result);

            return $result;
        }

        try {
            $result = ['ok' => true, 'removed' => $this->clearIconCache()];
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'error' => $e->getMessage()];
        }

        $this->deliverAjaxResult($event, $result);

        return $result;
    }

    /**
     * Places an onAjax* handler's result where com_ajax's AjaxController actually looks
     * for it. Tries the current ResultAwareInterface::addResult() convention first, then
     * falls back to the older getArgument('result')/setArgument('result', ...) array
     * accumulator - see the class-level note on onAjaxFgfaviconmanager() for why both exist.
     *
     * @since  1.9.2
     */
    private function deliverAjaxResult(?Event $event, array $result): void
    {
        if ($event === null) {
            return;
        }

        if (
            \interface_exists(ResultAwareInterface::class)
            && $event instanceof ResultAwareInterface
        ) {
            $event->addResult($result);

            return;
        }

        if (method_exists($event, 'getArgument') && method_exists($event, 'setArgument')) {
            $existing   = $event->getArgument('result') ?? [];
            $existing[] = $result;
            $event->setArgument('result', $existing);
        }
    }

    /**
     * Deletes everything under the plugin's cache folder (all generated icon sets,
     * maskable variants, splash screens and manifests, plus any leftover staging
     * folders), but keeps the folder itself so it does not need re-creating.
     *
     * Unlike the automatic pruning elsewhere in the plugin, this is an explicit
     * administrator action, so it does not skip recently-touched staging folders -
     * it clears everything, unconditionally.
     *
     * @return  int  Number of top-level entries removed.
     *
     * @since   1.9.0
     */
    private function clearIconCache(): int
    {
        $cacheFs = JPATH_ROOT . '/' . self::CACHE_RELATIVE;

        if (!is_dir($cacheFs)) {
            return 0;
        }

        $removed = 0;

        foreach (new \DirectoryIterator($cacheFs) as $entry) {
            if ($entry->isDot()) {
                continue;
            }

            $path = $entry->getPathname();

            try {
                if ($entry->isDir()) {
                    if (IconGenerator::removeTree($path)) {
                        $removed++;
                    }
                } elseif (@unlink($path)) {
                    $removed++;
                }
            } catch (\Throwable $e) {
                // Best effort - keep clearing the rest even if one entry fails
                // (e.g. a permission issue on a single stray file).
            }
        }

        return $removed;
    }

    /**
     * After this plugin's own options are saved, checks the chosen master image(s) and
     * warns in the administrator when a source is likely to produce poor icons — not
     * square, or smaller than the largest generated size (512px), which would mean
     * upscaling a small bitmap into a blurry 512 icon.
     *
     * Warnings only; nothing is blocked, since an intentionally small or non-square
     * source is a legitimate (if suboptimal) choice.
     *
     * @param   Event  $event  The event.
     *
     * @return  void
     *
     * @since   1.2.0
     */
    public function validateSources(Event $event): void
    {
        $app = $this->getApplication();

        if ($app === null || !$app->isClient('administrator')) {
            return;
        }

        $args    = $event->getArguments();
        $context = $args['context'] ?? $args[0] ?? null;
        $table   = $args['subject'] ?? $args[1] ?? null;

        if ($context !== 'com_plugins.plugin' || !\is_object($table)) {
            return;
        }

        if (($table->element ?? '') !== 'fgfaviconmanager' || ($table->folder ?? '') !== 'system') {
            return;
        }

        $params = $table->params ?? '';

        if (\is_string($params)) {
            $params = json_decode($params, true);
        }

        if (!\is_array($params)) {
            return;
        }

        $labels = [
            'favicon_source'       => 'Favicon Source',
            'favicon_dark_source'  => 'Dark Mode Favicon',
            'admin_favicon_source' => 'Administrator Favicon',
            'apple_touch_icon'     => 'Apple Touch Icon',
        ];

        foreach ($labels as $key => $label) {
            $value = $this->sanitizeMediaValue((string) ($params[$key] ?? ''));

            if ($value === '') {
                continue;
            }

            $path = $this->resolveSourcePath($value);

            if ($path === null) {
                $app->enqueueMessage(
                    sprintf(
                        'FG Favicon Manager: "%s" — file not found, or the path escapes the site directory (%s).',
                        $label,
                        $value
                    ),
                    'warning'
                );

                continue;
            }

            // SVG is resolution independent, so neither check applies to it.
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'svg') {
                continue;
            }

            $info = @getimagesize($path);

            if (!$info) {
                continue;
            }

            [$width, $height] = $info;

            if ($width !== $height) {
                $app->enqueueMessage(
                    sprintf(
                        'FG Favicon Manager: "%s" is not square (%dx%d). It will be padded with transparent (or, for maskable icons, background-coloured) space to fit a square — crop it to a 1:1 ratio to use the full icon area instead.',
                        $label,
                        $width,
                        $height
                    ),
                    'notice'
                );
            }

            if (min($width, $height) < 512) {
                $app->enqueueMessage(
                    sprintf(
                        'FG Favicon Manager: "%s" is only %dx%d. Larger sizes (up to 512x512) will be upscaled and may look blurry — use a source of at least 512x512px.',
                        $label,
                        $width,
                        $height
                    ),
                    'notice'
                );
            }
        }

        $this->prewarmSplashScreens($params);
    }

    /**
     * Generates iOS splash screens right after Save rather than leaving them to be
     * built lazily on the next page view.
     *
     * Splash generation composites nine device-specific canvases (some multi-megapixel)
     * and, measured, takes over a second on first run. Every other asset this plugin
     * generates is cheap enough (tens of milliseconds) that paying it lazily on the next
     * page view is unnoticeable, but splash screens are the one exception worth avoiding:
     * an administrator saving settings already expects and tolerates a brief pause after
     * clicking Save, whereas a site visitor loading a page does not. Doing it here means
     * that cost is paid once, by the administrator, instead of by whichever visitor's
     * request happens to trigger the cache miss.
     *
     * Best-effort: any failure here is silently skipped and the same generation is
     * simply retried lazily on the next real page view, exactly as before this existed.
     *
     * @since  1.8.0
     */
    private function prewarmSplashScreens(array $params): void
    {
        if (
            (int) ($params['apple_web_app_capable'] ?? 0) !== 1
            || (int) ($params['enable_apple_splash'] ?? 0) !== 1
        ) {
            return;
        }

        $source = $this->sanitizeMediaValue((string) ($params['favicon_source'] ?? ''));
        $abs    = $source !== '' ? $this->resolveSourcePath($source) : null;

        if ($abs === null) {
            return;
        }

        $cacheFs  = JPATH_ROOT . '/' . self::CACHE_RELATIVE;
        $cacheUrl = rtrim(Uri::root(true), '/') . '/' . self::CACHE_RELATIVE;

        if (!is_dir($cacheFs)) {
            IconGenerator::makeDir($cacheFs);
        }

        $background = (string) ($params['apple_splash_background'] ?? '#ffffff');

        try {
            (new IconGenerator())->generateSplashScreens($abs, $cacheFs, $cacheUrl, $background);
        } catch (\Throwable $e) {
            // Fall through to lazy generation on the next page view.
        }
    }

    /**
     * Writes a diagnostic HTML comment right before </head> so the reason for a missing
     * favicon can be inspected via View Source, without needing server log access.
     * Only ever called when the "Debug Mode" plugin option is enabled.
     *
     * @since  1.0.1
     */
    private function injectDebugComment($app, string $reason): void
    {
        $body    = $app->getBody();
        $comment = "<!-- Fgfaviconmanager DEBUG: {$reason} -->";

        if (stripos($body, '</head>') !== false) {
            $body = preg_replace('#</head>#i', $comment . "\n</head>", $body, 1);
            $app->setBody($body);
        }
    }

    /**
     * Detects a web manifest link in <head>, using the same token-aware rel parsing as
     * the strip above (rel="manifest alternate" still counts) and the same head-only
     * scoping, so a manifest mentioned in page content never triggers "defer" mode.
     *
     * @since  1.5.1
     */
    private function hasManifestLink(string $body): bool
    {
        $headEnd = stripos($body, '</head>');
        $head    = $headEnd === false ? $body : substr($body, 0, $headEnd);

        if (!preg_match_all('#<link\b[^>]*>#i', $head, $matches)) {
            return false;
        }

        foreach ($matches[0] as $tag) {
            if (!preg_match('#\brel\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))#i', $tag, $attr)) {
                continue;
            }

            $rel = strtolower(trim(($attr[1] ?? '') . ($attr[2] ?? '') . ($attr[3] ?? '')));

            if (\in_array('manifest', preg_split('#\s+#', $rel, -1, PREG_SPLIT_NO_EMPTY) ?: [], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Removes favicon tags already present in the rendered document (typically hardcoded
     * by the active template, e.g. Helix Ultimate), at the requested strength:
     *
     * - soft:       only the classic favicon / apple-touch-icon links. A foreign
     *               mask-icon and, crucially, a foreign <link rel="manifest"> are left
     *               alone, so a dedicated PWA extension keeps owning the manifest.
     * - aggressive: also removes mask-icon and any foreign manifest link, so this plugin
     *               is the single source of truth for all icon-related tags.
     *
     * @since  1.0.0
     * @since  1.2.0  Added $mode.
     */
    private function stripDefaultFavicons(string $body, string $mode = 'soft'): string
    {
        if ($mode === 'off') {
            return $body;
        }

        // Per the HTML spec, rel is a SPACE-SEPARATED TOKEN LIST: rel="shortcut icon",
        // rel="alternate icon" and rel="icon shortcut" are all favicon links. Matching the
        // attribute value as one literal string would miss every form but the exact one,
        // so each tag's rel value is tokenised and compared token by token.
        $tokens = ['icon', 'apple-touch-icon', 'apple-touch-icon-precomposed'];

        if ($mode === 'aggressive') {
            $tokens[] = 'mask-icon';
            $tokens[] = 'manifest';
        }

        // Only ever touch <head>. Running this over the whole response would also rewrite
        // page CONTENT - e.g. an article showing <link rel="icon"> inside a code sample -
        // and would needlessly scan the entire document.
        $headEnd = stripos($body, '</head>');

        if ($headEnd === false) {
            return $body;
        }

        $head = substr($body, 0, $headEnd);
        $rest = substr($body, $headEnd);

        $stripped = preg_replace_callback(
            '#<link\b[^>]*>[ \t]*\r?\n?#i',
            static function (array $match) use ($tokens): string {
                // rel may be double-quoted, single-quoted or unquoted.
                if (!preg_match('#\brel\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))#i', $match[0], $attr)) {
                    return $match[0];
                }

                $rel       = strtolower(trim(($attr[1] ?? '') . ($attr[2] ?? '') . ($attr[3] ?? '')));
                $relTokens = preg_split('#\s+#', $rel, -1, PREG_SPLIT_NO_EMPTY) ?: [];

                return array_intersect($relTokens, $tokens) ? '' : $match[0];
            },
            $head
        );

        // preg_replace_callback returns null on failure (e.g. backtrack limit) - in that
        // case keep the original head rather than dropping it.
        return ($stripped ?? $head) . $rest;
    }

    /**
     * Builds the block of <link>/<meta> tags to inject, generating/caching icon
     * assets on demand via IconGenerator.
     *
     * @since  1.0.0
     */
    private function buildHeadMarkup(bool $isAdmin, bool $foreignManifest = false): string
    {
        $cacheFs  = JPATH_ROOT . '/' . self::CACHE_RELATIVE;
        $cacheUrl = rtrim(Uri::root(true), '/') . '/' . self::CACHE_RELATIVE;

        if (!is_dir($cacheFs)) {
            IconGenerator::makeDir($cacheFs);
        }

        $generator       = new IconGenerator();
        $this->generator = $generator;
        $lines           = [];

        $mainSource = $this->sanitizeMediaValue((string) $this->params->get('favicon_source', ''));
        $darkSource = $this->sanitizeMediaValue((string) $this->params->get('favicon_dark_source', ''));

        if ($isAdmin) {
            $adminSource = $this->sanitizeMediaValue((string) $this->params->get('admin_favicon_source', ''));

            if ($adminSource !== '') {
                $mainSource = $adminSource;
            }

            // Dark-mode variant is opt-in for the admin panel: it's the same file
            // configured under "Frontend Favicon" (favicon_dark_source), reused here
            // rather than duplicated as a separate admin-only setting. Off by default,
            // since pairing a custom admin icon with a dark variant designed to match the
            // FRONTEND icon may not always look right - left to the administrator to judge.
            if ((int) $this->params->get('admin_use_dark_mode', 0) !== 1) {
                $darkSource = '';
            }
        }

        if ($mainSource === '') {
            throw new \RuntimeException('No favicon_source configured in plugin params (isAdmin=' . ($isAdmin ? '1' : '0') . ').');
        }

        $absSourcePath = $this->resolveSourcePath($mainSource);

        if ($absSourcePath === null) {
            throw new \RuntimeException(
                "Refusing source '{$mainSource}': does not resolve to an existing path inside JPATH_ROOT "
                . '(missing file, or the value attempts to escape the site directory).'
            );
        }

        $mainSet = $generator->process($absSourcePath, $cacheFs, $cacheUrl);

        if (!$mainSet) {
            $gdOk     = \function_exists('imagecreatefromstring') ? 'yes' : 'no';
            $imagick  = \class_exists(\Imagick::class) ? 'yes' : 'no';
            $cacheDir = is_dir($cacheFs) && is_writable($cacheFs) ? 'yes' : 'NO (check permissions on ' . $cacheFs . ')';

            // Concrete reasons recovered from suppressed warnings - far more actionable
            // than "generation failed" ("Permission denied", "No space left on device").
            $reasons = $generator->getErrors();
            $detail  = $reasons ? ' | reasons: ' . implode(' ;; ', $reasons) : '';

            throw new \RuntimeException(
                "Icon generation failed. source='{$mainSource}' resolvedPath='{$absSourcePath}' "
                . "gd={$gdOk} imagick={$imagick} cacheWritable={$cacheDir}{$detail}"
            );
        }

        $darkSet = $darkSource !== ''
            ? $this->resolveSourcePath($darkSource)
            : null;
        $darkSet = $darkSet !== null ? $generator->process($darkSet, $cacheFs, $cacheUrl) : null;

        if ($mainSet['type'] === 'svg') {
            $lines[] = '<link rel="icon" type="image/svg+xml" href="' . htmlspecialchars($mainSet['svg']) . '">';

            // Additive PNG/ICO fallback for browsers without SVG favicon support,
            // only present when Imagick successfully rasterised the SVG.
            if (isset($mainSet['png'], $mainSet['ico'])) {
                $mediaLight = $darkSet ? ' media="(prefers-color-scheme: light)"' : '';

                $lines[] = '<link rel="icon" href="' . htmlspecialchars($mainSet['ico']) . '"' . $mediaLight . '>';

                foreach ($mainSet['png'] as $size => $url) {
                    $lines[] = '<link rel="icon" type="image/png" sizes="' . $size . 'x' . $size . '"'
                        . $mediaLight . ' href="' . htmlspecialchars($url) . '">';
                }
            }

            $appleUrl = $mainSet['apple'] ?? null;

            if (!$isAdmin) {
                $appleSource = $this->sanitizeMediaValue((string) $this->params->get('apple_touch_icon', ''));

                if ($appleSource !== '') {
                    $appleAbs = $this->resolveSourcePath($appleSource);
                    $appleSet = $appleAbs !== null ? $generator->process($appleAbs, $cacheFs, $cacheUrl) : null;

                    if ($appleSet && isset($appleSet['apple'])) {
                        $appleUrl = $appleSet['apple'];
                    }
                }
            }

            // Only emit apple-touch-icon if we actually have a raster image for it
            // (pure SVG with no Imagick fallback and no manual override -> omit the tag,
            // iOS will fall back to a screenshot rather than get a broken image link).
            if ($appleUrl !== null) {
                $lines[] = '<link rel="apple-touch-icon" sizes="180x180" href="' . htmlspecialchars($appleUrl) . '">';
            }
        } else {
            $mediaLight = $darkSet ? ' media="(prefers-color-scheme: light)"' : '';

            $lines[] = '<link rel="icon" href="' . htmlspecialchars($mainSet['ico']) . '"' . $mediaLight . '>';

            foreach ($mainSet['png'] as $size => $url) {
                $lines[] = '<link rel="icon" type="image/png" sizes="' . $size . 'x' . $size . '"'
                    . $mediaLight . ' href="' . htmlspecialchars($url) . '">';
            }

            $appleUrl = $mainSet['apple'];

            if (!$isAdmin) {
                $appleSource = $this->sanitizeMediaValue((string) $this->params->get('apple_touch_icon', ''));

                if ($appleSource !== '') {
                    $appleAbs = $this->resolveSourcePath($appleSource);
                    $appleSet = $appleAbs !== null ? $generator->process($appleAbs, $cacheFs, $cacheUrl) : null;

                    if ($appleSet && $appleSet['type'] === 'raster') {
                        $appleUrl = $appleSet['apple'];
                    }
                }
            }

            $lines[] = '<link rel="apple-touch-icon" sizes="180x180" href="' . htmlspecialchars($appleUrl) . '">';
        }

        if ($darkSet) {
            if ($darkSet['type'] === 'svg') {
                $lines[] = '<link rel="icon" type="image/svg+xml" media="(prefers-color-scheme: dark)" href="'
                    . htmlspecialchars($darkSet['svg']) . '">';
            } else {
                $lines[] = '<link rel="icon" media="(prefers-color-scheme: dark)" href="'
                    . htmlspecialchars($darkSet['ico']) . '">';

                foreach ($darkSet['png'] as $size => $url) {
                    $lines[] = '<link rel="icon" type="image/png" sizes="' . $size . 'x' . $size
                        . '" media="(prefers-color-scheme: dark)" href="' . htmlspecialchars($url) . '">';
                }
            }
        }

        if ($isAdmin) {
            // Administrator gets its own optional tint, so the backend chrome can be
            // visually distinct from the public site (handy across several sites).
            $adminThemeColor = trim((string) $this->params->get('admin_theme_color', ''));

            if ($adminThemeColor !== '') {
                $lines[] = '<meta name="theme-color" content="' . htmlspecialchars($adminThemeColor) . '">';
            }

            return implode("\n", $lines);
        }

        $themeColor     = trim((string) $this->params->get('theme_color', ''));
        $themeColorDark = trim((string) $this->params->get('theme_color_dark', ''));

        if ($themeColor !== '') {
            $mediaAttr = $themeColorDark !== '' ? ' media="(prefers-color-scheme: light)"' : '';
            $lines[]   = '<meta name="theme-color" content="' . htmlspecialchars($themeColor) . '"' . $mediaAttr . '>';
        }

        if ($themeColorDark !== '') {
            $lines[] = '<meta name="theme-color" content="' . htmlspecialchars($themeColorDark)
                . '" media="(prefers-color-scheme: dark)">';
        }

        // iOS treats a site added to the Home Screen as a "web app" only with this pair
        // of meta tags; without them it just opens as a Safari bookmark. This is an
        // explicit opt-in (default off) since it changes visible iOS behaviour (hides
        // the Safari chrome) rather than being purely additive like the tags above.
        if ((int) $this->params->get('apple_web_app_capable', 0) === 1) {
            $lines[] = '<meta name="mobile-web-app-capable" content="yes">';
            $lines[] = '<meta name="apple-mobile-web-app-capable" content="yes">';

            $statusBarStyle = (string) $this->params->get('apple_status_bar_style', 'default');

            if (!\in_array($statusBarStyle, ['default', 'black', 'black-translucent'], true)) {
                $statusBarStyle = 'default';
            }

            $lines[] = '<meta name="apple-mobile-web-app-status-bar-style" content="'
                . htmlspecialchars($statusBarStyle) . '">';

            $appTitle = trim((string) $this->params->get('apple_web_app_title', ''));

            if ($appTitle !== '') {
                // Recommended alongside the two tags above (controls the label under the
                // Home Screen icon); not one of the three originally asked for, but comes
                // essentially free once "capable" is on, so it is offered as an optional field.
                $lines[] = '<meta name="apple-mobile-web-app-title" content="' . htmlspecialchars($appTitle) . '">';
            }

            if ((int) $this->params->get('enable_apple_splash', 0) === 1) {
                $splashBg = (string) $this->params->get('apple_splash_background', '#ffffff');
                $splashes = $generator->generateSplashScreens($absSourcePath, $cacheFs, $cacheUrl, $splashBg);

                foreach ((array) $splashes as $splash) {
                    $media = '(device-width: ' . $splash['width'] . 'px) and (device-height: ' . $splash['height'] . 'px)'
                        . ' and (-webkit-device-pixel-ratio: ' . $splash['scale'] . ') and (orientation: portrait)';
                    $lines[] = '<link rel="apple-touch-startup-image" href="' . htmlspecialchars($splash['url'])
                        . '" media="' . $media . '">';
                }
            }
        }

        $manifestMode = $this->getManifestMode();

        // "defer" = another extension already owns the web manifest; stay out of its way
        // (we still handle every favicon / Apple / theme-color tag above).
        if ($manifestMode === 'defer' && $foreignManifest) {
            return implode("\n", $lines);
        }

        if ($manifestMode !== 'off' && isset($mainSet['png'])) {
            $maskable = null;

            if ((int) $this->params->get('enable_maskable', 1) === 1) {
                $maskable = $generator->processMaskable(
                    $absSourcePath,
                    $cacheFs,
                    $cacheUrl,
                    (int) $this->params->get('maskable_padding', 12),
                    (string) $this->params->get('maskable_background', '#ffffff')
                );
            }

            $manifestUrl = $this->ensureManifest($mainSet, $cacheFs, $cacheUrl, $themeColor, $maskable);

            if ($manifestUrl) {
                $lines[] = '<link rel="manifest" href="' . htmlspecialchars($manifestUrl) . '">';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Writes (once, cached) a Web App Manifest referencing the generated PNG icon set,
     * for Android / PWA install support. When maskable icons are enabled they are added
     * with purpose "maskable" alongside the normal "any" icons, so Android adaptive
     * shapes use the padded variant while everything else uses the full-bleed one.
     *
     * The cache filename includes a hash of everything that affects the manifest's
     * CONTENT (names, colours, maskable set), not just the source image — otherwise
     * changing e.g. the safe-zone padding would keep serving a stale manifest.
     *
     * @since  1.0.0
     * @since  1.2.0  Maskable icon support + content-aware cache key.
     */
    private function ensureManifest(
        array $mainSet,
        string $cacheFs,
        string $cacheUrl,
        string $themeColor,
        ?array $maskable = null
    ): ?string {
        $app      = $this->getApplication();
        $siteName = $app !== null ? (string) $app->get('sitename', 'Website') : 'Website';

        $icons = array_map(
            static fn ($size, $url) => [
                'src'     => $url,
                'sizes'   => $size . 'x' . $size,
                'type'    => 'image/png',
                'purpose' => 'any',
            ],
            array_keys($mainSet['png']),
            array_values($mainSet['png'])
        );

        if ($maskable) {
            foreach ($maskable as $size => $url) {
                $icons[] = [
                    'src'     => $url,
                    'sizes'   => $size . 'x' . $size,
                    'type'    => 'image/png',
                    'purpose' => 'maskable',
                ];
            }
        }

        $root = Uri::root();

        $manifest = [
            'name'       => (string) $this->params->get('manifest_name', '') ?: $siteName,
            'short_name' => (string) $this->params->get('manifest_short_name', '') ?: $siteName,
            // "id" pins the app's identity. Without it browsers fall back to start_url,
            // so a later change to start_url would register as a DIFFERENT app for anyone
            // who already installed it. Setting it explicitly to the same value keeps
            // existing installations intact while protecting against that.
            'id'         => $root,
            'start_url'  => $root,
            // "scope" is resolved against the MANIFEST's URL, and this manifest lives deep
            // under /media/..., not at the site root. A relative value would therefore
            // scope the app to the cache folder and push every real page out of scope, so
            // an absolute URL is required here - Uri::root() supplies one, and it stays
            // correct for subfolder installations too.
            'scope'      => $root,
            'display'    => 'standalone',
            'background_color' => (string) $this->params->get('manifest_background_color', '#ffffff'),
            'theme_color'      => $themeColor !== '' ? $themeColor : '#ffffff',
            'icons'            => $icons,
        ];
        // UNESCAPED_UNICODE keeps accented site names readable in the manifest
        // (e.g. "Žilina" instead of "\u017dilina").
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            return null;
        }

        $dirFs    = $cacheFs . '/' . $mainSet['hash'];
        $fileName = 'manifest-' . substr(md5($json), 0, 12) . '.webmanifest';
        $file     = $dirFs . '/' . $fileName;

        if (!is_file($file)) {
            if (!is_dir($dirFs) && !IconGenerator::makeDir($dirFs)) {
                return null;
            }

            if (!@file_put_contents($file, $json)) {
                return null;
            }

            // Drop superseded manifests in this folder (e.g. after changing the theme
            // colour or app name, where the source image - and so the folder - is
            // unchanged). Recently written ones are left alone in case a concurrent
            // request is still serving them.
            foreach ((array) @glob($dirFs . '/manifest-*.webmanifest') as $old) {
                if ($old !== $file && time() - (int) @filemtime($old) > 60) {
                    @unlink($old);
                }
            }
        }

        return $cacheUrl . '/' . $mainSet['hash'] . '/' . $fileName;
    }
}
