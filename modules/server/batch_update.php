<?php
/*
 *
 * GSP - GameServer Panel
 *
 * Remote Servers page: update one, several, or all agents, selected with a
 * select-all checkbox plus one checkbox per row. Selected agents update one
 * at a time, in the order they were listed. Each one shows its own result;
 * the batch continues past a failure and reports every failure at the end
 * (does not stop the whole batch on the first failure).
 *
 */

require_once('modules/server/agent_helpers.php');

function exec_gsp_module()
{
	global $db;

	echo "<h2>Batch Agent Update</h2>";
	echo "<p><a href='?m=server'>[Back to Agents]</a></p>";

	$ids = gsp_agent_sanitize_ids(isset($_POST['agent_ids']) ? $_POST['agent_ids'] : null);

	if (empty($ids)) {
		print_failure('No agents were selected.');
		return;
	}

	if (empty($_POST['batch_update_confirm'])) {
		echo "<p><strong>" . count($ids) . "</strong> agent(s) selected. Confirm to queue an update and restart on each, one at a time, in list order.</p>";
		echo "<form method='post' action='?m=server&amp;p=batch_update'>";
		foreach ($ids as $id) {
			echo "<input type='hidden' name='agent_ids[]' value='" . (int)$id . "' />";
		}
		echo "<p><label><input type='checkbox' name='batch_update_confirm' value='1' required /> Confirm queued update and restart for all selected agents.</label></p>";
		echo "<p><button type='submit'>Update Selected Agents</button></p>";
		echo "</form>";
		return;
	}

	$results = array();
	foreach ($ids as $remote_server_id) {
		$remote_server_row = $db->getRemoteServer($remote_server_id);
		if (!$remote_server_row) {
			$results[] = array(
				'remote_server_id' => $remote_server_id,
				'name' => '(unknown)',
				'success' => false,
				'message' => 'Remote host no longer exists.',
			);
			continue;
		}
		$results[] = gsp_agent_perform_update($db, $remote_server_row);
		// Continue the batch regardless of this row's outcome; failures are reported together below.
	}

	$failed = array_filter($results, function ($r) { return empty($r['success']); });
	$succeeded = array_filter($results, function ($r) { return !empty($r['success']); });

	echo "<h3>Batch Update Results</h3>";
	echo "<p>" . count($succeeded) . " of " . count($results) . " agent(s) updated successfully.</p>";

	echo "<table class='center' style='width:100%;'>";
	echo "<tr><th>ID</th><th>Agent</th><th>Result</th><th>Message</th></tr>";
	foreach ($results as $r) {
		$status = !empty($r['success']) ? gsp_agent_badge('success', 'ok') : gsp_agent_badge('failed', 'critical');
		echo "<tr>";
		echo "<td>" . (int)$r['remote_server_id'] . "</td>";
		echo "<td>" . gsp_agent_html($r['name']) . "</td>";
		echo "<td>" . $status . "</td>";
		echo "<td>" . gsp_agent_html($r['message']) . "</td>";
		echo "</tr>";
	}
	echo "</table>";

	if (!empty($failed)) {
		echo "<h3>Failures</h3><ul>";
		foreach ($failed as $r) {
			echo "<li>" . gsp_agent_html($r['name']) . " (ID " . (int)$r['remote_server_id'] . "): " . gsp_agent_html($r['message']) . "</li>";
		}
		echo "</ul>";
	}
}
