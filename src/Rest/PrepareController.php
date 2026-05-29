<?php
/**
 * Controller REST per l'endpoint di preparazione analisi.
 *
 * @package Mavida\SemanticInternalLinks\Rest
 */

declare( strict_types=1 );

namespace Mavida\SemanticInternalLinks\Rest;

use Mavida\SemanticInternalLinks\Content\CandidateProvider;

/**
 * Registra e gestisce l'endpoint REST:
 *   GET /wp-json/semantic-ai/v1/prepare?postId=X
 *
 * Restituisce il conteggio dei candidati disponibili per il post specificato,
 * permettendo al client JS di mostrare feedback di progresso prima della
 * chiamata AI (che è la fase lenta).
 *
 * Sicurezza:
 * - permission_callback: current_user_can('edit_post', $post_id).
 * - Nonce REST gestito automaticamente da wp.apiFetch (header X-WP-Nonce).
 */
class PrepareController {

	/** Namespace dell'API REST. */
	public const REST_NAMESPACE = 'semantic-ai/v1';

	/** Route dell'endpoint. */
	public const REST_ROUTE = '/prepare';

	/**
	 * Provider dei contenuti candidati per i link.
	 *
	 * @var CandidateProvider
	 */
	private CandidateProvider $candidate_provider;

	/**
	 * Costruttore.
	 *
	 * @param CandidateProvider $candidate_provider Provider dei candidati.
	 */
	public function __construct( CandidateProvider $candidate_provider ) {
		$this->candidate_provider = $candidate_provider;
	}

	/** Registra la route REST. Chiamato su rest_api_init. */
	public function register(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => $this->get_args_schema(),
			]
		);
	}

	/**
	 * Verifica che l'utente possa modificare il post richiesto.
	 *
	 * @param \WP_REST_Request $request Richiesta REST.
	 * @return bool|\WP_Error True se autorizzato, WP_Error altrimenti.
	 */
	public function check_permission( \WP_REST_Request $request ): bool|\WP_Error {
		$post_id = (int) $request->get_param( 'postId' );

		if ( $post_id <= 0 ) {
			return new \WP_Error(
				'sai_invalid_post_id',
				__( 'ID post non valido.', 'semantic-ai' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'sai_forbidden',
				__( 'Non hai i permessi per modificare questo post.', 'semantic-ai' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Recupera il conteggio dei candidati per il post specificato.
	 *
	 * @param \WP_REST_Request $request Richiesta REST validata.
	 * @return \WP_REST_Response|\WP_Error Risposta JSON o errore.
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id    = (int) $request->get_param( 'postId' );
		$candidates = $this->candidate_provider->get_candidates( $post_id );

		return rest_ensure_response(
			[ 'candidateCount' => count( $candidates ) ]
		);
	}

	/**
	 * Schema degli argomenti REST.
	 *
	 * @return array<string, mixed> Schema args.
	 */
	private function get_args_schema(): array {
		return [
			'postId' => [
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'validate_callback' => static function ( mixed $value ): bool|\WP_Error {
					$post_id = absint( $value );

					if ( $post_id <= 0 ) {
						return new \WP_Error(
							'sai_invalid_post_id',
							__( 'postId deve essere un intero positivo.', 'semantic-ai' )
						);
					}

					$post = get_post( $post_id );

					if ( null === $post ) {
						return new \WP_Error(
							'sai_post_not_found',
							__( 'Post non trovato.', 'semantic-ai' )
						);
					}

					return true;
				},
			],
		];
	}
}
