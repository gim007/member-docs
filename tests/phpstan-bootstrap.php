<?php
/**
 * Constants PHPStan needs to know about when analysing the plugin outside WordPress.
 *
 * @package MemberDocs
 */

define( 'MEMBER_DOCS_VERSION', '1.0.0' );
define( 'MEMBER_DOCS_FILE', dirname( __DIR__ ) . '/member-docs.php' );
define( 'WP_CLI', true );
