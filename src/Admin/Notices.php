<?php
/**
 * Admin notices stored across redirects.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Admin;

/**
 * Queues notices in a per-user transient and shows them on the next admin page.
 *
 * Messages never travel in query arguments, so they cannot be forged via links.
 */
final class Notices {

	private const TRANSIENT_PREFIX = 'member_docs_notices_';

	/**
	 * Hooks notice output.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Queues a notice for the current user.
	 *
	 * @param string $message Message; may contain basic HTML.
	 * @param string $type    One of success, error, warning, info.
	 */
	public function add( string $message, string $type = 'success' ): void {
		$notices   = $this->pending();
		$notices[] = array(
			'message' => $message,
			'type'    => $type,
		);

		set_transient( $this->key(), $notices, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Prints and clears the queued notices.
	 */
	public function render(): void {
		$notices = $this->pending();
		if ( array() === $notices ) {
			return;
		}

		delete_transient( $this->key() );

		foreach ( $notices as $notice ) {
			wp_admin_notice(
				$notice['message'],
				array(
					'type'        => $notice['type'],
					'dismissible' => true,
				)
			);
		}
	}

	/**
	 * Notices waiting to be shown.
	 *
	 * @return list<array{message: string, type: string}>
	 */
	public function pending(): array {
		$notices = get_transient( $this->key() );

		return is_array( $notices ) ? array_values( $notices ) : array();
	}

	/**
	 * Transient key for the current user.
	 */
	private function key(): string {
		return self::TRANSIENT_PREFIX . get_current_user_id();
	}
}
