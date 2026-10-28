<?php
/**
 * View rendering.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs;

use DateTimeImmutable;

/**
 * Renders PHP templates from the templates/ directory into a string.
 */
final class View {

	/**
	 * Prints a template. Variables are passed as $args, like core's load_template().
	 * Templates are responsible for escaping their output.
	 *
	 * @param string               $template Path below templates/, without ".php".
	 * @param array<string, mixed> $args     Template variables.
	 */
	public static function display( string $template, array $args = array() ): void {
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $args is read by the required template.
		( static function ( string $member_docs_template, array $args ): void {
			require $member_docs_template;
		} )( dirname( MEMBER_DOCS_FILE ) . '/templates/' . $template . '.php', $args );
	}

	/**
	 * Renders a template into a string.
	 *
	 * @param string               $template Path below templates/, without ".php".
	 * @param array<string, mixed> $args     Template variables.
	 */
	public static function render( string $template, array $args = array() ): string {
		ob_start();
		self::display( $template, $args );

		return (string) ob_get_clean();
	}

	/**
	 * Formats a UTC date in the site's time zone and date/time format.
	 *
	 * @param DateTimeImmutable $date Date.
	 */
	public static function datetime( DateTimeImmutable $date ): string {
		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $date->getTimestamp() );
	}
}
