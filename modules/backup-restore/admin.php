<?php
/*
 * Backup/Restore - restic admin page
 *
 * Stores the restic target storage and backup policy in the panel settings
 * table under restic_ keys. No passwords or keys are stored here.
 */

function restic_admin_defaults()
{
    return array(
        'restic_target_host'       => 'dr.isaserver.net',
        'restic_target_user'       => 'leroy',
        'restic_target_path'       => '/srv/restic-dev',
        'restic_policy_mode'       => 'manual',   // manual | daily | every_x_days
        'restic_policy_every_days' => '7',
        'restic_initial_backup'    => '0',        // 1 = baseline snapshot at install
    );
}

function restic_admin_load_settings()
{
    global $db;
    $settings = array();
    foreach (restic_admin_defaults() as $key => $default)
    {
        $value = $db->getSetting($key);
        $settings[$key] = ($value === FALSE || $value === '') ? $default : $value;
    }
    return $settings;
}

function restic_admin_validate($input, &$errors)
{
    $host = trim((string)$input['restic_target_host']);
    $user = trim((string)$input['restic_target_user']);
    $path = rtrim(trim((string)$input['restic_target_path']), '/');
    $mode = (string)$input['restic_policy_mode'];
    $days = (int)$input['restic_policy_every_days'];

    if (!preg_match('/^[A-Za-z0-9.-]+$/', $host))
        $errors[] = 'Target host may only contain letters, digits, dots and hyphens.';
    if (!preg_match('/^[a-z_][a-z0-9_-]*$/', $user))
        $errors[] = 'Target user name is not valid.';
    if (!preg_match('#^/[A-Za-z0-9._/-]+$#', $path) || strpos($path, '..') !== FALSE)
        $errors[] = 'Target path must be an absolute path with no spaces and no "..".';
    if (!in_array($mode, array('manual', 'daily', 'every_x_days'), TRUE))
        $errors[] = 'Unknown backup policy.';
    if ($mode === 'every_x_days' && ($days < 1 || $days > 365))
        $errors[] = 'Every X days must be between 1 and 365.';

    return array(
        'restic_target_host'       => $host,
        'restic_target_user'       => $user,
        'restic_target_path'       => $path,
        'restic_policy_mode'       => $mode,
        'restic_policy_every_days' => (string)max(1, min(365, $days)),
        'restic_initial_backup'    => isset($input['restic_initial_backup']) ? '1' : '0',
    );
}

function exec_gsp_module()
{
    global $db;

    $errors = array();
    if (isset($_POST['restic_save']))
    {
        $clean = restic_admin_validate($_POST, $errors);
        if (empty($errors))
        {
            $db->setSettings($clean);
            echo '<center><div class="ok">Backup settings saved.</div></center>';
        }
    }

    $current = restic_admin_load_settings();

    echo '<center><h3>Backup/Restore - Admin Settings</h3></center>';
    foreach ($errors as $error)
        echo '<center><div class="error">'.htmlspecialchars($error).'</div></center>';

    $modes = array('manual' => 'Manual only', 'daily' => 'Daily', 'every_x_days' => 'Every X days');
    $initial = ($current['restic_initial_backup'] === '1') ? ' checked' : '';

    echo '<center><form method="POST" action="?m=backup-restore&amp;p=admin">';
    echo '<table>';
    echo '<tr><td>Target host</td><td><input type="text" name="restic_target_host" value="'.htmlspecialchars($current['restic_target_host']).'"></td></tr>';
    echo '<tr><td>Target user</td><td><input type="text" name="restic_target_user" value="'.htmlspecialchars($current['restic_target_user']).'"></td></tr>';
    echo '<tr><td>Target path</td><td><input type="text" name="restic_target_path" value="'.htmlspecialchars($current['restic_target_path']).'"></td></tr>';
    echo '<tr><td>Backup policy</td><td><select name="restic_policy_mode">';
    foreach ($modes as $value => $label)
    {
        $selected = ($current['restic_policy_mode'] === $value) ? ' selected' : '';
        echo '<option value="'.$value.'"'.$selected.'>'.$label.'</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><td>Every X days</td><td><input type="number" min="1" max="365" name="restic_policy_every_days" value="'.htmlspecialchars($current['restic_policy_every_days']).'"></td></tr>';
    echo '<tr><td>Initial backup at install</td><td><input type="checkbox" name="restic_initial_backup" value="1"'.$initial.'></td></tr>';
    echo '</table><br>';
    echo '<button type="submit" name="restic_save" value="1">Save</button>';
    echo '</form></center>';
}
