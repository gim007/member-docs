<?php
/**
 * Acceptance log filter.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Repository;

use DateTimeImmutable;

/**
 * Criteria for searching the acceptance log. Null means "any".
 */
final class AcceptanceFilter {

	/**
	 * Constructor.
	 *
	 * @param int|null               $template_id Template ID.
	 * @param int|null               $version_id  Version ID.
	 * @param int|null               $user_id     User ID.
	 * @param DateTimeImmutable|null $from        Accepted at or after (UTC).
	 * @param DateTimeImmutable|null $to          Accepted at or before (UTC).
	 */
	public function __construct(
		public readonly ?int $template_id = null,
		public readonly ?int $version_id = null,
		public readonly ?int $user_id = null,
		public readonly ?DateTimeImmutable $from = null,
		public readonly ?DateTimeImmutable $to = null,
	) {}
}
