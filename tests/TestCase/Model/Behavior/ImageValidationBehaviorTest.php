<?php

App::uses('ConnectionManager', 'Model');
App::uses('DataSource', 'Model/Datasource');
App::uses('ImageValidationBehavior', 'Image.Model/Behavior');
App::uses('Model', 'Model');

/**
 * A datasource without a database (the rules do not use the database)
 */
class ImageValidationTestSource extends DataSource
{
}

/**
 * A model without a table
 */
class ImageValidationUpload extends Model
{
    public $useTable = false;

    public $useDbConfig = 'image_validation_test';

    public $name = 'Upload';

    public $alias = 'Upload';
}

/**
 * ImageValidationBehavior: the rules read the uploaded file
 */
class ImageValidationBehaviorTest extends CakeTestCase
{
    use ImageTestTrait;

    private Model $Upload;

    public function setUp(): void
    {
        parent::setUp();
        if (!in_array('image_validation_test', ConnectionManager::sourceList(), true)) {
            ConnectionManager::create('image_validation_test', ['datasource' => 'ImageValidationTestSource']);
        }
        $this->Upload = new ImageValidationUpload();
    }

    public function tearDown(): void
    {
        $this->removeFiles();
        unset($this->Upload);
        parent::tearDown();
    }

    /**
     * Validates the uploaded file with the rules
     *
     * @param string|null $path The uploaded file (null: no file)
     * @param array $rules
     * @param array $settings The settings of the behavior
     * @return array The errors of the field
     */
    private function errors(?string $path, array $rules, array $settings = []): array
    {
        $this->Upload->Behaviors->load('Image.ImageValidation', $settings);
        // Check every rule (CakePHP stops at the first rule that fails by default)
        $this->Upload->validate = ['file' => array_map(fn(array $rule): array => $rule + ['last' => false], $rules)];
        $file = $path === null
            ? ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0]
            : ['name' => 'upload.png', 'type' => 'image/png', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)];
        $this->Upload->set(['file' => $file]);
        $this->Upload->validates();

        return $this->Upload->validationErrors['file'] ?? [];
    }

    /**
     * Without parameters, imageType allows JPEG and PNG (the types setting)
     *
     * @return void
     */
    public function testImageTypeAllowsJpegAndPngByDefault(): void
    {
        $rule = ['type' => ['rule' => ['imageType'], 'message' => 'type']];

        $this->assertSame([], $this->errors($this->raster('jpeg', 10, 10), $rule));
        $this->assertSame([], $this->errors($this->raster('png', 10, 10), $rule));
        $this->assertSame(['type'], $this->errors($this->raster('gif', 10, 10), $rule));
        $this->assertSame(['type'], $this->errors($this->svg(), $rule));
        $this->assertSame(['type'], $this->errors($this->file('not an image'), $rule));
    }

    /**
     * imageType accepts the types in its parameter or in the types setting (jpg is jpeg)
     *
     * @return void
     */
    public function testImageTypeWithTypes(): void
    {
        $rule = ['type' => ['rule' => ['imageType', ['jpg', 'svg']], 'message' => 'type']];

        $this->assertSame([], $this->errors($this->raster('jpeg', 10, 10), $rule));
        $this->assertSame([], $this->errors($this->svg(), $rule));
        $this->assertSame(['type'], $this->errors($this->raster('png', 10, 10), $rule));
        $this->assertSame(['type'], $this->errors($this->svg('<script>alert(1)</script>'), $rule), 'SVG with a script');

        $setting = ['type' => ['rule' => ['imageType'], 'message' => 'type']];
        $this->assertSame([], $this->errors($this->raster('webp', 10, 10), $setting, ['types' => ['webp']]));
        $this->assertSame(['type'], $this->errors($this->raster('png', 10, 10), $setting, ['types' => ['webp']]));
    }

    /**
     * The SVG options of the settings are used
     *
     * @return void
     */
    public function testSvgOptions(): void
    {
        $rule = ['type' => ['rule' => ['imageType', ['svg']], 'message' => 'type']];

        $this->assertSame(['type'], $this->errors($this->svg('<style>.a{fill:red}</style>'), $rule, ['svg' => ['allowStyle' => false]]));
    }

    /**
     * An unknown type is an error of the settings
     *
     * @return void
     */
    public function testUnknownTypeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"bmp"');

        $this->Upload->Behaviors->load('Image.ImageValidation', ['types' => ['bmp']]);
    }

    /**
     * The width and the height must be the values, at most or at least the values
     *
     * @return void
     */
    public function testSize(): void
    {
        $rules = [
            'width' => ['rule' => ['imageWidth', 122], 'message' => 'width'],
            'height' => ['rule' => ['imageHeight', 95], 'message' => 'height'],
        ];
        $this->assertSame([], $this->errors($this->raster('png', 122, 95), $rules));
        $this->assertSame(['width', 'height'], $this->errors($this->raster('png', 123, 94), $rules));
        $this->assertSame([], $this->errors($this->svg('', 'viewBox="0 0 122 95"'), $rules));

        $ranges = [
            'maxWidth' => ['rule' => ['imageMaxWidth', 100], 'message' => 'maxWidth'],
            'maxHeight' => ['rule' => ['imageMaxHeight', 100], 'message' => 'maxHeight'],
            'minWidth' => ['rule' => ['imageMinWidth', 50], 'message' => 'minWidth'],
            'minHeight' => ['rule' => ['imageMinHeight', 50], 'message' => 'minHeight'],
        ];
        $this->assertSame([], $this->errors($this->raster('png', 100, 50), $ranges));
        $this->assertSame(['maxWidth', 'minHeight'], $this->errors($this->raster('png', 101, 49), $ranges));
        $this->assertSame(['maxHeight', 'minWidth'], $this->errors($this->raster('png', 49, 101), $ranges));
    }

    /**
     * An SVG document without a size in pixels and a file that is not an image fail the size rules
     *
     * @return void
     */
    public function testSizeOfUnknownSize(): void
    {
        $rules = ['width' => ['rule' => ['imageMaxWidth', 1000], 'message' => 'width']];

        $this->assertSame(['width'], $this->errors($this->svg('', 'width="10mm" height="10mm"'), $rules));
        $this->assertSame(['width'], $this->errors($this->file('not an image'), $rules));
    }

    /**
     * The rules pass when no file is uploaded
     *
     * @return void
     */
    public function testNoFile(): void
    {
        $rules = [
            'type' => ['rule' => ['imageType'], 'message' => 'type'],
            'width' => ['rule' => ['imageWidth', 1], 'message' => 'width'],
        ];

        $this->assertSame([], $this->errors(null, $rules));
        $this->Upload->set(['file' => 'not an upload']);
        $this->assertTrue($this->Upload->validates());
    }

    /**
     * The behavior works with the defaults when it is not attached to the model
     *
     * @return void
     */
    public function testBehaviorWithoutSettings(): void
    {
        $behavior = new ImageValidationBehavior();
        $file = ['tmp_name' => $this->raster('png', 10, 10), 'error' => UPLOAD_ERR_OK];

        $this->assertTrue($behavior->imageType($this->Upload, ['file' => $file]));
        $this->assertTrue($behavior->imageWidth($this->Upload, ['file' => $file], 10));
        $this->assertFalse($behavior->imageType($this->Upload, ['file' => ['tmp_name' => $this->svg(), 'error' => UPLOAD_ERR_OK]]));
    }

    /**
     * A new file at the same path is read again
     *
     * @return void
     */
    public function testNewFileAtSamePathIsReadAgain(): void
    {
        $rule = ['type' => ['rule' => ['imageType'], 'message' => 'type']];
        $path = $this->raster('png', 10, 10);
        $this->assertSame([], $this->errors($path, $rule));

        file_put_contents($path, 'not an image');
        $this->assertSame(['type'], $this->errors($path, $rule));
    }

    /**
     * The extension is decided by the contents of the file
     *
     * @return void
     */
    public function testExtension(): void
    {
        $this->Upload->Behaviors->load('Image.ImageValidation', ['svg' => ['allowStyle' => false]]);
        $styled = $this->svg('<style>.a{fill:red}</style>');

        $this->assertSame('png', ImageValidationBehavior::extensionOf($this->raster('png', 1, 1)));
        $this->assertSame('jpg', ImageValidationBehavior::extensionOf($this->raster('jpeg', 1, 1)));
        $this->assertSame('svg', ImageValidationBehavior::extensionOf($styled));
        $this->assertNull(ImageValidationBehavior::extensionOf($this->file('not an image')));
        $this->assertSame('gif', $this->Upload->imageExtension($this->raster('gif', 1, 1)));
        $this->assertNull($this->Upload->imageExtension($styled), 'The SVG options of the model');
    }
}
