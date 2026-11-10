<?php
/*
 * Group start/stop/restart for the Game Monitor "ALL" view.
 *
 * Each function has one job. Nothing here talks to an agent directly: the
 * caller passes a "runner" function, so tests can stub the agent calls.
 */

// Actions an admin may run on a group of servers.
function group_allowed_actions()
{
	return array('start', 'stop', 'restart');
}

// Returns the action name if it is allowed, or '' if it is not.
function group_parse_action($action)
{
	$action = is_string($action) ? $action : '';
	return in_array($action, group_allowed_actions(), true) ? $action : '';
}

// Turns one checkbox value "home_id-mod_id-ip-port" into a target array.
// Returns NULL when the value does not have that shape.
function group_parse_target($value)
{
	$pattern = '/^(\d+)-(\d+)-(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})-(\d+)$/';
	if (!is_string($value) || !preg_match($pattern, $value, $m))
		return NULL;
	return array('home_id' => $m[1], 'mod_id' => $m[2], 'ip' => $m[3], 'port' => $m[4]);
}

// Turns the posted checkbox values into a list of targets.
// Bad values are skipped. The same server is listed once.
function group_parse_selection($values)
{
	$targets = array();
	foreach ((array)$values as $value)
	{
		$target = group_parse_target($value);
		if ($target === NULL)
			continue;
		$targets[$target['home_id'] . '-' . $target['mod_id'] . '-' . $target['ip'] . '-' . $target['port']] = $target;
	}
	return array_values($targets);
}

// Runs one target through the runner. A crash in the runner counts as a failure,
// so the other servers still run.
function group_run_one($action, $target, $runner)
{
	try
	{
		$outcome = call_user_func($runner, $action, $target);
	}
	catch (Throwable $e)
	{
		$outcome = array(false, $e->getMessage());
	}
	$ok = is_array($outcome) && !empty($outcome[0]);
	$reason = (is_array($outcome) && isset($outcome[1])) ? (string)$outcome[1] : '';
	return array('target' => $target, 'ok' => $ok, 'reason' => $reason);
}

// Runs the action on every target, one after the other, and returns all results.
function group_run($action, $targets, $runner)
{
	$results = array();
	foreach ($targets as $target)
		$results[] = group_run_one($action, $target, $runner);
	return $results;
}

// Counts how many results succeeded and failed.
function group_summary($results)
{
	$summary = array('total' => count($results), 'succeeded' => 0, 'failed' => 0);
	foreach ($results as $result)
		$summary[$result['ok'] ? 'succeeded' : 'failed']++;
	return $summary;
}

// The real runner: uses exec_operation() and turns its answer into (ok, reason).
function group_exec_runner($action, $target)
{
	exec_operation_error('');
	$ok = exec_operation($action, $target['home_id'], $target['mod_id'], $target['ip'], $target['port']);
	if ($ok)
		return array(true, '');
	$reason = exec_operation_error();
	return array(false, $reason !== '' ? $reason : get_lang('group_operation_failed'));
}

// Text shown for one server in the result list.
function group_target_label($target, $names)
{
	$name = isset($names[$target['home_id']]) ? $names[$target['home_id']] : 'Home ' . $target['home_id'];
	return $name . ' (' . $target['ip'] . ':' . $target['port'] . ')';
}

// HTML list of results: which servers worked and which failed, with the reason.
function group_render_results($action, $results, $names)
{
	$summary = group_summary($results);
	$html = "<div class='group-results'><b>" . htmlentities(get_lang('group_results_' . $action)) . ": "
		. (int)$summary['succeeded'] . " " . htmlentities(get_lang('group_succeeded')) . ", "
		. (int)$summary['failed'] . " " . htmlentities(get_lang('group_failed')) . "</b><ul>";
	foreach ($results as $result)
	{
		$label = htmlentities(group_target_label($result['target'], $names));
		if ($result['ok'])
			$html .= "<li class='success'>" . $label . "</li>";
		else
			$html .= "<li class='failure'>" . $label . ": " . htmlentities($result['reason']) . "</li>";
	}
	return $html . "</ul></div>";
}

// Handles a group POST. Returns the HTML to show, or '' when nothing was posted.
// Only admins may run a group; everyone else gets nothing.
function group_handle_post($post, $isAdmin, $names)
{
	if (!$isAdmin || !isset($post['group_action']))
		return '';
	$action = group_parse_action($post['group_action']);
	$targets = group_parse_selection(isset($post['group_select']) ? $post['group_select'] : array());
	if ($action === '' || count($targets) == 0)
		return "<p class='failure'>" . htmlentities(get_lang('group_select_none')) . "</p>";
	return group_render_results($action, group_run($action, $targets, 'group_exec_runner'), $names);
}

// Splits a per-row radio value "action-home_id-mod_id-ip-port" into array(action, target).
// Returns NULL when the value is not valid.
function group_parse_row_value($value)
{
	$parts = explode('-', (string)$value, 2);
	if (count($parts) != 2)
		return NULL;
	$action = group_parse_action($parts[0]);
	$target = group_parse_target($parts[1]);
	if ($action === '' || $target === NULL)
		return NULL;
	return array($action, $target);
}

// Handles the per-row Start/Stop/Restart radio buttons (fields named action-<home_id>).
// Returns the result HTML, or '' when nothing was posted.
function group_handle_row_post($post, $names)
{
	$by_action = array();
	foreach ((array)$post as $key => $value)
	{
		$row = preg_match('/^action/', $key) ? group_parse_row_value($value) : NULL;
		if ($row !== NULL)
			$by_action[$row[0]][] = $row[1];
	}
	$html = '';
	foreach ($by_action as $action => $targets)
		$html .= group_render_results($action, group_run($action, $targets, 'group_exec_runner'), $names);
	return $html;
}

// Value of a row checkbox: "home_id-mod_id-ip-port".
function group_row_value($server_home)
{
	return $server_home['home_id'] . '-' . $server_home['mod_id'] . '-' . $server_home['ip'] . '-' . $server_home['port'];
}

// Checkbox for one row of the ALL view.
function group_row_checkbox($server_home)
{
	return "<input type='checkbox' class='group-select' value='" . htmlentities(group_row_value($server_home))
		. "' title='" . htmlentities(get_lang('group_select_server')) . "' /> ";
}

// One footer button. The confirm text (with %d for the count) is empty when no confirmation is needed.
function group_button($action, $confirm_key)
{
	$confirm = $confirm_key === '' ? '' : get_lang($confirm_key);
	return "<input type='button' class='group-action' data-action='" . $action . "' data-confirm='" . htmlentities($confirm)
		. "' value='" . htmlentities(get_lang('group_' . $action . '_selected')) . "' /> ";
}

// The three group buttons for the footer.
function group_footer_buttons()
{
	return group_button('start', '') . group_button('stop', 'group_confirm_stop') . group_button('restart', 'group_confirm_restart');
}
