import {StrictMode} from 'react';
import {createRoot} from 'react-dom/client';
import {config as fontAwesomeConfig} from '@fortawesome/fontawesome-svg-core';
import '@fortawesome/fontawesome-svg-core/styles.css';
import './index.css';
import App from '@/App.tsx';
import '@/i18n/i18n.ts';

// Après un déploiement, un onglet resté ouvert peut demander un morceau de code qui n'existe plus : on recharge
// la page une fois pour récupérer la nouvelle version.
window.addEventListener('vite:preloadError', (event) => {
  if (!sessionStorage.getItem('js-reloaded-after-deploy')) {
    sessionStorage.setItem('js-reloaded-after-deploy', '1');
    event.preventDefault();
    window.location.reload();
  }
});

// Font Awesome en SVG : la feuille de style est importée ci-dessus avec le reste du CSS plutôt qu'injectée par la
// bibliothèque au premier rendu (une balise <style> injectée serait bloquée par la CSP).
fontAwesomeConfig.autoAddCss = false;

const root = document.getElementById('root');

if (!root) {
  throw new Error('Élément #root introuvable.');
}

createRoot(root).render(
  <StrictMode>
    <App />
  </StrictMode>
);
