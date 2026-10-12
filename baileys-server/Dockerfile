FROM node:22-bookworm-slim
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --omit=dev && npm cache clean --force
COPY server.mjs store.mjs lock.mjs destination.mjs sessions.mjs inbox.mjs ./
RUN mkdir /app/data && chown -R node:node /app
USER node
ENV WHATSAPP_SERVICE_HOST=0.0.0.0
EXPOSE 3100
CMD ["node", "server.mjs"]
