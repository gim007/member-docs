<?php
/**
 * Acceptance email tests.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Tests\Integration;

use MemberDocs\Domain\Acceptance;

/**
 * @covers \MemberDocs\Mail\AcceptanceMailer
 */
final class AcceptanceMailerTest extends TestCase {

	/**
	 * Captured wp_mail() arguments.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $mails = array();

	public function set_up(): void {
		parent::set_up();

		add_filter(
			'pre_wp_mail',
			function ( $result, array $atts ) {
				$this->mails[] = $atts;
				return true;
			},
			10,
			2
		);
	}

	public function test_email_with_pdf_attachment_is_sent_on_acceptance(): void {
		$user       = $this->member();
		$acceptance = $this->accept( $this->create_template()->published, $user );

		$this->assertCount( 1, $this->mails );
		$this->assertSame( $user->user_email, $this->mails[0]['to'] );
		$this->assertStringContainsString( 'Membership Terms', $this->mails[0]['subject'] );
		$this->assertSame( array( $this->plugin()->storage->path( (string) $acceptance->pdf_path ) ), $this->mails[0]['attachments'] );
	}

	public function test_no_email_is_sent_when_acceptance_already_existed(): void {
		$version = $this->create_template()->published;
		$user    = $this->member();

		$this->accept( $version, $user );
		$this->accept( $version, $user );

		$this->assertCount( 1, $this->mails );
	}

	public function test_subject_and_body_are_filterable(): void {
		add_filter( 'member_docs_mail_subject', static fn( string $subject, Acceptance $acceptance ): string => "Custom #{$acceptance->id}", 10, 2 );
		add_filter( 'member_docs_mail_body', static fn(): string => 'Custom body' );

		$acceptance = $this->accept( $this->create_template()->published, $this->member() );

		$this->assertSame( "Custom #{$acceptance->id}", $this->mails[0]['subject'] );
		$this->assertSame( 'Custom body', $this->mails[0]['message'] );
	}
}
