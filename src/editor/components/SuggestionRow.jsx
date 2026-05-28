/**
 * Riga singola di un suggerimento (link o enfasi) nella modale.
 */

import { CheckboxControl } from '@wordpress/components';

/**
 * @param {Object}   props
 * @param {Object}   props.item        Suggerimento (link o emphasis).
 * @param {boolean}  props.checked     Stato checkbox.
 * @param {Function} props.onChange    Callback cambio stato.
 * @param {'link'|'emphasis'} props.type Tipo di suggerimento.
 */
export default function SuggestionRow( { item, checked, onChange, type } ) {
	const label =
		type === 'link'
			? `"${ item.anchorText }" → ${ item.title }`
			: `"${ item.phrase }" (${ item.format })`;

	return (
		<div className={ `sai-modal__row${ checked ? ' sai-modal__row--selected' : '' }` }>
			<CheckboxControl
				label={ label }
				checked={ checked }
				onChange={ onChange }
			/>
			{ item.rationale && (
				<p className="sai-modal__rationale">{ item.rationale }</p>
			) }
		</div>
	);
}
