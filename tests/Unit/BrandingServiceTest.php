<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Service\BrandingService;
use OCA\FormVox\Service\UploadService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for BrandingService — pins the CURRENT behaviour of
 * branding layout/style storage, image URL resolution, and the per-form vs
 * app-data image folder plumbing. Everything is mocked; no real server.
 */
class BrandingServiceTest extends TestCase
{
    private IConfig $config;
    private IAppData $appData;
    private UploadService $uploadService;
    private IURLGenerator $urlGenerator;

    private const APP_ID = 'formvox';

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = $this->createMock(IConfig::class);
        $this->appData = $this->createMock(IAppData::class);
        $this->uploadService = $this->createMock(UploadService::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
    }

    private function service(): BrandingService
    {
        return new BrandingService(
            $this->config,
            $this->appData,
            $this->uploadService,
            $this->urlGenerator
        );
    }

    /** Write a temp file with the given content and return its path. */
    private function tmpFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fvbrand');
        file_put_contents($path, $content);
        return $path;
    }

    // ---- getBranding() defaults -------------------------------------------

    public function testGetBrandingReturnsDefaultsWhenConfigEmpty(): void
    {
        $this->config->method('getAppValue')->willReturn('');

        $result = $this->service()->getBranding();

        // Default layout structure.
        $this->assertArrayHasKey('layout', $result);
        $this->assertArrayHasKey('globalStyles', $result);
        $this->assertSame([], $result['layout']['header']);
        $this->assertSame([], $result['layout']['footer']);
        $this->assertCount(2, $result['layout']['thankYou']);
        $this->assertSame('default-thankyou-heading', $result['layout']['thankYou'][0]['id']);
        $this->assertSame('heading', $result['layout']['thankYou'][0]['type']);
        $this->assertSame('Thank you!', $result['layout']['thankYou'][0]['settings']['text']);
        $this->assertSame('default-thankyou-text', $result['layout']['thankYou'][1]['id']);

        // Default global styles.
        $this->assertSame([
            'primaryColor' => '#0082c9',
            'backgroundColor' => '#ffffff',
            'fontFamily' => 'default',
        ], $result['globalStyles']);
    }

    public function testGetBrandingParsesStoredJson(): void
    {
        $storedLayout = json_encode([
            'header' => [],
            'footer' => [],
            'thankYou' => [],
        ]);
        $storedStyles = json_encode([
            'primaryColor' => '#ff0000',
            'backgroundColor' => '#000000',
            'fontFamily' => 'serif',
        ]);

        $this->config->method('getAppValue')->willReturnCallback(
            function (string $app, string $key, string $default) use ($storedLayout, $storedStyles) {
                if ($key === 'branding_layout') {
                    return $storedLayout;
                }
                if ($key === 'branding_globalStyles') {
                    return $storedStyles;
                }
                return $default;
            }
        );

        $result = $this->service()->getBranding();

        $this->assertSame([], $result['layout']['thankYou']);
        $this->assertSame('#ff0000', $result['globalStyles']['primaryColor']);
        $this->assertSame('serif', $result['globalStyles']['fontFamily']);
    }

    public function testGetBrandingUsesDefaultLayoutButStoredStyles(): void
    {
        $storedStyles = json_encode(['primaryColor' => '#123456']);
        $this->config->method('getAppValue')->willReturnCallback(
            function (string $app, string $key, string $default) use ($storedStyles) {
                if ($key === 'branding_globalStyles') {
                    return $storedStyles;
                }
                return ''; // layout empty -> defaults
            }
        );

        $result = $this->service()->getBranding();

        // Layout is default (thankYou has 2 blocks).
        $this->assertCount(2, $result['layout']['thankYou']);
        // Styles are the parsed stored value (only primaryColor key).
        $this->assertSame(['primaryColor' => '#123456'], $result['globalStyles']);
    }

    // ---- getBranding() + resolveImageUrls ---------------------------------

    public function testGetBrandingResolvesImageUrlsForLogoAndImageBlocks(): void
    {
        $layout = [
            'header' => [
                [
                    'id' => 'logo-1',
                    'type' => 'logo',
                    'settings' => ['imageId' => 'logo-1'],
                ],
            ],
            'footer' => [
                [
                    'id' => 'img-1',
                    'type' => 'image',
                    'settings' => ['imageId' => 'img-1'],
                ],
            ],
            'thankYou' => [],
        ];
        $this->config->method('getAppValue')->willReturnCallback(
            function (string $app, string $key, string $default) use ($layout) {
                return $key === 'branding_layout' ? json_encode($layout) : '';
            }
        );
        $this->urlGenerator->method('linkToRoute')->willReturnCallback(
            function (string $route, array $params) {
                return '/route/' . $route . '/' . $params['blockId'];
            }
        );

        $result = $this->service()->getBranding();

        $this->assertSame(
            '/route/formvox.branding.blockImage/logo-1',
            $result['layout']['header'][0]['settings']['imageUrl']
        );
        $this->assertSame(
            '/route/formvox.branding.blockImage/img-1',
            $result['layout']['footer'][0]['settings']['imageUrl']
        );
    }

    public function testResolveImageUrlsSkipsBlocksWithoutImageId(): void
    {
        $layout = [
            'header' => [
                ['id' => 'logo-1', 'type' => 'logo', 'settings' => []],
                ['id' => 'txt-1', 'type' => 'text', 'settings' => ['imageId' => 'x']],
            ],
            'footer' => [],
            'thankYou' => [],
        ];
        $this->config->method('getAppValue')->willReturnCallback(
            function (string $app, string $key, string $default) use ($layout) {
                return $key === 'branding_layout' ? json_encode($layout) : '';
            }
        );
        // linkToRoute should never be called since no block qualifies.
        $this->urlGenerator->expects($this->never())->method('linkToRoute');

        $result = $this->service()->getBranding();

        $this->assertArrayNotHasKey('imageUrl', $result['layout']['header'][0]['settings']);
        $this->assertArrayNotHasKey('imageUrl', $result['layout']['header'][1]['settings']);
    }

    public function testResolveImageUrlsSkipsMissingZone(): void
    {
        // Layout missing 'header' zone entirely; should not error.
        $layout = [
            'footer' => [],
            'thankYou' => [],
        ];
        $this->config->method('getAppValue')->willReturnCallback(
            function (string $app, string $key, string $default) use ($layout) {
                return $key === 'branding_layout' ? json_encode($layout) : '';
            }
        );

        $result = $this->service()->getBranding();

        $this->assertArrayNotHasKey('header', $result['layout']);
    }

    // ---- saveLayout() ------------------------------------------------------

    public function testSaveLayoutStoresJsonAndReturnsBranding(): void
    {
        $layout = ['header' => [], 'footer' => [], 'thankYou' => []];

        $this->config->expects($this->once())
            ->method('setAppValue')
            ->with(self::APP_ID, 'branding_layout', json_encode($layout));

        // getBranding() reads config afterwards -> return empty (defaults).
        $this->config->method('getAppValue')->willReturn('');

        $result = $this->service()->saveLayout($layout);

        $this->assertArrayHasKey('layout', $result);
        $this->assertArrayHasKey('globalStyles', $result);
    }

    // ---- saveGlobalStyles() -----------------------------------------------

    public function testSaveGlobalStylesStoresJsonAndReturnsBranding(): void
    {
        $styles = ['primaryColor' => '#abcdef'];

        $this->config->expects($this->once())
            ->method('setAppValue')
            ->with(self::APP_ID, 'branding_globalStyles', json_encode($styles));

        $this->config->method('getAppValue')->willReturn('');

        $result = $this->service()->saveGlobalStyles($styles);

        $this->assertArrayHasKey('globalStyles', $result);
    }

    // ---- saveBlockImage() (app data folder) -------------------------------

    public function testSaveBlockImageUsesExistingFolderAndWritesFile(): void
    {
        $tmp = $this->tmpFile('PNGDATA');

        $file = $this->createMock(ISimpleFile::class);
        $file->expects($this->once())->method('putContent')->with('PNGDATA');

        $folder = $this->createMock(ISimpleFolder::class);
        $folder->method('getDirectoryListing')->willReturn([]);
        $folder->expects($this->once())
            ->method('newFile')
            ->with('block_b1.png')
            ->willReturn($file);

        $this->appData->expects($this->once())->method('getFolder')->with('branding')->willReturn($folder);
        $this->appData->expects($this->never())->method('newFolder');

        $result = $this->service()->saveBlockImage('b1', $tmp, 'image/png');

        $this->assertSame('b1', $result);
        unlink($tmp);
    }

    public function testSaveBlockImageCreatesFolderWhenMissing(): void
    {
        $tmp = $this->tmpFile('JPGDATA');

        $file = $this->createMock(ISimpleFile::class);
        $folder = $this->createMock(ISimpleFolder::class);
        $folder->method('getDirectoryListing')->willReturn([]);
        $folder->expects($this->once())
            ->method('newFile')
            ->with('block_b2.jpg')
            ->willReturn($file);

        $this->appData->method('getFolder')->willThrowException(new NotFoundException());
        $this->appData->expects($this->once())->method('newFolder')->with('branding')->willReturn($folder);

        $result = $this->service()->saveBlockImage('b2', $tmp, 'image/jpeg');

        $this->assertSame('b2', $result);
        unlink($tmp);
    }

    public function testSaveBlockImageDeletesPreviousFile(): void
    {
        $tmp = $this->tmpFile('DATA');

        $old = $this->createMock(ISimpleFile::class);
        $old->method('getName')->willReturn('block_b3.png');
        $old->expects($this->once())->method('delete');

        $other = $this->createMock(ISimpleFile::class);
        $other->method('getName')->willReturn('block_other.png');
        $other->expects($this->never())->method('delete');

        $newFile = $this->createMock(ISimpleFile::class);

        $folder = $this->createMock(ISimpleFolder::class);
        $folder->method('getDirectoryListing')->willReturn([$other, $old]);
        $folder->method('newFile')->willReturn($newFile);

        $this->appData->method('getFolder')->willReturn($folder);

        $this->service()->saveBlockImage('b3', $tmp, 'image/png');
        unlink($tmp);
    }

    public function testSaveBlockImageExtensionMapping(): void
    {
        // Pin getExtensionFromMimeType via observable filename in newFile().
        $cases = [
            'image/jpeg' => 'block_x.jpg',
            'image/svg+xml' => 'block_x.svg',
            'image/gif' => 'block_x.gif',
            'image/webp' => 'block_x.webp',
            'image/png' => 'block_x.png',
            'application/octet-stream' => 'block_x.png', // default
        ];

        foreach ($cases as $mime => $expectedName) {
            $tmp = $this->tmpFile('D');
            $file = $this->createMock(ISimpleFile::class);
            $folder = $this->createMock(ISimpleFolder::class);
            $folder->method('getDirectoryListing')->willReturn([]);
            $folder->expects($this->once())->method('newFile')->with($expectedName)->willReturn($file);
            $this->appData->method('getFolder')->willReturn($folder);

            $this->service()->saveBlockImage('x', $tmp, $mime);
            unlink($tmp);

            // Fresh mocks for next iteration.
            $this->setUp();
        }
    }

    // ---- deleteBlockImage() (app data folder) -----------------------------

    public function testDeleteBlockImageDeletesMatchingFile(): void
    {
        $target = $this->createMock(ISimpleFile::class);
        $target->method('getName')->willReturn('block_del.png');
        $target->expects($this->once())->method('delete');

        $folder = $this->createMock(ISimpleFolder::class);
        $folder->method('getDirectoryListing')->willReturn([$target]);

        $this->appData->method('getFolder')->with('branding')->willReturn($folder);

        $this->service()->deleteBlockImage('del');
    }

    public function testDeleteBlockImageNoFolderIsSilent(): void
    {
        $this->appData->method('getFolder')->willThrowException(new NotFoundException());

        // Must not throw.
        $this->service()->deleteBlockImage('del');
        $this->assertTrue(true);
    }

    public function testDeleteBlockImageOnlyDeletesFirstMatchAndBreaks(): void
    {
        $first = $this->createMock(ISimpleFile::class);
        $first->method('getName')->willReturn('block_m.png');
        $first->expects($this->once())->method('delete');

        // Second matching file should NOT be deleted (break after first).
        $second = $this->createMock(ISimpleFile::class);
        $second->method('getName')->willReturn('block_m.jpg');
        $second->expects($this->never())->method('delete');

        $folder = $this->createMock(ISimpleFolder::class);
        $folder->method('getDirectoryListing')->willReturn([$first, $second]);
        $this->appData->method('getFolder')->willReturn($folder);

        $this->service()->deleteBlockImage('m');
    }

    // ---- getBlockImage() (app data folder) --------------------------------

    public function testGetBlockImageReturnsContentAndMime(): void
    {
        $file = $this->createMock(ISimpleFile::class);
        $file->method('getName')->willReturn('block_g.webp');
        $file->method('getContent')->willReturn('BYTES');

        $folder = $this->createMock(ISimpleFolder::class);
        $folder->method('getDirectoryListing')->willReturn([$file]);
        $this->appData->method('getFolder')->with('branding')->willReturn($folder);

        $result = $this->service()->getBlockImage('g');

        $this->assertSame(['content' => 'BYTES', 'mimeType' => 'image/webp'], $result);
    }

    public function testGetBlockImageReturnsNullWhenNoMatch(): void
    {
        $file = $this->createMock(ISimpleFile::class);
        $file->method('getName')->willReturn('block_other.png');

        $folder = $this->createMock(ISimpleFolder::class);
        $folder->method('getDirectoryListing')->willReturn([$file]);
        $this->appData->method('getFolder')->willReturn($folder);

        $this->assertNull($this->service()->getBlockImage('nope'));
    }

    public function testGetBlockImageReturnsNullWhenFolderMissing(): void
    {
        $this->appData->method('getFolder')->willThrowException(new NotFoundException());
        $this->assertNull($this->service()->getBlockImage('g'));
    }

    public function testGetBlockImageMimeTypeMapping(): void
    {
        $names = [
            'block_a.jpg' => 'image/jpeg',
            'block_a.jpeg' => 'image/jpeg',
            'block_a.svg' => 'image/svg+xml',
            'block_a.gif' => 'image/gif',
            'block_a.webp' => 'image/webp',
            'block_a.png' => 'image/png',
            'block_a.bmp' => 'image/png', // default fallback
        ];

        foreach ($names as $fname => $expectedMime) {
            $file = $this->createMock(ISimpleFile::class);
            $file->method('getName')->willReturn($fname);
            $file->method('getContent')->willReturn('C');
            $folder = $this->createMock(ISimpleFolder::class);
            $folder->method('getDirectoryListing')->willReturn([$file]);
            $this->appData->method('getFolder')->willReturn($folder);

            $result = $this->service()->getBlockImage('a');
            $this->assertSame($expectedMime, $result['mimeType'], "mime for $fname");

            $this->setUp();
        }
    }

    // ---- saveFormBlockImage() (per-form folder) ---------------------------

    public function testSaveFormBlockImageWritesToFormFolder(): void
    {
        $tmp = $this->tmpFile('FORMDATA');

        $newFile = $this->createMock(File::class);
        $newFile->expects($this->once())->method('putContent')->with('FORMDATA');

        $folder = $this->createMock(Folder::class);
        $folder->method('getDirectoryListing')->willReturn([]);
        $folder->expects($this->once())
            ->method('newFile')
            ->with('block_fb.png')
            ->willReturn($newFile);

        $this->uploadService->expects($this->once())
            ->method('getBrandingFolder')
            ->with(42, true)
            ->willReturn($folder);

        $result = $this->service()->saveFormBlockImage(42, 'fb', $tmp, 'image/png');

        $this->assertSame('fb', $result);
        unlink($tmp);
    }

    public function testSaveFormBlockImageDeletesPreviousMatches(): void
    {
        $tmp = $this->tmpFile('D');

        $old = $this->createMock(File::class);
        $old->method('getName')->willReturn('block_fb.jpg');
        $old->expects($this->once())->method('delete');

        $unrelated = $this->createMock(File::class);
        $unrelated->method('getName')->willReturn('block_zz.png');
        $unrelated->expects($this->never())->method('delete');

        $newFile = $this->createMock(File::class);

        $folder = $this->createMock(Folder::class);
        $folder->method('getDirectoryListing')->willReturn([$old, $unrelated]);
        $folder->method('newFile')->willReturn($newFile);

        $this->uploadService->method('getBrandingFolder')->willReturn($folder);

        $this->service()->saveFormBlockImage(1, 'fb', $tmp, 'image/jpeg');
        unlink($tmp);
    }

    public function testSaveFormBlockImageIgnoresListingErrorThenUploads(): void
    {
        $tmp = $this->tmpFile('DATA');

        $newFile = $this->createMock(File::class);
        $newFile->expects($this->once())->method('putContent')->with('DATA');

        $folder = $this->createMock(Folder::class);
        // getDirectoryListing throws -> caught, proceeds to upload.
        $folder->method('getDirectoryListing')->willThrowException(new \RuntimeException('boom'));
        $folder->expects($this->once())->method('newFile')->willReturn($newFile);

        $this->uploadService->method('getBrandingFolder')->willReturn($folder);

        $result = $this->service()->saveFormBlockImage(1, 'fb', $tmp, 'image/png');
        $this->assertSame('fb', $result);
        unlink($tmp);
    }

    // ---- getFormBlockImage() (per-form folder) ----------------------------

    public function testGetFormBlockImageReturnsContentAndMime(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getName')->willReturn('block_fb.svg');
        $file->method('getContent')->willReturn('SVGDATA');

        $folder = $this->createMock(Folder::class);
        $folder->method('getDirectoryListing')->willReturn([$file]);

        $this->uploadService->expects($this->once())
            ->method('getBrandingFolder')
            ->with(7)
            ->willReturn($folder);

        $result = $this->service()->getFormBlockImage(7, 'fb');

        $this->assertSame(['content' => 'SVGDATA', 'mimeType' => 'image/svg+xml'], $result);
    }

    public function testGetFormBlockImageReturnsNullWhenNoMatch(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getName')->willReturn('block_other.png');
        $folder = $this->createMock(Folder::class);
        $folder->method('getDirectoryListing')->willReturn([$file]);
        $this->uploadService->method('getBrandingFolder')->willReturn($folder);

        $this->assertNull($this->service()->getFormBlockImage(7, 'fb'));
    }

    public function testGetFormBlockImageReturnsNullWhenFolderThrows(): void
    {
        $this->uploadService->method('getBrandingFolder')
            ->willThrowException(new NotFoundException());

        $this->assertNull($this->service()->getFormBlockImage(7, 'fb'));
    }

    // ---- deleteFormBlockImage() (per-form folder) -------------------------

    public function testDeleteFormBlockImageDeletesAllMatches(): void
    {
        $m1 = $this->createMock(File::class);
        $m1->method('getName')->willReturn('block_fb.png');
        $m1->expects($this->once())->method('delete');

        $m2 = $this->createMock(File::class);
        $m2->method('getName')->willReturn('block_fb.jpg');
        $m2->expects($this->once())->method('delete');

        $skip = $this->createMock(File::class);
        $skip->method('getName')->willReturn('block_zz.png');
        $skip->expects($this->never())->method('delete');

        $folder = $this->createMock(Folder::class);
        $folder->method('getDirectoryListing')->willReturn([$m1, $skip, $m2]);

        $this->uploadService->expects($this->once())
            ->method('getBrandingFolder')
            ->with(7, true)
            ->willReturn($folder);

        $this->service()->deleteFormBlockImage(7, 'fb');
    }

    public function testDeleteFormBlockImageSilentWhenFolderThrows(): void
    {
        $this->uploadService->method('getBrandingFolder')
            ->willThrowException(new NotFoundException());

        // Must not throw.
        $this->service()->deleteFormBlockImage(7, 'fb');
        $this->assertTrue(true);
    }
}
