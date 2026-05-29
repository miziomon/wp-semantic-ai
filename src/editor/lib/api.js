/**
 * Wrapper attorno a apiFetch per gli endpoint di analisi.
 * apiFetch gestisce automaticamente il nonce X-WP-Nonce tramite middleware.
 */

import apiFetch from '@wordpress/api-fetch';

/**
 * Recupera il conteggio dei candidati per il post corrente (fase veloce, solo DB).
 * Usato per mostrare feedback di progresso prima della chiamata AI.
 *
 * @param {number} postId ID del post.
 * @returns {Promise<{candidateCount: number}>} Conteggio candidati.
 * @throws {Error} In caso di errore di rete o risposta non valida.
 */
export async function fetchPrepare( postId ) {
	return apiFetch( {
		path: `/semantic-ai/v1/prepare?postId=${ postId }`,
		method: 'GET',
	} );
}

/**
 * Richiede i suggerimenti AI per il post corrente (fase lenta, chiamata LLM).
 *
 * @param {number}   postId ID del post.
 * @param {Object[]} blocks Array di blocchi {index, type, text}.
 * @returns {Promise<{links: Object[], emphasis: Object[]}>} Suggerimenti.
 * @throws {Error} In caso di errore di rete o risposta non valida.
 */
export async function fetchSuggestions( postId, blocks ) {
	return apiFetch( {
		path: '/semantic-ai/v1/suggest',
		method: 'POST',
		data: { postId, blocks },
	} );
}
