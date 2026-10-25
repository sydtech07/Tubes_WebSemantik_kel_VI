# Apache Jena Fuseki (triple store + SPARQL endpoint).
# Jena 6.x membutuhkan Java 21 atau lebih baru.
FROM eclipse-temurin:21-jre

ARG FUSEKI_VERSION=6.2.0
ENV FUSEKI_HOME=/opt/fuseki \
    FUSEKI_BASE=/fuseki

# dlcdn.apache.org hanya menyimpan rilis terbaru; arsip lama ada di archive.apache.org
RUN apt-get update \
 && apt-get install -y --no-install-recommends curl ca-certificates tar \
 && rm -rf /var/lib/apt/lists/* \
 && mkdir -p "$FUSEKI_HOME" "$FUSEKI_BASE" \
 && ( curl -fsSL "https://mirror.facebook.net/apache/jena/binaries/apache-jena-fuseki-${FUSEKI_VERSION}.tar.gz" -o /tmp/fuseki.tgz \
   || curl -fsSL "https://archive.apache.org/dist/jena/binaries/apache-jena-fuseki-${FUSEKI_VERSION}.tar.gz" -o /tmp/fuseki.tgz ) \
 && tar -xzf /tmp/fuseki.tgz -C "$FUSEKI_HOME" --strip-components=1 \
 && rm /tmp/fuseki.tgz

WORKDIR /fuseki
EXPOSE 3030

ENTRYPOINT ["/bin/sh", "-c", "mkdir -p \"$FUSEKI_BASE/databases/cinelog\" && exec /opt/fuseki/fuseki-server \"$@\"", "--"]

CMD ["--update", "--tdb2", "--loc=/fuseki/databases/cinelog", "/cinelog"]

