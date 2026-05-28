/**
 * Applica i suggerimenti selezionati ai blocchi dell'editor via @wordpress/rich-text.
 *
 * Non inserisce mai testo generato dall'AI: applica soltanto formati
 * (core/link, core/bold, core/italic) a testo già presente nei blocchi.
 * Gli URL provengono esclusivamente dalla lista candidati (targetId → url),
 * mai da testo libero del modello.
 */

import { create, applyFormat, toHTMLString, getTextContent } from '@wordpress/rich-text';
import { dispatch, select } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

/** Mappa tipo enfasi → nome formato rich-text. */
const EMPHASIS_FORMAT_MAP = {
	bold:   'core/bold',
	italic: 'core/italic',
};

/**
 * Trova l'offset iniziale della n-esima occorrenza di una frase nel testo plain.
 *
 * @param {string} plainText  Testo del blocco senza HTML.
 * @param {string} phrase     Frase da cercare.
 * @param {number} occurrence Numero occorrenza (1-based).
 * @returns {number} Offset iniziale, oppure -1 se non trovato.
 */
function findOccurrenceOffset( plainText, phrase, occurrence ) {
	let searchFrom = 0;
	let found      = 0;

	while ( searchFrom < plainText.length ) {
		const idx = plainText.indexOf( phrase, searchFrom );
		if ( idx === -1 ) {
			return -1;
		}

		found++;
		if ( found === occurrence ) {
			return idx;
		}

		searchFrom = idx + 1;
	}

	return -1;
}

/**
 * Controlla se il range [start, end) contiene già un formato del tipo specificato.
 *
 * @param {Object} richValue  Valore rich-text.
 * @param {string} formatType Nome del formato (es. 'core/link').
 * @param {number} start      Offset iniziale.
 * @param {number} end        Offset finale (escluso).
 * @returns {boolean}
 */
function rangeHasFormat( richValue, formatType, start, end ) {
	const { formats } = richValue;
	for ( let i = start; i < end; i++ ) {
		if ( formats[ i ] && formats[ i ].some( ( f ) => f.type === formatType ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Restituisce l'attributo HTML contenente il testo del blocco.
 *
 * @param {string} blockName Nome blocco Gutenberg (es. 'core/paragraph').
 * @returns {string} Nome attributo ('content' o 'values').
 */
function getContentAttribute( blockName ) {
	return blockName === 'core/list-item' ? 'values' : 'content';
}

/**
 * Applica un singolo suggerimento link a un blocco.
 *
 * @param {Object} suggestion  Suggerimento link {anchorText, url, title, occurrence, blockIndex}.
 * @param {string} clientId    Client ID del blocco.
 * @returns {'applied'|'skipped'} Esito dell'operazione.
 */
function applyLinkSuggestion( suggestion, clientId ) {
	const block = select( 'core/block-editor' ).getBlock( clientId );
	if ( ! block ) {
		return 'skipped';
	}

	const attr      = getContentAttribute( block.name );
	const html      = block.attributes?.[ attr ] ?? '';
	const richValue = create( { html } );
	const plainText = getTextContent( richValue );
	const phrase    = suggestion.anchorText ?? '';
	const occ       = suggestion.occurrence ?? 1;

	if ( ! phrase ) {
		return 'skipped';
	}

	const start = findOccurrenceOffset( plainText, phrase, occ );
	if ( start === -1 ) {
		return 'skipped';
	}

	const end = start + phrase.length;

	if ( rangeHasFormat( richValue, 'core/link', start, end ) ) {
		return 'skipped';
	}

	const newValue = applyFormat(
		richValue,
		{
			type:       'core/link',
			attributes: {
				url:   suggestion.url,
				title: suggestion.title ?? '',
			},
		},
		start,
		end
	);

	dispatch( 'core/block-editor' ).updateBlockAttributes(
		clientId,
		{ [ attr ]: toHTMLString( { value: newValue } ) }
	);

	return 'applied';
}

/**
 * Applica un singolo suggerimento di enfasi (grassetto/corsivo) a un blocco.
 *
 * @param {Object} suggestion  Suggerimento enfasi {phrase, format, occurrence, blockIndex}.
 * @param {string} clientId    Client ID del blocco.
 * @returns {'applied'|'skipped'} Esito dell'operazione.
 */
function applyEmphasisSuggestion( suggestion, clientId ) {
	const block = select( 'core/block-editor' ).getBlock( clientId );
	if ( ! block ) {
		return 'skipped';
	}

	const formatType = EMPHASIS_FORMAT_MAP[ suggestion.format ];
	if ( ! formatType ) {
		return 'skipped';
	}

	const attr      = getContentAttribute( block.name );
	const html      = block.attributes?.[ attr ] ?? '';
	const richValue = create( { html } );
	const plainText = getTextContent( richValue );
	const phrase    = suggestion.phrase ?? '';
	const occ       = suggestion.occurrence ?? 1;

	if ( ! phrase ) {
		return 'skipped';
	}

	const start = findOccurrenceOffset( plainText, phrase, occ );
	if ( start === -1 ) {
		return 'skipped';
	}

	const end = start + phrase.length;

	if ( rangeHasFormat( richValue, formatType, start, end ) ) {
		return 'skipped';
	}

	const newValue = applyFormat(
		richValue,
		{ type: formatType },
		start,
		end
	);

	dispatch( 'core/block-editor' ).updateBlockAttributes(
		clientId,
		{ [ attr ]: toHTMLString( { value: newValue } ) }
	);

	return 'applied';
}

/**
 * Applica tutti i suggerimenti selezionati e mostra una snackbar di riepilogo.
 *
 * @param {{ links: Object[], emphasis: Object[] }} selected Suggerimenti selezionati.
 * @param {Object<number, string>} blockMap Mappa blockIndex → clientId.
 */
export function applyAllSuggestions( selected, blockMap ) {
	let applied = 0;
	let skipped = 0;

	for ( const link of ( selected.links ?? [] ) ) {
		const clientId = blockMap[ link.blockIndex ];
		if ( ! clientId ) {
			skipped++;
			continue;
		}
		const result = applyLinkSuggestion( link, clientId );
		result === 'applied' ? applied++ : skipped++;
	}

	for ( const emph of ( selected.emphasis ?? [] ) ) {
		const clientId = blockMap[ emph.blockIndex ];
		if ( ! clientId ) {
			skipped++;
			continue;
		}
		const result = applyEmphasisSuggestion( emph, clientId );
		result === 'applied' ? applied++ : skipped++;
	}

	const message = skipped > 0
		/* translators: 1: numero suggerimenti applicati, 2: numero suggerimenti saltati */
		? __( `Applicati ${ applied }, saltati ${ skipped } (già formattati o non trovati).`, 'semantic-ai' )
		/* translators: numero suggerimenti applicati */
		: __( `Applicati ${ applied } suggerimenti.`, 'semantic-ai' );

	dispatch( 'core/notices' ).createSuccessNotice( message, {
		type: 'snackbar',
		isDismissible: true,
	} );
}
