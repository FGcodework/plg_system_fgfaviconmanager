<?php

/**
 * PHPUnit bootstrap for plg_system_fgfaviconmanager.
 *
 * Loads the plugin's REAL source files (not a modified copy) outside a full Joomla
 * installation. Two things stand in the way of that:
 *
 * 1. Every plugin file starts with `defined('_JEXEC') or die;` - this constant is
 *    defined here before anything else runs.
 * 2. Fgfaviconmanager.php is declared as `class Fgfaviconmanager extends CMSPlugin
 *    implements SubscriberInterface`. PHP resolves an extends/implements clause
 *    immediately when the class is declared (unlike a method's parameter or return
 *    type, which is resolved lazily on first use), so CMSPlugin and SubscriberInterface
 *    have to exist as real classes/interfaces the moment this file is require()'d, even
 *    though the tests below never exercise real Joomla behaviour through them.
 *
 * IconGenerator.php needs neither: it's a plain class with no Joomla parent, so it's
 * required directly with no stubbing at all.
 *
 * These stubs are intentionally minimal - just enough surface for the class to load and
 * for the specific methods under test to run via Reflection (bypassing the constructor,
 * which needs a live Joomla dispatcher/container). They are not a general-purpose Joomla
 * test double library; do not assume more of them than is defined here.
 */

declare(strict_types=1);

namespace {

define('_JEXEC', 1);

// ---------------------------------------------------------------------------
// Fixture filesystem for resolveSourcePath() tests.
//
// A single temp directory tree, unique per test run (pid-suffixed) so parallel runs on
// the same machine never collide, cleaned up automatically when the process exits.
// JPATH_ROOT points at "webroot"; the sibling folders exist specifically to exercise the
// two containment-check edge cases a naive strpos($resolved, JPATH_ROOT) === 0 check
// would get wrong (see FgfaviconmanagerTest for the corresponding assertions):
//   - webroot-other/  shares "webroot" as a literal string PREFIX but is a different
//     directory entirely - must NOT be treated as being inside JPATH_ROOT.
//   - outside/        a plain sibling, for classic ../ traversal.
//   - webroot/images/link-outside.png  a symlink pointing outside JPATH_ROOT entirely.
// ---------------------------------------------------------------------------

$base = sys_get_temp_dir() . '/favicon_manager_tests_' . getmypid();

define('JPATH_ROOT', $base . '/webroot');

@mkdir(JPATH_ROOT . '/images/sub', 0777, true);
file_put_contents(JPATH_ROOT . '/images/logo.png', 'fixture');
file_put_contents(JPATH_ROOT . '/images/sub/deep.png', 'fixture');

@mkdir($base . '/outside', 0777, true);
file_put_contents($base . '/outside/passwd', "root:x:0:0\n");

@mkdir($base . '/webroot-other/images', 0777, true);
file_put_contents($base . '/webroot-other/images/sneaky.png', 'fixture');

@symlink($base . '/outside/passwd', JPATH_ROOT . '/images/link-outside.png');

register_shutdown_function(static function () use ($base): void {
    $it = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($it as $entry) {
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }

    @rmdir($base);
});

}

// ---------------------------------------------------------------------------
// Minimal Joomla stand-ins - see the class docblock above for why these exist.
// ---------------------------------------------------------------------------

namespace Joomla\CMS\Plugin {
    /**
     * Stand-in for Joomla\CMS\Plugin\CMSPlugin. Tests never call this constructor -
     * Fgfaviconmanager is instantiated via ReflectionClass::newInstanceWithoutConstructor()
     * - it exists purely so `extends CMSPlugin` resolves.
     */
    class CMSPlugin
    {
        protected $params;
        protected $app;

        public function getApplication()
        {
            return $this->app;
        }

        public function setApplication($app): void
        {
            $this->app = $app;
        }
    }
}

namespace Joomla\CMS\Uri {
    /**
     * Stand-in for Joomla\CMS\Uri\Uri - only root() is needed (markup cache key tests).
     * Tests change $root / $base to simulate a domain change or a move into a subfolder.
     */
    class Uri
    {
        public static string $root = 'https://example.test/';
        public static string $base = '';

        public static function root($pathOnly = false)
        {
            return $pathOnly ? self::$base : self::$root;
        }
    }
}

namespace Joomla\Event {
    /**
     * Stand-in for Joomla\Event\SubscriberInterface - only the method signature matters,
     * Fgfaviconmanager's real getSubscribedEvents() implementation is what's loaded.
     */
    interface SubscriberInterface
    {
        public static function getSubscribedEvents(): array;
    }

    /**
     * Stand-in for Joomla\Event\Event. Not touched by the methods under test in this
     * suite, but required for the `Event $event` parameter type on injectFavicons() and
     * onAjaxFgfaviconmanager() to resolve if anything ever calls them directly.
     */
    class Event
    {
        /** @var array<string, mixed> */
        private array $arguments;

        public function __construct(string $name = '', array $arguments = [])
        {
            $this->arguments = $arguments;
        }

        public function getArgument(string $name, $default = null)
        {
            return $this->arguments[$name] ?? $default;
        }

        public function setArgument(string $name, $value): void
        {
            $this->arguments[$name] = $value;
        }
    }
}

namespace {
    require __DIR__ . '/../src/Helper/IconGenerator.php';
    require __DIR__ . '/../src/Extension/Fgfaviconmanager.php';
}
