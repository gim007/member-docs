<?php
/**
 * Legacy acceptance import.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Cli;

use InvalidArgumentException;
use MemberDocs\Domain\Template;
use MemberDocs\Domain\TemplateStatus;
use MemberDocs\Domain\TemplateVersion;
use MemberDocs\Repository\AcceptanceRepository;
use MemberDocs\Repository\TemplateRepository;
use MemberDocs\Repository\VersionRepository;
use MemberDocs\Service\AcceptanceService;
use MemberDocs\Service\ClientInfo;
use RuntimeException;
use SplFileObject;
use Throwable;

/**
 * Imports acceptances from a legacy CSV export.
 *
 * Idempotent: rows already in the log (same member and version) are skipped, so
 * the import can be re-run after fixing failed rows. Rows are processed in
 * batches and the runtime object cache is flushed between batches to keep memory flat.
 */
final class Migrator {

	/**
	 * Constructor.
	 *
	 * @param TemplateRepository   $templates   Template repository.
	 * @param VersionRepository    $versions    Version repository.
	 * @param AcceptanceRepository $acceptances Acceptance repository.
	 * @param AcceptanceService    $service     Acceptance service.
	 */
	public function __construct(
		private readonly TemplateRepository $templates,
		private readonly VersionRepository $versions,
		private readonly AcceptanceRepository $acceptances,
		private readonly AcceptanceService $service,
	) {}

	/**
	 * Number of data rows in a CSV file (for the progress bar).
	 *
	 * @param string $file CSV path.
	 */
	public function count_rows( string $file ): int {
		$rows = -1;
		foreach ( $this->open( $file ) as $values ) {
			if ( is_array( $values ) && array( null ) !== $values ) {
				++$rows;
			}
		}

		return max( 0, $rows );
	}

	/**
	 * Header row of a CSV file.
	 *
	 * @param string $file CSV path.
	 * @return list<string>
	 */
	public function header( string $file ): array {
		$header = $this->open( $file )->fgetcsv();

		return is_array( $header ) ? array_map( 'strval', $header ) : array();
	}

	/**
	 * Runs the import.
	 *
	 * @param string                  $file    CSV path.
	 * @param MigrationOptions        $options Options.
	 * @param (callable(): void)|null $tick    Called once per data row (progress bar).
	 * @throws InvalidArgumentException When the file cannot be read or the header is invalid.
	 */
	public function run( string $file, MigrationOptions $options, ?callable $tick = null ): MigrationReport {
		$csv    = $this->open( $file );
		$header = $csv->fgetcsv();
		$parser = new LegacyRowParser( is_array( $header ) ? $header : array() );
		$report = new MigrationReport();
		$seen   = array();
		$batch  = array();
		$line   = 1;

		while ( ! $csv->eof() ) {
			$values = $csv->fgetcsv();
			++$line;

			if ( ! is_array( $values ) || array( null ) === $values ) {
				continue;
			}

			$batch[ $line ] = $values;
			if ( count( $batch ) >= $options->batch_size ) {
				$this->process_batch( $batch, $parser, $options, $report, $seen, $tick );
				$batch = array();
			}
		}

		$this->process_batch( $batch, $parser, $options, $report, $seen, $tick );

		return $report;
	}

	/**
	 * Processes one batch of rows.
	 *
	 * @param array<int, list<string|null>> $batch   Rows keyed by line number.
	 * @param LegacyRowParser               $parser  Row parser.
	 * @param MigrationOptions              $options Options.
	 * @param MigrationReport               $report  Report to fill.
	 * @param array<string, int>            $seen    Row keys handled so far, mapped to their line.
	 * @param (callable(): void)|null       $tick    Progress callback.
	 */
	private function process_batch( array $batch, LegacyRowParser $parser, MigrationOptions $options, MigrationReport $report, array &$seen, ?callable $tick ): void {
		foreach ( $batch as $line => $values ) {
			$this->process( $line, $values, $parser, $options, $report, $seen );

			if ( null !== $tick ) {
				$tick();
			}
		}

		wp_cache_flush_runtime();
	}

	/**
	 * Imports, skips or rejects one row.
	 *
	 * @param int                $line    Line number.
	 * @param list<string|null>  $values  Raw values.
	 * @param LegacyRowParser    $parser  Row parser.
	 * @param MigrationOptions   $options Options.
	 * @param MigrationReport    $report  Report to fill.
	 * @param array<string, int> $seen    Row keys handled so far, mapped to their line.
	 */
	private function process( int $line, array $values, LegacyRowParser $parser, MigrationOptions $options, MigrationReport $report, array &$seen ): void {
		try {
			$row = $parser->parse( $values, $line );
		} catch ( InvalidArgumentException $error ) {
			$report->fail( $line, $error->getMessage(), $values );
			return;
		}

		if ( isset( $seen[ $row->key() ] ) ) {
			$report->skip( $line, sprintf( 'Duplicate of line %d.', $seen[ $row->key() ] ) );
			return;
		}

		$user = get_user_by( 'email', $row->email );
		if ( false === $user ) {
			$report->fail( $line, sprintf( 'No user with email "%s".', $row->email ), $values );
			return;
		}

		$template = $this->templates->find_by_slug( $row->template_slug );
		$version  = null === $template ? null : $this->versions->find_by_number( $template->id, $row->version );

		if ( null === $version ) {
			$problem = $this->missing_version_problem( $row, $template, $options );
			if ( null !== $problem ) {
				$report->fail( $line, $problem, $values );
				return;
			}
			if ( ! $options->dry_run ) {
				$version = $this->create_missing_version( $row, $template );
			}
		}

		if ( null !== $version && null !== $this->acceptances->find_for( $version->id, $user->ID ) ) {
			$seen[ $row->key() ] = $line;
			$report->skip( $line, 'Already imported.' );
			return;
		}

		if ( null !== $version && ! $options->dry_run ) {
			try {
				$this->service->import( $version, $user, $row->accepted_at, new ClientInfo( $row->ip ), $options->generate_pdfs );
			} catch ( Throwable $error ) {
				$report->fail( $line, $error->getMessage(), $values );
				return;
			}
		}

		$seen[ $row->key() ] = $line;
		++$report->imported;
	}

	/**
	 * Explains why a missing version cannot be created, or null if it can.
	 *
	 * Only versions of templates that do not exist yet are created. Adding a
	 * placeholder version to an existing template would make it the current
	 * version and ask every member to accept it.
	 *
	 * @param LegacyRow        $row      Row.
	 * @param Template|null    $template Existing template.
	 * @param MigrationOptions $options  Options.
	 */
	private function missing_version_problem( LegacyRow $row, ?Template $template, MigrationOptions $options ): ?string {
		if ( null !== $template ) {
			return sprintf( 'Template "%s" has no version %d.', $row->template_slug, $row->version );
		}
		if ( ! $options->create_missing_versions ) {
			return sprintf( 'Unknown template "%s" (use --create-missing-versions to create it).', $row->template_slug );
		}

		return null;
	}

	/**
	 * Creates an archived template with placeholder versions up to the requested number.
	 *
	 * @param LegacyRow     $row      Row.
	 * @param Template|null $template Template created earlier in this run, if any.
	 * @throws RuntimeException When the version cannot be created.
	 */
	private function create_missing_version( LegacyRow $row, ?Template $template ): TemplateVersion {
		$template ??= $this->templates->insert( $row->template_slug, ucwords( str_replace( '-', ' ', $row->template_slug ) ), TemplateStatus::Archived );

		$latest = $this->versions->latest_for( $template->id );
		for ( $number = ( $latest->version ?? 0 ) + 1; $number <= $row->version; $number++ ) {
			$this->versions->insert(
				$template->id,
				$template->title,
				sprintf( '<p>Legacy version %d imported from a previous system. The original text was not migrated.</p>', $number ),
				0
			);
		}

		$version = $this->versions->find_by_number( $template->id, $row->version );
		if ( null === $version ) {
			throw new RuntimeException( 'Could not create the legacy version.' );
		}

		return $version;
	}

	/**
	 * Opens a CSV file.
	 *
	 * @param string $file CSV path.
	 * @throws InvalidArgumentException When the file is not readable.
	 */
	private function open( string $file ): SplFileObject {
		if ( ! is_readable( $file ) || ! is_file( $file ) ) {
			throw new InvalidArgumentException( sprintf( 'Cannot read %s.', esc_html( $file ) ) );
		}

		$csv = new SplFileObject( $file, 'r' );
		$csv->setFlags( SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD );
		$csv->setCsvControl( ',', '"', '' );

		return $csv;
	}
}
