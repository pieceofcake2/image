<?php

/**
 * Reads the type and the size of an image file from its contents
 *
 * JPEG, PNG, GIF and WebP are read with getimagesize(). SVG is accepted only when the document is
 * valid for SvgValidator; its size is the `width` and `height` of the root element in pixels or the
 * size of its `viewBox`. The name of the file and the MIME type sent by the browser are not used.
 *
 * ```
 * $image = ImageFile::read($path); // ['type' => 'png', 'width' => 122, 'height' => 95] or null
 * $extension = ImageFile::extension($image['type']); // 'png'
 * ```
 */
class ImageFile
{
    public const JPEG = 'jpeg';
    public const PNG = 'png';
    public const GIF = 'gif';
    public const WEBP = 'webp';
    public const SVG = 'svg';

    /**
     * The types and the extensions of their files
     */
    public const EXTENSIONS = [
        self::JPEG => 'jpg',
        self::PNG => 'png',
        self::GIF => 'gif',
        self::WEBP => 'webp',
        self::SVG => 'svg',
    ];

    /**
     * The types and their MIME types
     */
    public const MIME_TYPES = [
        self::JPEG => 'image/jpeg',
        self::PNG => 'image/png',
        self::GIF => 'image/gif',
        self::WEBP => 'image/webp',
        self::SVG => 'image/svg+xml',
    ];

    /**
     * The raster types that getimagesize() reads (PHP 8.5+ also reads SVG, which is validated separately)
     */
    private const RASTER_TYPES = [
        IMAGETYPE_JPEG => self::JPEG,
        IMAGETYPE_PNG => self::PNG,
        IMAGETYPE_GIF => self::GIF,
        IMAGETYPE_WEBP => self::WEBP,
    ];

    /**
     * Reads the type and the size of the image
     *
     * @param string $path The path of the file
     * @param array<string, mixed> $svgOptions The options of SvgValidator
     * @return array{type: string, width: int|float|null, height: int|float|null}|null Null if the file is not
     *   an image of the supported types (or an SVG document that is not valid). The width and the height
     *   of an SVG document are floats, or null if it has no size in pixels.
     */
    public static function read(string $path, array $svgOptions = []): ?array
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $info = @getimagesize($path); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
        if ($info !== false && isset(self::RASTER_TYPES[$info[2]])) {
            return ['type' => self::RASTER_TYPES[$info[2]], 'width' => (int)$info[0], 'height' => (int)$info[1]];
        }

        return self::readSvg($path, $svgOptions);
    }

    /**
     * The extension of the files of the type
     *
     * @param string $type One of the type constants
     * @return string|null Null if the type is not supported
     */
    public static function extension(string $type): ?string
    {
        return self::EXTENSIONS[$type] ?? null;
    }

    /**
     * Reads a valid SVG document
     *
     * @param string $path
     * @param array<string, mixed> $svgOptions
     * @return array{type: string, width: float|null, height: float|null}|null
     */
    private static function readSvg(string $path, array $svgOptions): ?array
    {
        $validator = new SvgValidator($svgOptions);
        $maxBytes = $validator->getOptions()['maxBytes'];
        if ((int)filesize($path) > $maxBytes) {
            return null;
        }
        $contents = (string)file_get_contents($path, false, null, 0, $maxBytes + 1);
        if (!SvgValidator::looksLikeSvg($contents) || !$validator->isValid($contents)) {
            return null;
        }
        $size = $validator->dimensions($contents);

        return ['type' => self::SVG, 'width' => $size[0] ?? null, 'height' => $size[1] ?? null];
    }
}
