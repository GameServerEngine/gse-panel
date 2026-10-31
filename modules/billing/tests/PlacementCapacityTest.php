<?php
require_once __DIR__ . '/../includes/placement_capacity.php';

function placement_assert($ok, $msg)
{
    if (!$ok) {
        throw new Exception($msg);
    }
}

$pct = array('headroom_cpu_pct' => 70, 'headroom_ram_pct' => 70, 'headroom_disk_pct' => 85);
$total = array('cpu_cores' => 8, 'ram_mb' => 16384, 'disk_gb' => 500);

placement_assert(agent_fits_requirements($total, array('cpu_cores' => 8, 'ram_mb' => 16384, 'disk_gb' => 500), array('cpu_cores' => 2, 'ram_mb' => 4096, 'disk_gb' => 50), $pct) === true, 'fits with room');
placement_assert(agent_fits_requirements($total, array('cpu_cores' => 8, 'ram_mb' => 6000, 'disk_gb' => 500), array('cpu_cores' => 2, 'ram_mb' => 2048, 'disk_gb' => 50), $pct) === false, 'ram over the limit is refused');
placement_assert(agent_fits_requirements($total, array('cpu_cores' => 4, 'ram_mb' => 16384, 'disk_gb' => 500), array('cpu_cores' => 2, 'ram_mb' => 4096, 'disk_gb' => 50), $pct) === false, 'cpu over the limit is refused');
placement_assert(agent_fits_requirements($total, array('cpu_cores' => 3, 'ram_mb' => 5000, 'disk_gb' => 80), array('cpu_cores' => 0, 'ram_mb' => 0, 'disk_gb' => 0), $pct) === true, 'zero requirements fit while under the limit');

$boundaryTotal = array('cpu_cores' => 10, 'ram_mb' => 10000, 'disk_gb' => 100);
$need = array('cpu_cores' => 2, 'ram_mb' => 1000, 'disk_gb' => 35);
placement_assert(agent_fits_requirements($boundaryTotal, array('cpu_cores' => 5, 'ram_mb' => 4000, 'disk_gb' => 50), $need, $pct) === true, 'exactly at the limit fits');
placement_assert(agent_fits_requirements($boundaryTotal, array('cpu_cores' => 5, 'ram_mb' => 4000, 'disk_gb' => 49), $need, $pct) === false, 'one GB over the disk limit is refused');
placement_assert(agent_fits_requirements($boundaryTotal, array('cpu_cores' => 5, 'ram_mb' => 3999, 'disk_gb' => 50), array('cpu_cores' => 2, 'ram_mb' => 1000, 'disk_gb' => 35), $pct) === false, 'one MB over the RAM limit is refused');

echo "PlacementCapacityTest passed\n";
