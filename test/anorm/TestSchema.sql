# USE `anorm_test`;
# DROP TABLE `some_table` IF EXISTS;
CREATE TABLE `some_table` (
  `some_id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` varchar(128) NOT NULL,
  `dtc` date NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
