<?php
/**
 * Integrity verification tests.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Tests\Integration;

use DateTimeImmutable;
use MemberDocs\Cli\Verifier;
use MemberDocs\Service\ClientInfo;

/**
 * @covers \MemberDocs\Cli\Verifier
 */
final class VerifierTest extends TestCase {

	private function verifier(): Verifier {
		return new Verifier( $this->plugin()->acceptances, $this->plugin()->versions, $this->plugin()->storage );
	}

	public function test_intact_log_has_no_problems(): void {
		$version = $this->create_template()->published;
		$this->accept( $version, $this->member() );
		$this->plugin()->acceptance_service->import( $version, $this->member(), new DateTimeImmutable( '2020-01-01' ), new ClientInfo(), false );

		$this->assertSame( array(), $this->verifier()->run() );
	}

	public function test_detects_a_missing_pdf(): void {
		$acceptance = $this->accept( $this->create_template()->published, $this->member() );
		unlink( $this->plugin()->storage->path( (string) $acceptance->pdf_path ) );

		$this->assertSame(
			array(
				array(
					'acceptance_id' => $acceptance->id,
					'problem'       => "PDF {$acceptance->pdf_path} is missing.",
				),
			),
			$this->verifier()->run()
		);
	}

	public function test_detects_a_tampered_pdf(): void {
		$acceptance = $this->accept( $this->create_template()->published, $this->member() );
		file_put_contents( $this->plugin()->storage->path( (string) $acceptance->pdf_path ), '%PDF-1.7 forged', FILE_APPEND );

		$problems = $this->verifier()->run();

		$this->assertSame( array( "PDF {$acceptance->pdf_path} has been modified." ), array_column( $problems, 'problem' ) );
	}

	public function test_detects_a_version_changed_in_the_database(): void {
		global $wpdb;

		$version = $this->create_template()->published;
		$this->accept( $version, $this->member() );
		$wpdb->update( $wpdb->prefix . 'md_template_versions', array( 'body' => '<p>Rewritten</p>' ), array( 'id' => $version->id ) );

		$this->assertSame( array( "Version #{$version->id} content does not match its stored hash." ), array_column( $this->verifier()->run(), 'problem' ) );
	}

	public function test_detects_an_acceptance_hash_mismatch(): void {
		global $wpdb;

		$acceptance = $this->accept( $this->create_template()->published, $this->member() );
		$wpdb->update( $wpdb->prefix . 'md_acceptances', array( 'content_hash' => str_repeat( '0', 64 ) ), array( 'id' => $acceptance->id ) );

		$this->assertSame( array( 'Acceptance hash does not match the accepted version.' ), array_column( $this->verifier()->run(), 'problem' ) );
	}
}
