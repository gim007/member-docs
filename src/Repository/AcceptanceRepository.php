<?php
/**
 * Acceptance persistence.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Repository;

use Generator;
use MemberDocs\Domain\Acceptance;
use MemberDocs\Domain\AcceptanceSource;
use MemberDocs\Installer;
use RuntimeException;
use wpdb;

/**
 * Reads and appends rows of the md_acceptances table.
 *
 * The log is append-only: this class deliberately has no update or delete method.
 */
final class AcceptanceRepository {

	/**
	 * Prefixed acceptances table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Prefixed versions table name.
	 *
	 * @var string
	 */
	private string $versions_table;

	/**
	 * Constructor.
	 *
	 * @param wpdb $db Database connection.
	 */
	public function __construct( private readonly wpdb $db ) {
		$this->table          = $db->prefix . Installer::TABLE_ACCEPTANCES;
		$this->versions_table = $db->prefix . Installer::TABLE_VERSIONS;
	}

	/**
	 * Finds an acceptance by ID.
	 *
	 * @param int $id Acceptance ID.
	 */
	public function find( int $id ): ?Acceptance {
		$row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table, $id ), ARRAY_A );

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Finds the acceptance of a version by a user.
	 *
	 * @param int $version_id Version ID.
	 * @param int $user_id    User ID.
	 */
	public function find_for( int $version_id, int $user_id ): ?Acceptance {
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT * FROM %i WHERE version_id = %d AND user_id = %d', $this->table, $version_id, $user_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Appends an acceptance to the log.
	 *
	 * @param Acceptance $acceptance Record to store; its ID is ignored.
	 * @return Acceptance The stored record with its ID.
	 * @throws RuntimeException When the row cannot be inserted, e.g. on a duplicate (version, user).
	 */
	public function insert( Acceptance $acceptance ): Acceptance {
		$inserted = $this->db->insert(
			$this->table,
			array(
				'version_id'   => $acceptance->version_id,
				'user_id'      => $acceptance->user_id,
				'accepted_at'  => Timestamps::to_db( $acceptance->accepted_at ),
				'ip_address'   => $acceptance->ip_address,
				'user_agent'   => $acceptance->user_agent,
				'content_hash' => $acceptance->content_hash,
				'pdf_path'     => $acceptance->pdf_path,
				'pdf_hash'     => $acceptance->pdf_hash,
				'source'       => $acceptance->source->value,
				'order_id'     => $acceptance->order_id,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		if ( false === $inserted ) {
			throw new RuntimeException( esc_html__( 'Could not record the acceptance.', 'member-docs' ) );
		}

		return $acceptance->with_id( (int) $this->db->insert_id );
	}

	/**
	 * Acceptances of a user, newest first.
	 *
	 * @param int $user_id User ID.
	 * @return list<Acceptance>
	 */
	public function for_user( int $user_id ): array {
		$rows = $this->db->get_results(
			$this->db->prepare( 'SELECT * FROM %i WHERE user_id = %d ORDER BY accepted_at DESC, id DESC', $this->table, $user_id ),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Number of acceptances across all versions of a template.
	 *
	 * @param int $template_id Template ID.
	 */
	public function count_for_template( int $template_id ): int {
		return (int) $this->db->get_var(
			$this->db->prepare(
				'SELECT COUNT(*) FROM %i a INNER JOIN %i v ON v.id = a.version_id WHERE v.template_id = %d',
				$this->table,
				$this->versions_table,
				$template_id
			)
		);
	}

	/**
	 * One page of matching acceptances, newest first.
	 *
	 * @param AcceptanceFilter $filter Criteria.
	 * @param int              $limit  Page size.
	 * @param int              $offset Rows to skip.
	 * @return list<Acceptance>
	 */
	public function search( AcceptanceFilter $filter, int $limit, int $offset = 0 ): array {
		[ $where, $args ] = $this->where( $filter );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where only contains placeholders built in where().
		$query = $this->db->prepare( "SELECT a.* FROM %i a WHERE {$where} ORDER BY a.accepted_at DESC, a.id DESC LIMIT %d OFFSET %d", $this->table, ...$args, ...array( $limit, $offset ) );

		return array_map( array( $this, 'hydrate' ), $this->db->get_results( $query, ARRAY_A ) );
	}

	/**
	 * Number of matching acceptances.
	 *
	 * @param AcceptanceFilter $filter Criteria.
	 */
	public function count( AcceptanceFilter $filter ): int {
		[ $where, $args ] = $this->where( $filter );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where only contains placeholders built in where().
		return (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM %i a WHERE {$where}", $this->table, ...$args ) );
	}

	/**
	 * Streams all matching acceptances in ID order, loading one batch at a time.
	 *
	 * Uses keyset pagination so memory stays flat for large logs (CSV export, verify).
	 *
	 * @param AcceptanceFilter $filter     Criteria.
	 * @param int              $batch_size Rows per query.
	 * @return Generator<int, Acceptance>
	 */
	public function each( AcceptanceFilter $filter, int $batch_size = 500 ): Generator {
		[ $where, $args ] = $this->where( $filter );
		$last_id          = 0;

		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where only contains placeholders built in where().
			$query = $this->db->prepare( "SELECT a.* FROM %i a WHERE {$where} AND a.id > %d ORDER BY a.id ASC LIMIT %d", $this->table, ...$args, ...array( $last_id, $batch_size ) );
			$rows  = $this->db->get_results( $query, ARRAY_A );
			$found = count( $rows );

			foreach ( $rows as $row ) {
				$acceptance = $this->hydrate( $row );
				$last_id    = $acceptance->id;
				yield $acceptance;
			}
		} while ( $found === $batch_size );
	}

	/**
	 * Builds the WHERE clause for a filter.
	 *
	 * @param AcceptanceFilter $filter Criteria.
	 * @return array{0: string, 1: list<int|string>} SQL with placeholders and their values.
	 */
	private function where( AcceptanceFilter $filter ): array {
		$where = array( '1=1' );
		$args  = array();

		if ( null !== $filter->template_id ) {
			$where[] = 'a.version_id IN (SELECT id FROM %i WHERE template_id = %d)';
			$args[]  = $this->versions_table;
			$args[]  = $filter->template_id;
		}
		if ( null !== $filter->version_id ) {
			$where[] = 'a.version_id = %d';
			$args[]  = $filter->version_id;
		}
		if ( null !== $filter->user_id ) {
			$where[] = 'a.user_id = %d';
			$args[]  = $filter->user_id;
		}
		if ( null !== $filter->from ) {
			$where[] = 'a.accepted_at >= %s';
			$args[]  = Timestamps::to_db( $filter->from );
		}
		if ( null !== $filter->to ) {
			$where[] = 'a.accepted_at <= %s';
			$args[]  = Timestamps::to_db( $filter->to );
		}

		return array( implode( ' AND ', $where ), $args );
	}

	/**
	 * Builds a value object from a row.
	 *
	 * @param array<string, mixed> $row Database row.
	 */
	private function hydrate( array $row ): Acceptance {
		return new Acceptance(
			(int) $row['id'],
			(int) $row['version_id'],
			(int) $row['user_id'],
			Timestamps::from_db( (string) $row['accepted_at'] ),
			(string) $row['ip_address'],
			(string) $row['user_agent'],
			(string) $row['content_hash'],
			null === $row['pdf_path'] ? null : (string) $row['pdf_path'],
			null === $row['pdf_hash'] ? null : (string) $row['pdf_hash'],
			AcceptanceSource::from( (string) $row['source'] ),
			null === $row['order_id'] ? null : (int) $row['order_id']
		);
	}
}
