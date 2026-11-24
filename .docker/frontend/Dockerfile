# Front React de développement : serveur Vite avec rechargement à chaud. Le code est monté (./frontend:/app) et le
# conteneur tourne sous VOTRE identifiant (voir docker-compose.yml) : node_modules et dist vous appartiennent.
#
# La production n'a pas d'image à part : le build statique (npm ci && npm run build) est copié dans l'image nginx
# au lot « Final » du chantier (voir docs/API.md), comme Coelbook copie `frontend/dist` dans son image unique.
FROM node:lts

WORKDIR /app

CMD ["sh", "-c", "npm install --no-audit --no-fund && npm run dev -- --host 0.0.0.0"]
