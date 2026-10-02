-- Any-Chart Reports Lab (prototype): lines of a statement layout.
CREATE TABLE llx_anychartlab_layout_line (
  rowid      integer AUTO_INCREMENT PRIMARY KEY,
  fk_layout  integer NOT NULL,
  position   integer DEFAULT 0 NOT NULL,
  code       varchar(32) NOT NULL,
  label      varchar(255) NOT NULL,
  type       varchar(16) NOT NULL,           -- heading, group, formula
  natures    varchar(128) DEFAULT NULL,      -- group: natures it takes, e.g. ASSET or INCOME|EXPENSE (empty = any)
  accounts   varchar(255) DEFAULT NULL,      -- group: account prefixes, e.g. 11,12,!1301,512D (D/C = only when in debit/credit, ! = exclude)
  formula    varchar(255) DEFAULT NULL,      -- formula: e.g. REVENUE - COGS (codes of earlier lines, RESULT)
  sign       varchar(8) DEFAULT 'natural' NOT NULL -- natural (by nature), cd (credit - debit), dc (debit - credit)
) ENGINE=innodb;
