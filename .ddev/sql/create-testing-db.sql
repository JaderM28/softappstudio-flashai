-- PostgreSQL no soporta CREATE DATABASE IF NOT EXISTS, asi que generamos la
-- sentencia solo cuando la base no existe y la ejecutamos con \gexec.
SELECT 'CREATE DATABASE testing OWNER db'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'testing')
\gexec
