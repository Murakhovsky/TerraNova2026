FROM node:22-alpine AS frontend
WORKDIR /src
COPY package.json package-lock.json vite.config.js ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./frontend/
RUN npm run build

FROM nginx:1.28-alpine

COPY docker/symfony/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY symfony/public/ /var/www/html/symfony/public/
COPY --from=frontend /src/public/build/ /var/www/html/symfony/public/build/
