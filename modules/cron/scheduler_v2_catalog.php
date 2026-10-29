<?php
/*
 * Scheduler v2 typed action catalog (phase P1: data only, no execution path).
 * See docs/plans/scheduler-v2/PLAN.md. This file has no execution logic: it is the
 * single source of truth for which actions a customer may schedule, and what
 * params each one accepts. Adding an action here does not expose a way to run it;
 * P2 wires these keys to the existing agent RPCs.
 */

if (!defined('SCHEDULER_V2_CATALOG_LOADED')) {
	define('SCHEDULER_V2_CATALOG_LOADED', true);

	/**
	 * Returns the full catalog, keyed by action_key. Each entry:
	 *   params: map of param name => array('type' => ..., 'required' => bool, [range/allowlist])
	 *   required_state: 'any' | 'running' | 'stopped'
	 *   permission: 'customer' | 'admin'
	 *   agent_call: human-readable pointer to the agent RPC this will use in P2
	 */
	function scheduler_v2_catalog()
	{
		return array(
			'server.start' => array(
				'params' => array(),
				'required_state' => 'stopped',
				'permission' => 'customer',
				'agent_call' => 'universal_start',
			),
			'server.stop' => array(
				'params' => array(
					'grace_seconds' => array('type' => 'int', 'required' => false, 'min' => 0, 'max' => 300, 'default' => 0),
				),
				'required_state' => 'running',
				'permission' => 'customer',
				'agent_call' => 'stop_server',
			),
			'server.restart' => array(
				'params' => array(
					'grace_seconds' => array('type' => 'int', 'required' => false, 'min' => 0, 'max' => 300, 'default' => 0),
				),
				'required_state' => 'any',
				'permission' => 'customer',
				'agent_call' => 'restart_server',
			),
			'server.backup' => array(
				'params' => array(
					'target' => array('type' => 'enum', 'required' => true, 'values' => array('config', 'world')),
				),
				'required_state' => 'any',
				'permission' => 'customer',
				'agent_call' => 'backup helper',
			),
			'workshop.check_updates' => array(
				'params' => array(),
				'required_state' => 'any',
				'permission' => 'customer',
				'agent_call' => 'server_content check',
			),
			'workshop.install_updates_if_stopped' => array(
				'params' => array(),
				'required_state' => 'stopped',
				'permission' => 'customer',
				'agent_call' => 'server_content conditional install',
			),
			'workshop.install_updates_and_restart' => array(
				'params' => array(),
				'required_state' => 'any',
				'permission' => 'customer',
				'agent_call' => 'server_content install + restart',
			),
			'workshop.install_updates_next_restart' => array(
				'params' => array(),
				'required_state' => 'any',
				'permission' => 'customer',
				'agent_call' => 'deferred marker',
			),
			'content.validate_files' => array(
				'params' => array(),
				'required_state' => 'stopped',
				'permission' => 'customer',
				'agent_call' => 'server_content validate',
			),
			'steam.update_game' => array(
				'params' => array(
					'branch' => array('type' => 'enum', 'required' => true, 'values' => array('public', 'beta')),
				),
				'required_state' => 'stopped',
				'permission' => 'customer',
				'agent_call' => 'steam_cmd update',
			),
			'companion.restart' => array(
				'params' => array(
					'companion_key' => array('type' => 'string', 'required' => true),
				),
				'required_state' => 'running',
				'permission' => 'customer',
				'agent_call' => 'Server Bot content restart',
			),
		);
	}

	/** Customer-facing subset: excludes anything with permission != customer. */
	function scheduler_v2_catalog_for_customer()
	{
		$out = array();
		foreach (scheduler_v2_catalog() as $key => $entry) {
			if ($entry['permission'] === 'customer') {
				$out[$key] = $entry;
			}
		}
		return $out;
	}

	function scheduler_v2_catalog_entry($action_key)
	{
		$catalog = scheduler_v2_catalog();
		return isset($catalog[$action_key]) ? $catalog[$action_key] : null;
	}

	/**
	 * Validates $params against the catalog entry's params schema for $action_key.
	 * Returns array('ok' => bool, 'errors' => array(), 'params' => array (normalized, with defaults filled)).
	 * Pure function: no DB, no side effects. Used both at save time and run time per the plan.
	 */
	function scheduler_v2_validate_params($action_key, $params)
	{
		$entry = scheduler_v2_catalog_entry($action_key);
		if ($entry === null) {
			return array('ok' => false, 'errors' => array("unknown action_key: $action_key"), 'params' => array());
		}
		$params = (array)$params;
		$errors = array();
		$normalized = array();

		foreach ($entry['params'] as $name => $spec) {
			$present = array_key_exists($name, $params) && $params[$name] !== '' && $params[$name] !== null;
			if (!$present) {
				if (!empty($spec['required'])) {
					$errors[] = "missing required param: $name";
				} elseif (array_key_exists('default', $spec)) {
					$normalized[$name] = $spec['default'];
				}
				continue;
			}
			$value = $params[$name];
			switch ($spec['type']) {
				case 'int':
					if (!is_numeric($value) || (int)$value != $value) {
						$errors[] = "param $name must be an integer";
						break;
					}
					$value = (int)$value;
					if (isset($spec['min']) && $value < $spec['min']) {
						$errors[] = "param $name below minimum {$spec['min']}";
					}
					if (isset($spec['max']) && $value > $spec['max']) {
						$errors[] = "param $name above maximum {$spec['max']}";
					}
					$normalized[$name] = $value;
					break;
				case 'enum':
					if (!in_array($value, $spec['values'], true)) {
						$errors[] = "param $name must be one of: " . implode(', ', $spec['values']);
						break;
					}
					$normalized[$name] = $value;
					break;
				case 'string':
					$normalized[$name] = (string)$value;
					break;
				default:
					$errors[] = "param $name has an unknown type in the catalog";
			}
		}

		// Reject params not declared in the schema; the plan requires no raw commands, ever.
		foreach ($params as $name => $value) {
			if (!isset($entry['params'][$name])) {
				$errors[] = "unexpected param: $name";
			}
		}

		return array('ok' => empty($errors), 'errors' => $errors, 'params' => $normalized);
	}
}
