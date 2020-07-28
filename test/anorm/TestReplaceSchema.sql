# USE `anorm_test`;
# DROP TABLE `replace_table` IF EXISTS;
CREATE TABLE `replace_table` (
  `replace_id` varchar(32) NOT NULL,
  `name` varchar(128) NOT NULL,
  `dtc` date NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
ALTER TABLE `replace_table`
  ADD UNIQUE KEY `replace_id` (`replace_id`);
