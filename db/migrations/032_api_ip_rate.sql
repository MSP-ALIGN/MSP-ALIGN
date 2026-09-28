-- 1.27.1: requests without a valid API key are limited per IP address (and stop filling the request log)
CREATE TABLE api_ip_rate (
  ip VARCHAR(64) NOT NULL,
  window_start INT UNSIGNED NOT NULL,      -- unix minute
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (ip, window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
