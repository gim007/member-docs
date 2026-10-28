<?php
/**
 * Template persistence.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Repository;

use MemberDocs\Domain\Template;
use MemberDocs\Domain\TemplateStatus;
use MemberDocs\Installer;
use RuntimeException;
use wpdb;

/**
 * Reads and writes rows of the md_templates table.
 */
final class TemplateRepository {

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
		$this->table = $db->prefix . Installer::TABLE_TEMPLATES;
	}

	/**
	 * Finds a template by ID.
	 *
	 * @param int $id Template ID.
	 */
	public function find( int $id ): ?Template {
		$row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table, $id ), ARRAY_A );

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Finds a template by slug.
	 *
	 * @param string $slug Template slug.
	 */
	public function find_by_slug( string $slug ): ?Template {
		$row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE slug = %s', $this->table, $slug ), ARRAY_A );

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * All templates ordered by title, optionally limited to one status.
	 *
	 * @param TemplateStatus|null $status Status filter.
	 * @return list<Template>
	 */
	public function all( ?TemplateStatus $status = null ): array {
		$query = null === $status
			? $this->db->prepare( 'SELECT * FROM %i ORDER BY title ASC, id ASC', $this->table )
			: $this->db->prepare( 'SELECT * FROM %i WHERE status = %s ORDER BY title ASC, id ASC', $this->table, $status->value );

		return array_map( array( $this, 'hydrate' ), $this->db->get_results( $query, ARRAY_A ) );
	}

	/**
	 * Inserts a new template.
	 *
	 * @param string         $slug   Unique slug.
	 * @param string         $title  Title.
	 * @param TemplateStatus $status Status.
	 * @throws RuntimeException When the row cannot be inserted.
	 */
	public function insert( string $slug, string $title, TemplateStatus $status ): Template {
		$now = Timestamps::to_db( Timestamps::now() );

		$inserted = $this->db->insert(
			$this->table,
			array(
				'slug'       => $slug,
				'title'      => $title,
				'status'     => $status->value,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		$template = false === $inserted ? null : $this->find( (int) $this->db->insert_id );
		if ( null === $template ) {
			throw new RuntimeException( esc_html__( 'Could not save the template.', 'member-docs' ) );
		}

		return $template;
	}

	/**
	 * Updates the descriptive fields of a template. Content changes are versions, not updates.
	 *
	 * @param int            $id     Template ID.
	 * @param string         $slug   Unique slug.
	 * @param string         $title  Title.
	 * @param TemplateStatus $status Status.
	 * @throws RuntimeException When the row cannot be updated.
	 */
	public function update( int $id, string $slug, string $title, TemplateStatus $status ): Template {
		$updated = $this->db->update(
			$this->table,
			array(
				'slug'       => $slug,
				'title'      => $title,
				'status'     => $status->value,
				'updated_at' => Timestamps::to_db( Timestamps::now() ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		$template = false === $updated ? null : $this->find( $id );
		if ( null === $template ) {
			throw new RuntimeException( esc_html__( 'Could not save the template.', 'member-docs' ) );
		}

		return $template;
	}

	/**
	 * Whether another template already uses the slug.
	 *
	 * @param string $slug       Slug to check.
	 * @param int    $exclude_id Template ID to ignore (the one being edited).
	 */
	public function slug_exists( string $slug, int $exclude_id = 0 ): bool {
		return null !== $this->db->get_var(
			$this->db->prepare( 'SELECT id FROM %i WHERE slug = %s AND id <> %d', $this->table, $slug, $exclude_id )
		);
	}

	/**
	 * Builds a value object from a row.
	 *
	 * @param array<string, mixed> $row Database row.
	 */
	private function hydrate( array $row ): Template {
		return new Template(
			(int) $row['id'],
			(string) $row['slug'],
			(string) $row['title'],
			TemplateStatus::from( (string) $row['status'] ),
			Timestamps::from_db( (string) $row['created_at'] ),
			Timestamps::from_db( (string) $row['updated_at'] )
		);
	}
}
