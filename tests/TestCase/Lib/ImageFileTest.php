<?php

App::uses('ImageFile', 'Image.Lib');

/**
 * ImageFile: the type and the size are read from the contents of the file
 */
class ImageFileTest extends CakeTestCase
{
    use ImageTestTrait;

    public function tearDown(): void
    {
        $this->removeFiles();
        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rasterProvider(): array
    {
        return ['JPEG' => ['jpeg'], 'PNG' => ['png'], 'GIF' => ['gif'], 'WebP' => ['webp']];
    }

    /**
     * Raster images are read with their type and size
     *
     * @dataProvider rasterProvider
     * @param string $type
     * @return void
     */
    public function testReadsRasterImage(string $type): void
    {
        $this->assertSame(['type' => $type, 'width' => 122, 'height' => 95], ImageFile::read($this->raster($type, 122, 95)));
    }

    /**
     * A valid SVG document is read with the size of its root element
     *
     * @return void
     */
    public function testReadsValidSvg(): void
    {
        $this->assertSame(['type' => 'svg', 'width' => 122.0, 'height' => 95.0], ImageFile::read($this->svg('<rect width="1" height="1"/>')));
        $this->assertSame(['type' => 'svg', 'width' => null, 'height' => null], ImageFile::read($this->svg('', '')));
    }

    /**
     * An SVG document that is not valid is not an image, also on PHP 8.5+ where getimagesize() reads SVG
     *
     * @return void
     */
    public function testInvalidSvgIsNotAnImage(): void
    {
        $path = $this->svg('<script>alert(1)</script>');

        $this->assertNull(ImageFile::read($path));
        if (defined('IMAGETYPE_SVG')) {
            $this->assertSame(constant('IMAGETYPE_SVG'), getimagesize($path)[2], 'getimagesize() reads SVG on this PHP version');
        }
    }

    /**
     * The SVG options are used, and a document larger than maxBytes is not read
     *
     * @return void
     */
    public function testSvgOptions(): void
    {
        $path = $this->svg('<style>.a{fill:red}</style>');

        $this->assertNotNull(ImageFile::read($path));
        $this->assertNull(ImageFile::read($path, ['allowStyle' => false]));
        $this->assertNull(ImageFile::read($path, ['maxBytes' => 10]));
    }

    /**
     * Other files are not images
     *
     * @return void
     */
    public function testOtherFilesAreNotImages(): void
    {
        $this->assertNull(ImageFile::read($this->file('<?php echo 1;')));
        $this->assertNull(ImageFile::read($this->file('<html><body><svg xmlns="http://www.w3.org/2000/svg"/></body></html>')));
        $this->assertNull(ImageFile::read($this->file('')));
        $this->assertNull(ImageFile::read(sys_get_temp_dir() . DS . 'no-such-image-' . uniqid()));
        $this->assertNull(ImageFile::read(sys_get_temp_dir()));
    }

    /**
     * The extension of each type
     *
     * @return void
     */
    public function testExtension(): void
    {
        $this->assertSame('jpg', ImageFile::extension(ImageFile::JPEG));
        $this->assertSame('png', ImageFile::extension(ImageFile::PNG));
        $this->assertSame('gif', ImageFile::extension(ImageFile::GIF));
        $this->assertSame('webp', ImageFile::extension(ImageFile::WEBP));
        $this->assertSame('svg', ImageFile::extension(ImageFile::SVG));
        $this->assertNull(ImageFile::extension('bmp'));
    }
}
