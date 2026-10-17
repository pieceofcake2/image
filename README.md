# CakePHP 2 Image Plugin

[![GitHub License](https://img.shields.io/github/license/pieceofcake2/image?label=License)](LICENSE)
[![Packagist Version](https://img.shields.io/packagist/v/pieceofcake2/image?label=Packagist)](https://packagist.org/packages/pieceofcake2/image)
![PHP](https://img.shields.io/packagist/dependency-v/pieceofcake2/image/php?logo=php&logoColor=%23FFFFFF&label=PHP&labelColor=%23777BB4&color=%23FFFFFF)
![CakePHP](https://img.shields.io/packagist/dependency-v/pieceofcake2/image/pieceofcake2/cakephp?logo=cakephp&logoColor=%23FFFFFF&label=CakePHP&labelColor=%23D33C43&color=%23FFFFFF)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%205-brightgreen?label=PHPStan)](https://github.com/pieceofcake2/image/actions/workflows/CI.yml)
[![CI](https://img.shields.io/github/actions/workflow/status/pieceofcake2/image/CI.yml?label=CI)](https://github.com/pieceofcake2/image/actions/workflows/CI.yml)
[![Codecov](https://img.shields.io/codecov/c/gh/pieceofcake2/image?label=Coverage)](https://codecov.io/gh/pieceofcake2/image)

A plugin for [pieceofcake2/cakephp](https://github.com/pieceofcake2/cakephp) applications that
validates uploaded images by their contents: the type (JPEG, PNG, GIF, WebP and SVG) and the size,
and the extension to give the saved file. SVG documents are accepted only if they contain nothing
but static graphics, with a strict allowlist: no scripts, event handlers, external references,
animations or XML entities.

## Documentation

- Website: https://pieceofcake2.github.io/image/ (日本語: https://pieceofcake2.github.io/image/ja/)
- Markdown: [docs/README.md](docs/README.md) (日本語: [docs/ja/README.md](docs/ja/README.md)) (for AI tools and offline reading)

It covers the validation rules, the settings, what SVG documents are accepted and how to serve
uploaded SVG files safely.

The Markdown documentation is also included in the Composer package, in the plugin's directory: `plugins/Image/docs/README.md` (the location depends on your `installer-paths`).

## Requirements

* PHP 8.0+ with the `dom` and `libxml` extensions
* pieceofcake2/cakephp 2.12+

## Installation

```bash
composer require pieceofcake2/image
```

Load the plugin in `config/bootstrap.php` (legacy layout: `app/Config/bootstrap.php`):

```php
CakePlugin::load('Image');
```

See [Installation](docs/README.md#installation) for the `installer-paths` setting.

## Usage

```php
class Hotel extends AppModel
{
    public $actsAs = ['Image.ImageValidation'];

    public $validate = [
        'photo' => [
            'type' => ['rule' => ['imageType'], 'message' => 'Select a JPEG or PNG image'],
            'width' => ['rule' => ['imageWidth', 295], 'message' => 'The width must be 295px'],
        ],
        'logo' => [
            'type' => ['rule' => ['imageType', ['jpeg', 'png', 'svg']], 'message' => 'Select a JPEG, PNG or SVG image'],
        ],
    ];
}
```

Save the file with the extension of its contents (`$this->Hotel->imageExtension($file['tmp_name'])`),
and serve uploaded SVG files with a restrictive `Content-Security-Policy`. See
[Validation Rules](docs/README.md#validation-rules) and
[Serving Uploaded SVG Files](docs/README.md#serving-uploaded-svg-files).

## Contributing

See the [contribution guidelines](https://github.com/pieceofcake2/.github/blob/master/CONTRIBUTING.md).

## License

Licensed under the MIT License. See [LICENSE](LICENSE).
