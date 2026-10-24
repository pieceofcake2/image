<?php

/**
 * Validates an SVG document against a strict allowlist
 *
 * An SVG file is an XML document that a browser renders as a web page when it is opened directly,
 * so an uploaded SVG can run scripts on the site's origin (stored XSS), load other resources, or
 * attack the XML parser (XXE, entity expansion). This validator accepts only static graphics: the
 * elements, attributes and CSS properties in the allowlists, with references only to fragments of
 * the same document (`#id`). Anything else makes the document invalid. It does not sanitize (remove
 * the disallowed parts and keep the rest): a document that is not valid must be rejected.
 *
 * Rejected, among others:
 *
 * - Scripts: `<script>`, event handler attributes (`onload` ...), `javascript:` and other URLs
 * - Embedded content and links: `<foreignObject>`, `<image>`, `<a>`, `<iframe>`, `<feImage>`
 * - Animations, which can change attributes such as `href` after the validation: `<animate>`, `<set>` ...
 * - References outside the document: `href`, `xlink:href` and `url()` that are not `#id`, `xml:base`
 * - XML features: DOCTYPE, entities, processing instructions (`<?xml-stylesheet?>`), encodings other than UTF-8
 * - Documents, attribute values or `<style>` contents larger than the limits (`maxBytes`, 64 KiB)
 * - Elements outside the SVG namespace (XHTML `<script>` and `<iframe>` run in an SVG document) and
 *   attributes in other namespaces (editor data such as `inkscape:*`; export the file as plain SVG)
 * - CSS other than declarations of the allowed properties with simple selectors: at-rules (`@import` ...),
 *   escapes, comments, `url()` that is not `#id`, functions such as `expression()`
 *
 * ```
 * $errors = (new SvgValidator())->validate(file_get_contents($path));
 * if ($errors !== []) {
 *     // reject the file
 * }
 * ```
 *
 * Options:
 *
 * - `maxBytes`: The maximum size of the document in bytes. Default 1048576 (1 MiB)
 * - `maxElements`: The maximum number of elements. Default 10000
 * - `maxDepth`: The maximum depth of nested elements. Default 64
 * - `allowStyle`: Allow CSS (`<style>` and the `style` attribute) with the allowed properties. Default true
 */
class SvgValidator
{
    public const NS_SVG = 'http://www.w3.org/2000/svg';
    public const NS_XLINK = 'http://www.w3.org/1999/xlink';
    public const NS_XML = 'http://www.w3.org/XML/1998/namespace';

    /**
     * Elements of static graphics (in the SVG namespace). `<title>`, `<desc>` and `<metadata>` may contain only text.
     */
    public const ELEMENTS = [
        // Structure
        'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'metadata', 'style',
        // Shapes and text
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'text', 'tspan', 'textPath',
        // Paint servers, clipping, masking and markers
        'linearGradient', 'radialGradient', 'stop', 'pattern', 'clipPath', 'mask', 'marker',
        // Filters (without feImage, which loads images)
        'filter', 'feBlend', 'feColorMatrix', 'feComponentTransfer', 'feFuncR', 'feFuncG', 'feFuncB', 'feFuncA',
        'feComposite', 'feConvolveMatrix', 'feDiffuseLighting', 'feDisplacementMap', 'feDistantLight',
        'feDropShadow', 'feFlood', 'feGaussianBlur', 'feMerge', 'feMergeNode', 'feMorphology', 'feOffset',
        'fePointLight', 'feSpecularLighting', 'feSpotLight', 'feTile', 'feTurbulence',
    ];

    /**
     * Elements that may contain only text (no child elements)
     */
    public const TEXT_ONLY_ELEMENTS = ['title', 'desc', 'metadata', 'style'];

    /**
     * Presentation attributes, which are also the CSS properties allowed in `<style>` and `style`
     */
    public const PROPERTIES = [
        'alignment-baseline', 'baseline-shift', 'clip', 'clip-path', 'clip-rule', 'color', 'color-interpolation',
        'color-interpolation-filters', 'color-rendering', 'direction', 'display', 'dominant-baseline',
        'enable-background', 'fill', 'fill-opacity', 'fill-rule', 'filter', 'flood-color', 'flood-opacity', 'font',
        'font-family', 'font-size', 'font-size-adjust', 'font-stretch', 'font-style', 'font-variant', 'font-weight',
        'image-rendering', 'isolation', 'letter-spacing', 'lighting-color', 'marker', 'marker-end', 'marker-mid',
        'marker-start', 'mask', 'mix-blend-mode', 'opacity', 'overflow', 'paint-order', 'shape-rendering',
        'stop-color', 'stop-opacity', 'stroke', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-linecap',
        'stroke-linejoin', 'stroke-miterlimit', 'stroke-opacity', 'stroke-width', 'text-anchor', 'text-decoration',
        'text-rendering', 'transform', 'transform-box', 'transform-origin', 'unicode-bidi', 'vector-effect',
        'visibility', 'word-spacing', 'writing-mode',
    ];

    /**
     * Attributes without a namespace that are allowed on any allowed element, besides PROPERTIES,
     * `data-*` and `aria-*`. `href` must be a fragment (`#id`).
     */
    public const ATTRIBUTES = [
        // Core and structure
        'id', 'class', 'style', 'lang', 'tabindex', 'role', 'focusable', 'version', 'baseProfile', 'x', 'y',
        'width', 'height', 'viewBox', 'preserveAspectRatio', 'transform', 'href', 'type', 'media',
        // Shapes and text
        'd', 'pathLength', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'points', 'dx', 'dy', 'rotate',
        'textLength', 'lengthAdjust', 'startOffset', 'method', 'spacing', 'side', 'path',
        // Paint servers, clipping, masking and markers
        'offset', 'gradientUnits', 'gradientTransform', 'spreadMethod', 'fx', 'fy', 'fr', 'patternUnits',
        'patternContentUnits', 'patternTransform', 'clipPathUnits', 'maskUnits', 'maskContentUnits', 'markerUnits',
        'markerWidth', 'markerHeight', 'refX', 'refY', 'orient',
        // Filters
        'filterUnits', 'primitiveUnits', 'in', 'in2', 'result', 'stdDeviation', 'mode', 'values', 'tableValues',
        'slope', 'intercept', 'amplitude', 'exponent', 'operator', 'k1', 'k2', 'k3', 'k4', 'order', 'kernelMatrix',
        'divisor', 'bias', 'targetX', 'targetY', 'edgeMode', 'kernelUnitLength', 'preserveAlpha', 'surfaceScale',
        'diffuseConstant', 'specularConstant', 'specularExponent', 'scale', 'xChannelSelector', 'yChannelSelector',
        'azimuth', 'elevation', 'z', 'pointsAtX', 'pointsAtY', 'pointsAtZ', 'limitingConeAngle', 'radius',
        'baseFrequency', 'numOctaves', 'seed', 'stitchTiles',
    ];

    /**
     * Default options
     */
    public const DEFAULTS = [
        'maxBytes' => 1048576,
        'maxElements' => 10000,
        'maxDepth' => 64,
        'allowStyle' => true,
    ];

    /**
     * A fragment reference (`#id`)
     */
    private const FRAGMENT = '#[A-Za-z_][A-Za-z0-9_.:-]*';

    /**
     * The maximum size of an attribute value and of the contents of `<style>`, besides `maxBytes`
     * (limits the work of the checks of one value)
     */
    private const MAX_VALUE_BYTES = 65536;

    /**
     * @var array{maxBytes: int, maxElements: int, maxDepth: int, allowStyle: bool}
     */
    private array $options;

    /**
     * @var array<string, true>
     */
    private array $elements;

    /**
     * @var array<string, true>
     */
    private array $attributes;

    /**
     * @var array<string, true>
     */
    private array $properties;

    /**
     * Errors of the document being validated
     *
     * @var array<string>
     */
    private array $errors = [];

    /**
     * @param array<string, mixed> $options See the class description
     */
    public function __construct(array $options = [])
    {
        $options += self::DEFAULTS;
        $this->options = [
            'maxBytes' => (int)$options['maxBytes'],
            'maxElements' => (int)$options['maxElements'],
            'maxDepth' => (int)$options['maxDepth'],
            'allowStyle' => (bool)$options['allowStyle'],
        ];
        $this->elements = array_fill_keys(self::ELEMENTS, true);
        $this->attributes = array_fill_keys(array_merge(self::ATTRIBUTES, self::PROPERTIES), true);
        $this->properties = array_fill_keys(self::PROPERTIES, true);
    }

    /**
     * The options in use
     *
     * @return array{maxBytes: int, maxElements: int, maxDepth: int, allowStyle: bool}
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Whether the contents look like an SVG document (an `<svg` start tag before any other element)
     *
     * This does not validate the document: use validate() before accepting it.
     *
     * @param string $contents
     * @return bool
     */
    public static function looksLikeSvg(string $contents): bool
    {
        $head = substr($contents, 0, 4096);
        if (str_starts_with($head, "\xEF\xBB\xBF")) {
            $head = substr($head, 3);
        }
        // XML declaration, comments, processing instructions and a DOCTYPE may come before the root element
        $head = (string)preg_replace('/\A(?:\s+|<\?.*?\?>|<!--.*?-->|<!DOCTYPE[^>\[]*(?:\[.*?\])?\s*>)*/is', '', $head);

        return preg_match('/\A<(?:[A-Za-z_][\w.-]*:)?svg[\s>\/]/', $head) === 1;
    }

    /**
     * Whether the document is valid
     *
     * @param string $svg The document
     * @return bool
     */
    public function isValid(string $svg): bool
    {
        return $this->validate($svg) === [];
    }

    /**
     * Validates the document
     *
     * @param string $svg The document
     * @return array<string> The reasons why the document is not valid (empty if it is valid). They are
     *   for logs and developers; do not show them to users as they are.
     */
    public function validate(string $svg): array
    {
        $this->errors = [];
        $dom = $this->parse($svg);
        if ($dom !== null) {
            $this->validateTree($dom);
        }

        return $this->errors;
    }

    /**
     * The width and height of a valid document: the `width` and `height` of the root element in pixels
     * (a number, or a number with `px`), or the size of its `viewBox`
     *
     * @param string $svg The document
     * @return array{0: float, 1: float}|null Null if the document is not valid or has no size in pixels
     */
    public function dimensions(string $svg): ?array
    {
        if (!$this->isValid($svg)) {
            return null;
        }
        $dom = $this->parse($svg);
        $root = $dom?->documentElement;
        if ($root === null) {
            return null;
        }

        $viewBox = preg_split('/[\s,]+/', trim($root->getAttribute('viewBox')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $box = count($viewBox) === 4 && is_numeric($viewBox[2]) && is_numeric($viewBox[3])
            ? [(float)$viewBox[2], (float)$viewBox[3]]
            : [null, null];
        $width = $this->pixels($root->getAttribute('width')) ?? $box[0];
        $height = $this->pixels($root->getAttribute('height')) ?? $box[1];
        if ($width === null || $height === null || $width <= 0 || $height <= 0) {
            return null;
        }

        return [$width, $height];
    }

    /**
     * @param string $value
     * @return float|null
     */
    private function pixels(string $value): ?float
    {
        return preg_match('/\A(\d++(?:\.\d++)?|\.\d++)(?:px)?\z/', trim($value), $matches) === 1 ? (float)$matches[1] : null;
    }

    /**
     * Checks the size, the encoding and the XML features, and parses the document
     *
     * @param string $svg
     * @return DOMDocument|null Null (with errors) if the document cannot be accepted
     */
    private function parse(string $svg): ?DOMDocument
    {
        if (strlen($svg) > $this->options['maxBytes']) {
            $this->errors[] = sprintf('The document is larger than %d bytes', $this->options['maxBytes']);

            return null;
        }
        // NUL bytes are valid UTF-8, but a document in UTF-16 or UTF-32 without a BOM is made of them:
        // libxml would read it in that encoding and skip the checks of the text below
        if (preg_match('//u', $svg) !== 1 || str_contains($svg, "\0")) {
            $this->errors[] = 'The document is not UTF-8';

            return null;
        }
        if (str_starts_with($svg, "\xEF\xBB\xBF")) {
            $svg = substr($svg, 3);
        }
        $declared = preg_match('/\A<\?xml\s[^>]*?\bencoding\s*=\s*["\']([^"\']*)["\']/i', $svg, $matches);
        if ($declared === false) {
            $this->errors[] = 'The XML declaration cannot be checked';

            return null;
        }
        if ($declared === 1 && strcasecmp($matches[1], 'UTF-8') !== 0) {
            $this->errors[] = sprintf('The encoding %s is not allowed (only UTF-8)', $matches[1]);

            return null;
        }
        // A DOCTYPE can declare entities (entity expansion, external entities): reject it before parsing
        if (stripos($svg, '<!DOCTYPE') !== false || stripos($svg, '<!ENTITY') !== false) {
            $this->errors[] = 'DOCTYPE and entity declarations are not allowed';

            return null;
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            // No LIBXML_NOENT (entity substitution) or LIBXML_DTDLOAD; no network access
            $loaded = $dom->loadXML($svg, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            $this->errors[] = 'The document is not well-formed XML';

            return null;
        }
        if ($dom->doctype !== null) {
            $this->errors[] = 'DOCTYPE is not allowed';

            return null;
        }

        return $dom;
    }

    /**
     * @param DOMDocument $dom
     * @return void
     */
    private function validateTree(DOMDocument $dom): void
    {
        foreach ($dom->childNodes as $node) {
            if ($node instanceof DOMProcessingInstruction) {
                $this->errors[] = sprintf('The processing instruction <?%s?> is not allowed', $node->target);
            }
        }
        $root = $dom->documentElement;
        if ($root === null || $root->namespaceURI !== self::NS_SVG || $root->localName !== 'svg') {
            $this->errors[] = 'The root element is not <svg> in the SVG namespace';

            return;
        }

        $count = 0;
        // Walk the tree without recursion: [node, depth]
        $stack = [[$root, 1]];
        while ($stack !== []) {
            [$element, $depth] = array_pop($stack);
            if (++$count > $this->options['maxElements']) {
                $this->errors[] = sprintf('The document has more than %d elements', $this->options['maxElements']);

                return;
            }
            if ($depth > $this->options['maxDepth']) {
                $this->errors[] = sprintf('The elements are nested more than %d levels', $this->options['maxDepth']);

                return;
            }
            $this->validateElement($element);

            $textOnly = in_array($element->localName, self::TEXT_ONLY_ELEMENTS, true);
            foreach ($element->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    if ($textOnly) {
                        $this->errors[] = sprintf('<%s> must contain only text, not <%s>', $element->localName, $child->nodeName);
                    } else {
                        $stack[] = [$child, $depth + 1];
                    }
                } elseif ($child instanceof DOMProcessingInstruction) {
                    $this->errors[] = sprintf('The processing instruction <?%s?> is not allowed', $child->target);
                } elseif ($child instanceof DOMEntityReference) {
                    $this->errors[] = sprintf('The entity reference &%s; is not allowed', $child->nodeName);
                }
            }
        }
    }

    /**
     * @param DOMElement $element
     * @return void
     */
    private function validateElement(DOMElement $element): void
    {
        $name = $element->nodeName;
        if ($element->namespaceURI !== self::NS_SVG || !isset($this->elements[$element->localName])) {
            $this->errors[] = sprintf('The element <%s> is not allowed', $name);

            return;
        }
        if ($element->localName === 'style') {
            if (!$this->options['allowStyle']) {
                $this->errors[] = 'The element <style> is not allowed';
            } else {
                $this->validateStyleSheet($element->textContent);
            }
        }

        foreach ($element->attributes as $attribute) {
            /** @var DOMAttr $attribute */
            $this->validateAttribute($element, $attribute);
        }
    }

    /**
     * @param DOMElement $element
     * @param DOMAttr $attribute
     * @return void
     */
    private function validateAttribute(DOMElement $element, DOMAttr $attribute): void
    {
        $namespace = (string)$attribute->namespaceURI;
        $name = $attribute->localName;
        $value = $attribute->value;
        $label = sprintf('The attribute %s of <%s>', $attribute->nodeName, $element->nodeName);
        if (strlen($value) > self::MAX_VALUE_BYTES) {
            $this->errors[] = sprintf('%s is larger than %d bytes', $label, self::MAX_VALUE_BYTES);

            return;
        }

        if ($namespace === self::NS_XLINK && $name === 'href') {
            $this->validateFragment($label, $value);

            return;
        }
        if ($namespace === self::NS_XML && ($name === 'space' || $name === 'lang')) {
            return;
        }
        if ($namespace !== '') {
            $this->errors[] = $label . ' is not allowed';

            return;
        }
        if (!isset($this->attributes[$name]) && !preg_match('/\A(?:data|aria)-[a-z0-9_.-]+\z/', $name)) {
            $this->errors[] = $label . ' is not allowed';

            return;
        }

        if ($name === 'href') {
            $this->validateFragment($label, $value);
        } elseif ($name === 'style') {
            if (!$this->options['allowStyle']) {
                $this->errors[] = $label . ' is not allowed';
            } else {
                $this->validateDeclarations($label, $value);
            }
        } elseif ($element->localName === 'style' && $name === 'type' && !in_array(strtolower(trim($value)), ['', 'text/css'], true)) {
            $this->errors[] = $label . ' must be text/css';
        } else {
            $this->validateValue($label, $value);
        }
    }

    /**
     * A reference must be a fragment of the same document (`#id`)
     *
     * @param string $label
     * @param string $value
     * @return void
     */
    private function validateFragment(string $label, string $value): void
    {
        if (preg_match('/\A' . self::FRAGMENT . '\z/', $value) !== 1) {
            $this->errors[] = $label . ' must refer to an element of the document (#id)';
        }
    }

    /**
     * An attribute value or a CSS value: no CSS escapes or comments (which can hide a function name),
     * and `url()` only with a fragment
     *
     * @param string $label
     * @param string $value
     * @return void
     */
    private function validateValue(string $label, string $value): void
    {
        if (str_contains($value, '\\') || str_contains($value, '/*')) {
            $this->errors[] = $label . ' must not contain CSS escapes or comments';

            return;
        }
        // A failure of a regular expression (false) rejects the value
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) !== 0) {
            $this->errors[] = $label . ' must not contain control characters';

            return;
        }
        $offset = 0;
        while (($found = preg_match('/url\s*+\(/i', $value, $matches, PREG_OFFSET_CAPTURE, $offset)) !== 0) {
            if ($found === false) {
                $this->errors[] = $label . ' cannot be checked';

                return;
            }
            $position = (int)$matches[0][1];
            if (preg_match('/\Gurl\(\s*(["\']?)' . self::FRAGMENT . '\1\s*\)/i', $value, $unused, 0, $position) !== 1) {
                $this->errors[] = $label . ' must use url() only with an element of the document (#id)';

                return;
            }
            $offset = $position + 4;
        }
    }

    /**
     * The value of a CSS property: letters, digits, colors, units, lengths, lists, quoted font names,
     * `!important` and the functions of colors and transforms (and `url(#id)`)
     *
     * @param string $label
     * @param string $value
     * @return void
     */
    private function validateCssValue(string $label, string $value): void
    {
        $value = trim($value);
        $count = count($this->errors);
        $this->validateValue($label, $value);
        if (count($this->errors) > $count) {
            return;
        }
        // Possessive quantifiers: the time is linear in the length of the value (no backtracking)
        if (preg_match('/\A[\p{L}\p{N}#%.,\s"\'+_-]*+(?:\([\p{L}\p{N}#%.,\s"\'+_-]*+\)[\p{L}\p{N}#%.,\s"\'+_-]*+)*+(?:!\s*+important)?+\z/u', $value) !== 1) {
            $this->errors[] = $label . ' has a CSS value that is not allowed: ' . $value;

            return;
        }
        $functions = preg_match_all('/([A-Za-z-]++)\s*+\(/', $value, $matches);
        if ($functions === false) {
            $this->errors[] = $label . ' cannot be checked';

            return;
        }
        if ($functions > 0) {
            foreach ($matches[1] as $function) {
                if (!in_array(strtolower($function), ['url', 'rgb', 'rgba', 'hsl', 'hsla', 'matrix', 'translate', 'translatex', 'translatey', 'scale', 'scalex', 'scaley', 'rotate', 'skew', 'skewx', 'skewy'], true)) {
                    $this->errors[] = sprintf('%s uses the CSS function %s(), which is not allowed', $label, $function);

                    return;
                }
            }
        }
    }

    /**
     * Declarations of a `style` attribute or a rule: `property: value;` of the allowed properties
     *
     * @param string $label
     * @param string $declarations
     * @return void
     */
    private function validateDeclarations(string $label, string $declarations): void
    {
        if (str_contains($declarations, '\\') || str_contains($declarations, '/*')) {
            $this->errors[] = $label . ' must not contain CSS escapes or comments';

            return;
        }
        foreach (explode(';', $declarations) as $declaration) {
            if (trim($declaration) === '') {
                continue;
            }
            $parts = explode(':', $declaration, 2);
            $property = strtolower(trim($parts[0]));
            if (count($parts) !== 2 || !isset($this->properties[$property])) {
                $this->errors[] = sprintf('%s has a CSS property that is not allowed: %s', $label, trim($parts[0]));

                continue;
            }
            $this->validateCssValue(sprintf('%s (%s)', $label, $property), trim($parts[1]));
        }
    }

    /**
     * The contents of `<style>`: rules with simple selectors (elements, classes, ids, `*` and combinators)
     * and declarations of the allowed properties; no at-rules
     *
     * @param string $css
     * @return void
     */
    private function validateStyleSheet(string $css): void
    {
        $label = 'The element <style>';
        if (strlen($css) > self::MAX_VALUE_BYTES) {
            $this->errors[] = sprintf('%s is larger than %d bytes', $label, self::MAX_VALUE_BYTES);

            return;
        }
        if (str_contains($css, '\\') || str_contains($css, '/*') || str_contains($css, '@') || str_contains($css, '<')) {
            $this->errors[] = $label . ' must not contain CSS escapes, comments, at-rules or "<"';

            return;
        }
        $rest = preg_replace_callback('/([^{}]*+)\{([^{}]*+)\}/', function (array $matches) use ($label): string {
            foreach (explode(',', $matches[1]) as $selector) {
                if (preg_match('/\A[A-Za-z0-9_.#*\s>+~-]++\z/', trim($selector)) !== 1) {
                    $this->errors[] = sprintf('%s has a selector that is not allowed: %s', $label, trim($selector));
                }
            }
            $this->validateDeclarations($label, $matches[2]);

            return '';
        }, $css);
        if ($rest === null) {
            $this->errors[] = $label . ' cannot be checked';

            return;
        }
        if (trim($rest) !== '') {
            $this->errors[] = $label . ' has CSS that is not a rule: ' . trim($rest);
        }
    }
}
