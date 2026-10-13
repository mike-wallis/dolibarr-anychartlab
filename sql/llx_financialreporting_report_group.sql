-- Financial Reporting: wizard data for each personalised group (llx_c_accounting_category) of a
-- wizard-built report: the template section it belongs to and the natures its prefix rules take.
-- The prefix rules themselves are kept in llx_c_accounting_category.range_account.
CREATE TABLE llx_financialreporting_report_group (
  rowid          integer AUTO_INCREMENT PRIMARY KEY,
  fk_category    integer NOT NULL,              -- llx_c_accounting_category.rowid
  fk_report      integer NOT NULL,              -- llx_c_accounting_report.rowid
  section        varchar(32) NOT NULL,          -- section code from the template
  section_label  varchar(255) NOT NULL,
  section_type   varchar(16) NOT NULL,          -- INCOME, EXPENSE (later ASSET, LIABILITY, EQUITY)
  natures        varchar(128) DEFAULT NULL      -- natures the prefix rules take, e.g. INCOME|EXPENSE
) ENGINE=innodb;
