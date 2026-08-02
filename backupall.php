<?PHP

require_once(__DIR__.'/lib/functions.inc.php');

$errorcount=backupAll(isset($_REQUEST['docker']));

TBHeader(false,false,false,false);
?>
  <body>
    <div class="container-fluid">
<?php
        if(is_array($errorcount)) {
            if($errorcount[0]==0 && $errorcount[1]==0) {
                $output = t('All backups are up to date');
            }
            if($errorcount[0]==0 && $errorcount[1]>0) {
                $output = sprintf(
                    tn('All %d backup completed successfully!',
                        'All %d backups completed successfully!',
                        $errorcount[1]),
                    $errorcount[1]);
            }
            if($errorcount[0]>0 && $errorcount[1]>0) {
                $output = sprintf(
                    t('%1$d backups failed out of %2$d backups attempted.'),
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
?>
    </div>
<?php
TBFooter();
?>
</body>
</html>
