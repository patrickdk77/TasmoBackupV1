<?php

require_once(__DIR__.'/lib/functions.inc.php');

global $db_handle;
global $settings;

if (isset($_POST["sortoption"])) {
    dbSettingsUpdate('sort',intval($_POST["sortoption"]));
}

if (isset($_POST["amountoption"])) {
    dbSettingsUpdate('amount',intval($_POST["amountoption"]));
}
if (isset($_POST['mqtt_host'])) {
    dbSettingsUpdate('mqtt_host',$_POST['mqtt_host']);
}
if (isset($_POST['mqtt_port'])) {
    dbSettingsUpdate('mqtt_port',intval($_POST['mqtt_port']));
}
if (isset($_POST['mqtt_user'])) {
    dbSettingsUpdate('mqtt_user',$_POST['mqtt_user']);
}
if (isset($_POST['mqtt_password'])) {
    dbSettingsUpdate('mqtt_password',$_POST['mqtt_password']);
}
if (isset($_POST['mqtt_topic'])) {
    dbSettingsUpdate('mqtt_topic',trim($_POST['mqtt_topic']," \t\n\r\0\v/"));
}
if (isset($_POST['mqtt_topic_format'])) {
    dbSettingsUpdate('mqtt_topic_format',trim($_POST['mqtt_topic_format']," \t\n\r\0\v/"));
}
if (isset($_POST['backup_minhours'])) {
    dbSettingsUpdate('backup_minhours',intval($_POST['backup_minhours']));
}
if (isset($_POST['backup_maxdays'])) {
    dbSettingsUpdate('backup_maxdays',intval($_POST['backup_maxdays']));
}
if (isset($_POST['backup_maxcount'])) {
    dbSettingsUpdate('backup_maxcount',intval($_POST['backup_maxcount']));
}
if (isset($_POST['backup_folder'])) {
    dbSettingsUpdate('backup_folder',$_POST['backup_folder']);
}
if (isset($_POST['tasmota_password'])) {
    dbSettingsUpdate('tasmota_password',$_POST['tasmota_password']);
}
if (isset($_POST['autoupdate_name'])) {
    if (in_array(strtolower($_POST['autoupdate_name']),array('y','yes','true','t')))
        dbSettingsUpdate('autoupdate_name','Y');
    else
        dbSettingsUpdate('autoupdate_name','N');
}
if (isset($_POST['autoadd_scan'])) {
    if (in_array(strtolower($_POST['autoadd_scan']),array('y','yes','true','t')))
        dbSettingsUpdate('autoadd_scan','Y');
    else
        dbSettingsUpdate('autoadd_scan','N');
}
if (isset($_POST['theme'])) {
    if (in_array(strtolower($_POST['theme']),array('light','dark','auto')))
        dbSettingsUpdate('theme',strtolower($_POST['theme']));
}
if (isset($_POST['use_topic_as_name'])) {
    if (in_array(strtolower($_POST['use_topic_as_name']),array('y','yes','true','t')))
        dbSettingsUpdate('use_topic_as_name','Y');
    else if (in_array(strtolower($_POST['use_topic_as_name']),array('f','full')))
        dbSettingsUpdate('use_topic_as_name','F');
    else
        dbSettingsUpdate('use_topic_as_name','N');
}
if (isset($_POST['hide_mac_column'])) {
    if (in_array(strtolower($_POST['hide_mac_column']),array('y','yes','true','t')))
        dbSettingsUpdate('hide_mac_column','Y');
    else
        dbSettingsUpdate('hide_mac_column','N');
}
if (isset($_POST['language'])) {
    $want = strtolower(trim($_POST['language']));
    if ($want === 'auto' || tbMatchLanguage($want) !== false)
        dbSettingsUpdate('language', $want);
    // Reload the page text in the language just chosen.
    tbSetupLocale();
}
if (isset($_POST['hide_hostname_column'])) {
    if (in_array(strtolower($_POST['hide_hostname_column']),array('y','yes','true','t')))
        dbSettingsUpdate('hide_hostname_column','Y');
    else
        dbSettingsUpdate('hide_hostname_column','N');
}
if (isset($_POST['backup_berry'])) {
    if (in_array(strtolower($_POST['backup_berry']),array('y','yes','true','t')))
        dbSettingsUpdate('backup_berry','Y');
    else
        dbSettingsUpdate('backup_berry','N');
}
if (isset($_POST['debug'])) {
    if (in_array(strtolower($_POST['debug']),array('y','yes','true','t')))
        dbSettingsUpdate('debug','Y');
    else
        dbSettingsUpdate('debug','N');
}


TBHeader(t('Settings'),true,'
$(document).ready(function() {
        $(\'#status\').DataTable({
        "order": [],
        "pageLength": '. (isset($settings['amount'])?$settings['amount']:25) .',
        "stateSave": true,
        "autoWidth": false
} );
} );
',true);
?>
  <body>

    <div class="container-fluid">
        <center><h4><a href="index.php">TasmoBackup</a> - <?php echo t('Settings'); ?></h4></center>
        <form method='POST' action='settings.php'>
            <table class="table table-striped table-bordered" id="status" >
                <thead>
                    <tr><th><?php echo t('Setting'); ?></th><th><?php echo t('Value'); ?></th></tr>
                </thead>
                <tbody>
                <tr valign='middle'><td align="right"><?php echo t('Sort Column'); ?></td><td><select name ="sortoption"><option value="0" <?php if(isset($settings['sort']) && $settings['sort']==0) { echo 'selected="selected"'; } ?>><?php echo t('Name'); ?></option><option value="1" <?php if(isset($settings['sort']) && $settings['sort']==1) { echo 'selected="selected"'; } ?>><?php echo t('IP'); ?></option><option value="2" <?php if(isset($settings['sort']) && $settings['sort']==2) { echo 'selected="selected"'; } ?>><?php echo t('Auth'); ?></option><option value="3" <?php if(isset($settings['sort']) && $settings['sort']==3) { echo 'selected="selected"'; } ?>><?php echo t('Version'); ?></option><option value="4" <?php if(isset($settings['sort']) && $settings['sort']==4) { echo 'selected="selected"'; } ?>><?php echo t('Last Backup'); ?></option></select></td></tr>
                    <tr valign='middle'><td align="right"><?php echo t('Amount of Rows'); ?></td><td><input type='text' name='amountoption' value='<?php echo isset($settings['amount'])?$settings['amount']:100; ?>'></td></tr>
                    <tr valign='middle'><td align="right"><?php echo t('Theme (light or dark or auto)'); ?></td><td><input type="text" name='theme' value='<?php echo isset($settings['theme'])?$settings['theme']:'auto'; ?>'></td></tr>
                    <tr valign='middle'><td align="right"><?php echo t('Tasmota Default Password for web login on devices'); ?></td><td><input type="password" name='tasmota_password' value='<?php if(isset($settings['tasmota_password'])) echo $settings['tasmota_password']; ?>'></td></tr>
                    <tr valign='middle'><td align="right"><?php echo t('Update Device Name when doing Backups (Y or N)'); ?></td><td><input type="text" name='autoupdate_name' value='<?php echo isset($settings['autoupdate_name'])?$settings['autoupdate_name']:'Y'; ?>'></td></tr>
                    <tr valign='middle'><td align="right"><?php echo t('Automatically Add New Devices (Y or N)'); ?></td><td><input type="text" name='autoadd_scan' value='<?php echo isset($settings['autoadd_scan'])?$settings['autoadd_scan']:'N'; ?>'></td></tr>
                    <tr valign='middle'><td align="right"><?php echo t('Use MQTT Topic as Device Name (Y or N or F (Full))'); ?></td><td><input type="text" name='use_topic_as_name' value='<?php echo isset($settings['use_topic_as_name'])?$settings['use_topic_as_name']:'N'; ?>'></td></tr>
                    <tr valign='middle'><td align="right"><?php echo t('Hide MAC Address column on index page (Y or N)'); ?></td><td><input type="text" name='hide_mac_column' value='<?php echo isset($settings['hide_mac_column'])?$settings['hide_mac_column']:'N'; ?>'></td></tr>
                    <tr valign='middle'><td align="right"><?php echo t('Language'); ?></td><td><select name='language'>
<?php
    $cur = isset($settings['language'])?strtolower($settings['language']):'auto';
    echo "<option value='auto'".($cur==='auto'?" selected='selected'":"").">".
        t('Automatic (browser)')."</option>";
    foreach (tbLanguages() as $code => $label) {
        echo "<option value='".htmlspecialchars($code)."'".
            ($cur===strtolower($code)?" selected='selected'":"").">".
            htmlspecialchars($label)."</option>";
    }
?>
                    </select></td></tr>
                    <tr valign='middle'><td align="right"><?php echo t('Hide Hostname column on index page (Y or N)'); ?></td><td><input type="text" name='hide_hostname_column' value='<?php echo isset($settings['hide_hostname_column'])?$settings['hide_hostname_column']:'N'; ?>'></td></tr>
                    <tr valign='middle'><td align="right"><?php echo t('Backup Tasmota32 Berry scripts (Y or N)'); ?></td><td><input type="text" name='backup_berry' value='<?php echo isset($settings['backup_berry'])?$settings['backup_berry']:'Y'; ?>'></td></tr>
                    <tr valign='middle'><td align="right"><?php echo t('Debug Logging to the container log (Y or N)'); ?></td><td><input type="text" name='debug' value='<?php echo isset($settings['debug'])?$settings['debug']:'N'; ?>'></td></tr>
                    <tr valign='middle'><td align="right"><?php echo t('MQTT Host'); ?></td><td><input type="text" name='mqtt_host' value='<?php if(isset($settings['mqtt_host'])) echo $settings['mqtt_host']; ?>'></td></tr>
                    <tr valign='middle'><td align="right"><?php echo t('MQTT Port'); ?></td><td><input type="text" name='mqtt_port' value='<?php echo isset($settings['mqtt_port'])?$settings['mqtt_port']:1883; ?>'></td></tr>
                    <tr valign='middle'><td align="right"><?php echo t('MQTT Username'); ?></td><td><input type="text" name='mqtt_user' value='<?php if(isset($settings['mqtt_user'])) echo $settings['mqtt_user']; ?>'></td></tr>
                    <tr valign='middle'><td align="right"><?php echo t('MQTT Password'); ?></td><td><input type="password" name='mqtt_password' value='<?php if(isset($settings['mqtt_password'])) echo $settings['mqtt_password']; ?>'></td></tr>
                    <tr valign='middle'><td align="right"><?php echo t('MQTT Topic'); ?></td><td><input type="text" name='mqtt_topic' value='<?php echo isset($settings['mqtt_topic'])?$settings['mqtt_topic']:'tasmotas'; ?>'></td></tr>
                    <tr valign='middle'><td align="right"><?php echo t('MQTT Topic Format'); ?></td><td><input type="text" name='mqtt_topic_format' value='<?php echo isset($settings['mqtt_topic_format'])?$settings['mqtt_topic_format']:'%prefix%/%topic%'; ?>'></td></tr>
                    <tr valign='middle'><td align="right"><?php echo t('Backup-All Min Hours between backups'); ?></td><td><input type="text" name='backup_minhours' value='<?php echo isset($settings['backup_minhours'])?$settings['backup_minhours']:'23'; ?>'></td></tr>
                    <tr valign='middle'><td align="right"><?php echo t('Backup Max Days Old to keep'); ?></td><td><input type="text" name='backup_maxdays' value='<?php echo isset($settings['backup_maxdays'])?$settings['backup_maxdays']:''; ?>'></td></tr>
                    <tr valign='middle'><td align="right"><?php echo t('Backup Max Count to keep'); ?></td><td><input type="text" name='backup_maxcount' value='<?php echo isset($settings['backup_maxcount'])?$settings['backup_maxcount']:''; ?>'></td></tr>
                    <tr valign='middle'><td align="right"><?php echo t('Backup Data Directory'); ?></td><td><input type="text" name='backup_folder' value='<?php echo $settings['backup_folder']; ?>'></td></tr>
                </tbody>
                <tfoot>
                    <tr><td>&nbsp;</td><td><button type='submit' class='btn btn-sm btn-success'><?php echo t('Save'); ?></button></td></tr>
                </tfoot>
            </table>
        </form>
        <hr>
        <table >
            <tr valign='middle'>
                <td><?php echo t('Export Devices'); ?></td>
                <td style="padding-left:8px;">
                    <form method='POST' action='export.php'>
                        <input type="hidden" name="export" value="export">
                        <select name ="sortoption">
                            <option value="0"><?php echo t('CSV'); ?></option>
                </td>
                <td style="padding-left:8px;"><button type='submit' class='btn btn-sm btn-success'><?php echo t('Submit'); ?></button></form></td>
            </tr>
        </table>
    </div>
<?php
TBFooter();
