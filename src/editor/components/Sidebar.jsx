/**
 * Sidebar Gutenberg del plugin — pulsante di analisi e gestione stato multi-step.
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
import { fetchPrepare, fetchSuggestions } from '../lib/api';
import { getTextBlocks } from '../lib/blocks';
import SuggestionModal from './SuggestionModal';
import { applyAllSuggestions } from '../lib/apply';

const PLUGIN_NAME = 'semantic-ai';
const SIDEBAR_NAME = `${ PLUGIN_NAME }/sidebar`;

/** Flag iniettato da wp_localize_script: indica se il provider AI è disponibile. */
const providerAvailable = window.silData?.providerAvailable ?? true;

export default function Sidebar() {
	const [ isModalOpen, setIsModalOpen ] = useState( false );
	const [ steps,       setSteps ]       = useState( [] );
	const [ error,       setError ]       = useState( '' );
	const [ links,       setLinks ]       = useState( [] );
	const [ emphasis,    setEmphasis ]    = useState( [] );
	/** Mappa blockIndex → clientId, aggiornata ad ogni analisi. */
	const [ blockMap,    setBlockMap ]    = useState( {} );

	/**
	 * Flag di cancellazione: impostato a true da handleClose/handleInterrupt
	 * per impedire che i risultati di un'analisi interrotta aggiornino lo stato.
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

		// ── Step 1: raccolta blocchi (sincrono, nessun await) ────────────────
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

		const blockCount = blocks.length;

		// Sostituisce l'intero array: step 0 done, step 1 loading.
		// Pattern "replace-full-array" — evita la cattura per riferimento di stepIdx.
		setSteps( [
			{ text: __( 'Raccolta blocchi:', 'semantic-ai' ) + ' ' + blockCount + ' ' + __( 'blocchi trovati', 'semantic-ai' ), status: 'done' },
			{ text: __( 'Ricerca contenuti candidati…', 'semantic-ai' ), status: 'loading' },
		] );

		// ── Step 2: ricerca candidati ────────────────────────────────────────
		try {
			const prepareData    = await fetchPrepare( postId );
			if ( cancelledRef.current ) return;

			const candidateCount = prepareData?.candidateCount ?? 0;

			// Step 0+1 done, step 2 loading.
			setSteps( [
				{ text: __( 'Raccolta blocchi:', 'semantic-ai' ) + ' ' + blockCount + ' ' + __( 'blocchi trovati', 'semantic-ai' ), status: 'done' },
				{ text: __( 'Candidati trovati:', 'semantic-ai' ) + ' ' + candidateCount + ' ' + __( 'articoli', 'semantic-ai' ), status: 'done' },
				{ text: __( 'Analisi AI in corso…', 'semantic-ai' ), status: 'loading' },
			] );

			// ── Step 3: chiamata AI ──────────────────────────────────────────
			const result = await fetchSuggestions(
				postId,
				blocks.map( ( { index, type, text } ) => ( { index, type, text } ) )
			);
			if ( cancelledRef.current ) return;

			if ( result.error ) {
				setSteps( [
					{ text: __( 'Raccolta blocchi:', 'semantic-ai' ) + ' ' + blockCount + ' ' + __( 'blocchi trovati', 'semantic-ai' ), status: 'done' },
					{ text: __( 'Candidati trovati:', 'semantic-ai' ) + ' ' + candidateCount + ' ' + __( 'articoli', 'semantic-ai' ), status: 'done' },
					{ text: __( 'Errore durante l\'analisi AI.', 'semantic-ai' ), status: 'error' },
				] );
				setError( result.error );
				return;
			}

			const foundLinks    = result.links ?? [];
			const foundEmphasis = result.emphasis ?? [];

			// Stato finale: 4 step dettagliati (link e enfasi separati).
			setSteps( [
				{ text: __( 'Raccolta blocchi:', 'semantic-ai' ) + ' ' + blockCount + ' ' + __( 'blocchi trovati', 'semantic-ai' ), status: 'done' },
				{ text: __( 'Candidati trovati:', 'semantic-ai' ) + ' ' + candidateCount + ' ' + __( 'articoli', 'semantic-ai' ), status: 'done' },
				{ text: __( 'Analisi link:', 'semantic-ai' ) + ' ' + __( 'trovati', 'semantic-ai' ) + ' ' + foundLinks.length + ' ' + __( 'link', 'semantic-ai' ), status: 'done' },
				{ text: __( 'Analisi enfasi:', 'semantic-ai' ) + ' ' + __( 'trovate', 'semantic-ai' ) + ' ' + foundEmphasis.length + ' ' + __( 'enfasi', 'semantic-ai' ), status: 'done' },
			] );

			setLinks( foundLinks );
			setEmphasis( foundEmphasis );
		} catch ( err ) {
			if ( cancelledRef.current ) return;

			// Segna l'ultimo step in loading come errore.
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

	/** Chiude la modale e cancella eventuale analisi in corso. */
	const handleClose = useCallback( () => {
		cancelledRef.current = true;
		setSteps( [] ); // azzera isLoading → sblocca il pulsante sidebar
		setIsModalOpen( false );
		setError( '' );
	}, [] );

	/** Interrompe l'analisi in corso e chiude la modale. */
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
