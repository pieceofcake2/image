<?php

App::uses('SvgValidator', 'Image.Lib');

/**
 * SvgValidator: valid static graphics are accepted, and every known way to run scripts, load
 * resources or attack the XML parser is rejected
 */
class SvgValidatorTest extends CakeTestCase
{
    private const SVG = 'xmlns="http://www.w3.org/2000/svg"';
    private const XLINK = 'xmlns:xlink="http://www.w3.org/1999/xlink"';

    /**
     * @return array<string, array{string}>
     */
    public static function validProvider(): array
    {
        $svg = self::SVG;
        $xlink = self::XLINK;

        return [
            'minimal' => ["<svg {$svg}/>"],
            'shapes' => ["<svg {$svg} width=\"122\" height=\"95\" viewBox=\"0 0 122 95\"><rect x=\"0\" y=\"0\" width=\"10\" height=\"10\" fill=\"#fff\"/><circle cx=\"5\" cy=\"5\" r=\"2\" stroke=\"red\" stroke-width=\"1.5\"/><path d=\"M0 0L10 10Z\"/></svg>"],
            'Illustrator export' => [
                "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<!-- Generator: Adobe Illustrator 28.0.0 -->\n"
                . "<svg id=\"_レイヤー_1\" data-name=\"レイヤー 1\" {$svg} viewBox=\"0 0 122 95\" xml:space=\"preserve\">"
                . '<defs><style>.cls-1{fill:#231815;}.cls-2{fill:none;stroke:#00abe1;stroke-miterlimit:10;}</style></defs>'
                . '<g><path class="cls-1" d="M10 10h20v20H10z"/><polygon class="cls-2" points="1,1 2,2 3,1"/></g></svg>',
            ],
            'CDATA in style' => ["<svg {$svg}><style><![CDATA[ g > .a, #b { fill: rgb(0, 0, 0); opacity: .5 !important } ]]></style><g class=\"a\"/></svg>"],
            'gradient and url(#id)' => ["<svg {$svg}><defs><linearGradient id=\"g\" x1=\"0\" y1=\"0\" x2=\"1\" y2=\"1\"><stop offset=\"0\" stop-color=\"#000\"/><stop offset=\"1\" style=\"stop-color:#fff\"/></linearGradient></defs><rect width=\"1\" height=\"1\" fill=\"url(#g)\" style=\"stroke: url('#g')\"/></svg>"],
            'use with href and xlink:href' => ["<svg {$svg} {$xlink}><defs><symbol id=\"s\"><path d=\"M0 0h1v1z\"/></symbol></defs><use href=\"#s\"/><use xlink:href=\"#s\" x=\"2\"/></svg>"],
            'text with a Japanese font' => ["<svg {$svg}><text x=\"0\" y=\"10\" style=\"font-family:'ヒラギノ角ゴ ProN', sans-serif; font-size: 12px\">ヒルトン &amp; <tspan dy=\"2\">ロゴ</tspan></text></svg>"],
            'filter' => ["<svg {$svg}><filter id=\"f\"><feGaussianBlur in=\"SourceGraphic\" stdDeviation=\"2\" result=\"b\"/><feOffset dx=\"1\" dy=\"1\"/><feMerge><feMergeNode in=\"b\"/></feMerge></filter><g filter=\"url(#f)\"/></svg>"],
            'clip path and mask' => ["<svg {$svg}><clipPath id=\"c\"><rect width=\"1\" height=\"1\"/></clipPath><mask id=\"m\"><rect width=\"1\" height=\"1\" fill=\"#fff\"/></mask><g clip-path=\"url(#c)\" mask=\"url(#m)\"/></svg>"],
            'title, desc and metadata' => ["<svg {$svg} role=\"img\" aria-label=\"Logo\"><title>Logo</title><desc>A logo</desc><metadata>Made by hand</metadata></svg>"],
            'transform' => ["<svg {$svg}><g transform=\"matrix(1 0 0 1 10 10) rotate(45)\" style=\"transform: translate(1px, 2px) scale(2)\"/></svg>"],
            'BOM' => ["\xEF\xBB\xBF<svg {$svg}/>"],
            'prefixed SVG namespace' => ['<svg:svg xmlns:svg="http://www.w3.org/2000/svg"><svg:rect width="1" height="1"/></svg:svg>'],
            'comment' => ["<svg {$svg}><!-- <script>alert(1)</script> --></svg>"],
        ];
    }

    /**
     * Static graphics are valid
     *
     * @dataProvider validProvider
     * @param string $svg
     * @return void
     */
    public function testValidDocument(string $svg): void
    {
        $this->assertSame([], (new SvgValidator())->validate($svg));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidProvider(): array
    {
        $svg = self::SVG;
        $xlink = self::XLINK;

        return [
            // Scripts
            'script' => ["<svg {$svg}><script>alert(1)</script></svg>", 'The element <script> is not allowed'],
            'script with CDATA' => ["<svg {$svg}><script><![CDATA[alert(1)]]></script></svg>", '<script>'],
            'script with an uppercase name' => ["<svg {$svg}><SCRIPT>alert(1)</SCRIPT></svg>", '<SCRIPT>'],
            'XHTML script' => ["<svg {$svg}><html:script xmlns:html=\"http://www.w3.org/1999/xhtml\">alert(1)</html:script></svg>", '<html:script>'],
            'XHTML script with a default namespace' => ["<svg {$svg}><g><script xmlns=\"http://www.w3.org/1999/xhtml\">alert(1)</script></g></svg>", '<script>'],
            'element without a namespace' => ["<svg {$svg}><g xmlns=\"\"/></svg>", '<g>'],
            'onload' => ["<svg {$svg} onload=\"alert(1)\"/>", 'onload'],
            'onLoad (mixed case)' => ["<svg {$svg} onLoad=\"alert(1)\"/>", 'onLoad'],
            'onclick on a shape' => ["<svg {$svg}><rect width=\"1\" height=\"1\" onclick=\"alert(1)\"/></svg>", 'onclick'],
            'handler in the SVG namespace' => ["<svg {$svg} xmlns:svg=\"http://www.w3.org/2000/svg\" svg:onload=\"alert(1)\"/>", 'svg:onload'],
            'nested svg with onload' => ["<svg {$svg}><svg onload=\"alert(1)\"/></svg>", 'onload'],
            'handler element' => ["<svg {$svg}><handler type=\"application/ecmascript\">alert(1)</handler></svg>", '<handler>'],
            // Links and references
            'a with javascript:' => ["<svg {$svg} {$xlink}><a xlink:href=\"javascript:alert(1)\"><rect width=\"1\" height=\"1\"/></a></svg>", '<a>'],
            'use with javascript:' => ["<svg {$svg}><use href=\"javascript:alert(1)\"/></svg>", 'must refer to an element of the document'],
            'use with an external document' => ["<svg {$svg} {$xlink}><use xlink:href=\"https://evil.example/x.svg#a\"/></svg>", 'must refer to an element of the document'],
            'use with a data URI' => ["<svg {$svg}><use href=\"data:image/svg+xml;base64,PHN2Zy8+#a\"/></svg>", 'must refer to an element of the document'],
            'use with javascript: in character references' => ["<svg {$svg}><use href=\"&#x6a;avascript&#58;alert(1)\"/></svg>", 'must refer to an element of the document'],
            'use with a leading space' => ["<svg {$svg}><use href=\" #a\"/></svg>", 'must refer to an element of the document'],
            'textPath with an external document' => ["<svg {$svg}><text><textPath href=\"https://evil.example/p.svg#p\">x</textPath></text></svg>", 'must refer to an element of the document'],
            'xlink:href on a gradient' => ["<svg {$svg} {$xlink}><linearGradient xlink:href=\"https://evil.example/g.svg#g\"/></svg>", 'must refer to an element of the document'],
            'other xlink attribute' => ["<svg {$svg} {$xlink}><g xlink:show=\"new\"/></svg>", 'xlink:show'],
            'xml:base' => ["<svg {$svg} xml:base=\"https://evil.example/\"/>", 'xml:base'],
            // Embedded content
            'image' => ["<svg {$svg}><image href=\"data:image/svg+xml,&lt;svg/&gt;\"/></svg>", '<image>'],
            'feImage' => ["<svg {$svg}><filter id=\"f\"><feImage href=\"https://evil.example/a.png\"/></filter></svg>", '<feImage>'],
            'foreignObject' => ["<svg {$svg}><foreignObject width=\"1\" height=\"1\"><iframe xmlns=\"http://www.w3.org/1999/xhtml\" src=\"javascript:alert(1)\"/></foreignObject></svg>", '<foreignObject>'],
            'iframe' => ["<svg {$svg}><iframe src=\"https://evil.example/\"/></svg>", '<iframe>'],
            'switch' => ["<svg {$svg}><switch><g/></switch></svg>", '<switch>'],
            // Animations can change attributes after the validation
            'animate href' => ["<svg {$svg}><use href=\"#a\"><animate attributeName=\"href\" values=\"javascript:alert(1)\"/></use></svg>", '<animate>'],
            'set onload' => ["<svg {$svg}><set attributeName=\"onload\" to=\"alert(1)\"/></svg>", '<set>'],
            'animateTransform' => ["<svg {$svg}><animateTransform attributeName=\"transform\" type=\"rotate\"/></svg>", '<animateTransform>'],
            // CSS
            'style with @import' => ["<svg {$svg}><style>@import url(https://evil.example/a.css);</style></svg>", 'at-rules'],
            'style with @font-face' => ["<svg {$svg}><style>@font-face { font-family: a; src: url(https://evil.example/a.woff) }</style></svg>", 'at-rules'],
            'style with an external url()' => ["<svg {$svg}><style>.a { fill: url(https://evil.example/a.svg#g) }</style></svg>", 'url()'],
            'style with url(javascript:)' => ["<svg {$svg}><style>.a { fill: url(javascript:alert(1)) }</style></svg>", 'url()'],
            'style with an escape' => ["<svg {$svg}><style>.a { fill: \\75 rl(https://evil.example/) }</style></svg>", 'escapes'],
            'style with a comment' => ["<svg {$svg}><style>.a { fill: ur/**/l(https://evil.example/) }</style></svg>", 'comments'],
            'style with expression()' => ["<svg {$svg}><style>.a { fill: expression(alert(1)) }</style></svg>", 'not allowed: expression(alert(1))'],
            'style with another function' => ["<svg {$svg}><style>.a { fill: attr(href) }</style></svg>", 'attr()'],
            'style with behavior' => ["<svg {$svg}><style>.a { behavior: url(#a) }</style></svg>", 'behavior'],
            'style with -moz-binding' => ["<svg {$svg}><style>.a { -moz-binding: url(#a) }</style></svg>", '-moz-binding'],
            'style with background-image' => ["<svg {$svg}><style>.a { background-image: url(#a) }</style></svg>", 'background-image'],
            'style with an attribute selector' => ["<svg {$svg}><style>a[href] { fill: red }</style></svg>", 'selector'],
            'style with a pseudo-class' => ["<svg {$svg}><style>.a:hover { fill: red }</style></svg>", 'selector'],
            'style with text outside rules' => ["<svg {$svg}><style>fill: red</style></svg>", 'not a rule'],
            'style with a script type' => ["<svg {$svg}><style type=\"text/javascript\">.a { fill: red }</style></svg>", 'text/css'],
            'style with a child element' => ["<svg {$svg}><style><g/></style></svg>", 'must contain only text'],
            'style attribute with an external url()' => ["<svg {$svg}><rect style=\"fill: url(https://evil.example/#g)\"/></svg>", 'url()'],
            'style attribute with an unknown property' => ["<svg {$svg}><rect style=\"content: 'a'\"/></svg>", 'content'],
            'style attribute with an escape' => ["<svg {$svg}><rect style=\"fill: \\75 rl(x)\"/></svg>", 'escapes'],
            'presentation attribute with an external url()' => ["<svg {$svg}><rect fill=\"url(https://evil.example/#g)\"/></svg>", 'url()'],
            'presentation attribute with an escape' => ["<svg {$svg}><rect fill=\"\\75 rl(https://evil.example/)\"/></svg>", 'escapes'],
            'presentation attribute with a control character' => ["<svg {$svg}><rect fill=\"red&#x1;\"/></svg>", 'well-formed'],
            // XML
            'DOCTYPE' => ["<!DOCTYPE svg PUBLIC \"-//W3C//DTD SVG 1.1//EN\" \"http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd\"><svg {$svg}/>", 'DOCTYPE'],
            'external entity (XXE)' => ["<?xml version=\"1.0\"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM \"file:///etc/passwd\">]><svg {$svg}><text>&xxe;</text></svg>", 'DOCTYPE'],
            'entity expansion (billion laughs)' => ["<?xml version=\"1.0\"?><!DOCTYPE lolz [<!ENTITY lol \"lol\"><!ENTITY lol2 \"&lol;&lol;&lol;&lol;\">]><svg {$svg}><text>&lol2;</text></svg>", 'DOCTYPE'],
            'xml-stylesheet' => ["<?xml-stylesheet href=\"https://evil.example/a.css\"?><svg {$svg}/>", 'processing instruction'],
            'processing instruction in the document' => ["<svg {$svg}><?php echo 1; ?></svg>", 'processing instruction'],
            'UTF-16' => [mb_convert_encoding("<?xml version=\"1.0\" encoding=\"UTF-16\"?><svg {$svg}/>", 'UTF-16', 'UTF-8'), 'not UTF-8'],
            'UTF-16 without a declaration' => [mb_convert_encoding("<svg {$svg}><script>alert(1)</script></svg>", 'UTF-16LE', 'UTF-8'), 'not UTF-8'],
            'UTF-16 with a BOM' => ["\xFF\xFE" . mb_convert_encoding("<svg {$svg}/>", 'UTF-16LE', 'UTF-8'), 'not UTF-8'],
            'NUL byte' => ["<svg {$svg}>\0</svg>", 'not UTF-8'],
            'other encoding' => ["<?xml version=\"1.0\" encoding=\"ISO-8859-1\"?><svg {$svg}/>", 'ISO-8859-1'],
            'not well-formed' => ["<svg {$svg}><g></svg>", 'well-formed'],
            'undefined entity' => ["<svg {$svg}><text>&nbsp;</text></svg>", 'well-formed'],
            'HTML' => ['<html><body><svg ' . $svg . '/></body></html>', 'root element'],
            'svg without the namespace' => ['<svg onload="alert(1)"/>', 'root element'],
            'metadata with elements' => ["<svg {$svg}><metadata><rdf:RDF xmlns:rdf=\"http://www.w3.org/1999/02/22-rdf-syntax-ns#\"/></metadata></svg>", 'must contain only text'],
            'title with elements' => ["<svg {$svg}><title><g/></title></svg>", 'must contain only text'],
            'editor attribute' => ["<svg {$svg} xmlns:inkscape=\"http://www.inkscape.org/namespaces/inkscape\" inkscape:version=\"1.0\"/>", 'inkscape:version'],
        ];
    }

    /**
     * Scripts, links, embedded content, animations, CSS other than the allowed declarations and the XML
     * features are rejected
     *
     * @dataProvider invalidProvider
     * @param string $svg
     * @param string $error A part of the expected error
     * @return void
     */
    public function testInvalidDocument(string $svg, string $error): void
    {
        $errors = (new SvgValidator())->validate($svg);

        $this->assertNotSame([], $errors);
        $this->assertStringContainsString($error, implode("\n", $errors));
    }

    /**
     * The size, the number of elements and the depth are limited
     *
     * @return void
     */
    public function testLimits(): void
    {
        $svg = self::SVG;

        $this->assertStringContainsString('larger than 100 bytes', implode('', (new SvgValidator(['maxBytes' => 100]))->validate("<svg {$svg}>" . str_repeat(' ', 100) . '</svg>')));
        $this->assertSame([], (new SvgValidator(['maxElements' => 3]))->validate("<svg {$svg}><g/><g/></svg>"));
        $this->assertStringContainsString('more than 3 elements', implode('', (new SvgValidator(['maxElements' => 3]))->validate("<svg {$svg}><g/><g/><g/></svg>")));
        $this->assertSame([], (new SvgValidator(['maxDepth' => 3]))->validate("<svg {$svg}><g><g/></g></svg>"));
        $this->assertStringContainsString('more than 3 levels', implode('', (new SvgValidator(['maxDepth' => 3]))->validate("<svg {$svg}><g><g><g/></g></g></svg>")));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function slowInputProvider(): array
    {
        $svg = self::SVG;
        $spaces = str_repeat(' ', 65000);

        return [
            'style attribute' => ["<svg {$svg}><rect style=\"fill:red{$spaces}\$\"/></svg>"],
            'style attribute with tabs' => ["<svg {$svg}><rect style=\"fill:red" . str_repeat("\t", 65000) . '$"/></svg>'],
            'style element' => ["<svg {$svg}><style>rect{fill:red{$spaces}\$}</style></svg>"],
            'selector' => ["<svg {$svg}><style>.a{$spaces}\${fill:red}</style></svg>"],
            'width' => ["<svg {$svg} width=\"1{$spaces}x\" height=\"1\"/>"],
            'url()' => ["<svg {$svg}><rect fill=\"" . str_repeat('url(', 16000) . '"/></svg>'],
        ];
    }

    /**
     * Inputs that make regular expressions backtrack are checked in linear time (no denial of service)
     *
     * @dataProvider slowInputProvider
     * @param string $svg
     * @return void
     */
    public function testSlowInputIsCheckedQuickly(string $svg): void
    {
        $validator = new SvgValidator();
        $start = microtime(true);
        $validator->validate($svg);
        $validator->dimensions($svg);

        $this->assertLessThan(1.0, microtime(true) - $start);
    }

    /**
     * An attribute value and the contents of <style> are limited to 64 KiB
     *
     * @return void
     */
    public function testValueSizeIsLimited(): void
    {
        $validator = new SvgValidator(['maxBytes' => 4194304]);
        $long = str_repeat('a', 65537);

        $this->assertStringContainsString('larger than 65536 bytes', implode('', $validator->validate('<svg ' . self::SVG . '><g id="' . $long . '"/></svg>')));
        $this->assertStringContainsString('larger than 65536 bytes', implode('', $validator->validate('<svg ' . self::SVG . '><style>.' . $long . '{fill:red}</style></svg>')));
        $this->assertSame([], $validator->validate('<svg ' . self::SVG . '><g id="' . substr($long, 1) . '"/></svg>'));
    }

    /**
     * Without allowStyle, CSS is rejected
     *
     * @return void
     */
    public function testStyleCanBeDisallowed(): void
    {
        $validator = new SvgValidator(['allowStyle' => false]);

        $this->assertStringContainsString('<style>', implode('', $validator->validate('<svg ' . self::SVG . '><style>.a{fill:red}</style></svg>')));
        $this->assertStringContainsString('style', implode('', $validator->validate('<svg ' . self::SVG . '><rect style="fill:red"/></svg>')));
        $this->assertSame([], $validator->validate('<svg ' . self::SVG . '><rect fill="red"/></svg>'));
    }

    /**
     * @return array<string, array{string, array{float, float}|null}>
     */
    public static function dimensionsProvider(): array
    {
        $svg = self::SVG;

        return [
            'width and height' => ["<svg {$svg} width=\"122\" height=\"95\"/>", [122.0, 95.0]],
            'pixels' => ["<svg {$svg} width=\"122px\" height=\"95.5px\"/>", [122.0, 95.5]],
            'viewBox' => ["<svg {$svg} viewBox=\"0 0 122 95\"/>", [122.0, 95.0]],
            'viewBox with commas' => ["<svg {$svg} viewBox=\"0,0,122,95\"/>", [122.0, 95.0]],
            'percentages and viewBox' => ["<svg {$svg} width=\"100%\" height=\"100%\" viewBox=\"0 0 122 95\"/>", [122.0, 95.0]],
            'width and viewBox' => ["<svg {$svg} width=\"244\" viewBox=\"0 0 122 95\"/>", [244.0, 95.0]],
            'other units' => ["<svg {$svg} width=\"10mm\" height=\"10mm\"/>", null],
            'no size' => ["<svg {$svg}/>", null],
            'zero' => ["<svg {$svg} width=\"0\" height=\"95\"/>", null],
            'not valid' => ["<svg {$svg} width=\"122\" height=\"95\" onload=\"alert(1)\"/>", null],
        ];
    }

    /**
     * The size is the width and height in pixels, or the size of the viewBox
     *
     * @dataProvider dimensionsProvider
     * @param string $svg
     * @param array{float, float}|null $expected
     * @return void
     */
    public function testDimensions(string $svg, ?array $expected): void
    {
        $this->assertSame($expected, (new SvgValidator())->dimensions($svg));
    }

    /**
     * An SVG document is recognized by its root element
     *
     * @return void
     */
    public function testLooksLikeSvg(): void
    {
        $this->assertTrue(SvgValidator::looksLikeSvg('<svg xmlns="http://www.w3.org/2000/svg"/>'));
        $this->assertTrue(SvgValidator::looksLikeSvg("\xEF\xBB\xBF<?xml version=\"1.0\"?>\n<!-- comment -->\n<svg>"));
        $this->assertTrue(SvgValidator::looksLikeSvg('<!DOCTYPE svg><svg:svg xmlns:svg="http://www.w3.org/2000/svg">'));
        $this->assertFalse(SvgValidator::looksLikeSvg('<html><svg/></html>'));
        $this->assertFalse(SvgValidator::looksLikeSvg('svg'));
        $this->assertFalse(SvgValidator::looksLikeSvg("\x89PNG\r\n"));
    }
}
