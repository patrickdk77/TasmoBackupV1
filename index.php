<?php
require_once(__DIR__.'/lib/functions.inc.php');

global $db_handle;
global $settings;

$task='';
$password='';
$user='admin';
if(isset($settings['tasmota_username'])) $user=$settings['tasmota_username'];
if (isset($_POST["task"])) {
    $task = $_POST["task"];
}
if (isset($_POST["user"])) {
    $user = $_POST["user"];
}
if (isset($_POST["password"])) {
    $password = $_POST["password"];
}
if (isset($_POST["ip"])) {
    $device = $ip = $_POST["ip"];
}
if (isset($_POST["name"])) {
    $name = $_POST["name"];
}

switch(strtolower($task)) {
    case 'discover':
        $show_modal = true;
        $output = '<center>'.addTasmotaDevice($ip, $user, $password).'<br></center>';
        break;
    case 'discoverall':
        $show_modal = true;
        $output = '<center>';
        if (!is_array($ip)) {
            $output .= t("You didn't select any devices.").'<br>';
        } else {
            foreach($ip as $i) {
                $output .= addTasmotaDevice($i, $user, $password).'<br>';
            }
        }
        $output .= '</center>';
        break;
    case 'edit':
        if (isset($_POST['oldip'])) {
            $old_ip = $_POST['oldip'];
        }
        if (isset($_POST['oldname'])) {
            $old_name = $_POST['oldname'];
        }
        if (isset($old_ip) && isset($ip)) {
            if (dbDeviceRename($old_ip, $name, $ip, $password)) {
                $show_modal = true;
                $output = '<center><b>'.
                    sprintf(t('%s updated successfully'), $name).
                    '</b><br></center>';
            } else {
                $show_modal = true;
                echo '<center><b>'.
                    sprintf(t('Error updating record for %1$s %2$s'),
                        $old_ip, $name).' <br>';
            }
        }
        break;
    case 'download':
        $device=dbDeviceId(intval($_POST["id"]));
        $backup=dbBackupId(intval($_POST["backupid"]));
        downloadTasmotaBackup($backup);
        break;
    case 'singlebackup':
        $show_modal = true;
        $output = '<center><b>'.
            sprintf(t('Device not found: %s'), $ip).'</b></center>';

        $devices = dbDeviceIp($ip);
        if ($devices!==false) {
            foreach ($devices as $db_field) {
                if (backupSingle($db_field['id'], $db_field['name'], $db_field['ip'], 'admin', $db_field['password'], $db_field['type'])) {
                    $show_modal = true;
                    $output = '<center><b>'.t('Backup failed').
                        '</b></center>';
                } else {
                    $show_model = true;
                    $output = t('Backup completed successfully!');
                }
            }
        }
        break;
    case 'backupall':
        $errorcount = backupAll();

        $show_modal = true;
        if(is_array($errorcount)) {
            if($errorcount[0]==0 && $errorcount[1]==0) {
                $output = t('All backups are up to date');
            }
            if($errorcount[0]==0 && $errorcount[1]>0) {
                $output = sprintf(tn('All %d backup completed successfully!',
                    'All %d backups completed successfully!',
                    $errorcount[1]), $errorcount[1]);
            }
            if($errorcount[0]>0 && $errorcount[1]>0) {
                $output = sprintf(t('%1$d backups failed out of %2$d backups attempted.'),
                    $errorcount[0], $errorcount[1]);
            }
        } else {
            if ($errorcount < 1) {
                $output = t('All backups completed successfully!');
            } else {
                $output = "<font color='red'><b>".
                    t('Not all backups completed successfully!').
                    "</b></font>";
            }
        }
        break;
    case 'delete':
        $show_modal = true;
        try {
            if (dbDeviceDel($ip)) {
                $output = sprintf(
                    t('%s deleted successfully from the database.'), $name);
            } else {
                $output = sprintf(t('Error deleting %s'), $ip);
            }
        } catch (PDOException $e) {
            $output = sprintf(t('Error deleting %1$s : %2$s'), $ip,
                $e->getMessage());
        }
        break;
    case 'noofbackups':
        $findname = preg_replace('/\s+/', '_', $name);
        $findname = preg_replace('/[^A-Za-z0-9\-]/', '', $findname);
        $directory = $settings['backup_folder'] . $findname;
        $scanned_directory = array_diff(scandir($directory), array('..','.'));
        $out = array();
        foreach ($scanned_directory as $value) {
            $link = strtolower(implode("-", explode(" ", $value)));
            $out[] = '<a href="' . $settings['backup_folder'] . $findname . '/' . $link . '">' . $link . '</a>';
        }
        $output = implode("<br>", $out);

        $show_modal = true;
        break;
    default:
        break;
}

// Column layout: NAME, IP, [HOSTNAME], [MAC], AUTH, VERSION, ...
// The stored sort setting numbers the columns as if both optional
// columns were hidden, so shift it by however many are on show.
$show_hostname = !(isset($settings['hide_hostname_column']) &&
    $settings['hide_hostname_column']=='Y');
$show_mac = !(isset($settings['hide_mac_column']) &&
    $settings['hide_mac_column']=='Y');
$optional_cols = ($show_hostname?1:0) + ($show_mac?1:0);
$version_col = 3 + $optional_cols;
$sort_col = isset($settings['sort'])?intval($settings['sort']):0;
if($sort_col >= 2)
    $sort_col += $optional_cols;

TBHeader(false,true,'
$(document).ready(function() {
        $(\'#status\').DataTable({
        "order": ['. $sort_col .', "asc" ],
        "pageLength": '. (isset($settings['amount'])?$settings['amount']:100) .',
        "columnDefs": [
            { "type": "ip-address", "targets": [1] },
            { "type": "version", "targets": ['. $version_col .'] }
            ],
        "stateSave": true,
        "autoWidth": false
} );
} );
',true);
?>
  <body style="scrollbar-gutter: stable;overflow-y:scroll;">
    <div class="container-fluid">
      <center><h4>TasmoBackup <a href="settings.php"><?php
	if(isset($settings['theme']) && $settings['theme']=='dark') { // Enforce Dark mode
	    echo '<img src="images/settings-dark.png">';
	} else if(isset($settings['theme']) && $settings['theme']=='light') { // Enforce Light mode
	    echo '<img src="images/settings.png">';
	} else { // auto mode
	    echo '<picture><source srcset="images/settings-dark.png" media="(prefers-color-scheme: dark)">';
	    echo '<source srcset="images/settings.png" media="(prefers-color-scheme: light), (prefers-color-scheme: no-preference)">';
	    echo '<img src="images/settings.png"></picture>';
	}
?></a></h4></center>
    <table class="table table-striped table-bordered" id="status">
    <thead>
      <tr><th><b><?php echo t('NAME'); ?></th><th><center><?php echo t('IP'); ?></center></th><?php if($show_hostname) { echo '<th><center>'.t('HOSTNAME').'</center></th>'; } if($show_mac) { echo '<th><center>'.t('MAC').'</center></th>'; } ?><th><center><?php echo t('AUTH'); ?></center></th><th><center><b><?php echo t('VERSION'); ?></b></center></th><th><center><?php echo t('LAST BACKUP'); ?></center></th><th><center><b><?php echo t('FILES'); ?></b></center></th><th><center><b><?php echo t('BACKUP'); ?></b></center></th><th><center><?php echo t('EDIT'); ?></center></th><th><center><b><?php echo t('DELETE'); ?></b></center></th></tr>
    </thead>
    <tbody>
<?php
    $github_tasmota_release_data = getGithubTasmotaReleaseData();

    $list_model='';
    $now=time();
    $lastbackup_green=0;
    $lastbackup_red=0;
    $lastbackup_yellow=0;
    if(isset($settings['backup_minhours']) && $settings['backup_minhours']>0) {
        $lastbackup_green=$now-(intval($settings['backup_minhours'])*3600*2.2);
        $lastbackup_red=$now-(intval($settings['backup_minhours'])*3600*8);
    }    
    $devices = dbDevicesSort();
    foreach ($devices as $db_field) {
        $id = $db_field['id'];
        $name = $db_field['name'];
        $ip = $db_field['ip'];
        if(isset($db_field['mac'])) {
            $mac = $db_field['mac'];
            $mac_display = $mac;
        } else {
            $mac = '';
            $mac_display = '&nbsp;';
        }
        $hostname = isset($db_field['hostname'])?$db_field['hostname']:'';
        if($hostname !== '') {
            $h = htmlspecialchars($hostname);
            $hostname_display = "<a href='http://".$h."' target='_blank'>".
                $h."</a>&nbsp&nbsp<a href='http://".$h.
                "/cs' target='_blank'><img src='images/newtab.png' ".
                "style='width:16px;' alt='".
                t('Open console in new tab')."'></a>";
        } else {
            $hostname_display = '&nbsp;';
        }
        $logo='images/tasmota.png';
        $type='Tasmota';
        if(isset($db_field['type']) && intval($db_field['type'])===1) {
            $logo='images/wled.png';
            $type='WLED';
        }
        if(isset($db_field['type']) && intval($db_field['type'])===2) {
            $logo='images/openbeken.png';
            $type='OpenBeken';
        }
        $version = $db_field['version'];
        $lastbackup = $db_field['lastbackup'];
        $numberofbackups = $db_field['noofbackups'];
        $password = $db_field['password'];

	$color='';
        if($lastbackup_green>0 && isset($lastbackup) && strlen($lastbackup)>10) {
            $ts=strtotime($lastbackup);
            if($ts<$lastbackup_red && $ts>0)
                $color='bg-danger text-white';
            if($ts>$lastbackup_red)
                $color='bg-warning text-dark';
            if($ts>$lastbackup_green)
                $color=''; //    $color='bg-success';
	}
	$mac_display='<td><center>'.$mac_display.'</center></td>';
        if(!$show_mac)
            $mac_display='';
        $hostname_display='<td><center>'.$hostname_display.'</center></td>';
        if(!$show_hostname)
            $hostname_display='';

        //echo "<tr valign='middle'><td onclick=\"deviceModal('#myModaldevice".$id."');\"><img src=\"" . $logo ."\" width=\"32\" height=\"32\" style=\"align:left\">&nbsp;" . $name . "</td><td><center><a href='http://" . $ip . "' target='_blank'>" . $ip . "</a>&nbsp&nbsp<img src='images/cli.png' alt='Open inline console' style='cursor: pointer;width:16px;margin-right:8px;' class='openConsole' data-ip='".$ip."' data-row='".$id."'><a href='http://".$ip."/cs' target='_blank'><img src='images/newtab.png' style='width:16px;' alt='Open console in new tab'></a></td>" . $mac_display . "<td><center>";
        echo "<tr valign='middle'><td onclick=\"deviceModal('#myModaldevice".$id."');\"><img src=\"" . $logo ."\" width=\"32\" height=\"32\" style=\"align:left\">&nbsp;" . $name . "</td><td><center><a href='http://" . $ip . "' target='_blank'>" . $ip . "</a>&nbsp&nbsp<a href='http://".$ip."/cs' target='_blank'><img src='images/newtab.png' style='width:16px;' alt='" . t('Open console in new tab') . "'></a></td>" . $hostname_display . $mac_display . "<td><center>";
	if(isset($settings['theme']) && $settings['theme']=='dark') { // Enforce Dark mode
	    echo "<img src='" . (strlen($password) > 0 ? 'images/lock-dark.png' : 'images/lock-open-variant-dark.png') . "'>";
	} else if(isset($settings['theme']) && $settings['theme']=='light') { // Enforce Light mode
	    echo "<img src='" . (strlen($password) > 0 ? 'images/lock.png' : 'images/lock-open-variant.png') . "'>";
	} else { // auto mode
	    if(strlen($password) >0) {
		echo '<picture><source srcset="images/lock-dark.png" media="(prefers-color-scheme: dark)">';
		echo '<source srcset="images/lock.png" media="(prefers-color-scheme: light), (prefers-color-scheme: no-preference)">';
		echo '<img src="images/lock.png"></picture>';
	    } else {
		echo '<picture><source srcset="images/lock-open-variant-dark.png" media="(prefers-color-scheme: dark)">';
		echo '<source srcset="images/lock-open-variant.png" media="(prefers-color-scheme: light), (prefers-color-scheme: no-preference)">';
		echo '<img src="images/lock-open-variant.png"></picture>';
	    }
	}
        $ver=$version;
        $tag='';
        $release_html = '';
        if(($pos=strpos($version,'('))>0) {
            $ver=substr($version,0,$pos);
            $tag=substr($version,$pos);
            $version=$ver.' <small>'.$tag.'</small>';

            if ( in_array($tag,array('(tasmota)','(lite)','(sensors)','(display)','(ir)','(knx)','(zbbridge)','(webcam)','(bluetooth)','(core2)')) ) {
                $github_tag_name = 'v' . $ver;
                foreach ( $github_tasmota_release_data as $release => $values ) {
                    $url = $values['html_url'];
                    if ( $values['tag_name'] == $github_tag_name ) {
                        break;
                    }
                }
            } else {
                // default to the Tasmota documentation if a custom "version" is in use
                $url = "https://tasmota.github.io/docs/";
            }
            if(isset($url) && strlen($url)>5)
                $version='<a href="'.$url.'">'.$version.'</a>';
        }
	$upgrade = '&nbsp;&nbsp;<a href="http://'.$ip.'/u1" target="_blank"><img src="images/upgrade.png" style="width:16px;" alt="'.t('Open upgrade in new tab').'"></a>';
	echo "</center></td><td><center>" . $version . $upgrade . "</center></td><td class='$color'><center>" . $lastbackup . "</center></td>";
	echo "<td data-sort='" . $numberofbackups . "'><center><form method='POST' action='listbackups.php'><input type='hidden' value='" . $name . "' name='name'><input type='hidden' value='" . $id . "' name='id'><button type='submit' class='btn btn-sm btn-info' title='" . htmlspecialchars(t('List backups to download or restore')) . "'>" . $numberofbackups . "</button></form></center></td>";
	echo "<td><center><form method='POST' action='index.php'><input type='hidden' value='" . $ip . "' name='ip'><input type='hidden' value='singlebackup' name='task'><button type='submit' class='btn btn-sm btn-success'>" . t('Backup') . "</button></form></center></td>";
	echo "<td><center><form method='POST' action='edit.php'><input type='hidden' value='" . $ip . "' name='ip'><input type='hidden' value='" . $name . "' name='name'><input type='hidden' value='edit' name='task'><button type='submit' class='btn btn-sm btn-warning'>" . t('Edit') . "</button></form></center></td>";
	echo "<td><center><form method='POST' id='deleteform' action='index.php'><input type='hidden' value='" . $ip . "' name='ip'><input type='hidden' value='" . $name . "' name='name'><input type='hidden' value='delete' name='task'><button type='submit' onclick='return window.confirm(\"" . sprintf(t('Are you sure you want to delete %s'), $name) . "\");' class='btn btn-sm btn-danger'>" . t('Delete') . "</button></form></center></td></tr>\r\n";
//        echo "<tr style='display:none'><td colspan='". ((isset($settings['hide_mac_column']) && $settings['hide_mac_column']=='Y')?'10':'11') ."'><iframe id='iframe".$id."' style='width:95vw;height:20vh' src=''></iframe></td></tr>";
// http://".$ip."/cs
        $list_model.='<div id="myModaldevice'.$id.'" class="modal fade" role="dialog"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h4 class="modal-title">'.$name.'</h4><button type="button" class="btn btn-sm close" data-bs-dismiss="modal">&times;</button></div><div class="modal-body"><p><pre>'."\r\n";
	$list_model.=sprintf("%14s: %s\r\n%14s: %s\r\n%14s: %s\r\n%14s: %s\r\n%14s: %s",t('Name'),$name,t('IP'),$ip,t('MAC'),$mac,t('Type'),$type,t('Version'),$ver);
	if(isset($tag))
            $list_model.=sprintf("\r\n%14s: %s",t('BuildTag'),$tag);
        $list_model.=sprintf("\r\n%14s: %s\r\n",t('Last Backup'),$lastbackup);
        $list_model.='</pre></p></div><div class="modal-footer"><button type="button" class="btn btn-default" data-bs-dismiss="modal">'.t('Close').'</button></div></div></div></div>'."\r\n";
    }

?>
           </tbody>
    </table>

<center><form method='POST' action='index.php'><input type='hidden' value='backupall' name='task'><button type='submit' class='btn btn-sm btn-success'><?php echo t('Backup All'); ?></button></form><br>
<form method="POST" action="scan.php"><input type=text name=range placeholder="192.168.1.1-255"><input type="password" name="password" placeholder="<?php echo t('password'); ?>" <?php if(isset($settings['tasmota_password'])) { echo 'value="'.$settings['tasmota_password'].'" '; } ?>><input type=hidden name=task value=scan><button style="min-width:200px" type=submit class='btn btn-sm btn-danger'><?php echo t('Discover'); ?></button></form>
<?php if(isset($settings['mqtt_host']) && isset($settings['mqtt_port']) && strlen($settings['mqtt_host'])>1) {
?>
<form method="POST" action="scan.php"><input type=text name=mqtt_topic value='<?php echo isset($settings['mqtt_topic'])?$settings['mqtt_topic']:'tasmotas'; ?>'><input type="password" name="password" placeholder="<?php echo t('password'); ?>" <?php if(isset($settings['tasmota_password'])) { echo 'value="'.$settings['tasmota_password'].'" '; } ?>><input type=hidden name=task value=mqtt><button style="min-width:200px" type=submit class='btn btn-sm btn-danger'><?php echo t('MQTT Discover'); ?></button></form>
<?php
}

TBFooter();
echo '</div>';

if(isset($list_model)) {
    echo $list_model;
?>
<script>
$(document).ready(function() {
    $('.openConsole').on('click', function(){
        let buttonClicked = $(this);
        buttonClicked.closest('tr').next('tr').toggle();
        let row = buttonClicked.data('row');
        let ip = buttonClicked.data('ip')
        $('#iframe'+row).attr('src', 'http://' + ip + '/cs');
    });
});

function deviceModal(modalId) {
  $(modalId).modal('show');
}
</script>
<?php
}

if (isset($show_modal) && $show_modal):
?>
   <script>
    $(document).ready(function(){
        $('#myModal').modal('show');
    });
    </script>
<?php
endif;
?>


<!-- Modal -->
<div id="myModal" class="modal fade" role="dialog">
  <div class="modal-dialog">

    <!-- Modal content-->
    <div class="modal-content">
      <div class="modal-header">
        
        <h4 class="modal-title">TasmoBackup</h4>
        <button type="button" class="btn btn-sm close" data-bs-dismiss="modal">&times;</button>
      </div>
      <div class="modal-body">
        <p style="align:center">
          <?php if (isset($output)) { echo $output; } ?>
          <br>
          <?php if (isset($output2)) { echo $output2; } ?>
        </p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-bs-dismiss="modal"><?php echo t('Close'); ?></button>
      </div>
    </div>

  </div>
</div>
