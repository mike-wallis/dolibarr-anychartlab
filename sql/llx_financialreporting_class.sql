-- Financial Reporting: the user's class structure (Getting started, step 2).
--   kind = type : label of one of the fixed account types (ASSET, LIABILITY…), code = the type
--   kind = class, base_code NULL : label / hidden flag of a base class (code = base class code)
--   kind = class, base_code set  : a class added by the user under a base class (code U_…)
-- Base types and classes are fixed in lib/classes.lib.php; only labels, hiding and added
-- classes are stored here, so templates and reports can always rely on the base codes.
CREATE TABLE llx_financialreporting_class (
  rowid          integer AUTO_INCREMENT PRIMARY KEY,
  entity         integer DEFAULT 1 NOT NULL,
  kind           varchar(8) NOT NULL,            -- type or class
  code           varchar(32) NOT NULL,
  base_code      varchar(32) DEFAULT NULL,       -- added classes: the base class they belong to
  label          varchar(255) DEFAULT NULL,      -- NULL = translated default label
  hidden         tinyint DEFAULT 0 NOT NULL,
  position       integer DEFAULT 0 NOT NULL,
  tms            timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  fk_user_modif  integer DEFAULT NULL
) ENGINE=innodb;
