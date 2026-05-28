/**
 * Wrapper attorno a apiFetch per l'endpoint di suggerimento.
 * apiFetch gestisce automaticamente il nonce X-WP-Nonce tramite middleware.
 */

import apiFetch from '@wordpress/api-fetch';

/**
 * Richiede i suggerimenti AI per il post corrente.
 *
 * @param {number}   postId ID del post.
 * @param {Object[]} blocks Array di blocchi {index, type, text, clientId}.
 * @returns {Promise<{links: Object[], emphasis: Object[]}>} Suggerimenti.
 * @throws {Error} In caso di errore di rete o risposta non valida.
 */
export async function fetchSuggestions( postId, blocks ) {
	return apiFetch(
		{
			path: '/semantic-ai/v1/suggest',
			method: 'POST',
			data: { postId, blocks },
		}
	);
}
