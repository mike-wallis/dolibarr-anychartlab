-- Any-Chart Reports Lab (prototype): nature of each accounting account.
-- One row per llx_accounting_account. No core table is altered.
CREATE TABLE llx_anychartlab_nature (
  rowid                 integer AUTO_INCREMENT PRIMARY KEY,
  entity                integer DEFAULT 1 NOT NULL,
  fk_accounting_account bigint NOT NULL,           -- llx_accounting_account.rowid
  nature                varchar(16) DEFAULT NULL,  -- ASSET, LIABILITY, EQUITY, INCOME, EXPENSE, CLEARING, EXCLUDED; NULL = unclassified
  nature_alt            varchar(16) DEFAULT NULL,  -- nature used when the balance is on the opposite side (Tryton-style)
  contra                tinyint DEFAULT 0 NOT NULL, -- 1 = contra account (stays on its nature's side, shown as a deduction)
  source                varchar(16) DEFAULT NULL,  -- chart, generic, parent, manual, import
  cf_class              varchar(16) DEFAULT NULL,  -- cash flow class set by hand: CASH, OPERATING, INVESTING, FINANCING; NULL = suggested
  tms                   timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  fk_user_modif         integer DEFAULT NULL
) ENGINE=innodb;
