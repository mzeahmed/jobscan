import {fileURLToPath, URL} from 'node:url';
import {readFileSync} from 'node:fs';
import {defineConfig} from 'vite';
import react from '@vitejs/plugin-react';

/**
 * Symfony, joint par le serveur de développement. Par défaut : la pile Docker locale derrière Traefik
 * (`/etc/hosts` : jobscan.local). Autre cible : `API_PROXY_TARGET=http://127.0.0.1:8000 npm run dev`.
 */
const API_PROXY_TARGET = process.env.API_PROXY_TARGET ?? 'https://jobscan.local:8443';
// Dans la pile Docker, compose fixe API_PROXY_TARGET=http://nginx:80 : réseau interne, sans TLS ni /etc/hosts.

/**
 * Reprend la CSP de nginx.conf pour le serveur Vite, afin de reproduire en local les blocages du navigateur vus
 * en production. nginx.conf reste la source unique : seuls les écarts propres au développement sont ajoutés ici.
 */
function cspFromNginx(development: boolean): string {
  const nginx = readFileSync(fileURLToPath(new URL('./nginx.conf', import.meta.url)), 'utf8');
  const match = nginx.match(/^\s*add_header Content-Security-Policy "([^"]+)" always;/m);

  if (!match) {
    throw new Error('Politique CSP introuvable dans nginx.conf.');
  }

  if (!development) {
    // Aperçu du build : la politique de production, telle quelle.
    return match[1];
  }

  return (
    match[1]
      // Vite injecte un script en ligne (React Refresh) en développement.
      .replace("script-src 'self'", "script-src 'self' 'unsafe-inline'")
      // Le CSS est injecté par des balises <style> en développement.
      .replace("style-src 'self'", "style-src 'self' 'unsafe-inline'")
      // HMR (WebSocket).
      .replace("connect-src 'self'", "connect-src 'self' ws://localhost:* ws://127.0.0.1:*")
  );
}

// Même origine pour le navigateur : pas de CORS, et le cookie de rafraîchissement (chemin /api/v1/auth) est posé
// sur l'origine du front. `secure: false` : certificat local (mkcert) de Traefik.
const apiProxy = {
  '/api': {target: API_PROXY_TARGET, changeOrigin: true, secure: false},
  '/health': {target: API_PROXY_TARGET, changeOrigin: true, secure: false}
};

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url))
    }
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    // Vite refuse les noms d'hôte inconnus : `app.jobscan.local` (nginx → Vite) doit être autorisé.
    allowedHosts: ['.jobscan.local'],
    headers: {
      'Content-Security-Policy': cspFromNginx(true)
    },
    proxy: apiProxy
  },
  // `npm run preview` sert le build avec la CSP de production : c'est la cible des tests de bout en bout.
  preview: {
    host: '0.0.0.0',
    port: 4173,
    strictPort: true,
    headers: {
      'Content-Security-Policy': cspFromNginx(false)
    },
    proxy: apiProxy
  }
});
