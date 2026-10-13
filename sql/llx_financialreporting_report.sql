-- Financial Reporting: Dolibarr personalised reports (llx_c_accounting_report) created by the
-- setup wizard. Core has no column for the statement type, so it is recorded here.
CREATE TABLE llx_financialreporting_report (
  rowid          integer AUTO_INCREMENT PRIMARY KEY,
  entity         integer DEFAULT 1 NOT NULL,
  fk_report      integer NOT NULL,              -- llx_c_accounting_report.rowid
  statement      varchar(2) NOT NULL,           -- IS (income statement) or BS (balance sheet)
  template       varchar(64) DEFAULT NULL,      -- template file the report was built from
  datec          datetime DEFAULT NULL,
  fk_user_creat  integer DEFAULT NULL,
  tms            timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
