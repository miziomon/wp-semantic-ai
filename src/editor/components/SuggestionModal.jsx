/**
 * Modale di anteprima dei suggerimenti AI.
 * Presenta due sezioni (link / enfasi) con selezione individuale e globale.
 * Mostra il progresso dell'analisi tramite l'array steps.
 */

import { useState, useCallback, useEffect } from '@wordpress/element';
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
 * @param {boolean}   props.isOpen       Visibilità modale.
 * @param {Array}     props.steps        Step di progresso [{text, status}].
 * @param {string}    props.error        Messaggio di errore (vuoto = nessun errore).
 * @param {Object[]}  props.links        Suggerimenti link.
 * @param {Object[]}  props.emphasis     Suggerimenti enfasi.
 * @param {Function}  props.onApply      Callback con {links, emphasis} selezionati.
 * @param {Function}  props.onClose      Callback chiusura modale.
 * @param {Function}  props.onReanalyze  Callback per rilanciare l'analisi.
 * @param {Function}  props.onInterrupt  Callback per interrompere l'analisi in corso.
 */
export default function SuggestionModal( {
	isOpen,
	steps,
	error,
	links,
	emphasis,
	onApply,
	onClose,
	onReanalyze,
	onInterrupt,
} ) {
	// Fix bug 2: inizializzazione vuota + useEffect per sincronizzare con i props.
	// La modale rimane montata durante l'analisi, quindi i set devono aggiornarsi
	// quando links/emphasis cambiano (dopo il completamento della chiamata AI).
	const [ selectedLinks, setSelectedLinks ]       = useState( new Set() );
	const [ selectedEmphasis, setSelectedEmphasis ] = useState( new Set() );

	useEffect( () => {
		setSelectedLinks( new Set( links.map( ( _, i ) => i ) ) );
	}, [ links ] );

	useEffect( () => {
		setSelectedEmphasis( new Set( emphasis.map( ( _, i ) => i ) ) );
	}, [ emphasis ] );

	const isLoading = steps.some( ( s ) => s.status === 'loading' );

	const toggleItem = useCallback( ( set, setter, idx, checked ) => {
		setter( ( prev ) => {
			const next = new Set( prev );
			checked ? next.add( idx ) : next.delete( idx );
			return next;
		} );
	}, [] );

	const selectAll   = ( setter, items ) => setter( new Set( items.map( ( _, i ) => i ) ) );
	const deselectAll = ( setter ) => setter( new Set() );

	const handleApply = () => {
		onApply( {
			links:    links.filter( ( _, i ) => selectedLinks.has( i ) ),
			emphasis: emphasis.filter( ( _, i ) => selectedEmphasis.has( i ) ),
		} );
	};

	if ( ! isOpen ) return null;

	/** Icona per ogni stato di step. */
	const stepIcon = ( status ) => {
		if ( status === 'loading' ) return <Spinner style={ { margin: '0 4px 0 0' } } />;
		if ( status === 'done' )    return <span style={ { color: '#0a7a0a', marginRight: 6 } }>✓</span>;
		if ( status === 'error' )   return <span style={ { color: '#a00', marginRight: 6 } }>✗</span>;
		return null;
	};

	return (
		<Modal
			title={ __( 'Suggerimenti link interni', 'semantic-ai' ) }
			onRequestClose={ onClose }
			className="sai-modal"
			size="medium"
		>
			{ /* Progress steps */ }
			{ steps.length > 0 && (
				<div className="sai-modal__steps" style={ { marginBottom: 16 } }>
					{ steps.map( ( step, i ) => (
						<div
							key={ i }
							style={ {
								display: 'flex',
								alignItems: 'center',
								padding: '6px 0',
								borderBottom: i < steps.length - 1 ? '1px solid #f0f0f0' : 'none',
								color: step.status === 'error' ? '#a00' : 'inherit',
							} }
						>
							{ stepIcon( step.status ) }
							<span>{ step.text }</span>
						</div>
					) ) }
				</div>
			) }

			{ ! isLoading && error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ ! isLoading && ! error && links.length === 0 && emphasis.length === 0 && steps.length > 0 && (
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

			<div className="sai-modal__footer">
				{ /* Pulsante interrompi: visibile e prominente solo durante il caricamento */ }
				{ isLoading && onInterrupt && (
					<Button
						variant="primary"
						onClick={ onInterrupt }
						style={ { backgroundColor: '#d63638', borderColor: '#d63638' } }
					>
						{ __( 'Interrompi analisi', 'semantic-ai' ) }
					</Button>
				) }

				{ ! isLoading && (
					<>
						<Button variant="secondary" onClick={ onClose }>
							{ __( 'Annulla', 'semantic-ai' ) }
						</Button>
						{ onReanalyze && (
							<Button variant="secondary" onClick={ onReanalyze }>
								{ __( 'Rieffettua analisi', 'semantic-ai' ) }
							</Button>
						) }
						{ ! error && ( links.length > 0 || emphasis.length > 0 ) && (
							<Button variant="primary" onClick={ handleApply }>
								{ __( 'Applica selezionati', 'semantic-ai' ) }
							</Button>
						) }
					</>
				) }
			</div>
		</Modal>
	);
}
