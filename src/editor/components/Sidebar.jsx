/**
 * Sidebar Gutenberg del plugin — pulsante di analisi e gestione stato.
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
import { fetchSuggestions } from '../lib/api';
import { getTextBlocks } from '../lib/blocks';
import SuggestionModal from './SuggestionModal';
import { applyAllSuggestions } from '../lib/apply';

const PLUGIN_NAME = 'semantic-internal-links';
const SIDEBAR_NAME = `${ PLUGIN_NAME }/sidebar`;

/** Flag iniettato da wp_localize_script: indica se il provider AI è disponibile. */
const providerAvailable = window.silData?.providerAvailable ?? true;

export default function Sidebar() {
	const [ isModalOpen,  setIsModalOpen ]  = useState( false );
	const [ isLoading,    setIsLoading ]    = useState( false );
	const [ error,        setError ]        = useState( '' );
	const [ links,        setLinks ]        = useState( [] );
	const [ emphasis,     setEmphasis ]     = useState( [] );
	/** Mappa blockIndex → clientId, aggiornata ad ogni analisi. */
	const [ blockMap,     setBlockMap ]     = useState( {} );

	const postId = useSelect(
		( select ) => select( 'core/editor' ).getCurrentPostId(),
		[]
	);

	const handleAnalyze = useCallback( async () => {
		setError( '' );
		setIsLoading( true );
		setIsModalOpen( true );

		try {
			const blocks = getTextBlocks();

			if ( blocks.length === 0 ) {
				setError( __( 'Nessun blocco testuale trovato nel post.', 'semantic-internal-links' ) );
				setIsLoading( false );
				return;
			}

			/** Mappa index → clientId per l'applicazione successiva. */
			const map = {};
			blocks.forEach( ( b ) => { map[ b.index ] = b.clientId; } );
			setBlockMap( map );

			const result = await fetchSuggestions(
				postId,
				blocks.map( ( { index, type, text } ) => ( { index, type, text } ) )
			);

			if ( result.error ) {
				setError( result.error );
			} else {
				setLinks( result.links ?? [] );
				setEmphasis( result.emphasis ?? [] );
			}
		} catch ( err ) {
			if ( err.message === 'sil_no_provider' ) {
				setError( __( 'Nessun provider AI configurato. Configura un provider in Impostazioni → Connettori.', 'semantic-internal-links' ) );
			} else {
				setError( err.message ?? __( 'Errore durante l\'analisi.', 'semantic-internal-links' ) );
			}
		} finally {
			setIsLoading( false );
		}
	}, [ postId ] );

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
				{ __( 'Semantic Internal Links', 'semantic-internal-links' ) }
			</PluginSidebarMoreMenuItem>

			<PluginSidebar
				name={ SIDEBAR_NAME }
				title={ __( 'Semantic Internal Links', 'semantic-internal-links' ) }
			>
				<div className="sil-sidebar">
					{ ! providerAvailable && (
						<Notice status="warning" isDismissible={ false }>
							{ __( 'Nessun provider AI configurato. Configura un provider in Impostazioni → Connettori.', 'semantic-internal-links' ) }
						</Notice>
					) }

					<p className="sil-sidebar__description">
						{ __( 'Analizza il post e suggerisce link interni ed enfasi semantica tramite AI.', 'semantic-internal-links' ) }
					</p>

					<Button
						variant="primary"
						onClick={ handleAnalyze }
						disabled={ ! providerAvailable || isLoading }
						className="sil-sidebar__button"
					>
						{ isLoading
							? <><Spinner /> { __( 'Analisi in corso…', 'semantic-internal-links' ) }</>
							: __( 'Analizza link interni', 'semantic-internal-links' )
						}
					</Button>
				</div>
			</PluginSidebar>

			<SuggestionModal
				isOpen={ isModalOpen }
				isLoading={ isLoading }
				error={ error }
				links={ links }
				emphasis={ emphasis }
				onApply={ handleApply }
				onClose={ handleClose }
			/>
		</>
	);
}
