# LangPress

A WordPress plugin for multilingual websites with a visual, manual translation editor: no duplicate pages, no auto-translation APIs, full control over every translated string.

![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue?logo=wordpress)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4?logo=php)
![License](https://img.shields.io/badge/License-GPL%20v2-green)
![Version](https://img.shields.io/badge/Version-1.4.0-orange)

> **Note:** LangPress was originally built for a specific multilingual website using German, English, and Ukrainian. It is shared as-is as an open-source project. It is not actively maintained, but pull requests and improvements are welcome.

> **Important:** For WordPress installation, download the `langpress.zip` file from releases.
> Do **not** use GitHub's automatically generated "Source code" ZIP, because it may not be installable as a WordPress plugin.

---

## What it does

LangPress lets you translate a WordPress site into multiple languages by clicking directly on text in the live frontend. Translations are stored in a custom database table and applied dynamically at request time, so no extra pages or posts are ever created.
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

- **Visual editor:** Translate text directly on the live page without touching the WordPress editor
- **Floating action button:** A polished FAB in the bottom-left corner of every frontend page opens the full multi-language Translation Editor with one click
- **Human translations only:** No auto-translation; every string is written by a human, ensuring accuracy and tone
- **No duplicate pages:** Translations are swapped in via PHP output buffering at runtime
- **Manual control:** You decide what gets translated; ignored strings are never suggested again
- **Language switcher:** Fixed-position dropdown or buttons that appear on every page
- **Auto-activation:** Saving a translation makes it live immediately
- **Auto-migration:** Database schema updates run automatically on version change
- **English admin UI:** Admin panels switch to English when WordPress is set to English; German otherwise
- **Security-conscious:** Uses nonces, capability checks, prepared statements, and input/output sanitization.

---

## Requirements

| | Minimum |
|---|---|
| WordPress | 6.0 |
| PHP | 8.1 |
| MySQL | 5.7 / MariaDB 10.3 |

The 1.4.0 test suite was run against WordPress 7.1.3 on PHP 8.5, using the SQLite Database Integration plugin as the database layer. The minimum versions above are declared, not re-verified, and MySQL/MariaDB are exercised by the CI workflow rather than the local runs.

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

1. Go to **Translator → Languages** and confirm German as default, enable English and Ukrainian
2. Go to **Translator → Settings**, pick a position (e.g. *Bottom Right*) and enable **Fix switcher on screen**
3. Visit any page on your site as an admin → click the **✎ Translate Page** button at the bottom-left of the page
4. Hover over any text → click the **✎** icon → type your translation → **Save**

The language switcher will now appear on every page for all visitors.

---

## Translation Mode

The editor is only visible to administrators; regular visitors never see it.

### Translate Page (full editor)

A floating **✎ Translate Page** button appears in the bottom-left corner of every frontend page when you are logged in as an admin. Click it to open the full Translation Editor alongside your live page in its original language.

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
- All target languages are shown at once, so you can translate English and Ukrainian in one step
- Click **Save**; it goes live immediately
- Click **×** to close the editor and return to the normal page

---

## Language Switcher

There are three ways to add the language switcher to your site:

**Fixed position (recommended):** Enable **Fix switcher on screen** in Settings. The switcher appears in your chosen corner on every page automatically.

**Shortcode:** Paste this anywhere in your content or templates:
```
[langpress_switcher]
```

**Widget:** Add the **Language Switcher** widget to any sidebar or footer area via **Appearance → Widgets**.

When a visitor clicks a language, the page reloads with `?lp_lang=en` (or `uk`) appended, the server sets an `lp_language` cookie (`HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS), and the translated version is shown. The cookie keeps the language active as they navigate the site. Switching back to the default language resets the cookie before navigating to the clean URL. Any text without a translation falls back to the original silently.

---

## Automatic Text Detection

When a page is viewed in the default language, LangPress registers its texts as *pending* so they show up under **Translations**. To keep anonymous traffic from driving database writes:

- Each page (post, term archive, author archive, home page) is scanned at most once a day, and again as soon as it is edited.
- Search results, 404 pages, previews, feeds, POST requests and URLs with query parameters WordPress does not route on (e.g. `?utm_source=`) are never scanned.
- A single scan registers at most 1,000 strings of up to 5,000 characters, using a handful of batched queries.
- The admin bar and the language switcher are ignored.

You can turn detection off under **Translator → Settings → Automatic text detection**. Existing translations keep working either way.

---

## Uninstalling

Deactivating LangPress removes nothing. Deleting it from **Plugins** always clears its page-scan cache (`lp_scan_*`/`lp_scanned_*` transients). Translations and settings are **kept** unless **Translator → Settings → Uninstall** is ticked. With that box ticked, deleting the plugin drops `{prefix}lp_translations` and `{prefix}lp_settings` and deletes the LangPress options by exact name; other `lp_`-prefixed data is not touched. On multisite each site follows its own setting. Export your translations first, because this cannot be undone.

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

1. `LP_Translator` detects the active language: URL param → cookie → site default
2. `LP_Frontend` starts PHP output buffering
3. WordPress renders the full page HTML
4. The buffer callback passes the HTML to `translate_html()`
5. `DOMDocument` walks the page: for block-level elements (`p`, `h1`–`h6`, `li`, etc.) it first tries a combined lookup of the element's full visible text, then falls back to per-text-node replacement. This ensures paragraphs that contain inline tags like `<strong>` or `<em>` are translated correctly and their formatting is preserved.
6. Scripts, styles, HTML attributes, IDs, and classes are never touched

**Cache:** All translations for the active language are loaded in a single query at the start of the request and held in a PHP array keyed by SHA-256 hash. With a persistent cache backend (Redis, Memcached), `wp_cache_set()` stores the map for 5 minutes across requests, so most page loads never hit the database at all.

---

## Known Limitations

- **Page caching plugins** (WP Rocket, W3 Total Cache full-page cache, LiteSpeed Cache, WP Super Cache): cached pages bypass the output buffer, so translations are not applied. LangPress shows a warning in its admin panels when one of these plugins is detected. You would need to configure your cache plugin to serve separate cached versions per language cookie or disable full-page caching.
- **JavaScript-rendered content:** Text injected by JavaScript after page load (React, Vue, Elementor widgets, etc.) is not captured by the PHP output buffer and will not be translated.

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
| `lp_clear_cache` | admin | (none) |
| `lp_reinstall_db` | admin | (none) |
| `lp_export` | admin | `nonce` (GET) |
| `lp_switch_lang` | public | `lang` |

`lp_switch_lang` deliberately has no nonce. It only sets the visitor's own language cookie to an allow-listed code, which any `?lp_lang=` link does as well. Nonces for logged-out visitors are shared by everyone, and page caches serve them after they expire, which used to break switching.

### Database

**`{prefix}lp_translations`**

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED | Primary key |
| `post_id` | BIGINT UNSIGNED NULL | Optional; links translation to a specific post/page |
| `original_text` | LONGTEXT | The source text as found on the page |
| `normalized_text` | LONGTEXT NULL | Whitespace-collapsed version used for consistent matching (NULL on rows from before 1.2) |
| `text_hash` | VARCHAR(64) | SHA-256 of the normalized text, used as the lookup key |
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
├── uninstall.php        # Cleanup when the plugin is deleted (see "Uninstalling")
├── includes/
│   ├── class-lp-activator.php          # DB install, default options on activation
│   ├── class-lp-database.php           # All DB operations and schema migration
│   ├── class-lp-translator.php         # Language detection, cache, DOM text replacement
│   ├── class-lp-frontend.php           # Output buffering, editor mode, text registration
│   ├── class-lp-language-switcher.php  # Switcher HTML, shortcode, widget, AJAX
│   └── class-lp-admin.php              # All admin pages and AJAX handlers
├── public/
│   ├── public.{css,js}                  # Language switcher styles and interaction
│   ├── translate-mode.{css,js}          # Floating action button (opens the full editor)
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
| Nonces | Every admin AJAX and form request verified with `check_ajax_referer()` or `wp_verify_nonce()` (the public language switch is the documented exception) |
| Capability checks | All admin actions require `current_user_can('manage_options')`; covered by role tests for subscriber, contributor, author and editor |
| Input validation | Language codes, statuses, hashes and design options are checked against allow-lists or strict formats; design options are re-validated when rendered |
| Plain-text storage | Source texts and translations are stored verbatim as plain text (invalid UTF-8 and control characters removed) and only ever output as DOM text nodes or through `esc_html`/`esc_attr`/`esc_textarea` |
| URLs | Generated links use the scheme and host from `home_url()`, never the request's `Host` header |
| SQL | `$wpdb->prepare()` on every query with user input; writes are atomic `INSERT … ON DUPLICATE KEY UPDATE` |
| XSS | `DOMDocument` replaces text nodes only; raw HTML is never injected; admin JS inserts messages with `.text()` |
| Resource limits | Bounded, throttled page scanning (see "Automatic Text Detection") |
| Cookies | Set server-side only: `HttpOnly`, `Secure` on HTTPS, `SameSite=Lax` |

---

## Contributing

Pull requests are welcome.

This is a personal project and is not actively maintained, so response times may vary.

Please make sure that PHP code follows the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/).

### Tests and packaging

```bash
# Source checks: php -l on every file, no BOM / output before <?php
bash bin/check-source.sh

# Integration + security regression tests in a throwaway WordPress
# (SQLite by default; set DB=mysql DB_HOST=… DB_NAME=… DB_USER=… DB_PASSWORD=… for MySQL)
WP_DIR=/tmp/lp-wp bash bin/setup-test-wp.sh
LP_WP_DIR=/tmp/lp-wp php tests/run.php

# Build the installable ZIP (langpress/ root, forward slashes, LF) and verify it
bash bin/build-zip.sh              # from HEAD, for releases
bash bin/build-zip.sh --worktree   # from uncommitted changes
bash bin/check-package.sh langpress.zip HEAD
```

The tests create and delete data, so never point them at a real site. CI (`.github/workflows/ci.yml`) runs the source and package checks, then the suite on PHP 8.5 with both MySQL 8.0 and SQLite. It fails if the committed `langpress.zip` does not match the committed source.

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

### 1.4.0: security and reliability release
- **Security: unauthenticated scan abuse.** The page-scan throttle was keyed on the full request URL, so every unique query string triggered a full scan and left a transient behind. Search pages also registered visitor-supplied text. Scans are now keyed on the resolved page, limited to clean GET requests, bounded per request, and can be disabled. Pre-1.4 throttle transients are removed on upgrade.
- **Security: Host header injection.** The hreflang and switcher links, the editor's close link and the stored `page_url` were built from the client's `Host` header. They now use the configured site URL. Output was already escaped with `esc_url()`, so this was not an XSS, but cached pages could carry links to a foreign domain.
- **Security: admin toast messages** are inserted as text instead of HTML.
- **Security: design options** (colors, sizes, style, position) are validated when rendered, not only when saved.
- **Fixed: UTF-8 BOM** in `class-lp-translator.php` was sent before headers. It broke the server-side language cookie, redirects (including WordPress's canonical redirect) and the first bytes of AJAX/REST JSON on hosts without output buffering. The client-side cookie workaround in `public.js` is removed.
- **Fixed: lost writes under concurrency.** Saving or registering the same string from two requests could fail on the unique key. Upserts are now a single atomic statement. Saving a translation no longer clears the page URL recorded at registration.
- **Fixed: schema upgrades never ran.** `CREATE TABLE IF NOT EXISTS` stopped `dbDelta()` from adding new columns. Sites upgraded from before 1.2 now get `normalized_text`/`post_id`.
- **Fixed: `column_exists()` cache** treated a cached "missing" result as a cache miss.
- **Fixed: embedded media deleted.** A combined block translation could remove `iframe`, `video`, `audio`, `svg`, `object`, `embed`, `canvas` and similar children from a paragraph.
- **Fixed: texts containing `<` or `%XX`** were altered on save (`sanitize_textarea_field`), so their translations never matched the page.
- **Fixed: percent-encoded URLs** (e.g. Cyrillic slugs, encoded query values) lost characters in switcher and hreflang links.
- **Fixed: JSON import** aborted with a fatal error when an entry had no `status`. Entries are now validated individually.
- **Fixed: switching back to the default language** could render the previous language because the cookie reset raced the navigation. The public endpoint no longer requires a nonce that page caches serve stale.
- **Performance:** Registering a 250-paragraph page (500 strings × 2 languages) went from 2,002 queries to 6 on first scan and from 1,000 to 3 on a rescan (local benchmark, see `tests/bench-register.php`).
- **New:** `uninstall.php` with an opt-in "delete all data" setting, an "Automatic text detection" switch, correct Plugin URI, reproducible ZIP build (`bin/build-zip.sh`), integration test suite and CI.

### 1.3.0
- **hreflang tags:** `<link rel="alternate" hreflang="...">` tags are now injected into `<head>` for every active language, including `x-default`. This tells search engines about language alternatives and prevents duplicate-content penalties.
- **RTL support:** Arabic now renders correctly. The `<html>` tag gets `dir="rtl"` automatically when Arabic is the active language, and the language switcher adapts its layout for right-to-left text.
- **Caching plugin warning:** A notice appears on all LangPress admin pages when WP Rocket, W3 Total Cache, LiteSpeed Cache, WP Super Cache, Cache Enabler, or Comet Cache is active, explaining the output-buffer incompatibility.
- **Import file size limit:** JSON imports are now rejected if the file exceeds 10 MB, preventing memory exhaustion on large uploads.
- **Floating action button:** A polished pill-shaped FAB with entry animation, hover lift, and pencil icon rotation now sits at the bottom-left of every frontend page. Clicking it opens the full multi-language Translation Editor directly.
- **Language switcher animation:** The dropdown now opens and closes with smooth `opacity`/`transform` transitions; the previous abrupt show/hide is gone.
- **Inline formatting fix:** Paragraphs containing `<strong>`, `<em>`, `<span>`, or other inline tags are now translated correctly. Previously the text was split across multiple DOM nodes and no translation was applied.
- **Formatting preserved after translation:** When a block-level translation is applied, inline elements (`<strong>`, `<em>`, etc.) are reconstructed in the translated output by locating the original inline content inside the translated string.
- **Fixed: error message when loading existing translations fails.** The Translation Editor sidebar now shows an error instead of silently clearing the loading indicator.
- **Dark mode:** A "Dark Mode" checkbox in Settings applies a full dark theme to all LangPress admin pages.
- **Translation Editor UI:** Tab bar and duplicate top Save button removed; a "Manage strings ↗" link replaces them inline next to the language pill.
- **Fixed: language switcher in editor mode.** Switching language while the Translation Editor is open now exits editor mode and shows the translated page, so saved translations are immediately visible.
- **Fixed: language switcher blue outline.** Browser default blue focus ring no longer appears on click; keyboard focus outline is preserved via `:focus-visible`.

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
- Database schema auto-migrates on version change, with no manual deactivate/reactivate needed

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
