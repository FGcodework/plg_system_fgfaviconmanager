<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.Fgfaviconmanager
 *
 * @copyright   Copyright (C) 2026. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace FgfaviconmanagerTests;

use FG\Plugin\System\Fgfaviconmanager\Extension\Fgfaviconmanager;
use Joomla\CMS\Uri\Uri;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Covers Fgfaviconmanager::resolveSourcePath() - the path-traversal guard between a
 * saved media-field value and the filesystem. The two rejection cases that matter most
 * are the ones a naive `strpos($resolved, JPATH_ROOT) === 0` containment check gets
 * wrong: a symlink resolving outside JPATH_ROOT, and a sibling directory that merely
 * shares JPATH_ROOT's name as a literal string prefix. See tests/bootstrap.php for the
 * fixture filesystem these tests run against.
 *
 * @since  1.14.0
 */
final class FgfaviconmanagerTest extends TestCase
{
    private Fgfaviconmanager $plugin;

    protected function setUp(): void
    {
        // Bypasses CMSPlugin's real constructor (needs a live Joomla dispatcher and
        // params Registry) - resolveSourcePath() touches neither, only JPATH_ROOT and
        // the filesystem, so an uninitialised instance is sufficient.
        $this->plugin = (new ReflectionClass(Fgfaviconmanager::class))->newInstanceWithoutConstructor();
    }

    private function resolveSourcePath(string $relative): ?string
    {
        $ref = new ReflectionMethod(Fgfaviconmanager::class, 'resolveSourcePath');
        $ref->setAccessible(true);

        return $ref->invoke($this->plugin, $relative);
    }

    public function testAcceptsPlainRelativePath(): void
    {
        self::assertSame(realpath(JPATH_ROOT . '/images/logo.png'), $this->resolveSourcePath('images/logo.png'));
    }

    public function testAcceptsLeadingSlashSameAsWithout(): void
    {
        // Joomla's media field commonly hands back a value with a leading "/" - this
        // must resolve identically to the same path without one, not be mistaken for
        // an absolute filesystem path.
        $expected = realpath(JPATH_ROOT . '/images/logo.png');
        self::assertSame($expected, $this->resolveSourcePath('/images/logo.png'));
    }

    public function testAcceptsNestedSubfolder(): void
    {
        self::assertSame(realpath(JPATH_ROOT . '/images/sub/deep.png'), $this->resolveSourcePath('images/sub/deep.png'));
    }

    public function testRejectsClassicParentTraversal(): void
    {
        self::assertNull($this->resolveSourcePath('../outside/passwd'));
    }

    public function testRejectsDeepParentTraversal(): void
    {
        self::assertNull($this->resolveSourcePath('../../../../etc/passwd'));
    }

    public function testRejectsTraversalInMiddleOfPath(): void
    {
        self::assertNull($this->resolveSourcePath('images/../../outside/passwd'));
    }

    public function testRejectsSymlinkEscapingRoot(): void
    {
        // images/link-outside.png is a real symlink (see bootstrap.php) pointing at
        // $base/outside/passwd, i.e. genuinely outside JPATH_ROOT once resolved -
        // exactly what realpath() is relied on to catch.
        self::assertNull($this->resolveSourcePath('images/link-outside.png'));
    }

    public function testRejectsSiblingDirectoryWithSharedNamePrefix(): void
    {
        // JPATH_ROOT is ".../webroot"; "webroot-other" is a DIFFERENT directory that
        // merely starts with the same characters. A string-prefix-only containment
        // check (no trailing separator) would wrongly accept this.
        self::assertNull($this->resolveSourcePath('../webroot-other/images/sneaky.png'));
    }

    public function testRejectsEmptyValue(): void
    {
        self::assertNull($this->resolveSourcePath(''));
    }

    public function testRejectsMissingFile(): void
    {
        self::assertNull($this->resolveSourcePath('images/does-not-exist.png'));
    }

    public function testRejectsDirectoryGivenInsteadOfFile(): void
    {
        self::assertNull($this->resolveSourcePath('images/sub'));
    }

    // --- computeMarkupCacheKey(): everything that changes the output must change the key -----

    /** Builds the key for a plugin whose params are $params (unset options read as ''). */
    private function markupKey(array $params): ?string
    {
        $paramsObj = new class ($params) {
            public function __construct(private array $data)
            {
            }

            public function get(string $key, $default = null)
            {
                return $this->data[$key] ?? $default;
            }
        };

        $plugin = (new ReflectionClass(Fgfaviconmanager::class))->newInstanceWithoutConstructor();
        $prop   = new \ReflectionProperty(\Joomla\CMS\Plugin\CMSPlugin::class, 'params');
        $prop->setAccessible(true);
        $prop->setValue($plugin, $paramsObj);

        $ref = new ReflectionMethod(Fgfaviconmanager::class, 'computeMarkupCacheKey');
        $ref->setAccessible(true);

        return $ref->invoke($plugin, false, false);
    }

    protected function tearDown(): void
    {
        Uri::$root = 'https://example.test/';
        Uri::$base = '';
    }

    public function testMarkupKeyDiffersWhenSourceSwitchesToAnotherFileWithSameMtimeAndSize(): void
    {
        $a = JPATH_ROOT . '/images/same-a.png';
        $b = JPATH_ROOT . '/images/same-b.png';
        file_put_contents($a, 'identical-size-1');
        file_put_contents($b, 'identical-size-2');
        touch($a, 1700000000);
        touch($b, 1700000000);

        self::assertNotNull($this->markupKey(['favicon_source' => 'images/same-a.png']));
        self::assertNotSame(
            $this->markupKey(['favicon_source' => 'images/same-a.png']),
            $this->markupKey(['favicon_source' => 'images/same-b.png']),
            'same size + mtime but a different file must not reuse the cached markup'
        );
    }

    public function testMarkupKeyCoversEverySourceSlot(): void
    {
        $a = JPATH_ROOT . '/images/slot-a.png';
        $b = JPATH_ROOT . '/images/slot-b.png';
        file_put_contents($a, 'same-bytes-1');
        file_put_contents($b, 'same-bytes-2');
        touch($a, 1700000000);
        touch($b, 1700000000);

        foreach (['favicon_source', 'favicon_dark_source', 'admin_favicon_source', 'apple_touch_icon'] as $slot) {
            self::assertNotSame(
                $this->markupKey([$slot => 'images/slot-a.png']),
                $this->markupKey([$slot => 'images/slot-b.png']),
                $slot
            );
        }
    }

    public function testMarkupKeyDiffersWhenSiteMovesToAnotherDomain(): void
    {
        $params = ['favicon_source' => 'images/logo.png'];

        $before = $this->markupKey($params);
        Uri::$root = 'https://other-domain.test/';

        self::assertNotSame($before, $this->markupKey($params));
    }

    public function testMarkupKeyDiffersWhenSiteMovesIntoASubfolder(): void
    {
        $params = ['favicon_source' => 'images/logo.png'];

        $before    = $this->markupKey($params);
        Uri::$root = 'https://example.test/joomla/';
        Uri::$base = '/joomla';

        self::assertNotSame($before, $this->markupKey($params));
    }

    public function testMarkupKeyIsStableForUnchangedInput(): void
    {
        $params = ['favicon_source' => 'images/logo.png', 'theme_color' => '#ffffff'];

        self::assertSame($this->markupKey($params), $this->markupKey($params));
    }
}
