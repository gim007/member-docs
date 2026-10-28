<?php
/**
 * Document rendering tests.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Tests\Integration;

use DateTimeImmutable;

/**
 * @covers \MemberDocs\Service\DocumentRenderer
 */
final class DocumentRendererTest extends TestCase {

	public function test_body_fills_member_and_document_placeholders(): void {
		$version = $this->create_template( 'terms', '<p>{{member.display_name}} / {{member.email}} / {{document.title}} v{{document.version}} / {{site.name}}</p>' )->published;
		$user    = self::factory()->user->create_and_get( array( 'user_email' => 'jo@example.com' ) );

		// Set in memory only: wp_insert_user() would strip the tags before they reach the renderer.
		$user->display_name = 'Jo <b>Bold</b>';

		$body = $this->plugin()->documents->body( $version, $user );

		$this->assertSame( '<p>Jo &lt;b&gt;Bold&lt;/b&gt; / jo@example.com / Terms v1 / ' . esc_html( get_bloginfo( 'name' ) ) . '</p>', $body );
	}

	public function test_placeholders_are_filterable(): void {
		add_filter(
			'member_docs_placeholders',
			static function ( array $values ): array {
				$values['member.number'] = 'M-42';
				return $values;
			}
		);
		$version = $this->create_template( 'terms', '<p>No. {{member.number}}</p>' )->published;

		$this->assertSame( '<p>No. M-42</p>', $this->plugin()->documents->body( $version, $this->member() ) );
	}

	public function test_pdf_html_contains_acceptance_record_and_is_filterable(): void {
		$version = $this->create_template()->published;
		$html    = $this->plugin()->documents->pdf_html( $version, $this->member(), new DateTimeImmutable() );

		$this->assertStringContainsString( 'Hello Jane Member.', $html );
		$this->assertStringContainsString( $version->content_hash, $html );

		add_filter( 'member_docs_pdf_html', static fn(): string => '<p>Replaced</p>' );
		$this->assertSame( '<p>Replaced</p>', $this->plugin()->documents->pdf_html( $version, $this->member(), new DateTimeImmutable() ) );
	}
}
