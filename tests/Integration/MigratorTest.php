<?php
/**
 * Legacy migration tests.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Tests\Integration;

use InvalidArgumentException;
use MemberDocs\Cli\MigrationOptions;
use MemberDocs\Cli\Migrator;
use MemberDocs\Cli\Seeder;
use MemberDocs\Domain\AcceptanceSource;
use MemberDocs\Domain\TemplateStatus;
use MemberDocs\Repository\AcceptanceFilter;

/**
 * @covers \MemberDocs\Cli\Migrator
 * @covers \MemberDocs\Cli\MigrationReport
 */
final class MigratorTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../fixtures/legacy-acceptances.csv';

	private Migrator $migrator;

	public function set_up(): void {
		parent::set_up();

		$this->migrator = $this->plugin()->migrator();

		self::factory()->user->create( array( 'user_email' => 'alice@example.com' ) );
		self::factory()->user->create( array( 'user_email' => 'bob@example.com' ) );

		$created = $this->create_template( 'terms', '<p>One</p>' );
		$this->plugin()->template_service->update( $created->template->id, 'Terms', 'terms', TemplateStatus::Active, '<p>Two</p>', 1 );
	}

	private function acceptance_count(): int {
		return $this->plugin()->acceptances->count( new AcceptanceFilter() );
	}

	public function test_dry_run_writes_nothing(): void {
		$report = $this->migrator->run( self::FIXTURE, new MigrationOptions( dry_run: true, create_missing_versions: true ) );

		$this->assertSame( 4, $report->imported );
		$this->assertSame( 0, $this->acceptance_count() );
		$this->assertNull( $this->plugin()->templates->find_by_slug( 'legacy-rules' ) );
	}

	public function test_import_records_valid_rows_and_reports_the_rest(): void {
		$report = $this->migrator->run( self::FIXTURE, new MigrationOptions() );

		$this->assertSame( 3, $report->imported );
		$this->assertSame( 3, $this->acceptance_count() );
		$this->assertSame(
			array(
				array(
					'line'   => 5,
					'reason' => 'Duplicate of line 2.',
				),
			),
			$report->skipped
		);
		$this->assertSame(
			array(
				6  => 'No user with email "ghost@example.com".',
				7  => 'Invalid date "2023-02-30 10:00:00"; expected YYYY-MM-DD HH:MM:SS.',
				8  => 'Template "terms" has no version 9.',
				9  => 'Unknown template "legacy-rules" (use --create-missing-versions to create it).',
				10 => 'Invalid email "not-an-email".',
				11 => 'Invalid IP address "999.0.0.1".',
			),
			array_column( $report->failed, 'reason', 'line' )
		);

		$bob        = get_user_by( 'email', 'bob@example.com' );
		$acceptance = $this->plugin()->acceptances->for_user( $bob->ID )[0];
		$this->assertSame( AcceptanceSource::Migration, $acceptance->source );
		$this->assertSame( '2023-02-01 08:00:00', $acceptance->accepted_at->format( 'Y-m-d H:i:s' ) );
		$this->assertNull( $acceptance->pdf_path );
	}

	public function test_second_run_imports_nothing(): void {
		$this->migrator->run( self::FIXTURE, new MigrationOptions() );

		$second = $this->migrator->run( self::FIXTURE, new MigrationOptions() );

		$this->assertSame( 0, $second->imported );
		$this->assertCount( 4, $second->skipped );
		$this->assertSame( 3, $this->acceptance_count() );
	}

	public function test_small_batches_give_the_same_result(): void {
		$ticks  = 0;
		$report = $this->migrator->run(
			self::FIXTURE,
			new MigrationOptions( batch_size: 2 ),
			static function () use ( &$ticks ): void {
				++$ticks;
			}
		);

		$this->assertSame( 3, $report->imported );
		$this->assertSame( 10, $ticks );
		$this->assertSame( 10, $this->migrator->count_rows( self::FIXTURE ) );
	}

	public function test_missing_templates_can_be_created_as_archived_placeholders(): void {
		$report = $this->migrator->run( self::FIXTURE, new MigrationOptions( create_missing_versions: true ) );

		$template = $this->plugin()->templates->find_by_slug( 'legacy-rules' );
		$this->assertSame( 4, $report->imported );
		$this->assertSame( TemplateStatus::Archived, $template?->status );
		$this->assertCount( 2, $this->plugin()->versions->for_template( (int) $template?->id ) );
		$this->assertContains( 'Template "terms" has no version 9.', array_column( $report->failed, 'reason' ) );
	}

	public function test_pdfs_are_generated_on_request_without_sending_email(): void {
		$sent = 0;
		add_filter(
			'pre_wp_mail',
			static function () use ( &$sent ): bool {
				++$sent;
				return true;
			}
		);

		$this->migrator->run( self::FIXTURE, new MigrationOptions( generate_pdfs: true ) );

		$this->assertCount( 3, $this->stored_pdfs() );
		$this->assertSame( 0, $sent );
	}

	public function test_failures_are_written_to_a_csv_report(): void {
		$report = $this->migrator->run( self::FIXTURE, new MigrationOptions() );
		$path   = get_temp_dir() . 'member-docs-test-report.csv';

		$report->write_failures( $path, $this->migrator->header( self::FIXTURE ) );
		$lines = file( $path, FILE_IGNORE_NEW_LINES );
		unlink( $path );

		$this->assertSame( 'line,reason,email,template_slug,version,accepted_at,ip', $lines[0] );
		$this->assertCount( 7, $lines );
		$this->assertStringStartsWith( '6,"No user with email ""ghost@example.com"".",ghost@example.com,terms,1', $lines[1] );
	}

	public function test_unreadable_file_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->migrator->run( '/does/not/exist.csv', new MigrationOptions() );
	}

	public function test_sample_data_imports_on_top_of_the_seed_data(): void {
		$plugin = $this->plugin();
		( new Seeder( $plugin->template_service, $plugin->templates, $plugin->versions, $plugin->acceptance_service ) )->run();
		$sample = dirname( __DIR__, 2 ) . '/sample-data/legacy-acceptances.csv';

		$report = $this->migrator->run( $sample, new MigrationOptions() );

		$this->assertSame( 31, $this->migrator->count_rows( $sample ) );
		$this->assertSame( 18, $report->imported );
		$this->assertCount( 4, $report->skipped, 'Two in-file duplicates and two rows already accepted on the website.' );
		$this->assertCount( 9, $report->failed );
	}
}
