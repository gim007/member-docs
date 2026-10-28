<?php
/**
 * Acceptance flow.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Service;

use DateTimeImmutable;
use MemberDocs\Domain\Acceptance;
use MemberDocs\Domain\AcceptanceSource;
use MemberDocs\Domain\TemplateVersion;
use MemberDocs\Pdf\PdfRenderer;
use MemberDocs\Pdf\PrivateStorage;
use MemberDocs\Repository\AcceptanceRepository;
use MemberDocs\Repository\Timestamps;
use MemberDocs\Repository\VersionRepository;
use MemberDocs\Repository\TemplateRepository;
use RuntimeException;
use Throwable;
use WP_User;

/**
 * Records that a member accepted a template version, with a PDF copy.
 */
final class AcceptanceService {

	/**
	 * Constructor.
	 *
	 * @param TemplateRepository   $templates   Template repository.
	 * @param VersionRepository    $versions    Version repository.
	 * @param AcceptanceRepository $acceptances Acceptance repository.
	 * @param DocumentRenderer     $documents   Document renderer.
	 * @param PdfRenderer          $pdf         PDF renderer.
	 * @param PrivateStorage       $storage     Private file storage.
	 */
	public function __construct(
		private readonly TemplateRepository $templates,
		private readonly VersionRepository $versions,
		private readonly AcceptanceRepository $acceptances,
		private readonly DocumentRenderer $documents,
		private readonly PdfRenderer $pdf,
		private readonly PrivateStorage $storage,
	) {}

	/**
	 * Accepts the current version of an active template on behalf of a member.
	 *
	 * Idempotent: accepting the same version again returns the existing record.
	 * Fires `member_docs_accepted` for new acceptances only.
	 *
	 * @param TemplateVersion  $version  Version the member saw.
	 * @param WP_User          $user     Member.
	 * @param AcceptanceSource $source   Where the acceptance happens.
	 * @param ClientInfo       $client   Client details.
	 * @param int|null         $order_id WooCommerce order ID for checkout acceptances.
	 * @throws StaleVersionException When the version is not current or the template is inactive.
	 * @throws RuntimeException When the PDF or the database record cannot be written.
	 * @throws \Exception When the PDF engine fails.
	 */
	public function accept( TemplateVersion $version, WP_User $user, AcceptanceSource $source, ClientInfo $client, ?int $order_id = null ): Acceptance {
		$this->assert_current( $version );

		$existing = $this->acceptances->find_for( $version->id, $user->ID );
		if ( null !== $existing ) {
			return $existing;
		}

		$acceptance = $this->record( $version, $user, Timestamps::now(), $source, $client, $order_id, true );

		/**
		 * Fires after a member accepted a document version.
		 *
		 * @param Acceptance $acceptance The new acceptance record.
		 */
		do_action( 'member_docs_accepted', $acceptance );

		return $acceptance;
	}

	/**
	 * Records a historical acceptance, e.g. from a legacy system.
	 *
	 * Does not check whether the version is current and does not fire
	 * `member_docs_accepted`, so imported members receive no emails.
	 *
	 * @param TemplateVersion   $version      Accepted version.
	 * @param WP_User           $user         Member.
	 * @param DateTimeImmutable $accepted_at  Original acceptance time.
	 * @param ClientInfo        $client       Client details known from the legacy system.
	 * @param bool              $generate_pdf Whether to create a PDF copy now.
	 * @throws RuntimeException When the PDF or the database record cannot be written.
	 */
	public function import( TemplateVersion $version, WP_User $user, DateTimeImmutable $accepted_at, ClientInfo $client, bool $generate_pdf ): Acceptance {
		return $this->acceptances->find_for( $version->id, $user->ID )
			?? $this->record( $version, $user, $accepted_at, AcceptanceSource::Migration, $client, null, $generate_pdf );
	}

	/**
	 * Generates the PDF and stores the record. Removes the PDF again if the insert fails.
	 *
	 * @param TemplateVersion   $version      Accepted version.
	 * @param WP_User           $user         Member.
	 * @param DateTimeImmutable $accepted_at  Acceptance time.
	 * @param AcceptanceSource  $source       Source.
	 * @param ClientInfo        $client       Client details.
	 * @param int|null          $order_id     Order ID.
	 * @param bool              $generate_pdf Whether to create a PDF copy.
	 * @throws RuntimeException When the PDF or the database record cannot be written.
	 */
	private function record(
		TemplateVersion $version,
		WP_User $user,
		DateTimeImmutable $accepted_at,
		AcceptanceSource $source,
		ClientInfo $client,
		?int $order_id,
		bool $generate_pdf
	): Acceptance {
		$pdf_path = null;
		$pdf_hash = null;

		if ( $generate_pdf ) {
			$pdf      = $this->pdf->render( $this->documents->pdf_html( $version, $user, $accepted_at ) );
			$pdf_path = $this->storage->store( $pdf, $accepted_at );
			$pdf_hash = hash( 'sha256', $pdf );
		}

		try {
			return $this->acceptances->insert(
				new Acceptance(
					0,
					$version->id,
					$user->ID,
					$accepted_at,
					$client->ip_address,
					$client->user_agent,
					$version->content_hash,
					$pdf_path,
					$pdf_hash,
					$source,
					$order_id
				)
			);
		} catch ( Throwable $error ) {
			if ( null !== $pdf_path ) {
				$this->storage->delete( $pdf_path );
			}

			// A concurrent request for the same member and version may have won the unique key.
			$winner = $this->acceptances->find_for( $version->id, $user->ID );
			if ( null !== $winner ) {
				return $winner;
			}

			throw $error;
		}
	}

	/**
	 * Ensures the version is the current version of an active template.
	 *
	 * @param TemplateVersion $version Version.
	 * @throws StaleVersionException When it is not.
	 */
	private function assert_current( TemplateVersion $version ): void {
		$template = $this->templates->find( $version->template_id );
		$latest   = $this->versions->latest_for( $version->template_id );

		if ( null === $template || ! $template->is_active() || null === $latest || $latest->id !== $version->id ) {
			throw new StaleVersionException( esc_html__( 'This version of the document is no longer current. Please review the latest version.', 'member-docs' ) );
		}
	}
}
