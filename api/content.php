<?php
declare(strict_types=1);

use D2S\domain\Problem;
use D2S\repositories\Database;
use D2S\services\Audit;
use D2S\services\HttpRequest;
use D2S\services\Permissions;
use D2S\services\ReleaseInfo;
use D2S\services\PolicyTranslations;
use D2S\services\AnnouncementTranslations;
use D2S\services\LocaleRegistry;
use D2S\services\TranslationJobs;
use D2S\services\SiteContent;
use D2S\services\WebSessions;

$db=Database::fromEnvironment();
$method=$_SERVER['REQUEST_METHOD'];
$owner=HttpRequest::session('admin',new WebSessions($db),$method==='POST',time());
$context=Audit::context($_SERVER);
$now=time();
$content=new SiteContent($db);
$policies=new PolicyTranslations($db);

if($method==='POST'&&$path==='/api/v2/admin/content/policies/publish-languages'){
    $input=HttpRequest::json(true,200000);
    HttpRequest::strings($input,['policy_type','policy_version','locale','title']);
    if(!is_string($input['body']??null)||strlen($input['body'])>150000
        ||!is_int($input['lock_version']??null)||!is_array($input['target_locales']??null))throw new Problem('policy_translation_invalid');
    $data=Database::transaction($db,function(PDO $tx)use($owner,$input,$context,$now):array{
        Permissions::require($tx,$owner['id'],'settings.manage');
        $languages=new D2S\services\ContentPublicationLanguages($tx);
        $selected=$languages->targets($input['locale'],$input['target_locales']);
        $type=strtoupper($input['policy_type']);$version=$input['policy_version'];
        $catalog=(new PolicyTranslations($tx))->adminCatalog($owner['id'],$type);
        if($catalog['version']!==$version)throw new Problem('record_changed',409);
        $tx->prepare('INSERT IGNORE INTO portal_policy_documents
            (policy_type,policy_version,effective_date,source_locale,source_revision,lock_version,created_at,updated_at)
            VALUES (?,?,?,?,0,1,?,?)')->execute([$type,$version,$catalog['effective_date'],$selected[0],$now,$now]);
        $tx->prepare('UPDATE portal_policy_documents SET source_revision=source_revision+1,source_locale=? WHERE policy_type=? AND policy_version=? AND source_locale<>?')
            ->execute([$selected[0],$type,$version,$selected[0]]);
        $saved=(new PolicyTranslations($tx))->save($owner['id'],$type,$version,$selected[0],
            $input['title'],$input['body'],$input['lock_version'],$context,$now,true);
        $id=substr(hash('sha256',$type."\n".$version),0,32);
        $languages->save('policy',$id,$selected[0],$selected,$now);
        $targets=array_values(array_diff($selected,[$selected[0]]));
        $jobs=$targets===[]?['jobs'=>[]]:(new TranslationJobs($tx))->enqueueMany($owner['id'],'policy',$type,$targets,
            ['publish_when_ready'=>true],$context,$now,$version);
        return ['saved'=>$saved,'publication_locales'=>$selected,'translation_jobs'=>$jobs['jobs']];
    });
}elseif($method==='POST'&&$path==='/api/v2/admin/content/announcements/publish-languages'){
    $input=HttpRequest::json(true,200000);
    HttpRequest::strings($input,['locale']);
    if(!is_array($input['items']??null)||!is_array($input['target_locales']??null))throw new Problem('site_announcement_invalid');
    $data=Database::transaction($db,function(PDO $tx)use($owner,$input,$context,$now):array{
        Permissions::require($tx,$owner['id'],'announcements.manage');
        $languages=new D2S\services\ContentPublicationLanguages($tx);
        $selected=$languages->targets($input['locale'],$input['target_locales']);
        $before=$tx->query('SELECT id FROM portal_announcements')->fetchAll(PDO::FETCH_COLUMN);
        $edited=[];
        foreach($input['items']as$item){
            if(!is_array($item))throw new Problem('site_announcement_invalid');
            $id=$item['id']??'';
            if($id!==''){
                $q=$tx->prepare('SELECT source_locale,lock_version FROM portal_announcements WHERE id=? FOR UPDATE');$q->execute([$id]);$entity=$q->fetch();
                if(!$entity||(int)$entity['lock_version']!==(int)($item['entity_lock_version']??0))throw new Problem('record_changed',409);
                if($entity['source_locale']!==$selected[0])$tx->prepare('UPDATE portal_announcements SET source_locale=?,source_revision=source_revision+1 WHERE id=?')->execute([$selected[0],$id]);
                $edited[]=$id;
            }
        }
        $saved=(new AnnouncementTranslations($tx,new LocaleRegistry()))->save($owner['id'],$selected[0],$input['items'],
            (array)($input['deleted_ids']??[]),$context,$now,true);
        $jobs=[];$targets=array_values(array_diff($selected,[$selected[0]]));
        foreach($saved as$row){
            if(!in_array($row['id'],$edited,true)&&in_array($row['id'],$before,true))continue;
            $languages->save('announcement',$row['id'],$selected[0],$selected,$now);
            if($targets!==[])$jobs[$row['id']]=(new TranslationJobs($tx))->enqueueMany($owner['id'],'announcement',$row['id'],
                $targets,['publish_when_ready'=>true],$context,$now)['jobs'];
        }
        return ['saved'=>(new AnnouncementTranslations($tx,new LocaleRegistry()))->adminList($selected[0]),
            'publication_locales'=>$selected,'translation_jobs'=>$jobs,
            'created_ids'=>array_values(array_diff(array_column($saved,'id'),$before))];
    });
}elseif($method==='POST'&&$path==='/api/v2/admin/content/announcements/draft'){
    $input=HttpRequest::json(true,200000);HttpRequest::strings($input,['locale']);
    if(!is_array($input['items']??null))throw new Problem('site_announcement_invalid');
    $data=Database::transaction($db,function(PDO $tx)use($owner,$input,$context,$now):array{
        Permissions::require($tx,$owner['id'],'announcements.manage');
        if(!is_array($input['target_locales']??[]))throw new Problem('translation_targets_invalid');
        $targets=array_key_exists('target_locales',$input)
            ? (new D2S\services\ContentPublicationLanguages($tx))->targets($input['locale'],$input['target_locales']) : [];
        $before=$tx->query('SELECT id FROM portal_announcements')->fetchAll(PDO::FETCH_COLUMN);
        $saved=(new AnnouncementTranslations($tx,new LocaleRegistry()))
            ->save($owner['id'],$input['locale'],$input['items'],[],$context,$now,false,false,$targets);
        return ['saved'=>$saved,'created_ids'=>array_values(array_diff(array_column($saved,'id'),$before)),'translation_jobs'=>[]];
    });
}elseif($method==='POST'&&$path==='/api/v2/admin/content/policies/delete'){
    $input=HttpRequest::json(true);HttpRequest::strings($input,['policy_type','policy_version']);
    $data=$policies->deleteAllLanguages($owner['id'],$input['policy_type'],$input['policy_version'],$context,$now);
}elseif($method==='GET'&&$path==='/api/v2/admin/content/policies'){
    $data=$policies->adminCatalog($owner['id'],(string)($_GET['type']??''));
}elseif($method==='GET'&&$path==='/api/v2/admin/content/policies/translation'){
    $data=$policies->get($owner['id'],(string)($_GET['type']??''),(string)($_GET['locale']??''));
}elseif($method==='POST'&&$path==='/api/v2/admin/content/policies'){
    $input=HttpRequest::json(true,200000);
    HttpRequest::strings($input,['policy_type','policy_version','locale','title']);
    if(!isset($input['body'])||!is_string($input['body'])||strlen($input['body'])>150000
        ||!is_int($input['lock_version']??null))throw new Problem('policy_translation_invalid');
    $data=$policies->save($owner['id'],$input['policy_type'],$input['policy_version'],$input['locale'],
        $input['title'],$input['body'],$input['lock_version'],$context,$now,$input['publish']);
    $admin=$db->prepare('SELECT email FROM portal_admins WHERE id=?');
    $admin->execute([$owner['id']]);
    Audit::admin($db,$owner['id'],(string)$admin->fetchColumn(),'SAVE_POLICY_TRANSLATION',
        $data['policy_type'].':'.$data['policy_version'],$context,'SUCCESS',
        ['locale'=>$data['locale'],'revision'=>$data['revision'],'published'=>$data['published']],$now);
}elseif($method==='POST'&&$path==='/api/v2/admin/content/policies/save-and-translate'){
    $input=HttpRequest::json(true,200000);
    HttpRequest::strings($input,['policy_type','policy_version','locale','target_locale','title']);
    if(!isset($input['body'])||!is_string($input['body'])||strlen($input['body'])>150000
        ||!is_int($input['lock_version']??null)||!is_bool($input['publish']??null))throw new Problem('policy_translation_invalid');
    $target=$input['target_locale'];
    $data=Database::transaction($db,function(PDO $tx)use($owner,$input,$target,$context,$now):array{
        $source=$tx->prepare('SELECT source_locale FROM portal_policy_documents WHERE policy_type=? AND policy_version=? FOR UPDATE');
        $source->execute([strtoupper($input['policy_type']),$input['policy_version']]);
        if($source->fetchColumn()!==$input['locale'])throw new Problem('translation_target_is_source',409);
        $saved=(new PolicyTranslations($tx))->save($owner['id'],$input['policy_type'],$input['policy_version'],$input['locale'],
            $input['title'],$input['body'],$input['lock_version'],$context,$now,false);
        $job=(new TranslationJobs($tx))->enqueuePolicy($owner['id'],$input['policy_type'],
            $input['policy_version'],$target,[],$context,$now);
        return ['saved'=>$saved,'translation_job'=>$job];
    });
}elseif($method==='GET'&&$path==='/api/v2/admin/content'){
    $data=$content->view($owner['id'],(string)($_GET['locale'] ?? 'zh-Hans'));
}elseif($method==='POST'&&$path==='/api/v2/admin/content/site'){
    $input=HttpRequest::json(true);
    $data=$content->saveSite($owner['id'],(array)($input['values']??[]),$context,$now,(string)($input['locale'] ?? 'zh-Hans'));
}elseif($method==='POST'&&$path==='/api/v2/admin/content/announcements'){
    $input=HttpRequest::json(true);
    if(!is_array($input['items']??null))throw new Problem('site_announcement_invalid');
    $data=$content->saveAnnouncements($owner['id'],$input['items'],$context,$now,(string)($input['locale'] ?? 'zh-Hans'),(array)($input['deleted_ids'] ?? []),(bool)($input['publish'] ?? true));
}elseif($method==='POST'&&$path==='/api/v2/admin/content/announcements/save-and-translate'){
    $input=HttpRequest::json(true,200000);
    HttpRequest::strings($input,['locale','target_locale']);
    if(!is_array($input['item']??null)||!is_string($input['item']['id']??null)
        ||!is_int($input['item']['entity_lock_version']??null)
        ||!is_int($input['item']['translation_lock_version']??null)
        ||!is_string($input['item']['title']??null)||!is_string($input['item']['body']??null)
        ||!is_bool($input['item']['enabled']??null))throw new Problem('site_announcement_invalid');
    $target=$input['target_locale'];
    $data=Database::transaction($db,function(PDO $tx)use($owner,$input,$target,$context,$now):array{
        $source=$tx->prepare('SELECT source_locale FROM portal_announcements WHERE id=? AND deleted_at IS NULL FOR UPDATE');
        $source->execute([$input['item']['id']]);
        if($source->fetchColumn()!==$input['locale'])throw new Problem('translation_target_is_source',409);
        $saved=(new AnnouncementTranslations($tx,new LocaleRegistry()))
            ->save($owner['id'],$input['locale'],[$input['item']],[],$context,$now,false,true);
        $job=(new TranslationJobs($tx))->enqueueAnnouncement($owner['id'],$input['item']['id'],
            $target,[],$context,$now);
        return ['saved'=>$saved,'translation_job'=>$job];
    });
}elseif($method==='POST'&&$path==='/api/v2/admin/content/release/refresh'){
    Permissions::require($db,$owner['id'],'settings.manage');
    $site=$content->view($owner['id'])['site'];
    $release=ReleaseInfo::fromEnvironment()->refresh($site);
    $query=$db->prepare('SELECT email FROM portal_admins WHERE id=?');
    $query->execute([$owner['id']]);
    Audit::admin($db,$owner['id'],(string)$query->fetchColumn(),'REFRESH_GITHUB_RELEASE',$site['github_repo'],
        $context,'SUCCESS',['version'=>$release['version'],'published_at'=>$release['published_at']],$now);
    $data=$release;
}else{
    throw new Problem('not_found',404);
}
echo json_encode(['success'=>true,'data'=>$data],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
