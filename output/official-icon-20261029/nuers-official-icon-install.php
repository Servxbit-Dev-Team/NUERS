<?php
if(PHP_SAPI!=='cli')exit(1);umask(0077);
$base=__DIR__;$root=$base.'/httpdocs';if(basename($base)!=='nuers.net')exit(1);
$mode=$argv[1]??'check';if(!in_array($mode,['check','install']))exit(1);
$lock=fopen($base.'/nuers-compliance.lock','c');if(!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Deployment in progress');
$zipPath=$base.'/nuers-official-icon-20261029.zip';
if(hash_file('sha256',$zipPath)!==trim(file_get_contents($base.'/nuers-official-icon.sha256')))throw new RuntimeException('Package checksum mismatch');
$z=new ZipArchive;if($z->open($zipPath)!==true)throw new RuntimeException('Cannot open package');
$m=json_decode($z->getFromName('manifest.json'),true,512,JSON_THROW_ON_ERROR);$stage=$base.'/nuers-official-icon-stage-20261029';if(!is_dir($stage))mkdir($stage,0700);
$allowed='#^(?:assets/|dist/assets/|public/build/|(?:public/|dist/)?(?:icon\.png|nuers-favicon\.png|favicon\.ico|apple-touch-icon\.png|app-icon-(?:192|512)\.png|site\.webmanifest(?:\.json)?)$|(?:dist/)?index\.html$|resources/views/(?:app\.blade\.php|errors/429\.blade\.php)$|src/components/enterprise/enterprise-shell\.tsx$|vite\.static\.config\.ts$)#';
for($i=0;$i<$z->numFiles;$i++){$p=$z->getNameIndex($i);if($p!=='manifest.json'&&(!isset($m['files'][$p])||!preg_match($allowed,$p)||in_array('..',explode('/',$p))))throw new RuntimeException('Unexpected package path');}
$z->extractTo($stage);$z->close();
foreach($m['files']as$p=>$h){if(hash_file('sha256',$stage.'/'.$p)!==$h)throw new RuntimeException('Invalid staged hash');$actual=is_file($root.'/'.$p)?hash_file('sha256',$root.'/'.$p):null;if($actual!==$h&&$actual!==$m['baseline'][$p])throw new RuntimeException('Unexpected live drift: '.$p);}
if($mode==='check'){echo json_encode(['preflight'=>true,'files'=>count($m['files']),'entry'=>$m['entry']]);exit;}
$backup=$base.'/nuers-official-icon-backup-'.gmdate('Ymd-His');mkdir($backup,0700);$z=new ZipArchive;$z->open($backup.'/previous-files.zip',ZipArchive::CREATE);
foreach($m['files']as$p=>$h)if(is_file($root.'/'.$p))$z->addFile($root.'/'.$p,$p);$z->close();chmod($backup.'/previous-files.zip',0600);
try{
 $names=array_keys($m['files']);usort($names,fn($a,$b)=>(int)in_array($a,['index.html','dist/index.html','public/build/manifest.json'])<=>(int)in_array($b,['index.html','dist/index.html','public/build/manifest.json']));
 foreach($names as$p){$dest=$root.'/'.$p;$dir=dirname($dest);if(!is_dir($dir))mkdir($dir,0755,true);$tmp=$dest.'.official-icon';if(!copy($stage.'/'.$p,$tmp))throw new RuntimeException('Copy failed');chmod($tmp,0644);if(!rename($tmp,$dest))throw new RuntimeException('Promote failed');}
 foreach($m['files']as$p=>$h)if(hash_file('sha256',$root.'/'.$p)!==$h)throw new RuntimeException('Installed hash mismatch');
 $result=['deployed'=>true,'release'=>$m['release'],'files_verified'=>count($m['files']),'entry'=>$m['entry'],'database_untouched'=>true,'old_assets_retained'=>true,'backup'=>basename($backup),'deployed_at'=>gmdate('c')];
 file_put_contents($base.'/nuers-official-icon-result.json',json_encode($result,JSON_PRETTY_PRINT));echo json_encode($result);
}catch(Throwable$e){$z=new ZipArchive;if($z->open($backup.'/previous-files.zip')===true){$z->extractTo($root);$z->close();}echo json_encode(['deployed'=>false,'previous_files_restored'=>true,'error'=>$e->getMessage()]);exit(1);}
