--
-- PostgreSQL database dump
--

SET statement_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SET check_function_bodies = false;
SET client_min_messages = warning;

--
-- Name: plpgsql; Type: EXTENSION; Schema: -; Owner: 
--

CREATE EXTENSION IF NOT EXISTS plpgsql WITH SCHEMA pg_catalog;


--
-- Name: EXTENSION plpgsql; Type: COMMENT; Schema: -; Owner: 
--

COMMENT ON EXTENSION plpgsql IS 'PL/pgSQL procedural language';


SET search_path = public, pg_catalog;

SET default_tablespace = '';

SET default_with_oids = false;

--
-- Name: file_types; Type: TABLE; Schema: public; Owner: profplump; Tablespace: 
--

CREATE TABLE file_types (
    type character varying(64) NOT NULL
);


ALTER TABLE public.file_types OWNER TO profplump;

--
-- Name: COLUMN file_types.type; Type: COMMENT; Schema: public; Owner: profplump
--

COMMENT ON COLUMN file_types.type IS 'File Type';


--
-- Name: files; Type: TABLE; Schema: public; Owner: profplump; Tablespace: 
--

CREATE TABLE files (
    uid integer NOT NULL,
    base character varying(255) NOT NULL,
    path character varying(4095) NOT NULL,
    type character varying(64) NOT NULL,
    mtime timestamp without time zone NOT NULL,
    hash character(32),
    hash_time timestamp without time zone,
    remote_mtime timestamp without time zone,
    remote_hash character(32),
    remote_hash_time timestamp without time zone,
    priority smallint DEFAULT 0 NOT NULL,
    size bigint DEFAULT 0 NOT NULL
);


ALTER TABLE public.files OWNER TO profplump;

--
-- Name: COLUMN files.uid; Type: COMMENT; Schema: public; Owner: profplump
--

COMMENT ON COLUMN files.uid IS 'Unique ID';


--
-- Name: COLUMN files.base; Type: COMMENT; Schema: public; Owner: profplump
--

COMMENT ON COLUMN files.base IS 'Local base path';


--
-- Name: COLUMN files.path; Type: COMMENT; Schema: public; Owner: profplump
--

COMMENT ON COLUMN files.path IS 'Relative path';


--
-- Name: COLUMN files.type; Type: COMMENT; Schema: public; Owner: profplump
--

COMMENT ON COLUMN files.type IS 'Path Type';


--
-- Name: COLUMN files.mtime; Type: COMMENT; Schema: public; Owner: profplump
--

COMMENT ON COLUMN files.mtime IS 'Modification Time';


--
-- Name: COLUMN files.hash; Type: COMMENT; Schema: public; Owner: profplump
--

COMMENT ON COLUMN files.hash IS 'Hash';


--
-- Name: COLUMN files.hash_time; Type: COMMENT; Schema: public; Owner: profplump
--

COMMENT ON COLUMN files.hash_time IS 'Hash Time';


--
-- Name: COLUMN files.remote_mtime; Type: COMMENT; Schema: public; Owner: profplump
--

COMMENT ON COLUMN files.remote_mtime IS 'Remote Modification Time';


--
-- Name: COLUMN files.remote_hash; Type: COMMENT; Schema: public; Owner: profplump
--

COMMENT ON COLUMN files.remote_hash IS 'Remote Hash';


--
-- Name: COLUMN files.remote_hash_time; Type: COMMENT; Schema: public; Owner: profplump
--

COMMENT ON COLUMN files.remote_hash_time IS 'Remote Hash Time';


--
-- Name: COLUMN files.priority; Type: COMMENT; Schema: public; Owner: profplump
--

COMMENT ON COLUMN files.priority IS 'Manual Prioritization';


--
-- Name: COLUMN files.size; Type: COMMENT; Schema: public; Owner: profplump
--

COMMENT ON COLUMN files.size IS 'File Size (bytes)';


--
-- Name: files_uid_seq; Type: SEQUENCE; Schema: public; Owner: profplump
--

CREATE SEQUENCE files_uid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.files_uid_seq OWNER TO profplump;

--
-- Name: files_uid_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: profplump
--

ALTER SEQUENCE files_uid_seq OWNED BY files.uid;


--
-- Name: paths; Type: TABLE; Schema: public; Owner: profplump; Tablespace: 
--

CREATE TABLE paths (
    path character varying(4095) NOT NULL,
    priority integer DEFAULT 0 NOT NULL,
    last_scan timestamp without time zone DEFAULT '2000-01-01 00:00:00'::timestamp without time zone NOT NULL,
    remote_last_scan timestamp without time zone DEFAULT '2000-01-01 00:00:00'::timestamp without time zone NOT NULL,
    base character varying(255) NOT NULL,
    min_age interval DEFAULT '30 days'::interval NOT NULL,
    scan_age interval DEFAULT '1 day'::interval NOT NULL,
    remote_scan_age interval DEFAULT '30 days'::interval NOT NULL
);


ALTER TABLE public.paths OWNER TO profplump;

--
-- Name: COLUMN paths.path; Type: COMMENT; Schema: public; Owner: profplump
--

COMMENT ON COLUMN paths.path IS 'Relative path';


--
-- Name: COLUMN paths.priority; Type: COMMENT; Schema: public; Owner: profplump
--

COMMENT ON COLUMN paths.priority IS 'Upload priority';


--
-- Name: COLUMN paths.last_scan; Type: COMMENT; Schema: public; Owner: profplump
--

COMMENT ON COLUMN paths.last_scan IS 'Last local scan';


--
-- Name: COLUMN paths.remote_last_scan; Type: COMMENT; Schema: public; Owner: profplump
--

COMMENT ON COLUMN paths.remote_last_scan IS 'Last remote scan';


--
-- Name: COLUMN paths.base; Type: COMMENT; Schema: public; Owner: profplump
--

COMMENT ON COLUMN paths.base IS 'Local base path';


--
-- Name: COLUMN paths.min_age; Type: COMMENT; Schema: public; Owner: profplump
--

COMMENT ON COLUMN paths.min_age IS 'Minimum mtime age before update';


--
-- Name: COLUMN paths.scan_age; Type: COMMENT; Schema: public; Owner: profplump
--

COMMENT ON COLUMN paths.scan_age IS 'Days between local scans';


--
-- Name: COLUMN paths.remote_scan_age; Type: COMMENT; Schema: public; Owner: profplump
--

COMMENT ON COLUMN paths.remote_scan_age IS 'Days between remote scans';


--
-- Name: pending; Type: VIEW; Schema: public; Owner: profplump
--

CREATE VIEW pending AS
    SELECT files.uid, files.base, files.path, files.type, files.mtime, files.hash, files.hash_time, files.remote_mtime, files.remote_hash, files.remote_hash_time, files.priority, files.size FROM files WHERE (((files.remote_mtime IS NULL) AND ((files.type)::text <> 'folder'::text)) AND ((files.type)::text <> 'ignored'::text));


ALTER TABLE public.pending OWNER TO profplump;

--
-- Name: VIEW pending; Type: COMMENT; Schema: public; Owner: profplump
--

COMMENT ON VIEW pending IS 'Files that have not yet been uploaded';


--
-- Name: sections_path_seq; Type: SEQUENCE; Schema: public; Owner: profplump
--

CREATE SEQUENCE sections_path_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.sections_path_seq OWNER TO profplump;

--
-- Name: sections_path_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: profplump
--

ALTER SEQUENCE sections_path_seq OWNED BY paths.path;


--
-- Name: uploaded; Type: VIEW; Schema: public; Owner: profplump
--

CREATE VIEW uploaded AS
    SELECT files.uid, files.base, files.path, files.type, files.mtime, files.hash, files.hash_time, files.remote_mtime, files.remote_hash, files.remote_hash_time, files.priority, files.size FROM files WHERE (((files.remote_mtime IS NOT NULL) AND ((files.type)::text <> 'folder'::text)) AND ((files.type)::text <> 'ignored'::text));


ALTER TABLE public.uploaded OWNER TO profplump;

--
-- Name: VIEW uploaded; Type: COMMENT; Schema: public; Owner: profplump
--

COMMENT ON VIEW uploaded IS 'Files that have been uploaded';


--
-- Name: uid; Type: DEFAULT; Schema: public; Owner: profplump
--

ALTER TABLE ONLY files ALTER COLUMN uid SET DEFAULT nextval('files_uid_seq'::regclass);


--
-- Name: file_types_pkey; Type: CONSTRAINT; Schema: public; Owner: profplump; Tablespace: 
--

ALTER TABLE ONLY file_types
    ADD CONSTRAINT file_types_pkey PRIMARY KEY (type);


--
-- Name: files_pkey; Type: CONSTRAINT; Schema: public; Owner: profplump; Tablespace: 
--

ALTER TABLE ONLY files
    ADD CONSTRAINT files_pkey PRIMARY KEY (uid);


--
-- Name: files_unique; Type: CONSTRAINT; Schema: public; Owner: profplump; Tablespace: 
--

ALTER TABLE ONLY files
    ADD CONSTRAINT files_unique UNIQUE (base, path);


--
-- Name: paths_pkey1; Type: CONSTRAINT; Schema: public; Owner: profplump; Tablespace: 
--

ALTER TABLE ONLY paths
    ADD CONSTRAINT paths_pkey1 PRIMARY KEY (base, path);


--
-- Name: paths_pkey; Type: INDEX; Schema: public; Owner: profplump; Tablespace: 
--

CREATE UNIQUE INDEX paths_pkey ON paths USING btree (base, path);


--
-- Name: type; Type: FK CONSTRAINT; Schema: public; Owner: profplump
--

ALTER TABLE ONLY files
    ADD CONSTRAINT type FOREIGN KEY (type) REFERENCES file_types(type) ON UPDATE CASCADE ON DELETE RESTRICT;


--
-- Name: public; Type: ACL; Schema: -; Owner: pgsql
--

REVOKE ALL ON SCHEMA public FROM PUBLIC;
REVOKE ALL ON SCHEMA public FROM pgsql;
GRANT ALL ON SCHEMA public TO pgsql;
GRANT ALL ON SCHEMA public TO PUBLIC;


--
-- PostgreSQL database dump complete
--

