<?php
/**
 * Acceptance source.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Domain;

/**
 * Where an acceptance was recorded.
 */
enum AcceptanceSource: string {

	case Web       = 'web';
	case Checkout  = 'checkout';
	case Migration = 'migration';

	/**
	 * Human readable label.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Web       => __( 'Website', 'member-docs' ),
			self::Checkout  => __( 'Checkout', 'member-docs' ),
			self::Migration => __( 'Migration', 'member-docs' ),
		};
	}
}
