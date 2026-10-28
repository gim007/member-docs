<?php
/**
 * Admin menu.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Admin;

use MemberDocs\Capabilities;

/**
 * Registers the "Member Documents" menu and the admin-post handlers.
 */
final class Menu {

	/**
	 * Constructor.
	 *
	 * @param TemplatesPage     $templates Templates screens.
	 * @param AcceptanceLogPage $log       Acceptance log screen.
	 * @param Notices           $notices   Admin notices.
	 */
	public function __construct(
		private readonly TemplatesPage $templates,
		private readonly AcceptanceLogPage $log,
		private readonly Notices $notices,
	) {}

	/**
	 * Hooks into the admin.
	 */
	public function register(): void {
		$this->notices->register();

		add_action( 'admin_menu', array( $this, 'add_pages' ) );
		add_action( 'admin_post_' . TemplatesPage::SAVE_ACTION, array( $this->templates, 'handle_save' ) );
		add_action( 'admin_post_' . AcceptanceLogPage::EXPORT_ACTION, array( $this->log, 'handle_export' ) );
	}

	/**
	 * Adds the menu pages.
	 */
	public function add_pages(): void {
		add_menu_page(
			__( 'Member Documents', 'member-docs' ),
			__( 'Member Documents', 'member-docs' ),
			Capabilities::MANAGE_TEMPLATES,
			TemplatesPage::SLUG,
			array( $this->templates, 'render' ),
			'dashicons-media-document',
			58
		);

		add_submenu_page(
			TemplatesPage::SLUG,
			__( 'Documents', 'member-docs' ),
			__( 'All Documents', 'member-docs' ),
			Capabilities::MANAGE_TEMPLATES,
			TemplatesPage::SLUG,
			array( $this->templates, 'render' )
		);

		add_submenu_page(
			TemplatesPage::SLUG,
			__( 'Add Document', 'member-docs' ),
			__( 'Add New', 'member-docs' ),
			Capabilities::MANAGE_TEMPLATES,
			TemplatesPage::NEW_SLUG,
			array( $this->templates, 'render_new' )
		);

		add_submenu_page(
			TemplatesPage::SLUG,
			__( 'Acceptance Log', 'member-docs' ),
			__( 'Acceptance Log', 'member-docs' ),
			Capabilities::VIEW_ACCEPTANCES,
			AcceptanceLogPage::SLUG,
			array( $this->log, 'render' )
		);
	}
}
