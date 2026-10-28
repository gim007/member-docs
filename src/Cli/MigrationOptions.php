<?php
/**
 * Migration options.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Cli;

/**
 * Flags of `wp member-docs migrate`.
 */
final class MigrationOptions {

	/**
	 * Constructor.
	 *
	 * @param bool $dry_run                 Validate only, write nothing.
	 * @param int  $batch_size              Rows per batch.
	 * @param bool $create_missing_versions Create placeholder templates/versions referenced by the file.
	 * @param bool $generate_pdfs           Generate PDF copies for imported acceptances.
	 */
	public function __construct(
		public readonly bool $dry_run = false,
		public readonly int $batch_size = 100,
		public readonly bool $create_missing_versions = false,
		public readonly bool $generate_pdfs = false,
	) {}
}
