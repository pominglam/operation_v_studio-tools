FROM node:20.19.4-bookworm AS dependencies

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --omit=dev

FROM node:20.19.4-bookworm AS runtime

WORKDIR /app

ENV NODE_ENV=production
ENV PLAYWRIGHT_BROWSERS_PATH=/ms-playwright

COPY --from=dependencies /app/node_modules ./node_modules
COPY package.json package-lock.json server.js ./
COPY src ./src

RUN npx playwright install --with-deps chromium \
    && chown -R node:node /app /ms-playwright

USER node

CMD ["node", "server.js"]
