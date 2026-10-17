<?php

App::uses('ImageFile', 'Image.Lib');
App::uses('ModelBehavior', 'Model');

/**
 * Validation rules for uploaded images: the type and the size, read from the contents of the file
 *
 * ```
 * public $actsAs = ['Image.ImageValidation'];
 * public $validate = ['photo' => [
 *     'type' => ['rule' => ['imageType'], 'message' => 'Select a JPEG or PNG image'],
 *     'width' => ['rule' => ['imageWidth', 295], 'message' => 'The width must be 295px'],
 *     'height' => ['rule' => ['imageHeight', 215], 'message' => 'The height must be 215px'],
 * ]];
 *
 * // A logo in JPEG, PNG or SVG
 * public $validate = ['logo' => [
 *     'type' => ['rule' => ['imageType', ['jpeg', 'png', 'svg']], 'message' => 'Select a JPEG, PNG or SVG image'],
 * ]];
 * ```
 *
 * The value of the field is the uploaded file (`['tmp_name' => ..., 'error' => ...]`). The rules pass
 * when no file is uploaded (no `tmp_name`, or the error `UPLOAD_ERR_NO_FILE`): add a `notBlank`-like
 * rule if the file is required, and check the other upload errors yourself. A file that is not an
 * image of the allowed types fails every rule.
 *
 * The type is read from the contents of the file, never from its name or the MIME type sent by the
 * browser. SVG documents are accepted only if they are valid for SvgValidator (static graphics,
 * see its description); SVG is not allowed unless you add `svg` to the types.
 *
 * Settings:
 *
 * - `types`: The types allowed by `imageType` without parameters: `jpeg` (or `jpg`), `png`, `gif`, `webp`,
 *   `svg`. Default `['jpeg', 'png']`. An unknown type throws InvalidArgumentException
 * - `svg`: The options of SvgValidator (`maxBytes`, `maxElements`, `maxDepth`, `allowStyle`)
 *
 * Each file is read once per model.
 */
class ImageValidationBehavior extends ModelBehavior
{
    /**
     * Default settings
     */
    public const DEFAULTS = [
        'types' => [ImageFile::JPEG, ImageFile::PNG],
        'svg' => [],
    ];

    /**
     * Read images (cache key => image, or null if the file is not an image)
     *
     * @var array<string, array{type: string, width: int|float|null, height: int|float|null}|null>
     */
    private array $images = [];

    /**
     * @param Model $model
     * @param array<string, mixed> $config
     * @return void
     */
    public function setup(Model $model, $config = [])
    {
        $settings = (array)$config + self::DEFAULTS;
        $settings['types'] = $this->types($settings['types']);
        $settings['svg'] = (array)$settings['svg'];
        $this->settings[$model->alias] = $settings;
    }

    /**
     * Whether the file is an image of the allowed types
     *
     * @param Model $model
     * @param array $check
     * @param array<string>|string|null $types The allowed types (default: the `types` setting)
     * @return bool
     */
    public function imageType(Model $model, $check, $types = null): bool
    {
        // Without parameters, CakePHP passes the rule settings (with `rule`) as the last argument
        if ($types === null || (is_array($types) && array_key_exists('rule', $types))) {
            $types = $this->settings[$model->alias]['types'];
        }
        $path = $this->uploadedPath(reset($check));
        if ($path === null) {
            return true;
        }
        $image = $this->image($model, $path);

        return $image !== null && in_array($image['type'], $this->types($types), true);
    }

    /**
     * Whether the width of the image is the value (in pixels)
     *
     * @param Model $model
     * @param array $check
     * @param float|int $width
     * @return bool
     */
    public function imageWidth(Model $model, $check, $width): bool
    {
        return $this->compare($model, reset($check), 'width', fn(float $value): bool => abs($value - (float)$width) < 0.0001);
    }

    /**
     * Whether the height of the image is the value (in pixels)
     *
     * @param Model $model
     * @param array $check
     * @param float|int $height
     * @return bool
     */
    public function imageHeight(Model $model, $check, $height): bool
    {
        return $this->compare($model, reset($check), 'height', fn(float $value): bool => abs($value - (float)$height) < 0.0001);
    }

    /**
     * Whether the width of the image is at most the value (in pixels)
     *
     * @param Model $model
     * @param array $check
     * @param float|int $width
     * @return bool
     */
    public function imageMaxWidth(Model $model, $check, $width): bool
    {
        return $this->compare($model, reset($check), 'width', fn(float $value): bool => $value <= (float)$width);
    }

    /**
     * Whether the height of the image is at most the value (in pixels)
     *
     * @param Model $model
     * @param array $check
     * @param float|int $height
     * @return bool
     */
    public function imageMaxHeight(Model $model, $check, $height): bool
    {
        return $this->compare($model, reset($check), 'height', fn(float $value): bool => $value <= (float)$height);
    }

    /**
     * Whether the width of the image is at least the value (in pixels)
     *
     * @param Model $model
     * @param array $check
     * @param float|int $width
     * @return bool
     */
    public function imageMinWidth(Model $model, $check, $width): bool
    {
        return $this->compare($model, reset($check), 'width', fn(float $value): bool => $value >= (float)$width);
    }

    /**
     * Whether the height of the image is at least the value (in pixels)
     *
     * @param Model $model
     * @param array $check
     * @param float|int $height
     * @return bool
     */
    public function imageMinHeight(Model $model, $check, $height): bool
    {
        return $this->compare($model, reset($check), 'height', fn(float $value): bool => $value >= (float)$height);
    }

    /**
     * The extension of the file for the contents of the image, read with the settings of the model
     * (the `svg` options). Use it to name the saved file from its contents, not from the uploaded name.
     *
     * @param Model $model
     * @param string $path
     * @return string|null `jpg`, `png`, `gif`, `webp` or `svg`; null if the file is not an image
     */
    public function imageExtension(Model $model, string $path): ?string
    {
        $image = $this->image($model, $path);

        return $image === null ? null : ImageFile::extension($image['type']);
    }

    /**
     * The extension of the file for the contents of the image, read with the default SVG options
     *
     * @param string $path
     * @param array<string, mixed> $svgOptions The options of SvgValidator
     * @return string|null `jpg`, `png`, `gif`, `webp` or `svg`; null if the file is not an image
     */
    public static function extensionOf(string $path, array $svgOptions = []): ?string
    {
        $image = ImageFile::read($path, $svgOptions);

        return $image === null ? null : ImageFile::extension($image['type']);
    }

    /**
     * Compares the width or the height of the uploaded image
     *
     * @param Model $model
     * @param mixed $file The uploaded file
     * @param string $key width or height
     * @param callable(float): bool $matches
     * @return bool
     */
    private function compare(Model $model, $file, string $key, callable $matches): bool
    {
        $path = $this->uploadedPath($file);
        if ($path === null) {
            return true;
        }
        $image = $this->image($model, $path);

        return $image !== null && $image[$key] !== null && $matches((float)$image[$key]);
    }

    /**
     * The path of the temporary file of the uploaded file
     *
     * @param mixed $file The uploaded file
     * @return string|null Null if no file is uploaded
     */
    private function uploadedPath($file): ?string
    {
        $path = is_array($file) && is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
        if ($path === '' || ($file['error'] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        return $path;
    }

    /**
     * Reads the image once (the cache key includes the modification time and the size, so a new file
     * at the same path is read again)
     *
     * @param Model $model
     * @param string $path
     * @return array{type: string, width: int|float|null, height: int|float|null}|null
     */
    private function image(Model $model, string $path): ?array
    {
        clearstatcache(true, $path);
        $svg = $this->settings[$model->alias]['svg'] ?? [];
        $key = implode("\0", [$path, (string)@filemtime($path), (string)@filesize($path), serialize($svg)]); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
        if (!array_key_exists($key, $this->images)) {
            $this->images[$key] = ImageFile::read($path, $svg);
        }

        return $this->images[$key];
    }

    /**
     * The list of types (`jpg` is the same as `jpeg`)
     *
     * @param mixed $types
     * @return array<string>
     * @throws InvalidArgumentException If a type is not supported
     */
    private function types($types): array
    {
        $list = [];
        foreach ((array)$types as $type) {
            $type = strtolower((string)$type);
            $type = $type === 'jpg' ? ImageFile::JPEG : $type;
            if (!isset(ImageFile::EXTENSIONS[$type])) {
                throw new InvalidArgumentException(sprintf('The image type "%s" is not supported (%s)', $type, implode(', ', array_keys(ImageFile::EXTENSIONS))));
            }
            $list[] = $type;
        }

        return array_values(array_unique($list));
    }
}
