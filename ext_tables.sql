#
# Table structure for table 'tx_pushnotification_tokens'
#
CREATE TABLE tx_pushnotification_tokens (
  token varchar(160) NOT NULL DEFAULT '',
  user_id int(10) unsigned NOT NULL DEFAULT '0',

  PRIMARY KEY (token),
  KEY idx_user (user_id)
) ENGINE=InnoDB;
