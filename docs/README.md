# Image Plugin Documentation

The Image plugin validates uploaded images by their contents. Its model behavior checks the type
(JPEG, PNG, GIF, WebP and SVG) and the size of an uploaded file, and tells which extension to give the
saved file. The type is always read from the contents of the file, never from its name or from the
MIME type sent by the browser, which an attacker controls.

SVG is different from the other types: an SVG file is an XML document that a browser renders as a
web page when it is opened directly, so an uploaded SVG can run scripts on your site (stored XSS).
The plugin accepts an SVG document only if it contains nothing but static graphics, with a strict
allowlist. It is disabled unless you allow `svg` explicitly.

- [Installation](#installation)
- [Usage](#usage)
- [Validation Rules](#validation-rules)
- [Settings](#settings)
- [Naming the Saved File](#naming-the-saved-file)
- [SVG](#svg)
- [Serving Uploaded SVG Files](#serving-uploaded-svg-files)
- [Using the Classes Directly](#using-the-classes-directly)

## Installation

Requirements: PHP 8.0+ with the `dom` and `libxml` extensions,
[pieceofcake2/cakephp](https://github.com/pieceofcake2/cakephp) 2.13+.

```bash
composer require pieceofcake2/image
```

The plugin has the `cakephp-plugin` type, so it is installed into your plugins directory by
`composer/installers`. Set `installer-paths` in your application's `composer.json`:

| Layout | `installer-paths` |
| --- | --- |
| Modern (`config/`, `src/`, `templates/`, `plugins/`) | `"plugins/{$name}/": ["type:cakephp-plugin"]` |
| Legacy (`app/Config/`, `app/Plugin/`) | `"app/Plugin/{$name}/": ["type:cakephp-plugin"]` |

Load the plugin in `config/bootstrap.php` (legacy layout: `app/Config/bootstrap.php`). If you
already use `CakePlugin::loadAll()`, this is not necessary:

```php
CakePlugin::load('Image');
```

Composer loads the classes of the plugin (`ImageValidationBehavior`, `ImageFile` and `SvgValidator`),
so they need no `App::uses()`.

## Usage

Attach the behavior to the model and add the rules to the field of the uploaded file:

```php
class Article extends AppModel
{
    public $actsAs = ['Image.ImageValidation'];

    public $validate = [
        'photo' => [
            'type' => ['rule' => ['imageType'], 'message' => 'Select a JPEG or PNG image'],
            'width' => ['rule' => ['imageWidth', 295], 'message' => 'The width must be 295px'],
            'height' => ['rule' => ['imageHeight', 215], 'message' => 'The height must be 215px'],
        ],
    ];
}
```

The value of the field is the uploaded file as PHP gives it (`$this->request->data['Article']['photo']`
with `name`, `type`, `tmp_name`, `error` and `size`).

- The rules pass when no file is uploaded (no `tmp_name`, or the error `UPLOAD_ERR_NO_FILE`). Add a
  rule of your own if the file is required, and check the other upload errors (`UPLOAD_ERR_INI_SIZE`
  ...) yourself.
- A file that is not an image of the allowed types fails every rule, including the size rules.
- Each file is read once per model, even when several rules check it.

## Validation Rules

| Rule | Parameters | Passes when |
| --- | --- | --- |
| `imageType` | the allowed types (optional) | the file is an image of the allowed types: the parameter, or the `types` setting (default JPEG and PNG) |
| `imageWidth` | pixels | the width is the value |
| `imageHeight` | pixels | the height is the value |
| `imageMaxWidth` | pixels | the width is the value or less |
| `imageMaxHeight` | pixels | the height is the value or less |
| `imageMinWidth` | pixels | the width is the value or more |
| `imageMinHeight` | pixels | the height is the value or more |

The types are `jpeg` (or `jpg`), `png`, `gif`, `webp` and `svg`:

```php
'logo' => [
    'type' => ['rule' => ['imageType', ['jpeg', 'png', 'svg']], 'message' => 'Select a JPEG, PNG or SVG image'],
    'width' => ['rule' => ['imageMaxWidth', 600], 'message' => 'The width must be 600px or less'],
],
```

The size of an SVG document is the `width` and `height` of its root element in pixels (a number, or a
number with `px`), or the size of its `viewBox`. A document whose size cannot be read this way (for
example `width="10mm"` without a `viewBox`) fails the size rules.

## Settings

```php
public $actsAs = [
    'Image.ImageValidation' => [
        'types' => ['jpeg', 'png', 'webp'],
        'svg' => ['maxBytes' => 524288],
    ],
];
```

| Setting | Default | Description |
| --- | --- | --- |
| `types` | `['jpeg', 'png']` | The types allowed by `imageType` without parameters. An unknown type throws `InvalidArgumentException` |
| `svg` | `[]` | The options of the SVG validation (see [SVG](#svg)) |

## Naming the Saved File

Give the saved file the extension of its contents, not the extension of the uploaded name: a file
named `photo.png` may be an SVG document or a script. The web server and the storage (S3, ...)
decide the `Content-Type` from the extension.

```php
// With the settings of the model (the SVG options)
$extension = $this->Article->imageExtension($file['tmp_name']); // 'jpg', 'png', 'gif', 'webp', 'svg' or null

// Without a model (the default SVG options)
$extension = ImageValidationBehavior::extensionOf($file['tmp_name']);
```

Validate the file before you save it; the extension is `null` if the file is not an image.

## SVG

An SVG document is accepted only if everything in it is in the allowlists of `SvgValidator`. The
validator does not sanitize (remove the parts that are not allowed and keep the rest): a document
with anything that is not allowed is rejected, so what you save is exactly what was checked.

Allowed:

- Elements of static graphics in the SVG namespace: `svg`, `g`, `defs`, `symbol`, `use`, `title`,
  `desc`, `metadata`, `style`, shapes (`path`, `rect`, `circle`, `ellipse`, `line`, `polyline`,
  `polygon`), text (`text`, `tspan`, `textPath`), gradients and patterns, `clipPath`, `mask`,
  `marker`, and filters (`filter` and the `fe*` primitives except `feImage`)
- Their geometry and filter attributes, the presentation attributes (`fill`, `stroke`, ...), `id`,
  `class`, `data-*`, `aria-*`, `role`, `xml:space` and `xml:lang`
- References to elements of the same document only: `href` and `xlink:href` must be `#id`, and
  `url()` must be `url(#id)`
- CSS in `<style>` and the `style` attribute: rules with simple selectors (elements, classes, ids,
  `*` and combinators) and declarations of the presentation properties, with values made of
  colors, numbers, units, names and the functions `rgb()`, `rgba()`, `hsl()`, `hsla()` and the
  transform functions. Graphics exported by Adobe Illustrator with internal CSS use this form

Rejected, among others:

- Scripts: `<script>`, event handler attributes (`onload` ...), `javascript:` and other URLs
- Embedded content and links: `<foreignObject>`, `<image>`, `<a>`, `<iframe>`, `<feImage>`
- Animations (`<animate>`, `<set>` ...), which can change attributes such as `href` after the validation
- References outside the document: `href`, `xlink:href` and `url()` that are not `#id`, `xml:base`
- XML features: a DOCTYPE (and so entities and external entities), processing instructions
  (`<?xml-stylesheet?>`), encodings other than UTF-8 (UTF-16 and UTF-32, also without a BOM)
- Elements outside the SVG namespace, such as XHTML `<script>` and `<iframe>`, which run in an SVG
  document, and attributes in other namespaces, such as the editor data of Inkscape
  (`inkscape:*`, `sodipodi:*`). Save the file as "Plain SVG" or "Optimized SVG"
- CSS at-rules (`@import`, `@font-face` ...), escapes, comments, attribute selectors and
  pseudo-classes, properties that are not presentation properties, other functions
  (`expression()`, `attr()`, `var()` ...)
- Documents larger than `maxBytes`, with more than `maxElements` elements or nested deeper than
  `maxDepth`, and attribute values or `<style>` contents larger than 64 KiB

Options (the `svg` setting of the behavior, or the constructor of `SvgValidator`):

| Option | Default | Description |
| --- | --- | --- |
| `maxBytes` | `1048576` (1 MiB) | The maximum size of the document |
| `maxElements` | `10000` | The maximum number of elements |
| `maxDepth` | `64` | The maximum depth of nested elements |
| `allowStyle` | `true` | Allow CSS (`<style>` and the `style` attribute). Without it, only presentation attributes are allowed |

The document is parsed by libxml without network access, entity substitution or loading of a DTD,
and a DOCTYPE is rejected before parsing.

## Serving Uploaded SVG Files

Validate SVG files, and also serve them so that a document that gets through cannot do harm.
These measures are outside the plugin:

- Show uploaded SVG files with `<img>` (or as a CSS background). A browser does not run scripts or
  load other resources in an SVG image shown this way. Do not show them with `<object>`, `<embed>`,
  `<iframe>` or inline in the HTML.
- When the file is opened directly, it is a document of your origin. Send these headers with
  uploaded files (with the web server, or a response headers policy of the CDN):

  ```
  Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox
  X-Content-Type-Options: nosniff
  ```

  `sandbox` runs the document in a unique origin without scripts; `style-src 'unsafe-inline'` keeps
  the internal CSS of the graphics.
- Better, serve uploaded files from a domain other than your application (a separate domain of the
  CDN, or the storage), so that the documents have no access to your cookies and pages.
- Save the file with the extension of its contents (see [Naming the Saved File](#naming-the-saved-file)).

## Using the Classes Directly

`ImageFile::read()` reads the type and the size of a file (`null` if it is not an image of the
supported types, or an SVG document that is not valid):

```php
$image = ImageFile::read($path); // ['type' => 'png', 'width' => 122, 'height' => 95]
$image = ImageFile::read($path, ['maxBytes' => 524288]); // with SVG options
ImageFile::extension($image['type']); // 'png'
```

`SvgValidator::validate()` returns the reasons why a document is not valid (empty if it is valid).
They are for logs and developers; do not show them to users as they are:

```php
$validator = new SvgValidator(['allowStyle' => false]);
$errors = $validator->validate($contents);
$size = $validator->dimensions($contents); // [122.0, 95.0], or null
```

On PHP 8.5+, `getimagesize()` also reads SVG files: it does not validate them, so do not use it to
accept SVG.
