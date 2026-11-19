<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use PDO;

final readonly class PolicyConsents
{
    public function __construct(private PDO $db)
    {
    }

    public function missing(int $userId): array
    {
        $policies=(new SiteContent($this->db))->registrationPolicies();
        foreach($policies as$policy){
            if(!preg_match('/^[A-Za-z0-9._+-]{1,40}$/D',$policy['version']))throw new Problem('legal_policy_unavailable',503);
        }
        $query=$this->db->prepare('SELECT policy_type,policy_version FROM portal_policy_consents WHERE user_id=?');
        $query->execute([$userId]);
        $accepted=[];
        foreach($query->fetchAll()as$row)$accepted[$row['policy_type'].':'.$row['policy_version']]=true;
        return array_values(array_filter($policies,static fn(array $policy):bool=>
            !isset($accepted[$policy['type'].':'.$policy['version']])));
    }

    public function record(int $userId, array $policies, array $context, int $now): void
    {
        $locales = new LocaleRegistry();
        $locale = $locales->normalize((string)($context['policy_locale'] ?? ''), true) ?? $locales->defaultLocale();
        foreach($policies as$policy){
            $consentHash=hash('sha256',json_encode([$userId,$policy['type'],$policy['version'],$locale,$policy['effective_date'],
                $now,$context['source_ip']??null,$context['user_agent']??null],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
            $this->db->prepare('INSERT IGNORE INTO portal_policy_consents
                (user_id,policy_type,policy_version,locale_code,effective_date,accepted_at,source_ip,user_agent,record_hash)
                VALUES (?,?,?,?,?,?,?,?,?)')->execute([$userId,$policy['type'],$policy['version'],$locale,$policy['effective_date'],
                $now,$context['source_ip']??null,$context['user_agent']??null,$consentHash]);
        }
    }
}
