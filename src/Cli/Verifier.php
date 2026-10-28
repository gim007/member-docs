<?php
/**
 * Audit integrity verification.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Cli;

use MemberDocs\Domain\Acceptance;
use MemberDocs\Domain\AcceptanceSource;
use MemberDocs\Domain\TemplateVersion;
use MemberDocs\Pdf\PrivateStorage;
use MemberDocs\Repository\AcceptanceFilter;
use MemberDocs\Repository\AcceptanceRepository;
use MemberDocs\Repository\VersionRepository;

/**
 * Recomputes hashes and checks stored PDFs for every acceptance.
 */
final class Verifier {

	/**
	 * Versions checked so far, keyed by ID.
	 *
	 * @var array<int, TemplateVersion|null>
	 */
	private array $version_cache = array();

	/**
	 * Constructor.
	 *
	 * @param AcceptanceRepository $acceptances Acceptance repository.
	 * @param VersionRepository    $versions    Version repository.
	 * @param PrivateStorage       $storage     Private file storage.
	 */
	public function __construct(
		private readonly AcceptanceRepository $acceptances,
		private readonly VersionRepository $versions,
		private readonly PrivateStorage $storage,
	) {}

	/**
	 * Checks all acceptances.
	 *
	 * @param (callable(): void)|null $tick Called once per acceptance.
	 * @return list<array{acceptance_id: int, problem: string}>
	 */
	public function run( ?callable $tick = null ): array {
		$problems = array();

		foreach ( $this->acceptances->each( new AcceptanceFilter() ) as $acceptance ) {
			foreach ( $this->check( $acceptance ) as $problem ) {
				$problems[] = array(
					'acceptance_id' => $acceptance->id,
					'problem'       => $problem,
				);
			}

			if ( null !== $tick ) {
				$tick();
			}
		}

		return $problems;
	}

	/**
	 * Problems with one acceptance.
	 *
	 * @param Acceptance $acceptance Acceptance.
	 * @return list<string>
	 */
	private function check( Acceptance $acceptance ): array {
		$problems = array();

		if ( ! array_key_exists( $acceptance->version_id, $this->version_cache ) ) {
			$this->version_cache[ $acceptance->version_id ] = $this->versions->find( $acceptance->version_id );
		}
		$version = $this->version_cache[ $acceptance->version_id ];

		if ( null === $version ) {
			$problems[] = sprintf( 'Version #%d does not exist.', $acceptance->version_id );
		} else {
			if ( ! $version->is_intact() ) {
				$problems[] = sprintf( 'Version #%d content does not match its stored hash.', $version->id );
			}
			if ( ! hash_equals( $version->content_hash, $acceptance->content_hash ) ) {
				$problems[] = 'Acceptance hash does not match the accepted version.';
			}
		}

		if ( null === $acceptance->pdf_path ) {
			if ( AcceptanceSource::Migration !== $acceptance->source ) {
				$problems[] = 'No PDF was recorded.';
			}
			return $problems;
		}

		$hash = $this->storage->hash( $acceptance->pdf_path );
		if ( null === $hash ) {
			$problems[] = sprintf( 'PDF %s is missing.', $acceptance->pdf_path );
		} elseif ( null === $acceptance->pdf_hash || ! hash_equals( $acceptance->pdf_hash, $hash ) ) {
			$problems[] = sprintf( 'PDF %s has been modified.', $acceptance->pdf_path );
		}

		return $problems;
	}
}
