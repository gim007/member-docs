<?php
/**
 * Template editing and versioning.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Service;

use InvalidArgumentException;
use MemberDocs\Domain\ContentHash;
use MemberDocs\Domain\Template;
use MemberDocs\Domain\TemplateStatus;
use MemberDocs\Domain\TemplateVersion;
use MemberDocs\Repository\TemplateRepository;
use MemberDocs\Repository\VersionRepository;

/**
 * Saves templates and publishes a new immutable version whenever the content changes.
 */
final class TemplateService {

	/**
	 * Constructor.
	 *
	 * @param TemplateRepository $templates Template repository.
	 * @param VersionRepository  $versions  Version repository.
	 */
	public function __construct(
		private readonly TemplateRepository $templates,
		private readonly VersionRepository $versions,
	) {}

	/**
	 * Creates a template and publishes its first version.
	 *
	 * @param string         $title   Title.
	 * @param string         $slug    Slug; derived from the title when empty.
	 * @param TemplateStatus $status  Status.
	 * @param string         $body    HTML body with placeholders.
	 * @param int            $user_id Publishing user.
	 * @throws InvalidArgumentException When the input is invalid.
	 */
	public function create( string $title, string $slug, TemplateStatus $status, string $body, int $user_id ): SaveResult {
		[ $title, $slug, $body ] = $this->validate( $title, $slug, $body, 0 );

		$template = $this->templates->insert( $slug, $title, $status );
		$version  = $this->versions->insert( $template->id, $title, $body, $user_id );

		return new SaveResult( $template, $version );
	}

	/**
	 * Updates a template. Publishes version N+1 only if the title or body changed.
	 *
	 * @param int            $template_id Template ID.
	 * @param string         $title       Title.
	 * @param string         $slug        Slug; derived from the title when empty.
	 * @param TemplateStatus $status      Status.
	 * @param string         $body        HTML body with placeholders.
	 * @param int            $user_id     Publishing user.
	 * @throws InvalidArgumentException When the template does not exist or the input is invalid.
	 */
	public function update( int $template_id, string $title, string $slug, TemplateStatus $status, string $body, int $user_id ): SaveResult {
		if ( null === $this->templates->find( $template_id ) ) {
			throw new InvalidArgumentException( esc_html__( 'The template does not exist.', 'member-docs' ) );
		}

		[ $title, $slug, $body ] = $this->validate( $title, $slug, $body, $template_id );

		$template = $this->templates->update( $template_id, $slug, $title, $status );
		$latest   = $this->versions->latest_for( $template_id );

		if ( null !== $latest && hash_equals( $latest->content_hash, ContentHash::of( $title, $body ) ) ) {
			return new SaveResult( $template, null );
		}

		return new SaveResult( $template, $this->versions->insert( $template_id, $title, $body, $user_id ) );
	}

	/**
	 * The version members are currently asked to accept: the latest version of an active template.
	 *
	 * @param Template $template Template.
	 */
	public function current_version( Template $template ): ?TemplateVersion {
		return $template->is_active() ? $this->versions->latest_for( $template->id ) : null;
	}

	/**
	 * Looks up an active template by slug together with its current version.
	 *
	 * @param string $slug Template slug.
	 * @return array{0: Template, 1: TemplateVersion}|null
	 */
	public function find_current( string $slug ): ?array {
		$template = $this->templates->find_by_slug( $slug );
		$version  = null === $template ? null : $this->current_version( $template );

		return null === $template || null === $version ? null : array( $template, $version );
	}

	/**
	 * Sanitizes and validates editor input.
	 *
	 * @param string $title      Raw title.
	 * @param string $slug       Raw slug.
	 * @param string $body       Raw body.
	 * @param int    $exclude_id Template being edited, 0 for new.
	 * @return array{0: string, 1: string, 2: string} Sanitized title, slug and body.
	 * @throws InvalidArgumentException When the input is invalid.
	 */
	private function validate( string $title, string $slug, string $body, int $exclude_id ): array {
		$title = sanitize_text_field( $title );
		$slug  = sanitize_title( '' === trim( $slug ) ? $title : $slug );
		$body  = wp_kses_post( $body );

		if ( '' === $title ) {
			throw new InvalidArgumentException( esc_html__( 'The title is required.', 'member-docs' ) );
		}
		if ( '' === trim( $body ) ) {
			throw new InvalidArgumentException( esc_html__( 'The document body is required.', 'member-docs' ) );
		}
		if ( $this->templates->slug_exists( $slug, $exclude_id ) ) {
			throw new InvalidArgumentException( esc_html__( 'Another template already uses this slug.', 'member-docs' ) );
		}

		return array( $title, $slug, $body );
	}
}
