-- CRM external module. Tables are created on enable and kept on disable.

CREATE TABLE llx_crm_pipeline (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	ref varchar(32) NOT NULL,
	label varchar(255) NOT NULL,
	active smallint DEFAULT 1 NOT NULL,
	datec datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer,
	UNIQUE KEY uk_crm_pipeline_ref (entity, ref)
) ENGINE=innodb;

CREATE TABLE llx_crm_stage (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	fk_pipeline integer NOT NULL,
	label varchar(255) NOT NULL,
	position integer DEFAULT 0 NOT NULL,
	color varchar(7) DEFAULT '#6c757d',
	probability integer DEFAULT 0 NOT NULL,
	rotting_days integer DEFAULT 15 NOT NULL,
	forecast_category varchar(16) DEFAULT 'pipeline' NOT NULL,
	is_won smallint DEFAULT 0 NOT NULL,
	is_lost smallint DEFAULT 0 NOT NULL,
	active smallint DEFAULT 1 NOT NULL,
	KEY idx_crm_stage_pipeline (fk_pipeline)
) ENGINE=innodb;

CREATE TABLE llx_crm_source (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	code varchar(32) NOT NULL,
	label varchar(255) NOT NULL,
	position integer DEFAULT 0 NOT NULL,
	active smallint DEFAULT 1 NOT NULL,
	UNIQUE KEY uk_crm_source_code (entity, code)
) ENGINE=innodb;

CREATE TABLE llx_crm_loss_reason (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	code varchar(32) NOT NULL,
	label varchar(255) NOT NULL,
	position integer DEFAULT 0 NOT NULL,
	active smallint DEFAULT 1 NOT NULL,
	UNIQUE KEY uk_crm_loss_code (entity, code)
) ENGINE=innodb;

CREATE TABLE llx_crm_opportunity (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	ref varchar(32) NOT NULL,
	label varchar(255) NOT NULL,
	fk_soc integer DEFAULT NULL,
	fk_socpeople integer DEFAULT NULL,
	fk_pipeline integer NOT NULL,
	fk_stage integer NOT NULL,
	amount double(24,8) DEFAULT 0 NOT NULL,
	date_close_expected date DEFAULT NULL,
	fk_source integer DEFAULT NULL,
	fk_user_resp integer NOT NULL,
	description text,
	status varchar(16) DEFAULT 'open' NOT NULL,
	date_close datetime DEFAULT NULL,
	fk_loss_reason integer DEFAULT NULL,
	loss_note varchar(255) DEFAULT NULL,
	datec datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer,
	UNIQUE KEY uk_crm_opportunity_ref (entity, ref),
	KEY idx_crm_opportunity_stage (fk_stage),
	KEY idx_crm_opportunity_owner (fk_user_resp),
	KEY idx_crm_opportunity_soc (fk_soc),
	KEY idx_crm_opportunity_status (status)
) ENGINE=innodb;

CREATE TABLE llx_crm_stage_history (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	fk_opportunity integer NOT NULL,
	fk_stage_from integer DEFAULT NULL,
	fk_stage_to integer NOT NULL,
	fk_user integer NOT NULL,
	datec datetime NOT NULL,
	KEY idx_crm_history_opp (fk_opportunity),
	KEY idx_crm_history_to (fk_stage_to)
) ENGINE=innodb;

CREATE TABLE llx_crm_opportunity_propal (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	fk_opportunity integer NOT NULL,
	fk_propal integer NOT NULL,
	UNIQUE KEY uk_crm_opp_propal (fk_opportunity, fk_propal)
) ENGINE=innodb;

CREATE TABLE llx_crm_qualification (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	code varchar(32) NOT NULL,
	label varchar(255) NOT NULL,
	points integer DEFAULT 0 NOT NULL,
	position integer DEFAULT 0 NOT NULL,
	active smallint DEFAULT 1 NOT NULL,
	UNIQUE KEY uk_crm_qual_code (entity, code)
) ENGINE=innodb;

CREATE TABLE llx_crm_opportunity_qualification (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	fk_opportunity integer NOT NULL,
	fk_qualification integer NOT NULL,
	answer smallint DEFAULT 0 NOT NULL,
	datec datetime NOT NULL,
	fk_user integer NOT NULL,
	UNIQUE KEY uk_crm_opp_qual (fk_opportunity, fk_qualification)
) ENGINE=innodb;

CREATE TABLE llx_crm_sequence (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	ref varchar(32) NOT NULL,
	label varchar(255) NOT NULL,
	active smallint DEFAULT 1 NOT NULL,
	stop_on_reply smallint DEFAULT 1 NOT NULL,
	stop_on_won smallint DEFAULT 1 NOT NULL,
	stop_on_lost smallint DEFAULT 1 NOT NULL,
	datec datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer,
	UNIQUE KEY uk_crm_sequence_ref (entity, ref)
) ENGINE=innodb;

CREATE TABLE llx_crm_sequence_step (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	fk_sequence integer NOT NULL,
	position integer DEFAULT 1 NOT NULL,
	action varchar(16) NOT NULL,
	delay_days integer DEFAULT 0 NOT NULL,
	subject varchar(255) DEFAULT NULL,
	body text,
	send_mode varchar(8) DEFAULT '' NOT NULL,
	KEY idx_crm_step_seq (fk_sequence)
) ENGINE=innodb;

CREATE TABLE llx_crm_sequence_enrol (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	fk_sequence integer NOT NULL,
	fk_opportunity integer NOT NULL,
	date_enrol datetime NOT NULL,
	current_step integer DEFAULT 1 NOT NULL,
	date_next datetime DEFAULT NULL,
	status varchar(16) DEFAULT 'running' NOT NULL,
	stop_reason varchar(32) DEFAULT NULL,
	KEY idx_crm_enrol_seq (fk_sequence),
	KEY idx_crm_enrol_opp (fk_opportunity),
	KEY idx_crm_enrol_next (status, date_next)
) ENGINE=innodb;

CREATE TABLE llx_crm_sequence_log (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	fk_enrol integer NOT NULL,
	fk_step integer DEFAULT NULL,
	date_send datetime NOT NULL,
	email varchar(255) DEFAULT NULL,
	subject varchar(255) DEFAULT NULL,
	status varchar(16) NOT NULL,
	error_msg varchar(255) DEFAULT NULL,
	from_email varchar(255) DEFAULT NULL,
	body text,
	body_format varchar(8) DEFAULT '' NOT NULL,
	step_position integer DEFAULT NULL,
	content_source varchar(16) DEFAULT '' NOT NULL,
	KEY idx_crm_seqlog_enrol (fk_enrol)
) ENGINE=innodb;

CREATE TABLE llx_crm_reminder_log (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	fk_user integer NOT NULL,
	date_day date NOT NULL,
	date_send datetime NOT NULL,
	status varchar(16) NOT NULL,
	error_msg varchar(255) DEFAULT NULL,
	UNIQUE KEY uk_crm_reminder_day (entity, fk_user, date_day)
) ENGINE=innodb;
