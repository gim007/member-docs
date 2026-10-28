<?php
/**
 * Content hashing.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Domain;

/**
 * Computes the audit hash of a version's title and body.
 *
 * Whitespace runs and line endings are normalized first, so re-saving the same text
 * from a different editor or OS produces the same hash, while any change to the
 * actual words does not.
 */
final class ContentHash {

	/**
	 * SHA-256 of the normalized title and body.
	 *
	 * @param string $title Title.
	 * @param string $body  HTML body.
	 */
	public static function of( string $title, string $body ): string {
		return hash( 'sha256', self::normalize( $title ) . "\n" . self::normalize( $body ) );
	}

	/**
	 * Unifies line endings, collapses whitespace runs to one space and trims.
	 *
	 * @param string $text Text to normalize.
	 */
	public static function normalize( string $text ): string {
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}
}
