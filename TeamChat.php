-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 12, 2026 at 09:32 PM
-- Server version: 10.4.28-MariaDB
-- PHP Version: 8.2.4

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `squidpro`
--

-- --------------------------------------------------------

--
-- Table structure for table `ai_coach_logs`
--

CREATE TABLE `ai_coach_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `prompt` text NOT NULL,
  `response` text NOT NULL,
  `tokens_used` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ai_coach_logs`
--

INSERT INTO `ai_coach_logs` (`id`, `user_id`, `prompt`, `response`, `tokens_used`, `created_at`) VALUES
(1, 1, 'How to improve defense?', 'Try using jockey and timing.', 50, '2026-04-01 08:55:23'),
(2, 1, 'Coach, I want to get better at RPS. Can you teach me how to predict my opponent’s patterns and improve my win rate?', 'Sure! في لعبة RPS، المراوغة والتوقع مهمين جدًا. إليك بعض النصائح لتحسين فرصك في الفوز:\n\n1. **راقب أنماط الخصم**: حاول ملاحظة إذا كان يميل لاستخدام حجر، ورقة، أو مقص بشكل متكرر. سجل ملاحظاتك أثناء اللعب.\n2. **تغيير استراتيجيتك**: لا تكن متوقعًا، غير أسلوبك بين الحين والآخر حتى تشتت انتباه الخصم.\n3. **استخدم التوقع النفسي**: إذا لاحظت أن الخصم يبدأ غالبًا بحجر، حاول أن تختار ورقة أو مقص بشكل متكرر لمفاجأته.\n4. **اللعب على التوقع**: بعد فوز، حاول أن تتوقع رد فعل الخصم، وغالبًا ما يكرر نفس النمط مرة أخرى.\n5. **تمرن على قراءة الخصم**: العب مع أصدقاء أو عبر الإنترنت وركز على أنماطهم، ثم حاول استغلالها لصالحك.\n\nهل تريد تمارين معينة أو لعب تجريبي لتحسين مهارتك؟', 324, '2026-04-01 10:03:13'),
(3, 1, 'Coach, I need help improving my tennis performance. Can you give me tips on positioning, timing, and how to stay consistent during matches?', 'Of course! Here are some practical tips:\n\n**Positioning:**  \n- Always try to stay balanced and on the balls of your feet.  \n- Keep your racket ready and head at the same level as the ball.  \n- Move early to get into optimal position before the shot.\n\n**Timing:**  \n- Watch the ball closely and anticipate your opponent’s shot.  \n- Practice shadow swings to improve your reaction time.  \n- Focus on contact point—hit the ball at the peak of your swing for consistency.\n\n**Staying Consistent:**  \n- Keep a steady rhythm; don’t rush the shot.  \n- Use a controlled, smooth swing rather than power for accuracy.  \n- Stay relaxed, breathe regularly, and maintain focus throughout the match.\n\nWould you like specific drills for practice?', 249, '2026-04-01 10:04:06');

-- --------------------------------------------------------

--
-- Table structure for table `calls`
--

CREATE TABLE `calls` (
  `id` int(11) NOT NULL,
  `host_id` int(11) NOT NULL,
  `game_id` int(11) DEFAULT NULL,
  `title` varchar(150) DEFAULT NULL,
  `call_code` varchar(20) DEFAULT NULL,
  `status` enum('open','in_progress','closed') DEFAULT 'open',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `calls`
--

INSERT INTO `calls` (`id`, `host_id`, `game_id`, `title`, `call_code`, `status`, `created_at`) VALUES
(1, 1, 1, 'FIFA Training Call', 'ABC123', 'open', '2026-04-01 08:55:23');

-- --------------------------------------------------------

--
-- Table structure for table `call_participants`
--

CREATE TABLE `call_participants` (
  `id` int(11) NOT NULL,
  `call_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `joined_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chats`
--

CREATE TABLE `chats` (
  `id` int(11) NOT NULL,
  `type` enum('global','team','private','call') DEFAULT 'global',
  `team_id` int(11) DEFAULT NULL,
  `call_id` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `chats`
--

INSERT INTO `chats` (`id`, `type`, `team_id`, `call_id`, `created_at`) VALUES
(77, 'team', 10, NULL, '2026-04-10 18:38:27'),
(78, 'team', 11, NULL, '2026-04-10 18:50:40');

-- --------------------------------------------------------

--
-- Table structure for table `chat_messages`
--

CREATE TABLE `chat_messages` (
  `id` int(11) NOT NULL,
  `chat_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_participants`
--

CREATE TABLE `chat_participants` (
  `id` int(11) NOT NULL,
  `chat_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `joined_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `chat_participants`
--

INSERT INTO `chat_participants` (`id`, `chat_id`, `user_id`, `joined_at`) VALUES
(148, 77, 1, '2026-04-10 18:38:27'),
(149, 77, 2, '2026-04-10 18:39:19'),
(150, 77, 3, '2026-04-10 18:39:38'),
(151, 78, 4, '2026-04-10 18:50:40'),
(152, 78, 5, '2026-04-10 18:51:06'),
(153, 78, 6, '2026-04-10 18:51:31');

-- --------------------------------------------------------

--
-- Table structure for table `events`
--

CREATE TABLE `events` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `organizer_id` int(11) NOT NULL,
  `game_id` int(11) DEFAULT NULL,
  `location_name` varchar(150) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `start_date` datetime DEFAULT NULL,
  `description` text DEFAULT NULL,
  `status` enum('pending','approved','rejected','completed') DEFAULT 'pending',
  `created_at` datetime DEFAULT current_timestamp(),
  `location_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `events`
--

INSERT INTO `events` (`id`, `name`, `organizer_id`, `game_id`, `location_name`, `latitude`, `longitude`, `start_date`, `description`, `status`, `created_at`, `location_id`) VALUES
(1, 'Padel Masters Riyadh', 11, 3, 'Padel Zone', 24.7743000, 46.7386000, '2026-02-20 17:00:00', 'Top padel event.', 'approved', '2026-04-01 08:55:22', 2);

-- --------------------------------------------------------

--
-- Table structure for table `games`
--

CREATE TABLE `games` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `icon` varchar(10) DEFAULT NULL,
  `game_url` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `games`
--

INSERT INTO `games` (`id`, `name`, `icon`, `game_url`, `description`, `is_active`) VALUES
(1, 'Rock Paper Scissors', 'RPS.jpg', NULL, 'Classic RPS competitive game', 1),
(2, 'FIFA', 'fifa.jpg', NULL, 'FIFA Game', 1),
(3, 'Padel', 'tenese.jpg', NULL, 'Real-life Padel sport', 1);

-- --------------------------------------------------------

--
-- Table structure for table `locations`
--

CREATE TABLE `locations` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `type` enum('padel_court','arena','club','other') DEFAULT 'other',
  `latitude` decimal(10,7) NOT NULL,
  `longitude` decimal(10,7) NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `locations`
--

INSERT INTO `locations` (`id`, `name`, `type`, `latitude`, `longitude`, `address`, `created_at`) VALUES
(1, 'Riyadh Arena', 'arena', 24.7136000, 46.6753000, 'King Fahad Road', '2026-04-01 08:55:21'),
(2, 'Padel Zone', 'padel_court', 24.7743000, 46.7386000, 'Exit 9', '2026-04-01 08:55:21'),
(3, 'Elite Club', 'club', 24.8000000, 46.7500000, 'North Riyadh', '2026-04-01 08:55:21');

-- --------------------------------------------------------

--
-- Table structure for table `matches`
--

CREATE TABLE `matches` (
  `id` int(11) NOT NULL,
  `tournament_id` int(11) DEFAULT NULL,
  `game_id` int(11) NOT NULL,
  `team1_id` int(11) NOT NULL,
  `team2_id` int(11) NOT NULL,
  `score_team1` varchar(20) DEFAULT NULL,
  `score_team2` varchar(20) DEFAULT NULL,
  `status` enum('pending','accepted','rejected','ongoing','finished') DEFAULT 'pending',
  `match_date` datetime DEFAULT NULL,
  `winner_team_id` int(11) DEFAULT NULL,
  `organizer_id` int(11) DEFAULT NULL,
  `team_id` int(11) DEFAULT NULL,
  `opponent_team_id` int(11) DEFAULT NULL,
  `team_score` int(11) DEFAULT NULL,
  `opponent_score` int(11) DEFAULT NULL,
  `responded_at` datetime DEFAULT NULL,
  `responded_by` int(11) DEFAULT NULL,
  `scheduled_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `matches`
--

INSERT INTO `matches` (`id`, `tournament_id`, `game_id`, `team1_id`, `team2_id`, `score_team1`, `score_team2`, `status`, `match_date`, `winner_team_id`, `organizer_id`, `team_id`, `opponent_team_id`, `team_score`, `opponent_score`, `responded_at`, `responded_by`, `scheduled_at`, `created_at`) VALUES
(2, 3, 3, 10, 11, NULL, NULL, 'accepted', NULL, NULL, 11, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-13 18:53:00', '2026-04-10 18:53:52');

-- --------------------------------------------------------

--
-- Table structure for table `reports`
--

-- --------------------------------------------------------

--
-- Table structure for table `match_player_results`
--

CREATE TABLE `match_player_results` (
  `id` int(11) NOT NULL,
  `match_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `team_id` int(11) NOT NULL,
  `game_id` int(11) NOT NULL,
  `score_for` int(11) NOT NULL,
  `score_against` int(11) NOT NULL,
  `result` enum('win','loss','draw') NOT NULL,
  `submitted_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Indexes for table `match_player_results`
--

ALTER TABLE `match_player_results`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_match_user` (`match_id`,`user_id`);

-- --------------------------------------------------------

--
-- AUTO_INCREMENT for table `match_player_results`
--

ALTER TABLE `match_player_results`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` varchar(50) NOT NULL,
  `title` varchar(150) NOT NULL,
  `body` text DEFAULT NULL,
  `match_id` int(11) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Indexes for table `notifications`
--

ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_user_match_type` (`user_id`,`match_id`,`type`);

-- --------------------------------------------------------

--
-- AUTO_INCREMENT for table `notifications`
--

ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- --------------------------------------------------------

--
-- Table structure for table `training_progress`
--

CREATE TABLE `training_progress` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `game_id` int(11) NOT NULL,
  `mode` varchar(20) NOT NULL,
  `sessions` int(11) DEFAULT 0,
  `best_score_for` int(11) DEFAULT 0,
  `best_score_against` int(11) DEFAULT 0,
  `last_played` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `training_progress`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_user_game_mode` (`user_id`,`game_id`,`mode`);

ALTER TABLE `training_progress`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- --------------------------------------------------------

--
-- Indexes for table `match_player_results`
--

ALTER TABLE `match_player_results`
  ADD KEY `idx_match_player_user` (`user_id`);

CREATE TABLE `reports` (
  `id` int(11) NOT NULL,
  `reporter_id` int(11) NOT NULL,
  `reported_user_id` int(11) DEFAULT NULL,
  `match_id` int(11) DEFAULT NULL,
  `issue_type` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `status` enum('open','in_review','resolved','banned') DEFAULT 'open',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rewards`
--

CREATE TABLE `rewards` (
  `id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `points_cost` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `rewards`
--

INSERT INTO `rewards` (`id`, `title`, `description`, `points_cost`, `is_active`) VALUES
(1, '10% Discount', 'Discount on next event', 100, 1),
(2, 'VIP Badge', 'Special profile badge', 200, 1);

-- --------------------------------------------------------

--
-- Table structure for table `teams`
--

CREATE TABLE `teams` (
  `id` int(11) NOT NULL,
  `owner_id` int(11) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `game_id` int(11) NOT NULL,
  `captain_id` int(11) NOT NULL,
  `logo_url` varchar(255) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `icon` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `teams`
--

INSERT INTO `teams` (`id`, `owner_id`, `name`, `description`, `game_id`, `captain_id`, `logo_url`, `bio`, `created_at`, `icon`) VALUES
(10, 1, 'team1', 'dsfsfdsf', 3, 1, NULL, NULL, '2026-04-10 18:38:27', NULL),
(11, 4, 'team2', 'dsfdsfsd', 3, 4, NULL, NULL, '2026-04-10 18:50:40', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `team_join_requests`
--

CREATE TABLE `team_join_requests` (
  `id` int(11) NOT NULL,
  `team_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `main_game` varchar(100) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `created_at` datetime DEFAULT current_timestamp(),
  `processed_at` datetime DEFAULT NULL,
  `processed_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `team_members`
--

CREATE TABLE `team_members` (
  `id` int(11) NOT NULL,
  `team_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role` enum('member','co-captain','captain','owner') DEFAULT 'member',
  `joined_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `team_members`
--

INSERT INTO `team_members` (`id`, `team_id`, `user_id`, `role`, `joined_at`) VALUES
(14, 10, 1, 'captain', '2026-04-10 18:38:27'),
(15, 10, 2, 'member', '2026-04-10 18:39:19'),
(16, 10, 3, 'member', '2026-04-10 18:39:38'),
(17, 11, 4, 'captain', '2026-04-10 18:50:40'),
(18, 11, 5, 'member', '2026-04-10 18:51:06'),
(19, 11, 6, 'member', '2026-04-10 18:51:31');

-- --------------------------------------------------------

--
-- Table structure for table `tournaments`
--

CREATE TABLE `tournaments` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `game_id` int(11) NOT NULL,
  `organizer_id` int(11) NOT NULL,
  `format` varchar(50) DEFAULT NULL,
  `start_date` datetime DEFAULT NULL,
  `description` text DEFAULT NULL,
  `status` enum('pending','validated','rejected','ongoing','finished') DEFAULT 'pending',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tournaments`
--

INSERT INTO `tournaments` (`id`, `name`, `game_id`, `organizer_id`, `format`, `start_date`, `description`, `status`, `created_at`) VALUES
(3, 'tour1', 3, 11, 'saddas', '2026-04-10 18:47:00', 'sadsadsa', 'pending', '2026-04-10 18:47:48');

-- --------------------------------------------------------

--
-- Table structure for table `tournament_games`
--

CREATE TABLE `tournament_games` (
  `id` int(11) NOT NULL,
  `tournament_id` int(11) NOT NULL,
  `game_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tournament_games`
--

INSERT INTO `tournament_games` (`id`, `tournament_id`, `game_id`) VALUES
(3, 3, 3);

-- --------------------------------------------------------

--
-- Table structure for table `tournament_game_requests`
--

CREATE TABLE `tournament_game_requests` (
  `id` int(11) NOT NULL,
  `tournament_id` int(11) NOT NULL,
  `team_id` int(11) NOT NULL,
  `game_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `message` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `created_at` datetime DEFAULT current_timestamp(),
  `processed_at` datetime DEFAULT NULL,
  `processed_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tournament_game_requests`
--

INSERT INTO `tournament_game_requests` (`id`, `tournament_id`, `team_id`, `game_id`, `user_id`, `message`, `status`, `created_at`, `processed_at`, `processed_by`) VALUES
(3, 3, 10, 3, 1, 'sadfasdsa', 'pending', '2026-04-10 18:48:52', NULL, NULL),
(4, 3, 11, 3, 4, 'sdasd', 'pending', '2026-04-10 18:52:36', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `tournament_teams`
--

CREATE TABLE `tournament_teams` (
  `id` int(11) NOT NULL,
  `tournament_id` int(11) NOT NULL,
  `team_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tournament_teams`
--

INSERT INTO `tournament_teams` (`id`, `tournament_id`, `team_id`) VALUES
(4, 3, 10),
(5, 3, 11);

-- --------------------------------------------------------

--
-- Table structure for table `tournament_team_requests`
--

CREATE TABLE `tournament_team_requests` (
  `id` int(11) NOT NULL,
  `tournament_id` int(11) NOT NULL,
  `team_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `message` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `processed_at` datetime DEFAULT NULL,
  `processed_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tournament_team_requests`
--

INSERT INTO `tournament_team_requests` (`id`, `tournament_id`, `team_id`, `user_id`, `message`, `status`, `created_at`, `processed_at`, `processed_by`) VALUES
(3, 3, 10, 1, 'scsadas', 'approved', '2026-04-10 18:48:33', '2026-04-10 18:49:36', 11),
(4, 3, 11, 4, 'sdsdsa', 'approved', '2026-04-10 18:52:22', '2026-04-10 18:53:10', 11);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('player','organizer','admin') DEFAULT 'player',
  `display_name` varchar(100) DEFAULT NULL,
  `avatar_url` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password_hash`, `role`, `display_name`, `avatar_url`, `created_at`, `updated_at`) VALUES
(1, 'player1', 'player1@mail.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'player', 'Player One', 'uploads/avatars/avatar_1_ae1fb2f42bfc.jpg', '2026-04-01 08:55:21', '2026-04-01 10:01:11'),
(2, 'player2', 'player2@mail.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'player', 'Player Two', NULL, '2026-04-01 08:55:21', '2026-04-01 08:55:21'),
(3, 'player3', 'player3@mail.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'player', 'Player Three', NULL, '2026-04-01 08:55:21', '2026-04-01 08:55:21'),
(4, 'player4', 'player4@mail.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'player', 'Player Four', NULL, '2026-04-01 08:55:21', '2026-04-01 08:55:21'),
(5, 'player5', 'player5@mail.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'player', 'Player Five', NULL, '2026-04-01 08:55:21', '2026-04-01 08:55:21'),
(6, 'player6', 'player6@mail.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'player', 'Player Six', NULL, '2026-04-01 08:55:21', '2026-04-01 08:55:21'),
(7, 'player7', 'player7@mail.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'player', 'Player Seven', NULL, '2026-04-01 08:55:21', '2026-04-01 08:55:21'),
(8, 'player8', 'player8@mail.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'player', 'Player Eight', NULL, '2026-04-01 08:55:21', '2026-04-01 08:55:21'),
(9, 'player9', 'player9@mail.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'player', 'Player Nine', NULL, '2026-04-01 08:55:21', '2026-04-01 08:55:21'),
(10, 'player10', 'player10@mail.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'player', 'Player Ten', NULL, '2026-04-01 08:55:21', '2026-04-01 08:55:21'),
(11, 'organizer1', 'organizer@mail.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'organizer', 'Organizer One', NULL, '2026-04-01 08:55:21', '2026-04-01 08:55:21'),
(12, 'admin1', 'admin@mail.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'Admin One', NULL, '2026-04-01 08:55:21', '2026-04-01 08:55:21');

-- --------------------------------------------------------

--
-- Table structure for table `user_rewards`
--

CREATE TABLE `user_rewards` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `reward_id` int(11) NOT NULL,
  `redeemed_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_rewards`
--

INSERT INTO `user_rewards` (`id`, `user_id`, `reward_id`, `redeemed_at`) VALUES
(1, 1, 1, '2026-04-01 08:55:23');

-- --------------------------------------------------------

--
-- Table structure for table `user_settings`
--

CREATE TABLE `user_settings` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `language` varchar(10) DEFAULT 'en',
  `theme` enum('dark','light','neon') DEFAULT 'dark',
  `notifications` tinyint(1) DEFAULT 1,
  `privacy_level` enum('public','friends','private') DEFAULT 'public'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_settings`
--

INSERT INTO `user_settings` (`id`, `user_id`, `language`, `theme`, `notifications`, `privacy_level`) VALUES
(1, 1, 'en', 'light', 1, 'public'),
(2, 11, 'en', 'dark', 1, 'public'),
(3, 1, 'en', 'light', 1, 'public'),
(4, 1, 'en', 'light', 1, 'public'),
(5, 1, 'en', 'dark', 1, 'public');

-- --------------------------------------------------------

--
-- Table structure for table `user_stats`
--

CREATE TABLE `user_stats` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `game_id` int(11) DEFAULT NULL,
  `total_points` int(11) DEFAULT 0,
  `wins` int(11) DEFAULT 0,
  `losses` int(11) DEFAULT 0,
  `streak` int(11) DEFAULT 0,
  `last_update` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_stats`
--

INSERT INTO `user_stats` (`id`, `user_id`, `game_id`, `total_points`, `wins`, `losses`, `streak`, `last_update`) VALUES
(1, 1, 1, 1200, 10, 5, 3, '2026-04-01 08:55:23'),
(2, 2, 1, 1500, 15, 4, 5, '2026-04-01 08:55:23'),
(3, 3, 2, 900, 6, 8, 1, '2026-04-01 08:55:23'),
(4, 4, 3, 700, 4, 6, 0, '2026-04-01 08:55:23');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `ai_coach_logs`
--
ALTER TABLE `ai_coach_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `calls`
--
ALTER TABLE `calls`
  ADD PRIMARY KEY (`id`),
  ADD KEY `host_id` (`host_id`),
  ADD KEY `game_id` (`game_id`);

--
-- Indexes for table `call_participants`
--
ALTER TABLE `call_participants`
  ADD PRIMARY KEY (`id`),
  ADD KEY `call_id` (`call_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `chats`
--
ALTER TABLE `chats`
  ADD PRIMARY KEY (`id`),
  ADD KEY `team_id` (`team_id`);

--
-- Indexes for table `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `chat_id` (`chat_id`),
  ADD KEY `sender_id` (`sender_id`);

--
-- Indexes for table `chat_participants`
--
ALTER TABLE `chat_participants`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_chat_user` (`chat_id`,`user_id`),
  ADD KEY `chat_id` (`chat_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `events`
--
ALTER TABLE `events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `organizer_id` (`organizer_id`),
  ADD KEY `game_id` (`game_id`),
  ADD KEY `location_id` (`location_id`);

--
-- Indexes for table `games`
--
ALTER TABLE `games`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `locations`
--
ALTER TABLE `locations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `matches`
--
ALTER TABLE `matches`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tournament_id` (`tournament_id`),
  ADD KEY `team1_id` (`team1_id`),
  ADD KEY `team2_id` (`team2_id`);

--
-- Indexes for table `reports`
--
ALTER TABLE `reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `reporter_id` (`reporter_id`),
  ADD KEY `reported_user_id` (`reported_user_id`),
  ADD KEY `match_id` (`match_id`);

--
-- Indexes for table `rewards`
--
ALTER TABLE `rewards`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `teams`
--
ALTER TABLE `teams`
  ADD PRIMARY KEY (`id`),
  ADD KEY `owner_id` (`owner_id`),
  ADD KEY `captain_id` (`captain_id`),
  ADD KEY `game_id` (`game_id`);

--
-- Indexes for table `team_join_requests`
--
ALTER TABLE `team_join_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `team_id` (`team_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `team_members`
--
ALTER TABLE `team_members`
  ADD PRIMARY KEY (`id`),
  ADD KEY `team_id` (`team_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `tournaments`
--
ALTER TABLE `tournaments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `game_id` (`game_id`),
  ADD KEY `organizer_id` (`organizer_id`);

--
-- Indexes for table `tournament_games`
--
ALTER TABLE `tournament_games`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_tourn_game` (`tournament_id`,`game_id`),
  ADD KEY `game_id` (`game_id`);

--
-- Indexes for table `tournament_game_requests`
--
ALTER TABLE `tournament_game_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_tgr` (`tournament_id`,`team_id`,`game_id`),
  ADD KEY `team_id` (`team_id`),
  ADD KEY `game_id` (`game_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `processed_by` (`processed_by`);

--
-- Indexes for table `tournament_teams`
--
ALTER TABLE `tournament_teams`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tournament_id` (`tournament_id`),
  ADD KEY `team_id` (`team_id`);

--
-- Indexes for table `tournament_team_requests`
--
ALTER TABLE `tournament_team_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_tourn_team_req` (`tournament_id`,`team_id`),
  ADD KEY `idx_ttr_tourn` (`tournament_id`),
  ADD KEY `idx_ttr_team` (`team_id`),
  ADD KEY `fk_ttr_user` (`user_id`),
  ADD KEY `fk_ttr_processed_by` (`processed_by`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `user_rewards`
--
ALTER TABLE `user_rewards`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `reward_id` (`reward_id`);

--
-- Indexes for table `user_settings`
--
ALTER TABLE `user_settings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `user_stats`
--
ALTER TABLE `user_stats`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `game_id` (`game_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `ai_coach_logs`
--
ALTER TABLE `ai_coach_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `calls`
--
ALTER TABLE `calls`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `call_participants`
--
ALTER TABLE `call_participants`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `chats`
--
ALTER TABLE `chats`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=79;

--
-- AUTO_INCREMENT for table `chat_messages`
--
ALTER TABLE `chat_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `chat_participants`
--
ALTER TABLE `chat_participants`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=154;

--
-- AUTO_INCREMENT for table `events`
--
ALTER TABLE `events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `games`
--
ALTER TABLE `games`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `locations`
--
ALTER TABLE `locations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `matches`
--
ALTER TABLE `matches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `reports`
--
ALTER TABLE `reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `rewards`
--
ALTER TABLE `rewards`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `teams`
--
ALTER TABLE `teams`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `team_join_requests`
--
ALTER TABLE `team_join_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `team_members`
--
ALTER TABLE `team_members`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `tournaments`
--
ALTER TABLE `tournaments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `tournament_games`
--
ALTER TABLE `tournament_games`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `tournament_game_requests`
--
ALTER TABLE `tournament_game_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `tournament_teams`
--
ALTER TABLE `tournament_teams`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `tournament_team_requests`
--
ALTER TABLE `tournament_team_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `user_rewards`
--
ALTER TABLE `user_rewards`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `user_settings`
--
ALTER TABLE `user_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `user_stats`
--
ALTER TABLE `user_stats`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `ai_coach_logs`
--
ALTER TABLE `ai_coach_logs`
  ADD CONSTRAINT `ai_coach_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `calls`
--
ALTER TABLE `calls`
  ADD CONSTRAINT `calls_ibfk_1` FOREIGN KEY (`host_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `calls_ibfk_2` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`);

--
-- Constraints for table `call_participants`
--
ALTER TABLE `call_participants`
  ADD CONSTRAINT `call_participants_ibfk_1` FOREIGN KEY (`call_id`) REFERENCES `calls` (`id`),
  ADD CONSTRAINT `call_participants_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `chats`
--
ALTER TABLE `chats`
  ADD CONSTRAINT `chats_ibfk_1` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`);

--
-- Constraints for table `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD CONSTRAINT `chat_messages_ibfk_1` FOREIGN KEY (`chat_id`) REFERENCES `chats` (`id`),
  ADD CONSTRAINT `chat_messages_ibfk_2` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `chat_participants`
--
ALTER TABLE `chat_participants`
  ADD CONSTRAINT `chat_participants_chat_fk` FOREIGN KEY (`chat_id`) REFERENCES `chats` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `chat_participants_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `events`
--
ALTER TABLE `events`
  ADD CONSTRAINT `events_ibfk_1` FOREIGN KEY (`organizer_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `events_ibfk_2` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`),
  ADD CONSTRAINT `events_ibfk_3` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`);

--
-- Constraints for table `matches`
--
ALTER TABLE `matches`
  ADD CONSTRAINT `matches_ibfk_1` FOREIGN KEY (`tournament_id`) REFERENCES `tournaments` (`id`),
  ADD CONSTRAINT `matches_ibfk_2` FOREIGN KEY (`team1_id`) REFERENCES `teams` (`id`),
  ADD CONSTRAINT `matches_ibfk_3` FOREIGN KEY (`team2_id`) REFERENCES `teams` (`id`);

--
-- Constraints for table `reports`
--
ALTER TABLE `reports`
  ADD CONSTRAINT `reports_ibfk_1` FOREIGN KEY (`reporter_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `reports_ibfk_2` FOREIGN KEY (`reported_user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `reports_ibfk_3` FOREIGN KEY (`match_id`) REFERENCES `matches` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `teams`
--
ALTER TABLE `teams`
  ADD CONSTRAINT `teams_ibfk_1` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `teams_ibfk_2` FOREIGN KEY (`captain_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `teams_ibfk_3` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`);

--
-- Constraints for table `team_join_requests`
--
ALTER TABLE `team_join_requests`
  ADD CONSTRAINT `team_join_requests_ibfk_1` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`),
  ADD CONSTRAINT `team_join_requests_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `team_members`
--
ALTER TABLE `team_members`
  ADD CONSTRAINT `team_members_ibfk_1` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`),
  ADD CONSTRAINT `team_members_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `tournaments`
--
ALTER TABLE `tournaments`
  ADD CONSTRAINT `tournaments_ibfk_1` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`),
  ADD CONSTRAINT `tournaments_ibfk_2` FOREIGN KEY (`organizer_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `tournament_games`
--
ALTER TABLE `tournament_games`
  ADD CONSTRAINT `tournament_games_ibfk_1` FOREIGN KEY (`tournament_id`) REFERENCES `tournaments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tournament_games_ibfk_2` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tournament_game_requests`
--
ALTER TABLE `tournament_game_requests`
  ADD CONSTRAINT `tournament_game_requests_ibfk_1` FOREIGN KEY (`tournament_id`) REFERENCES `tournaments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tournament_game_requests_ibfk_2` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tournament_game_requests_ibfk_3` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tournament_game_requests_ibfk_4` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tournament_game_requests_ibfk_5` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `tournament_teams`
--
ALTER TABLE `tournament_teams`
  ADD CONSTRAINT `tournament_teams_ibfk_1` FOREIGN KEY (`tournament_id`) REFERENCES `tournaments` (`id`),
  ADD CONSTRAINT `tournament_teams_ibfk_2` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`);

--
-- Constraints for table `tournament_team_requests`
--
ALTER TABLE `tournament_team_requests`
  ADD CONSTRAINT `fk_ttr_processed_by` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_ttr_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ttr_tourn` FOREIGN KEY (`tournament_id`) REFERENCES `tournaments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ttr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_rewards`
--
ALTER TABLE `user_rewards`
  ADD CONSTRAINT `user_rewards_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `user_rewards_ibfk_2` FOREIGN KEY (`reward_id`) REFERENCES `rewards` (`id`);

--
-- Constraints for table `user_settings`
--
ALTER TABLE `user_settings`
  ADD CONSTRAINT `user_settings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `user_stats`
--
ALTER TABLE `user_stats`
  ADD CONSTRAINT `user_stats_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `user_stats_ibfk_2` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
