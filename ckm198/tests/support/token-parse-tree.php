<?php
if(PHP_SAPI!=='cli' || PHP_MAJOR_VERSION!==8 || PHP_MINOR_VERSION!==3)exit(2);
$root=realpath($argv[1]??dirname(__DIR__,2));
if(!$root)exit(2);
$files=[];$failures=[];
$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
foreach($iterator as $item)if($item->isFile()&&str_ends_with($item->getFilename(),'.php'))$files[]=$item->getPathname();
sort($files);
foreach($files as $file){
    try{token_get_all(file_get_contents($file),TOKEN_PARSE);}
    catch(Throwable $error){$failures[]=['file'=>substr($file,strlen($root)+1),'error'=>$error->getMessage()];}
}
echo json_encode(['check'=>'PHP 8.3 TOKEN_PARSE','php'=>PHP_VERSION,'total'=>count($files),
    'passed'=>count($files)-count($failures),'failed'=>count($failures),'failures'=>$failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),"\n";
exit($failures?1:0);
