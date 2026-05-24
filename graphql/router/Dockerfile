FROM ghcr.io/apollographql/router:v1.55.0
COPY router.yaml          /etc/router.yaml
COPY supergraph.graphql   /etc/supergraph.graphql
EXPOSE 4000
ENV APOLLO_ROUTER_CONFIG_PATH=/etc/router.yaml \
    APOLLO_ROUTER_SUPERGRAPH_PATH=/etc/supergraph.graphql \
    APOLLO_ROUTER_LOG=info
