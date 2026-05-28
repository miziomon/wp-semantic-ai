<?php
/**
 * Recupera i post/page candidati per i link interni.
 *
 * @package Mavida\SemanticInternalLinks\Content
 */

declare( strict_types=1 );

namespace Mavida\SemanticInternalLinks\Content;

use Mavida\SemanticInternalLinks\Plugin;

/**
 * Fornisce i candidati per i link interni usando una strategia ibrida:
 * 1. Prima query: post/page con tassonomie in comune col post corrente.
 * 2. Se i risultati sono insufficienti, seconda query fulltext sulle keyword.
 *
 * Formato di output per ogni candidato:
 * { id: int, title: string, url: string, excerpt: string, terms: string[] }
 */
class CandidateProvider {

	/** Numero massimo di caratteri nell'excerpt normalizzato. */
	private const EXCERPT_MAX_LENGTH = 200;

	/** Soglia minima di risultati sotto la quale scatta la query fulltext. */
	private const FULLTEXT_FALLBACK_THRESHOLD = 10;

	/**
	 * Estrattore di keyword per il fallback fulltext.
	 *
	 * @var KeywordExtractor
	 */
	private KeywordExtractor $keyword_extractor;

	/**
	 * Costruttore.
	 *
	 * @param KeywordExtractor $keyword_extractor Estrattore keyword.
	 */
	public function __construct( KeywordExtractor $keyword_extractor ) {
		$this->keyword_extractor = $keyword_extractor;
	}

	/**
	 * Restituisce la lista dei candidati per un dato post.
	 *
	 * @param int $post_id ID del post corrente (escluso dai risultati).
	 * @return array<int, array{id: int, title: string, url: string, excerpt: string, terms: string[]}> Candidati.
	 */
	public function get_candidates( int $post_id ): array {
		$max_candidates = (int) Plugin::get_option( 'max_candidates' );
		$post_types     = (array) Plugin::get_option( 'target_post_types' );

		// Recupera i termini (categorie + tag) del post corrente per la query tassonomica.
		$tax_terms = $this->get_post_terms( $post_id );

		// 1. Query principale: post che condividono tassonomie col post corrente.
		$candidates = $this->query_by_taxonomy( $post_id, $tax_terms, $post_types, $max_candidates );

		// 2. Fallback fulltext se i risultati sono insufficienti.
		if ( count( $candidates ) < self::FULLTEXT_FALLBACK_THRESHOLD ) {
			$needed   = $max_candidates - count( $candidates );
			$existing = array_column( $candidates, 'id' );
			$keywords = $this->keyword_extractor->extract( $post_id );

			if ( ! empty( $keywords ) ) {
				$more       = $this->query_by_fulltext(
					$post_id,
					$keywords,
					$post_types,
					$needed,
					$existing
				);
				$candidates = array_merge( $candidates, $more );
			}
		}

		/**
		 * Filtra o estende la lista candidati prima che venga inviata all'AI.
		 *
		 * @param array<int, array{id: int, title: string, url: string, excerpt: string, terms: string[]}> $candidates Lista candidati.
		 * @param int $post_id ID del post corrente.
		 */
		$candidates = apply_filters( 'sai_candidates', $candidates, $post_id );

		/* @var array<int, array{id: int, title: string, url: string, excerpt: string, terms: string[]}> $candidates */
		return array_values( $candidates );
	}

	/**
	 * Query WP_Query per tassonomie condivise.
	 *
	 * @param int      $post_id    Post corrente da escludere.
	 * @param string[] $tax_terms  Slug dei termini da usare nel filtro.
	 * @param string[] $post_types Tipi di post da includere.
	 * @param int      $limit      Numero massimo di risultati.
	 * @return array<int, array{id: int, title: string, url: string, excerpt: string, terms: string[]}> Candidati.
	 */
	private function query_by_taxonomy( int $post_id, array $tax_terms, array $post_types, int $limit ): array {
		if ( empty( $tax_terms ) ) {
			return [];
		}

		$args = [
			'post_type'           => $post_types,
			'post_status'         => 'publish',
			'posts_per_page'      => $limit,
			'post__not_in'        => [ $post_id ],
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'tax_query'           => [  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				'relation' => 'OR',
				[
					'taxonomy' => 'category',
					'field'    => 'slug',
					'terms'    => $tax_terms,
				],
				[
					'taxonomy' => 'post_tag',
					'field'    => 'slug',
					'terms'    => $tax_terms,
				],
			],
		];

		$query = new \WP_Query( $args );

		return $this->normalize_posts( $query->posts );
	}

	/**
	 * Query WP_Query fulltext su keyword estratte.
	 *
	 * @param int      $post_id        Post corrente da escludere.
	 * @param string[] $keywords       Keyword per la ricerca fulltext.
	 * @param string[] $post_types     Tipi di post da includere.
	 * @param int      $limit          Numero massimo di risultati.
	 * @param int[]    $exclude_ids    ID già presenti nella lista candidati.
	 * @return array<int, array{id: int, title: string, url: string, excerpt: string, terms: string[]}> Candidati aggiuntivi.
	 */
	private function query_by_fulltext(
		int $post_id,
		array $keywords,
		array $post_types,
		int $limit,
		array $exclude_ids
	): array {
		$search_term   = implode( ' ', array_slice( $keywords, 0, 5 ) );
		$exclude_ids[] = $post_id;

		$args = [
			'post_type'           => $post_types,
			'post_status'         => 'publish',
			'posts_per_page'      => $limit,
			'post__not_in'        => $exclude_ids,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			's'                   => $search_term,  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_s
		];

		$query = new \WP_Query( $args );

		return $this->normalize_posts( $query->posts );
	}

	/**
	 * Recupera gli slug dei termini (category + post_tag) del post corrente.
	 *
	 * @param int $post_id ID del post.
	 * @return string[] Slug dei termini.
	 */
	private function get_post_terms( int $post_id ): array {
		$slugs = [];

		foreach ( [ 'category', 'post_tag' ] as $taxonomy ) {
			$terms = get_the_terms( $post_id, $taxonomy );

			if ( ! is_array( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				$slugs[] = $term->slug;
			}
		}

		return $slugs;
	}

	/**
	 * Normalizza un array di WP_Post in candidati strutturati.
	 *
	 * @param \WP_Post[]|int[] $posts Array di oggetti WP_Post (o ID).
	 * @return array<int, array{id: int, title: string, url: string, excerpt: string, terms: string[]}> Candidati normalizzati.
	 */
	private function normalize_posts( array $posts ): array {
		$candidates = [];

		foreach ( $posts as $post ) {
			if ( ! ( $post instanceof \WP_Post ) ) {
				continue;
			}

			$candidates[] = [
				'id'      => $post->ID,
				'title'   => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
				'url'     => (string) get_permalink( $post ),
				'excerpt' => $this->clean_excerpt( $post ),
				'terms'   => $this->get_post_terms( $post->ID ),
			];
		}

		return $candidates;
	}

	/**
	 * Genera un excerpt pulito (no HTML, max 200 caratteri) per il payload AI.
	 *
	 * @param \WP_Post $post Post di cui generare l'excerpt.
	 * @return string Excerpt normalizzato.
	 */
	private function clean_excerpt( \WP_Post $post ): string {
		$excerpt = ( '' !== $post->post_excerpt )
			? $post->post_excerpt
			: wp_trim_words( $post->post_content, 30 );
		$excerpt = wp_strip_all_tags( $excerpt );
		$excerpt = html_entity_decode( $excerpt, ENT_QUOTES, 'UTF-8' );

		if ( mb_strlen( $excerpt, 'UTF-8' ) > self::EXCERPT_MAX_LENGTH ) {
			$excerpt = mb_substr( $excerpt, 0, self::EXCERPT_MAX_LENGTH, 'UTF-8' ) . '…';
		}

		return $excerpt;
	}
}
