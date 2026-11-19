<?php
declare(strict_types=1);

namespace D2S\services;

use FilesystemIterator;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

final readonly class DataMaintenance
{
    private const KEY_TABLES=[
        'portal_users','portal_contacts','portal_licenses','portal_devices','portal_client_sessions',
        'portal_orders','portal_wallet_entries','portal_support_tickets','portal_support_messages',
        'portal_support_attachments','portal_payment_events','portal_transaction_notifications',
        'portal_user_events','portal_admin_audit','portal_login_history','portal_retention_runs',
        'portal_translation_jobs','portal_translation_usage','portal_notification_outbox','portal_edge_cache_purges',
    ];
    private const MAX_FILE_SCAN=20000;
    private const MAX_HASH_FILES=500;
    private const MAX_HASH_BYTES=134217728;

    public function __construct(
        private PDO $db,
        private string $root=D2S_ROOT,
        private ?string $ticketStorage=null,
        private ?string $manifestDirectory=null,
    ) {}

    public function inspect(int $now): array
    {
        $tables=$this->tables();
        $counts=[];
        foreach(self::KEY_TABLES as$table){
            try{$counts[$table]=(int)$this->db->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();}
            catch(Throwable){$counts[$table]=null;}
        }
        $attachmentRows=$this->attachmentRows();
        $attachments=$this->attachments($attachmentRows);
        $manifestRoot=$this->manifestDirectory??(getenv('D2S_RETENTION_MANIFEST_DIR')?:$this->root.'/storage/retention-manifests');
        $manifests=$this->manifests($manifestRoot);
        return [
            'generated_at'=>$now,
            'database'=>[
                'tables'=>$tables,
                'table_count'=>count($tables),
                'data_bytes'=>array_sum(array_column($tables,'data_bytes')),
                'index_bytes'=>array_sum(array_column($tables,'index_bytes')),
                'allocated_bytes'=>array_sum(array_column($tables,'allocated_bytes')),
                'key_row_counts'=>$counts,
            ],
            'attachments'=>$attachments,
            'manifests'=>$manifests,
            'retention_runs'=>$this->retentionRuns(),
            'read_only'=>true,
        ];
    }

    private function tables(): array
    {
        $schema=(string)$this->db->query('SELECT DATABASE()')->fetchColumn();
        if(!preg_match('/^[A-Za-z0-9_]{1,64}$/D',$schema))return [];
        $query=$this->db->prepare("SELECT TABLE_NAME table_name,ENGINE engine,TABLE_ROWS table_rows,
            DATA_LENGTH data_bytes,INDEX_LENGTH index_bytes,DATA_FREE allocated_bytes
            FROM information_schema.TABLES WHERE TABLE_SCHEMA=? ORDER BY (DATA_LENGTH+INDEX_LENGTH) DESC,TABLE_NAME");
        $query->execute([$schema]);
        return array_map(static fn(array $row):array=>[
            'name'=>(string)$row['table_name'],'engine'=>(string)($row['engine']??''),
            'estimated_rows'=>(int)$row['table_rows'],'data_bytes'=>(int)$row['data_bytes'],
            'index_bytes'=>(int)$row['index_bytes'],'allocated_bytes'=>(int)$row['allocated_bytes'],
        ],$query->fetchAll());
    }

    private function attachmentRows(): array
    {
        try{
            return $this->db->query('SELECT storage_name,byte_size,sha256,created_at FROM portal_support_attachments ORDER BY created_at,id')
                ->fetchAll();
        }catch(Throwable){return [];}
    }

    private function attachments(array $rows): array
    {
        $root=$this->normalizePath($this->ticketStorage??(getenv('D2S_TICKET_STORAGE')?:$this->root.'/storage/tickets'));
        $result=[
            'configured'=>is_dir($root),'writable'=>is_writable($root),'database_records'=>count($rows),
            'database_bytes'=>array_sum(array_map(static fn(array $row):int=>(int)$row['byte_size'],$rows)),
            'files_scanned'=>0,'files_bytes'=>0,'missing_files'=>0,'size_mismatches'=>0,
            'hash_checked'=>0,'hash_bytes_checked'=>0,'hash_mismatches'=>0,'orphan_files'=>0,
            'invalid_files'=>0,'symlinks_skipped'=>0,'scan_truncated'=>false,
            'disk_free_bytes'=>is_dir($root)?disk_free_space($root):false,
        ];
        if($result['disk_free_bytes']===false)$result['disk_free_bytes']=null;
        else$result['disk_free_bytes']=(int)$result['disk_free_bytes'];
        $expected=[];
        foreach($rows as$row){
            $name=(string)($row['storage_name']??'');
            if(!preg_match('/^[a-f0-9]{64}$/D',$name)){continue;}
            $expected[$name]=true;
            $path=rtrim($root,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.substr($name,0,2).DIRECTORY_SEPARATOR.$name;
            if(!is_file($path)){$result['missing_files']++;continue;}
            $size=(int)filesize($path);
            if($size!==(int)$row['byte_size'])$result['size_mismatches']++;
            if($size===(int)$row['byte_size']&&$result['hash_checked']<self::MAX_HASH_FILES
                &&$result['hash_bytes_checked']+$size<=self::MAX_HASH_BYTES){
                $hash=hash_file('sha256',$path);
                $result['hash_checked']++;
                $result['hash_bytes_checked']+=$size;
                if(!is_string($hash)||!hash_equals((string)$row['sha256'],$hash))$result['hash_mismatches']++;
            }
        }
        if(!is_dir($root))return $result;
        try{
            $iterator=new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
            );
            foreach($iterator as$file){
                if($result['files_scanned']>=self::MAX_FILE_SCAN){$result['scan_truncated']=true;break;}
                if(!$file instanceof SplFileInfo)continue;
                if($file->isLink()){$result['symlinks_skipped']++;continue;}
                if(!$file->isFile()){continue;}
                $result['files_scanned']++;
                $result['files_bytes']+=$file->getSize();
                $name=$file->getFilename();
                if(!preg_match('/^[a-f0-9]{64}$/D',$name)){$result['invalid_files']++;continue;}
                if(!isset($expected[$name]))$result['orphan_files']++;
            }
        }catch(Throwable){
            $result['scan_truncated']=true;
        }
        return $result;
    }

    private function manifests(string $path): array
    {
        $root=$this->normalizePath($path);
        $result=['configured'=>is_dir($root),'files'=>0,'bytes'=>0,'latest_at'=>null,'invalid_files'=>0,'symlinks_skipped'=>0,'scan_truncated'=>false];
        if(!is_dir($root))return $result;
        try{
            $iterator=new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
            );
            foreach($iterator as$file){
                if($result['files']>=self::MAX_FILE_SCAN){$result['scan_truncated']=true;break;}
                if(!$file instanceof SplFileInfo)continue;
                if($file->isLink()){$result['symlinks_skipped']++;continue;}
                if(!$file->isFile())continue;
                if(!preg_match('/\.(json|enc|bin)$/iD',$file->getFilename())){$result['invalid_files']++;continue;}
                if(!$file->isReadable()){$result['invalid_files']++;continue;}
                $result['files']++;
                $result['bytes']+=$file->getSize();
                $result['latest_at']=max($result['latest_at']??0,$file->getMTime());
            }
        }catch(Throwable){$result['scan_truncated']=true;}
        return $result;
    }

    private function retentionRuns(): array
    {
        try{$rows=$this->db->query('SELECT id,operator_id,started_at,range_start,range_end,counts_json
            FROM portal_retention_runs ORDER BY id DESC LIMIT 20')->fetchAll();}
        catch(Throwable){return [];}
        return array_map(static function(array $row):array{
            $details=[];try{$decoded=json_decode((string)$row['counts_json'],true,32,JSON_THROW_ON_ERROR);if(is_array($decoded))$details=$decoded;}catch(Throwable){}
            return ['id'=>(int)$row['id'],'operator_id'=>$row['operator_id']===null?null:(int)$row['operator_id'],
                'started_at'=>(int)$row['started_at'],'range_start'=>(int)$row['range_start'],'range_end'=>(int)$row['range_end'],
                'task'=>(string)($details['task']??'support_tickets'),'status'=>(string)($details['status']??'recorded'),
                'deleted'=>(int)($details['deleted']??$details['record_count']??0),'data'=>$details];
        },$rows);
    }

    private function normalizePath(string $path): string
    {
        $path=trim($path);
        if($path===''||str_contains($path,"\0"))return $this->root.'/storage';
        if(!str_starts_with($path,DIRECTORY_SEPARATOR)&&!preg_match('/^[A-Za-z]:[\\\\\/]/D',$path))$path=$this->root.'/'.$path;
        return rtrim(str_replace(['/','\\'],DIRECTORY_SEPARATOR,$path),DIRECTORY_SEPARATOR);
    }
}
