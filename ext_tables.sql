#
# Table structure for table 'tx_pgubrofuxextras_broken_links'
#
CREATE TABLE tx_pgubrofuxextras_broken_links (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,
    crdate int(11) DEFAULT '0' NOT NULL,
    tstamp int(11) DEFAULT '0' NOT NULL,
    deleted tinyint(4) DEFAULT '0' NOT NULL,
    hidden tinyint(4) DEFAULT '0' NOT NULL,

    record_uid int(11) DEFAULT '0' NOT NULL,
    table_name varchar(255) DEFAULT '' NOT NULL,
    field varchar(255) DEFAULT '' NOT NULL,
    flexform_field varchar(255) DEFAULT '' NOT NULL,
    flexform_field_label text,
    link_type varchar(255) DEFAULT '' NOT NULL,
    link_title text,
    url text,
    url_hash varchar(40) DEFAULT '' NOT NULL, # sha1 hash of URL
    url_response text, # JSON response from LinkTargetResponse
    check_status int(11) DEFAULT '0' NOT NULL,
    last_check_url int(11) DEFAULT '0' NOT NULL, # Timestamp of when the URL itself was last checked
    last_check int(11) DEFAULT '0' NOT NULL, # Timestamp of when this specific record entry was last processed/checked
    headline text,
    language int(11) DEFAULT '0' NOT NULL,
    element_type varchar(255) DEFAULT '' NOT NULL, # e.g., CType for tt_content
    exclude_link_targets_pid int(11) DEFAULT '0' NOT NULL, # PID of the exclude rules storage

    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY record_ident (record_uid,table_name(191),field(191),url_hash),
    KEY url_hash (url_hash),
    KEY check_status (check_status),
    KEY link_type (link_type(191))
);

#
# Table structure for table 'tx_pgubrofuxextras_exclude_link_target'
#
CREATE TABLE tx_pgubrofuxextras_exclude_link_target (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,
    crdate int(11) DEFAULT '0' NOT NULL,
    tstamp int(11) DEFAULT '0' NOT NULL,
    deleted tinyint(4) DEFAULT '0' NOT NULL,
    hidden tinyint(4) DEFAULT '0' NOT NULL,

    linktarget text,
    link_type varchar(100) DEFAULT '' NOT NULL,
    matchtype varchar(50) DEFAULT 'exact' NOT NULL, # 'exact' or 'domain'
    reason int(11) DEFAULT '0' NOT NULL,
    comment text,

    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY link_type_match (link_type(99),matchtype(49))
);

#
# Table structure for table 'tx_pgubrofuxextras_link_target_cache'
#
CREATE TABLE tx_pgubrofuxextras_link_target_cache (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL, # Should typically be 0 for cache table unless specific partitioning is intended
    crdate int(11) DEFAULT '0' NOT NULL,
    tstamp int(11) DEFAULT '0' NOT NULL,

    url text,
    url_hash varchar(40) DEFAULT '' NOT NULL, # sha1 hash of URL
    link_type varchar(100) DEFAULT '' NOT NULL,
    url_response mediumtext, # JSON response, can be large
    check_status int(11) DEFAULT '0' NOT NULL, # Status from LinkTargetResponse
    last_check int(11) DEFAULT '0' NOT NULL, # Timestamp of last check

    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY url_hash_link_type (url_hash,link_type(99)),
    KEY last_check (last_check)
);
