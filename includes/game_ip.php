<?php
/*
 * Which addresses a game server may be bound to.
 *
 * Players must be able to reach a game server. On an agent with a public address (use_nat off)
 * that means a public address: a private one (10.x, 172.16-31.x, 192.168.x and other reserved
 * ranges) is a LAN or VPN address, such as the WireGuard mesh 10.255.0.x, and nobody outside can
 * reach a game bound to it. Only an agent behind NAT (use_nat on) binds games to a private
 * address, because its router forwards the public ports to that address.
 */

if (!function_exists('game_ip_is_public')) {
	function game_ip_is_public($ip)
	{
		return filter_var((string)$ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
	}
}

if (!function_exists('game_ip_allowed')) {
	function game_ip_allowed($ip, $use_nat)
	{
		return (int)$use_nat === 1 || game_ip_is_public($ip);
	}
}

if (!function_exists('game_ip_refusal')) {
	/** The message shown when an address is refused. */
	function game_ip_refusal($ip)
	{
		return "The address " . $ip . " is a private (LAN or VPN) address, and this agent is not behind NAT, "
			. "so players could not reach a game server on it. Use the agent's public address, or turn on "
			. "\"Use NAT\" for the agent if it really is behind a router.";
	}
}
