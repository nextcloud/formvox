# Third-party code in lib/Vendor

Built by `composer vendor:pdf` (scripts/vendor-pdf.php) on 2026-10-26.
Every file was changed by Strauss: its namespace now starts with OCA\FormVox\Vendor\, so no other app's copy can clash with it.
dompdf/php-font-lib src/FontLib/TrueType/File.php was also patched in two lines to build its class names under that namespace.
Each package keeps its own licence file; the unchanged originals are at the sources below.

| Package | Version | Licence | Source |
|---|---|---|---|
| dompdf/dompdf | v3.1.6 | LGPL-2.1 | https://github.com/dompdf/dompdf.git |
| dompdf/php-font-lib | 1.0.2 | LGPL-2.1-or-later | https://github.com/dompdf/php-font-lib.git |
| dompdf/php-svg-lib | 1.0.2 | LGPL-3.0-or-later | https://github.com/dompdf/php-svg-lib.git |
| masterminds/html5 | 2.12.0 | MIT | https://github.com/Masterminds/html5-php.git |
| sabberworm/php-css-parser | v9.5.0 | MIT | https://github.com/MyIntervals/PHP-CSS-Parser.git |

The DejaVu Sans fonts in dompdf/dompdf/lib/fonts come with dompdf and are under the Bitstream Vera and Arev licences, with DejaVu's changes in the public domain: see LICENSES/DejaVu-Fonts.txt.
