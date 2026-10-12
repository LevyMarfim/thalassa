CREATE SCHEMA IF NOT EXISTS thalassa;

DROP SCHEMA IF EXISTS public;

ALTER DATABASE thalassa SET search_path TO thalassa, public;
