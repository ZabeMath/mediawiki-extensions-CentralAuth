-- This file is automatically generated using maintenance/generateSchemaSql.php.
-- Source: schema/tables.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
CREATE TABLE globalnames (
  gn_name TEXT NOT NULL,
  PRIMARY KEY(gn_name)
);


CREATE TABLE localnames (
  ln_wiki TEXT NOT NULL,
  ln_name TEXT NOT NULL,
  PRIMARY KEY(ln_wiki, ln_name)
);

CREATE INDEX ln_name_wiki ON localnames (ln_name, ln_wiki);


CREATE TABLE globaluser (
  gu_id SERIAL NOT NULL,
  gu_name TEXT DEFAULT NULL,
  gu_enabled TEXT DEFAULT '' NOT NULL,
  gu_enabled_method TEXT DEFAULT NULL,
  gu_home_db TEXT DEFAULT NULL,
  gu_email TEXT DEFAULT NULL,
  gu_email_authenticated TIMESTAMPTZ DEFAULT NULL,
  gu_salt TEXT DEFAULT NULL,
  gu_password TEXT DEFAULT NULL,
  gu_locked SMALLINT DEFAULT 0 NOT NULL,
  gu_hidden TEXT DEFAULT '' NOT NULL,
  gu_hidden_level INT DEFAULT 0 NOT NULL,
  gu_registration TIMESTAMPTZ DEFAULT NULL,
  gu_password_reset_key TEXT DEFAULT NULL,
  gu_password_reset_expiration TIMESTAMPTZ DEFAULT NULL,
  gu_auth_token TEXT DEFAULT NULL,
  gu_cas_token INT DEFAULT 1 NOT NULL,
  PRIMARY KEY(gu_id)
);

CREATE UNIQUE INDEX gu_name ON globaluser (gu_name);

CREATE INDEX gu_email ON globaluser (gu_email);

CREATE INDEX gu_locked ON globaluser (gu_name, gu_locked);

CREATE INDEX gu_hidden ON globaluser (gu_name, gu_hidden);

CREATE INDEX gu_hidden_level ON globaluser (gu_name, gu_hidden_level);


CREATE TABLE localuser (
  lu_wiki TEXT NOT NULL,
  lu_name TEXT NOT NULL,
  lu_attached_timestamp TIMESTAMPTZ DEFAULT NULL,
  lu_attached_method TEXT DEFAULT NULL,
  lu_local_id INT DEFAULT NULL,
  lu_global_id INT DEFAULT NULL,
  PRIMARY KEY(lu_wiki, lu_name)
);

CREATE INDEX lu_name_wiki ON localuser (lu_name, lu_wiki);


CREATE TABLE global_user_groups (
  gug_user INT NOT NULL,
  gug_group VARCHAR(255) NOT NULL,
  gug_expiry TIMESTAMPTZ DEFAULT NULL,
  PRIMARY KEY(gug_user, gug_group)
);

CREATE INDEX gug_group ON global_user_groups (gug_group);

CREATE INDEX gug_expiry ON global_user_groups (gug_expiry);


CREATE TABLE global_group_permissions (
  ggp_group VARCHAR(255) NOT NULL,
  ggp_permission VARCHAR(255) NOT NULL,
  PRIMARY KEY(ggp_group, ggp_permission)
);

CREATE INDEX ggp_permission ON global_group_permissions (ggp_permission);


CREATE TABLE wikiset (
  ws_id SERIAL NOT NULL,
  ws_name VARCHAR(255) NOT NULL,
  ws_type TEXT DEFAULT NULL,
  ws_wikis TEXT NOT NULL,
  PRIMARY KEY(ws_id)
);

CREATE UNIQUE INDEX ws_name ON wikiset (ws_name);


CREATE TABLE global_group_restrictions (
  ggr_group VARCHAR(255) NOT NULL,
  ggr_set INT NOT NULL,
  PRIMARY KEY(ggr_group)
);

CREATE INDEX ggr_set ON global_group_restrictions (ggr_set);


CREATE TABLE renameuser_status (
  ru_oldname TEXT NOT NULL, ru_newname TEXT NOT NULL,
  ru_wiki TEXT NOT NULL, ru_status TEXT DEFAULT NULL
);

CREATE UNIQUE INDEX ru_oldname ON renameuser_status (ru_oldname, ru_wiki);


CREATE TABLE renameuser_queue (
  rq_id SERIAL NOT NULL,
  rq_name TEXT NOT NULL,
  rq_wiki TEXT DEFAULT NULL,
  rq_newname TEXT NOT NULL,
  rq_reason TEXT DEFAULT NULL,
  rq_requested_ts TIMESTAMPTZ DEFAULT NULL,
  rq_status TEXT NOT NULL,
  rq_completed_ts TIMESTAMPTZ DEFAULT NULL,
  rq_deleted SMALLINT DEFAULT 0 NOT NULL,
  rq_performer INT DEFAULT NULL,
  rq_comments TEXT DEFAULT NULL,
  PRIMARY KEY(rq_id)
);

CREATE INDEX rq_oldstatus ON renameuser_queue (rq_name, rq_wiki, rq_status);

CREATE INDEX rq_newstatus ON renameuser_queue (rq_newname, rq_status);

CREATE INDEX rq_requested_ts ON renameuser_queue (rq_requested_ts);


CREATE TABLE users_to_rename (
  utr_id SERIAL NOT NULL,
  utr_name TEXT NOT NULL,
  utr_wiki TEXT NOT NULL,
  utr_status INT DEFAULT 0,
  PRIMARY KEY(utr_id)
);

CREATE UNIQUE INDEX utr_user ON users_to_rename (utr_name, utr_wiki);

CREATE INDEX utr_notif ON users_to_rename (utr_status);

CREATE INDEX utr_wiki ON users_to_rename (utr_wiki);


CREATE TABLE global_edit_count (
  gec_user INT NOT NULL,
  gec_count INT NOT NULL,
  PRIMARY KEY(gec_user)
);
