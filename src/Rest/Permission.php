<?php
/**
 * Shared REST permission checks.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Rest;

use WP_Error;

/**
 * Permission callbacks used by several routes.
 */
final class Permission {

	public const NAMESPACE = 'member-docs/v1';

	/**
	 * Requires an authenticated user.
	 *
	 * @return true|WP_Error
	 */
	public static function logged_in(): bool|WP_Error {
		if ( is_user_logged_in() ) {
			return true;
		}

		return new WP_Error( 'rest_not_logged_in', __( 'You must be logged in.', 'member-docs' ), array( 'status' => 401 ) );
	}

	/**
	 * Requires a capability.
	 *
	 * @param string $capability Capability name.
	 * @return true|WP_Error
	 */
	public static function can( string $capability ): bool|WP_Error {
		if ( current_user_can( $capability ) ) {
			return true;
		}

		return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'member-docs' ), array( 'status' => rest_authorization_required_code() ) );
	}
}
