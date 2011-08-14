SET NAMES 'utf8';
DROP TABLE IF EXISTS okapi_tokens;
CREATE TABLE okapi_tokens (
	`key` varchar(20) charset ascii collate ascii_bin NOT NULL,
	secret varchar(40) charset ascii collate ascii_bin NOT NULL,
	token_type enum('request','access') NOT NULL,
	timestamp int(10) NOT NULL,
	user_id int(10) default NULL,
	consumer_key varchar(20) charset ascii collate ascii_bin NOT NULL,
	verifier varchar(10) charset ascii collate ascii_bin default NULL,
	callback varchar(2083) character set utf8 collate utf8_general_ci default NULL,
	PRIMARY KEY  (`key`),
	KEY by_consumer (consumer_key)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;
