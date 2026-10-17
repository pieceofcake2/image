# CHANGELOG

## 1.0.0 - 2026-10-18

First release of `pieceofcake2/image`.

- `ImageValidationBehavior` with the rules `imageType`, `imageWidth`, `imageHeight`, `imageMaxWidth`,
  `imageMaxHeight`, `imageMinWidth` and `imageMinHeight`, and the extension of the saved file
  (`imageExtension()`, `ImageValidationBehavior::extensionOf()`)
- JPEG, PNG, GIF and WebP read from the contents with `getimagesize()`
- SVG validated by `SvgValidator` against a strict allowlist of static graphics (no scripts, event
  handlers, external references, animations, DOCTYPE or encodings other than UTF-8)
- PHPUnit tests, PHP_CodeSniffer and PHPStan (level 5) in CI
- Documentation in English and Japanese
