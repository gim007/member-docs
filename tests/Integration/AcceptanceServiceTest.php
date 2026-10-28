<?php
/**
 * Acceptance flow tests.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Tests\Integration;

use MemberDocs\Domain\Acceptance;
use MemberDocs\Domain\AcceptanceSource;
use MemberDocs\Domain\TemplateStatus;
use MemberDocs\Repository\AcceptanceFilter;
use MemberDocs\Service\StaleVersionException;
use RuntimeException;

/**
 * @covers \MemberDocs\Service\AcceptanceService
 * @covers \MemberDocs\Repository\AcceptanceRepository
 */
final class AcceptanceServiceTest extends TestCase {

	public function test_accepting_records_an_audit_entry_with_pdf(): void {
		$version = $this->create_template()->published;
		$user    = $this->member();

		$acceptance = $this->accept( $version, $user );

		$this->assertGreaterThan( 0, $acceptance->id );
		$this->assertSame( $version->id, $acceptance->version_id );
		$this->assertSame( $user->ID, $acceptance->user_id );
		$this->assertSame( $version->content_hash, $acceptance->content_hash );
		$this->assertSame( AcceptanceSource::Web, $acceptance->source );
		$this->assertSame( '203.0.113.7', $acceptance->ip_address );
		$this->assertSame( 'Test Browser', $acceptance->user_agent );
		$this->assertNull( $acceptance->order_id );
		$this->assertEquals( $acceptance, $this->plugin()->acceptances->find( $acceptance->id ) );

		$this->assertNotNull( $acceptance->pdf_path );
		$this->assertTrue( $this->plugin()->storage->exists( $acceptance->pdf_path ) );
		$this->assertSame( $acceptance->pdf_hash, $this->plugin()->storage->hash( $acceptance->pdf_path ) );
		$this->assertStringStartsWith( '%PDF-', (string) file_get_contents( $this->plugin()->storage->path( $acceptance->pdf_path ) ) );
	}

	public function test_accepting_twice_is_idempotent(): void {
		$version = $this->create_template()->published;
		$user    = $this->member();

		$first  = $this->accept( $version, $user );
		$second = $this->accept( $version, $user );

		$this->assertEquals( $first, $second );
		$this->assertSame( 1, $this->plugin()->acceptances->count( new AcceptanceFilter( version_id: $version->id ) ) );
		$this->assertCount( 1, $this->stored_pdfs() );
	}

	public function test_accepted_action_fires_once_with_the_new_record(): void {
		$version  = $this->create_template()->published;
		$user     = $this->member();
		$received = array();
		add_action(
			'member_docs_accepted',
			static function ( Acceptance $acceptance ) use ( &$received ): void {
				$received[] = $acceptance;
			}
		);

		$acceptance = $this->accept( $version, $user );
		$this->accept( $version, $user );

		$this->assertEquals( array( $acceptance ), $received );
	}

	public function test_outdated_version_cannot_be_accepted(): void {
		$created = $this->create_template( 'terms', '<p>One</p>' );
		$this->plugin()->template_service->update( $created->template->id, 'Terms', 'terms', TemplateStatus::Active, '<p>Two</p>', 1 );

		$this->expectException( StaleVersionException::class );
		$this->accept( $created->published, $this->member() );
	}

	public function test_inactive_template_cannot_be_accepted(): void {
		$created = $this->create_template( 'terms', '<p>One</p>', TemplateStatus::Archived );

		$this->expectException( StaleVersionException::class );
		$this->accept( $created->published, $this->member() );
	}

	public function test_failed_insert_removes_the_generated_pdf(): void {
		global $wpdb;

		$version = $this->create_template()->published;
		$table   = $wpdb->prefix . 'md_acceptances';
		add_filter(
			'query',
			static fn( string $query ): string => str_starts_with( $query, "INSERT INTO `{$table}`" ) ? 'INSERT INTO `md_missing_table` (id) VALUES (1)' : $query
		);
		$suppress = $wpdb->suppress_errors( true );

		try {
			$this->accept( $version, $this->member() );
			$this->fail( 'The acceptance should not have been recorded.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( array(), $this->stored_pdfs() );
			$this->assertSame( 0, $this->plugin()->acceptances->count( new AcceptanceFilter() ) );
		} finally {
			$wpdb->suppress_errors( $suppress );
		}
	}

	public function test_import_records_historical_acceptance_without_pdf_or_action(): void {
		$created = $this->create_template( 'terms', '<p>One</p>' );
		$this->plugin()->template_service->update( $created->template->id, 'Terms', 'terms', TemplateStatus::Active, '<p>Two</p>', 1 );
		$fired = did_action( 'member_docs_accepted' );
		$when  = new \DateTimeImmutable( '2020-05-01 10:00:00', new \DateTimeZone( 'UTC' ) );

		$acceptance = $this->plugin()->acceptance_service->import( $created->published, $this->member(), $when, new \MemberDocs\Service\ClientInfo( '198.51.100.1' ), false );

		$this->assertSame( AcceptanceSource::Migration, $acceptance->source );
		$this->assertEquals( $when, $acceptance->accepted_at );
		$this->assertNull( $acceptance->pdf_path );
		$this->assertSame( $fired, did_action( 'member_docs_accepted' ) );
	}
}
