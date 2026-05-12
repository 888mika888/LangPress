# Custom Website Translator

A WordPress plugin for multilingual websites with a visual, manual translation editor — no duplicate pages, no auto-translation APIs, full control over every translated string.

![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue?logo=wordpress)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4?logo=php)
![License](https://img.shields.io/badge/License-GPL%20v2-green)
![Version](https://img.shields.io/badge/Version-1.2.0-orange)

> **Note:** This plugin was built for a specific use case and is not actively maintained. Pull requests are welcome but response times may be slow.

---

## What it does

Custom Website Translator lets you translate a WordPress site into multiple languages by clicking directly on text in the live frontend. Translations are stored in a custom database table and applied dynamically at request time — no extra pages or posts are ever created.

When you click **Translate Page** in the admin bar, a sidebar opens alongside your live page. You hover over any text, click the pencil icon, type your translation, and save. That's the whole workflow.

**Ships with:** German (default), English, Ukrainian  
**Also available:** French, Spanish, Italian, Turkish, Polish, Russian, Arabic

---

## Features

- **Visual editor** — translate text directly on the live page without touching the WordPress editor
- **Human translations only** — no auto-translation; every string is written by a human, ensuring accuracy and tone
- **No duplicate pages** — translations are swapped in via PHP output buffering at runtime
- **Manual control** — you decide what gets translated; ignored strings are never suggested again
- **Language switcher** — fixed-position dropdown or buttons that appear on every page
- **Auto-activation** — saving a translation makes it live immediately
- **Auto-migration** — database schema updates run automatically on version change
- **Secure** — nonces, capability checks, prepared statements, sanitized inputs throughout

---

## Requirements

| | Minimum |
|---|---|
| WordPress | 6.0 |
| PHP | 8.1 |
| MySQL | 5.7 / MariaDB 10.3 |

---

## Installation

**Via ZIP (easiest)**

1. Download `custom-website-translator.zip` from [Releases](../../releases)
2. Go to **WordPress Admin → Plugins → Add New → Upload Plugin**
3. Select the ZIP → **Install Now** → **Activate**

**Via FTP**

1. Copy the `custom-website-translator/` folder to `/wp-content/plugins/`
2. Go to **WordPress Admin → Plugins** and activate it

The plugin creates its database tables and sets default options automatically on first activation.

---

## Quick Start

1. Go to **Translator → Languages** — confirm German as default, enable English and Ukrainian
2. Go to **Translator → Settings** — pick a position (e.g. *Bottom Right*) and enable **Fix switcher on screen**
3. Visit any page on your site as an admin → click **Translate Page** in the top bar
4. Hover over any text → click the **✎** icon → type your translation → **Save**

The language switcher will now appear on every page for all visitors.

---

## Using the Visual Editor

The editor is opened via **Translate Page** in the WordPress admin bar. It is only visible to administrators.

```
┌─────────────────────┬──────────────────────────────────────────┐
│  Translation Editor │                                          │
│  ─────────────────  │        Your live website                 │
│  From German        │                                          │
│  ┌───────────────┐  │   ┌─────────────────────────────────┐    │
│  │ Original text │  │   │ ✎  Heading text                 │    │
│  └───────────────┘  │   └─────────────────────────────────┘    │
│  To English         │                                          │
│  ┌───────────────┐  │   Paragraph text here. Click any         │
│  │               │  │   text block to translate it. ✎          │
│  └───────────────┘  │                                          │
│  To Ukrainian       │                                          │
│  ┌───────────────┐  │                                          │
│  │               │  │                                          │
│  └───────────────┘  │                                          │
│  [ Save ]           │                                          │
└─────────────────────┴──────────────────────────────────────────┘
```

- Hover any text block → a pencil icon appears
- Click the pencil (or the text itself) → the original text loads in the sidebar; any existing translations are pre-filled
- Edit the translation fields and click **Save** — it goes live immediately
- Regular visitors never see the editor or pencil icons

---

## Language Switcher

There are three ways to add the language switcher to your site:

**Fixed position (recommended)** — enable **Fix switcher on screen** in Settings. The switcher appears in your chosen corner on every page automatically.

**Shortcode** — paste this anywhere in your content or templates:
```
[custom_language_switcher]
```

**Widget** — add the **Language Switcher** widget to any sidebar or footer area via **Appearance → Widgets**.

When a visitor clicks a language, the page reloads with `?cwt_lang=en` (or `uk`) appended, a cookie is set, and the translated version is shown. The cookie keeps the language active as they navigate the site. Any text without a translation falls back to the original silently.

---

## Admin Panels

| Panel | What it does |
|---|---|
| **Settings** | Control which pages show the switcher, its position, and fixed mode |
| **Languages** | Set the default language and choose which languages are active |
| **Translations** | Browse, search, and edit all translations in one table |
| **Design** | Style the switcher: dropdown vs buttons, text/flag/both, colors, sizes |
| **Import / Export** | Back up and restore translations as JSON |
| **Debug / Status** | Check system info, clear cache, reinstall DB tables |

---

## How It Works

When a visitor loads a page in a non-default language:

1. `CWT_Translator` detects the active language — URL param → cookie → site default
2. `CWT_Frontend` starts PHP output buffering
3. WordPress renders the full page HTML
4. The buffer callback passes the HTML to `translate_html()`
5. `DOMDocument` walks all visible text nodes and replaces any that have a translation in the in-memory cache
6. Scripts, styles, HTML attributes, IDs, and classes are never touched

**Cache:** All translations for the active language are loaded in a single query at the start of the request and held in a PHP array keyed by SHA-256 hash. With a persistent cache backend (Redis, Memcached), `wp_cache_set()` stores the map for 5 minutes across requests, so most page loads never hit the database at all.

---

## Developer Reference

### AJAX Endpoints

All endpoints POST to `wp-admin/admin-ajax.php`. Admin endpoints require a valid `cwt_admin_nonce` nonce and the `manage_options` capability.

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
| `id` | BIGINT UNSIGNED | Primary key |
| `post_id` | BIGINT UNSIGNED NULL | Optional — links translation to a specific post/page |
| `original_text` | LONGTEXT | The source text as found on the page |
| `normalized_text` | LONGTEXT | Trimmed, whitespace-collapsed version for matching |
| `text_hash` | VARCHAR(64) | SHA-256 of the normalized text — used as the lookup key |
| `language_code` | VARCHAR(10) | e.g. `en`, `uk`, `fr` |
| `translated_text` | LONGTEXT | The translation |
| `status` | ENUM | `active` / `pending` / `ignored` |
| `page_url` | VARCHAR(2083) | URL where the text was first detected |
| `created_at` | DATETIME | |
| `updated_at` | DATETIME | |

Unique index on `(text_hash, language_code)`.

### File structure

```
custom-website-translator/
├── custom-website-translator.php        # Bootstrap, constants, autoloader
├── includes/
│   ├── class-cwt-activator.php          # DB install, default options on activation
│   ├── class-cwt-database.php           # All DB operations and schema migration
│   ├── class-cwt-translator.php         # Language detection, cache, DOM text replacement
│   ├── class-cwt-frontend.php           # Output buffering, admin bar button, editor mode
│   ├── class-cwt-language-switcher.php  # Switcher HTML, shortcode, widget, AJAX
│   └── class-cwt-admin.php              # All admin pages and AJAX handlers
├── public/
│   ├── public.{css,js}                  # Language switcher styles and interaction
│   ├── translate-mode.{css,js}          # Floating quick-translate sidebar
│   └── translation-editor.{css,js}      # Full visual editor (pencil icons, sidebar)
├── admin/
│   └── admin.{css,js}                   # WordPress admin panel UI
└── languages/                           # .po / .mo files for plugin UI strings
```

---

## Security

| Measure | How |
|---|---|
| Nonces | Every AJAX and form request verified with `check_ajax_referer()` or `wp_verify_nonce()` |
| Capability checks | All write operations require `current_user_can('manage_options')` |
| Input sanitization | `sanitize_textarea_field`, `sanitize_key`, `sanitize_hex_color`, `absint` |
| Output escaping | `esc_html`, `esc_attr`, `esc_url`, `esc_textarea` on all output |
| SQL | `$wpdb->prepare()` on every query with user input |
| XSS | `DOMDocument` replaces text nodes only — raw HTML is never injected |
| Cookies | `HttpOnly`, `Secure` on HTTPS, `SameSite=Lax` |

---

## Contributing

Pull requests are welcome. For larger changes, open an issue first so we can discuss the approach before you invest time in it.

1. Fork the repository
2. Create a branch (`git checkout -b feature/my-change`)
3. Commit your changes
4. Open a pull request

Please follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/) for PHP.

---

## License

GNU General Public License v2 or later — see [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html).

---

## Changelog

### 1.2.0
- Visual Translation Editor: "Translate Page" button in admin bar opens a full sidebar editor
- Pencil icons on every translatable text block in the editor
- Save EN + UK translations in a single AJAX call
- `post_id` and `normalized_text` database columns added
- Database schema auto-migrates on version change — no manual deactivate/reactivate needed

### 1.1.0
- Floating quick-translate sidebar mode
- Default language flag in sidebar is now dynamic
- Status filter in the translations table now works correctly

### 1.0.1
- Deleting a translation now removes all language entries, not just the first
- `cwt_clear_cache` and `cwt_reinstall_db` endpoints added
- Translations activate automatically on save
- Fixed-position switcher now shows on all pages regardless of page filter setting

### 1.0.0
- Initial release: output-buffer translation, language switcher, admin panel, JSON import/export
