SET NAMES 'utf8';
DROP TABLE IF EXISTS okapi_consumers;
CREATE TABLE okapi_consumers (
	`key` varchar(20) charset ascii collate ascii_bin NOT NULL,
	name varchar(100) collate utf8_general_ci NOT NULL,
	secret varchar(40) charset ascii collate ascii_bin NOT NULL,
	url varchar(250) collate utf8_general_ci default NULL,
	email varchar(70) collate utf8_general_ci default NULL,
	date_created datetime NOT NULL,
	PRIMARY KEY  (`key`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;
