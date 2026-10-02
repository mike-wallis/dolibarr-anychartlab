-- Any-Chart Reports Lab (prototype): statement layouts (own copy, core personalised groups are only read).
CREATE TABLE llx_anychartlab_layout (
  rowid         integer AUTO_INCREMENT PRIMARY KEY,
  entity        integer DEFAULT 1 NOT NULL,
  code          varchar(32) NOT NULL,
  label         varchar(255) NOT NULL,
  statement     varchar(2) NOT NULL,          -- BS (balance sheet) or IS (income statement)
  country_code  varchar(8) DEFAULT NULL,
  source        varchar(16) DEFAULT NULL,     -- core (copied from personalised groups), file, import, manual
  source_ref    varchar(255) DEFAULT NULL,
  tms           timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
