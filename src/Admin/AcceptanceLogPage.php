<?php
/**
 * Acceptance log admin screen.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Admin;

use DateTimeImmutable;
use MemberDocs\Capabilities;
use MemberDocs\Repository\AcceptanceFilter;
use MemberDocs\Repository\AcceptanceRepository;
use MemberDocs\Repository\TemplateRepository;
use MemberDocs\Repository\VersionRepository;
use MemberDocs\View;

/**
 * Filterable acceptance log with CSV export.
 */
final class AcceptanceLogPage {

	public const SLUG          = 'member-docs-log';
	public const EXPORT_ACTION = 'member_docs_export_acceptances';

	private const FILTER_KEYS = array( 'template_id', 'version_id', 'user', 'from', 'to' );

	/**
	 * Constructor.
	 *
	 * @param AcceptanceRepository $acceptances Acceptance repository.
	 * @param TemplateRepository   $templates   Template repository.
	 * @param VersionRepository    $versions    Version repository.
	 * @param CsvExporter          $exporter    CSV exporter.
	 */
	public function __construct(
		private readonly AcceptanceRepository $acceptances,
		private readonly TemplateRepository $templates,
		private readonly VersionRepository $versions,
		private readonly CsvExporter $exporter,
	) {}

	/**
	 * URL of the log, optionally with filters.
	 *
	 * @param array<string, string|int> $args Query arguments.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array( 'page' => self::SLUG ) + $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Renders the log screen.
	 */
	public function render(): void {
		$query = self::read_query();
		$table = new AcceptanceLogListTable( $this->acceptances, $this->versions, self::filter( $query ) );
		$table->prepare_items();

		$export_url = wp_nonce_url(
			add_query_arg( array( 'action' => self::EXPORT_ACTION ) + array_filter( $query ), admin_url( 'admin-post.php' ) ),
			self::EXPORT_ACTION
		);

		View::display(
			'admin/acceptance-log',
			array(
				'table'      => $table,
				'query'      => $query,
				'templates'  => $this->templates->all(),
				'export_url' => $export_url,
				'page'       => self::SLUG,
			)
		);
	}

	/**
	 * Streams the filtered log as CSV (admin-post.php).
	 */
	public function handle_export(): void {
		if ( ! current_user_can( Capabilities::VIEW_ACCEPTANCES ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to export acceptances.', 'member-docs' ), 403 );
		}
		check_admin_referer( self::EXPORT_ACTION );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="acceptances-' . gmdate( 'Y-m-d-His' ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );

		$output = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming to the response body.
		if ( false !== $output ) {
			$this->exporter->write( $output, self::filter( self::read_query() ) );
			fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- See above.
		}
		exit;
	}

	/**
	 * Builds a repository filter from the (sanitized) query.
	 *
	 * The user may be given as ID, email or login. Dates are whole days (Y-m-d) in the
	 * site's time zone and are converted to a UTC range.
	 *
	 * @param array<string, string> $query Values keyed by FILTER_KEYS.
	 */
	public static function filter( array $query ): AcceptanceFilter {
		$user_id = null;
		$user    = $query['user'] ?? '';

		if ( '' !== $user ) {
			$found = ctype_digit( $user ) ? get_userdata( (int) $user ) : get_user_by( 'email', $user );
			if ( false === $found && ! ctype_digit( $user ) ) {
				$found = get_user_by( 'login', $user );
			}
			$user_id = false === $found ? 0 : $found->ID;
		}

		$to = self::day( $query['to'] ?? '' );

		return new AcceptanceFilter(
			self::id( $query['template_id'] ?? '' ),
			self::id( $query['version_id'] ?? '' ),
			$user_id,
			self::day( $query['from'] ?? '' ),
			$to?->modify( '+1 day -1 second' )
		);
	}

	/**
	 * Reads the filter values from the request.
	 *
	 * @return array<string, string>
	 */
	private static function read_query(): array {
		$query = array();
		foreach ( self::FILTER_KEYS as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filters; the export checks its own nonce.
			$query[ $key ] = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
		}

		return $query;
	}

	/**
	 * Parses a positive ID; anything else means "any".
	 *
	 * @param string $value Raw value.
	 */
	private static function id( string $value ): ?int {
		$id = absint( $value );

		return 0 === $id ? null : $id;
	}

	/**
	 * Parses a Y-m-d date as the start of that day in the site's time zone.
	 *
	 * @param string $value Date string.
	 */
	private static function day( string $value ): ?DateTimeImmutable {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );

		return false === $date ? null : $date;
	}
}
