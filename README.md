# Custom Website Translator

A professional WordPress plugin for multilingual websites with manual translation management. Translate your content into English and Ukrainian (and more) without creating duplicate pages — all translations live in a separate database and are applied dynamically.

---

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Plugin Structure](#plugin-structure)
- [How It Works](#how-it-works)
- [Usage Guide](#usage-guide)
  - [1. First Setup](#1-first-setup)
  - [2. Visual Translation Editor](#2-visual-translation-editor)
  - [3. Admin Translations Table](#3-admin-translations-table)
  - [4. Language Switcher](#4-language-switcher)
- [Admin Menu Reference](#admin-menu-reference)
- [Shortcode](#shortcode)
- [AJAX Endpoints](#ajax-endpoints)
- [Database Schema](#database-schema)
- [Security](#security)
- [Performance](#performance)
- [Changelog](#changelog)

---

## Features

- **Visual Translation Editor** — click "Translate Page" in the WordPress admin bar to open a sidebar editor directly on the live page. Click any text to translate it in place.
- **No duplicate pages** — translations are stored in a custom database table and applied dynamically via output buffering. No extra WordPress pages or posts are created.
- **Manual control** — you decide which texts get translated. Ignored texts are never suggested again.
- **Multi-language** — ships with German (default), English, and Ukrainian. Additional languages can be activated in settings.
- **Language Switcher** — fixed dropdown or button switcher that appears on every page. Supports shortcode, widget, and automatic fixed-position rendering.
- **Auto-activation** — saving a translation automatically sets its status to *active*. No manual activation step needed.
- **Auto-migration** — database schema updates run automatically when the plugin version changes. No deactivate/reactivate required.
- **Secure** — nonces, capability checks, prepared statements, sanitized inputs, escaped outputs throughout.
- **Performant** — translations are cached per language per request. Only the active language is loaded. No per-text-node database queries at runtime.

---

## Requirements

| Requirement | Minimum |
|---|---|
| WordPress | 6.0+ |
| PHP | 8.1+ |
| MySQL | 5.7+ / MariaDB 10.3+ |
| Browser | Any modern browser (ES2017+) |

---

## Installation

### Via ZIP Upload (recommended)

1. Download `custom-website-translator.zip`
2. Go to **WordPress Admin → Plugins → Add New → Upload Plugin**
3. Select the ZIP file and click **Install Now**
4. Click **Activate Plugin**

### Via FTP

1. Upload the `custom-website-translator/` folder to `/wp-content/plugins/`
2. Go to **WordPress Admin → Plugins** and activate **Custom Website Translator**

### After Activation

The plugin automatically:
- Creates the database tables (`wp_cwt_translations`, `wp_cwt_settings`)
- Sets default options (German as default language, English + Ukrainian active)
- Runs `dbDelta` on every version change to keep the schema up to date

---

## Plugin Structure

```
custom-website-translator/
│
├── custom-website-translator.php        # Main plugin file, constants, autoloader, bootstrap
│
├── includes/
│   ├── class-cwt-activator.php          # Activation hook: installs DB, sets defaults
│   ├── class-cwt-database.php           # All database operations (CRUD, schema, migration)
│   ├── class-cwt-translator.php         # Language detection, translation cache, DOM walk
│   ├── class-cwt-frontend.php           # Output buffering, admin-bar button, editor mode
│   ├── class-cwt-language-switcher.php  # Switcher HTML, shortcode, widget, AJAX
│   └── class-cwt-admin.php              # All admin pages and AJAX handlers
│
├── public/
│   ├── public.css                       # Frontend language switcher styles
│   ├── public.js                        # Switcher dropdown interaction, cookie handling
│   ├── translate-mode.css               # Floating sidebar styles (quick translate mode)
│   ├── translate-mode.js                # Floating sidebar JS (quick translate mode)
│   ├── translation-editor.css           # Full visual editor sidebar styles
│   └── translation-editor.js            # Full visual editor JS (pencil icons, AJAX save)
│
├── admin/
│   ├── admin.css                        # WordPress admin panel styles
│   └── admin.js                         # Admin translations table interactions
│
├── languages/                           # .po / .mo translation files (WP i18n)
└── custom-website-translator.zip        # Installable ZIP
```

---

## How It Works

### Translation Storage

Every translated text is stored in `wp_cwt_translations` with:
- A SHA-256 hash of the normalized original text as the lookup key
- The original German text
- The translated text per language code
- Status: `active` | `pending` | `ignored`

### Runtime Translation

1. A visitor loads a page
2. `CWT_Translator` detects the active language (URL param → cookie → default)
3. If language ≠ default: `CWT_Frontend` starts PHP output buffering
4. After WordPress renders the full HTML, the buffer callback passes it to `CWT_Translator::translate_html()`
5. `translate_html()` parses the HTML with `DOMDocument`, walks all text nodes, and replaces matches found in the in-memory cache
6. Scripts, styles, attributes, IDs, classes, and hidden elements are never touched

### Cache

Translations are loaded **once per request** from the database into a PHP array keyed by SHA-256 hash. WordPress object cache (`wp_cache_set`) stores the map for 5 minutes across requests when a persistent cache like Redis or Memcached is active.

---

## Usage Guide

### 1. First Setup

1. Go to **Translator → Sprachen**
2. Confirm **German** as the default language
3. Check **English** and **Ukrainian** as active translation targets → **Save**
4. Go to **Translator → Einstellungen**
5. Set the switcher position (e.g. *Bottom Right*) and enable **Fix switcher on screen** → **Save**

### 2. Visual Translation Editor

The visual editor is the primary way to add translations.

**Opening the editor:**

1. As an administrator, navigate to any page on your website
2. Click **Translate Page** in the WordPress admin bar at the top

The page reloads with:
- A **left sidebar** (340 px) showing the Translation Editor
- The **full live page** shifted to the right
- **Pencil icons** appearing when you hover over any translatable text block

**Translating a text:**

1. Hover over any heading, paragraph, button, link, or list item
2. Click the **✎ pencil icon** (or click directly on the text)
3. The sidebar shows:
   - **From German** — the original text (read-only)
   - **To English** — textarea for the English translation
   - **To Ukrainian** — textarea for the Ukrainian translation
4. Type your translations
5. Click **Save** — the translation is immediately active

**Editing an existing translation:**

1. Click the same text again in the editor
2. The existing translations load automatically into the fields
3. Edit the text and click **Save** — it stays active

**Closing the editor:**

Click the **×** button in the sidebar or navigate away normally.

> **Note:** The editor only shows for logged-in administrators. Regular visitors never see the pencil icons or the sidebar.

### 3. Admin Translations Table

Go to **Translator → Übersetzungen** for a table overview of all detected texts.

- **Search** by original text
- **Filter** by status (Pending / Active / Ignored)
- **Edit** English and Ukrainian translations inline
- **Save** individual entries — saving automatically sets status to *Active*
- **Delete** all translations for an original text (removes all language entries)
- **Status** options:
  - *Active* — translation is applied in the frontend
  - *Pending* — text detected but not yet translated
  - *Ignored* — text will not be suggested for translation again

### 4. Language Switcher

The language switcher lets visitors choose their preferred language.

**Automatic fixed rendering (recommended):**

Enable **Fix switcher on screen** in **Translator → Einstellungen**. The switcher appears as a fixed widget on every page at the configured position (top/bottom, left/right).

**Via Shortcode:**

```
[custom_language_switcher]
```

Place this shortcode anywhere in your content, a widget area, or a page builder block.

**Via Widget:**

Go to **Appearance → Widgets** and add the **Sprachumschalter** widget to any sidebar or footer area.

**Language switching behavior:**

| Action | Result |
|---|---|
| Click **English** | Page reloads with `?cwt_lang=en`, cookie is set, English translations applied |
| Navigate to another page | Cookie keeps the language active across all pages |
| Click **Deutsch** | Returns to the original German content |
| Text without translation | Falls back silently to the original German text |

---

## Admin Menu Reference

| Menu Item | Description |
|---|---|
| **Translator → Einstellungen** | Switcher visibility (all pages / specific / exclude), position, fixed mode |
| **Translator → Sprachen** | Default language, active translation languages |
| **Translator → Übersetzungen** | Full translation table with inline editing, search and status filter |
| **Translator → Design** | Switcher style (dropdown/buttons), display mode (text/flag/both), colors, border radius, font size, padding |
| **Translator → Import / Export** | Export all translations as JSON, import from a previously exported JSON file |
| **Translator → Debug / Status** | PHP/WP version info, table status, cache clear button, DB reinstall button, translation statistics |

---

## Shortcode

```
[custom_language_switcher]
```

No attributes required. The switcher renders using the styles configured in **Translator → Design**.

---

## AJAX Endpoints

All endpoints require a valid nonce (`cwt_admin_nonce`) and `manage_options` capability unless noted.

| Action | Auth | Parameters | Response |
|---|---|---|---|
| `cwt_get_translation` | admin | `original`, `post_id?` | `{ hash, original, translations: { en, uk } }` |
| `cwt_save_translation` | admin | `original`, `en?`, `uk?`, `post_id?` | `{ message, langs[] }` |
| `cwt_save_translation` (legacy) | admin | `original`, `lang`, `translated`, `status` | `{ message }` |
| `cwt_get_page_translations` | admin | `language_code`, `post_id?` | `{ lang, translations }` |
| `cwt_update_status` | admin | `id`, `status` | success/error |
| `cwt_delete_translation` | admin | `hash` | success/error |
| `cwt_clear_cache` | admin | — | `{ message }` |
| `cwt_reinstall_db` | admin | — | `{ message }` |
| `cwt_export` | admin | `nonce` (GET) | JSON file download |
| `cwt_switch_lang` | public | `lang`, `nonce` | `{ lang }` (sets cookie) |

---

## Database Schema

### `wp_cwt_translations`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED | Primary key, auto-increment |
| `post_id` | BIGINT UNSIGNED NULL | Optional WordPress post/page ID |
| `original_text` | LONGTEXT | The original source text |
| `normalized_text` | LONGTEXT | Trimmed, whitespace-normalized version |
| `text_hash` | VARCHAR(64) | SHA-256 of normalized text — the lookup key |
| `language_code` | VARCHAR(10) | `en`, `uk`, `fr`, etc. |
| `translated_text` | LONGTEXT | The translated content |
| `status` | ENUM | `active` \| `pending` \| `ignored` |
| `page_url` | VARCHAR(2083) | URL where the text was first detected |
| `created_at` | DATETIME | Creation timestamp |
| `updated_at` | DATETIME | Last modification timestamp |

**Indexes:** `UNIQUE(text_hash, language_code)`, `status`, `language_code`, `post_id`

### `wp_cwt_settings`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED | Primary key |
| `setting_key` | VARCHAR(191) UNIQUE | Setting name |
| `setting_value` | LONGTEXT | Setting value |

> Most settings use standard WordPress `wp_options` via `get_option()` / `update_option()` for full compatibility with caching plugins and the WordPress Settings API.

---

## Security

| Measure | Implementation |
|---|---|
| **Nonces** | Every AJAX and form request verified with `check_ajax_referer()` / `wp_verify_nonce()` |
| **Capability checks** | All write operations require `manage_options` (`current_user_can()`) |
| **Input sanitization** | `sanitize_textarea_field()`, `sanitize_key()`, `sanitize_hex_color()`, `absint()` on all inputs |
| **Output escaping** | `esc_html()`, `esc_attr()`, `esc_url()`, `esc_textarea()` on all outputs |
| **Prepared statements** | `$wpdb->prepare()` for every database query with user input |
| **No XSS** | `DOMDocument` replaces only text nodes — raw HTML is never injected |
| **No SQL injection** | All user values go through `$wpdb->prepare()` or WP CRUD methods (`$wpdb->insert`, `$wpdb->update`) |
| **Cookie security** | `HttpOnly`, `Secure` (HTTPS only), `SameSite=Lax` |
| **Editor visibility** | Admin bar button, pencil icons, and sidebar only rendered for `manage_options` users |

---

## Performance

- **Single cache load per request** — translations for the active language are fetched once and held in a PHP array for the entire request lifecycle
- **WordPress object cache** — `wp_cache_set()` with 5-minute TTL stores the translation map across requests when a persistent backend (Redis, Memcached) is active
- **Conditional asset loading** — editor CSS/JS (`translation-editor.*`) only load when `?cwt_translation_editor=1` is present in the URL
- **Text scan throttled** — each page URL is scanned for new texts at most once every 24 hours (controlled via WordPress transients)
- **O(1) lookups** — all runtime translation lookups are hash-table lookups against the in-memory array, not database queries
- **Column existence caching** — `column_exists()` uses `wp_cache` to avoid repeated `information_schema` queries

---

## Changelog

### v1.2.0
- **New:** "Translate Page" button in WordPress admin bar opens a full visual editor
- **New:** Left sidebar with pencil icons on every translatable text block
- **New:** Multi-language save (EN + UK) in a single AJAX call
- **New:** `cwt_get_page_translations` AJAX endpoint
- **New:** `post_id` and `normalized_text` columns in the database
- **New:** Auto-migration — `dbDelta` runs automatically on version change
- **Fix:** Safe column existence check prevents INSERT failures on un-migrated schemas

### v1.1.0
- **New:** Floating sidebar translate mode with "✎ Seite übersetzen" toggle button
- **New:** Dynamic default-language flag in sidebar (not hardcoded German)
- **Fix:** `status_filter` now correctly applied in the admin translations query
- **Fix:** Removed empty `ajax_import()` dead code registration

### v1.0.1
- **Fix:** `delete_by_hash()` removes all language entries for an original (previously only deleted the first)
- **Fix:** `cwt_clear_cache` and `cwt_reinstall_db` AJAX actions implemented and registered
- **Fix:** Auto-activate translation on save — no separate "Activate" button needed
- **Fix:** Language switcher in fixed mode now renders on all pages regardless of page filter

### v1.0.0
- Initial release
- Output-buffer translation engine, language detection via URL param → cookie → default
- Admin panel: Settings, Languages, Translations, Design, Import/Export, Debug
- Language switcher: fixed position, shortcode `[custom_language_switcher]`, widget
- Supported languages: DE, EN, UK (+ FR, ES, IT, TR, PL, RU, AR selectable in settings)
