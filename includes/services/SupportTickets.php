<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;
use Throwable;

final readonly class SupportTickets
{
    private const CATEGORIES = ['BUG','PURCHASE','LICENSE','DOWNLOAD','ACCOUNT','SOFTWARE','SUGGESTION','OTHER'];
    private const STATUSES = ['OPEN','IN_PROGRESS','RESOLVED','CLOSED'];
    private const MAX_ATTACHMENTS = 5;
    private const MAX_ATTACHMENT_BYTES = 5 * 1024 * 1024;
    private const MAX_TICKET_ATTACHMENT_BYTES = 20 * 1024 * 1024;
    private const MIME_TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/zip' => 'zip',
        'text/plain' => 'txt',
    ];

    public function __construct(private PDO $db, private ?string $storageRoot = null, private ?SecretBox $secrets = null,
        private ?string $manifestRoot = null)
    {
    }

    public function create(int $userId, array $input, array $context, int $now): array
    {
        $category = strtoupper(trim((string)($input['category'] ?? '')));
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new Problem('ticket_category_invalid');
        }
        $subject = trim((string)($input['subject'] ?? ''));
        [$body,$bodyFormat] = $this->messageBody($input['body'] ?? '',(string)($input['body_format'] ?? 'PLAIN'),10);
        $this->body($subject, 1, 200, 'ticket_subject_invalid');
        $orderNumber = trim((string)($input['order_no'] ?? ''));
        if ($orderNumber !== '' && !preg_match('/^[0-9]{16}$/D', $orderNumber)) {
            throw new Problem('ticket_order_number_invalid');
        }

        return Database::transaction($this->db, function (PDO $db) use ($userId,$category,$subject,$body,$bodyFormat,$orderNumber,$context,$now): array {
            [$email,$contact] = $this->account($db,$userId);
            $orderId = null;
            if ($orderNumber !== '') {
                $order = $db->prepare('SELECT id FROM portal_orders WHERE user_id=? AND order_no=?');
                $order->execute([$userId,$orderNumber]);
                $orderId = $order->fetchColumn();
                if ($orderId === false) {
                    throw new Problem('ticket_order_not_found',404);
                }
            }
            $ticketId = bin2hex(random_bytes(16));
            $messageId = bin2hex(random_bytes(16));
            $db->prepare('INSERT INTO portal_support_tickets
                (id,user_id,order_id,category,subject,status,contact_value,source_ip,user_agent,created_at,updated_at,last_message_at)
                VALUES (?,?,?,?,?,\'OPEN\',?,?,?,?,?,?)')
                ->execute([$ticketId,$userId,$orderId === null ? null : (string)$orderId,$category,$subject,$contact,
                    $context['source_ip'] ?? null,$context['user_agent'] ?? null,$now,$now,$now]);
            $db->prepare('INSERT INTO portal_support_messages
                (id,ticket_id,author_kind,author_id,visibility,body,body_format,created_at,record_hash)
                VALUES (?,?,\'USER\',?,\'PUBLIC\',?,?,?,?)')
                ->execute([$messageId,$ticketId,$userId,$body,$bodyFormat,$now,
                    $this->messageHash($ticketId,$userId,'USER','PUBLIC',$body,$bodyFormat,$now)]);
            $this->event($db,$ticketId,'USER',$userId,'CREATED',null,'OPEN',['order_no'=>$orderNumber === '' ? null : $orderNumber],$now);
            return ['id'=>$ticketId,'message_id'=>$messageId,'status'=>'OPEN','created_at'=>$now,'email'=>$email,'body_format'=>$bodyFormat];
        });
    }

    public function userTickets(int $userId, int $page = 1, string $view = '', string $query = ''): array
    {
        $page = max(1,$page);
        $offset = ($page-1)*20;
        $where = 't.user_id=?';
        $parameters = [$userId];
        if ($view === 'pending') $where .= " AND t.status IN ('OPEN','IN_PROGRESS')";
        $query = mb_substr(trim($query),0,200,'UTF-8');
        if ($query !== '') {
            $where .= ' AND (LOCATE(?,t.subject)>0 OR LOCATE(?,t.id)>0)';
            array_push($parameters,$query,$query);
        }
        $select = $this->db->prepare("SELECT t.id,t.category,t.subject,t.status,t.created_at,t.updated_at,t.closed_at,
            o.order_no,(SELECT COUNT(*) FROM portal_support_messages m WHERE m.ticket_id=t.id AND m.visibility='PUBLIC') AS message_count,
            (SELECT MAX(m.created_at) FROM portal_support_messages m WHERE m.ticket_id=t.id AND m.visibility='PUBLIC' AND m.author_kind='ADMIN') AS last_staff_reply_at,
            (SELECT MAX(m.created_at) FROM portal_support_messages m WHERE m.ticket_id=t.id AND m.visibility='PUBLIC' AND m.author_kind='USER') AS last_user_message_at,
            (SELECT m.body FROM portal_support_messages m WHERE m.ticket_id=t.id AND m.visibility='PUBLIC' AND m.author_kind='ADMIN' ORDER BY m.created_at DESC,m.id DESC LIMIT 1) AS last_staff_reply,
            (SELECT e.actor_kind FROM portal_support_ticket_events e WHERE e.ticket_id=t.id AND e.event_type IN ('CREATED','REPLIED')
                AND (e.actor_kind='USER' OR JSON_UNQUOTE(JSON_EXTRACT(e.details_json,'$.visibility'))='PUBLIC')
                ORDER BY e.id DESC LIMIT 1) AS last_public_actor,
            (SELECT COUNT(*) FROM portal_support_attachments a WHERE a.ticket_id=t.id) AS attachment_count
            FROM portal_support_tickets t LEFT JOIN portal_orders o ON o.id=t.order_id
            WHERE $where ORDER BY t.updated_at DESC,t.id DESC LIMIT 20 OFFSET $offset");
        $select->execute($parameters);
        $count = $this->db->prepare('SELECT COUNT(*) FROM portal_support_tickets t WHERE '.$where);
        $count->execute($parameters);
        return ['items'=>$select->fetchAll(),'page'=>$page,'per_page'=>20,'total'=>(int)$count->fetchColumn()];
    }

    public function userView(int $userId, string $ticketId, array $context, int $now): array
    {
        self::id($ticketId);
        $ticket = $this->ticket($userId,$ticketId);
        $messages = $this->messages($ticketId,false);
        $lastStaffReplyAt = null;
        $lastUserMessageAt = null;
        foreach ($messages as $message) {
            if ($message['author_kind'] === 'ADMIN') $lastStaffReplyAt = (int)$message['created_at'];
            else $lastUserMessageAt = (int)$message['created_at'];
        }
        $lastActor = $this->db->prepare("SELECT e.actor_kind FROM portal_support_ticket_events e
            WHERE e.ticket_id=? AND e.event_type IN ('CREATED','REPLIED')
            AND (e.actor_kind='USER' OR JSON_UNQUOTE(JSON_EXTRACT(e.details_json,'$.visibility'))='PUBLIC')
            ORDER BY e.id DESC LIMIT 1");
        $lastActor->execute([$ticketId]);
        $ticket['last_staff_reply_at'] = $lastStaffReplyAt;
        $ticket['last_user_message_at'] = $lastUserMessageAt;
        $ticket['last_public_actor'] = $lastActor->fetchColumn() ?: null;
        return ['ticket'=>$ticket,'messages'=>$messages];
    }

    public function userReply(int $userId, string $ticketId, string $body, array $context, int $now, string $format = 'PLAIN'): array
    {
        self::id($ticketId);
        [$body,$bodyFormat] = $this->messageBody($body,$format,1);
        return Database::transaction($this->db,function (PDO $db) use ($userId,$ticketId,$body,$bodyFormat,$context,$now): array {
            $this->account($db,$userId);
            $ticket = $this->lockedTicket($db,$userId,$ticketId);
            if ($ticket['status'] === 'CLOSED') {
                throw new Problem('ticket_closed',409);
            }
            $messageId = bin2hex(random_bytes(16));
            $db->prepare('INSERT INTO portal_support_messages
                (id,ticket_id,author_kind,author_id,visibility,body,body_format,created_at,record_hash)
                VALUES (?,?,\'USER\',?,\'PUBLIC\',?,?,?,?)')
                ->execute([$messageId,$ticketId,$userId,$body,$bodyFormat,$now,
                    $this->messageHash($ticketId,$userId,'USER','PUBLIC',$body,$bodyFormat,$now)]);
            $status = $ticket['status'] === 'RESOLVED' ? 'OPEN' : $ticket['status'];
            $db->prepare('UPDATE portal_support_tickets SET status=?,updated_at=?,last_message_at=?,closed_at=NULL WHERE id=?')
                ->execute([$status,$now,$now,$ticketId]);
            $this->event($db,$ticketId,'USER',$userId,'REPLIED',$ticket['status'],$status,[],$now);
            return ['message_id'=>$messageId,'status'=>$status,'created_at'=>$now];
        });
    }

    public function closeByUser(int $userId, string $ticketId, array $context, int $now): array
    {
        self::id($ticketId);
        return Database::transaction($this->db,function (PDO $db) use ($userId,$ticketId,$context,$now): array {
            $this->account($db,$userId);
            $ticket = $this->lockedTicket($db,$userId,$ticketId);
            if ($ticket['status'] === 'CLOSED') {
                return ['status'=>'CLOSED','closed_at'=>$ticket['closed_at']];
            }
            $db->prepare("UPDATE portal_support_tickets SET status='CLOSED',closed_at=?,updated_at=? WHERE id=?")
                ->execute([$now,$now,$ticketId]);
            $this->event($db,$ticketId,'USER',$userId,'CLOSED',$ticket['status'],'CLOSED',[],$now);
            return ['status'=>'CLOSED','closed_at'=>$now];
        });
    }

    public function uploadAttachment(int $userId, string $ticketId, string $messageId, array $file, int $now): array
    {
        self::id($ticketId);
        self::id($messageId);
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) ||
            !is_file($file['tmp_name'])) {
            throw new Problem('attachment_upload_invalid');
        }
        $size = filesize($file['tmp_name']);
        if (!is_int($size) || $size < 1 || $size > self::MAX_ATTACHMENT_BYTES) {
            throw new Problem('attachment_size_invalid',413);
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (!is_string($mime) || !isset(self::MIME_TYPES[$mime])) {
            throw new Problem('attachment_type_invalid');
        }
        $original = $this->filename((string)($file['name'] ?? 'attachment'));
        return Database::transaction($this->db,function (PDO $db) use ($userId,$ticketId,$messageId,$file,$size,$mime,$original,$now): array {
            $this->account($db,$userId);
            $ticket = $db->prepare('SELECT status FROM portal_support_tickets WHERE id=? AND user_id=? FOR UPDATE');
            $ticket->execute([$ticketId,$userId]);
            $status = $ticket->fetchColumn();
            if ($status === false) {
                throw new Problem('ticket_not_found',404);
            }
            if ($status === 'CLOSED') {
                throw new Problem('ticket_closed',409);
            }
            $message = $db->prepare('SELECT 1 FROM portal_support_messages WHERE id=? AND ticket_id=? AND author_kind=\'USER\' AND author_id=?');
            $message->execute([$messageId,$ticketId,$userId]);
            if ($message->fetchColumn() === false) {
                throw new Problem('ticket_message_not_found',404);
            }
            $stats = $db->prepare('SELECT id,byte_size FROM portal_support_attachments WHERE ticket_id=?');
            $stats->execute([$ticketId]);
            $attachments = $stats->fetchAll();
            $count = count($attachments);
            $total = array_sum(array_map(static fn(array $row): int => (int)$row['byte_size'],$attachments));
            if ($count >= self::MAX_ATTACHMENTS || $total + $size > self::MAX_TICKET_ATTACHMENT_BYTES) {
                throw new Problem('ticket_attachment_limit',409);
            }
            $id = bin2hex(random_bytes(16));
            $storage = hash('sha256',$id . ':' . bin2hex(random_bytes(24)));
            $path = $this->attachmentPath($storage,$now);
            if (!is_dir(dirname($path)) && !mkdir(dirname($path),UpdateSharedPermissions::directoryMode(),true) && !is_dir(dirname($path))) {
                throw new Problem('attachment_storage_unavailable',503);
            }
            if (!move_uploaded_file($file['tmp_name'],$path)) {
                if (!copy($file['tmp_name'],$path)) {
                    throw new Problem('attachment_storage_unavailable',503);
                }
                @unlink($file['tmp_name']);
            }
            chmod($path,UpdateSharedPermissions::fileMode());
            $hash = hash_file('sha256',$path);
            try {
                $db->prepare('INSERT INTO portal_support_attachments
                    (id,ticket_id,message_id,user_id,storage_name,original_name,mime_type,byte_size,sha256,created_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$id,$ticketId,$messageId,$userId,$storage,$original,$mime,$size,$hash,$now]);
                $db->prepare('UPDATE portal_support_tickets SET updated_at=?,last_message_at=? WHERE id=?')
                    ->execute([$now,$now,$ticketId]);
            } catch (Throwable $error) {
                @unlink($path);
                throw $error;
            }
            return ['id'=>$id,'name'=>$original,'mime_type'=>$mime,'byte_size'=>$size,'sha256'=>$hash];
        });
    }

    public function attachment(int $viewerId, string $realm, string $attachmentId, array $context, int $now): array
    {
        self::id($attachmentId);
        $query = $this->db->prepare('SELECT a.*,t.user_id,t.subject FROM portal_support_attachments a
            JOIN portal_support_tickets t ON t.id=a.ticket_id WHERE a.id=?');
        $query->execute([$attachmentId]);
        $row = $query->fetch();
        if ($row === false) {
            throw new Problem('attachment_not_found',404);
        }
        if ($realm === 'user') {
            if ((int)$row['user_id'] !== $viewerId) {
                throw new Problem('attachment_not_found',404);
            }
        } elseif ($realm === 'admin') {
            Permissions::require($this->db,$viewerId,'tickets.read');
            Database::transaction($this->db,function (PDO $db) use ($viewerId,$row,$context,$now): void {
                $email = $this->adminEmail($db,$viewerId);
                Audit::admin($db,$viewerId,$email,'DOWNLOAD_SUPPORT_ATTACHMENT','support_attachment',$context,'SUCCESS',
                    ['byte_size'=>(int)$row['byte_size'],'mime_type'=>$row['mime_type']],$now);
            });
        } else {
            throw new Problem('authentication_required',401);
        }
        $path = $this->attachmentPath($row['storage_name'],(int)$row['created_at']);
        if (!is_file($path) || hash_file('sha256',$path) !== $row['sha256']) {
            throw new Problem('attachment_unavailable',503);
        }
        return ['path'=>$path,'name'=>$row['original_name'],'mime_type'=>$row['mime_type'],'byte_size'=>(int)$row['byte_size']];
    }

    public function adminTickets(int $adminId, string $status, int $page, array $context, int $now): array
    {
        Permissions::require($this->db,$adminId,'tickets.read');
        $status = strtoupper(trim($status));
        if ($status !== '' && !in_array($status,self::STATUSES,true)) {
            throw new Problem('ticket_status_invalid');
        }
        $page = max(1,$page);
        $offset = ($page-1)*50;
        $where = $status === '' ? '' : ' WHERE t.status=' . $this->db->quote($status);
        $items = $this->db->query("SELECT t.id,t.user_id,u.username,t.category,t.subject,t.status,t.contact_value,
            t.created_at,t.updated_at,t.closed_at,o.order_no,
            (SELECT COUNT(*) FROM portal_support_messages m WHERE m.ticket_id=t.id) AS message_count,
            (SELECT COUNT(*) FROM portal_support_attachments a WHERE a.ticket_id=t.id) AS attachment_count
            FROM portal_support_tickets t JOIN portal_users u ON u.id=t.user_id
            LEFT JOIN portal_orders o ON o.id=t.order_id $where ORDER BY t.updated_at DESC,t.id DESC LIMIT 50 OFFSET $offset")
            ->fetchAll();
        $count = $this->db->query('SELECT COUNT(*) FROM portal_support_tickets t'.$where);
        $this->adminAudit($adminId,'VIEW_SUPPORT_TICKETS','support_tickets',$context,
            ['status'=>$status === '' ? null : $status,'page'=>$page,'result_count'=>count($items)],$now);
        return ['items'=>$items,'page'=>$page,'per_page'=>50,'total'=>(int)$count->fetchColumn()];
    }

    public function adminView(int $adminId, string $ticketId, array $context, int $now): array
    {
        self::id($ticketId);
        Permissions::require($this->db,$adminId,'tickets.read');
        $query = $this->db->prepare('SELECT t.*,u.username,u.status AS user_status,o.order_no
            FROM portal_support_tickets t JOIN portal_users u ON u.id=t.user_id
            LEFT JOIN portal_orders o ON o.id=t.order_id WHERE t.id=?');
        $query->execute([$ticketId]);
        $ticket = $query->fetch();
        if ($ticket === false) {
            throw new Problem('ticket_not_found',404);
        }
        $messages = $this->messages($ticketId,true);
        // Ticket identifiers are intentionally omitted so post-retention audit cannot relink deleted content.
        $this->adminAudit($adminId,'VIEW_SUPPORT_TICKET','support_ticket',$context,['status'=>$ticket['status']],$now);
        return ['ticket'=>$ticket,'messages'=>$messages];
    }

    public function adminReply(int $adminId, string $ticketId, string $body, ?string $status, string $visibility, array $context, int $now, string $format = 'PLAIN'): array
    {
        self::id($ticketId);
        [$body,$bodyFormat] = $this->messageBody($body,$format,1);
        $visibility = strtoupper($visibility);
        if (!in_array($visibility,['PUBLIC','STAFF'],true)) {
            throw new Problem('ticket_visibility_invalid');
        }
        if ($status !== null) {
            $status = strtoupper($status);
            if (!in_array($status,self::STATUSES,true)) {
                throw new Problem('ticket_status_invalid');
            }
        }
        return Database::transaction($this->db,function (PDO $db) use ($adminId,$ticketId,$body,$bodyFormat,$status,$visibility,$context,$now): array {
            Permissions::require($db,$adminId,'tickets.reply');
            $query = $db->prepare('SELECT * FROM portal_support_tickets WHERE id=? FOR UPDATE');
            $query->execute([$ticketId]);
            $ticket = $query->fetch();
            if ($ticket === false) {
                throw new Problem('ticket_not_found',404);
            }
            $messageId = bin2hex(random_bytes(16));
            $db->prepare('INSERT INTO portal_support_messages
                (id,ticket_id,author_kind,author_id,visibility,body,body_format,created_at,record_hash)
                VALUES (?,?,\'ADMIN\',?,?,?,?,?,?)')
                ->execute([$messageId,$ticketId,$adminId,$visibility,$body,$bodyFormat,$now,
                    $this->messageHash($ticketId,$adminId,'ADMIN',$visibility,$body,$bodyFormat,$now)]);
            $next = $status ?? ($visibility === 'PUBLIC' && $ticket['status'] !== 'CLOSED' ? 'IN_PROGRESS' : $ticket['status']);
            $closedAt = $next === 'CLOSED' ? ($ticket['status'] === 'CLOSED' ? $ticket['closed_at'] : $now) : null;
            $db->prepare('UPDATE portal_support_tickets SET status=?,assigned_admin_id=?,updated_at=?,last_message_at=?,closed_at=? WHERE id=?')
                ->execute([$next,$adminId,$now,$now,$closedAt,$ticketId]);
            $this->event($db,$ticketId,'ADMIN',$adminId,'REPLIED',$ticket['status'],$next,['visibility'=>$visibility],$now);
            if ($next !== $ticket['status']) {
                $this->event($db,$ticketId,'ADMIN',$adminId,'STATUS_CHANGED',$ticket['status'],$next,[],$now);
            }
            $this->adminAudit($adminId,'REPLY_SUPPORT_TICKET','support_ticket',$context,
                ['visibility'=>$visibility,'from_status'=>$ticket['status'],'to_status'=>$next],$now);
            return ['message_id'=>$messageId,'status'=>$next,'closed_at'=>$closedAt,'created_at'=>$now];
        });
    }

    public function requestIdentityReview(int $adminId, int $userId, string $reason, array $context, int $now): array
    {
        $reason = trim($reason);
        $this->body($reason,1,500,'identity_review_reason_invalid');
        return Database::transaction($this->db,function (PDO $db) use ($adminId,$userId,$reason,$context,$now): array {
            Permissions::require($db,$adminId,'accounts.restrict');
            $user = $db->prepare('SELECT id FROM portal_users WHERE id=? FOR UPDATE');
            $user->execute([$userId]);
            if ($user->fetchColumn() === false) {
                throw new Problem('user_not_found',404);
            }
            $db->prepare("INSERT INTO portal_identity_verifications
                (user_id,status,requested_by,requested_at,updated_at,decision_reason)
                VALUES (?,'PENDING',?,?,?,?) ON DUPLICATE KEY UPDATE
                status='PENDING',requested_by=VALUES(requested_by),requested_at=VALUES(requested_at),
                verified_by=NULL,verified_at=NULL,updated_at=VALUES(updated_at),decision_reason=VALUES(decision_reason)")
                ->execute([$userId,$adminId,$now,$now,$reason]);
            $this->adminAudit($adminId,'REQUEST_IDENTITY_VERIFICATION',(string)$userId,$context,['reason'=>$reason],$now);
            return ['user_id'=>$userId,'status'=>'PENDING'];
        });
    }

    public function decideIdentityReview(int $adminId, int $userId, string $decision, string $documentType, string $documentNumber, string $country, string $reason, array $context, int $now): array
    {
        $decision = strtoupper($decision);
        if (!in_array($decision,['VERIFIED','REJECTED'],true)) {
            throw new Problem('identity_decision_invalid');
        }
        $documentType = strtoupper(trim($documentType));
        if (!in_array($documentType,['PASSPORT','FOREIGN_PERMANENT_RESIDENT_ID','OTHER'],true)) {
            throw new Problem('identity_document_type_invalid');
        }
        $documentNumber = trim($documentNumber);
        $this->body($documentNumber,3,128,'identity_document_number_invalid');
        $country = strtoupper(trim($country));
        if (!preg_match('/^[A-Z]{2}$/D',$country)) {
            throw new Problem('identity_country_invalid');
        }
        $reason = trim($reason);
        $this->body($reason,1,500,'identity_review_reason_invalid');
        if ($this->secrets === null) {
            throw new Problem('identity_hash_key_required',503);
        }
        // Store a keyed digest, which prevents offline dictionary recovery of document numbers.
        $hash = $this->secrets->digest('identity:'.$country.':'.strtoupper($documentNumber));
        return Database::transaction($this->db,function (PDO $db) use ($adminId,$userId,$decision,$documentType,$hash,$country,$reason,$context,$now): array {
            Permissions::require($db,$adminId,'accounts.restrict');
            $query = $db->prepare('SELECT status,requested_by FROM portal_identity_verifications WHERE user_id=? FOR UPDATE');
            $query->execute([$userId]);
            $current = $query->fetch();
            if ($current === false || $current['status'] !== 'PENDING') {
                throw new Problem('identity_review_not_pending',409);
            }
            if ((int)($current['requested_by'] ?? 0) === $adminId) {
                throw new Problem('identity_reviewer_separation_required',403);
            }
            $db->prepare('UPDATE portal_identity_verifications SET document_type=?,document_number_hash=?,document_country=?,
                status=?,verified_by=?,verified_at=?,updated_at=?,decision_reason=? WHERE user_id=?')
                ->execute([$documentType,$hash,$country,$decision,$adminId,$now,$now,$reason,$userId]);
            $this->adminAudit($adminId,$decision === 'VERIFIED' ? 'VERIFY_IDENTITY' : 'REJECT_IDENTITY',(string)$userId,$context,
                ['document_type'=>$documentType,'document_country'=>$country,'document_hash'=>$hash,'reason'=>$reason],$now);
            return ['user_id'=>$userId,'status'=>$decision,'verified_at'=>$now];
        });
    }

    public static function retentionCutoff(int $now): int
    {
        $date = new \DateTimeImmutable('@'.$now,new \DateTimeZone('UTC'));
        $year = (int)$date->format('Y');
        $month = (int)$date->format('n') - 6;
        if ($month < 1) {
            $month += 12;
            $year--;
        }
        $lastDay = (int)(new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00',$year,$month),new \DateTimeZone('UTC')))
            ->modify('last day of this month')->format('j');
        $day = min((int)$date->format('j'),$lastDay);
        return (new \DateTimeImmutable(sprintf('%04d-%02d-%02d %s',$year,$month,$day,$date->format('H:i:s')),new \DateTimeZone('UTC')))
            ->getTimestamp();
    }

    public function pruneClosed(PDO $retentionDb, int $now, int $batchSize = 100): array
    {
        $batchSize = max(1,min(500,$batchSize));
        $lock = $retentionDb->query("SELECT GET_LOCK('d2s_support_purge',0)")->fetchColumn();
        if ((int)$lock !== 1) {
            return ['busy'=>true,'deleted'=>0,'replayed'=>0];
        }
        try {
            $cutoff = self::retentionCutoff($now);
            $replayed = $this->replayRetentionManifests($retentionDb,$now);
            $candidates = $retentionDb->prepare("SELECT id,closed_at FROM portal_support_tickets
                WHERE status='CLOSED' AND closed_at IS NOT NULL AND closed_at<=?
                ORDER BY closed_at,id LIMIT $batchSize");
            $candidates->execute([$cutoff]);
            $rows = $candidates->fetchAll();
            if ($rows === []) {
                return ['busy'=>false,'deleted'=>0,'replayed'=>$replayed,'cutoff'=>$cutoff];
            }
            $selected = array_map(static fn(array $row): array => [
                'id'=>(string)$row['id'],'closed_at'=>(int)$row['closed_at'],
            ],$rows);
            $deleted = $this->purgeRetentionBatch($retentionDb,$selected,$now,true,null);
            return ['busy'=>false,'deleted'=>$deleted,'replayed'=>$replayed,'cutoff'=>$cutoff];
        } finally {
            $release = $retentionDb->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute(['d2s_support_purge']);
        }
    }

    private function replayRetentionManifests(PDO $db, int $now): int
    {
        try {
            $manifests = $this->retentionManifestStore()->all();
        } catch (Throwable $error) {
            $this->recordRetentionFailure($db,[
                'range_start'=>0,'range_end'=>0,'candidate_record_count'=>0,'record_count'=>0,
                'attachment_count'=>0,'replay'=>true,'stage'=>'manifest_read',
            ],$now,$error);
            throw $error;
        }
        $deleted = 0;
        foreach ($manifests as $manifest) {
            $deleted += $this->purgeRetentionBatch($db,$manifest['tickets'],$now,false,$manifest);
        }
        return $deleted;
    }

    private function purgeRetentionBatch(PDO $db, array $tickets, int $now, bool $createManifest, ?array $manifest): int
    {
        $expected = [];
        foreach ($tickets as $ticket) {
            if (!is_string($ticket['id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D',$ticket['id']) ||
                !is_int($ticket['closed_at'] ?? null)) {
                throw new \RuntimeException('support_purge_manifest_invalid');
            }
            $expected[$ticket['id']] = (int)$ticket['closed_at'];
        }
        if ($expected === []) {
            return 0;
        }
        ksort($expected,SORT_STRING);
        $ids = array_keys($expected);
        $placeholders = implode(',',array_fill(0,count($ids),'?'));
        $rangeStart = min($expected);
        $rangeEnd = max($expected);
        $candidateCount = count($expected);
        $details = [
            'range_start'=>$rangeStart,'range_end'=>$rangeEnd,'candidate_record_count'=>$candidateCount,
            'record_count'=>0,'attachment_count'=>0,'replay'=>!$createManifest,
        ];
        $stage = 'plan';
        try {
            $plan = Database::transaction($db,function (PDO $db) use ($ids,$placeholders,$expected,$rangeStart,$rangeEnd,$now,$createManifest,$manifest): array {
                $rows = $this->lockRetentionRows($db,$ids,$placeholders);
                $deletableIds = $this->retentionDeletableIds($rows,$expected,$createManifest);
                $deleteTickets = array_intersect_key($expected,array_fill_keys($deletableIds,true));
                $dbFiles = $this->filterRetentionFiles(
                    $this->retentionAttachmentRows($db,$ids,$placeholders),$deletableIds,
                );
                $files = $this->filterRetentionFiles(
                    $this->mergeRetentionFiles($manifest['files'] ?? [],$dbFiles),$deletableIds,
                );
                $existing = $this->existingRetentionFiles($files);
                if ($createManifest) {
                    $manifestTickets = [];
                    foreach ($expected as $id => $closedAt) {
                        $manifestTickets[] = ['id'=>$id,'closed_at'=>$closedAt];
                    }
                    $this->retentionManifestStore()->persist($manifestTickets,$dbFiles,$now);
                }
                $presentIds = array_fill_keys(array_column($rows,'id'),true);
                return [
                    'rows'=>count(array_intersect_key($deleteTickets,$presentIds)),
                    'tickets'=>$deleteTickets,
                    'files'=>$files,
                    'existing_count'=>count($existing),
                ];
            });
            if ($plan['rows'] === 0 && $plan['existing_count'] === 0) {
                return 0;
            }
            $details['record_count'] = $plan['rows'];
            $details['attachment_count'] = $plan['existing_count'];
            $stage = 'pending_audit';
            $this->writeRetentionAudit($db,'PENDING',$details,$now);
            $stage = 'database_delete';
            $deleted = 0;
            if ($plan['rows'] > 0) {
                $deleteIds = array_keys($plan['tickets']);
                $deletePlaceholders = implode(',',array_fill(0,count($deleteIds),'?'));
                $deleted = Database::transaction($db,function (PDO $db) use ($deleteIds,$deletePlaceholders,$plan): int {
                    $rows = $this->lockRetentionTickets($db,$deleteIds,$deletePlaceholders,$plan['tickets'],false);
                    foreach ([
                        'portal_support_attachments','portal_support_messages','portal_support_ticket_events',
                    ] as $table) {
                        $db->prepare("DELETE FROM $table WHERE ticket_id IN ($deletePlaceholders)")->execute($deleteIds);
                    }
                    $db->prepare("DELETE FROM portal_support_tickets WHERE id IN ($deletePlaceholders)")->execute($deleteIds);
                    return count($rows);
                });
            }
            $details['record_count'] = $deleted;
            $stage = 'file_delete';
            $this->deleteRetentionFiles($plan['files']);
            $stage = 'success_audit';
            $this->writeRetentionAudit($db,'SUCCESS',$details,$now);
            return $deleted;
        } catch (Throwable $error) {
            $this->recordRetentionFailure($db,$details + ['stage'=>$stage],$now,$error);
            throw $error;
        }
    }

    private function lockRetentionRows(PDO $db, array $ids, string $placeholders): array
    {
        $query = $db->prepare("SELECT id,status,closed_at FROM portal_support_tickets
            WHERE id IN ($placeholders) FOR UPDATE");
        $query->execute($ids);
        return $query->fetchAll();
    }

    private function retentionDeletableIds(array $rows, array $expected, bool $requireAll): array
    {
        if ($requireAll && count($rows) !== count($expected)) {
            throw new \RuntimeException('support_purge_selection_changed');
        }
        $deletable = array_fill_keys(array_keys($expected),true);
        foreach ($rows as $row) {
            $id = (string)$row['id'];
            if ($row['status'] !== 'CLOSED' || $row['closed_at'] === null ||
                !isset($expected[$id]) || (int)$row['closed_at'] !== $expected[$id]) {
                if ($requireAll) {
                    throw new \RuntimeException('support_purge_selection_changed');
                }
                unset($deletable[$id]);
            }
        }
        return array_keys($deletable);
    }

    private function lockRetentionTickets(PDO $db, array $ids, string $placeholders, array $expected, bool $requireAll): array
    {
        $rows = $this->lockRetentionRows($db,$ids,$placeholders);
        $this->retentionDeletableIds($rows,$expected,$requireAll);
        foreach ($rows as $row) {
            $id = (string)$row['id'];
            if ($row['status'] !== 'CLOSED' || $row['closed_at'] === null ||
                !isset($expected[$id]) || (int)$row['closed_at'] !== $expected[$id]) {
                throw new \RuntimeException('support_purge_selection_changed');
            }
        }
        return $rows;
    }

    private function retentionAttachmentRows(PDO $db, array $ids, string $placeholders): array
    {
        $query = $db->prepare("SELECT ticket_id,storage_name,created_at FROM portal_support_attachments
            WHERE ticket_id IN ($placeholders)");
        $query->execute($ids);
        return $query->fetchAll();
    }

    private function filterRetentionFiles(array $files, array $ticketIds): array
    {
        $allowed = array_fill_keys($ticketIds,true);
        return array_values(array_filter($files,static fn(array $file): bool =>
            isset($allowed[(string)$file['ticket_id']])));
    }

    private function mergeRetentionFiles(array $left, array $right): array
    {
        $merged = [];
        foreach (array_merge($left,$right) as $file) {
            $merged[$file['storage_name']] = [
                'ticket_id'=>(string)$file['ticket_id'],
                'storage_name'=>(string)$file['storage_name'],
                'created_at'=>(int)$file['created_at'],
            ];
        }
        ksort($merged,SORT_STRING);
        return array_values($merged);
    }

    private function existingRetentionFiles(array $files): array
    {
        $existing = [];
        foreach ($files as $file) {
            $path = $this->attachmentPath((string)$file['storage_name'],(int)$file['created_at']);
            if (!file_exists($path) && !is_link($path)) {
                continue;
            }
            if (!is_file($path) && !is_link($path)) {
                throw new \RuntimeException('support_attachment_delete_failed');
            }
            $existing[] = $file;
        }
        return $existing;
    }

    private function deleteRetentionFiles(array $files): void
    {
        foreach ($files as $file) {
            $path = $this->attachmentPath((string)$file['storage_name'],(int)$file['created_at']);
            if ((file_exists($path) || is_link($path)) && !@unlink($path)) {
                throw new \RuntimeException('support_attachment_delete_failed');
            }
        }
    }

    private function writeRetentionAudit(PDO $db, string $result, array $details, int $now): void
    {
        Database::transaction($db,static function (PDO $db) use ($result,$details,$now): void {
            Audit::admin($db,null,'system@d2s.invalid','CLEANUP_SUPPORT_TICKETS','support_tickets',
                ['source_ip'=>null],$result,$details,$now);
        });
    }

    private function recordRetentionFailure(PDO $db, array $details, int $now, Throwable $original): void
    {
        try {
            $this->writeRetentionAudit($db,'FAILURE',$details,$now);
        } catch (Throwable) {
            throw new \RuntimeException('support_retention_failure_audit_failed',0,$original);
        }
    }

    private function retentionManifestStore(): SupportRetentionManifests
    {
        if ($this->secrets === null) {
            throw new \RuntimeException('support_retention_manifest_key_required');
        }
        $root = $this->manifestRoot ?? (getenv('D2S_RETENTION_MANIFEST_DIR') ?: D2S_ROOT.'/storage/retention-manifests');
        return new SupportRetentionManifests($root,$this->secrets);
    }

    private function account(PDO $db, int $userId): array
    {
        $query = $db->prepare('SELECT status FROM portal_users WHERE id=? FOR UPDATE');
        $query->execute([$userId]);
        if ($query->fetchColumn() !== 'active') {
            throw new Problem('account_restricted_or_unverified',403);
        }
        $blocked = $db->prepare('SELECT login_blocked FROM portal_user_controls WHERE user_id=?');
        $blocked->execute([$userId]);
        if ((int)$blocked->fetchColumn() === 1) {
            throw new Problem('account_restricted',403);
        }
        $contact = $db->prepare("SELECT kind,value FROM portal_contacts WHERE user_id=? AND verified_at IS NOT NULL
            ORDER BY CASE kind WHEN 'email' THEN 0 ELSE 1 END LIMIT 1");
        $contact->execute([$userId]);
        $row = $contact->fetch();
        if ($row === false) {
            throw new Problem('account_restricted_or_unverified',403);
        }
        return [$row['kind'] === 'email' ? $row['value'] : null,$row['value']];
    }

    private function ticket(int $userId, string $ticketId): array
    {
        $query = $this->db->prepare("SELECT t.id,t.category,t.subject,t.status,t.contact_value,t.created_at,t.updated_at,
            t.closed_at,o.order_no FROM portal_support_tickets t LEFT JOIN portal_orders o ON o.id=t.order_id
            WHERE t.id=? AND t.user_id=?");
        $query->execute([$ticketId,$userId]);
        $ticket = $query->fetch();
        if ($ticket === false) {
            throw new Problem('ticket_not_found',404);
        }
        return $ticket;
    }

    private function lockedTicket(PDO $db, int $userId, string $ticketId): array
    {
        $query = $db->prepare('SELECT * FROM portal_support_tickets WHERE id=? AND user_id=? FOR UPDATE');
        $query->execute([$ticketId,$userId]);
        $ticket = $query->fetch();
        if ($ticket === false) {
            throw new Problem('ticket_not_found',404);
        }
        return $ticket;
    }

    private function messages(string $ticketId, bool $staff): array
    {
        $filter = $staff ? '' : " AND m.visibility='PUBLIC'";
        $query = $this->db->prepare("SELECT m.id,m.author_kind,m.author_id,m.visibility,m.body,m.body_format,m.created_at,
            CASE WHEN m.author_kind='ADMIN' THEN a.username ELSE u.username END AS author_name
            FROM portal_support_messages m
            LEFT JOIN portal_admins a ON m.author_kind='ADMIN' AND a.id=m.author_id
            LEFT JOIN portal_users u ON m.author_kind='USER' AND u.id=m.author_id
            WHERE m.ticket_id=? $filter ORDER BY m.created_at,m.id");
        $query->execute([$ticketId]);
        $rows = $query->fetchAll();
        $attachments = $this->db->prepare('SELECT id,message_id,original_name,mime_type,byte_size,sha256,created_at
            FROM portal_support_attachments WHERE ticket_id=? ORDER BY created_at,id');
        $attachments->execute([$ticketId]);
        $byMessage = [];
        foreach ($attachments->fetchAll() as $attachment) {
            $byMessage[$attachment['message_id']][] = $attachment;
        }
        foreach ($rows as &$row) {
            $row['attachments'] = $byMessage[$row['id']] ?? [];
            unset($byMessage[$row['id']]);
        }
        unset($row);
        return $rows;
    }

    private function event(PDO $db, string $ticketId, string $actorKind, int $actorId, string $type, ?string $from, ?string $to, array $details, int $now): void
    {
        $db->prepare('INSERT INTO portal_support_ticket_events
            (ticket_id,actor_kind,actor_id,event_type,from_status,to_status,details_json,created_at)
            VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$ticketId,$actorKind,$actorId,$type,$from,$to,
                json_encode($details,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$now]);
    }

    private function adminAudit(int $adminId, string $operation, string $target, array $context, array $details, int $now): void
    {
        if ($this->db->inTransaction()) {
            Audit::admin($this->db,$adminId,$this->adminEmail($this->db,$adminId),$operation,$target,$context,'SUCCESS',$details,$now);
            return;
        }
        Database::transaction($this->db,function (PDO $db) use ($adminId,$operation,$target,$context,$details,$now): void {
            Audit::admin($db,$adminId,$this->adminEmail($db,$adminId),$operation,$target,$context,'SUCCESS',$details,$now);
        });
    }

    private function adminEmail(PDO $db, int $adminId): string
    {
        $query = $db->prepare("SELECT email FROM portal_admins WHERE id=? AND status='active'");
        $query->execute([$adminId]);
        $email = $query->fetchColumn();
        if ($email === false) {
            throw new Problem('administrator_inactive',403);
        }
        return (string)$email;
    }

    private function attachmentPath(string $storageName, int $createdAt): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$storageName)) {
            throw new Problem('attachment_storage_invalid');
        }
        $root = $this->storageRoot ?? (getenv('D2S_TICKET_STORAGE') ?: D2S_ROOT.'/storage/tickets');
        if (!str_starts_with($root,DIRECTORY_SEPARATOR) && !preg_match('/^[A-Za-z]:[\\\\\/]/D',$root)) {
            $root = D2S_ROOT.'/'.$root;
        }
        return rtrim($root,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.substr($storageName,0,2)
            .DIRECTORY_SEPARATOR.$storageName;
    }

    private function filename(string $name): string
    {
        $name = basename(str_replace('\\','/',$name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u','',$name) ?: 'attachment';
        return mb_strcut($name,0,255,'UTF-8');
    }

    private function messageHash(string $ticketId, int $authorId, string $kind, string $visibility, string $body, string $format, int $now): string
    {
        return hash('sha256',json_encode([$ticketId,$authorId,$kind,$visibility,$body,$format,$now],
            JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }

    private function messageBody(mixed $source,string $format,int $minimum): array
    {
        $format=strtoupper(trim($format));
        if(!in_array($format,['PLAIN','HTML'],true))throw new Problem('ticket_body_invalid');
        if($format==='HTML'){
            $body=TicketContent::clean($source);
            $visible=TicketContent::plainText($body);
        }else{
            if(!is_string($source))throw new Problem('ticket_body_invalid');
            $body=trim($source);
            $visible=$body;
        }
        $length=mb_strlen($visible,'UTF-8');
        if($length<$minimum||$length>TicketContent::MAX_CHARACTERS)throw new Problem('ticket_body_invalid');
        return [$body,$format];
    }

    private function body(string $value, int $minimum, int $maximum, string $error): void
    {
        $length = mb_strlen($value,'UTF-8');
        if ($length < $minimum || $length > $maximum) {
            throw new Problem($error);
        }
    }

    private static function id(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D',$id)) {
            throw new Problem('resource_id_invalid');
        }
    }
}
