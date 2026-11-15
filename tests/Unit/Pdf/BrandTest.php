<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Pdf;

use OCA\FormVox\Pdf\Brand;
use OCA\FormVox\Pdf\PdfCache;
use OCP\Defaults;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IConfig;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;

/** The instance's theming for the PDF header: its own logo only, scaled to what the header shows, and nothing but a colour where a colour goes. */
class BrandTest extends TestCase {
	private string $base;

	protected function setUp(): void {
		parent::setUp();
		$this->base = sys_get_temp_dir() . '/formvox-brand-test-' . bin2hex(random_bytes(4));
		mkdir($this->base);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->base));
		parent::tearDown();
	}

	private function brand(Defaults $defaults, string $logoMime, ?IAppDataFactory $appData = null): Brand {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(fn (string $app, string $key, string $default) => $app === 'theming' && $key === 'logoMime' ? $logoMime : $default);
		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTempBaseDir')->willReturn($this->base);
		return new Brand($defaults, $config, $appData ?? $this->createMock(IAppDataFactory::class), new PdfCache($temp, $config));
	}

	/** Nextcloud before 35: no getLogoImage(). */
	private function defaults(string $color = '#0082c9', string $text = '#ffffff'): Defaults {
		$defaults = $this->createMock(Defaults::class);
		$defaults->method('getName')->willReturn('Schulcloud');
		$defaults->method('getColorPrimary')->willReturn($color);
		$defaults->method('getTextColorPrimary')->willReturn($text);
		return $defaults;
	}

	private function storedLogo(string $content): IAppDataFactory {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getContent')->willReturn($content);
		$images = $this->createMock(ISimpleFolder::class);
		$images->method('getFile')->with('logo')->willReturn($file);
		$global = $this->createMock(ISimpleFolder::class);
		$global->method('getFolder')->with('images')->willReturn($images);
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->with('global')->willReturn($global);
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->with('theming')->willReturn($appData);
		return $factory;
	}

	/** A PNG of this size, with enough in it to be a picture. */
	private static function png(int $width, int $height): string {
		$image = imagecreatetruecolor($width, $height);
		for ($x = 0; $x < $width; $x += 10) {
			imagefilledrectangle($image, $x, 0, $x + 9, $height - 1, (int)imagecolorallocate($image, $x % 256, 100, ($x * 7) % 256));
		}
		ob_start();
		imagepng($image);
		return (string)ob_get_clean();
	}

	public function testNameAndColours(): void {
		$header = $this->brand($this->defaults('#123456', '#000000'), '')->header();

		$this->assertSame(['name' => 'Schulcloud', 'color' => '#123456', 'textColor' => '#000000', 'logo' => null], $header);
	}

	public function testOnlyAColourWhereAColourGoes(): void {
		$header = $this->brand($this->defaults('red;background:url(x)', 'inherit'), '')->header();

		$this->assertSame(['#00679e', '#ffffff'], [$header['color'], $header['textColor']]);
	}

	public function testWithoutAnOwnLogoNone(): void {
		$this->assertNull($this->brand($this->defaults(), '', $this->storedLogo('png'))->header()['logo']);
	}

	public function testTheOwnLogoBeforeNextcloud35(): void {
		$png = self::png(40, 20);
		$header = $this->brand($this->defaults(), 'image/png', $this->storedLogo($png))->header();

		$this->assertSame('data:image/png;base64,' . base64_encode($png), $header['logo']);
	}

	public function testTheRasterLogoFromNextcloud35(): void {
		$defaults = new class extends Defaults {
			public function __construct() {
			}
			public function getName(): string {
				return 'Schulcloud';
			}
			public function getColorPrimary(): string {
				return '#0082c9';
			}
			public function getTextColorPrimary(): string {
				return '#ffffff';
			}
			/** @return array{content: string, mimeType: string}|null */
			public function getLogoImage(): ?array {
				return ['content' => 'png-from-svg', 'mimeType' => 'image/png'];
			}
		};

		$this->assertSame('data:image/png;base64,' . base64_encode('png-from-svg'), $this->brand($defaults, 'image/svg+xml')->header()['logo']);
	}

	/** A photo-sized logo is scaled once to what the header shows; every PDF after takes the small one from the cache. */
	public function testALargeLogoIsScaledDownOnceAndKept(): void {
		$defaults = new class(self::png(2000, 600)) extends Defaults {
			public int $reads = 0;
			public function __construct(
				private string $png,
			) {
			}
			public function getName(): string {
				return 'Schulcloud';
			}
			public function getColorPrimary(): string {
				return '#0082c9';
			}
			public function getTextColorPrimary(): string {
				return '#ffffff';
			}
			/** @return array{content: string, mimeType: string}|null */
			public function getLogoImage(): ?array {
				$this->reads++;
				return ['content' => $this->png, 'mimeType' => 'image/png'];
			}
		};

		$logo = (string)$this->brand($defaults, 'image/png')->header()['logo'];

		$this->assertStringStartsWith('data:image/png;base64,', $logo);
		$bytes = (string)base64_decode(substr($logo, strlen('data:image/png;base64,')));
		$this->assertSame([600, 180], array_slice((array)getimagesizefromstring($bytes), 0, 2));
		$this->assertLessThan(100 * 1024, strlen($bytes));
		$this->assertSame($logo, $this->brand($defaults, 'image/png')->header()['logo'], 'the same again');
		$this->assertSame(1, $defaults->reads, 'read and scaled once, then from the cache');
	}

	/** A JPEG logo stays a JPEG, which is what a photo is smallest as. */
	public function testAJpegLogoStaysAJpeg(): void {
		$image = imagecreatetruecolor(1200, 900);
		ob_start();
		imagejpeg($image);
		$jpeg = (string)ob_get_clean();

		$logo = (string)$this->brand($this->defaults(), 'image/jpeg', $this->storedLogo($jpeg))->header()['logo'];

		$this->assertStringStartsWith('data:image/jpeg;base64,', $logo);
		$this->assertSame([347, 260], array_slice((array)getimagesizefromstring((string)base64_decode(substr($logo, strlen('data:image/jpeg;base64,')))), 0, 2));
	}

	public function testALogoThePdfCannotShowOrThatIsGoneIsLeftOut(): void {
		$this->assertNull($this->brand($this->defaults(), 'image/webp', $this->storedLogo('webp'))->header()['logo']);
		$this->assertNull($this->brand($this->defaults(), 'image/png', $this->storedLogo(str_repeat('x', 2 * 1024 * 1024 + 1)))->header()['logo']);

		$gone = $this->createMock(IAppDataFactory::class);
		$gone->method('get')->willThrowException(new NotFoundException());
		$this->assertNull($this->brand($this->defaults(), 'image/png', $gone)->header()['logo']);
	}
}
