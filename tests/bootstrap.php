<?php
/**
 * PHPUnit bootstrap for the WordPress integration test suite (wp-env "tests" environment).
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

$member_docs_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( false === $member_docs_tests_dir || '' === $member_docs_tests_dir ) {
	$member_docs_tests_dir = '/wordpress-phpunit';
}

if ( ! file_exists( $member_docs_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test library not found in {$member_docs_tests_dir}. Run the tests with `composer test` (wp-env).\n" );
	exit( 1 );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once $member_docs_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		$woocommerce = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		if ( file_exists( $woocommerce ) ) {
			require_once $woocommerce;
		}

		require_once dirname( __DIR__ ) . '/member-docs.php';
	}
);

tests_add_filter(
	'setup_theme',
	static function (): void {
		// Start every run from empty plugin tables.
		MemberDocs\Installer::remove_all_data();
		MemberDocs\Installer::install();

		if ( class_exists( 'WC_Install' ) ) {
			WC_Install::install();
			$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reload roles after WooCommerce added its own.
			wp_roles();
		}
	}
);

require $member_docs_tests_dir . '/includes/bootstrap.php';
