-- Financial Reporting: the user's own rules ("My rules", Setup > Rule sets). Applied before the
-- rule sets shipped with the module, and like them only to accounts with no nature / no class yet.
--   kind = nature : sets nature, nature_alt, contra
--   kind = class  : sets class; natures limits which accounts it applies to
-- Match columns: several values separated by |, empty = any (same format as seed/*.csv).
CREATE TABLE llx_financialreporting_rule (
  rowid          integer AUTO_INCREMENT PRIMARY KEY,
  entity         integer DEFAULT 1 NOT NULL,
  kind           varchar(8) NOT NULL,            -- nature or class
  position       integer DEFAULT 0 NOT NULL,     -- first match wins, in position order
  pcg_type       varchar(128) DEFAULT NULL,      -- match: account group (pcg_type)
  account_prefix varchar(255) DEFAULT NULL,      -- match: account number starts with
  label_contains varchar(255) DEFAULT NULL,      -- match: words in the account label
  natures        varchar(128) DEFAULT NULL,      -- class rules: natures it applies to
  nature         varchar(16) DEFAULT NULL,       -- nature rules: what it sets
  nature_alt     varchar(16) DEFAULT NULL,
  contra         tinyint DEFAULT 0 NOT NULL,
  class          varchar(32) DEFAULT NULL,       -- class rules: what it sets
  comment        varchar(255) DEFAULT NULL,
  active         tinyint DEFAULT 1 NOT NULL,
  tms            timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  fk_user_modif  integer DEFAULT NULL
) ENGINE=innodb;
