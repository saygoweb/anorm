# Extended test schema for relationship testing
# USE `anorm_test`;

# Drop tables if they exist (in reverse order due to foreign keys)
DROP TABLE IF EXISTS `post_tags`;
DROP TABLE IF EXISTS `comments`;
DROP TABLE IF EXISTS `posts`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `tags`;
DROP TABLE IF EXISTS `companies`;

# Companies table
CREATE TABLE `companies` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` varchar(255) NOT NULL,
  `address` text NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

# Users table
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` varchar(128) NOT NULL,
  `email` varchar(128) NOT NULL,
  `company_id` int(11) NULL,
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

# Posts table
CREATE TABLE `posts` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `title` varchar(255) NOT NULL,
  `content` text,
  `user_id` int(11) NOT NULL,
  `status` varchar(32) DEFAULT 'draft',
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

# Comments table
CREATE TABLE `comments` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `content` text NOT NULL,
  `user_id` int(11) NOT NULL,
  `post_id` int(11) NOT NULL,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`),
  FOREIGN KEY (`post_id`) REFERENCES `posts`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

# Tags table
CREATE TABLE `tags` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` varchar(128) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

# Post-Tags join table (many-to-many)
CREATE TABLE `post_tags` (
  `post_id` int(11) NOT NULL,
  `tag_id` int(11) NOT NULL,
  PRIMARY KEY (`post_id`, `tag_id`),
  FOREIGN KEY (`post_id`) REFERENCES `posts`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`tag_id`) REFERENCES `tags`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

# Insert test data
INSERT INTO `companies` (`id`, `name`, `address`) VALUES
(1, 'Tech Corp', '123 Tech Street'),
(2, 'Design Inc', '456 Design Avenue');

INSERT INTO `users` (`id`, `name`, `email`, `company_id`) VALUES
(1, 'John Doe', 'john@example.com', 1),
(2, 'Jane Smith', 'jane@example.com', 1),
(3, 'Bob Wilson', 'bob@example.com', 2);

INSERT INTO `posts` (`id`, `title`, `content`, `user_id`, `status`) VALUES
(1, 'First Post', 'This is the first post content', 1, 'published'),
(2, 'Second Post', 'This is the second post content', 1, 'draft'),
(3, 'Third Post', 'This is the third post content', 2, 'published');

INSERT INTO `comments` (`id`, `content`, `user_id`, `post_id`) VALUES
(1, 'Great post!', 2, 1),
(2, 'Thanks for sharing', 3, 1),
(3, 'Looking forward to more', 1, 3);

INSERT INTO `tags` (`id`, `name`) VALUES
(1, 'technology'),
(2, 'programming'),
(3, 'design');

INSERT INTO `post_tags` (`post_id`, `tag_id`) VALUES
(1, 1),
(1, 2),
(2, 2),
(3, 3);
