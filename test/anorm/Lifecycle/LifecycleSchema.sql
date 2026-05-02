CREATE TABLE `lifecycle_model` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` varchar(128) NOT NULL,
  `email` varchar(128) NULL,
  `dtu` datetime NULL,
  `payload` varchar(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
