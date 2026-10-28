<?php
/**
 * Templates list table.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Admin;

use MemberDocs\Domain\Template;
use MemberDocs\Repository\AcceptanceRepository;
use MemberDocs\Repository\TemplateRepository;
use MemberDocs\Service\TemplateService;
use WP_List_Table;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists templates with their current version and acceptance count.
 */
final class TemplatesListTable extends WP_List_Table {

	/**
	 * Constructor.
	 *
	 * @param TemplateRepository   $templates   Template repository.
	 * @param TemplateService      $service     Template service.
	 * @param AcceptanceRepository $acceptances Acceptance repository.
	 */
	public function __construct(
		private readonly TemplateRepository $templates,
		private readonly TemplateService $service,
		private readonly AcceptanceRepository $acceptances,
	) {
		parent::__construct(
			array(
				'singular' => 'template',
				'plural'   => 'templates',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Column headers.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'title'           => __( 'Title', 'member-docs' ),
			'slug'            => __( 'Slug', 'member-docs' ),
			'status'          => __( 'Status', 'member-docs' ),
			'current_version' => __( 'Current version', 'member-docs' ),
			'acceptances'     => __( 'Acceptances', 'member-docs' ),
		);
	}

	/**
	 * Loads all templates. The number of document types is small, so no pagination.
	 */
	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'title' );
		$this->items           = $this->templates->all();
	}

	/**
	 * Message when there are no templates.
	 */
	public function no_items(): void {
		esc_html_e( 'No documents yet.', 'member-docs' );
	}

	/**
	 * Title column with row actions.
	 *
	 * @param Template $item Template.
	 */
	public function column_title( $item ): string {
		$edit_url = TemplatesPage::url(
			array(
				'action' => 'edit',
				'id'     => $item->id,
			)
		);

		$actions = array(
			'edit'        => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'member-docs' ) ),
			'history'     => sprintf(
				'<a href="%s">%s</a>',
				esc_url(
					TemplatesPage::url(
						array(
							'action' => 'history',
							'id'     => $item->id,
						)
					)
				),
				esc_html__( 'Version history', 'member-docs' )
			),
			'acceptances' => sprintf( '<a href="%s">%s</a>', esc_url( AcceptanceLogPage::url( array( 'template_id' => $item->id ) ) ), esc_html__( 'Acceptances', 'member-docs' ) ),
		);

		return sprintf( '<strong><a class="row-title" href="%s">%s</a></strong>%s', esc_url( $edit_url ), esc_html( $item->title ), $this->row_actions( $actions ) );
	}

	/**
	 * Remaining columns.
	 *
	 * @param Template $item        Template.
	 * @param string   $column_name Column.
	 */
	public function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'slug':
				return '<code>' . esc_html( $item->slug ) . '</code>';
			case 'status':
				return esc_html( $item->status->label() );
			case 'current_version':
				$version = $this->service->current_version( $item );
				return null === $version ? '&mdash;' : esc_html( (string) $version->version );
			case 'acceptances':
				return esc_html( number_format_i18n( $this->acceptances->count_for_template( $item->id ) ) );
			default:
				return '';
		}
	}
}
