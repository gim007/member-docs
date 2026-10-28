<?php
/**
 * Parsed legacy CSV row.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Cli;

use DateTimeImmutable;

/**
 * One validated acceptance from the legacy system.
 */
final class LegacyRow {

	/**
	 * Constructor.
	 *
	 * @param int               $line          Line number in the CSV file.
	 * @param string            $email         Member email.
	 * @param string            $template_slug Template slug.
	 * @param int               $version       Version number.
	 * @param DateTimeImmutable $accepted_at   Acceptance time (UTC).
	 * @param string            $ip            IP address, empty if unknown.
	 */
	public function __construct(
		public readonly int $line,
		public readonly string $email,
		public readonly string $template_slug,
		public readonly int $version,
		public readonly DateTimeImmutable $accepted_at,
		public readonly string $ip,
	) {}

	/**
	 * Identity used to detect duplicates within one import.
	 */
	public function key(): string {
		return strtolower( $this->email ) . '|' . $this->template_slug . '|' . $this->version;
	}
}
