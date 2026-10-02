-- Any-Chart Reports Lab (prototype): accounts explicitly assigned to a layout group line.
CREATE TABLE llx_anychartlab_layout_account (
  rowid                 integer AUTO_INCREMENT PRIMARY KEY,
  fk_layout_line        integer NOT NULL,
  fk_accounting_account bigint NOT NULL
) ENGINE=innodb;
