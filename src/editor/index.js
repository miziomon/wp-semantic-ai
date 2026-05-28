/**
 * Entry point dell'editor Gutenberg — registra il plugin sidebar.
 */

import { registerPlugin } from '@wordpress/plugins';
import Sidebar from './components/Sidebar';

registerPlugin(
	'semantic-ai',
	{
		render: Sidebar,
	}
);
