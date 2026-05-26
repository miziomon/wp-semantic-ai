<?php
/**
 * Orchestratore principale: chiama il WP AI Client e gestisce la cache.
 *
 * @package Mavida\SemanticInternalLinks\Ai
 */

declare( strict_types=1 );

namespace Mavida\SemanticInternalLinks\Ai;

use Mavida\SemanticInternalLinks\Plugin;

/**
 * Coordina PromptBuilder, SuggestionCache e ResponseValidator per produrre
 * i suggerimenti di link interni e di enfasi semantica.
 *
 * Utilizza esclusivamente il WP AI Client nativo (wp_ai_client_prompt()) e
 * non gestisce direttamente chiavi API (delegate ai Connectors WP).
 */
class LinkSuggester {

	/**
	 * Modelli AI preferiti, in ordine di priorità.
	 * Il WP AI Client usa il primo supportato dal provider configurato.
	 *
	 * @var string[]
	 */
	private const MODEL_PREFERENCES = [
		'claude-sonnet-4-6',
		'gemini-3.1-pro-preview',
		'gpt-5.4',
	];

	/**
	 * Costruttore prompt AI.
	 *
	 * @var PromptBuilder
	 */
	private PromptBuilder $prompt_builder;

	/**
	 * Cache transient per le risposte AI.
	 *
	 * @var SuggestionCache
	 */
	private SuggestionCache $cache;

	/**
	 * Validatore della risposta JSON dell'AI.
	 *
	 * @var ResponseValidator
	 */
	private ResponseValidator $validator;

	/**
	 * Costruttore.
	 *
	 * @param PromptBuilder     $prompt_builder Costruttore prompt.
	 * @param SuggestionCache   $cache          Cache transient.
	 * @param ResponseValidator $validator      Validatore risposta.
	 */
	public function __construct(
		PromptBuilder $prompt_builder,
		SuggestionCache $cache,
		ResponseValidator $validator
	) {
		$this->prompt_builder = $prompt_builder;
		$this->cache          = $cache;
		$this->validator      = $validator;
	}

	/**
	 * Verifica se il WP AI Client è disponibile e configurato.
	 * Non fa chiamate di rete — è deterministica e gratuita.
	 *
	 * @return bool True se il provider è configurato e la feature è supportata.
	 */
	public function is_available(): bool {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return false;
		}

		$builder = wp_ai_client_prompt( 'test' );

		if ( is_wp_error( $builder ) ) {
			return false;
		}

		return $builder->is_supported_for_text_generation();
	}

	/**
	 * Genera i suggerimenti di link interni e enfasi semantica.
	 *
	 * @param int                                                                                      $post_id    ID del post da analizzare.
	 * @param array<int, array{index: int, type: string, text: string}>                                $blocks     Blocchi testuali.
	 * @param array<int, array{id: int, title: string, url: string, excerpt: string, terms: string[]}> $candidates Candidati link.
	 * @return array<string, mixed>|\WP_Error Suggerimenti validati ({links, emphasis}) oppure WP_Error.
	 */
	public function suggest( int $post_id, array $blocks, array $candidates ): array|\WP_Error {
		if ( ! $this->is_available() ) {
			return new \WP_Error(
				'sil_no_provider',
				__( 'Nessun provider AI configurato. Vai in Impostazioni → Connettori.', 'semantic-internal-links' )
			);
		}

		// Estrai plain text per la chiave di cache (non inviare HTML all'AI).
		$post_content  = implode( ' ', array_column( $blocks, 'text' ) );
		$candidate_ids = array_column( $candidates, 'id' );
		$locale        = get_locale();

		$cache_key = $this->cache->build_key( $post_id, $post_content, $candidate_ids, $locale );
		$cached    = $this->cache->get( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		// Determina la lingua del contenuto per la system instruction.
		$language_hint = $this->detect_language_hint( $locale );

		// Costruisci system instruction e payload.
		$system_instruction = $this->prompt_builder->build_system_instruction( $language_hint );
		$user_payload       = $this->prompt_builder->build_user_payload( $blocks, $candidates );
		$json_schema        = $this->prompt_builder->get_json_schema();

		// Chiama il WP AI Client.
		$builder = wp_ai_client_prompt( $user_payload );

		if ( is_wp_error( $builder ) ) {
			return $builder;
		}

		$builder
			->using_system_instruction( $system_instruction )
			->using_temperature( 0.2 )
			->using_max_tokens( 4000 )
			->using_model_preference( ...self::MODEL_PREFERENCES )
			->as_json_response( $json_schema );

		$raw_json = $builder->generate_text();

		if ( is_wp_error( $raw_json ) ) {
			return $raw_json;
		}

		// Decodifica e valida la risposta JSON.
		$raw_response = json_decode( $raw_json, true );

		if ( ! is_array( $raw_response ) ) {
			return new \WP_Error(
				'sil_invalid_response',
				__( 'Risposta AI non valida (JSON non decodificabile).', 'semantic-internal-links' )
			);
		}

		$result = $this->validator->validate( $raw_response, $candidates, count( $blocks ) );

		// Salva in cache solo se la risposta è valida.
		$this->cache->set( $cache_key, $result );

		return $result;
	}

	/**
	 * Rileva un hint sulla lingua dall'locale WordPress.
	 *
	 * @param string $locale Locale WP (es. it_IT, en_US).
	 * @return string Hint lingua leggibile (es. "italiano", "english").
	 */
	private function detect_language_hint( string $locale ): string {
		$lang_map = [
			'it' => 'italiano',
			'en' => 'english',
			'fr' => 'français',
			'de' => 'deutsch',
			'es' => 'español',
			'pt' => 'português',
		];

		$lang_code = substr( $locale, 0, 2 );

		return $lang_map[ $lang_code ] ?? 'italiano';
	}
}
