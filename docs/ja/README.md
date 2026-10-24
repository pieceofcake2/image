# Image プラグインドキュメント

Image プラグインは、アップロードされた画像をファイルの中身で検証します。モデルのビヘイビアが、
アップロードされたファイルの種類 (JPEG、PNG、GIF、WebP、SVG) と大きさを確かめ、保存するファイルに
付ける拡張子を返します。種類はいつもファイルの中身から判定し、攻撃者が自由に決められるファイル名や
ブラウザが送る MIME タイプは使いません。

SVG はほかの種類と違います。SVG ファイルは XML の文書で、直接開くとブラウザが Web ページとして表示する
ため、アップロードされた SVG はサイト上でスクリプトを実行できます (格納型 XSS)。このプラグインは、
静的な図形だけでできた SVG だけを、厳しい許可リストで受け付けます。`svg` を明示して許可しない限り、
SVG は受け付けません。

- [インストール](#installation)
- [使い方](#usage)
- [検証のルール](#validation-rules)
- [設定](#settings)
- [保存するファイルの名前](#naming-the-saved-file)
- [SVG](#svg)
- [アップロードされた SVG の配信](#serving-uploaded-svg-files)
- [クラスを直接使う](#using-the-classes-directly)

<a id="installation"></a>

## インストール

動作要件: PHP 8.0 以上 (`dom`・`libxml` 拡張)、[pieceofcake2/cakephp](https://github.com/pieceofcake2/cakephp) 2.12 以上。

```bash
composer require pieceofcake2/image
```

プラグインのタイプは `cakephp-plugin` なので、`composer/installers` によってプラグインディレクトリに
インストールされます。アプリケーションの `composer.json` で `installer-paths` を設定してください。

| レイアウト | `installer-paths` |
| --- | --- |
| モダン (`config/`、`src/`、`templates/`、`plugins/`) | `"plugins/{$name}/": ["type:cakephp-plugin"]` |
| レガシー (`app/Config/`、`app/Plugin/`) | `"app/Plugin/{$name}/": ["type:cakephp-plugin"]` |

`config/bootstrap.php` (レガシーレイアウトでは `app/Config/bootstrap.php`) でプラグインを読み込みます。
すでに `CakePlugin::loadAll()` を使っている場合は不要です。

```php
CakePlugin::load('Image');
```

プラグインのクラス (`ImageValidationBehavior`、`ImageFile`、`SvgValidator`) は Composer が読み込むため、
`App::uses()` は不要です。

<a id="usage"></a>

## 使い方

モデルにビヘイビアを付け、アップロードされたファイルのフィールドにルールを書きます。

```php
class Article extends AppModel
{
    public $actsAs = ['Image.ImageValidation'];

    public $validate = [
        'photo' => [
            'type' => ['rule' => ['imageType'], 'message' => 'JPEG・PNG の画像を選んでください'],
            'width' => ['rule' => ['imageWidth', 295], 'message' => '幅を 295px にしてください'],
            'height' => ['rule' => ['imageHeight', 215], 'message' => '高さを 215px にしてください'],
        ],
    ];
}
```

フィールドの値は、PHP が渡すアップロードされたファイルの情報です (`$this->request->data['Article']['photo']`。
`name`、`type`、`tmp_name`、`error`、`size` を持つ)。

- ファイルがアップロードされていない場合 (`tmp_name` が無い、またはエラーが `UPLOAD_ERR_NO_FILE`)、
  ルールは成功します。ファイルが必須なら別のルールを足し、ほかのアップロードのエラー
  (`UPLOAD_ERR_INI_SIZE` など) はアプリケーションで確かめてください。
- 許可した種類の画像でないファイルは、大きさのルールを含め、すべてのルールで失敗します。
- 複数のルールで確かめても、ファイルはモデルごとに 1 度だけ読みます。

<a id="validation-rules"></a>

## 検証のルール

| ルール | パラメータ | 成功する条件 |
| --- | --- | --- |
| `imageType` | 許可する種類 (省略可) | ファイルが許可した種類の画像: パラメータ、または `types` の設定 (既定は JPEG と PNG) |
| `imageWidth` | ピクセル | 幅がその値 |
| `imageHeight` | ピクセル | 高さがその値 |
| `imageMaxWidth` | ピクセル | 幅がその値以下 |
| `imageMaxHeight` | ピクセル | 高さがその値以下 |
| `imageMinWidth` | ピクセル | 幅がその値以上 |
| `imageMinHeight` | ピクセル | 高さがその値以上 |

種類は `jpeg` (または `jpg`)、`png`、`gif`、`webp`、`svg` です。

```php
'logo' => [
    'type' => ['rule' => ['imageType', ['jpeg', 'png', 'svg']], 'message' => 'JPEG・PNG・SVG の画像を選んでください'],
    'width' => ['rule' => ['imageMaxWidth', 600], 'message' => '幅を 600px 以下にしてください'],
],
```

SVG の大きさは、ルート要素の `width` と `height` (数値、または数値と `px`)、無ければ `viewBox` の大きさです。
この方法で大きさが読めない文書 (`viewBox` が無く `width="10mm"` など) は、大きさのルールで失敗します。

<a id="settings"></a>

## 設定

```php
public $actsAs = [
    'Image.ImageValidation' => [
        'types' => ['jpeg', 'png', 'webp'],
        'svg' => ['maxBytes' => 524288],
    ],
];
```

| 設定 | 既定値 | 説明 |
| --- | --- | --- |
| `types` | `['jpeg', 'png']` | パラメータの無い `imageType` で許可する種類。知らない種類は `InvalidArgumentException` |
| `svg` | `[]` | SVG の検証のオプション ([SVG](#svg) を参照) |

<a id="naming-the-saved-file"></a>

## 保存するファイルの名前

保存するファイルには、アップロードされたファイル名の拡張子ではなく、中身の種類の拡張子を付けてください。
`photo.png` という名前のファイルが SVG やスクリプトのこともあります。Web サーバーやストレージ (S3 など) は、
拡張子から `Content-Type` を決めます。

```php
// モデルの設定 (SVG のオプション) で読む
$extension = $this->Article->imageExtension($file['tmp_name']); // 'jpg'、'png'、'gif'、'webp'、'svg'、または null

// モデルを使わない (SVG のオプションは既定値)
$extension = ImageValidationBehavior::extensionOf($file['tmp_name']);
```

保存する前に検証してください。画像でないファイルの拡張子は `null` です。

<a id="svg"></a>

## SVG

SVG の文書は、中身がすべて `SvgValidator` の許可リストにあるときだけ受け付けます。許可していない部分を
取り除いて残りを使う (サニタイズ) のではなく、許可していないものが 1 つでもあれば文書ごと拒否します。
そのため、保存するのは確かめたものそのものです。

許可するもの:

- SVG の名前空間の、静的な図形の要素: `svg`、`g`、`defs`、`symbol`、`use`、`title`、`desc`、`metadata`、
  `style`、図形 (`path`、`rect`、`circle`、`ellipse`、`line`、`polyline`、`polygon`)、テキスト
  (`text`、`tspan`、`textPath`)、グラデーションとパターン、`clipPath`、`mask`、`marker`、フィルター
  (`filter` と、`feImage` を除く `fe*`)
- それらの形・フィルターの属性、プレゼンテーション属性 (`fill`、`stroke` など)、`id`、`class`、`data-*`、
  `aria-*`、`role`、`xml:space`、`xml:lang`
- 同じ文書の中の要素への参照だけ: `href` と `xlink:href` は `#id`、`url()` は `url(#id)`
- `<style>` と `style` 属性の CSS: 単純なセレクター (要素・クラス・ID・`*`・結合子) のルールと、
  プレゼンテーションのプロパティの宣言。値は色・数値・単位・名前と、関数 `rgb()`・`rgba()`・`hsl()`・
  `hsla()`・変形の関数。Adobe Illustrator が内部の CSS で書き出す図形は、この形です

拒否するもの (主なもの):

- スクリプト: `<script>`、イベントハンドラの属性 (`onload` など)、`javascript:` などの URL
- 埋め込みとリンク: `<foreignObject>`、`<image>`、`<a>`、`<iframe>`、`<feImage>`
- アニメーション (`<animate>`、`<set>` など)。検証した後に `href` などの属性を変えられるため
- 文書の外への参照: `#id` でない `href`・`xlink:href`・`url()`、`xml:base`
- XML の機能: DOCTYPE (実体・外部実体も)、処理命令 (`<?xml-stylesheet?>`)、UTF-8 以外の文字コード
  (BOM の無いものを含む UTF-16・UTF-32)
- SVG の名前空間の外の要素 (SVG の文書の中で実行される XHTML の `<script>`・`<iframe>` など) と、ほかの
  名前空間の属性 (Inkscape の編集の情報 `inkscape:*`・`sodipodi:*` など)。「プレーン SVG」や
  「最適化 SVG」で保存してください
- CSS の at ルール (`@import`、`@font-face` など)、エスケープ、コメント、属性セレクター、疑似クラス、
  プレゼンテーションのプロパティでないプロパティ、ほかの関数 (`expression()`、`attr()`、`var()` など)
- `maxBytes` より大きい文書、`maxElements` より多い要素、`maxDepth` より深い入れ子、64 KiB より大きい
  属性の値や `<style>` の中身

オプション (ビヘイビアの `svg` の設定、または `SvgValidator` のコンストラクタ):

| オプション | 既定値 | 説明 |
| --- | --- | --- |
| `maxBytes` | `1048576` (1 MiB) | 文書の最大の大きさ |
| `maxElements` | `10000` | 要素の最大の数 |
| `maxDepth` | `64` | 要素の入れ子の最大の深さ |
| `allowStyle` | `true` | CSS (`<style>` と `style` 属性) を許可する。許可しない場合は、プレゼンテーション属性だけを許可する |

文書は libxml で、ネットワークに接続せず、実体を置き換えず、DTD を読まずに解析します。DOCTYPE は解析の前に拒否します。

<a id="serving-uploaded-svg-files"></a>

## アップロードされた SVG の配信

SVG ファイルを検証したうえで、すり抜けた文書があっても害が出ないように配信してください。次の対策は
プラグインの外で行います。

- アップロードされた SVG は `<img>` (または CSS の背景) で表示してください。この方法で表示した SVG の
  画像では、ブラウザはスクリプトを実行せず、ほかのリソースも読み込みません。`<object>`、`<embed>`、
  `<iframe>` や HTML へのインラインでは表示しないでください。
- ファイルを直接開くと、あなたのオリジンの文書になります。アップロードされたファイルには、次のヘッダーを
  付けて配信してください (Web サーバー、または CDN のレスポンスヘッダーのポリシーで)。

  ```
  Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox
  X-Content-Type-Options: nosniff
  ```

  `sandbox` は文書をスクリプトの無い一意のオリジンで実行します。`style-src 'unsafe-inline'` は図形の
  内部の CSS を残します。
- できれば、アップロードされたファイルはアプリケーションと別のドメイン (CDN の別のドメインやストレージ)
  から配信してください。文書からアプリケーションの Cookie やページに触れられなくなります。
- ファイルは中身の種類の拡張子で保存してください ([保存するファイルの名前](#naming-the-saved-file) を参照)。

<a id="using-the-classes-directly"></a>

## クラスを直接使う

`ImageFile::read()` はファイルの種類と大きさを読みます (対応する種類の画像でない場合、または検証を
通らない SVG の場合は `null`)。

```php
$image = ImageFile::read($path); // ['type' => 'png', 'width' => 122, 'height' => 95]
$image = ImageFile::read($path, ['maxBytes' => 524288]); // SVG のオプションを付けて読む
ImageFile::extension($image['type']); // 'png'
```

`SvgValidator::validate()` は、文書が検証を通らない理由を返します (通る場合は空)。ログや開発者のための
もので、そのまま利用者に見せないでください。

```php
$validator = new SvgValidator(['allowStyle' => false]);
$errors = $validator->validate($contents);
$size = $validator->dimensions($contents); // [122.0, 95.0]、または null
```

PHP 8.5 以上では `getimagesize()` も SVG を読みますが、検証はしません。SVG を受け付ける判定には使わないでください。
