<?php
/**
 * Acceptance value object.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Domain;

use DateTimeImmutable;

/**
 * Append-only record that a user accepted a specific template version.
 */
final class Acceptance {

	/**
	 * Constructor.
	 *
	 * @param int               $id           Acceptance ID, 0 when not yet stored.
	 * @param int               $version_id   Accepted version.
	 * @param int               $user_id      Accepting user.
	 * @param DateTimeImmutable $accepted_at  Acceptance time (UTC).
	 * @param string            $ip_address   Client IP address, empty if unknown.
	 * @param string            $user_agent   Client user agent, at most 255 characters.
	 * @param string            $content_hash Hash of the version at acceptance time.
	 * @param string|null       $pdf_path     PDF path relative to private storage.
	 * @param string|null       $pdf_hash     SHA-256 of the PDF file.
	 * @param AcceptanceSource  $source       Where the acceptance happened.
	 * @param int|null          $order_id     WooCommerce order ID for checkout acceptances.
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $version_id,
		public readonly int $user_id,
		public readonly DateTimeImmutable $accepted_at,
		public readonly string $ip_address,
		public readonly string $user_agent,
		public readonly string $content_hash,
		public readonly ?string $pdf_path,
		public readonly ?string $pdf_hash,
		public readonly AcceptanceSource $source,
		public readonly ?int $order_id,
	) {}

	/**
	 * Copy of this record with the ID assigned by the database.
	 *
	 * @param int $id Acceptance ID.
	 */
	public function with_id( int $id ): self {
		return new self(
			$id,
			$this->version_id,
			$this->user_id,
			$this->accepted_at,
			$this->ip_address,
			$this->user_agent,
			$this->content_hash,
			$this->pdf_path,
			$this->pdf_hash,
			$this->source,
			$this->order_id
		);
	}
}
