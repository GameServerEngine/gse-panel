		<div>
			<form action="" method="POST" id="steam_converter">
				<label for="steam_input">SteamID / SteamID3 / SteamID64 / CustomID:</label>
				<input type="text" name="steam_input" id="steam_input" required>

				<button type="submit"><?php echo get_lang('submit'); ?></button>
			</form>
		</div>

		<div id="invalid_steamid" class="hide"><?php echo get_lang('invalid_steamid'); ?></div>
		<div id="invalid_response" class="hide"><?php echo get_lang('post_failed'); ?></div>

		<div id="steam_info">
			<div id="steamLink"></div>
			<div id="steamId"></div>
			<div id="steamId3"></div>
			<div id="steamId64"></div>
		</div>
