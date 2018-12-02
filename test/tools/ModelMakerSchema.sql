# USE `anorm_test`;
# DROP TABLE `model_test` IF EXISTS;
CREATE TABLE `model_test` (
  `some_id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` varchar(128) NOT NULL,
  `dtc` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
