/** Base de l'API Symfony, sans barre finale. Même origine : le serveur Vite ou le reverse proxy relaie `/api/v1`. */
export const SYMFONY_API_URL = (import.meta.env.VITE_SYMFONY_API_BASE_URL ?? '/api/v1').replace(/\/+$/, '');
