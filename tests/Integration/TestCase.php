<?php
/**
 * Shared helpers for integration tests.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Tests\Integration;

use MemberDocs\Domain\Acceptance;
use MemberDocs\Domain\AcceptanceSource;
use MemberDocs\Domain\TemplateStatus;
use MemberDocs\Domain\TemplateVersion;
use MemberDocs\Plugin;
use MemberDocs\Service\ClientInfo;
use MemberDocs\Service\SaveResult;
use WP_UnitTestCase;
use WP_User;

/**
 * Base class giving tests access to the wired plugin services.
 */
abstract class TestCase extends WP_UnitTestCase {

	public function tear_down(): void {
		$this->plugin()->storage->delete_all();
		parent::tear_down();
	}

	protected function plugin(): Plugin {
		return member_docs();
	}

	protected function create_template(
		string $slug = 'membership-terms',
		string $body = '<p>Hello {{member.display_name}}.</p>',
		TemplateStatus $status = TemplateStatus::Active
	): SaveResult {
		return $this->plugin()->template_service->create( ucwords( str_replace( '-', ' ', $slug ) ), $slug, $status, $body, 1 );
	}

	protected function member( string $role = 'subscriber' ): WP_User {
		return self::factory()->user->create_and_get(
			array(
				'role'         => $role,
				'display_name' => 'Jane Member',
			)
		);
	}

	protected function accept( TemplateVersion $version, WP_User $user ): Acceptance {
		return $this->plugin()->acceptance_service->accept( $version, $user, AcceptanceSource::Web, new ClientInfo( '203.0.113.7', 'Test Browser' ) );
	}

	/**
	 * Files currently in private storage, relative to its root.
	 *
	 * @return list<string>
	 */
	protected function stored_pdfs(): array {
		$base  = $this->plugin()->storage->base_dir();
		$files = glob( $base . '/*/*/*.pdf' );

		return array_map( static fn( string $file ): string => substr( $file, strlen( $base ) + 1 ), false === $files ? array() : $files );
	}
}
