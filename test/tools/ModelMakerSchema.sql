# USE `anorm_test`;
# DROP TABLE `model_test` IF EXISTS;
CREATE TABLE `model_test` (
  `some_id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` varchar(128) NOT NULL,
  `dtc` date NOT NULL,
  `owner_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT NULL,
  `quota_bytes` bigint(20) DEFAULT NULL,
  `rate` decimal(10,2) DEFAULT NULL,
  `notes` text
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
