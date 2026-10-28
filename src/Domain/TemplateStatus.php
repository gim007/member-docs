<?php
/**
 * Template status.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Domain;

/**
 * Lifecycle of a document template. Only active templates can be accepted.
 */
enum TemplateStatus: string {

	case Draft    = 'draft';
	case Active   = 'active';
	case Archived = 'archived';

	/**
	 * Human readable label.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Draft    => __( 'Draft', 'member-docs' ),
			self::Active   => __( 'Active', 'member-docs' ),
			self::Archived => __( 'Archived', 'member-docs' ),
		};
	}
}
