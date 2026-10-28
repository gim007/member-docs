<?php
/**
 * Migration results.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Cli;

use RuntimeException;

/**
 * Counts imported, skipped and failed rows and keeps the reasons.
 */
final class MigrationReport {

	/**
	 * Number of imported rows (or rows that would be imported in a dry run).
	 *
	 * @var int
	 */
	public int $imported = 0;

	/**
	 * Skipped duplicate rows.
	 *
	 * @var list<array{line: int, reason: string}>
	 */
	public array $skipped = array();

	/**
	 * Failed rows with reasons and the original values.
	 *
	 * @var list<array{line: int, reason: string, values: list<string|null>}>
	 */
	public array $failed = array();

	/**
	 * Records a skipped row.
	 *
	 * @param int    $line   Line number.
	 * @param string $reason Reason.
	 */
	public function skip( int $line, string $reason ): void {
		$this->skipped[] = array(
			'line'   => $line,
			'reason' => $reason,
		);
	}

	/**
	 * Records a failed row.
	 *
	 * @param int               $line   Line number.
	 * @param string            $reason Reason.
	 * @param list<string|null> $values Original values.
	 */
	public function fail( int $line, string $reason, array $values ): void {
		$this->failed[] = array(
			'line'   => $line,
			'reason' => $reason,
			'values' => $values,
		);
	}

	/**
	 * Writes the failed rows as CSV: line, reason, then the original columns.
	 *
	 * @param string       $path   Target file.
	 * @param list<string> $header Original header row.
	 * @throws RuntimeException When the file cannot be written.
	 */
	public function write_failures( string $path, array $header ): void {
		$handle = fopen( $path, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- CLI report file.
		if ( false === $handle ) {
			throw new RuntimeException( sprintf( 'Could not write the report to %s.', esc_html( $path ) ) );
		}

		fputcsv( $handle, array_merge( array( 'line', 'reason' ), $header ), ',', '"', '' );
		foreach ( $this->failed as $failure ) {
			fputcsv( $handle, array_merge( array( (string) $failure['line'], $failure['reason'] ), $failure['values'] ), ',', '"', '' );
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- See above.
	}
}
