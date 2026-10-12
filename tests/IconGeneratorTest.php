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

use FG\Plugin\System\Fgfaviconmanager\Helper\IconGenerator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Covers IconGenerator's pure, framework-independent logic: aspect-ratio-preserving
 * resize math (containSize), colour normalisation (normalizeColor), and the SVG safety
 * pre-scan (isSvgSafeToRasterize). All three are private, invoked here via Reflection -
 * they're implementation details, not a public API, but exactly the kind of logic where
 * a subtle regression (an off-by-one, a dropped edge case) would otherwise only surface
 * as a visibly wrong icon or a missed security check, not a PHP error.
 *
 * @since  1.14.0
 */
final class IconGeneratorTest extends TestCase
{
    private IconGenerator $generator;

    /** @var string[] */
    private array $fixtureFiles = [];

    protected function setUp(): void
    {
        $this->generator = new IconGenerator();
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtureFiles as $file) {
            @unlink($file);
        }

        $this->fixtureFiles = [];
    }

    /**
     * @param  mixed[]  $args
     *
     * @return mixed
     */
    private function callPrivate(string $method, array $args)
    {
        $ref = new ReflectionMethod(IconGenerator::class, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($this->generator, $args);
    }

    private function svgFixture(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'favicon_svg_') . '.svg';
        file_put_contents($path, $contents);
        $this->fixtureFiles[] = $path;

        return $path;
    }

    // -------------------------------------------------------------------
    // containSize() - "contain" fit: scale to fit inside a box, aspect preserved.
    // -------------------------------------------------------------------

    public function testContainSizeShrinksWideSourceLimitedByWidth(): void
    {
        // 1000x500 (2:1) into 512x512: scale = min(512/1000, 512/500) = 0.512 -> width-bound.
        [$w, $h] = $this->callPrivate('containSize', [1000, 500, 512, 512]);
        self::assertSame(512, $w);
        self::assertSame(256, $h);
    }

    public function testContainSizeShrinksTallSourceLimitedByHeight(): void
    {
        [$w, $h] = $this->callPrivate('containSize', [500, 1000, 512, 512]);
        self::assertSame(256, $w);
        self::assertSame(512, $h);
    }

    public function testContainSizeIsNoOpForAlreadySquareSource(): void
    {
        [$w, $h] = $this->callPrivate('containSize', [800, 800, 512, 512]);
        self::assertSame(512, $w);
        self::assertSame(512, $h);
    }

    public function testContainSizeUpscalesSmallSourceToFillBox(): void
    {
        // A smaller-than-target square source still fills the target box exactly
        // (favicon generation always upscales up to the target sizes, never pads
        // a square source - only a non-square one gets letterboxed).
        [$w, $h] = $this->callPrivate('containSize', [64, 64, 512, 512]);
        self::assertSame(512, $w);
        self::assertSame(512, $h);
    }

    public function testContainSizeHandlesZeroDimensionSourceWithoutDivisionByZero(): void
    {
        [$w, $h] = $this->callPrivate('containSize', [0, 0, 512, 512]);
        self::assertSame(512, $w);
        self::assertSame(512, $h);
    }

    public function testContainSizeNeverProducesAZeroPixelDimension(): void
    {
        // An extreme aspect ratio must still round to at least 1px on the short side -
        // a 0-height result would be an invalid image and fail several GD/Imagick calls
        // downstream.
        [$w, $h] = $this->callPrivate('containSize', [10000, 1, 16, 16]);
        self::assertGreaterThanOrEqual(1, $w);
        self::assertGreaterThanOrEqual(1, $h);
    }

    // -------------------------------------------------------------------
    // normalizeColor() - canonical "#rrggbb" or null for transparent.
    // -------------------------------------------------------------------

    public function testNormalizeColorAcceptsHashPrefixedHex(): void
    {
        self::assertSame('#0b2a5b', $this->callPrivate('normalizeColor', ['#0b2a5b']));
    }

    public function testNormalizeColorAcceptsHexWithoutHash(): void
    {
        self::assertSame('#0b2a5b', $this->callPrivate('normalizeColor', ['0b2a5b']));
    }

    public function testNormalizeColorExpandsThreeDigitShorthand(): void
    {
        self::assertSame('#aabbcc', $this->callPrivate('normalizeColor', ['#abc']));
    }

    public function testNormalizeColorLowercasesInput(): void
    {
        self::assertSame('#aabbcc', $this->callPrivate('normalizeColor', ['ABC']));
        self::assertSame('#0b2a5b', $this->callPrivate('normalizeColor', ['#0B2A5B']));
    }

    public function testNormalizeColorTransparentLiteralReturnsNull(): void
    {
        self::assertNull($this->callPrivate('normalizeColor', ['transparent']));
        self::assertNull($this->callPrivate('normalizeColor', ['Transparent']));
        self::assertNull($this->callPrivate('normalizeColor', ['  transparent  ']));
    }

    public function testNormalizeColorEmptyValueFallsBackToWhiteNotTransparent(): void
    {
        // Deliberate: this colour is only ever used for a maskable icon background,
        // which must not be transparent - see IconGenerator's own docblock. Only the
        // literal "transparent" (tested above) opts into a transparent canvas.
        self::assertSame('#ffffff', $this->callPrivate('normalizeColor', ['']));
    }

    public function testNormalizeColorInvalidValueFallsBackToWhite(): void
    {
        self::assertSame('#ffffff', $this->callPrivate('normalizeColor', ['nonsense']));
        self::assertSame('#ffffff', $this->callPrivate('normalizeColor', ['#GGGGGG']));
        self::assertSame('#ffffff', $this->callPrivate('normalizeColor', ['rgb(1,2,3)']));
    }

    // -------------------------------------------------------------------
    // isSvgSafeToRasterize() - must accept ordinary SVG, reject anything that could
    // make the rasteriser fetch a remote/local resource, run script, or embed foreign
    // content.
    // -------------------------------------------------------------------

    public function testSvgSafeAcceptsBenignSvg(): void
    {
        $path = $this->svgFixture('<svg xmlns="http://www.w3.org/2000/svg"><circle r="5"/></svg>');
        self::assertTrue($this->callPrivate('isSvgSafeToRasterize', [$path]));
    }

    public function testSvgSafeAcceptsInternalFragmentReference(): void
    {
        $path = $this->svgFixture(
            '<svg xmlns="http://www.w3.org/2000/svg"><defs><circle id="c" r="5"/></defs><use href="#c"/></svg>'
        );
        self::assertTrue($this->callPrivate('isSvgSafeToRasterize', [$path]));
    }

    public function testSvgSafeAcceptsDataUri(): void
    {
        $path = $this->svgFixture(
            '<svg xmlns="http://www.w3.org/2000/svg"><image href="data:image/png;base64,iVBORw0KGgo="/></svg>'
        );
        self::assertTrue($this->callPrivate('isSvgSafeToRasterize', [$path]));
    }

    public function testSvgSafeAcceptsStyleElementWithoutUrl(): void
    {
        $path = $this->svgFixture('<svg xmlns="http://www.w3.org/2000/svg"><style>.a{fill:#fff}</style><circle class="a" r="5"/></svg>');
        self::assertTrue($this->callPrivate('isSvgSafeToRasterize', [$path]));
    }

    public function testSvgSafeRejectsDoctype(): void
    {
        $path = $this->svgFixture(
            "<?xml version=\"1.0\"?>\n<!DOCTYPE svg [<!ENTITY xxe SYSTEM \"file:///etc/passwd\">]>\n"
            . '<svg xmlns="http://www.w3.org/2000/svg"><text>&xxe;</text></svg>'
        );
        self::assertFalse($this->callPrivate('isSvgSafeToRasterize', [$path]));
    }

    public function testSvgSafeRejectsExternalHttpHref(): void
    {
        $path = $this->svgFixture('<svg xmlns="http://www.w3.org/2000/svg"><image href="http://evil.example/x.png"/></svg>');
        self::assertFalse($this->callPrivate('isSvgSafeToRasterize', [$path]));
    }

    public function testSvgSafeRejectsProtocolRelativeHref(): void
    {
        $path = $this->svgFixture('<svg xmlns="http://www.w3.org/2000/svg"><image href="//evil.example/x.png"/></svg>');
        self::assertFalse($this->callPrivate('isSvgSafeToRasterize', [$path]));
    }

    public function testSvgSafeRejectsScriptElement(): void
    {
        $path = $this->svgFixture('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        self::assertFalse($this->callPrivate('isSvgSafeToRasterize', [$path]));
    }

    public function testSvgSafeRejectsForeignObject(): void
    {
        $path = $this->svgFixture(
            '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><body xmlns="http://www.w3.org/1999/xhtml">x</body></foreignObject></svg>'
        );
        self::assertFalse($this->callPrivate('isSvgSafeToRasterize', [$path]));
    }

    public function testSvgSafeRejectsInlineEventHandler(): void
    {
        $path = $this->svgFixture(
            '<svg xmlns="http://www.w3.org/2000/svg" onload="fetch(\'//evil.example\')"><circle r="5"/></svg>'
        );
        self::assertFalse($this->callPrivate('isSvgSafeToRasterize', [$path]));
    }

    public function testSvgSafeRejectsExternalCssImportInStyleElement(): void
    {
        $path = $this->svgFixture(
            '<svg xmlns="http://www.w3.org/2000/svg"><style>@import url(http://evil.example/x.css);</style></svg>'
        );
        self::assertFalse($this->callPrivate('isSvgSafeToRasterize', [$path]));
    }

    public function testSvgSafeRejectsExternalUrlInStyleAttribute(): void
    {
        $path = $this->svgFixture(
            '<svg xmlns="http://www.w3.org/2000/svg"><circle style="fill:url(http://evil.example/x.svg#g)" r="5"/></svg>'
        );
        self::assertFalse($this->callPrivate('isSvgSafeToRasterize', [$path]));
    }

    public function testSvgSafeRejectsNumericEntityEncodedScheme(): void
    {
        // The scheme is spelled out via numeric character references - an XML parser
        // resolves these as ordinary text decoding, a raw-byte regex never does.
        $path = $this->svgFixture(
            '<svg xmlns="http://www.w3.org/2000/svg"><image href="&#104;&#116;&#116;&#112;://evil.example/x.png"/></svg>'
        );
        self::assertFalse($this->callPrivate('isSvgSafeToRasterize', [$path]));
    }

    public function testSvgSafeRejectsMalformedXml(): void
    {
        $path = $this->svgFixture('<svg xmlns="http://www.w3.org/2000/svg"><circle r="5"</svg>');
        self::assertFalse($this->callPrivate('isSvgSafeToRasterize', [$path]));
    }

    public function testSvgSafeRejectsNonSvgRoot(): void
    {
        $path = $this->svgFixture('<html><body>not an svg</body></html>');
        self::assertFalse($this->callPrivate('isSvgSafeToRasterize', [$path]));
    }

    public function testSvgSafeRejectsMissingFile(): void
    {
        $missing = sys_get_temp_dir() . '/favicon_does_not_exist_' . uniqid('', true) . '.svg';
        self::assertFalse($this->callPrivate('isSvgSafeToRasterize', [$missing]));
    }

    public function testSvgSafeRejectsOversizedFile(): void
    {
        // MAX_SVG_BYTES is 2 MiB; pad well past it with otherwise-valid content.
        $path = $this->svgFixture('<svg xmlns="http://www.w3.org/2000/svg">' . str_repeat(' ', 3 * 1024 * 1024) . '</svg>');
        self::assertFalse($this->callPrivate('isSvgSafeToRasterize', [$path]));
    }

    // --- makeDir() / removeTree(): plain-PHP replacements for the deprecated Folder class ----

    public function testMakeDirCreatesNestedFolderAndIsIdempotent(): void
    {
        $base = sys_get_temp_dir() . '/fgfm_dir_' . bin2hex(random_bytes(4));
        $dir  = $base . '/a/b/c';

        self::assertTrue(IconGenerator::makeDir($dir));
        self::assertDirectoryExists($dir);
        self::assertTrue(IconGenerator::makeDir($dir), 'an already existing folder counts as success');

        IconGenerator::removeTree($base);
    }

    public function testMakeDirFailsWhenAFileBlocksThePath(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'fgfm_file_');
        self::assertFalse(IconGenerator::makeDir($file . '/sub'));
        unlink($file);
    }

    public function testRemoveTreeDeletesRecursivelyButNeverFollowsSymlinks(): void
    {
        $base    = sys_get_temp_dir() . '/fgfm_rm_' . bin2hex(random_bytes(4));
        $outside = $base . '/outside';
        $victim  = $base . '/victim';

        mkdir($outside . '/keep', 0755, true);
        file_put_contents($outside . '/keep/precious.txt', 'x');
        mkdir($victim . '/sub', 0755, true);
        file_put_contents($victim . '/sub/f.png', 'x');
        file_put_contents($victim . '/.hidden', 'x');
        symlink($outside, $victim . '/link');

        self::assertTrue(IconGenerator::removeTree($victim));
        self::assertDirectoryDoesNotExist($victim);
        self::assertFileExists($outside . '/keep/precious.txt', 'symlink target must survive');

        IconGenerator::removeTree($base);
        self::assertFalse(IconGenerator::removeTree($base . '/nope'), 'missing folder returns false');
    }

    // --- publishBuild(): never replace an already complete folder ---------------------------

    /** Creates $root/<name> with the given files and returns its path. */
    private function makeSet(string $root, string $name, array $files): string
    {
        $dir = $root . '/' . $name;
        mkdir($dir, 0755, true);

        foreach ($files as $file => $content) {
            file_put_contents($dir . '/' . $file, $content);
        }

        return $dir;
    }

    private function publishRoot(): string
    {
        $root = sys_get_temp_dir() . '/fgfm_publish_' . bin2hex(random_bytes(4));
        mkdir($root, 0755, true);

        return $root;
    }

    public function testPublishKeepsExistingCompleteFolderAndDropsStaging(): void
    {
        $root  = $this->publishRoot();
        $final = $this->makeSet($root, 'abc-123', ['a.png' => 'FIRST']);
        $tmp   = $this->makeSet($root, '.tmp-abc-123-x', ['a.png' => 'SECOND']);
        $inode = fileinode($final);

        $complete = static fn (string $dir): bool => is_file($dir . '/a.png') && filesize($dir . '/a.png') > 0;

        self::assertTrue($this->callPrivate('publishBuild', [$tmp, $final, $complete]));
        self::assertSame('FIRST', file_get_contents($final . '/a.png'), 'the complete set must stay');
        self::assertSame($inode, fileinode($final), 'final folder must not be renamed away and replaced');
        self::assertDirectoryDoesNotExist($tmp, 'staging copy is discarded');
        self::assertSame([], glob($root . '/.old-*') ?: [], 'no swap leftovers');

        IconGenerator::removeTree($root);
    }

    public function testPublishReplacesExistingIncompleteFolder(): void
    {
        $root  = $this->publishRoot();
        $final = $this->makeSet($root, 'abc-123', ['a.png' => '']);          // zero-byte = broken
        $tmp   = $this->makeSet($root, '.tmp-abc-123-x', ['a.png' => 'GOOD']);

        $complete = static fn (string $dir): bool => is_file($dir . '/a.png') && filesize($dir . '/a.png') > 0;

        self::assertTrue($this->callPrivate('publishBuild', [$tmp, $final, $complete]));
        self::assertSame('GOOD', file_get_contents($final . '/a.png'));
        self::assertDirectoryDoesNotExist($tmp);
        self::assertSame([], glob($root . '/.old-*') ?: [], 'old broken folder is removed');

        IconGenerator::removeTree($root);
    }

    public function testPublishWithoutCheckStillReplacesExistingFolder(): void
    {
        $root  = $this->publishRoot();
        $final = $this->makeSet($root, 'abc-123', ['a.png' => 'OLD']);
        $tmp   = $this->makeSet($root, '.tmp-abc-123-x', ['a.png' => 'NEW']);

        self::assertTrue($this->callPrivate('publishBuild', [$tmp, $final]));
        self::assertSame('NEW', file_get_contents($final . '/a.png'));

        IconGenerator::removeTree($root);
    }

    public function testPublishMovesStagingIntoPlaceWhenNothingExists(): void
    {
        $root  = $this->publishRoot();
        $tmp   = $this->makeSet($root, '.tmp-abc-123-x', ['a.png' => 'NEW']);
        $final = $root . '/abc-123';

        self::assertTrue($this->callPrivate('publishBuild', [$tmp, $final, static fn (): bool => true]));
        self::assertSame('NEW', file_get_contents($final . '/a.png'));

        IconGenerator::removeTree($root);
    }

    // --- SVG pre-scan fails closed without ext-dom ------------------------------------------

    public function testBenignSvgIsAcceptedWhenDomIsAvailable(): void
    {
        $path = $this->svgFixture('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 8 8"><rect width="8" height="8"/></svg>');

        self::assertTrue($this->callPrivate('isSvgSafeToRasterize', [$path]));
    }

    public function testSvgIsRejectedWithoutDomEvenWhenBenign(): void
    {
        $noDom = new class () extends IconGenerator {
            protected function domAvailable(): bool
            {
                return false;
            }
        };

        $path = $this->svgFixture('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 8 8"><rect width="8" height="8"/></svg>');

        $ref = new ReflectionMethod(IconGenerator::class, 'isSvgSafeToRasterize');
        $ref->setAccessible(true);

        self::assertFalse($ref->invoke($noDom, $path), 'no XML parser -> no rasterisation of untrusted SVG');
        self::assertNotEmpty($noDom->getErrors());
        self::assertStringContainsString('ext-dom', implode(' ', $noDom->getErrors()));
    }

    public function testObfuscatedSchemeSvgStillRejectedWithDom(): void
    {
        $path = $this->svgFixture('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><image xlink:href="&#104;ttp://evil.test/x.png"/></svg>');

        self::assertFalse($this->callPrivate('isSvgSafeToRasterize', [$path]));
    }
}
