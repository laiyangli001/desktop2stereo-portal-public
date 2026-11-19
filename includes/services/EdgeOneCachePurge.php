<?php
declare(strict_types=1);

namespace D2S\services;

use PDO;
use RuntimeException;
use Throwable;

final readonly class EdgeOneCachePurge
{
    private const TASK_LOCK = 'd2s_edge_cache_purge_claim';
    private const BATCH_SIZE = 20;
    private const MAX_ATTEMPTS = 8;

    public static function enabled(): bool
    {
        return strtolower(trim((string)(getenv('D2S_EDGEONE_PURGE_ENABLED') ?: ''))) === 'true';
    }

    public static function enqueuePaths(PDO $db, array $paths, string $eventKey, int $now): int
    {
        if (!self::enabled()) return 0;
        if (!preg_match('/^[A-Za-z0-9:._-]{1,120}$/D', $eventKey)) {
            throw new RuntimeException('edge_cache_event_invalid');
        }
        $origin = self::origin();
        $registry = new LocaleRegistry();
        $publicLocales = $registry->enabled(true);
        $targets = [];
        foreach ($paths as $path) {
            if (!is_string($path) || !self::validPath($path)) {
                throw new RuntimeException('edge_cache_path_invalid');
            }
            if ($path === '/sitemap.xml') {
                $targets[$origin.$path] = true;
                continue;
            }
            foreach ($publicLocales as $locale) {
                $prefix = (string)($locale['code'] ?? '') === $registry->defaultLocale()
                    ? ''
                    : '/'.(string)($locale['url_prefix'] ?? '');
                $targets[$origin.$prefix.$path] = true;
            }
        }
        if ($targets === []) return 0;

        $insert = $db->prepare("INSERT IGNORE INTO portal_edge_cache_purges
            (dedup_key,target_url,status,attempts,available_at,created_at,updated_at)
            VALUES (?,?,'pending',0,?,?,?)");
        $queued = 0;
        foreach (array_keys($targets) as $target) {
            $dedup = hash('sha256', $eventKey."\n".$target);
            $insert->execute([$dedup,$target,$now,$now,$now]);
            $queued += $insert->rowCount();
        }
        return $queued;
    }

    public static function enqueueAllPublic(PDO $db, string $eventKey, int $now): int
    {
        $paths = [
            '/', '/help', '/shop', '/about', '/contact', '/business-license',
            '/privacy-policy', '/terms', '/legal-statement', '/sitemap.xml',
        ];
        $articles = $db->query("SELECT slug FROM portal_articles WHERE status='published' ORDER BY slug")
            ->fetchAll(PDO::FETCH_COLUMN);
        foreach ($articles as $slug) {
            if (is_string($slug) && preg_match('/^[A-Za-z0-9._~-]{1,120}$/D', $slug)) {
                $paths[] = '/help/'.$slug;
            }
        }
        return self::enqueuePaths($db,$paths,$eventKey,$now);
    }

    public static function status(PDO $db): array
    {
        $counts = ['pending'=>0,'retry'=>0,'running'=>0,'failed'=>0,'completed'=>0];
        $lastError = null;
        try {
            $rows = $db->query('SELECT status,COUNT(*) AS total FROM portal_edge_cache_purges GROUP BY status')->fetchAll();
            foreach ($rows as $row) {
                $status = (string)($row['status'] ?? '');
                if (array_key_exists($status,$counts)) $counts[$status] = (int)$row['total'];
            }
            $lastError = $db->query("SELECT last_error_code,updated_at FROM portal_edge_cache_purges
                WHERE last_error_code IS NOT NULL ORDER BY updated_at DESC,id DESC LIMIT 1")->fetch() ?: null;
        } catch (Throwable) {
            // Runtime status remains readable before the optional cache migration is applied.
        }
        $zone = trim((string)(getenv('D2S_EDGEONE_ZONE_ID') ?: ''));
        $secretId = trim((string)(getenv('D2S_TENCENT_SECRET_ID') ?: ''));
        $secretKey = (string)(getenv('D2S_TENCENT_SECRET_KEY') ?: '');
        $appUrl = trim((string)(getenv('D2S_APP_URL') ?: ''));
        $origin = parse_url($appUrl);
        $originReady = is_array($origin) && ($origin['scheme'] ?? null) === 'https'
            && is_string($origin['host'] ?? null) && $origin['host'] !== ''
            && !isset($origin['user']) && !isset($origin['pass'])
            && !isset($origin['query']) && !isset($origin['fragment']);
        $credentialReady = preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$zone) === 1
            && preg_match('/^[A-Za-z0-9]{1,128}$/D',$secretId) === 1
            && strlen($secretKey) >= 16 && strlen($secretKey) <= 256;
        return [
            'enabled'=>self::enabled(),
            'configuration_ready'=>$credentialReady && $originReady,
            'zone_configured'=>$zone !== '',
            'secret_id_configured'=>$secretId !== '',
            'secret_key_configured'=>$secretKey !== '',
            'origin_ready'=>$originReady,
            'counts'=>$counts,
            'last_error'=>is_array($lastError) ? (string)$lastError['last_error_code'] : null,
            'last_error_at'=>is_array($lastError) ? (int)$lastError['updated_at'] : null,
        ];
    }

    public function runBatch(PDO $db, int $now, int $limit = self::BATCH_SIZE): array
    {
        if (!self::enabled()) return ['processed'=>0,'status'=>'disabled','items'=>[]];
        $config = self::configuration();
        $limit = max(1,min(self::BATCH_SIZE,$limit));
        $lock = $db->prepare('SELECT GET_LOCK(?,5)');
        $lock->execute([self::TASK_LOCK]);
        if ((int)$lock->fetchColumn() !== 1) return ['processed'=>0,'status'=>'busy','items'=>[]];

        $lease = bin2hex(random_bytes(16));
        $rows = [];
        try {
            $db->beginTransaction();
            $query = $db->prepare("SELECT id,target_url,attempts FROM portal_edge_cache_purges
                WHERE (status IN ('pending','retry') AND available_at<=?)
                   OR (status='running' AND (lease_until IS NULL OR lease_until<=?))
                ORDER BY available_at,id LIMIT {$limit} FOR UPDATE");
            $query->execute([$now,$now]);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
            if ($rows !== []) {
                $ids = array_map(static fn(array $row): int => (int)$row['id'],$rows);
                $marks = implode(',',array_fill(0,count($ids),'?'));
                $db->prepare("UPDATE portal_edge_cache_purges
                    SET status='running',attempts=attempts+1,lease_id=?,lease_until=?,last_error_code=NULL,updated_at=?
                    WHERE id IN ({$marks})")->execute([$lease,$now+90,$now,...$ids]);
            }
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        } finally {
            $release = $db->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([self::TASK_LOCK]);
        }
        if ($rows === []) return ['processed'=>0,'status'=>'idle','items'=>[]];

        try {
            $jobId = $this->purge($config,array_column($rows,'target_url'));
            $ids = array_map(static fn(array $row): int => (int)$row['id'],$rows);
            $marks = implode(',',array_fill(0,count($ids),'?'));
            $db->prepare("UPDATE portal_edge_cache_purges SET status='submitted',lease_id=NULL,lease_until=NULL,
                provider_job_id=?,last_error_code=NULL,updated_at=? WHERE lease_id=? AND id IN ({$marks})")
                ->execute([$jobId,$now,$lease,...$ids]);
            return ['processed'=>count($rows),'status'=>'submitted','provider_job_id'=>$jobId,'items'=>$ids];
        } catch (Throwable $error) {
            $code = $error instanceof RuntimeException ? $error->getMessage() : 'edge_cache_request_failed';
            if (!preg_match('/^[a-zA-Z0-9._-]{1,128}$/D',$code)) $code = 'edge_cache_request_failed';
            foreach ($rows as $row) {
                $attempt = (int)$row['attempts'] + 1;
                $status = $attempt >= self::MAX_ATTEMPTS ? 'failed' : 'retry';
                $delay = min(3600,30 * (2 ** min(7,$attempt-1)));
                $db->prepare('UPDATE portal_edge_cache_purges
                    SET status=?,available_at=?,lease_id=NULL,lease_until=NULL,last_error_code=?,updated_at=?
                    WHERE id=? AND lease_id=?')
                    ->execute([$status,$now+$delay,$code,$now,(int)$row['id'],$lease]);
            }
            return ['processed'=>count($rows),'status'=>'retry','error'=>$code,'items'=>array_column($rows,'id')];
        }
    }

    private function purge(array $config, array $targets): string
    {
        $host = 'teo.tencentcloudapi.com';
        $action = 'CreatePurgeTask';
        $version = '2022-09-01';
        $timestamp = time();
        $date = gmdate('Y-m-d',$timestamp);
        $payload = json_encode([
            'ZoneId'=>$config['zone_id'],
            'Type'=>'purge_url',
            'Targets'=>array_values($targets),
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        $contentType = 'application/json; charset=utf-8';
        $canonicalHeaders = "content-type:{$contentType}\nhost:{$host}\n";
        $signedHeaders = 'content-type;host';
        $canonicalRequest = "POST\n/\n\n".$canonicalHeaders."\n".$signedHeaders."\n".hash('sha256',$payload);
        $scope = $date.'/teo/tc3_request';
        $stringToSign = "TC3-HMAC-SHA256\n{$timestamp}\n{$scope}\n".hash('sha256',$canonicalRequest);
        $secretDate = hash_hmac('sha256',$date,'TC3'.$config['secret_key'],true);
        $secretService = hash_hmac('sha256','teo',$secretDate,true);
        $secretSigning = hash_hmac('sha256','tc3_request',$secretService,true);
        $signature = hash_hmac('sha256',$stringToSign,$secretSigning);
        $authorization = 'TC3-HMAC-SHA256 Credential='.$config['secret_id'].'/'.$scope.
            ', SignedHeaders='.$signedHeaders.', Signature='.$signature;

        $curl = curl_init('https://'.$host.'/');
        curl_setopt_array($curl,[
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>$payload,
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>5,
            CURLOPT_TIMEOUT=>20,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER=>[
                'Authorization: '.$authorization,
                'Content-Type: '.$contentType,
                'Host: '.$host,
                'X-TC-Action: '.$action,
                'X-TC-Version: '.$version,
                'X-TC-Timestamp: '.$timestamp,
            ],
        ]);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
        $curlError = curl_errno($curl);
        curl_close($curl);
        if (!is_string($body) || $curlError !== 0) throw new RuntimeException('edge_cache_transport_failed');
        if ($status < 200 || $status >= 300) throw new RuntimeException('edge_cache_http_'.$status);
        try {
            $decoded = json_decode($body,true,32,JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('edge_cache_response_invalid');
        }
        $response = $decoded['Response'] ?? null;
        if (!is_array($response)) throw new RuntimeException('edge_cache_response_invalid');
        if (isset($response['Error'])) {
            $errorCode = is_array($response['Error']) ? (string)($response['Error']['Code'] ?? '') : '';
            $safeCode = preg_match('/^[A-Za-z0-9._-]{1,120}$/D',$errorCode) ? $errorCode : 'edge_cache_provider_rejected';
            throw new RuntimeException($safeCode);
        }
        if (!empty($response['FailedList'])) throw new RuntimeException('edge_cache_partial_failure');
        $jobId = $response['JobId'] ?? null;
        if (!is_string($jobId) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$jobId)) {
            throw new RuntimeException('edge_cache_response_invalid');
        }
        return $jobId;
    }

    private static function configuration(): array
    {
        $zone = trim((string)(getenv('D2S_EDGEONE_ZONE_ID') ?: ''));
        $secretId = trim((string)(getenv('D2S_TENCENT_SECRET_ID') ?: ''));
        $secretKey = (string)(getenv('D2S_TENCENT_SECRET_KEY') ?: '');
        if ($zone === '' || !preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$zone)
            || $secretId === '' || !preg_match('/^[A-Za-z0-9]{1,128}$/D',$secretId)
            || strlen($secretKey) < 16 || strlen($secretKey) > 256) {
            throw new RuntimeException('edge_cache_configuration_incomplete');
        }
        self::origin();
        return ['zone_id'=>$zone,'secret_id'=>$secretId,'secret_key'=>$secretKey];
    }

    private static function origin(): string
    {
        $configured = trim((string)(getenv('D2S_APP_URL') ?: ''));
        $parts = parse_url($configured);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || !is_string($parts['host'] ?? null) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('edge_cache_app_url_must_be_https_origin');
        }
        $port = isset($parts['port']) ? ':'.(int)$parts['port'] : '';
        return 'https://'.strtolower((string)$parts['host']).$port;
    }

    private static function validPath(string $path): bool
    {
        if ($path === '' || $path[0] !== '/' || str_contains($path,'?') || str_contains($path,'#')
            || str_contains($path,'\\') || preg_match('/[\x00-\x20\x7f]/',$path)) return false;
        foreach (explode('/',$path) as $segment) if ($segment === '.' || $segment === '..') return false;
        return preg_match('#^/[A-Za-z0-9._~/-]*$#D',$path) === 1;
    }
}
