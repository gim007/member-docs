<?php
/**
 * Acceptance log list table.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Admin;

use MemberDocs\Domain\Acceptance;
use MemberDocs\Domain\TemplateVersion;
use MemberDocs\Repository\AcceptanceFilter;
use MemberDocs\Repository\AcceptanceRepository;
use MemberDocs\Repository\VersionRepository;
use MemberDocs\Rest\DownloadController;
use MemberDocs\View;
use WP_List_Table;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Paginated, filterable view of the acceptance log.
 */
final class AcceptanceLogListTable extends WP_List_Table {

	private const PER_PAGE = 20;

	/**
	 * Versions loaded for the current page, keyed by ID.
	 *
	 * @var array<int, TemplateVersion|null>
	 */
	private array $version_cache = array();

	/**
	 * Constructor.
	 *
	 * @param AcceptanceRepository $acceptances Acceptance repository.
	 * @param VersionRepository    $versions    Version repository.
	 * @param AcceptanceFilter     $filter      Active filter.
	 */
	public function __construct(
		private readonly AcceptanceRepository $acceptances,
		private readonly VersionRepository $versions,
		private readonly AcceptanceFilter $filter,
	) {
		parent::__construct(
			array(
				'singular' => 'acceptance',
				'plural'   => 'acceptances',
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
			'accepted_at' => __( 'Accepted', 'member-docs' ),
			'member'      => __( 'Member', 'member-docs' ),
			'document'    => __( 'Document', 'member-docs' ),
			'source'      => __( 'Source', 'member-docs' ),
			'ip_address'  => __( 'IP address', 'member-docs' ),
			'pdf'         => __( 'PDF', 'member-docs' ),
		);
	}

	/**
	 * Loads one page of the log.
	 */
	public function prepare_items(): void {
		$total = $this->acceptances->count( $this->filter );

		$this->_column_headers = array( $this->get_columns(), array(), array(), 'accepted_at' );
		$this->items           = $this->acceptances->search( $this->filter, self::PER_PAGE, ( $this->get_pagenum() - 1 ) * self::PER_PAGE );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/**
	 * Message when nothing matches.
	 */
	public function no_items(): void {
		esc_html_e( 'No acceptances found.', 'member-docs' );
	}

	/**
	 * Cell contents.
	 *
	 * @param Acceptance $item        Acceptance.
	 * @param string     $column_name Column.
	 */
	public function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'accepted_at':
				return esc_html( View::datetime( $item->accepted_at ) );
			case 'member':
				return $this->member( $item->user_id );
			case 'document':
				return $this->document( $item->version_id );
			case 'source':
				return esc_html( $item->source->label() ) . $this->order_link( $item->order_id );
			case 'ip_address':
				return esc_html( $item->ip_address );
			case 'pdf':
				return null === $item->pdf_path
					? '&mdash;'
					: sprintf( '<a href="%s">%s</a>', esc_url( DownloadController::url( $item->id ) ), esc_html__( 'Download', 'member-docs' ) );
			default:
				return '';
		}
	}

	/**
	 * Member name linked to their profile.
	 *
	 * @param int $user_id User ID.
	 */
	private function member( int $user_id ): string {
		$user = get_userdata( $user_id );
		if ( false === $user ) {
			/* translators: %d: user ID. */
			return esc_html( sprintf( __( 'Deleted user #%d', 'member-docs' ), $user_id ) );
		}

		return sprintf(
			'<a href="%s">%s</a><br><span class="description">%s</span>',
			esc_url( get_edit_user_link( $user_id ) ),
			esc_html( $user->display_name ),
			esc_html( $user->user_email )
		);
	}

	/**
	 * Document title and version linked to the version view.
	 *
	 * @param int $version_id Version ID.
	 */
	private function document( int $version_id ): string {
		if ( ! array_key_exists( $version_id, $this->version_cache ) ) {
			$this->version_cache[ $version_id ] = $this->versions->find( $version_id );
		}

		$version = $this->version_cache[ $version_id ];
		if ( null === $version ) {
			return '&mdash;';
		}

		return sprintf(
			'<a href="%s">%s</a>',
			esc_url(
				TemplatesPage::url(
					array(
						'action' => 'version',
						'id'     => $version->id,
					)
				)
			),
			/* translators: 1: document title, 2: version number. */
			esc_html( sprintf( __( '%1$s (v%2$d)', 'member-docs' ), $version->title, $version->version ) )
		);
	}

	/**
	 * Link to the WooCommerce order, if any.
	 *
	 * @param int|null $order_id Order ID.
	 */
	private function order_link( ?int $order_id ): string {
		if ( null === $order_id ) {
			return '';
		}

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		/* translators: %d: order ID. */
		$label = esc_html( sprintf( __( 'Order #%d', 'member-docs' ), $order_id ) );

		return '<br>' . ( $order instanceof \WC_Order ? sprintf( '<a href="%s">%s</a>', esc_url( $order->get_edit_order_url() ), $label ) : $label );
	}
}
