<?php
/**
 * Private storage tests.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Tests\Integration;

use DateTimeImmutable;
use MemberDocs\Pdf\PrivateStorage;
use RuntimeException;

/**
 * @covers \MemberDocs\Pdf\PrivateStorage
 */
final class PrivateStorageTest extends TestCase {

	public function test_acceptance_pdf_is_stored_in_the_protected_private_directory(): void {
		$acceptance = $this->accept( $this->create_template()->published, $this->member() );
		$storage    = $this->plugin()->storage;
		$uploads    = wp_upload_dir();

		$this->assertSame( $uploads['basedir'] . '/' . PrivateStorage::DIRECTORY, $storage->base_dir() );
		$this->assertMatchesRegularExpression( '#^\d{4}/\d{2}/[a-f0-9]{32}\.pdf$#', (string) $acceptance->pdf_path );
		$this->assertStringContainsString( 'Require all denied', (string) file_get_contents( $storage->base_dir() . '/.htaccess' ) );
		$this->assertStringContainsString( '<deny users="*" />', (string) file_get_contents( $storage->base_dir() . '/web.config' ) );
		$this->assertFileExists( $storage->base_dir() . '/index.php' );
	}

	public function test_pdf_is_not_inside_the_public_year_month_upload_folders(): void {
		$acceptance = $this->accept( $this->create_template()->published, $this->member() );
		$uploads    = wp_upload_dir();

		$this->assertStringStartsNotWith( $uploads['path'], $this->plugin()->storage->path( (string) $acceptance->pdf_path ) );
		$this->assertSame( array(), glob( $uploads['path'] . '/*.pdf' ) );
	}

	public function test_file_names_are_random(): void {
		$storage = $this->plugin()->storage;
		$now     = new DateTimeImmutable();

		$this->assertNotSame( $storage->store( 'a', $now ), $storage->store( 'a', $now ) );
	}

	public function test_paths_outside_the_storage_root_are_rejected(): void {
		$this->expectException( RuntimeException::class );
		$this->plugin()->storage->path( '../../wp-config.php' );
	}
}
