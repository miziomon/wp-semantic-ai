<?php
/**
 * Valida e normalizza la risposta JSON del WP AI Client.
 *
 * @package Mavida\SemanticInternalLinks\Ai
 */

declare( strict_types=1 );

namespace Mavida\SemanticInternalLinks\Ai;

use Mavida\SemanticInternalLinks\Plugin;

/**
 * Valida la struttura JSON restituita dall'AI e la normalizza.
 *
 * Sicurezza: scarta item con targetId non presenti tra i candidati
 * (prevenendo link a URL arbitrari), blockIndex fuori range, o
 * anchorText/phrase vuoti. Risolve targetId → {url, title} dai candidati.
 */
class ResponseValidator {

	/**
	 * Valida e normalizza la risposta dell'AI.
	 *
	 * @param array<string, mixed>                                                                     $raw_response    Risposta decodificata da json_decode().
	 * @param array<int, array{id: int, title: string, url: string, excerpt: string, terms: string[]}> $candidates      Lista candidati originale.
	 * @param int                                                                                      $block_count     Numero di blocchi inviati (per validare blockIndex).
	 * @return array{links: list<array<string, mixed>>, emphasis: list<array<string, mixed>>} Risposta validata.
	 */
	public function validate(
		array $raw_response,
		array $candidates,
		int $block_count
	): array {
		// Mappa id → candidato per O(1) lookup.
		$candidates_by_id = [];

		foreach ( $candidates as $candidate ) {
			$candidates_by_id[ $candidate['id'] ] = $candidate;
		}

		$max_links    = (int) Plugin::get_option( 'max_links' );
		$max_emphasis = (int) Plugin::get_option( 'max_emphasis' );

		$links = $this->validate_links(
			$raw_response['links'] ?? [],
			$candidates_by_id,
			$block_count,
			$max_links
		);

		$emphasis = $this->validate_emphasis(
			$raw_response['emphasis'] ?? [],
			$block_count,
			$max_emphasis
		);

		return [
			'links'    => $links,
			'emphasis' => $emphasis,
		];
	}

	/**
	 * Valida la lista di suggerimenti link.
	 *
	 * @param mixed                                                                                    $raw_links        Valore grezzo dall'AI.
	 * @param array<int, array{id: int, title: string, url: string, excerpt: string, terms: string[]}> $candidates_by_id Candidati indicizzati per ID.
	 * @param int                                                                                      $block_count      Numero blocchi validi.
	 * @param int                                                                                      $max              Numero massimo di link.
	 * @return list<array<string, mixed>> Link validati con url/title risolti.
	 */
	private function validate_links(
		mixed $raw_links,
		array $candidates_by_id,
		int $block_count,
		int $max
	): array {
		if ( ! is_array( $raw_links ) ) {
			return [];
		}

		$valid        = [];
		$used_targets = [];

		foreach ( $raw_links as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$block_index = isset( $item['blockIndex'] ) ? (int) $item['blockIndex'] : -1;
			$anchor_text = isset( $item['anchorText'] ) ? trim( (string) $item['anchorText'] ) : '';
			$occurrence  = isset( $item['occurrence'] ) ? (int) $item['occurrence'] : 1;
			$target_id   = isset( $item['targetId'] ) ? (int) $item['targetId'] : 0;

			// Scarta se blockIndex fuori range.
			if ( $block_index < 0 || $block_index >= $block_count ) {
				continue;
			}

			// Scarta se anchorText vuoto.
			if ( '' === $anchor_text ) {
				continue;
			}

			// Scarta se occurrence non valida.
			if ( $occurrence < 1 ) {
				continue;
			}

			// Scarta se targetId non è tra i candidati (sicurezza: no URL arbitrari).
			if ( ! isset( $candidates_by_id[ $target_id ] ) ) {
				continue;
			}

			// Evita link duplicati allo stesso targetId.
			if ( in_array( $target_id, $used_targets, true ) ) {
				continue;
			}

			$candidate      = $candidates_by_id[ $target_id ];
			$used_targets[] = $target_id;

			$validated = [
				'blockIndex' => $block_index,
				'anchorText' => $anchor_text,
				'occurrence' => $occurrence,
				'targetId'   => $target_id,
				'url'        => $candidate['url'],
				'title'      => $candidate['title'],
				'rationale'  => isset( $item['rationale'] ) ? sanitize_text_field( (string) $item['rationale'] ) : '',
			];

			/**
			 * Filtra un singolo suggerimento link prima dell'approvazione finale.
			 *
			 * @param array<string, mixed>|false $validated Suggerimento validato, o false per scartarlo.
			 * @param array<string, mixed>        $raw_item  Item grezzo dell'AI.
			 */
			$validated = apply_filters( 'sai_suggestion_validate_link', $validated, $item );

			if ( ! is_array( $validated ) ) {
				continue;
			}

			$valid[] = $validated;

			if ( count( $valid ) >= $max ) {
				break;
			}
		}

		return $valid;
	}

	/**
	 * Valida la lista di suggerimenti enfasi.
	 *
	 * @param mixed $raw_emphasis Valore grezzo dall'AI.
	 * @param int   $block_count  Numero blocchi validi.
	 * @param int   $max          Numero massimo di enfasi.
	 * @return list<array<string, mixed>> Enfasi validate.
	 */
	private function validate_emphasis( mixed $raw_emphasis, int $block_count, int $max ): array {
		if ( ! is_array( $raw_emphasis ) ) {
			return [];
		}

		$valid           = [];
		$allowed_formats = [ 'bold', 'italic' ];

		foreach ( $raw_emphasis as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$block_index = isset( $item['blockIndex'] ) ? (int) $item['blockIndex'] : -1;
			$phrase      = isset( $item['phrase'] ) ? trim( (string) $item['phrase'] ) : '';
			$occurrence  = isset( $item['occurrence'] ) ? (int) $item['occurrence'] : 1;
			$format      = isset( $item['format'] ) ? (string) $item['format'] : '';

			if ( $block_index < 0 || $block_index >= $block_count ) {
				continue;
			}

			if ( '' === $phrase ) {
				continue;
			}

			if ( $occurrence < 1 ) {
				continue;
			}

			if ( ! in_array( $format, $allowed_formats, true ) ) {
				continue;
			}

			$validated = [
				'blockIndex' => $block_index,
				'phrase'     => $phrase,
				'occurrence' => $occurrence,
				'format'     => $format,
				'rationale'  => isset( $item['rationale'] ) ? sanitize_text_field( (string) $item['rationale'] ) : '',
			];

			/**
			 * Filtra un singolo suggerimento enfasi prima dell'approvazione finale.
			 *
			 * @param array<string, mixed>|false $validated Suggerimento validato, o false per scartarlo.
			 * @param array<string, mixed>        $raw_item  Item grezzo dell'AI.
			 */
			$validated = apply_filters( 'sai_suggestion_validate_emphasis', $validated, $item );

			if ( ! is_array( $validated ) ) {
				continue;
			}

			$valid[] = $validated;

			if ( count( $valid ) >= $max ) {
				break;
			}
		}

		return $valid;
	}
}
