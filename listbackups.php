<?php
require_once(__DIR__.'/lib/functions.inc.php');

global $db_handle;
global $settings;

// Reached by POST from the device list, but a refresh or a bookmarked
// url arrives as a GET. Both were left undefined, which warned four
// times and then rendered a listing with no device.
$name = '';
$id = 0;
if (isset($_POST["name"])) {
    $name = $_POST["name"];
} else if (isset($_GET["name"])) {
    $name = $_GET["name"];
}
if (isset($_POST["id"])) {
    $id = intval($_POST["id"]);
} else if (isset($_GET["id"])) {
    $id = intval($_GET["id"]);
}
$device = dbDeviceId($id);
if ($name === '' && isset($device['name']))
    $name = $device['name'];
$output = '';
if (isset($_POST["task"])) {
    switch(strtolower($_POST["task"])) {
        case 'delbackup':
            dbBackupDel(intval($_POST["backupid"]));
            dbDeviceBackups($id);
            break;
        case 'lockbackup':
            dbBackupSetLocked(intval($_POST["backupid"]), true);
            break;
        case 'unlockbackup':
            dbBackupSetLocked(intval($_POST["backupid"]), false);
            break;
        case 'deleteselected':
            if (!isset($_POST['backupids']) || !is_array($_POST['backupids']) ||
                    count($_POST['backupids']) < 1) {
                $output = t("You didn't select any backups.");
            } else {
                $skipped = 0;
                foreach ($_POST['backupids'] as $bid) {
                    if (!dbBackupDel(intval($bid)))
                        $skipped++;
                }
                dbDeviceBackups($id);
                if ($skipped > 0) {
                    $output = sprintf(tn(
                        '%d locked backup was not deleted.',
                        '%d locked backups were not deleted.',
                        $skipped), $skipped);
                }
            }
            break;
        case 'downloadselected':
            if (!isset($_POST['backupids']) || !is_array($_POST['backupids']) ||
                    count($_POST['backupids']) < 1) {
                $output = t("You didn't select any backups.");
            } else if (!downloadSelectedBackupVersions($_POST['backupids'])) {
                // downloadSelectedBackupVersions exits on success, only
                // a failure to produce anything reaches this line.
                $output = t('None of the selected backups could be found.');
            }
            break;
        case 'lockselected':
            if (isset($_POST['backupids']) && is_array($_POST['backupids'])) {
                foreach ($_POST['backupids'] as $bid)
                    dbBackupSetLocked(intval($bid), true);
            }
            break;
        case 'unlockselected':
            if (isset($_POST['backupids']) && is_array($_POST['backupids'])) {
                foreach ($_POST['backupids'] as $bid)
                    dbBackupSetLocked(intval($bid), false);
            }
            break;
        case 'restorebackup':
            $device=dbDeviceId($id);
            $backup=dbBackupId(intval($_POST["backupid"]));
            $to=htmlspecialchars($device['ip']);
            if (restoreTasmotaBackup($device['ip'],'admin',$device['password'],$backup['filename'],$device['type'])) {
                // Tasmota reboots to apply a restored config, OpenBeken
                // applies pins and the startup command immediately.
                if (intval($device['type'])===2) {
                    $output = '<div class="alert alert-success">'
                        .sprintf(t('Restore sent to %s and applied'), $to)
                        .'</div>';
                } else {
                    $output = '<div class="alert alert-success">'
                        .sprintf(t('Restore sent to %s, the device reboots to apply it'), $to)
                        .'</div>';
                }
            } else {
                $output = '<div class="alert alert-danger">'
                    .sprintf(t('Restore to %s failed, the device did not accept the upload'), $to)
                    .'</div>';
            }
            break;
    }
}

TBHeader(t('List Backups'),true,'
$(document).ready(function() {
        $(\'#status\').DataTable({
        "order": [[1, "desc" ]],
        "pageLength": '. (isset($settings['amount'])?$settings['amount']:25) .',
        "columnDefs": [
            { "orderable": false, "searchable": false, "targets": [0] },
            { "type": "version", "targets": [3] }
            ],
        "stateSave": true,
        "autoWidth": false
} );

$(\'#tb_selectall\').on(\'change\', function() {
    $(\'.tb_rowcheck\').prop(\'checked\', this.checked);
    tb_updateBulkToolbar();
});
$(document).on(\'change\', \'.tb_rowcheck\', tb_updateBulkToolbar);

$(\'#tb_bulkform\').on(\'submit\', function(e) {
    var native = e.originalEvent;
    var task = (native && native.submitter && native.submitter.name === \'task\')
        ? native.submitter.value : \'\';
    if (task === \'deleteselected\' &&
            !window.confirm("'. addslashes(t('Are you sure you want to delete all selected backups')) .'")) {
        e.preventDefault();
        return false;
    }
} );
} );

function tb_updateBulkToolbar() {
    var n = $(\'.tb_rowcheck:checked\').length;
    $(\'#tb_bulkcount\').text(n);
    $(\'#tb_bulktoolbar\').toggle(n > 0);
    $(\'#tb_selectall\').prop(\'checked\',
        n > 0 && n === $(\'.tb_rowcheck\').length);
}
',true);
?>
  <body>

    <div class="container-fluid">
	<center><h4><a href="index.php">TasmoBackup</a> - <?php echo sprintf(t('Listing for %s'), $name); ?></h4></center>
<?php if($output !== '') echo $output; ?>
    <table class="table table-striped table-bordered" id="status">
    <thead>
		    <tr><th><center><input type='checkbox' id='tb_selectall' title='<?php echo htmlspecialchars(t('Select all')); ?>'></center></th><th><b><?php echo t('DATE'); ?></b></th><th><center><b><?php echo t('NAME'); ?></b></center></th><th><center><b><?php echo t('VERSION'); ?></b></center></th><th><center><b><?php echo t('FILE'); ?></b></center></th><th><center><b><?php echo t('DELETE'); ?></b><center></th><th><center><b><?php echo t('LOCK'); ?></b></center></th><th><center><b><?php echo t('RESTORE'); ?></b></center></th></tr>
    </thead>
    <tbody>
<?php

    $type=0;
    if(isset($device['type']))
        $type=intval($device['type']);

    $backups = dbBackupList($id);
    foreach ($backups as $db_field) {
        $backupid = $db_field['id'];
        $name = $db_field['name'];
        $version = $db_field['version'];
        $date = $db_field['date'];
        $filename = $db_field['filename'];
        $locked = intval($db_field['locked'])===1;

        if(($pos=strpos($version,'('))>0) {
            $ver=substr($version,0,$pos);
            $tag=substr($version,$pos);
            $version=$ver.' <small>'.$tag.'</small>';
        }
?>
<tr valign='middle'>
  <td><center><input type='checkbox' class='tb_rowcheck' name='backupids[]' value='<?php echo $backupid; ?>' form='tb_bulkform'></center></td>
  <td><?php echo $date; ?></td>
  <td><center><?php echo $name; ?></center></td>
  <td><center><?php echo $version; ?></center></td>
  <td><center>
    <form action='index.php' method='POST'>
    <input type='hidden' name='task' value='download'>
    <input type='hidden' name='backupid' value='<?php echo $backupid; ?>'>
    <input type='hidden' name='id' value='<?php echo $id; ?>'>
    <button type='submit' class='btn btn-sm btn-success'><?php echo t('Download'); ?></button>
    </form>
  </center></td>
  <td><center>
<?php if($locked) { ?>
    <button type='button' class='btn btn-sm btn-danger' disabled title='<?php echo htmlspecialchars(t('Locked backups cannot be deleted')); ?>'><?php echo t('Delete'); ?></button>
<?php } else { ?>
    <form action='listbackups.php' method='POST'>
    <input type='hidden' name='task' value='delbackup'>
    <input type='hidden' name='backupid' value='<?php echo $backupid; ?>'>
    <input type='hidden' name='id' value='<?php echo $id; ?>'>
    <input type='hidden' name='name' value='<?php echo $name; ?>'>
    <button type='submit' onclick='return window.confirm("<?php echo sprintf(t('Are you sure you want to delete %s'), $filename); ?>");' class='btn-sm btn-danger'><?php echo t('Delete'); ?></button>
    </form>
<?php } ?>
  </center></td>
  <td><center>
    <form action='listbackups.php' method='POST'>
    <input type='hidden' name='task' value='<?php echo $locked?'unlockbackup':'lockbackup'; ?>'>
    <input type='hidden' name='backupid' value='<?php echo $backupid; ?>'>
    <input type='hidden' name='id' value='<?php echo $id; ?>'>
    <input type='hidden' name='name' value='<?php echo $name; ?>'>
    <button type='submit' class='btn btn-sm <?php echo $locked?'btn-warning':'btn-outline-secondary'; ?>'><?php echo $locked?t('Unlock'):t('Lock'); ?></button>
    </form>
  </center></td>
<?php
        // All three device types can be restored now. WLED gained a
        // restore path in this release, via POST /upload.
        if(intval($type)===0 || intval($type)===1 || intval($type)===2) {
?>  <td><center>
    <form action='listbackups.php' method='POST'>
    <input type='hidden' name='task' value='restorebackup'>
    <input type='hidden' name='backupid' value='<?php echo $backupid; ?>'>
    <input type='hidden' name='id' value='<?php echo $id; ?>'>
    <input type='hidden' name='name' value='<?php echo $name; ?>'>
    <button type='submit' onclick='return window.confirm("<?php echo sprintf(t('Are you sure you want to restore %s to this device'), $filename); ?>");' class='btn btn-sm btn-danger'><?php echo t('Restore'); ?></button>
    </form>
  </center></td>
<?php
        } else { echo '<td>&nbsp;</td>'; }
        echo "\r\n</tr>\r\n";
    }

?>
    </tbody>
    </table>

    <form method='POST' action='listbackups.php' id='tb_bulkform'>
    <input type='hidden' name='id' value='<?php echo $id; ?>'>
    <input type='hidden' name='name' value='<?php echo isset($device['name'])?htmlspecialchars($device['name']):''; ?>'>
    <!-- Same spacing approach as index.php, margins not d-flex, so the
         inline display:none from jQuery .toggle() still hides it. -->
    <center id='tb_bulktoolbar' class='my-3' style='display:none'>
      <span class='me-2 align-middle'><span id='tb_bulkcount'>0</span> <?php echo t('selected'); ?>:</span>
      <button type='submit' name='task' value='deleteselected' class='btn btn-sm btn-danger me-2 mb-2'><?php echo t('Delete Selected'); ?></button>
      <button type='submit' name='task' value='downloadselected' class='btn btn-sm btn-success me-2 mb-2'><?php echo t('Download Selected'); ?></button>
      <button type='submit' name='task' value='lockselected' class='btn btn-sm btn-secondary me-2 mb-2'><?php echo t('Lock Selected'); ?></button>
      <button type='submit' name='task' value='unlockselected' class='btn btn-sm btn-outline-secondary mb-2'><?php echo t('Unlock Selected'); ?></button>
    </center>
    </form>
    </div>

<?php TBFooter();
?>
</body>
</html>
