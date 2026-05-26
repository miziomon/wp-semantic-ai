<?php
/**
 * Costruisce system instruction e payload utente per il WP AI Client.
 *
 * @package Mavida\SemanticInternalLinks\Ai
 */

declare( strict_types=1 );

namespace Mavida\SemanticInternalLinks\Ai;

use Mavida\SemanticInternalLinks\Plugin;

/**
 * Responsabile di comporre il testo del prompt (system instruction + payload)
 * da inviare a wp_ai_client_prompt().
 *
 * La system instruction incorpora le regole SEO anti-over-linking definite
 * nel PROMPT §7, adattando la lingua all'articolo in analisi.
 */
class PromptBuilder {

	/**
	 * Genera la system instruction per l'AI.
	 * La lingua dei suggerimenti deve seguire quella dell'articolo (tipicamente italiano).
	 *
	 * @param string $language_hint Hint sulla lingua del contenuto (es. "italiano", "english").
	 * @return string System instruction completa.
	 */
	public function build_system_instruction( string $language_hint = 'italiano' ): string {
		$max_links    = (int) Plugin::get_option( 'max_links' );
		$max_emphasis = (int) Plugin::get_option( 'max_emphasis' );

		/**
		 * Permette di personalizzare la system instruction tramite filtro.
		 *
		 * @param string $instruction Instruction di default.
		 * @param string $language_hint Hint sulla lingua del contenuto.
		 */
		$instruction = apply_filters(
			'sil_system_instruction',
			$this->default_instruction( $language_hint, $max_links, $max_emphasis ),
			$language_hint
		);

		/* @var string $instruction */
		return $instruction;
	}

	/**
	 * Costruisce il testo del prompt utente con i blocchi e i candidati.
	 *
	 * @param array<int, array{index: int, type: string, text: string}>               $blocks     Blocchi testuali del post.
	 * @param array<int, array{id: int, title: string, url: string, excerpt: string}> $candidates Candidati link.
	 * @return string Payload JSON-encoded da passare a wp_ai_client_prompt().
	 */
	public function build_user_payload(
		array $blocks,
		array $candidates
	): string {
		$payload = [
			'blocks'     => $blocks,
			'candidates' => $candidates,
		];

		$json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );

		return ( false !== $json ) ? $json : '{}';
	}

	/**
	 * Restituisce lo schema JSON da passare a as_json_response().
	 * Corrisponde al §6 del PROMPT (contratto dati AI).
	 *
	 * @return array<string, mixed> Schema JSON come array PHP.
	 */
	public function get_json_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'links'    => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'blockIndex' => [ 'type' => 'integer' ],
							'anchorText' => [ 'type' => 'string' ],
							'occurrence' => [
								'type'    => 'integer',
								'minimum' => 1,
							],
							'targetId'   => [ 'type' => 'integer' ],
							'rationale'  => [ 'type' => 'string' ],
						],
						'required'   => [ 'blockIndex', 'anchorText', 'occurrence', 'targetId' ],
					],
				],
				'emphasis' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'blockIndex' => [ 'type' => 'integer' ],
							'phrase'     => [ 'type' => 'string' ],
							'occurrence' => [
								'type'    => 'integer',
								'minimum' => 1,
							],
							'format'     => [
								'type' => 'string',
								'enum' => [ 'bold', 'italic' ],
							],
							'rationale'  => [ 'type' => 'string' ],
						],
						'required'   => [ 'blockIndex', 'phrase', 'occurrence', 'format' ],
					],
				],
			],
			'required'   => [ 'links', 'emphasis' ],
		];
	}

	/**
	 * Genera la system instruction di default.
	 *
	 * @param string $language_hint  Lingua del contenuto.
	 * @param int    $max_links      Max link suggeribili.
	 * @param int    $max_emphasis   Max enfasi suggeribili.
	 * @return string System instruction.
	 */
	private function default_instruction( string $language_hint, int $max_links, int $max_emphasis ): string {
		return "Sei un esperto SEO specializzato in internal linking e leggibilità dei contenuti web.
Analizzi l'articolo fornito e suggerisci link interni e enfasi semantica per migliorare la SEO on-page.

LINGUA: Tutti i suggerimenti devono essere nella stessa lingua dell'articolo ({$language_hint}).

REGOLE PER I LINK INTERNI:
- Puoi linkare SOLO ai targetId presenti nella lista candidati fornita.
- Le ancore devono essere TESTO GIÀ PRESENTE nel blocco indicato (verbatim).
- Le ancore devono essere descrittive e ricche di keyword; mai usare testi generici come 'clicca qui', 'leggi di più', 'questo articolo'.
- Massimo 1 link ogni 100-150 parole nell'articolo.
- Non linkare più volte allo stesso targetId.
- Non linkare la stessa ancora più volte.
- Proponi al massimo {$max_links} link in totale.

REGOLE PER L'ENFASI:
- Usa grassetto (bold) o corsivo (italic) solo su keyword o frasi davvero portanti per il tema.
- Non enfatizzare frasi già all'interno di un link.
- Usa l'enfasi con parsimonia: massimo {$max_emphasis} elementi in totale.
- Non sovrapporre bold e italic sulla stessa frase.

OUTPUT:
- Restituisci SOLO JSON conforme allo schema, senza testo extra, senza markdown, senza spiegazioni.
- Il campo 'rationale' è opzionale ma consigliato: spiega brevemente il valore SEO del suggerimento.
- 'occurrence' indica quale occorrenza (1 = prima) dell'ancora/frase nel blocco linkare/enfatizzare.";
	}
}
