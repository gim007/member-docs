<?php
/**
 * Installer tests.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Tests\Integration;

use MemberDocs\Capabilities;
use MemberDocs\Installer;
use WP_UnitTestCase;

/**
 * @covers \MemberDocs\Installer
 */
final class InstallerTest extends WP_UnitTestCase {

	public function test_tables_exist(): void {
		global $wpdb;

		foreach ( Installer::tables() as $table ) {
			$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
		}
	}

	public function test_schema_version_is_stored(): void {
		$this->assertSame( Installer::DB_VERSION, get_option( Installer::DB_VERSION_OPTION ) );
	}

	public function test_administrators_get_capabilities(): void {
		$admin = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );

		foreach ( Capabilities::ALL as $capability ) {
			$this->assertTrue( $admin->has_cap( $capability ) );
		}
	}

	public function test_upgrade_runs_when_schema_version_is_outdated(): void {
		update_option( Installer::DB_VERSION_OPTION, '0' );

		Installer::maybe_upgrade();

		$this->assertSame( Installer::DB_VERSION, get_option( Installer::DB_VERSION_OPTION ) );
	}
}
