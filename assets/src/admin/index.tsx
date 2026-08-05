import { createRoot } from '@wordpress/element';
import { bootConfig } from './boot';

const el = document.getElementById('reservant-admin-root');
if (el) {
  createRoot(el).render(<div>{bootConfig().currency}</div>);
}
