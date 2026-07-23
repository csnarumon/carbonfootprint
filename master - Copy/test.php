<?php
$dir = dirname(__DIR__) . '/uploads/assets/equipment/';
echo "Path: " . $dir . "<br>";
echo "Exists: " . (is_dir($dir) ? 'YES' : 'NO') . "<br>";
$files = glob($dir . '*.png');
echo "Files: " . count($files) . "<br>";
if ($files) echo $files[0];?>