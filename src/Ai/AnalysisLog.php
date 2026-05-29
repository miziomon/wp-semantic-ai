<?php
/**
 * Gestisce il log delle analisi AI eseguite sul sito.
 *
 * @package Mavida\SemanticInternalLinks\Ai
 */

declare( strict_types=1 );

namespace Mavida\SemanticInternalLinks\Ai;

/**
 * Archivia e recupera il log delle analisi AI tramite WordPress Options API.
 *
 * Strategia di storage a due livelli:
 * - sai_analysis_log: indice compatto (max MAX_ENTRIES voci, autoload off)
 * - sai_log_result_{id}: risultato completo per voce (autoload off)
 *
 * Quando si raggiunge MAX_ENTRIES, la voce più vecchia viene rimossa
 * insieme al suo risultato completo.
 */
class AnalysisLog {

	/** Chiave dell'indice principale. */
	private const LOG_OPTION = 'sai_analysis_log';

	/** Prefisso per le opzioni dei risultati completi. */
	private const RESULT_PREFIX = 'sai_log_result_';

	/** Numero massimo di voci nel log. */
	private const MAX_ENTRIES = 50;

	/**
	 * Aggiunge una voce al log.
	 *
	 * @param int                  $post_id         ID del post analizzato.
	 * @param array<string, mixed> $result          Risultato dell'analisi ({links, emphasis}).
	 * @param int                  $candidate_count Numero di candidati usati.
	 * @param bool                 $from_cache      True se il risultato proviene dalla cache.
	 */
	public function add( int $post_id, array $result, int $candidate_count, bool $from_cache ): void {
		$id         = uniqid( 'sai_', true );
		$post_title = (string) get_the_title( $post_id );
		$timestamp  = current_time( 'Y-m-d H:i:s' );

		$raw_links    = $result['links'] ?? [];
		$raw_emphasis = $result['emphasis'] ?? [];

		$entry = [
			'id'              => $id,
			'post_id'         => $post_id,
			'post_title'      => $post_title,
			'timestamp'       => $timestamp,
			'links_count'     => is_array( $raw_links ) ? count( $raw_links ) : 0,
			'emphasis_count'  => is_array( $raw_emphasis ) ? count( $raw_emphasis ) : 0,
			'candidate_count' => $candidate_count,
			'from_cache'      => $from_cache,
		];

		$raw_entries = get_option( self::LOG_OPTION, [] );
		$entries     = is_array( $raw_entries ) ? $raw_entries : [];

		// Prepend nuova voce.
		array_unshift( $entries, $entry );

		// Rimuovi le voci eccedenti (con i loro risultati completi).
		if ( count( $entries ) > self::MAX_ENTRIES ) {
			$removed = array_splice( $entries, self::MAX_ENTRIES );
			foreach ( $removed as $old_entry ) {
				if ( isset( $old_entry['id'] ) ) {
					delete_option( self::RESULT_PREFIX . (string) $old_entry['id'] );
				}
			}
		}

		update_option( self::LOG_OPTION, $entries, false );
		update_option( self::RESULT_PREFIX . $id, $result, false );
	}

	/**
	 * Restituisce le voci dell'indice.
	 *
	 * @return array<int, array<string, mixed>> Voci del log, dalla più recente.
	 */
	public function get_entries(): array {
		$raw = get_option( self::LOG_OPTION, [] );
		return is_array( $raw ) ? $raw : [];
	}

	/**
	 * Restituisce il risultato completo di una voce.
	 *
	 * @param string $id ID della voce.
	 * @return array<string, mixed>|null Risultato o null se non trovato.
	 */
	public function get_result( string $id ): ?array {
		if ( '' === $id ) {
			return null;
		}

		$raw = get_option( self::RESULT_PREFIX . $id, null );
		return is_array( $raw ) ? $raw : null;
	}

	/**
	 * Elimina tutte le voci del log e i relativi risultati completi.
	 */
	public function clear(): void {
		$raw_entries = get_option( self::LOG_OPTION, [] );
		$entries     = is_array( $raw_entries ) ? $raw_entries : [];

		foreach ( $entries as $entry ) {
			if ( isset( $entry['id'] ) ) {
				delete_option( self::RESULT_PREFIX . (string) $entry['id'] );
			}
		}

		delete_option( self::LOG_OPTION );
	}
}
