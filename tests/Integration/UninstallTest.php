<?php
/**
 * Uninstall tests.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Tests\Integration;

use MemberDocs\Capabilities;
use MemberDocs\Installer;

/**
 * @covers \MemberDocs\Installer::remove_all_data
 */
final class UninstallTest extends TestCase {

	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'member-docs/member-docs.php' );
		}
	}

	public function tear_down(): void {
		// Restore the capabilities removed by the opt-in test for the tests that follow.
		Installer::install();
		parent::tear_down();
	}

	private function table_exists( string $table ): bool {
		global $wpdb;

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	private function uninstall(): void {
		require dirname( __DIR__, 2 ) . '/uninstall.php';
	}

	public function test_data_is_kept_by_default(): void {
		$this->accept( $this->create_template()->published, $this->member() );

		$this->uninstall();

		foreach ( Installer::tables() as $table ) {
			$this->assertTrue( $this->table_exists( $table ) );
		}
		$this->assertSame( Installer::DB_VERSION, get_option( Installer::DB_VERSION_OPTION ) );
		$this->assertDirectoryExists( $this->plugin()->storage->base_dir() );
	}

	public function test_everything_is_removed_when_opted_in(): void {
		update_option( Installer::DELETE_DATA_OPTION, '1' );
		$this->plugin()->storage->ensure_protected();

		// The WP test case turns DROP TABLE into DROP TEMPORARY TABLE to protect shared tables,
		// so capture the statements before that rewrite instead of checking the schema.
		$drops = array();
		add_filter(
			'query',
			static function ( string $query ) use ( &$drops ): string {
				if ( str_starts_with( $query, 'DROP TABLE' ) ) {
					$drops[] = $query;
				}
				return $query;
			},
			1
		);

		$this->uninstall();

		foreach ( Installer::tables() as $table ) {
			$this->assertContains( "DROP TABLE IF EXISTS `{$table}`", $drops );
		}
		$this->assertFalse( get_option( Installer::DB_VERSION_OPTION ) );
		$this->assertFalse( get_option( Installer::DELETE_DATA_OPTION ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( Capabilities::MANAGE_TEMPLATES ) );
		$this->assertDirectoryDoesNotExist( $this->plugin()->storage->base_dir() );
	}
}
