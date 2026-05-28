<?php
/**
 * Wrapper per la cache transient delle risposte AI.
 *
 * @package Mavida\SemanticInternalLinks\Ai
 */

declare( strict_types=1 );

namespace Mavida\SemanticInternalLinks\Ai;

use Mavida\SemanticInternalLinks\Plugin;

/**
 * Gestisce la cache delle risposte AI tramite WordPress Transient API.
 *
 * La chiave di cache include: contenuto del post, ID candidati, versione dello
 * schema e locale — così da invalidarsi automaticamente al cambio di qualsiasi
 * di questi elementi, senza bisogno di svuotare la cache manualmente.
 *
 * Invalidazione esplicita: alla chiamata di save_post sul post analizzato
 * (vedi Plugin::boot() → hook sai_invalidate_cache_on_save).
 */
class SuggestionCache {

	/**
	 * Versione dello schema dati: incrementarla forza il rinnovo della cache
	 * quando cambia la struttura del contratto AI (§6 del PROMPT).
	 */
	private const SCHEMA_VERSION = '1';

	/** Prefisso del transient per evitare collisioni con altri plugin. */
	private const TRANSIENT_PREFIX = 'sai_sugg_';

	/**
	 * Recupera una risposta dalla cache.
	 *
	 * La struttura interna è garantita da ResponseValidator al momento della
	 * scrittura. La cache non rivalidala: è responsabilità del chiamante.
	 *
	 * @param string $cache_key Chiave calcolata da build_key().
	 * @return array<string, mixed>|null Dati cachati, oppure null se assente/scaduto.
	 */
	public function get( string $cache_key ): ?array {
		$data = get_transient( $cache_key );

		if ( false === $data || ! is_array( $data ) ) {
			return null;
		}

		return $data;
	}

	/**
	 * Salva una risposta nella cache.
	 *
	 * @param string               $cache_key Chiave calcolata da build_key().
	 * @param array<string, mixed> $data    Dati da salvare.
	 */
	public function set( string $cache_key, array $data ): void {
		$ttl = (int) Plugin::get_option( 'cache_ttl' );
		set_transient( $cache_key, $data, $ttl );
	}

	/**
	 * Invalida la cache per un dato post.
	 * Chiamato su save_post: rimuove tutti i transient legati al post_id.
	 *
	 * Nota: siccome la chiave include anche i candidati (che cambiano nel tempo),
	 * non possiamo derivare la chiave esatta. Salviamo quindi un indice dei
	 * transient per post_id in un'opzione dedicata.
	 *
	 * @param int $post_id ID del post.
	 */
	public function invalidate_for_post( int $post_id ): void {
		$index_key = 'sai_cache_index_' . $post_id;

		$raw_keys = get_option( $index_key, [] );
		$keys     = is_array( $raw_keys ) ? $raw_keys : [];

		foreach ( $keys as $key ) {
			delete_transient( (string) $key );
		}

		delete_option( $index_key );
	}

	/**
	 * Costruisce la chiave del transient.
	 *
	 * @param int    $post_id      ID del post analizzato.
	 * @param string $post_content Contenuto plain text del post.
	 * @param int[]  $candidate_ids ID dei candidati inviati all'AI.
	 * @param string $locale       Locale WordPress corrente (es. it_IT).
	 * @return string Chiave del transient (max 172 char per le API WP).
	 */
	public function build_key( int $post_id, string $post_content, array $candidate_ids, string $locale ): string {
		sort( $candidate_ids );

		$hash = sha1(
			$post_content
			. implode( ',', $candidate_ids )
			. self::SCHEMA_VERSION
			. $locale
		);

		$key = self::TRANSIENT_PREFIX . $post_id . '_' . $hash;

		// Registra la chiave nell'indice del post per poterla invalidare in seguito.
		$this->register_key( $post_id, $key );

		return $key;
	}

	/**
	 * Registra una chiave nell'indice delle chiavi per il post_id.
	 *
	 * @param int    $post_id ID del post.
	 * @param string $key     Chiave transient da registrare.
	 */
	private function register_key( int $post_id, string $key ): void {
		$index_key = 'sai_cache_index_' . $post_id;

		$raw_keys = get_option( $index_key, [] );
		$keys     = is_array( $raw_keys ) ? $raw_keys : [];

		if ( ! in_array( $key, $keys, true ) ) {
			$keys[] = $key;
			update_option( $index_key, $keys, false );
		}
	}
}
