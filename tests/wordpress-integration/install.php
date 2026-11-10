<?php
// Only the shell runner creates this temporary installation.
$root = getenv( 'CLFA_TEST_ROOT' );
if ( ! $root || ! is_file( "$root/wordpress/wp-load.php" ) || ! is_dir( "$root/db" ) ) {
	throw new RuntimeException( 'Run tests/run-wordpress-integration.sh instead.' );
}
$config = '<?php
define("DB_NAME", "clfa_test");
define("DB_USER", "root");
define("DB_PASSWORD", "");
define("DB_HOST", "localhost:" . dirname(__DIR__) . "/mysql.sock");
define("DB_CHARSET", "utf8mb4");
define("DB_COLLATE", "");
define("WP_HOME", "http://wordpress.example.test");
define("WP_SITEURL", WP_HOME);
define("DISABLE_WP_CRON", true);
define("WP_HTTP_BLOCK_EXTERNAL", true);
define("WP_DEBUG", true);
define("WP_DEBUG_DISPLAY", true);
define("AUTH_KEY", "synthetic-integration-test-only");
define("AUTH_SALT", "synthetic-integration-test-only-salt");
$table_prefix = "wptest_";
if (!defined("ABSPATH")) { define("ABSPATH", __DIR__ . "/"); }
require_once ABSPATH . "wp-settings.php";
';
file_put_contents( "$root/wordpress/wp-config.php", $config );
define( 'WP_INSTALLING', true );
require "$root/wordpress/wp-load.php";
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
add_filter( 'pre_wp_mail', '__return_false' );
wp_install( 'Disposable integration test', 'synthetic-admin', 'admin@example.test', false, '', 'synthetic-test-only-password' );
echo "Installed WordPress " . get_bloginfo( 'version' ) . "\n";
