<?php
/**
 * WP-CLI commands.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Cli;

use InvalidArgumentException;
use WP_CLI;
use WP_CLI\Utils;

/**
 * Manage member documents from the command line.
 */
final class Commands {

	/**
	 * Constructor.
	 *
	 * @param Migrator $migrator Legacy importer.
	 * @param Verifier $verifier Integrity checker.
	 * @param Seeder   $seeder   Demo data seeder.
	 */
	public function __construct(
		private readonly Migrator $migrator,
		private readonly Verifier $verifier,
		private readonly Seeder $seeder,
	) {}

	/**
	 * Imports legacy acceptances from a CSV file.
	 *
	 * Rows already in the acceptance log are skipped, so the command can be re-run
	 * safely. Imported acceptances get source "migration", no PDF unless
	 * --generate-pdfs is given, and no email.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : CSV file with a header row and the columns email, template_slug, version, accepted_at, ip.
	 *
	 * [--dry-run]
	 * : Validate every row and print the report without writing to the database.
	 *
	 * [--batch-size=<n>]
	 * : Rows processed per batch.
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--create-missing-versions]
	 * : Create unknown templates (archived) with placeholder versions instead of failing those rows.
	 *
	 * [--generate-pdfs]
	 * : Generate PDF copies for imported acceptances.
	 *
	 * [--report=<file>]
	 * : Where to write failed rows as CSV. Defaults to a file in the system temp directory.
	 *
	 * ## EXAMPLES
	 *
	 *     wp member-docs migrate sample-data/legacy-acceptances.csv --dry-run
	 *     wp member-docs migrate sample-data/legacy-acceptances.csv --batch-size=500
	 *
	 * @param list<string>         $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function migrate( array $args, array $assoc_args ): void {
		$file       = $args[0];
		$batch_size = (int) ( $assoc_args['batch-size'] ?? 100 );
		if ( $batch_size < 1 ) {
			WP_CLI::error( '--batch-size must be at least 1.' );
		}

		$options = new MigrationOptions(
			(bool) Utils\get_flag_value( $assoc_args, 'dry-run', false ),
			$batch_size,
			(bool) Utils\get_flag_value( $assoc_args, 'create-missing-versions', false ),
			(bool) Utils\get_flag_value( $assoc_args, 'generate-pdfs', false )
		);

		try {
			$progress = Utils\make_progress_bar( $options->dry_run ? 'Checking rows (dry run)' : 'Importing rows', $this->migrator->count_rows( $file ) );
			$report   = $this->migrator->run( $file, $options, array( $progress, 'tick' ) );
			$progress->finish();
		} catch ( InvalidArgumentException $error ) {
			WP_CLI::error( $error->getMessage() );
			return;
		}

		WP_CLI::log( sprintf( '%s: %d', $options->dry_run ? 'Would import' : 'Imported', $report->imported ) );
		WP_CLI::log( sprintf( 'Skipped (duplicate): %d', count( $report->skipped ) ) );
		WP_CLI::log( sprintf( 'Failed: %d', count( $report->failed ) ) );

		if ( array() !== $report->failed ) {
			Utils\format_items( 'table', array_map( static fn( array $failure ): array => array_slice( $failure, 0, 2 ), $report->failed ), array( 'line', 'reason' ) );

			$path = (string) ( $assoc_args['report'] ?? get_temp_dir() . 'member-docs-migration-failures-' . gmdate( 'Ymd-His' ) . '.csv' );
			$report->write_failures( $path, $this->migrator->header( $file ) );
			WP_CLI::warning( sprintf( 'Failed rows written to %s', $path ) );
		}

		WP_CLI::success( $options->dry_run ? 'Dry run finished; nothing was written.' : 'Migration finished.' );
	}

	/**
	 * Verifies the integrity of the acceptance log.
	 *
	 * Recomputes every version's content hash, compares it with the hash stored on
	 * each acceptance and checks that every PDF exists and is unmodified. Exits with
	 * a non-zero code when problems are found.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format for problems.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp member-docs verify
	 *
	 * @param list<string>         $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function verify( array $args, array $assoc_args ): void {
		$problems = $this->verifier->run();

		if ( array() === $problems ) {
			WP_CLI::success( 'All acceptances verified.' );
			return;
		}

		Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $problems, array( 'acceptance_id', 'problem' ) );
		WP_CLI::error( sprintf( '%d problem(s) found.', count( $problems ) ) );
	}

	/**
	 * Creates demo documents, members, pages and acceptances for local review.
	 *
	 * Safe to run more than once. Demo members are member01@example.com to
	 * member12@example.com; their passwords are random (log in as the admin).
	 *
	 * ## EXAMPLES
	 *
	 *     wp member-docs seed
	 */
	public function seed(): void {
		$result = $this->seeder->run();

		WP_CLI::log( sprintf( 'Documents: %d, members: %d, acceptances: %d', $result['documents'], $result['members'], $result['acceptances'] ) );
		foreach ( $result['pages'] as $url ) {
			WP_CLI::log( 'Page: ' . $url );
		}
		WP_CLI::success( 'Demo data ready.' );
	}
}
