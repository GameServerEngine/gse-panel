		<div>
			<div>
				<span id="amx_no_servers" class="hide"><?php echo get_lang('no_servers'); ?></span>
			</div>

			<div id="amx_add_admin" class="hide">
				<form action="" method="POST" id="amx_addadmin_form">
					<div id="amx_games"></div>

					<label for="amx_login_type"><?php echo get_lang('amx_login_type'); ?></label>
					<select id="amx_login_type" name="amx_login_type">
						<option value="amx_login_steamid"><?php echo get_lang('amx_login_steamid'); ?></option>
						<option value="amx_login_nick_pass"><?php echo get_lang('amx_login_nick_pass'); ?></option>
					</select>

					<div id="amx_login_steamid">
						<label for="amx_Steamid"><?php echo get_lang('steamid'); ?></label>
						<input type="text" id="amx_Steamid" name="amx_Steamid" pattern="^STEAM_[01]:[01]:\d+$" required>
					</div>

					<div id="amx_login_nick_pass"  class="hide">
						<label for="amx_Nickname"><?php echo get_lang('nickname'); ?></label>
						<input type="text" id="amx_Nickname" name="amx_Nickname" pattern="^[^\s][A-zÀ-ÿ0-9 !@)(,}/|\.:?;{#$%&*+=-]{1,28}[^\s]$">
						<label for="amx_Password"><?php echo get_lang('password'); ?></label>
						<input type="text" id="amx_Password" name="amx_Password" pattern="^[^\s][A-zÀ-ÿ0-9 !@)(,}/|\.:?;{#$%&*+=-]{1,28}[^\s]$">
					</div>

					<label for="amx_mod_perms"><?php echo get_lang('amx_mod_perms'); ?></label>
					<select id="amx_mod_perms" name="amx_mod_perms">
						<option value="root"><?php echo get_lang('amx_mod_perm_root'); ?></option>
						<option value="custom"><?php echo get_lang('amx_mod_perm_custom'); ?></option>
					</select>

					<div id="amx_mod_flagList" class="hide">
						<fieldset>
							<?php
								foreach(range('a', 'u') as $flag){
							?>

							<div class="amx_item">
								<input type="checkbox" id="amx_flag_<?php echo $flag; ?>" name="amx_flags[]" value="<?php echo $flag; ?>">
								<label for="amx_flag_<?php echo $flag; ?>"><?php echo get_lang('amx_mod_flag_'.$flag); ?></label>
							</div>

							<?php
								}
							?>
						</fieldset>
					</div>

					<input type="hidden" name="remote_server_id" id="amx_remote_server_id" value="">
					<input type="hidden" name="gameserver_name" id="amx_gameserver_name" value="">
					<input type="hidden" name="gameserver_ip" id="amx_gameserver_ip" value="">
					<input type="hidden" name="gameserver_port" id="amx_gameserver_port" value="">

					<button type="submit"><?php echo get_lang('submit'); ?></button>
				</form>
			</div>

			<div id="amx_invalid_server" class="hide"><?php echo get_lang('server_not_selected'); ?></div>
			<div id="amx_invalid_steamid_admin" class="hide"><?php echo get_lang('invalid_steamid'); ?></div>
			<div id="amx_invalid_nickname_admin" class="hide"><?php echo get_lang('invalid_nickname'); ?></div>
			<div id="amx_invalid_password_admin" class="hide"><?php echo get_lang('invalid_password'); ?></div>
			<div id="amx_invalid_response_admin" class="hide"><?php echo get_lang('post_failed'); ?></div>

			<div id="amx_all_servers_offline" class="hide"><?php echo get_lang('agents_offline'); ?></div>

			<div id="amx_addadmin_response" class="hide"></div>
		</div>
