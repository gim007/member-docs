<?php
/**
 * Template value object.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Domain;

use DateTimeImmutable;

/**
 * A document type such as "Membership Terms". Its content lives in versions.
 */
final class Template {

	/**
	 * Constructor.
	 *
	 * @param int               $id         Template ID.
	 * @param string            $slug       Unique slug.
	 * @param string            $title      Title.
	 * @param TemplateStatus    $status     Status.
	 * @param DateTimeImmutable $created_at Creation time (UTC).
	 * @param DateTimeImmutable $updated_at Last update time (UTC).
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $slug,
		public readonly string $title,
		public readonly TemplateStatus $status,
		public readonly DateTimeImmutable $created_at,
		public readonly DateTimeImmutable $updated_at,
	) {}

	/**
	 * Whether members can accept this template.
	 */
	public function is_active(): bool {
		return TemplateStatus::Active === $this->status;
	}
}
