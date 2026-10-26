#!/usr/bin/env bash
# Create a DISPOSABLE WordPress install for the integration tests.
#
#   WP_DIR=/tmp/lp-wp bash bin/setup-test-wp.sh            # SQLite (no server needed)
#   WP_DIR=/tmp/lp-wp DB=mysql DB_HOST=127.0.0.1 DB_USER=root DB_PASSWORD=root \
#       DB_NAME=lp_test bash bin/setup-test-wp.sh            # MySQL / MariaDB
#
# Optional: WP_VERSION (default: latest stable from api.wordpress.org).
# Then run:  LP_WP_DIR="$WP_DIR" php tests/run.php
#
# The tests create, modify and delete data. Never point WP_DIR or the DB_*
# settings at a real site or database.
set -euo pipefail

repo="$(cd "$(dirname "$0")/.." && pwd)"
wp_dir="${WP_DIR:?set WP_DIR to an empty scratch directory}"
db="${DB:-sqlite}"
php="${PHP:-php}"
port="${LP_HTTP_PORT:-8899}"

if [ -z "${WP_VERSION:-}" ]; then
	WP_VERSION="$(curl -fsS https://api.wordpress.org/core/version-check/1.7/ | "$php" -r '$d = json_decode(stream_get_contents(STDIN), true); echo $d["offers"][0]["current"];')"
fi
echo "WordPress $WP_VERSION, database: $db"

mkdir -p "$wp_dir"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

curl -fsSL -o "$tmp/wp.zip" "https://wordpress.org/wordpress-$WP_VERSION.zip"
echo "$(curl -fsSL "https://wordpress.org/wordpress-$WP_VERSION.zip.sha1")  $tmp/wp.zip" | sha1sum -c -
unzip -q "$tmp/wp.zip" -d "$tmp"
cp -R "$tmp/wordpress/." "$wp_dir/"

if [ "$db" = "sqlite" ]; then
	curl -fsSL -o "$tmp/sqlite.zip" https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip
	unzip -q "$tmp/sqlite.zip" -d "$wp_dir/wp-content/plugins/"
	sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$wp_dir/wp-content/plugins/sqlite-database-integration#" \
	    -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
	    "$wp_dir/wp-content/plugins/sqlite-database-integration/db.copy" > "$wp_dir/wp-content/db.php"
	db_config="define( 'DB_DIR', __DIR__ . '/wp-content/database/' ); define( 'DB_FILE', 'test.sqlite' );
define( 'DB_NAME', 'wp' ); define( 'DB_USER', '' ); define( 'DB_PASSWORD', '' ); define( 'DB_HOST', '' );"
else
	db_config="define( 'DB_NAME', '${DB_NAME:?}' ); define( 'DB_USER', '${DB_USER:?}' ); define( 'DB_PASSWORD', '${DB_PASSWORD:-}' ); define( 'DB_HOST', '${DB_HOST:?}' );"
fi

cat > "$wp_dir/wp-config.php" <<PHP
<?php
// Disposable test install created by bin/setup-test-wp.sh.
$db_config
define( 'DB_CHARSET', 'utf8mb4' ); define( 'DB_COLLATE', '' );
\$table_prefix = 'wp_';
foreach ( [ 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ] as \$k ) {
	define( \$k, 'test-only-' . \$k );
}
define( 'WP_DEBUG', true ); define( 'WP_DEBUG_LOG', __DIR__ . '/debug.log' ); define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_HOME', 'http://127.0.0.1:$port' ); define( 'WP_SITEURL', 'http://127.0.0.1:$port' );
define( 'DISABLE_WP_CRON', true ); define( 'AUTOMATIC_UPDATER_DISABLED', true ); define( 'WP_HTTP_BLOCK_EXTERNAL', true );
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
PHP

mkdir -p "$wp_dir/wp-content/mu-plugins"
cp "$repo/tests/mu-plugin-lp-test.php" "$wp_dir/wp-content/mu-plugins/lp-test.php"
ln -sfn "$repo/langpress" "$wp_dir/wp-content/plugins/langpress"

cd "$wp_dir"
"$php" -r '
define( "WP_INSTALLING", true );
$_SERVER["HTTP_HOST"] = "127.0.0.1"; $_SERVER["REQUEST_URI"] = "/";
require "wp-load.php";
require_once ABSPATH . "wp-admin/includes/upgrade.php";
if ( ! is_blog_installed() ) {
	wp_install( "LP Test", "admin", "admin@example.test", true, "", wp_generate_password( 24 ) );
}'
"$php" -r '
$_SERVER["HTTP_HOST"] = "127.0.0.1"; $_SERVER["REQUEST_URI"] = "/";
require "wp-load.php";
require_once ABSPATH . "wp-admin/includes/plugin.php";
$r = activate_plugin( "langpress/langpress.php" );
if ( is_wp_error( $r ) ) { fwrite( STDERR, $r->get_error_message() . "\n" ); exit( 1 ); }
echo "LangPress activated on WordPress ", get_bloginfo( "version" ), "\n";'
