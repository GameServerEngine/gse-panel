<script type="text/javascript" src="js/modules/administration.js"></script>
<?php
/*
 *
 * GSP - GameServer Panel
 * Copyright (C) 2008 - 2018 The GSP Development Team
 *
 *
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 *
 */

function exec_module() {
	global $db, $view, $loggedInUserInfo;

	$search_field = (isset($_GET['search']) && !empty($_GET['search'])) ? $_GET['search'] : false;
	$p = (isset($_GET['page']) && (int)$_GET['page'] > 0) ? (int)$_GET['page'] : 1;
	$l = (isset($_GET['limit']) && (int)$_GET['limit'] > 0) ? (int)$_GET['limit'] : 10;
	$filters = array();
	foreach (array('source_type','category','severity','user_id','remote_server_id','home_id','date_from','date_to') as $filter_key) {
		if (isset($_GET[$filter_key]) && $_GET[$filter_key] !== '' && $_GET[$filter_key] !== 'all') {
			$filters[$filter_key] = $_GET[$filter_key];
		}
	}
	$db->ensureLoggerExtendedSchema();

	if(hasValue($loggedInUserInfo) && is_array($loggedInUserInfo) && $loggedInUserInfo["users_page_limit"] && !(isset($_GET['limit']) and !empty($_GET['limit']))){
		$l = $loggedInUserInfo["users_page_limit"];
	}

	echo "<h2>".get_lang('watch_logger')."</h2>";

	$logs = $db->read_logger($p, $l, $search_field, $filters);

	if (empty($logs) && (!empty($search_field) || !empty($filters))) {
		print_failure(get_lang_f('no_results_found', htmlentities($search_field ?? 'selected filters', ENT_QUOTES, 'UTF-8')));
		$view->refresh("?m=administration&p=watch_logger", 5);

		return;
	}
	$query_params = array_merge(array('m' => 'administration', 'p' => 'watch_logger', 'limit' => $l), $filters);
	if (!empty($search_field)) {
		$query_params['search'] = $search_field;
	}
	$base_query = http_build_query($query_params);

?>
	<!-- Search, Empty Logger, and Paging Options Table -->
	<table style="width: 100%;">
		<tr>
			<td style="width: 50%; vertical-align: middle; text-align: left;">
				<form action="home.php" method="GET" style="display: inline;">
					<input type ="hidden" name="m" value="administration" />
					<input type ="hidden" name="p" value="watch_logger" />
					<input name="search" type="text" id="search" value="<?php if(hasValue($search_field)){ echo htmlentities($search_field, ENT_QUOTES, 'UTF-8'); } ?>" />
					<select name="source_type">
						<?php foreach (array('all' => 'All sources', 'user' => 'User', 'admin' => 'Admin', 'agent' => 'Agent', 'scheduler' => 'Scheduler') as $value => $label) { ?>
							<option value="<?php echo $value; ?>" <?php echo (isset($_GET['source_type']) && $_GET['source_type'] === $value) ? 'selected' : ''; ?>><?php echo $label; ?></option>
						<?php } ?>
					</select>
					<select name="category">
						<?php foreach (array('all' => 'All categories', 'authentication' => 'Authentication', 'panel_action' => 'Panel actions', 'server_lifecycle' => 'Server lifecycle', 'file_config' => 'File/config changes', 'user_management' => 'User management', 'content_workshop' => 'Content/Workshop', 'errors' => 'Errors') as $value => $label) { ?>
							<option value="<?php echo $value; ?>" <?php echo (isset($_GET['category']) && $_GET['category'] === $value) ? 'selected' : ''; ?>><?php echo $label; ?></option>
						<?php } ?>
					</select>
					<select name="severity">
						<?php foreach (array('all' => 'All severities', 'info' => 'Info', 'notice' => 'Notice', 'warning' => 'Warning', 'error' => 'Error', 'critical' => 'Critical', 'success' => 'Success') as $value => $label) { ?>
							<option value="<?php echo $value; ?>" <?php echo (isset($_GET['severity']) && $_GET['severity'] === $value) ? 'selected' : ''; ?>><?php echo $label; ?></option>
						<?php } ?>
					</select>
					<input name="user_id" type="number" min="0" placeholder="User ID" value="<?php echo htmlentities($_GET['user_id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="width:70px;" />
					<input name="remote_server_id" type="number" min="0" placeholder="Agent ID" value="<?php echo htmlentities($_GET['remote_server_id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="width:75px;" />
					<input name="home_id" type="number" min="0" placeholder="Home ID" value="<?php echo htmlentities($_GET['home_id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="width:75px;" />
					<input name="date_from" type="date" value="<?php echo htmlentities($_GET['date_from'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" />
					<input name="date_to" type="date" value="<?php echo htmlentities($_GET['date_to'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" />
					<input type="submit" value="<?php echo get_lang('search'); ?>" />
				</form>
				<form method=POST style="display: inline;">
					<input type="submit" name="empty_logger" value="<?php print_lang('empty_logger'); ?>" >
				</form>
			</td>
			<td style="width: 50%; vertical-align: middle; text-align: right;">
				<?php echo print_lang('view'); ?> <a href='?<?php echo htmlentities(http_build_query(array_merge($query_params, array('limit' => 10))), ENT_QUOTES, 'UTF-8'); ?>'>10</a> / <a href='?<?php echo htmlentities(http_build_query(array_merge($query_params, array('limit' => 20))), ENT_QUOTES, 'UTF-8'); ?>'>20</a> / <a href='?<?php echo htmlentities(http_build_query(array_merge($query_params, array('limit' => 50))), ENT_QUOTES, 'UTF-8'); ?>'>50</a> / <a href='?<?php echo htmlentities(http_build_query(array_merge($query_params, array('limit' => 100))), ENT_QUOTES, 'UTF-8'); ?>'>100</a> <?php echo print_lang('per_page'); ?>
			</td>
		</tr>
	</table>
	<!-- END Search, Empty Logger, and Paging Options Table -->

	<table id="servermonitor" class="tablesorter" data-sortlist="[[1,1]]">
	<thead>
	<tr>
		<th style="width:16px;background-position: center;" class="sorter-false"></th>
		<th class="dateFormat-ddmmyyyy"><?php print_lang('when'); ?></th>
		<th><?php print_lang('who'); ?></th>
		<th><?php print_lang('where'); ?></th>
		<th>Type</th>
		<th>Severity</th>
		<th>Server</th>
		<th><?php print_lang('what'); ?></th>
	</tr>
	</thead>
	<tbody>
	<?php
	if( isset( $_POST['log_id'] ) ){
		$db->del_logger_log($_POST['log_id']);
		$newLogs = array();
		foreach ((array)$logs as $log){
			if($log['log_id'] != $_POST['log_id']){
				$newLogs[] = $log;
			}
		}
		$logs = $newLogs;
	}

	if( isset( $_POST['empty_logger'] ) ){
		$db->empty_logger();
		$logs = false;
	}

	if($logs)
	{
		foreach ((array)$logs as $log)
		{
			$user = $db->getUserById($log['user_id']);
			$user = is_array($user) ? $user : array();
			$when = htmlentities($log['date'] ?? '', ENT_QUOTES, 'UTF-8');
			$who = isset($log['actor']) && $log['actor'] !== '' ? $log['actor'] : ($user['users_login'] ?? 'System');
			$who = htmlentities($who, ENT_QUOTES, 'UTF-8');
			$where = htmlentities($log['ip'] ?? '', ENT_QUOTES, 'UTF-8');
			$what = htmlentities($log['message'] ?? '', ENT_QUOTES, 'UTF-8');
			$source = htmlentities(strtoupper($log['source_type'] ?? 'USER'), ENT_QUOTES, 'UTF-8');
			$category = htmlentities($log['category'] ?? '', ENT_QUOTES, 'UTF-8');
			$severity = htmlentities(strtoupper($log['severity'] ?? 'INFO'), ENT_QUOTES, 'UTF-8');
			$server_ref = '';
			if (!empty($log['home_id'])) {
				$server_ref .= 'Home '.$log['home_id'];
			}
			if (!empty($log['remote_server_id'])) {
				$server_ref .= ($server_ref ? ' / ' : '').'Agent '.$log['remote_server_id'];
			}
			$server_ref = htmlentities($server_ref, ENT_QUOTES, 'UTF-8');
			$log_id = $log['log_id'];
			// Template
			echo "<tr class='maintr'>\n".
				 "<td class='collapsible'>\n".
				 "<center>\n".
				 "<form method=POST>\n".
				 "<input type='hidden' name='log_id' value='$log_id' />\n".
				 "<input type='image' name='remove_log' onclick=\"this.form.submit();\" src='modules/administration/images/remove.gif' />\n".
				 "</form>\n".
				 "</center>\n".
				 "</td>\n".
				 "<td class='collapsible'>$when</td>\n".
				 "<td class='collapsible'>$who</td>\n".
				 "<td class='collapsible'>$where</td>\n".
				 "<td class='collapsible'><span class='note'>$source</span><br />$category</td>\n".
				 "<td class='collapsible'>$severity</td>\n".
				 "<td class='collapsible'>$server_ref</td>\n".
				 "<td class='collapsible'>$what</td>\n".
				 "</tr>\n";

			echo "<tr class='expand-child'>\n".
				 "<td colspan='8' >\n".
				 "<table>\n";

			$show_values = array( "users_login", "users_lang", "users_role", "users_email", "user_expires");
			foreach ((array)$user as $key => $value)
			{
				if( in_array( $key, $show_values ) )
					echo "<tr><td>".htmlentities(str_replace("_", "", substr($key,5)), ENT_QUOTES, 'UTF-8')."</td><td>".htmlentities($value, ENT_QUOTES, 'UTF-8')."</td></tr>\n";
			}
			if (!empty($log['event_type'])) {
				echo "<tr><td>event type</td><td>".htmlentities($log['event_type'], ENT_QUOTES, 'UTF-8')."</td></tr>\n";
			}
			if (!empty($log['correlation_id'])) {
				echo "<tr><td>correlation id</td><td>".htmlentities($log['correlation_id'], ENT_QUOTES, 'UTF-8')."</td></tr>\n";
			}
			if (!empty($log['metadata_json'])) {
				echo "<tr><td>details</td><td><pre>".htmlentities($log['metadata_json'], ENT_QUOTES, 'UTF-8')."</pre></td></tr>\n";
			}
			echo "</tr>\n".
				 "</td>\n".
				 "</table>\n";
		}
	}
	echo "</tbody>\n";
	echo "<tfoot style='border:1px solid grey;'></tfoot>\n";
	echo "</table>\n";
	$count_logs = $db->get_logger_count($search_field, $filters);

	$uri = '?'.$base_query.'&page=';
	echo paginationPages($count_logs[0]['total'], $p, $l, $uri, 3, 'watchLogger');
}
?>
