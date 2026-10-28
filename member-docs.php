<?php
/**
 * Plugin Name:       Member Documents
 * Description:       Versioned member documents with an auditable acceptance log, PDF copies and an optional WooCommerce checkout integration.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       member-docs
 * WC requires at least: 8.0
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'MEMBER_DOCS_VERSION', '1.0.0' );
define( 'MEMBER_DOCS_FILE', __FILE__ );

if ( ! is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Member Documents is missing its dependencies. Run "composer install" in the plugin directory.', 'member-docs' ) . '</p></div>';
		}
	);
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

register_activation_hook( __FILE__, array( MemberDocs\Installer::class, 'activate' ) );

/**
 * Returns the plugin instance, creating it on first use.
 */
function member_docs(): MemberDocs\Plugin {
	static $plugin = null;

	if ( null === $plugin ) {
		$plugin = new MemberDocs\Plugin();
	}

	return $plugin;
}

add_action(
	'plugins_loaded',
	static function (): void {
		member_docs()->register_hooks();
	}
);
