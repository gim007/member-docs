<?php
/**
 * Database schema, capabilities and upgrades.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs;

use MemberDocs\Integration\WooCommerce\CheckoutIntegration;
use MemberDocs\Pdf\PrivateStorage;

/**
 * Creates and upgrades the custom tables and grants capabilities.
 */
final class Installer {

	public const DB_VERSION        = '1';
	public const DB_VERSION_OPTION = 'member_docs_db_version';

	/**
	 * Opt-in flag that makes uninstall.php delete all data. Off by default: audit data must not vanish by accident.
	 */
	public const DELETE_DATA_OPTION = 'member_docs_delete_data_on_uninstall';

	public const TABLE_TEMPLATES   = 'md_templates';
	public const TABLE_VERSIONS    = 'md_template_versions';
	public const TABLE_ACCEPTANCES = 'md_acceptances';

	/**
	 * Activation hook callback.
	 */
	public static function activate(): void {
		self::install();
		PrivateStorage::in_uploads()->ensure_protected();
	}

	/**
	 * Runs the installer when the stored schema version is behind the code.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Creates or updates tables and grants capabilities. Safe to run repeatedly.
	 */
	public static function install(): void {
		self::create_tables();
		self::add_capabilities();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Fully prefixed table names.
	 *
	 * @return array<string, string> Table names keyed by their unprefixed name.
	 */
	public static function tables(): array {
		global $wpdb;

		return array(
			self::TABLE_TEMPLATES   => $wpdb->prefix . self::TABLE_TEMPLATES,
			self::TABLE_VERSIONS    => $wpdb->prefix . self::TABLE_VERSIONS,
			self::TABLE_ACCEPTANCES => $wpdb->prefix . self::TABLE_ACCEPTANCES,
		);
	}

	/**
	 * Drops the tables and removes options and capabilities. Used by uninstall.php only.
	 */
	public static function remove_all_data(): void {
		global $wpdb;

		foreach ( self::tables() as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall removes the plugin's own tables.
		}

		delete_option( self::DB_VERSION_OPTION );
		delete_option( self::DELETE_DATA_OPTION );
		delete_option( CheckoutIntegration::OPTION );

		$role = get_role( 'administrator' );
		foreach ( Capabilities::ALL as $capability ) {
			$role?->remove_cap( $capability );
		}

		PrivateStorage::in_uploads()->delete_all();
	}

	/**
	 * Creates or alters the custom tables with dbDelta.
	 */
	private static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$tables  = self::tables();

		dbDelta(
			array(
				"CREATE TABLE {$tables[ self::TABLE_TEMPLATES ]} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				slug varchar(191) NOT NULL,
				title varchar(255) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'draft',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug),
				KEY status (status)
				) $charset;",
				"CREATE TABLE {$tables[ self::TABLE_VERSIONS ]} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				template_id bigint(20) unsigned NOT NULL,
				version int(10) unsigned NOT NULL,
				title varchar(255) NOT NULL,
				body longtext NOT NULL,
				content_hash char(64) NOT NULL,
				published_at datetime NOT NULL,
				published_by bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY template_version (template_id,version)
				) $charset;",
				"CREATE TABLE {$tables[ self::TABLE_ACCEPTANCES ]} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				version_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				accepted_at datetime NOT NULL,
				ip_address varchar(45) NOT NULL DEFAULT '',
				user_agent varchar(255) NOT NULL DEFAULT '',
				content_hash char(64) NOT NULL,
				pdf_path varchar(255) DEFAULT NULL,
				pdf_hash char(64) DEFAULT NULL,
				source varchar(20) NOT NULL,
				order_id bigint(20) unsigned DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY version_user (version_id,user_id),
				KEY user_id (user_id),
				KEY accepted_at (accepted_at),
				KEY order_id (order_id)
				) $charset;",
			)
		);
	}

	/**
	 * Grants the plugin capabilities to administrators.
	 */
	private static function add_capabilities(): void {
		$role = get_role( 'administrator' );

		foreach ( Capabilities::ALL as $capability ) {
			$role?->add_cap( $capability );
		}
	}
}
