		<div>
			<div>
				<span id="no_servers" class="hide"><?php echo get_lang('no_servers'); ?></span>
			</div>

			<div id="add_admin" class="hide">
				<form action="" method="POST" id="addadmin_form">
					<div id="games"></div>

					<label for="addSteamid"><?php echo get_lang('steamid'); ?></label>
					<input type="text" id="addSteamid" name="addSteamid" pattern="^STEAM_[01]:[01]:\d+$" required>


					<label for="immunity"><?php echo get_lang('immunity'); ?></label>
					<input type="number" id="immunity" name="immunity" min="1" max="99">

					<label for="sourcemod_perms"><?php echo get_lang('sourcemod_perms'); ?></label>
					<select id="sourcemod_perms" name="sourcemod_perms">
						<option value="root"><?php echo get_lang('sourcemod_perm_root'); ?></option>
						<option value="custom"><?php echo get_lang('sourcemod_perm_custom'); ?></option>
					</select>

					<div id="sourcemod_flagList" class="hide">
						<fieldset>
							<?php
								foreach(range('a', 't') as $flag){
							?>

							<div class="item">
								<input type="checkbox" id="flag_<?php echo $flag; ?>" name="flags[]" value="<?php echo $flag; ?>">
								<label for="flag_<?php echo $flag; ?>"><?php echo get_lang('sourcemod_flag_'.$flag); ?></label>
							</div>

							<?php
								}
							?>
						</fieldset>
					</div>

					<input type="hidden" name="remote_server_id" id="remote_server_id" value="">
					<input type="hidden" name="gameserver_name" id="gameserver_name" value="">
					<input type="hidden" name="gameserver_ip" id="gameserver_ip" value="">
					<input type="hidden" name="gameserver_port" id="gameserver_port" value="">

					<button type="submit"><?php echo get_lang('submit'); ?></button>
				</form>
			</div>

			<div id="invalid_server" class="hide"><?php echo get_lang('server_not_selected'); ?></div>
			<div id="invalid_steamid_admin" class="hide"><?php echo get_lang('invalid_steamid'); ?></div>
			<div id="invalid_response_admin" class="hide"><?php echo get_lang('post_failed'); ?></div>
			<div id="invalid_immunity" class="hide"><?php echo get_lang('invalid_immunity'); ?></div>

			<div id="all_servers_offline" class="hide"><?php echo get_lang('agents_offline'); ?></div>

			<div id="addadmin_response" class="hide"></div>
		</div>
