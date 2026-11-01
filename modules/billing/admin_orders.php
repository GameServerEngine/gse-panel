<?php
/*
 * Admin page to manage all billing orders
 * Allows admins to view, provision, suspend, and delete orders
 */

require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/action_security.php';
require_once __DIR__ . '/classes/BillingRepository.php';
require_once __DIR__ . '/classes/BillingLifecycleService.php';

if (!function_exists('billing_admin_orders_h')) {
	function billing_admin_orders_h($value): string
	{
		return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
	}
}

if (!function_exists('billing_admin_orders_datetime_local')) {
	function billing_admin_orders_datetime_local($value): string
	{
		$ts = strtotime((string)$value);
		return $ts ? date('Y-m-d\TH:i', $ts) : '';
	}
}

if (!function_exists('billing_admin_orders_lifecycle')) {
	function billing_admin_orders_lifecycle(string $prefix)
	{
		global $db_host, $db_user, $db_pass, $db_name, $db_port;
		$port = isset($db_port) && $db_port !== '' ? intval($db_port) : null;
		$link = @mysqli_connect($db_host ?? null, $db_user ?? null, $db_pass ?? null, $db_name ?? null, $port);
		if (!$link) {
			return null;
		}
		mysqli_set_charset($link, 'utf8mb4');
		return new BillingLifecycleService(new BillingRepository($link, $prefix));
	}
}

function exec_gsp_module()
{
	global $db, $view, $table_prefix;
	$user_id = $_SESSION['user_id'];
	$isAdmin = $db->isAdmin($user_id);
	$csrf = billing_action_csrf_token('billing_panel_billing_csrf');
	$db_prefix = isset($table_prefix) ? $table_prefix : '';
	$messages = array();

	if (!$isAdmin) {
		echo "<div class='failure'><p>Access Denied: Admin privileges required.</p></div>";
		return;
	}

	if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_order_action'])) {
		if (!billing_action_validate_csrf($_POST['csrf'] ?? '', 'billing_panel_billing_csrf')) {
			$messages[] = array('type' => 'failure', 'text' => 'Invalid security token. Reload the orders page and try again.');
		} elseif ($_POST['admin_order_action'] === 'update_term') {
			if (empty($_POST['confirm_action'])) {
				$messages[] = array('type' => 'failure', 'text' => 'Confirmation is required before changing a service term.');
			} else {
				$orderId = intval($_POST['order_id'] ?? 0);
				$newEndDateInput = trim((string)($_POST['new_end_date'] ?? ''));
				$newEndDate = str_replace('T', ' ', $newEndDateInput);
				if (strlen($newEndDateInput) === 16) {
					$newEndDate .= ':00';
				}
				$lifecycle = billing_admin_orders_lifecycle($db_prefix);
				if (!$lifecycle) {
					$messages[] = array('type' => 'failure', 'text' => 'Billing lifecycle service is unavailable.');
				} else {
					$result = $lifecycle->adminChangeServiceTerm($orderId, $newEndDate, array(
						'admin_user_id' => intval($user_id),
						'allow_shortening' => !empty($_POST['allow_shortening']),
					));
					if (!empty($result['success'])) {
						$detail = 'Order #' . $orderId . ' service term set to ' . ($result['end_date'] ?? $newEndDate) . '.';
						if (!empty($result['home_id'])) {
							$detail .= ' Server home #' . intval($result['home_id']) . ' and compatibility order end_date were synchronized.';
						}
						if (!empty($result['invoice_id'])) {
							$detail .= ' Invoice #' . intval($result['invoice_id']) . ' period_end was synchronized.';
						}
						$messages[] = array('type' => 'success', 'text' => $detail);
					} else {
						$messages[] = array('type' => 'failure', 'text' => 'Order #' . $orderId . ' was not changed: ' . ($result['error'] ?? 'unknown error') . '.');
					}
				}
			}
		}
	}

	// Get filter parameters
	$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
	$search = isset($_GET['search']) ? $_GET['search'] : '';

	echo "<h2>Manage All Orders (Admin)</h2>";
	foreach ($messages as $message) {
		echo "<div class='" . billing_admin_orders_h($message['type']) . "'><p>" . billing_admin_orders_h($message['text']) . "</p></div>";
	}

	// Filter form
	echo "<form method='get' action='home.php' style='margin-bottom: 20px;'>";
	echo "<input type='hidden' name='m' value='billing'>";
	echo "<input type='hidden' name='p' value='admin_orders'>";
	echo "Status: <select name='status' onchange='this.form.submit()'>";
	echo "<option value='all' ".($status_filter == 'all' ? 'selected' : '').">All Orders</option>";
	echo "<option value='Active' ".($status_filter == 'Active' ? 'selected' : '').">Active</option>";
	echo "<option value='Invoiced' ".($status_filter == 'Invoiced' ? 'selected' : '').">Invoiced</option>";
	echo "<option value='Expired' ".($status_filter == 'Expired' ? 'selected' : '').">Expired</option>";
	echo "</select> ";
	echo "Search: <input type='text' name='search' value='".$search."' placeholder='Order ID, username, server name...'> ";
	echo "<button type='submit' class='btn'>Filter</button>";
	echo "</form>";

	// Build query
	$query = "SELECT o.*, s.service_name, u.users_login, u.users_email
			  FROM GSP_DB_PREFIXbilling_orders o
			  LEFT JOIN GSP_DB_PREFIXbilling_services s ON o.service_id = s.service_id
			  LEFT JOIN GSP_DB_PREFIXusers u ON o.user_id = u.user_id
			  WHERE 1=1";

	if ($status_filter != 'all') {
		$query .= " AND o.status = '".$db->realEscapeSingle($status_filter)."'";
	}

	if (!empty($search)) {
		$search_escaped = $db->realEscapeSingle($search);
		$query .= " AND (o.order_id LIKE '%".$search_escaped."%'
					OR o.home_name LIKE '%".$search_escaped."%'
					OR u.users_login LIKE '%".$search_escaped."%'
					OR u.users_email LIKE '%".$search_escaped."%')";
	}

	$query .= " ORDER BY o.order_date DESC";

	$orders = $db->resultQuery($query);

	if (empty($orders)) {
		echo "<div class='info'><p>No orders found matching your filters.</p></div>";
		return;
	}

	echo "<form id='billing-provision-selected-form' method='post' action='home.php?m=billing&p=provision_servers' onsubmit=\"return confirm('Provision the selected paid order(s)?');\">";
	echo "<input type='hidden' name='csrf' value='" . billing_admin_orders_h($csrf) . "'>";
	echo "</form>";
	echo "<div style='margin-bottom: 10px;'>";
	echo "<button type='submit' form='billing-provision-selected-form' name='provision_selected' value='1' class='btn'>Provision Selected Paid Orders</button>";
	echo "</div>";

	echo "<table class='tablesorter'>";
	echo "<thead><tr>";
	echo "<th><input type='checkbox' id='select_all' onclick='toggleAll(this)'></th>";
	echo "<th>Order ID</th>";
	echo "<th>Username</th>";
	echo "<th>Server Name</th>";
	echo "<th>Game Service</th>";
	echo "<th>Players</th>";
	echo "<th>Price</th>";
	echo "<th>Duration</th>";
	echo "<th>Status</th>";
	echo "<th>Order Date</th>";
	echo "<th>End Date</th>";
	echo "<th>Home ID</th>";
	echo "<th>Actions</th>";
	echo "</tr></thead><tbody>";

	foreach ((array)$orders as $order) {
		$status_class = '';
		switch ($order['status']) {
			case 'Active':   $status_class = 'label-success'; break;
			case 'Invoiced': $status_class = 'label-warning'; break;
			case 'Expired':  $status_class = 'label-danger';  break;
			default:         $status_class = 'label-info';
		}

		echo "<tr>";
		echo "<td><input type='checkbox' form='billing-provision-selected-form' name='selected_orders[]' value='".intval($order['order_id'])."'></td>";
		echo "<td>".$order['order_id']."</td>";
			echo "<td>".billing_admin_orders_h($order['users_login'])."<br><small>".billing_admin_orders_h($order['users_email'])."</small></td>";
			echo "<td>".billing_admin_orders_h($order['home_name'])."</td>";
			echo "<td>".billing_admin_orders_h($order['service_name'])."</td>";
			echo "<td>".intval($order['max_players'])."</td>";
			echo "<td>$".number_format($order['price'], 2)."</td>";
			echo "<td>".intval($order['qty'])." ".billing_admin_orders_h($order['invoice_duration'])."(s)</td>";
			echo "<td><span class='label ".$status_class."'>".billing_admin_orders_h($order['status'])."</span></td>";
			echo "<td>".date('Y-m-d H:i', strtotime($order['order_date']))."</td>";
			echo "<td>".($order['end_date'] ? date('Y-m-d', strtotime($order['end_date'])) : 'N/A')."</td>";
			echo "<td>".($order['home_id'] ? billing_admin_orders_h($order['home_id']) : 'N/A')."</td>";
			echo "<td>";

			if ($order['status'] == 'Active' && !$order['home_id']) {
				echo "<form method='post' action='home.php?m=billing&p=provision_servers' style='display:inline' onsubmit=\"return confirm('Provision order #".intval($order['order_id'])."?');\">";
				echo "<input type='hidden' name='csrf' value='".billing_admin_orders_h($csrf)."'>";
				echo "<input type='hidden' name='order_id' value='".intval($order['order_id'])."'>";
				echo "<button type='submit' name='provision_single' value='1' class='btn btn-sm'>Provision</button>";
				echo "</form> ";
			}

			if ($order['status'] == 'Active' && $order['home_id']) {
				echo "<a href='home.php?m=gamemanager&p=game_monitor&home_id-mod_id-ip=".$order['home_id']."' class='btn btn-sm'>View Server</a> ";
				echo "<details style='margin-top:6px;'>";
				echo "<summary class='btn btn-sm'>Change Service Term</summary>";
				echo "<form method='post' action='home.php?m=billing&p=admin_orders' onsubmit=\"return confirm('Apply this service-term change to order #".intval($order['order_id'])." and linked server billing fields?');\">";
				echo "<input type='hidden' name='csrf' value='".billing_admin_orders_h($csrf)."'>";
				echo "<input type='hidden' name='admin_order_action' value='update_term'>";
				echo "<input type='hidden' name='order_id' value='".intval($order['order_id'])."'>";
				echo "<input type='datetime-local' name='new_end_date' required value='".billing_admin_orders_h(billing_admin_orders_datetime_local($order['end_date'] ?? ''))."'>";
				echo "<label style='display:block;font-size:11px;margin-top:4px;'><input type='checkbox' name='allow_shortening' value='1'> Allow shortening</label>";
				echo "<label style='display:block;font-size:11px;margin-top:4px;'><input type='checkbox' name='confirm_action' value='1' required> Confirm linked server/order/invoice synchronization</label>";
				echo "<button type='submit' class='btn btn-sm'>Save Term</button>";
				echo "</form>";
				echo "</details>";
			}

			echo "<a href='#' onclick='viewOrder(".$order['order_id'].")' class='btn btn-sm'>Details</a>";
		echo "</td>";
		echo "</tr>";
	}

	echo "</tbody></table>";

	// JavaScript for checkbox toggle
	echo "<script>
	function toggleAll(checkbox) {
		var checkboxes = document.getElementsByName('selected_orders[]');
		for (var i = 0; i < checkboxes.length; i++) {
			checkboxes[i].checked = checkbox.checked;
		}
	}

	function viewOrder(orderId) {
		alert('Order details for #' + orderId + '\\n\\nFull order details feature coming soon.');
		return false;
	}
	</script>";

	// Summary stats
	$stats = $db->resultQuery("SELECT status, COUNT(*) as count, SUM(price) as total
							   FROM GSP_DB_PREFIXbilling_orders
							   GROUP BY status");

	echo "<div style='margin-top: 30px;'>";
	echo "<h3>Order Statistics</h3>";
	echo "<table class='tablesorter' style='width: auto;'>";
	echo "<thead><tr><th>Status</th><th>Count</th><th>Total Value</th></tr></thead><tbody>";

	foreach ((array)$stats as $stat) {
		echo "<tr>";
		echo "<td>".$stat['status']."</td>";
		echo "<td>".$stat['count']."</td>";
		echo "<td>$".number_format($stat['total'], 2)."</td>";
		echo "</tr>";
	}

	echo "</tbody></table>";
	echo "</div>";

	// Orphaned home_id diagnostics —————————————————————————————————————————
	// Find billing_orders rows where home_id != 0 but no matching GSP_DB_PREFIXserver_homes
	// record exists. These indicate provisioning failures or stale data, and they
	// are the reason the game monitor may show "No expiration date found".
	$orphans = $db->resultQuery(
		"SELECT o.order_id, o.user_id, o.home_name, o.home_id, o.status, o.end_date
		   FROM GSP_DB_PREFIXbilling_orders o
		   LEFT JOIN GSP_DB_PREFIXserver_homes sh ON sh.home_id = o.home_id
		  WHERE o.home_id != '0'
		    AND o.home_id != ''
		    AND sh.home_id IS NULL
		  ORDER BY o.order_id ASC"
	);

	echo "<div style='margin-top: 30px;'>";
	echo "<h3>Orphaned home_id Diagnostics</h3>";
	echo "<p style='color:#666;'>Billing orders that reference a <code>home_id</code> which no longer exists in <code>GSP_DB_PREFIXserver_homes</code>. ";
	echo "These orders will not show an expiration date on the game monitor. ";
	echo "Reset <code>home_id</code> to <code>0</code> or re-provision these orders to fix them. ";
	echo "Run <code>normalize_billing_order_status.sql</code> to standardize any legacy status values.</p>";

	if (empty($orphans)) {
		echo "<p style='color:green;'>&#10003; No orphaned billing orders found.</p>";
	} else {
		echo "<table class='tablesorter' style='width:100%;'>";
		echo "<thead><tr><th>Order ID</th><th>User ID</th><th>Server Name</th><th>home_id (missing)</th><th>Status</th><th>End Date</th></tr></thead><tbody>";
		foreach ($orphans as $row) {
			echo "<tr>";
			echo "<td>".intval($row['order_id'])."</td>";
			echo "<td>".intval($row['user_id'])."</td>";
			echo "<td>".htmlspecialchars($row['home_name'] ?? '')."</td>";
			echo "<td style='color:red;'>".htmlspecialchars($row['home_id'] ?? '')."</td>";
			echo "<td>".htmlspecialchars($row['status'] ?? '')."</td>";
			echo "<td>".htmlspecialchars($row['end_date'] ?? 'NULL')."</td>";
			echo "</tr>";
		}
		echo "</tbody></table>";
	}
	echo "</div>";
}
?>
