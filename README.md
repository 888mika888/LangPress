# Custom Website Translator

> A WordPress plugin for multilingual websites with a visual, manual translation editor — no duplicate pages, no auto-translation APIs, full control over every translated string.

![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue?logo=wordpress)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4?logo=php)
![License](https://img.shields.io/badge/License-GPL%20v2-green)
![Version](https://img.shields.io/badge/Version-1.2.0-orange)

---

## Overview

Custom Website Translator lets you translate any WordPress site into multiple languages by clicking directly on text in the live frontend. Translations are stored in a dedicated database table and applied dynamically — no extra pages or posts are ever created.

The visual editor works similarly to TranslatePress: a sidebar opens alongside the real page, you click on any text, and type your translation. The page content, theme, and WordPress structure are never modified.

**Default language:** German  
**Supported targets out of the box:** English, Ukrainian  
**Additional selectable languages:** French, Spanish, Italian, Turkish, Polish, Russian, Arabic

---

## Features

- **Visual Translation Editor** — click "Translate Page" in the admin bar; a sidebar opens on the live page with pencil icons on every text block
- **No duplicate pages** — translations stored in a custom DB table, applied via PHP output buffering at runtime
- **Manual control** — you choose what gets translated; ignored strings are never suggested again
- **Language switcher** — fixed-position dropdown or buttons, shortcode `[custom_language_switcher]`, or widget
- **Auto-activation** — saving a translation immediately makes it active; no extra confirmation step
- **Auto-migration** — DB schema upgrades run automatically on version change; no deactivate/reactivate needed
- **Secure** — nonces, `current_user_can()`, prepared statements, full input sanitization and output escaping
- **Performant** — one DB query per request loads the full translation map into memory; O(1) lookups at runtime

---

## Requirements

| | Minimum |
|---|---|
| WordPress | 6.0 |
| PHP | 8.1 |
| MySQL | 5.7 / MariaDB 10.3 |

---

## Installation

### ZIP upload

1. Download `custom-website-translator.zip` from [Releases](../../releases)
2. **WordPress Admin → Plugins → Add New → Upload Plugin**
3. Select the ZIP → **Install Now** → **Activate**

### FTP / manual

1. Copy the `custom-website-translator/` folder into `/wp-content/plugins/`
2. **WordPress Admin → Plugins → Activate**

On first activation the plugin creates its database tables and sets sensible defaults automatically.

---

## Quick Start

1. **Translator → Languages** — set German as default, enable English and Ukrainian
2. **Translator → Settings** — choose a position (e.g. *Bottom Right*) and tick **Fix switcher on screen**
3. Visit any page on your site → click **Translate Page** in the admin bar
4. Hover over any text → click the **✎** icon → type your translations → **Save**

That's it. The language switcher is now visible on every page for all visitors.

---

## Visual Translation Editor

Accessed via **Translate Page** in the WordPress admin bar (visible only to administrators).

```
┌─────────────────────┬──────────────────────────────────────────┐
│  Translation Editor │                                          │
│  ─────────────────  │        Live website preview              │
│  From German        │                                          │
│  ┌───────────────┐  │   ┌─────────────────────────────────┐    │
│  │ Original text │  │   │ ✎  Heading text                 │    │
│  └───────────────┘  │   └─────────────────────────────────┘    │
│  To English         │                                          │
│  ┌───────────────┐  │   Paragraph text here. More content      │
│  │               │  │   that can be clicked to translate. ✎    │
│  └───────────────┘  │                                          │
│  To Ukrainian       │                                          │
│  ┌───────────────┐  │                                          │
│  │               │  │                                          │
│  └───────────────┘  │                                          │
│  [ Save ]           │                                          │
└─────────────────────┴──────────────────────────────────────────┘
```

- Hover any text block → pencil icon appears
- Click pencil (or click the text) → original loads in sidebar, existing translations pre-filled
- Edit and click **Save** — translation is live immediately
- Regular visitors never see the editor UI or pencil icons

---

## Language Switcher

### Fixed position (recommended)

Enable **Fix switcher on screen** in settings. The dropdown appears on every page at your chosen corner.

### Shortcode

```
[custom_language_switcher]
```

### Widget

Add the **Language Switcher** widget to any sidebar or footer widget area.

### Switching behavior

| Action | Result |
|---|---|
| Click a language | Page reloads with `?cwt_lang=en`, cookie set, translations applied |
| Navigate to next page | Cookie keeps language active across the whole site |
| Text without translation | Falls back to the original text silently |

---

## Admin Panels

| Panel | Purpose |
|---|---|
| **Settings** | Switcher visibility (all pages / specific / exclude), position, fixed mode |
| **Languages** | Default language, active translation targets |
| **Translations** | Full table with search, status filter, inline editing, bulk delete |
| **Design** | Dropdown vs buttons, text/flag/both, colors, border radius, font size |
| **Import / Export** | Export all translations as JSON; import from a previous export |
| **Debug / Status** | System info, table check, cache clear, DB reinstall, translation counts |

---

## Shortcode

```
[custom_language_switcher]
```

Renders using the colors and style configured in the Design panel. No attributes required.

---

## How It Works

### Translation flow

1. Visitor requests a page
2. `CWT_Translator` reads the active language — URL param `?cwt_lang=` → cookie → site default
3. If not default: `CWT_Frontend` starts PHP output buffering
4. WordPress renders the full HTML; the buffer callback passes it to `translate_html()`
5. `DOMDocument` walks all visible text nodes and replaces matches from the in-memory cache
6. Scripts, styles, HTML attributes, IDs, classes are never modified

### Cache

All translations for the active language are loaded in a single query at the start of the request and held in a PHP array (keyed by SHA-256 hash). With a persistent cache backend (Redis, Memcached), `wp_cache_set()` stores the map for 5 minutes across requests.

---

## Developer Reference

### AJAX Endpoints

All endpoints POST to `wp-admin/admin-ajax.php`. Admin endpoints require a valid `cwt_admin_nonce` nonce and `manage_options` capability.

| Action | Auth | Key parameters |
|---|---|---|
| `cwt_get_translation` | admin | `original`, `post_id` |
| `cwt_save_translation` | admin | `original`, `en`, `uk`, `post_id` |
| `cwt_get_page_translations` | admin | `language_code`, `post_id` |
| `cwt_update_status` | admin | `id`, `status` |
| `cwt_delete_translation` | admin | `hash` |
| `cwt_clear_cache` | admin | — |
| `cwt_reinstall_db` | admin | — |
| `cwt_export` | admin | `nonce` (GET) |
| `cwt_switch_lang` | public | `lang`, `nonce` |

### Database

**`{prefix}cwt_translations`**

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED | PK |
| `post_id` | BIGINT UNSIGNED NULL | Optional page/post ID |
| `original_text` | LONGTEXT | Source text |
| `normalized_text` | LONGTEXT | Trimmed, whitespace-collapsed |
| `text_hash` | VARCHAR(64) | SHA-256 lookup key |
| `language_code` | VARCHAR(10) | `en`, `uk`, `fr` … |
| `translated_text` | LONGTEXT | Translation |
| `status` | ENUM | `active` / `pending` / `ignored` |
| `page_url` | VARCHAR(2083) | Where text was first found |
| `created_at` | DATETIME | |
| `updated_at` | DATETIME | |

Unique index on `(text_hash, language_code)`.

### File structure

```
custom-website-translator/
├── custom-website-translator.php        # Bootstrap, constants, autoloader
├── includes/
│   ├── class-cwt-activator.php          # DB install, default options
│   ├── class-cwt-database.php           # CRUD, schema migration
│   ├── class-cwt-translator.php         # Language detection, cache, DOM walk
│   ├── class-cwt-frontend.php           # Output buffering, admin bar, editor mode
│   ├── class-cwt-language-switcher.php  # Switcher HTML, shortcode, widget, AJAX
│   └── class-cwt-admin.php              # Admin pages, AJAX handlers
├── public/
│   ├── public.{css,js}                  # Language switcher frontend
│   ├── translate-mode.{css,js}          # Floating quick-translate sidebar
│   └── translation-editor.{css,js}      # Full visual editor (pencil icons)
├── admin/
│   └── admin.{css,js}                   # WordPress admin panel UI
└── languages/                           # WP i18n .po / .mo files
```

---

## Security

| Measure | Detail |
|---|---|
| Nonces | Every AJAX and form action verified with `check_ajax_referer()` |
| Capability | All writes require `current_user_can('manage_options')` |
| Sanitization | `sanitize_textarea_field`, `sanitize_key`, `sanitize_hex_color`, `absint` |
| Escaping | `esc_html`, `esc_attr`, `esc_url`, `esc_textarea` on all output |
| SQL | `$wpdb->prepare()` on every query; `$wpdb->insert` / `update` for writes |
| XSS | `DOMDocument` replaces text nodes only — no raw HTML injection |
| Cookie | `HttpOnly`, `Secure` on HTTPS, `SameSite=Lax` |

---

## Contributing

Pull requests are welcome. For significant changes please open an issue first to discuss the approach.

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/my-change`)
3. Commit your changes
4. Open a pull request

Please follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/) for PHP code.

---

## License

Distributed under the **GNU General Public License v2 or later**.  
See the plugin file header or [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html) for the full license text.

---

## Changelog

### 1.2.0
- Visual Translation Editor with admin-bar "Translate Page" button
- Left sidebar with pencil icons on every translatable block
- Multi-language save (EN + UK) in one AJAX call
- `post_id` and `normalized_text` columns; auto DB migration on version change
- Safe column-existence check prevents INSERT failures on old schemas

### 1.1.0
- Floating sidebar quick-translate mode
- Dynamic default-language flag (not hardcoded to German)
- `status_filter` now correctly applied in translations query

### 1.0.1
- Delete by hash removes all language entries (not just the first)
- `cwt_clear_cache` and `cwt_reinstall_db` endpoints implemented
- Translations auto-activate on save
- Fixed-position switcher now appears on all pages regardless of page filter

### 1.0.0
- Initial release: output-buffer translation, language switcher, admin panel, import/export
