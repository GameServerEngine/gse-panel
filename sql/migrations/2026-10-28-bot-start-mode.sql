-- Bot start mode for server content bots (default: start with the game)
ALTER TABLE server_content_manifest ADD COLUMN bot_start_mode VARCHAR(16) NOT NULL DEFAULT 'with_game' AFTER bot_arguments;
