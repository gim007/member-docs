<?php
/**
 * Demo data.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Cli;

use MemberDocs\Domain\AcceptanceSource;
use MemberDocs\Domain\TemplateStatus;
use MemberDocs\Domain\TemplateVersion;
use MemberDocs\Repository\TemplateRepository;
use MemberDocs\Repository\VersionRepository;
use MemberDocs\Service\AcceptanceService;
use MemberDocs\Service\ClientInfo;
use MemberDocs\Service\TemplateService;
use WP_User;

/**
 * Creates demo documents, members, pages and acceptances for local review.
 *
 * Safe to run repeatedly: existing documents, users and pages are reused. No
 * emails are sent while seeding.
 */
final class Seeder {

	public const MEMBER_COUNT = 12;

	private const PAGE_META = '_member_docs_seed_document';

	/**
	 * Constructor.
	 *
	 * @param TemplateService    $service     Template service.
	 * @param TemplateRepository $templates   Template repository.
	 * @param VersionRepository  $versions    Version repository.
	 * @param AcceptanceService  $acceptances Acceptance service.
	 */
	public function __construct(
		private readonly TemplateService $service,
		private readonly TemplateRepository $templates,
		private readonly VersionRepository $versions,
		private readonly AcceptanceService $acceptances,
	) {}

	/**
	 * Email address of demo member N (1-based), as used in sample-data/legacy-acceptances.csv.
	 *
	 * @param int $number Member number.
	 */
	public static function member_email( int $number ): string {
		return sprintf( 'member%02d@example.com', $number );
	}

	/**
	 * Seeds the data.
	 *
	 * @return array{documents: int, members: int, acceptances: int, pages: list<string>}
	 */
	public function run(): array {
		$terms   = $this->document(
			'membership-terms',
			'Membership Terms',
			array(
				'<h2>1. Membership</h2><p>Welcome, {{member.display_name}}. These terms apply to your membership of {{site.name}}.</p><h2>2. Fees</h2><p>Membership fees are due annually.</p>',
				'<h2>1. Membership</h2><p>Welcome, {{member.display_name}}. These terms apply to your membership of {{site.name}}.</p><h2>2. Fees</h2><p>Membership fees are due annually, within 30 days of the invoice date.</p><h2>3. Contact</h2><p>We will contact you at {{member.email}}.</p>',
			)
		);
		$privacy = $this->document(
			'privacy-policy',
			'Privacy Policy',
			array( '<p>{{site.name}} stores your name and email address ({{member.email}}) to manage your membership.</p><p>Document: {{document.title}}, version {{document.version}}. Accepted: {{accepted_at}}.</p>' )
		);
		$this->document( 'code-of-conduct', 'Code of Conduct', array( '<p>Be kind. Draft, not yet published to members.</p>' ), TemplateStatus::Draft );

		$members = array();
		for ( $number = 1; $number <= self::MEMBER_COUNT; $number++ ) {
			$members[ $number ] = $this->member( $number );
		}

		add_filter( 'pre_wp_mail', '__return_false' );
		$accepted = 0;
		foreach ( $members as $number => $member ) {
			if ( $number <= 4 ) {
				$this->acceptances->accept( $terms, $member, AcceptanceSource::Web, new ClientInfo( '192.0.2.' . $number, 'Seed data' ) );
				++$accepted;
			}
			if ( $number <= 8 ) {
				$this->acceptances->accept( $privacy, $member, AcceptanceSource::Web, new ClientInfo( '192.0.2.' . $number, 'Seed data' ) );
				++$accepted;
			}
		}
		remove_filter( 'pre_wp_mail', '__return_false' );

		return array(
			'documents'   => 3,
			'members'     => count( $members ),
			'acceptances' => $accepted,
			'pages'       => array(
				$this->page( 'membership-terms', 'Membership Terms' ),
				$this->page( 'privacy-policy', 'Privacy Policy' ),
			),
		);
	}

	/**
	 * Creates a document with the given version bodies unless it exists, and returns its latest version.
	 *
	 * @param string         $slug   Slug.
	 * @param string         $title  Title.
	 * @param list<string>   $bodies One body per version, oldest first.
	 * @param TemplateStatus $status Status.
	 */
	private function document( string $slug, string $title, array $bodies, TemplateStatus $status = TemplateStatus::Active ): TemplateVersion {
		$existing = $this->templates->find_by_slug( $slug );
		$latest   = null === $existing ? null : $this->versions->latest_for( $existing->id );
		if ( null !== $latest ) {
			return $latest;
		}

		$result = $this->service->create( $title, $slug, $status, (string) array_shift( $bodies ), 0 );
		$latest = $result->published;

		foreach ( $bodies as $body ) {
			$latest = $this->service->update( $result->template->id, $title, $slug, $status, $body, 0 )->published ?? $latest;
		}

		return $latest ?? $this->versions->latest_for( $result->template->id );
	}

	/**
	 * Gets or creates demo member N.
	 *
	 * @param int $number Member number.
	 */
	private function member( int $number ): WP_User {
		$email = self::member_email( $number );
		$user  = get_user_by( 'email', $email );
		if ( false !== $user ) {
			return $user;
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => sprintf( 'member%02d', $number ),
				'user_email'   => $email,
				'user_pass'    => wp_generate_password(),
				'display_name' => sprintf( 'Demo Member %02d', $number ),
				'role'         => 'subscriber',
			)
		);

		return new WP_User( is_int( $user_id ) ? $user_id : 0 );
	}

	/**
	 * Gets or creates a page that shows a document through the shortcode.
	 *
	 * Pages are found again by a meta key, because core may already own the slug
	 * (e.g. its draft "Privacy Policy" page).
	 *
	 * @param string $slug  Document slug.
	 * @param string $title Page title.
	 * @return string Page URL.
	 */
	private function page( string $slug, string $title ): string {
		$existing = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::PAGE_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-off lookup in a CLI command.
				'meta_value'     => $slug, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- See above.
			)
		);

		$page_id = $existing[0] ?? wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => '<!-- wp:shortcode -->[member_document slug="' . $slug . '"]<!-- /wp:shortcode -->',
				'meta_input'   => array( self::PAGE_META => $slug ),
			)
		);

		return (string) get_permalink( $page_id );
	}
}
