<?php
if(PHP_SAPI!=='cli') exit;
$root=dirname(__DIR__); $release=$root.'/release';
if(!is_dir($release)) mkdir($release);
file_put_contents($release.'/.htaccess',"Require all denied\n");
$zip=new ZipArchive();
if($zip->open($release.'/assembly-hostinger.zip',ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) throw new RuntimeException('Cannot create archive');
foreach(['index.php','setup.php','config.example.php','composer.json','composer.lock','README.md','.htaccess'] as $file) $zip->addFile($root.'/'.$file,$file);
foreach(['app','assets','vendor'] as $dir) {
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir,FilesystemIterator::SKIP_DOTS));
    foreach($it as $file) if($file->isFile()) { $relative=str_replace('\\','/',substr($file->getPathname(),strlen($root)+1)); $zip->addFile($file->getPathname(),$relative); }
}
$zip->addFile($root.'/storage/.htaccess','storage/.htaccess');
$zip->addFromString('storage/.gitkeep','');
$zip->close();
$zip=new ZipArchive(); $zip->open($release.'/assembly-hostinger.zip');
for($i=0;$i<$zip->numFiles;$i++) {
    $name=$zip->getNameIndex($i);
    if($name==='config.local.php' || preg_match('~(^|/)(mail\.log|setup-key\.txt|sess_|http-test-)~',$name)) throw new RuntimeException('Private file included');
}
echo 'Release ready: release/assembly-hostinger.zip ('.$zip->numFiles." files; private data excluded).\n";
$zip->close();
