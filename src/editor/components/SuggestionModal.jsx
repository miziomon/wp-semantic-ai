/**
 * Modale di anteprima dei suggerimenti AI.
 * Presenta due sezioni (link / enfasi) con selezione individuale e globale.
 */

import { useState, useCallback } from '@wordpress/element';
import {
	Modal,
	Button,
	Notice,
	Spinner,
	PanelBody,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import SuggestionRow from './SuggestionRow';

/**
 * @param {Object}    props
 * @param {boolean}   props.isOpen      Visibilità modale.
 * @param {boolean}   props.isLoading   Stato di caricamento.
 * @param {string}    props.error       Messaggio di errore (vuoto = nessun errore).
 * @param {Object[]}  props.links       Suggerimenti link.
 * @param {Object[]}  props.emphasis    Suggerimenti enfasi.
 * @param {Function}  props.onApply     Callback con {links, emphasis} selezionati.
 * @param {Function}  props.onClose     Callback chiusura modale.
 */
export default function SuggestionModal( {
	isOpen,
	isLoading,
	error,
	links,
	emphasis,
	onApply,
	onClose,
} ) {
	const [ selectedLinks, setSelectedLinks ]       = useState( () => new Set( links.map( ( _, i ) => i ) ) );
	const [ selectedEmphasis, setSelectedEmphasis ] = useState( () => new Set( emphasis.map( ( _, i ) => i ) ) );

	const toggleItem = useCallback( ( set, setter, idx, checked ) => {
		setter( ( prev ) => {
			const next = new Set( prev );
			checked ? next.add( idx ) : next.delete( idx );
			return next;
		} );
	}, [] );

	const selectAll = ( setter, items ) =>
		setter( new Set( items.map( ( _, i ) => i ) ) );
	const deselectAll = ( setter ) => setter( new Set() );

	const handleApply = () => {
		onApply( {
			links:    links.filter( ( _, i ) => selectedLinks.has( i ) ),
			emphasis: emphasis.filter( ( _, i ) => selectedEmphasis.has( i ) ),
		} );
	};

	if ( ! isOpen ) return null;

	return (
		<Modal
			title={ __( 'Suggerimenti link interni', 'semantic-ai' ) }
			onRequestClose={ onClose }
			className="sai-modal"
			size="medium"
		>
			{ isLoading && (
				<div className="sai-modal__loading">
					<Spinner />
					<p>{ __( 'Analisi in corso…', 'semantic-ai' ) }</p>
				</div>
			) }

			{ ! isLoading && error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ ! isLoading && ! error && links.length === 0 && emphasis.length === 0 && (
				<Notice status="info" isDismissible={ false }>
					{ __( 'Nessun suggerimento trovato per questo articolo.', 'semantic-ai' ) }
				</Notice>
			) }

			{ ! isLoading && ! error && links.length > 0 && (
				<PanelBody
					title={ __( 'Link interni', 'semantic-ai' ) }
					className="sai-modal__section"
					initialOpen={ true }
				>
					<div className="sai-modal__section-controls">
						<Button variant="link" onClick={ () => selectAll( setSelectedLinks, links ) }>
							{ __( 'Seleziona tutto', 'semantic-ai' ) }
						</Button>
						{ ' · ' }
						<Button variant="link" onClick={ () => deselectAll( setSelectedLinks ) }>
							{ __( 'Deseleziona tutto', 'semantic-ai' ) }
						</Button>
					</div>
					{ links.map( ( item, i ) => (
						<SuggestionRow
							key={ i }
							item={ item }
							type="link"
							checked={ selectedLinks.has( i ) }
							onChange={ ( checked ) => toggleItem( selectedLinks, setSelectedLinks, i, checked ) }
						/>
					) ) }
				</PanelBody>
			) }

			{ ! isLoading && ! error && emphasis.length > 0 && (
				<PanelBody
					title={ __( 'Enfasi semantica', 'semantic-ai' ) }
					className="sai-modal__section"
					initialOpen={ true }
				>
					<div className="sai-modal__section-controls">
						<Button variant="link" onClick={ () => selectAll( setSelectedEmphasis, emphasis ) }>
							{ __( 'Seleziona tutto', 'semantic-ai' ) }
						</Button>
						{ ' · ' }
						<Button variant="link" onClick={ () => deselectAll( setSelectedEmphasis ) }>
							{ __( 'Deseleziona tutto', 'semantic-ai' ) }
						</Button>
					</div>
					{ emphasis.map( ( item, i ) => (
						<SuggestionRow
							key={ i }
							item={ item }
							type="emphasis"
							checked={ selectedEmphasis.has( i ) }
							onChange={ ( checked ) => toggleItem( selectedEmphasis, setSelectedEmphasis, i, checked ) }
						/>
					) ) }
				</PanelBody>
			) }

			{ ! isLoading && (
				<div className="sai-modal__footer">
					<Button variant="secondary" onClick={ onClose }>
						{ __( 'Annulla', 'semantic-ai' ) }
					</Button>
					{ ! error && ( links.length > 0 || emphasis.length > 0 ) && (
						<Button variant="primary" onClick={ handleApply }>
							{ __( 'Applica selezionati', 'semantic-ai' ) }
						</Button>
					) }
				</div>
			) }
		</Modal>
	);
}
