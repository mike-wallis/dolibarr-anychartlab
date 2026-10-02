ALTER TABLE llx_anychartlab_nature ADD UNIQUE INDEX uk_anychartlab_nature_account (entity, fk_accounting_account);
-- cash flow class (installs made before it existed get the column when the module is disabled and enabled again)
ALTER TABLE llx_anychartlab_nature ADD COLUMN cf_class varchar(16) DEFAULT NULL AFTER source;
