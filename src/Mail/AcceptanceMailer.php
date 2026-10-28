<?php
/**
 * Acceptance confirmation email.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Mail;

use MemberDocs\Domain\Acceptance;
use MemberDocs\Pdf\PrivateStorage;
use MemberDocs\Repository\VersionRepository;
use MemberDocs\View;

/**
 * Emails the member a PDF copy of what they accepted.
 *
 * Listens to `member_docs_accepted` instead of being called by the acceptance
 * service, so mail can be disabled, replaced or queued without touching the flow.
 */
final class AcceptanceMailer {

	/**
	 * Constructor.
	 *
	 * @param VersionRepository $versions Version repository.
	 * @param PrivateStorage    $storage  Private file storage.
	 */
	public function __construct(
		private readonly VersionRepository $versions,
		private readonly PrivateStorage $storage,
	) {}

	/**
	 * Hooks the mailer into the acceptance flow.
	 */
	public function register(): void {
		add_action( 'member_docs_accepted', array( $this, 'send' ) );
	}

	/**
	 * Sends the confirmation email.
	 *
	 * @param Acceptance $acceptance New acceptance.
	 */
	public function send( Acceptance $acceptance ): void {
		$user    = get_userdata( $acceptance->user_id );
		$version = $this->versions->find( $acceptance->version_id );

		if ( false === $user || null === $version || '' === $user->user_email ) {
			return;
		}

		/* translators: %s: document title. */
		$subject = sprintf( __( 'Your copy of "%s"', 'member-docs' ), $version->title );

		$body = sprintf(
			/* translators: 1: member name, 2: document title, 3: version number, 4: acceptance date, 5: site name. */
			__( "Hello %1\$s,\n\nyou accepted \"%2\$s\" (version %3\$d) on %4\$s.\nA PDF copy is attached for your records.\n\n%5\$s", 'member-docs' ),
			$user->display_name,
			$version->title,
			$version->version,
			View::datetime( $acceptance->accepted_at ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);

		/**
		 * Filters the subject of the acceptance email.
		 *
		 * @param string     $subject    Subject.
		 * @param Acceptance $acceptance Acceptance.
		 * @param \WP_User   $user       Member.
		 */
		$subject = (string) apply_filters( 'member_docs_mail_subject', $subject, $acceptance, $user );

		/**
		 * Filters the plain text body of the acceptance email.
		 *
		 * @param string     $body       Body.
		 * @param Acceptance $acceptance Acceptance.
		 * @param \WP_User   $user       Member.
		 */
		$body = (string) apply_filters( 'member_docs_mail_body', $body, $acceptance, $user );

		$attachments = array();
		if ( null !== $acceptance->pdf_path && $this->storage->exists( $acceptance->pdf_path ) ) {
			$attachments[] = $this->storage->path( $acceptance->pdf_path );
		}

		wp_mail( $user->user_email, $subject, $body, '', $attachments );
	}
}
