/**
 * Sidebar Gutenberg del plugin — pulsante di analisi e gestione stato multi-step.
 */

import { useState, useCallback } from '@wordpress/element';
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

	const postId = useSelect(
		( select ) => select( 'core/editor' ).getCurrentPostId(),
		[]
	);

	/** Aggiunge uno step in coda e restituisce il suo indice nell'array corrente. */
	const appendStep = useCallback( ( text ) => {
		let newIndex = 0;
		setSteps( ( prev ) => {
			newIndex = prev.length;
			return [ ...prev, { text, status: 'loading' } ];
		} );
		return newIndex;
	}, [] );

	/** Aggiorna status e/o testo di uno step per indice. */
	const patchStep = useCallback( ( index, patch ) => {
		setSteps( ( prev ) =>
			prev.map( ( s, i ) => ( i === index ? { ...s, ...patch } : s ) )
		);
	}, [] );

	const isLoading = steps.some( ( s ) => s.status === 'loading' );

	const handleAnalyze = useCallback( async () => {
		// Reset completo dello stato.
		setError( '' );
		setLinks( [] );
		setEmphasis( [] );
		setSteps( [] );
		setIsModalOpen( true );

		try {
			// ── Step 1: raccolta blocchi ─────────────────────────────────────
			let stepIdx = 0;
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

			setSteps( ( prev ) =>
				prev.map( ( s, i ) => i === stepIdx ? {
					...s,
					status: 'done',
					text: __( 'Raccolta blocchi:', 'semantic-ai' ) + ' ' + blocks.length + ' ' + __( 'blocchi trovati', 'semantic-ai' ),
				} : s )
			);

			// ── Step 2: ricerca candidati ────────────────────────────────────
			stepIdx = 1;
			setSteps( ( prev ) => [ ...prev, { text: __( 'Ricerca contenuti candidati…', 'semantic-ai' ), status: 'loading' } ] );

			const prepareData    = await fetchPrepare( postId );
			const candidateCount = prepareData?.candidateCount ?? 0;

			setSteps( ( prev ) =>
				prev.map( ( s, i ) => i === stepIdx ? {
					...s,
					status: 'done',
					text: __( 'Candidati trovati:', 'semantic-ai' ) + ' ' + candidateCount + ' ' + __( 'articoli', 'semantic-ai' ),
				} : s )
			);

			// ── Step 3: analisi AI ───────────────────────────────────────────
			stepIdx = 2;
			setSteps( ( prev ) => [ ...prev, { text: __( 'Analisi AI in corso…', 'semantic-ai' ), status: 'loading' } ] );

			const result = await fetchSuggestions(
				postId,
				blocks.map( ( { index, type, text } ) => ( { index, type, text } ) )
			);

			if ( result.error ) {
				setSteps( ( prev ) =>
					prev.map( ( s, i ) => i === stepIdx ? { ...s, status: 'error', text: __( 'Errore durante l\'analisi AI.', 'semantic-ai' ) } : s )
				);
				setError( result.error );
			} else {
				const foundLinks    = result.links ?? [];
				const foundEmphasis = result.emphasis ?? [];
				setSteps( ( prev ) =>
					prev.map( ( s, i ) => i === stepIdx ? {
						...s,
						status: 'done',
						text: __( 'Analisi completata:', 'semantic-ai' ) + ' ' + foundLinks.length + ' ' + __( 'link', 'semantic-ai' ) + ', ' + foundEmphasis.length + ' ' + __( 'enfasi', 'semantic-ai' ),
					} : s )
				);
				setLinks( foundLinks );
				setEmphasis( foundEmphasis );
			}
		} catch ( err ) {
			// Segna l'ultimo step in loading come errore.
			setSteps( ( prev ) => {
				const lastLoadingIdx = [ ...prev ].reverse().findIndex( ( s ) => s.status === 'loading' );
				if ( lastLoadingIdx === -1 ) {
					return [ ...prev, { text: __( 'Errore imprevisto.', 'semantic-ai' ), status: 'error' } ];
				}
				const realIdx = prev.length - 1 - lastLoadingIdx;
				return prev.map( ( s, i ) => i === realIdx ? { ...s, status: 'error' } : s );
			} );

			if ( err.message === 'sai_no_provider' ) {
				setError( __( 'Nessun provider AI configurato. Configura un provider in Impostazioni → Connettori.', 'semantic-ai' ) );
			} else {
				setError( err.message ?? __( 'Errore durante l\'analisi.', 'semantic-ai' ) );
			}
		}
	}, [ postId ] );

	const handleReanalyze = useCallback( () => {
		handleAnalyze();
	}, [ handleAnalyze ] );

	const handleApply = useCallback( ( selected ) => {
		applyAllSuggestions( selected, blockMap );
		setIsModalOpen( false );
	}, [ blockMap ] );

	const handleClose = useCallback( () => {
		setIsModalOpen( false );
		setError( '' );
	}, [] );

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
			/>
		</>
	);
}
