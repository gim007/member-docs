<?php
/**
 * Template version persistence.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Repository;

use DateTimeImmutable;
use MemberDocs\Domain\ContentHash;
use MemberDocs\Domain\TemplateVersion;
use MemberDocs\Installer;
use RuntimeException;
use wpdb;

/**
 * Reads and appends rows of the md_template_versions table.
 *
 * Versions are immutable: this class deliberately has no update or delete method.
 */
final class VersionRepository {

	/**
	 * Prefixed table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Constructor.
	 *
	 * @param wpdb $db Database connection.
	 */
	public function __construct( private readonly wpdb $db ) {
		$this->table = $db->prefix . Installer::TABLE_VERSIONS;
	}

	/**
	 * Finds a version by ID.
	 *
	 * @param int $id Version ID.
	 */
	public function find( int $id ): ?TemplateVersion {
		$row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table, $id ), ARRAY_A );

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Finds a version by template and version number.
	 *
	 * @param int $template_id Template ID.
	 * @param int $number      Version number.
	 */
	public function find_by_number( int $template_id, int $number ): ?TemplateVersion {
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT * FROM %i WHERE template_id = %d AND version = %d', $this->table, $template_id, $number ),
			ARRAY_A
		);

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Highest published version of a template.
	 *
	 * @param int $template_id Template ID.
	 */
	public function latest_for( int $template_id ): ?TemplateVersion {
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT * FROM %i WHERE template_id = %d ORDER BY version DESC LIMIT 1', $this->table, $template_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * All versions of a template, newest first.
	 *
	 * @param int $template_id Template ID.
	 * @return list<TemplateVersion>
	 */
	public function for_template( int $template_id ): array {
		$rows = $this->db->get_results(
			$this->db->prepare( 'SELECT * FROM %i WHERE template_id = %d ORDER BY version DESC', $this->table, $template_id ),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Publishes the next version of a template.
	 *
	 * The version number is computed inside the INSERT so two concurrent publishes
	 * cannot pick the same number; the unique key rejects the loser.
	 *
	 * @param int                    $template_id  Template ID.
	 * @param string                 $title        Title.
	 * @param string                 $body         HTML body.
	 * @param int                    $published_by Publishing user ID, 0 for system.
	 * @param DateTimeImmutable|null $published_at Publication time, defaults to now.
	 * @throws RuntimeException When the row cannot be inserted.
	 */
	public function insert( int $template_id, string $title, string $body, int $published_by, ?DateTimeImmutable $published_at = null ): TemplateVersion {
		$inserted = $this->db->query(
			$this->db->prepare(
				'INSERT INTO %i (template_id, version, title, body, content_hash, published_at, published_by)
				SELECT %d, COALESCE(MAX(version), 0) + 1, %s, %s, %s, %s, %d FROM %i WHERE template_id = %d',
				$this->table,
				$template_id,
				$title,
				$body,
				ContentHash::of( $title, $body ),
				Timestamps::to_db( $published_at ?? Timestamps::now() ),
				$published_by,
				$this->table,
				$template_id
			)
		);

		$version = false === $inserted ? null : $this->find( (int) $this->db->insert_id );
		if ( null === $version ) {
			throw new RuntimeException( esc_html__( 'Could not publish the version.', 'member-docs' ) );
		}

		return $version;
	}

	/**
	 * Builds a value object from a row.
	 *
	 * @param array<string, mixed> $row Database row.
	 */
	private function hydrate( array $row ): TemplateVersion {
		return new TemplateVersion(
			(int) $row['id'],
			(int) $row['template_id'],
			(int) $row['version'],
			(string) $row['title'],
			(string) $row['body'],
			(string) $row['content_hash'],
			Timestamps::from_db( (string) $row['published_at'] ),
			(int) $row['published_by']
		);
	}
}
