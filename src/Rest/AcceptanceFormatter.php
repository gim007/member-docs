<?php
/**
 * Acceptance REST representation.
 *
 * @package MemberDocs
 */

declare( strict_types=1 );

namespace MemberDocs\Rest;

use MemberDocs\Domain\Acceptance;
use MemberDocs\Repository\TemplateRepository;
use MemberDocs\Repository\VersionRepository;
use MemberDocs\View;

/**
 * Turns acceptance records into REST response data.
 */
final class AcceptanceFormatter {

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
	 * Response data for one acceptance.
	 *
	 * @param Acceptance $acceptance Acceptance.
	 * @param bool       $audit      Include audit fields (user, IP, user agent, hash) for privileged users.
	 * @return array<string, mixed>
	 */
	public function format( Acceptance $acceptance, bool $audit = false ): array {
		$version  = $this->versions->find( $acceptance->version_id );
		$template = null === $version ? null : $this->templates->find( $version->template_id );

		$data = array(
			'id'                  => $acceptance->id,
			'template'            => null === $template ? null : array(
				'slug'  => $template->slug,
				'title' => $template->title,
			),
			'version'             => null === $version ? null : array(
				'id'     => $version->id,
				'number' => $version->version,
				'title'  => $version->title,
			),
			'accepted_at'         => $acceptance->accepted_at->format( DATE_ATOM ),
			'accepted_at_display' => View::datetime( $acceptance->accepted_at ),
			'source'              => $acceptance->source->value,
			'order_id'            => $acceptance->order_id,
			'download_url'        => null === $acceptance->pdf_path ? null : DownloadController::url( $acceptance->id ),
		);

		if ( $audit ) {
			$data['user_id']      = $acceptance->user_id;
			$data['ip_address']   = $acceptance->ip_address;
			$data['user_agent']   = $acceptance->user_agent;
			$data['content_hash'] = $acceptance->content_hash;
		}

		return $data;
	}
}
