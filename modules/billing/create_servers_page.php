<?php
// Admin page for Provision Servers. The provisioning library lives in create_servers.php;
// it is kept separate so the library can be required by other modules without
// declaring the page function a second time.
require_once __DIR__ . '/create_servers.php';

function exec_module()
{
	require_once __DIR__ . '/includes/provision_body.php';
	billing_run_provision_body();
}
