# Third-party notices

NBH Performance scopes bundled PHP dependencies under `NBH\Performance\Vendor` to prevent collisions with WordPress plugins and themes. Only open-source dependency code is included; no proprietary WP Rocket or Perfmatters application code is copied.

| Package | Bundled version | License | Purpose |
|---|---:|---|---|
| `matthiasmullie/minify` | 1.3.75 | MIT | Production CSS and JavaScript minification |
| `matthiasmullie/path-converter` | 1.1.3 | MIT | URL/path handling required by the CSS minifier |
| `sabberworm/php-css-parser` | 9.3.0 | MIT | Parser-backed CSS validation and local used-CSS fallback |

The corresponding license texts are retained inside each package directory.


Human-readable dependency sources are bundled under `vendor/nbh-performance/`. Their namespaces have been prefixed to avoid collisions. Upstream projects:

- https://github.com/matthiasmullie/minify
- https://github.com/matthiasmullie/path-converter
- https://github.com/sabberworm/PHP-CSS-Parser

## Bundled dashboard font

Outfit variable font is bundled unmodified in `assets/fonts/Outfit-VariableFont_wght.ttf` under the SIL Open Font License 1.1. Its copyright and complete license are included in `assets/fonts/OFL-Outfit.txt`. Source: https://github.com/google/fonts/tree/main/ofl/outfit . The dashboard loads this local file; no remote font request is made.
