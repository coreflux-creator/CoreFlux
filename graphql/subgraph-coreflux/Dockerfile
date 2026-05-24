FROM node:20-alpine AS builder
WORKDIR /app
COPY package.json ./
RUN npm install --omit=optional
COPY tsconfig.json schema.graphql ./
COPY src ./src
RUN npx tsc

FROM node:20-alpine
WORKDIR /app
COPY --from=builder /app/node_modules ./node_modules
COPY --from=builder /app/dist ./dist
COPY schema.graphql ./
EXPOSE 4001
CMD ["node", "dist/index.js"]
