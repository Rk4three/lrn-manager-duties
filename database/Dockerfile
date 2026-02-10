FROM postgres:15

# Copy init scripts into the container (avoids Windows volume mount bug)
COPY schema.sql /docker-entrypoint-initdb.d/01-schema.sql
COPY seeds.sql /docker-entrypoint-initdb.d/02-seeds.sql
