<?php
/**
 * Personalised document output.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Service;

use DateTimeImmutable;
use MemberDocs\Domain\TemplateVersion;
use MemberDocs\View;
use WP_User;

/**
 * Renders a version for a specific member, on screen and as PDF source HTML.
 */
final class DocumentRenderer {

	/**
	 * Constructor.
	 *
	 * @param PlaceholderRenderer $placeholders Placeholder renderer.
	 */
	public function __construct( private readonly PlaceholderRenderer $placeholders ) {}

	/**
	 * The version body with placeholders filled in for the member.
	 *
	 * @param TemplateVersion        $version     Version.
	 * @param WP_User                $user        Member.
	 * @param DateTimeImmutable|null $accepted_at Acceptance time, if accepted.
	 */
	public function body( TemplateVersion $version, WP_User $user, ?DateTimeImmutable $accepted_at = null ): string {
		$values = array(
			'member.display_name' => $user->display_name,
			'member.email'        => $user->user_email,
			'document.title'      => $version->title,
			'document.version'    => $version->version,
			'accepted_at'         => null === $accepted_at ? '' : View::datetime( $accepted_at ),
			'site.name'           => get_bloginfo( 'name' ),
		);

		/**
		 * Filters the placeholder values used to render a document. Values are escaped after this filter.
		 *
		 * @param array<string, string|int> $values      Values keyed by placeholder name.
		 * @param TemplateVersion           $version     Version being rendered.
		 * @param WP_User                   $user        Member the document is rendered for.
		 * @param DateTimeImmutable|null    $accepted_at Acceptance time, if accepted.
		 */
		$values = (array) apply_filters( 'member_docs_placeholders', $values, $version, $user, $accepted_at );

		return $this->placeholders->render( $version->body, $values );
	}

	/**
	 * Complete HTML document that is converted into the acceptance PDF.
	 *
	 * @param TemplateVersion   $version     Accepted version.
	 * @param WP_User           $user        Member.
	 * @param DateTimeImmutable $accepted_at Acceptance time.
	 */
	public function pdf_html( TemplateVersion $version, WP_User $user, DateTimeImmutable $accepted_at ): string {
		$html = View::render(
			'pdf/document',
			array(
				'title'        => $version->title,
				'version'      => $version->version,
				'body'         => $this->body( $version, $user, $accepted_at ),
				'member'       => $user->display_name,
				'email'        => $user->user_email,
				'accepted_at'  => View::datetime( $accepted_at ),
				'content_hash' => $version->content_hash,
				'site'         => get_bloginfo( 'name' ),
			)
		);

		/**
		 * Filters the HTML that is converted into the acceptance PDF.
		 *
		 * @param string            $html        Complete HTML document.
		 * @param TemplateVersion   $version     Accepted version.
		 * @param WP_User           $user        Member.
		 * @param DateTimeImmutable $accepted_at Acceptance time.
		 */
		return (string) apply_filters( 'member_docs_pdf_html', $html, $version, $user, $accepted_at );
	}
}
