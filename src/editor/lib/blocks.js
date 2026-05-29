/**
 * Raccoglie i blocchi testuali dall'editor Gutenberg.
 *
 * Tipi inclusi: paragraph, list-item, quote.
 * Tipi esclusi: heading, code, preformatted, html, embed, media.
 */

/** Tipi di blocco da analizzare. */
const SUPPORTED_BLOCK_TYPES = new Set( [
	'core/paragraph',
	'core/list-item',
	'core/quote',
] );

/**
 * Estrae il testo puro da un blocco, rimuovendo i tag HTML.
 *
 * @param {string} html Contenuto HTML del blocco.
 * @returns {string} Testo pulito.
 */
export function stripHtml( html ) {
	if ( ! html ) return '';
	return html.replace( /<[^>]+>/g, ' ' ).replace( /\s+/g, ' ' ).trim();
}

/**
 * Raccoglie ricorsivamente i blocchi testuali da un array di blocchi.
 *
 * @param {Object[]} blocks      Array di blocchi Gutenberg.
 * @param {Object}   accumulator Oggetto accumulatore {items: [], index: number}.
 * @returns {Array<{clientId: string, index: number, type: string, text: string}>}
 */
function collectBlocks( blocks, accumulator ) {
	for ( const block of blocks ) {
		const name = block.name ?? '';

		if ( SUPPORTED_BLOCK_TYPES.has( name ) ) {
			const shortName = name.replace( 'core/', '' );
			const html = block.attributes?.content ?? block.attributes?.values ?? '';
			const text = stripHtml( html );

			if ( text.trim() ) {
				accumulator.items.push( {
					clientId: block.clientId,
					index: accumulator.index,
					type: shortName,
					text,
				} );
				accumulator.index++;
			}
		}

		// Scansione ricorsiva per blocchi annidati (es. list → list-item).
		if ( block.innerBlocks?.length ) {
			collectBlocks( block.innerBlocks, accumulator );
		}
	}

	return accumulator.items;
}

/**
 * Restituisce i blocchi testuali del post corrente.
 *
 * @param {Function} selectFn Funzione select di wp.data (per testabilità).
 * @returns {Array<{clientId: string, index: number, type: string, text: string}>}
 */
export function getTextBlocks( selectFn ) {
	const select = selectFn ?? window.wp.data.select;
	const blocks = select( 'core/block-editor' ).getBlocks();
	const accumulator = { items: [], index: 0 };

	return collectBlocks( blocks, accumulator );
}
