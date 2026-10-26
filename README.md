# LangPress

A WordPress plugin for translating a site by clicking on text in the live page. Translations are stored in one database table and swapped in when the page is served, so no duplicate pages or posts are created. There is no machine translation; every string is written by you.

![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue?logo=wordpress)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4?logo=php)
![License](https://img.shields.io/badge/License-GPL%20v2-green)
![Version](https://img.shields.io/badge/Version-1.5.0-orange)

Built for a German, English and Ukrainian site and shared as-is. It is not actively maintained, but pull requests are welcome.

## Installation

1. Download `langpress.zip` from [Releases](../../releases). Do not use GitHub's "Source code" ZIP; WordPress can't install it.
2. In WordPress go to **Plugins → Add New → Upload Plugin**, choose the ZIP, then **Install Now** and **Activate**.

Requirements: WordPress 6.0+, PHP 8.1+, MySQL 5.7+ or MariaDB 10.3+. Tested with WordPress 7.1.3 and PHP 8.5.

## Getting started

1. **Translator → Languages**: pick the default language (German) and the languages to translate into.
2. **Translator → Settings**: choose where the language switcher appears.
3. Open any page while logged in as an administrator and click **✎ Translate Page** in the bottom-left corner.
4. Click a text, type the translations in the sidebar, and press **Save**. The translation is live immediately.

Available languages: German, English, Ukrainian, French, Spanish, Italian, Turkish, Polish, Russian and Arabic (right-to-left).

## Language switcher

Add it in one of three ways:

- **Fixed position:** enable it in **Settings**; it then appears in a corner of every page.
- **Shortcode:** `[langpress_switcher]`
- **Widget:** **Appearance → Widgets → Language Switcher**

Choosing a language loads the page with `?lp_lang=en` and stores the choice in a cookie. Text without a translation is shown in the original language.

## Automatic text detection

Pages viewed in the default language are scanned, and new texts appear under **Translator → Translations** as pending. Each page is scanned at most once a day and again after it is edited. Search results, 404 pages and URLs with unknown query parameters are skipped. You can turn this off in **Settings**.

## Admin pages

- **Settings:** switcher visibility and position, automatic detection, uninstall behaviour
- **Languages:** default and active languages
- **Translations:** search and edit all strings
- **Design:** switcher style, colours and sizes
- **Import / Export:** back up and restore translations as JSON
- **Debug / Status:** system info, clear cache, repair database tables

The admin interface is in German, or in English when WordPress is set to English.

## Uninstalling

Deleting the plugin keeps your translations and settings, so a reinstall restores them. To remove everything, tick **Settings → Uninstall** before deleting. Export your translations first; this cannot be undone.

## Limitations

- **Page caching** (WP Rocket, W3 Total Cache, LiteSpeed Cache, WP Super Cache and similar) serves cached pages without translations unless the cache varies by the `lp_language` cookie. LangPress shows a warning when one of these plugins is active.
- **Text added by JavaScript** after the page loads is not translated.

## Development

```bash
bash bin/check-source.sh                      # PHP syntax, no BOM before <?php
WP_DIR=/tmp/lp-wp bash bin/setup-test-wp.sh   # disposable WordPress (SQLite; DB=mysql for MySQL)
LP_WP_DIR=/tmp/lp-wp php tests/run.php        # integration and security tests
bash bin/build-zip.sh                         # build and verify langpress.zip from HEAD
```

The tests create and delete data, so only run them against a throwaway install. CI runs all of the above on every push.

Please follow the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/).

## License

GPL v2 or later. See [LICENSE](LICENSE).

## Changelog

### 1.5.0
Security:
- Anonymous visitors could trigger unlimited page scans and database writes by adding random query parameters; search pages stored visitor-typed text. Scanning is now limited per page and can be switched off.
- Links (language switcher, hreflang) were built from the request's `Host` header, which allowed cache poisoning. They now use the site URL.
- Admin notifications insert messages as text, and design settings are validated before output.

Fixes:
- Translated pages lost their `<!DOCTYPE>` and rendered in quirks mode (since 1.0).
- A byte-order mark in one PHP file broke the language cookie, redirects and AJAX responses on some hosts.
- Database upgrades could silently skip new columns, and a failed upgrade was never retried.
- Upgrading from the original Custom Website Translator tables failed on SQLite.
- Saves could be lost when two requests wrote the same string at once.
- Translating a paragraph could delete embedded videos, iframes, icons or images inside it.
- Text containing `<` or `%` never matched its translation.
- Links on pages with Cyrillic or other encoded URLs lost characters.
- Import stopped with an error on entries without a `status`, and did not restore the page URL.
- Switching back to the default language sometimes showed the previous language.
- The release ZIP used Windows path separators and could not be installed on Linux servers.

New:
- Option to delete all data on uninstall (off by default).
- Much faster text detection on large pages.
- Automated tests and CI.

### 1.3.0
- hreflang tags for every active language
- Right-to-left support for Arabic
- Warning when a page caching plugin is active
- 10 MB limit for JSON imports
- Floating "Translate Page" button that opens the editor
- Paragraphs with bold, italic or other inline formatting are translated and keep their formatting
- Dark mode for the admin pages
- Language switcher fixes in editor mode and for focus outlines

### 1.2.0
- Visual translation editor with a sidebar
- English admin interface (`en_US`, `en_GB`)
- Fixed translations being wiped every 24 hours by automatic detection
- Fixed editor saves not matching page text because of whitespace differences
- New `post_id` and `normalized_text` columns; database updates run automatically

### 1.1.0
- Quick-translate sidebar
- Status filter in the translations table fixed

### 1.0.1
- Deleting a translation removes it for all languages
- Translations are active as soon as they are saved
- Fixed switcher shows on every page

### 1.0.0
- First release