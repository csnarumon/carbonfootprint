<?php 
if (extension_loaded('sqlsrv')) {
    echo 'sqlsrv OK — version: ' . phpversion('sqlsrv');
} else {
    echo 'sqlsrv NOT loaded';
    echo '<br>PHP version: ' . PHP_VERSION;
    echo '<br>Extension dir: ' . ini_get('extension_dir');
}

?>