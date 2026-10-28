<?php
/**
 * Acceptance log CSV export.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Admin;

use MemberDocs\Domain\Template;
use MemberDocs\Domain\TemplateVersion;
use MemberDocs\Repository\AcceptanceFilter;
use MemberDocs\Repository\AcceptanceRepository;
use MemberDocs\Repository\TemplateRepository;
use MemberDocs\Repository\VersionRepository;

/**
 * Writes the acceptance log as CSV, row by row, so large logs never sit in memory.
 */
final class CsvExporter {

	/**
	 * Versions loaded during the export, keyed by ID.
	 *
	 * @var array<int, TemplateVersion|null>
	 */
	private array $versions_cache = array();

	/**
	 * Templates loaded during the export, keyed by ID.
	 *
	 * @var array<int, Template|null>
	 */
	private array $templates_cache = array();

	/**
	 * Constructor.
	 *
	 * @param AcceptanceRepository $acceptances Acceptance repository.
	 * @param TemplateRepository   $templates   Template repository.
	 * @param VersionRepository    $versions    Version repository.
	 */
	public function __construct(
		private readonly AcceptanceRepository $acceptances,
		private readonly TemplateRepository $templates,
		private readonly VersionRepository $versions,
	) {}

	/**
	 * Writes the header and all matching rows.
	 *
	 * @param resource         $handle Writable stream.
	 * @param AcceptanceFilter $filter Criteria.
	 * @return int Number of data rows written.
	 */
	public function write( $handle, AcceptanceFilter $filter ): int {
		$this->put(
			$handle,
			array( 'id', 'accepted_at_utc', 'template', 'version', 'user_id', 'user_email', 'user_name', 'source', 'order_id', 'ip_address', 'user_agent', 'content_hash', 'has_pdf' )
		);

		$rows = 0;
		foreach ( $this->acceptances->each( $filter ) as $acceptance ) {
			$version  = $this->version( $acceptance->version_id );
			$template = null === $version ? null : $this->template( $version->template_id );
			$user     = get_userdata( $acceptance->user_id );

			$this->put(
				$handle,
				array(
					$acceptance->id,
					$acceptance->accepted_at->format( 'Y-m-d H:i:s' ),
					$template->slug ?? '',
					$version->version ?? '',
					$acceptance->user_id,
					false === $user ? '' : $user->user_email,
					false === $user ? '' : $user->display_name,
					$acceptance->source->value,
					$acceptance->order_id,
					$acceptance->ip_address,
					$acceptance->user_agent,
					$acceptance->content_hash,
					null === $acceptance->pdf_path ? 'no' : 'yes',
				)
			);
			++$rows;
		}

		return $rows;
	}

	/**
	 * Neutralises spreadsheet formulas (CSV injection).
	 *
	 * Values starting with =, +, -, @, tab or carriage return are prefixed with a
	 * single quote so spreadsheet software treats them as text.
	 *
	 * @param string|int|null $value Cell value.
	 */
	public static function cell( string|int|null $value ): string {
		$value = (string) $value;

		return '' !== $value && str_contains( "=+-@\t\r", $value[0] ) ? "'" . $value : $value;
	}

	/**
	 * Writes one escaped row.
	 *
	 * @param resource                   $handle Writable stream.
	 * @param array<int, string|int|null> $row    Cells.
	 */
	private function put( $handle, array $row ): void {
		fputcsv( $handle, array_map( array( self::class, 'cell' ), $row ), ',', '"', '' );
	}

	/**
	 * Cached version lookup.
	 *
	 * @param int $id Version ID.
	 */
	private function version( int $id ): ?TemplateVersion {
		if ( ! array_key_exists( $id, $this->versions_cache ) ) {
			$this->versions_cache[ $id ] = $this->versions->find( $id );
		}

		return $this->versions_cache[ $id ];
	}

	/**
	 * Cached template lookup.
	 *
	 * @param int $id Template ID.
	 */
	private function template( int $id ): ?Template {
		if ( ! array_key_exists( $id, $this->templates_cache ) ) {
			$this->templates_cache[ $id ] = $this->templates->find( $id );
		}

		return $this->templates_cache[ $id ];
	}
}
