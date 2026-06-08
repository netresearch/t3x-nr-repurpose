CREATE TABLE tx_nrrepurpose_domain_model_job (
    source_type varchar(16) DEFAULT 'url' NOT NULL,
    source_value text,
    theme varchar(16) DEFAULT 'nr' NOT NULL,
    want_podcast smallint unsigned DEFAULT 1 NOT NULL,
    want_schaubild smallint unsigned DEFAULT 1 NOT NULL,
    want_story smallint unsigned DEFAULT 1 NOT NULL,
    status varchar(16) DEFAULT 'queued' NOT NULL,
    progress int unsigned DEFAULT 0 NOT NULL,
    current_step varchar(255) DEFAULT '' NOT NULL,
    error_message text,
    language_detected varchar(16) DEFAULT '' NOT NULL,
    be_user int unsigned DEFAULT 0 NOT NULL,
    artifacts int unsigned DEFAULT 0 NOT NULL
);

CREATE TABLE tx_nrrepurpose_domain_model_artifact (
    job int unsigned DEFAULT 0 NOT NULL,
    type varchar(16) DEFAULT '' NOT NULL,
    variant varchar(16) DEFAULT 'default' NOT NULL,
    file_uid int unsigned DEFAULT 0 NOT NULL,
    subtitle_file_uid int unsigned DEFAULT 0 NOT NULL,
    source_html mediumtext,
    script_text mediumtext,
    status varchar(16) DEFAULT 'pending' NOT NULL,
    error_message text,
    metadata text
);
