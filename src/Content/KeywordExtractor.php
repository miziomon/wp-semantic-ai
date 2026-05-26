<?php
/**
 * Estrae keyword rilevanti da titolo ed excerpt di un post.
 *
 * @package Mavida\SemanticInternalLinks\Content
 */

declare( strict_types=1 );

namespace Mavida\SemanticInternalLinks\Content;

/**
 * Estrarre 5-10 keyword significative da titolo + excerpt,
 * filtrando stop-words in italiano e inglese.
 */
class KeywordExtractor {

	/**
	 * Stop-words italiane e inglesi da ignorare.
	 * Mantenute lowercase per il confronto normalizzato.
	 *
	 * @var string[]
	 */
	private const STOP_WORDS = [
		// Italiano.
		'il',
		'lo',
		'la',
		'i',
		'gli',
		'le',
		'un',
		'uno',
		'una',
		'di',
		'a',
		'da',
		'in',
		'con',
		'su',
		'per',
		'tra',
		'fra',
		'e',
		'ed',
		'o',
		'ma',
		'se',
		'che',
		'chi',
		'cui',
		'non',
		'è',
		'sono',
		'ha',
		'ho',
		'hanno',
		'al',
		'del',
		'della',
		'dei',
		'delle',
		'degli',
		'dal',
		'dalla',
		'dai',
		'dalle',
		'nel',
		'nella',
		'nei',
		'nelle',
		'sul',
		'sulla',
		'sui',
		'sulle',
		'col',
		'coi',
		'come',
		'quando',
		'dove',
		'questo',
		'questa',
		'questi',
		'queste',
		'quello',
		'quella',
		'quelli',
		'quelle',
		'anche',
		'però',
		'quindi',
		'poi',
		'più',
		'meno',
		'molto',
		'poco',
		'tutto',
		'tutti',
		'ogni',
		'nessun',
		// Inglese.
		'the',
		'a',
		'an',
		'and',
		'or',
		'but',
		'in',
		'on',
		'at',
		'to',
		'for',
		'of',
		'with',
		'by',
		'from',
		'as',
		'is',
		'was',
		'are',
		'were',
		'be',
		'been',
		'being',
		'have',
		'has',
		'had',
		'do',
		'does',
		'did',
		'will',
		'would',
		'could',
		'should',
		'may',
		'might',
		'that',
		'this',
		'these',
		'those',
		'it',
		'its',
		'not',
		'no',
		'so',
		'if',
		'then',
		'than',
		'when',
		'where',
		'who',
		'which',
		'how',
		'all',
		'any',
		'both',
		'each',
		'few',
		'more',
		'most',
		'other',
		'some',
		'such',
		'into',
		'through',
		'about',
		'up',
		'out',
		'after',
		'before',
	];

	/** Lunghezza minima in caratteri perché una parola sia considerata keyword. */
	private const MIN_KEYWORD_LENGTH = 4;

	/** Numero massimo di keyword da restituire. */
	private const MAX_KEYWORDS = 10;

	/**
	 * Estrae keyword da titolo ed excerpt di un post.
	 *
	 * @param int $post_id ID del post.
	 * @return string[] Lista di keyword normalizzate (lowercase, dedup).
	 */
	public function extract( int $post_id ): array {
		$post = get_post( $post_id );

		if ( null === $post ) {
			return [];
		}

		// Combina titolo (peso maggiore: aggiunto due volte) + excerpt.
		$text = $post->post_title . ' ' . $post->post_title . ' ' . $post->post_excerpt;
		$text = wp_strip_all_tags( $text );

		return $this->tokenize( $text );
	}

	/**
	 * Estrae keyword da una stringa di testo libera.
	 * Utile quando si vuole analizzare un testo arbitrario.
	 *
	 * @param string $text Testo da analizzare.
	 * @return string[] Lista di keyword.
	 */
	public function extract_from_text( string $text ): array {
		return $this->tokenize( wp_strip_all_tags( $text ) );
	}

	/**
	 * Tokenizza il testo, filtra stop-words e restituisce le keyword.
	 *
	 * @param string $text Testo pulito (no HTML).
	 * @return string[] Keyword uniche normalizzate.
	 */
	private function tokenize( string $text ): array {
		// Normalizza: lowercase, rimuovi punteggiatura, split per spazi.
		$text    = mb_strtolower( $text, 'UTF-8' );
		$cleaned = preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', $text );
		$text    = ( null !== $cleaned ) ? $cleaned : $text;
		$split   = preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
		$words   = ( false !== $split ) ? $split : [];
		$stop    = array_flip( self::STOP_WORDS );
		$result  = [];

		foreach ( $words as $word ) {
			if (
				mb_strlen( $word, 'UTF-8' ) < self::MIN_KEYWORD_LENGTH
				|| isset( $stop[ $word ] )
				|| isset( $result[ $word ] )
			) {
				continue;
			}

			$result[ $word ] = true;

			if ( count( $result ) >= self::MAX_KEYWORDS ) {
				break;
			}
		}

		return array_keys( $result );
	}
}
