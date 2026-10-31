<?php
/**
 * Placement capacity rule for choosing a machine for a game.
 *
 * headroom_*_pct is the most of a machine's total that may be in use after the
 * game is installed. A machine fits when, for each resource:
 *   (total - free) + required <= total * headroom_pct / 100
 *
 * $agentTotal and $agentFree use the keys cpu_cores, ram_mb and disk_gb.
 * $required uses the same keys. $headroomPct uses headroom_cpu_pct,
 * headroom_ram_pct and headroom_disk_pct.
 */
if (!function_exists('agent_fits_requirements')) {
    function agent_fits_requirements(array $agentTotal, array $agentFree, array $required, array $headroomPct): bool
    {
        $pairs = array(
            'cpu_cores' => 'headroom_cpu_pct',
            'ram_mb' => 'headroom_ram_pct',
            'disk_gb' => 'headroom_disk_pct',
        );
        foreach ($pairs as $resource => $pctKey) {
            $total = (float)($agentTotal[$resource] ?? 0);
            $free = (float)($agentFree[$resource] ?? 0);
            $need = (float)($required[$resource] ?? 0);
            $limit = $total * (float)($headroomPct[$pctKey] ?? 0) / 100.0;
            $usedAfter = ($total - $free) + $need;
            if ($usedAfter > $limit) {
                return false;
            }
        }
        return true;
    }
}
