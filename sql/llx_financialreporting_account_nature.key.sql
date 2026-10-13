ALTER TABLE llx_financialreporting_account_nature ADD UNIQUE INDEX uk_financialreporting_account_nature (entity, fk_accounting_account);
-- cash flow class (installs made before it existed get the column when the module is disabled and enabled again)
ALTER TABLE llx_financialreporting_account_nature ADD COLUMN cf_class varchar(16) DEFAULT NULL AFTER source;
-- account class (installs made before it existed get the columns when the module is disabled and enabled again)
ALTER TABLE llx_financialreporting_account_nature ADD COLUMN class varchar(32) DEFAULT NULL AFTER cf_class;
ALTER TABLE llx_financialreporting_account_nature ADD COLUMN class_source varchar(16) DEFAULT NULL AFTER class;
