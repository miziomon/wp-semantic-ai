/**
 * Wrapper attorno a apiFetch e ajax per gli endpoint di analisi.
 * apiFetch gestisce automaticamente il nonce X-WP-Nonce tramite middleware.
 */

import apiFetch from '@wordpress/api-fetch';

/**
 * Recupera il conteggio dei candidati per il post corrente (fase veloce, solo DB).
 *
 * @param {number} postId ID del post.
 * @returns {Promise<{candidateCount: number}>}
 */
export async function fetchPrepare( postId ) {
	return apiFetch( {
		path: `/semantic-ai/v1/prepare?postId=${ postId }`,
		method: 'GET',
	} );
}

/**
 * Richiede i suggerimenti AI per un gruppo di blocchi.
 *
 * @param {number}   postId      ID del post.
 * @param {Object[]} blocks      Blocchi da analizzare {index, type, text}.
 * @param {number}   totalBlocks Totale blocchi del post (0 = tutto, >0 = chunking JS).
 *                               Quando >0 il server salta il chunking interno e usa
 *                               totalBlocks per validare i blockIndex.
 * @returns {Promise<{links: Object[], emphasis: Object[]}>}
 */
export async function fetchSuggestions( postId, blocks, totalBlocks = 0 ) {
	const data = { postId, blocks };
	if ( totalBlocks > 0 ) {
		data.totalBlocks = totalBlocks;
	}
	return apiFetch( {
		path: '/semantic-ai/v1/suggest',
		method: 'POST',
		data,
	} );
}

/**
 * Registra nel log un'analisi completata (usato dopo chunking multi-richiesta).
 * Viene chiamato una sola volta alla fine, con i risultati merged.
 *
 * @param {number}   postId         ID del post.
 * @param {Object[]} links          Suggerimenti link merged.
 * @param {Object[]} emphasis       Suggerimenti enfasi merged.
 * @param {number}   candidateCount Conteggio candidati usati.
 * @param {boolean}  fromCache      True se tutti i chunk erano in cache.
 * @param {Object[]} candidates     Lista candidati (per il modale dettaglio).
 * @returns {Promise<void>}
 */
export async function logAnalysis( postId, links, emphasis, candidateCount, fromCache, candidates ) {
	const ajaxUrl  = window.silData?.ajaxUrl  ?? '/wp-admin/admin-ajax.php';
	const logNonce = window.silData?.logNonce ?? '';

	const fd = new FormData();
	fd.append( 'action',         'sai_log_analysis' );
	fd.append( 'nonce',          logNonce );
	fd.append( 'postId',         String( postId ) );
	fd.append( 'candidateCount', String( candidateCount ) );
	fd.append( 'fromCache',      fromCache ? '1' : '0' );
	fd.append( 'links',          JSON.stringify( links ) );
	fd.append( 'emphasis',       JSON.stringify( emphasis ) );
	fd.append( 'candidates',     JSON.stringify( candidates ) );

	await fetch( ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' } );
}
