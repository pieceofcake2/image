<?php

/**
 * Creates image files for the tests in a temporary directory, removed in tearDown()
 */
trait ImageTestTrait
{
    /**
     * @var array<string>
     */
    private array $files = [];

    /**
     * Writes a file with the contents
     *
     * @param string $contents
     * @return string The path
     */
    protected function file(string $contents): string
    {
        $path = (string)tempnam(sys_get_temp_dir(), 'image-test-');
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }

    /**
     * Writes a raster image made with GD
     *
     * @param string $type jpeg, png, gif or webp
     * @param int $width
     * @param int $height
     * @return string The path
     */
    protected function raster(string $type, int $width, int $height): string
    {
        $function = 'image' . $type;
        if (!function_exists($function)) {
            $this->markTestSkipped(sprintf('GD cannot write %s images', $type));
        }
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        $function($image);

        return $this->file((string)ob_get_clean());
    }

    /**
     * Writes an SVG document
     *
     * @param string $contents The contents of the root element
     * @param string $attributes The attributes of the root element
     * @return string The path
     */
    protected function svg(string $contents = '', string $attributes = 'width="122" height="95"'): string
    {
        return $this->file('<svg xmlns="http://www.w3.org/2000/svg" ' . $attributes . '>' . $contents . '</svg>');
    }

    /**
     * Removes the files
     *
     * @return void
     */
    protected function removeFiles(): void
    {
        foreach ($this->files as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->files = [];
    }
}
