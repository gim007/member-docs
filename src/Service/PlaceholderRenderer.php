<?php
/**
 * Placeholder substitution.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Service;

use Closure;

/**
 * Replaces {{placeholders}} in document bodies with HTML-escaped values.
 *
 * Pure PHP without WordPress dependencies. Unknown placeholders are left in the
 * text unchanged and reported to the logger once per renderer instance.
 */
final class PlaceholderRenderer {

	public const SUPPORTED = array(
		'member.display_name',
		'member.email',
		'document.title',
		'document.version',
		'accepted_at',
		'site.name',
	);

	private const PATTERN = '/\{\{\s*([a-z0-9_]+(?:\.[a-z0-9_]+)*)\s*\}\}/i';

	/**
	 * Placeholder names already reported as unknown.
	 *
	 * @var array<string, true>
	 */
	private array $reported = array();

	/**
	 * Constructor.
	 *
	 * @param (Closure(string): void)|null $logger Receives a message for each unknown placeholder.
	 */
	public function __construct( private readonly ?Closure $logger = null ) {}

	/**
	 * Substitutes placeholders.
	 *
	 * @param string                    $text   HTML containing placeholders.
	 * @param array<string, string|int> $values Raw (unescaped) values keyed by placeholder name.
	 */
	public function render( string $text, array $values ): string {
		return (string) preg_replace_callback(
			self::PATTERN,
			function ( array $matches ) use ( $values ): string {
				$name = $matches[1];

				if ( ! array_key_exists( $name, $values ) ) {
					$this->report_unknown( $name );
					return $matches[0];
				}

				return htmlspecialchars( (string) $values[ $name ], ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8' );
			},
			$text
		);
	}

	/**
	 * Logs an unknown placeholder the first time it is seen.
	 *
	 * @param string $name Placeholder name.
	 */
	private function report_unknown( string $name ): void {
		if ( isset( $this->reported[ $name ] ) || null === $this->logger ) {
			return;
		}

		$this->reported[ $name ] = true;
		( $this->logger )( sprintf( 'Member Documents: unknown placeholder "{{%s}}" left unchanged.', $name ) );
	}
}
