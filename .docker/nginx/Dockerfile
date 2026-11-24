# Nginx de production : sert les ressources statiques copiées depuis l'image de l'application (contexte « app ») et relaie PHP à php-fpm.
FROM nginx:alpine

COPY --from=app /app/public /app/public
COPY .docker/nginx/default.conf /etc/nginx/conf.d/default.conf
