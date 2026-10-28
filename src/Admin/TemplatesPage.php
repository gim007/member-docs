<?php
/**
 * Template admin screens.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Admin;

use InvalidArgumentException;
use MemberDocs\Capabilities;
use MemberDocs\Domain\TemplateStatus;
use MemberDocs\Repository\AcceptanceFilter;
use MemberDocs\Repository\AcceptanceRepository;
use MemberDocs\Repository\TemplateRepository;
use MemberDocs\Repository\VersionRepository;
use MemberDocs\Service\PlaceholderRenderer;
use MemberDocs\Service\TemplateService;
use MemberDocs\View;

/**
 * Templates list, edit screen, version history and read-only version view.
 */
final class TemplatesPage {

	public const SLUG        = 'member-docs';
	public const NEW_SLUG    = 'member-docs-new';
	public const SAVE_ACTION = 'member_docs_save_template';

	private const OLD_INPUT_PREFIX = 'member_docs_old_input_';

	/**
	 * Constructor.
	 *
	 * @param TemplateService      $service     Template service.
	 * @param TemplateRepository   $templates   Template repository.
	 * @param VersionRepository    $versions    Version repository.
	 * @param AcceptanceRepository $acceptances Acceptance repository.
	 * @param Notices              $notices     Admin notices.
	 */
	public function __construct(
		private readonly TemplateService $service,
		private readonly TemplateRepository $templates,
		private readonly VersionRepository $versions,
		private readonly AcceptanceRepository $acceptances,
		private readonly Notices $notices,
	) {}

	/**
	 * URL of a screen on this page.
	 *
	 * @param array<string, string|int> $args Query arguments.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array( 'page' => self::SLUG ) + $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Routes to the requested screen.
	 */
	public function render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen routing.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		// phpcs:enable

		match ( $action ) {
			'edit'    => $this->render_edit( $id ),
			'history' => $this->render_history( $id ),
			'version' => $this->render_version( $id ),
			default   => $this->render_list(),
		};
	}

	/**
	 * "Add New" screen.
	 */
	public function render_new(): void {
		$this->render_edit( 0 );
	}

	/**
	 * Handles the edit form (admin-post.php).
	 */
	public function handle_save(): void {
		if ( ! current_user_can( Capabilities::MANAGE_TEMPLATES ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage documents.', 'member-docs' ), 403 );
		}
		check_admin_referer( self::SAVE_ACTION );

		$input = array(
			'template_id' => isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0,
			'title'       => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
			'slug'        => isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '',
			'status'      => TemplateStatus::tryFrom( isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '' ) ?? TemplateStatus::Draft,
			'body'        => isset( $_POST['body'] ) ? wp_kses_post( wp_unslash( $_POST['body'] ) ) : '',
		);

		wp_safe_redirect( $this->save( $input, get_current_user_id() ) );
		exit;
	}

	/**
	 * Saves the template and queues a notice. Separated from handle_save() for testing.
	 *
	 * @param array{template_id: int, title: string, slug: string, status: TemplateStatus, body: string} $input Sanitized form input.
	 * @param int                                                                                       $user_id Current user.
	 * @return string URL to redirect to.
	 */
	public function save( array $input, int $user_id ): string {
		try {
			$result = 0 === $input['template_id']
				? $this->service->create( $input['title'], $input['slug'], $input['status'], $input['body'], $user_id )
				: $this->service->update( $input['template_id'], $input['title'], $input['slug'], $input['status'], $input['body'], $user_id );
		} catch ( InvalidArgumentException $error ) {
			$this->notices->add( $error->getMessage(), 'error' );
			set_transient( self::OLD_INPUT_PREFIX . $user_id, array( 'status' => $input['status']->value ) + $input, MINUTE_IN_SECONDS );

			return 0 === $input['template_id']
				? admin_url( 'admin.php?page=' . self::NEW_SLUG )
				: self::url(
					array(
						'action' => 'edit',
						'id'     => $input['template_id'],
					)
				);
		}

		if ( null === $result->published ) {
			$this->notices->add( esc_html__( 'Document saved. The content is unchanged, so no new version was published.', 'member-docs' ) );
		} else {
			/* translators: %d: version number. */
			$this->notices->add( esc_html( sprintf( __( 'Version %d published.', 'member-docs' ), $result->published->version ) ) );
		}

		return self::url(
			array(
				'action' => 'edit',
				'id'     => $result->template->id,
			)
		);
	}

	/**
	 * Templates list.
	 */
	private function render_list(): void {
		$table = new TemplatesListTable( $this->templates, $this->service, $this->acceptances );
		$table->prepare_items();

		View::display( 'admin/templates', array( 'table' => $table ) );
	}

	/**
	 * Create/edit form.
	 *
	 * @param int $id Template ID, 0 for a new template.
	 */
	private function render_edit( int $id ): void {
		$template = 0 === $id ? null : $this->templates->find( $id );
		if ( 0 !== $id && null === $template ) {
			wp_die( esc_html__( 'Document not found.', 'member-docs' ), 404 );
		}

		$latest = null === $template ? null : $this->versions->latest_for( $template->id );
		$values = array(
			'title'  => $template->title ?? '',
			'slug'   => $template->slug ?? '',
			'status' => $template->status->value ?? TemplateStatus::Draft->value,
			'body'   => $latest->body ?? '',
		);

		$old_input = get_transient( self::OLD_INPUT_PREFIX . get_current_user_id() );
		if ( is_array( $old_input ) && (int) $old_input['template_id'] === $id ) {
			delete_transient( self::OLD_INPUT_PREFIX . get_current_user_id() );
			$values = array_intersect_key( $old_input, $values ) + $values;
		}

		View::display(
			'admin/template-edit',
			array(
				'template'     => $template,
				'latest'       => $latest,
				'values'       => $values,
				'statuses'     => TemplateStatus::cases(),
				'placeholders' => PlaceholderRenderer::SUPPORTED,
				'action'       => self::SAVE_ACTION,
			)
		);
	}

	/**
	 * Version history of one template.
	 *
	 * @param int $id Template ID.
	 */
	private function render_history( int $id ): void {
		$template = $this->templates->find( $id );
		if ( null === $template ) {
			wp_die( esc_html__( 'Document not found.', 'member-docs' ), 404 );
		}

		$versions = $this->versions->for_template( $template->id );
		$counts   = array();
		foreach ( $versions as $version ) {
			$counts[ $version->id ] = $this->acceptances->count( new AcceptanceFilter( version_id: $version->id ) );
		}

		View::display(
			'admin/template-history',
			array(
				'template' => $template,
				'versions' => $versions,
				'counts'   => $counts,
				'current'  => $this->service->current_version( $template ),
			)
		);
	}

	/**
	 * Read-only view of one version.
	 *
	 * @param int $id Version ID.
	 */
	private function render_version( int $id ): void {
		$version  = $this->versions->find( $id );
		$template = null === $version ? null : $this->templates->find( $version->template_id );
		if ( null === $version || null === $template ) {
			wp_die( esc_html__( 'Version not found.', 'member-docs' ), 404 );
		}

		View::display(
			'admin/version-view',
			array(
				'template' => $template,
				'version'  => $version,
				'intact'   => $version->is_intact(),
			)
		);
	}
}
