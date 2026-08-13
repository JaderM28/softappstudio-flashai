-- PostgreSQL has no CREATE DATABASE IF NOT EXISTS, so build the statement only
-- when the database is missing and run it with \gexec.
SELECT 'CREATE DATABASE testing OWNER db'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'testing')
\gexec
