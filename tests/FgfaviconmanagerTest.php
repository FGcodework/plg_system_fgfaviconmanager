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
}
