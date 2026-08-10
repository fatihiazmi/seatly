-- Runs once, on first initialisation of the data volume.
-- seatly_dev is created by POSTGRES_DB; seatly_test is the integration database
-- that concurrency, lock, and constraint tests run against.
CREATE DATABASE seatly_test OWNER seatly;

-- Extensions required by docs/LOGICAL_ERD.md §"Required extensions":
--   btree_gist — showtime overlap exclusion constraint
--   pgcrypto   — public_id UUID generation
\connect seatly_dev
CREATE EXTENSION IF NOT EXISTS btree_gist;
CREATE EXTENSION IF NOT EXISTS pgcrypto;

\connect seatly_test
CREATE EXTENSION IF NOT EXISTS btree_gist;
CREATE EXTENSION IF NOT EXISTS pgcrypto;
