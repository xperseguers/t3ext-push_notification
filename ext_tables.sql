#
# Table structure for table 'tx_pushnotification_tokens'
#
CREATE TABLE tx_pushnotification_tokens (
  token varchar(160) NOT NULL DEFAULT '',
  user_id int(10) unsigned NOT NULL DEFAULT '0',
  mode char(1) NOT NULL DEFAULT 'P',
  tstamp int(11) unsigned DEFAULT '0' NOT NULL,

  PRIMARY KEY (token),
  KEY idx_user (user_id)
) ENGINE=InnoDB;
