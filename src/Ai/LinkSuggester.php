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
	 * Gestisce automaticamente il chunking quando il testo supera la soglia configurata.
	 *
	 * @param int                                                                                      $post_id    ID del post da analizzare.
	 * @param array<int, array{index: int, type: string, text: string}>                                $blocks     Blocchi testuali.
	 * @param array<int, array{id: int, title: string, url: string, excerpt: string, terms: string[]}> $candidates Candidati link.
	 * @return array<string, mixed>|\WP_Error Suggerimenti validati ({links, emphasis}) oppure WP_Error.
	 */
	public function suggest( int $post_id, array $blocks, array $candidates ): array|\WP_Error {
		if ( ! $this->is_available() ) {
			return new \WP_Error(
				'sai_no_provider',
				__( 'Nessun provider AI configurato. Vai in Impostazioni → Connettori.', 'semantic-ai' )
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

		$threshold = (int) Plugin::get_option( 'chunk_threshold_chars' );

		// Chunking: se il contenuto totale supera la soglia, analizza a blocchi.
		if ( $threshold > 0 && strlen( $post_content ) > $threshold ) {
			$result = $this->suggest_chunked( $blocks, $candidates, $locale, $threshold );
		} else {
			$result = $this->call_ai( $blocks, $candidates, $locale, count( $blocks ) );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Salva in cache solo se la risposta è valida.
		$this->cache->set( $cache_key, $result );

		return $result;
	}

	/**
	 * Divide i blocchi in chunk e aggrega i risultati con deduplicazione.
	 *
	 * @param array<int, array{index: int, type: string, text: string}>                                $blocks     Blocchi testuali.
	 * @param array<int, array{id: int, title: string, url: string, excerpt: string, terms: string[]}> $candidates Candidati link.
	 * @param string                                                                                   $locale     Locale WP.
	 * @param int                                                                                      $threshold  Soglia caratteri per chunk.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function suggest_chunked( array $blocks, array $candidates, string $locale, int $threshold ): array|\WP_Error {
		$chunks       = $this->split_blocks_into_chunks( $blocks, $threshold );
		$total_blocks = count( $blocks );
		$all_links    = [];
		$all_emphasis = [];

		foreach ( $chunks as $chunk ) {
			$chunk_result = $this->call_ai( $chunk, $candidates, $locale, $total_blocks );

			if ( is_wp_error( $chunk_result ) ) {
				return $chunk_result;
			}

			$raw_links    = $chunk_result['links'] ?? [];
			$raw_emphasis = $chunk_result['emphasis'] ?? [];

			if ( is_array( $raw_links ) ) {
				$all_links = array_merge( $all_links, $raw_links );
			}

			if ( is_array( $raw_emphasis ) ) {
				$all_emphasis = array_merge( $all_emphasis, $raw_emphasis );
			}
		}

		return [
			'links'    => $this->deduplicate_links( $all_links ),
			'emphasis' => $this->deduplicate_emphasis( $all_emphasis ),
		];
	}

	/**
	 * Divide un array di blocchi in chunk in modo che ogni chunk non superi la soglia di caratteri.
	 *
	 * @param array<int, array{index: int, type: string, text: string}> $blocks    Tutti i blocchi.
	 * @param int                                                       $threshold Soglia caratteri.
	 * @return array<int, array<int, array{index: int, type: string, text: string}>>
	 */
	private function split_blocks_into_chunks( array $blocks, int $threshold ): array {
		$chunks      = [];
		$current     = [];
		$current_len = 0;

		foreach ( $blocks as $block ) {
			$block_len = strlen( $block['text'] );

			if ( $current_len + $block_len > $threshold && ! empty( $current ) ) {
				$chunks[]    = $current;
				$current     = [];
				$current_len = 0;
			}

			$current[]    = $block;
			$current_len += $block_len;
		}

		if ( ! empty( $current ) ) {
			$chunks[] = $current;
		}

		return $chunks;
	}

	/**
	 * Esegue una singola chiamata AI e restituisce il risultato validato.
	 *
	 * @param array<int, array{index: int, type: string, text: string}>                                $blocks       Blocchi da inviare.
	 * @param array<int, array{id: int, title: string, url: string, excerpt: string, terms: string[]}> $candidates   Candidati link.
	 * @param string                                                                                   $locale       Locale WP.
	 * @param int                                                                                      $total_blocks Totale blocchi del post (per validare blockIndex).
	 * @return array<string, mixed>|\WP_Error
	 */
	private function call_ai( array $blocks, array $candidates, string $locale, int $total_blocks ): array|\WP_Error {
		$language_hint      = $this->detect_language_hint( $locale );
		$system_instruction = $this->prompt_builder->build_system_instruction( $language_hint );
		$user_payload       = $this->prompt_builder->build_user_payload( $blocks, $candidates );
		$json_schema        = $this->prompt_builder->get_json_schema();

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

		$raw_response = json_decode( $raw_json, true );

		if ( ! is_array( $raw_response ) ) {
			return new \WP_Error(
				'sai_invalid_response',
				__( 'Risposta AI non valida (JSON non decodificabile).', 'semantic-ai' )
			);
		}

		return $this->validator->validate( $raw_response, $candidates, $total_blocks );
	}

	/**
	 * Deduplica i link per targetId: mantiene il primo occorrente.
	 *
	 * @param array<int, mixed> $links Lista link da deduplicare.
	 * @return array<int, mixed>
	 */
	private function deduplicate_links( array $links ): array {
		$seen   = [];
		$result = [];

		foreach ( $links as $link ) {
			if ( ! is_array( $link ) ) {
				continue;
			}

			$target_id = (int) ( $link['targetId'] ?? 0 );

			if ( $target_id > 0 && ! isset( $seen[ $target_id ] ) ) {
				$seen[ $target_id ] = true;
				$result[]           = $link;
			}
		}

		return $result;
	}

	/**
	 * Deduplica le enfasi per frase: mantiene la prima occorrente.
	 *
	 * @param array<int, mixed> $emphasis Lista enfasi da deduplicare.
	 * @return array<int, mixed>
	 */
	private function deduplicate_emphasis( array $emphasis ): array {
		$seen   = [];
		$result = [];

		foreach ( $emphasis as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$phrase = (string) ( $item['phrase'] ?? '' );
			$format = (string) ( $item['format'] ?? '' );

			if ( '' === $phrase || '' === $format ) {
				continue;
			}

			$key = $phrase . '|' . $format;

			if ( ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = true;
				$result[]     = $item;
			}
		}

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
