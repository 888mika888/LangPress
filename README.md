# LangPress

A WordPress plugin for multilingual websites with a visual, manual translation editor — no duplicate pages, no auto-translation APIs, full control over every translated string.

![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue?logo=wordpress)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4?logo=php)
![License](https://img.shields.io/badge/License-GPL%20v2-green)
![Version](https://img.shields.io/badge/Version-1.2.0-orange)

> **Note:** LangPress was originally built for a specific multilingual website using German, English, and Ukrainian. It is shared as-is as an open-source project. It is not actively maintained, but pull requests and improvements are welcome.

> **Important:** For WordPress installation, download the `langpress.zip` file from releases.
> Do **not** use GitHub's automatically generated "Source code" ZIP, because it may not be installable as a WordPress plugin.

---

## What it does

LangPress lets you translate a WordPress site into multiple languages by clicking directly on text in the live frontend. Translations are stored in a custom database table and applied dynamically at request time — no extra pages or posts are ever created.
Default configured languages:
- German
- English
- Ukrainian

Additional selectable languages:
- French
- Spanish
- Italian
- Turkish
- Polish
- Russian
- Arabic

Note: Only the admin UI translation files for English are currently included. Website translations are created manually by the site administrator.
---

## Features

- **Visual editor** — translate text directly on the live page without touching the WordPress editor
- **Two translation modes** — full sidebar editor (all languages at once) or floating quick-translate (one language at a time)
- **Human translations only** — no auto-translation; every string is written by a human, ensuring accuracy and tone
- **No duplicate pages** — translations are swapped in via PHP output buffering at runtime
- **Manual control** — you decide what gets translated; ignored strings are never suggested again
- **Language switcher** — fixed-position dropdown or buttons that appear on every page
- **Auto-activation** — saving a translation makes it live immediately
- **Auto-migration** — database schema updates run automatically on version change
- **English admin UI** — admin panels switch to English when WordPress is set to English; German otherwise
- **Security-conscious** — uses nonces, capability checks, prepared statements, and input/output sanitization.

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

1. Download `langpress.zip` from [Releases](../../releases)
2. Go to **WordPress Admin → Plugins → Add New → Upload Plugin**
3. Select the ZIP → **Install Now** → **Activate**

**Via FTP**

1. Copy the `langpress/` folder to `/wp-content/plugins/`
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

## Translation Modes

There are two ways to translate as an admin. Both are only visible to administrators — regular visitors never see them.

### Translate Page (full editor)

Click **Translate Page** in the WordPress admin bar at the top. This opens a sidebar alongside your live page shown in its original language.

```
┌─────────────────────┬──────────────────────────────────────────┐
│  Translation Editor │                                          │
│  ─────────────────  │        Your live website                 │
│  From German        │                                          │
│  ┌───────────────┐  │   ┌─────────────────────────────────┐    │
│  │ Original text │  │   │ ✎  Heading text                │    │
│  └───────────────┘  │   └─────────────────────────────────┘    │
│  To English         │                                          │
│  ┌───────────────┐  │   Paragraph text here. Click any         │
│  │               │  │   text block to translate it. ✎         │
│  └───────────────┘  │                                          │
│  To Ukrainian       │                                          │
│  ┌───────────────┐  │                                          │
│  │               │  │                                          │
│  └───────────────┘  │                                          │
│  [ Save ]           │                                          │
└─────────────────────┴──────────────────────────────────────────┘
```

- Hover any text block → a pencil icon appears
- Click the pencil → the original text loads in the sidebar; any existing translations are pre-filled
- All target languages are shown at once — translate English and Ukrainian in one step
- Click **Save** — goes live immediately

### Quick Translate (floating button)

A floating **✎ Translate** button appears in the bottom-left corner of every frontend page when you are logged in as an admin. Click it to open a floating sidebar.

- Select the target language from the dropdown
- Click any pencil icon on the page → type the translation → **Save**
- Translates one language at a time
- The mode stays active as you navigate between pages (stored in sessionStorage)
- Click the toggle button or the × to close it

---

## Language Switcher

There are three ways to add the language switcher to your site:

**Fixed position (recommended)** — enable **Fix switcher on screen** in Settings. The switcher appears in your chosen corner on every page automatically.

**Shortcode** — paste this anywhere in your content or templates:
```
[langpress_switcher]
```

**Widget** — add the **Language Switcher** widget to any sidebar or footer area via **Appearance → Widgets**.

When a visitor clicks a language, the page reloads with `?lp_lang=en` (or `uk`) appended, a cookie is set, and the translated version is shown. The cookie keeps the language active as they navigate the site. Any text without a translation falls back to the original silently.

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

The admin UI is in **German by default**. When WordPress is set to English (US or UK), the admin UI switches to English automatically. To add support for another language, create `.po`/`.mo` files in the `languages/` folder using the text domain `langpress`.

---

## How It Works

When a visitor loads a page in a non-default language:

1. `LP_Translator` detects the active language — URL param → cookie → site default
2. `LP_Frontend` starts PHP output buffering
3. WordPress renders the full page HTML
4. The buffer callback passes the HTML to `translate_html()`
5. `DOMDocument` walks all visible text nodes and replaces any that have a translation in the in-memory cache
6. Scripts, styles, HTML attributes, IDs, and classes are never touched

**Cache:** All translations for the active language are loaded in a single query at the start of the request and held in a PHP array keyed by SHA-256 hash. With a persistent cache backend (Redis, Memcached), `wp_cache_set()` stores the map for 5 minutes across requests, so most page loads never hit the database at all.

---

## Known Limitations

- **Page caching plugins** (WP Rocket, W3 Total Cache full-page cache) — cached pages bypass the output buffer, so translations are not applied. You would need to configure your cache plugin to serve separate cached versions per language cookie or disable full-page caching.
- **JavaScript-rendered content** — text injected by JavaScript after page load (React, Vue, Elementor widgets, etc.) is not captured by the PHP output buffer and will not be translated.
- **No hreflang tags** — the plugin does not add `hreflang` meta tags. If SEO for multiple languages matters, add these manually or via a separate SEO plugin.

---

## Developer Reference

### AJAX Endpoints

All endpoints POST to `wp-admin/admin-ajax.php`. Admin endpoints require a valid `lp_admin_nonce` nonce and the `manage_options` capability.

| Action | Auth | Key parameters |
|---|---|---|
| `lp_get_translation` | admin | `original`, `post_id` |
| `lp_save_translation` | admin | `original`, `lang`, `translated`, `status`, `post_id` |
| `lp_get_page_translations` | admin | `language_code`, `post_id` |
| `lp_update_status` | admin | `id`, `status` |
| `lp_delete_translation` | admin | `hash` |
| `lp_clear_cache` | admin | — |
| `lp_reinstall_db` | admin | — |
| `lp_export` | admin | `nonce` (GET) |
| `lp_switch_lang` | public | `lang`, `nonce` |

### Database

**`{prefix}lp_translations`**

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED | Primary key |
| `post_id` | BIGINT UNSIGNED NULL | Optional — links translation to a specific post/page |
| `original_text` | LONGTEXT | The source text as found on the page |
| `normalized_text` | LONGTEXT | Whitespace-collapsed version used for consistent matching |
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
langpress/
├── langpress.php        # Bootstrap, constants, autoloader
├── includes/
│   ├── class-lp-activator.php          # DB install, default options on activation
│   ├── class-lp-database.php           # All DB operations and schema migration
│   ├── class-lp-translator.php         # Language detection, cache, DOM text replacement
│   ├── class-lp-frontend.php           # Output buffering, admin bar button, editor mode
│   ├── class-lp-language-switcher.php  # Switcher HTML, shortcode, widget, AJAX
│   └── class-lp-admin.php              # All admin pages and AJAX handlers
├── public/
│   ├── public.{css,js}                  # Language switcher styles and interaction
│   ├── translate-mode.{css,js}          # Floating quick-translate sidebar
│   └── translation-editor.{css,js}      # Full visual editor (pencil icons, sidebar)
├── admin/
│   └── admin.{css,js}                   # WordPress admin panel UI
└── languages/
    ├── langpress-en_US.{po,mo}          # English (US) admin UI translations
    └── langpress-en_GB.{po,mo}          # English (UK) admin UI translations
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

Pull requests are welcome.

This is a personal project and is not actively maintained, so response times may vary.

Please make sure that PHP code follows the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/).

LangPress was created because I struggled to find a simple translation and language-switching plugin that fit my needs. I hope it can be useful for others as well.

Contributions, improvements, updated versions, or even a fully upgraded plugin based on LangPress are very welcome.

When I have time, I will review reported issues and try to fix them.

I hope this open-source project can grow and evolve with the WordPress community.

---

## License

LangPress is licensed under the GNU General Public License v2.0 or later.

See the [LICENSE](LICENSE) file for details.

---

## Changelog

### 1.2.0
- Visual Translation Editor: "Translate Page" button in admin bar opens a full sidebar editor
- Floating quick-translate sidebar ("Seite übersetzen") for one-language-at-a-time editing
- Pencil icons on every translatable text block in both editor modes
- English admin UI: `en_US` and `en_GB` translation files added
- Fixed: translations being wiped every 24 hours by auto-registration overwriting active rows
- Fixed: hash mismatch between visual editor saves and PHP translation lookup (whitespace normalization)
- Fixed: `post_id` column check missing from UPDATE path, causing saves to fail when `post_id` column is not yet in the schema
- Fixed: pencil icons appearing inside the language switcher in editor mode
- `post_id` and `normalized_text` database columns added
- Database schema auto-migrates on version change — no manual deactivate/reactivate needed

### 1.1.0
- Floating quick-translate sidebar mode
- Default language flag in sidebar is now dynamic
- Status filter in the translations table now works correctly

### 1.0.1
- Deleting a translation now removes all language entries, not just the first
- `lp_clear_cache` and `lp_reinstall_db` endpoints added
- Translations activate automatically on save
- Fixed-position switcher now shows on all pages regardless of page filter setting

### 1.0.0
- Initial release: output-buffer translation, language switcher, admin panel, JSON import/export
