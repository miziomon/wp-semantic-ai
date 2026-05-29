/**
 * Sidebar Gutenberg del plugin — pulsante di analisi con chunking client-side.
 *
 * Il chunking lato JS suddivide i blocchi in gruppi di maxBlocksPerChunk
 * per evitare timeout del provider AI su articoli lunghi.
 */

import { useState, useCallback, useRef } from '@wordpress/element';
import {
	Button,
	Notice,
	Spinner,
} from '@wordpress/components';
import {
	PluginSidebar,
	PluginSidebarMoreMenuItem,
} from '@wordpress/editor';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { fetchPrepare, fetchSuggestions, logAnalysis } from '../lib/api';
import { getTextBlocks } from '../lib/blocks';
import SuggestionModal from './SuggestionModal';
import { applyAllSuggestions } from '../lib/apply';

const PLUGIN_NAME = 'semantic-ai';
const SIDEBAR_NAME = `${ PLUGIN_NAME }/sidebar`;

/** Flag iniettato da wp_localize_script: indica se il provider AI è disponibile. */
const providerAvailable = window.silData?.providerAvailable ?? true;

/** Deduplica i link per targetId (mantiene il primo). */
function dedupeLinks( links ) {
	const seen = new Set();
	return links.filter( ( l ) => {
		if ( seen.has( l.targetId ) ) return false;
		seen.add( l.targetId );
		return true;
	} );
}

/** Deduplica le enfasi per coppia frase+formato (mantiene la prima). */
function dedupeEmphasis( emphasis ) {
	const seen = new Set();
	return emphasis.filter( ( e ) => {
		const key = ( e.phrase ?? '' ) + '|' + ( e.format ?? '' );
		if ( seen.has( key ) ) return false;
		seen.add( key );
		return true;
	} );
}

export default function Sidebar() {
	const [ isModalOpen, setIsModalOpen ] = useState( false );
	const [ steps,       setSteps ]       = useState( [] );
	const [ error,       setError ]       = useState( '' );
	const [ links,       setLinks ]       = useState( [] );
	const [ emphasis,    setEmphasis ]    = useState( [] );
	const [ blockMap,    setBlockMap ]    = useState( {} );

	/**
	 * Flag di cancellazione: impostato a true da handleClose/handleInterrupt
	 * per ignorare i risultati di un'analisi in corso.
	 */
	const cancelledRef = useRef( false );

	const postId = useSelect(
		( select ) => select( 'core/editor' ).getCurrentPostId(),
		[]
	);

	const isLoading = steps.some( ( s ) => s.status === 'loading' );

	const handleAnalyze = useCallback( async () => {
		cancelledRef.current = false;
		setError( '' );
		setLinks( [] );
		setEmphasis( [] );
		setIsModalOpen( true );

		// ── Step 1: raccolta blocchi (sincrono) ──────────────────────────────
		setSteps( [ { text: __( 'Raccolta blocchi testuali…', 'semantic-ai' ), status: 'loading' } ] );

		const blocks = getTextBlocks();

		if ( blocks.length === 0 ) {
			setSteps( [ { text: __( 'Nessun blocco testuale trovato nel post.', 'semantic-ai' ), status: 'error' } ] );
			setError( __( 'Nessun blocco testuale trovato nel post.', 'semantic-ai' ) );
			return;
		}

		const map = {};
		blocks.forEach( ( b ) => { map[ b.index ] = b.clientId; } );
		setBlockMap( map );

		const blockCount     = blocks.length;
		const maxPerChunk    = ( window.silData?.maxBlocksPerChunk ?? 8 );
		const clampedMax     = Math.max( 1, maxPerChunk );

		// Dividi i blocchi in chunk di clampedMax.
		const chunks = [];
		for ( let i = 0; i < blocks.length; i += clampedMax ) {
			chunks.push( blocks.slice( i, i + clampedMax ) );
		}
		const totalChunks = chunks.length;

		// Step 0 completato, avvia ricerca candidati.
		setSteps( [
			{ text: __( 'Raccolta blocchi:', 'semantic-ai' ) + ' ' + blockCount + ' ' + __( 'blocchi trovati', 'semantic-ai' ), status: 'done' },
			{ text: __( 'Ricerca contenuti candidati…', 'semantic-ai' ), status: 'loading' },
		] );

		try {
			// ── Step 2: ricerca candidati ────────────────────────────────────
			const prepareData    = await fetchPrepare( postId );
			if ( cancelledRef.current ) return;
			const candidateCount = prepareData?.candidateCount ?? 0;

			// Base degli step completati (cresce ad ogni chunk).
			let baseSteps = [
				{ text: __( 'Raccolta blocchi:', 'semantic-ai' ) + ' ' + blockCount + ' ' + __( 'blocchi trovati', 'semantic-ai' ), status: 'done' },
				{ text: __( 'Candidati trovati:', 'semantic-ai' ) + ' ' + candidateCount + ' ' + __( 'articoli', 'semantic-ai' ), status: 'done' },
			];

			let allLinks    = [];
			let allEmphasis = [];
			let allFromCache = true;

			// ── Step 3…N: analisi per chunk ──────────────────────────────────
			for ( let ci = 0; ci < totalChunks; ci++ ) {
				if ( cancelledRef.current ) return;

				const chunk     = chunks[ ci ];
				const fromBlock = ( chunk[ 0 ].index + 1 );
				const toBlock   = ( chunk[ chunk.length - 1 ].index + 1 );

				const chunkLabel = totalChunks > 1
					? __( 'Chunk', 'semantic-ai' ) + ' ' + ( ci + 1 ) + '/' + totalChunks + ': ' + __( 'blocchi', 'semantic-ai' ) + ' ' + fromBlock + '–' + toBlock
					: __( 'Analisi AI in corso…', 'semantic-ai' );

				setSteps( [
					...baseSteps,
					{ text: chunkLabel + '…', status: 'loading' },
				] );

				// Passa totalBlocks solo se stiamo facendo più chunk
				// (altrimenti comportamento identico a v0.3.1 senza skip log).
				const result = await fetchSuggestions(
					postId,
					chunk.map( ( { index, type, text } ) => ( { index, type, text } ) ),
					totalChunks > 1 ? blockCount : 0
				);
				if ( cancelledRef.current ) return;

				if ( result.error ) {
					setSteps( [
						...baseSteps,
						{ text: chunkLabel + ': ' + __( 'errore.', 'semantic-ai' ), status: 'error' },
					] );
					setError( result.error );
					return;
				}

				const chunkLinks    = result.links    ?? [];
				const chunkEmphasis = result.emphasis ?? [];
				if ( ! ( result._from_cache ?? false ) ) allFromCache = false;

				allLinks    = dedupeLinks( [ ...allLinks, ...chunkLinks ] );
				allEmphasis = dedupeEmphasis( [ ...allEmphasis, ...chunkEmphasis ] );

				const chunkSummary = totalChunks > 1
					? __( 'trovati', 'semantic-ai' ) + ' ' + chunkLinks.length + ' ' + __( 'link', 'semantic-ai' ) + ', ' + chunkEmphasis.length + ' ' + __( 'enfasi', 'semantic-ai' )
					  + ' · ' + __( 'totale:', 'semantic-ai' ) + ' ' + allLinks.length + ' ' + __( 'link', 'semantic-ai' ) + ', ' + allEmphasis.length + ' ' + __( 'enfasi', 'semantic-ai' )
					: __( 'Analisi AI completata', 'semantic-ai' );

				baseSteps = [
					...baseSteps,
					{ text: chunkLabel + ': ' + chunkSummary, status: 'done' },
				];
			}

			if ( cancelledRef.current ) return;

			// Step finali di riepilogo.
			setSteps( [
				...baseSteps,
				{ text: __( 'Analisi link: trovati', 'semantic-ai' ) + ' ' + allLinks.length + ' ' + __( 'link', 'semantic-ai' ), status: 'done' },
				{ text: __( 'Analisi enfasi: trovate', 'semantic-ai' ) + ' ' + allEmphasis.length + ' ' + __( 'enfasi', 'semantic-ai' ), status: 'done' },
			] );

			setLinks( allLinks );
			setEmphasis( allEmphasis );

			// Log centralizzato solo quando si è fatto chunking multi-richiesta.
			if ( totalChunks > 1 ) {
				// Non aspettiamo (fire and forget) — il log è secondario.
				logAnalysis( postId, allLinks, allEmphasis, prepareData?.candidateCount ?? 0, allFromCache, [] )
					.catch( () => {} );
			}
		} catch ( err ) {
			if ( cancelledRef.current ) return;

			setSteps( ( prev ) => {
				const lastLoadingIdx = [ ...prev ].reverse().findIndex( ( s ) => s.status === 'loading' );
				if ( lastLoadingIdx === -1 ) {
					return [ ...prev, { text: __( 'Errore imprevisto.', 'semantic-ai' ), status: 'error' } ];
				}
				const realIdx = prev.length - 1 - lastLoadingIdx;
				return prev.map( ( s, i ) => ( i === realIdx ? { ...s, status: 'error' } : s ) );
			} );

			if ( err.message === 'sai_no_provider' ) {
				setError( __( 'Nessun provider AI configurato. Configura un provider in Impostazioni → Connettori.', 'semantic-ai' ) );
			} else {
				setError( err.message ?? __( 'Errore durante l\'analisi.', 'semantic-ai' ) );
			}
		}
	}, [ postId ] );

	const handleClose = useCallback( () => {
		cancelledRef.current = true;
		setSteps( [] );
		setIsModalOpen( false );
		setError( '' );
	}, [] );

	const handleInterrupt = useCallback( () => {
		cancelledRef.current = true;
		setSteps( [] );
		setIsModalOpen( false );
		setError( '' );
	}, [] );

	const handleReanalyze = useCallback( () => {
		handleAnalyze();
	}, [ handleAnalyze ] );

	const handleApply = useCallback( ( selected ) => {
		applyAllSuggestions( selected, blockMap );
		setIsModalOpen( false );
	}, [ blockMap ] );

	return (
		<>
			<PluginSidebarMoreMenuItem target={ SIDEBAR_NAME }>
				{ __( 'Semantic AI', 'semantic-ai' ) }
			</PluginSidebarMoreMenuItem>

			<PluginSidebar
				name={ SIDEBAR_NAME }
				title={ __( 'Semantic AI', 'semantic-ai' ) }
			>
				<div className="sai-sidebar">
					{ ! providerAvailable && (
						<Notice status="warning" isDismissible={ false }>
							{ __( 'Nessun provider AI configurato. Configura un provider in Impostazioni → Connettori.', 'semantic-ai' ) }
						</Notice>
					) }

					<p className="sai-sidebar__description">
						{ __( 'Analizza il post e suggerisce link interni ed enfasi semantica tramite AI.', 'semantic-ai' ) }
					</p>

					<Button
						variant="primary"
						onClick={ handleAnalyze }
						disabled={ ! providerAvailable || isLoading }
						className="sai-sidebar__button"
					>
						{ isLoading
							? <><Spinner /> { __( 'Analisi in corso…', 'semantic-ai' ) }</>
							: __( 'Analizza link interni', 'semantic-ai' )
						}
					</Button>
				</div>
			</PluginSidebar>

			<SuggestionModal
				isOpen={ isModalOpen }
				steps={ steps }
				error={ error }
				links={ links }
				emphasis={ emphasis }
				onApply={ handleApply }
				onClose={ handleClose }
				onReanalyze={ handleReanalyze }
				onInterrupt={ handleInterrupt }
			/>
		</>
	);
}
