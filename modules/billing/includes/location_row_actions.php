<?php
// Row actions for one location in admin_locations.php. Expects $row, $csrf (set by the caller).
?>
<form method="post" style="display:inline">
<input type="hidden" name="csrf" value="<?php echo loc_h($csrf); ?>">
<input type="hidden" name="location_id" value="<?php echo (int)$row['location_id']; ?>">
<button name="action" value="toggle"><?php echo $row['enabled'] ? 'Disable' : 'Enable'; ?></button>
<button name="action" value="delete">Delete</button>
</form>
