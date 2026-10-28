<?php
/**
 * Uninstall routine.
 *
 * Acceptance records are audit data, so nothing is removed unless the site owner
 * explicitly opted in via the `member_docs_delete_data_on_uninstall` option.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/vendor/autoload.php';

if ( ! get_option( MemberDocs\Installer::DELETE_DATA_OPTION, false ) ) {
	return;
}

MemberDocs\Installer::remove_all_data();
