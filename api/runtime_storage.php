<?php
declare(strict_types=1);

const YC_RUNTIME_RETENTION_DAYS = 3;
const YC_RUNTIME_MAX_LOG_CONTEXT_BYTES = 12288;

function ycRuntimeRoot(): string {
    $dir=dirname(__DIR__).DIRECTORY_SEPARATOR.'logs';
    if(is_link($dir))throw new RuntimeException('Runtime log root must not be a symlink.');
    if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Runtime log root could not be created.');
    return $dir;
}
function ycRuntimeDir(string $kind): string {
    if(!in_array($kind,['nms','health','raw-nms','state'],true))throw new InvalidArgumentException('Unknown runtime storage kind.');
    $dir=ycRuntimeRoot().DIRECTORY_SEPARATOR.$kind;
    if(is_link($dir))throw new RuntimeException('Runtime storage directory must not be a symlink.');
    if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Runtime storage directory could not be created.');
    return $dir;
}
function ycRuntimeStatePath(string $name): string {
    if(!preg_match('/^[A-Za-z0-9_.-]+$/D',$name))throw new InvalidArgumentException('Invalid state filename.');
    return ycRuntimeDir('state').DIRECTORY_SEPARATOR.$name;
}
function ycRuntimeReadJson(string $path,int $maxBytes=1048576): ?array {
    if(!is_file($path)||is_link($path))return null;$size=@filesize($path);if($size===false||$size<1||$size>$maxBytes)return null;
    $fh=@fopen($path,'rb');if(!$fh)return null;try{$raw=stream_get_contents($fh,$maxBytes+1);}finally{fclose($fh);}
    if(!is_string($raw)||strlen($raw)>$maxBytes)return null;$decoded=json_decode($raw,true);return is_array($decoded)?$decoded:null;
}
function ycRuntimeWriteJson(string $path,array $value): void {
    $json=json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);if($json===false)throw new RuntimeException('Runtime state could not be encoded.');
    $dir=dirname($path);if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Runtime state directory could not be created.');
    $tmp=$path.'.tmp.'.getmypid().'.'.bin2hex(random_bytes(4));if(@file_put_contents($tmp,$json,LOCK_EX)===false)throw new RuntimeException('Runtime state could not be written.');@chmod($tmp,0600);
    if(!@rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('Runtime state could not be committed.');}@chmod($path,0600);
}
function ycNmsStatePath(string $environment): string {$safe=preg_replace('/[^A-Za-z0-9_.-]+/','_',$environment);return ycRuntimeStatePath('cron_state_'.$safe.'.json');}
function ycLegacyNmsIssues(array $state): array {
    $at=trim((string)($state['updatedAt']??''));$ts=$at!==''?strtotime($at):false;if($ts===false||$ts<time()-3*86400)return[];$at=gmdate('Y-m-d\TH:i:s\Z',$ts);$items=[];
    $append=static function(string$type,string$severity,string$summary,array$details=[])use(&$items,$at,$state):void{$basis=[$type,$summary,$details];$items[]=['type'=>$type,'severity'=>$severity,'summary'=>$summary,'details'=>$details,'firstAt'=>$at,'lastAt'=>$at,'occurrences'=>1,'lastRunId'=>$state['runId']??null,'fingerprint'=>hash('sha256',json_encode($basis,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:serialize($basis))];};
    $error=trim((string)($state['error']??''));if(($state['ok']??null)===false&&$error!==''&&empty($state['rateLimitedLocally']))$append('error','error',$error,['mode'=>$state['mode']??null]);
    $skipped=(int)($state['skipped']??0);if($skipped>0)$append('skipped','warning',$skipped.' NOTAM(s) skipped in the cron run.',['count'=>$skipped,'mode'=>$state['mode']??null]);
    $geometry=(int)($state['geometryWarnings']??0);if($geometry>0)$append('geometry','warning',$geometry.' geometry warning(s) in the cron run.',['count'=>$geometry]);
    $diag=trim((string)($state['geometryDiagnostics']['error']??''));if($diag!=='')$append('geometry-diagnostics','warning',$diag);
    return$items;
}
function ycReadNmsState(string $environment): ?array {
    $path=ycNmsStatePath($environment);$state=ycRuntimeReadJson($path,2097152);
    if(is_array($state)){
        if(!isset($state['recentIssues'])||!is_array($state['recentIssues'])){$state['recentIssues']=ycLegacyNmsIssues($state);try{ycRuntimeWriteJson($path,$state);}catch(Throwable){}}
        return$state;
    }
    $safe=preg_replace('/[^A-Za-z0-9_.-]+/','_',$environment);$legacy=rtrim(sys_get_temp_dir(),DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'yulcaribe_nms'.DIRECTORY_SEPARATOR.'cron_state_'.$safe.'.json';
    $state=ycRuntimeReadJson($legacy,2097152);
    if(is_array($state)){$state['recentIssues']=is_array($state['recentIssues']??null)?$state['recentIssues']:ycLegacyNmsIssues($state);try{ycRuntimeWriteJson($path,$state);}catch(Throwable){}return$state;}
    return null;
}
function ycRuntimeCleanup(int $days=YC_RUNTIME_RETENTION_DAYS): void {
    $days=max(1,min(30,$days));$cutoff=time()-$days*86400;$patterns=['nms'=>'/^nms-\d{4}-\d{2}-\d{2}\.log$/D','health'=>'/^health-\d{4}-\d{2}-\d{2}\.log$/D','raw-nms'=>'/^raw-nms-\d{4}-\d{2}-\d{2}\.jsonl(?:\.gz)?$/D'];
    foreach($patterns as$kind=>$pattern){$dir=ycRuntimeDir($kind);try{foreach(new DirectoryIterator($dir)as$file){if($file->isDot()||$file->isLink()||!$file->isFile()||!preg_match($pattern,$file->getFilename()))continue;if($file->getMTime()<$cutoff)@unlink($file->getPathname());}}catch(Throwable){}}
}
function ycRuntimeLog(string $kind,string $level,string $message,array $context=[]): void {
    if(!in_array($kind,['nms','health'],true))throw new InvalidArgumentException('Invalid log kind.');static$cleaned=false;if(!$cleaned){ycRuntimeCleanup();$cleaned=true;}
    $level=strtoupper(trim($level));if(!in_array($level,['DEBUG','INFO','WARNING','ERROR','RECOVERED'],true))$level='INFO';$message=trim($message);if(strlen($message)>2000)$message=substr($message,0,2000).'…';$suffix='';
    if($context){$encoded=json_encode($context,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);if(is_string($encoded))$suffix=' '.(strlen($encoded)<=YC_RUNTIME_MAX_LOG_CONTEXT_BYTES?$encoded:(json_encode(['contextTruncated'=>true,'bytes'=>strlen($encoded)],JSON_UNESCAPED_SLASHES)?:''));}
    $path=ycRuntimeDir($kind).DIRECTORY_SEPARATOR.$kind.'-'.gmdate('Y-m-d').'.log';@file_put_contents($path,'['.gmdate('Y-m-d\TH:i:s\Z').'] '.$level.' '.$message.$suffix.PHP_EOL,FILE_APPEND|LOCK_EX);@chmod($path,0600);
}
function ycArchiveRawNmsJson(string $json): bool {
    $json=trim($json);if($json==='')return false;static$gz=null,$gzDate=null,$plain=null,$plainDate=null;$date=gmdate('Y-m-d');
    if(function_exists('gzopen')){if($gz===null||$gzDate!==$date){if(is_resource($gz))@gzclose($gz);$path=ycRuntimeDir('raw-nms').DIRECTORY_SEPARATOR.'raw-nms-'.$date.'.jsonl.gz';$gz=@gzopen($path,'ab6');$gzDate=$date;if($gz!==false)@chmod($path,0600);}if($gz!==false&&$gz!==null)return@gzwrite($gz,$json."\n")!==false;}
    if($plain===null||$plainDate!==$date){if(is_resource($plain))@fclose($plain);$path=ycRuntimeDir('raw-nms').DIRECTORY_SEPARATOR.'raw-nms-'.$date.'.jsonl';$plain=@fopen($path,'ab');$plainDate=$date;if(is_resource($plain))@chmod($path,0600);}if(!is_resource($plain))return false;$written=@fwrite($plain,$json."\n");@fflush($plain);return$written!==false;
}
function ycArchiveRawNmsValue(mixed $value): bool {$json=json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);return is_string($json)&&ycArchiveRawNmsJson($json);}
function ycRuntimeLogFiles(): array {
    $result=[];$patterns=['nms'=>'/^nms-(\d{4}-\d{2}-\d{2})\.log$/D','health'=>'/^health-(\d{4}-\d{2}-\d{2})\.log$/D','raw-nms'=>'/^raw-nms-(\d{4}-\d{2}-\d{2})\.jsonl(?:\.gz)?$/D'];
    foreach($patterns as$kind=>$pattern){$dir=ycRuntimeDir($kind);try{foreach(new DirectoryIterator($dir)as$file){if($file->isDot()||$file->isLink()||!$file->isFile()||!preg_match($pattern,$file->getFilename(),$m))continue;$result[]=['type'=>$kind,'date'=>$m[1],'file'=>$file->getFilename(),'bytes'=>$file->getSize(),'modifiedAt'=>gmdate('c',$file->getMTime())];}}catch(Throwable){}}
    usort($result,static function(array$a,array$b):int{$date=strcmp((string)$b['date'],(string)$a['date']);return$date!==0?$date:strcmp((string)$a['type'],(string)$b['type']);});return$result;
}
function ycRuntimeResolveDownload(string $type,string $file): ?string {
    $patterns=['nms'=>'/^nms-\d{4}-\d{2}-\d{2}\.log$/D','health'=>'/^health-\d{4}-\d{2}-\d{2}\.log$/D','raw-nms'=>'/^raw-nms-\d{4}-\d{2}-\d{2}\.jsonl(?:\.gz)?$/D'];
    if(!isset($patterns[$type])||!preg_match($patterns[$type],$file)||basename($file)!==$file)return null;$dir=ycRuntimeDir($type);$candidate=$dir.DIRECTORY_SEPARATOR.$file;if(!is_file($candidate)||is_link($candidate))return null;$realDir=realpath($dir);$realFile=realpath($candidate);if($realDir===false||$realFile===false||!str_starts_with($realFile,$realDir.DIRECTORY_SEPARATOR))return null;return$realFile;
}
