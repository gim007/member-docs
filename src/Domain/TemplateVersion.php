<?php
/**
 * Template version value object.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Domain;

use DateTimeImmutable;

/**
 * An immutable, published revision of a template. Never updated or deleted.
 */
final class TemplateVersion {

	/**
	 * Constructor.
	 *
	 * @param int               $id           Version ID.
	 * @param int               $template_id  Template ID.
	 * @param int               $version      Sequential number per template, starting at 1.
	 * @param string            $title        Title at publication time.
	 * @param string            $body         HTML body with placeholders.
	 * @param string            $content_hash SHA-256 of the normalized title and body.
	 * @param DateTimeImmutable $published_at Publication time (UTC).
	 * @param int               $published_by ID of the publishing user, 0 for system.
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $template_id,
		public readonly int $version,
		public readonly string $title,
		public readonly string $body,
		public readonly string $content_hash,
		public readonly DateTimeImmutable $published_at,
		public readonly int $published_by,
	) {}

	/**
	 * Recomputes the hash from the stored content and compares it to the stored hash.
	 */
	public function is_intact(): bool {
		return hash_equals( $this->content_hash, ContentHash::of( $this->title, $this->body ) );
	}
}
