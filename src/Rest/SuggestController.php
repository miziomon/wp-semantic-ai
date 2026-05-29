<?php
/**
 * Controller REST per l'endpoint di suggerimento link interni.
 *
 * @package Mavida\SemanticInternalLinks\Rest
 */

declare( strict_types=1 );

namespace Mavida\SemanticInternalLinks\Rest;

use Mavida\SemanticInternalLinks\Ai\LinkSuggester;
use Mavida\SemanticInternalLinks\Content\CandidateProvider;
use Mavida\SemanticInternalLinks\Content\KeywordExtractor;

/**
 * Registra e gestisce l'endpoint REST:
 *   POST /wp-json/semantic-ai/v1/suggest
 *
 * Sicurezza:
 * - permission_callback: current_user_can('edit_post', $post_id).
 * - Nonce REST gestito automaticamente da wp.apiFetch (header X-WP-Nonce).
 * - Tutti gli input sanitizzati e validati negli args dello schema REST.
 */
class SuggestController {

	/** Namespace dell'API REST. */
	public const REST_NAMESPACE = 'semantic-ai/v1';

	/** Route dell'endpoint. */
	public const REST_ROUTE = '/suggest';

	/**
	 * Orchestratore AI per la generazione dei suggerimenti.
	 *
	 * @var LinkSuggester
	 */
	private LinkSuggester $suggester;

	/**
	 * Provider dei contenuti candidati per i link.
	 *
	 * @var CandidateProvider
	 */
	private CandidateProvider $candidate_provider;

	/**
	 * Costruttore.
	 *
	 * @param LinkSuggester     $suggester          Orchestratore AI.
	 * @param CandidateProvider $candidate_provider Provider dei candidati.
	 */
	public function __construct( LinkSuggester $suggester, CandidateProvider $candidate_provider ) {
		$this->suggester          = $suggester;
		$this->candidate_provider = $candidate_provider;
	}

	/** Registra la route REST. Chiamato su rest_api_init. */
	public function register(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			[
				'methods'             => \WP_REST_Server::CREATABLE,
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
	 * Gestisce la richiesta REST e restituisce i suggerimenti.
	 *
	 * @param \WP_REST_Request $request Richiesta REST validata.
	 * @return \WP_REST_Response|\WP_Error Risposta JSON o errore.
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id = (int) $request->get_param( 'postId' );

		// Verifica che il provider AI sia disponibile prima di fare query.
		if ( ! $this->suggester->is_available() ) {
			return new \WP_Error(
				'sai_no_provider',
				__( 'Nessun provider AI configurato. Vai in Impostazioni → Connettori.', 'semantic-ai' ),
				[ 'status' => 503 ]
			);
		}

		// Recupera i candidati per i link interni.
		$candidates = $this->candidate_provider->get_candidates( $post_id );

		if ( empty( $candidates ) ) {
			return rest_ensure_response(
				[
					'links'    => [],
					'emphasis' => [],
					'notice'   => __( 'Nessun contenuto candidato trovato sul sito.', 'semantic-ai' ),
				]
			);
		}

		// Legge e sanitizza i blocchi inviati dal client JS.
		$raw_blocks = (array) $request->get_param( 'blocks' );
		$blocks     = $this->sanitize_blocks( $raw_blocks );

		if ( empty( $blocks ) ) {
			return new \WP_Error(
				'sai_no_blocks',
				__( "Nessun blocco testuale trovato nell'articolo.", 'semantic-ai' ),
				[ 'status' => 422 ]
			);
		}

		// Genera i suggerimenti tramite il WP AI Client.
		$result = $this->suggester->suggest( $post_id, $blocks, $candidates );

		if ( is_wp_error( $result ) ) {
			$result->add_data( [ 'status' => 503 ] );
			return $result;
		}

		// Estrae il flag runtime _from_cache (non deve uscire nella risposta REST).
		$from_cache = (bool) ( $result['_from_cache'] ?? false );
		unset( $result['_from_cache'] );

		// Registra l'analisi nel log.
		$log = new \Mavida\SemanticInternalLinks\Ai\AnalysisLog();
		$log->add( $post_id, $result, count( $candidates ), $from_cache );

		return rest_ensure_response( $result );
	}

	/**
	 * Schema degli argomenti REST con sanitize e validate callback.
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
						return new \WP_Error( 'sai_invalid_post_id', __( 'postId deve essere un intero positivo.', 'semantic-ai' ) );
					}

					$post = get_post( $post_id );

					if ( null === $post ) {
						return new \WP_Error( 'sai_post_not_found', __( 'Post non trovato.', 'semantic-ai' ) );
					}

					return true;
				},
			],
			'blocks' => [
				'required'          => true,
				'type'              => 'array',
				'items'             => [
					'type'       => 'object',
					'properties' => [
						'index'    => [ 'type' => 'integer' ],
						'type'     => [ 'type' => 'string' ],
						'text'     => [ 'type' => 'string' ],
						'clientId' => [ 'type' => 'string' ],
					],
				],
				'validate_callback' => static function ( mixed $value ): bool|\WP_Error {
					if ( ! is_array( $value ) || empty( $value ) ) {
						return new \WP_Error( 'sai_no_blocks', __( "Nessun blocco fornito nell'articolo.", 'semantic-ai' ) );
					}

					return true;
				},
			],
		];
	}

	/**
	 * Sanitizza e normalizza i blocchi ricevuti dal client JS.
	 *
	 * @param array<mixed> $raw_blocks Blocchi grezzi dalla richiesta.
	 * @return array<int, array{index: int, type: string, text: string, clientId: string}> Blocchi sanitizzati.
	 */
	private function sanitize_blocks( array $raw_blocks ): array {
		$blocks = [];

		foreach ( $raw_blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$text = isset( $block['text'] ) ? sanitize_textarea_field( (string) $block['text'] ) : '';

			if ( '' === $text ) {
				continue;
			}

			$blocks[] = [
				'index'    => isset( $block['index'] ) ? absint( $block['index'] ) : count( $blocks ),
				'type'     => isset( $block['type'] ) ? sanitize_key( (string) $block['type'] ) : 'paragraph',
				'text'     => $text,
				'clientId' => isset( $block['clientId'] ) ? sanitize_text_field( (string) $block['clientId'] ) : '',
			];
		}

		return $blocks;
	}
}
