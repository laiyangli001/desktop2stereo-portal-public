<?php
declare(strict_types=1);

use D2S\domain\Problem;
use D2S\repositories\Database;
use D2S\services\Audit;
use D2S\services\Backoffice;
use D2S\services\ArticleTranslations;
use D2S\services\HttpRequest;
use D2S\services\Permissions;
use D2S\services\TaskHealth;
use D2S\services\UiTranslationCatalogs;
use D2S\services\WebSessions;

$db=Database::fromEnvironment();
$method=$_SERVER['REQUEST_METHOD'];
$write=in_array($method,['POST','PUT'],true);
if(!in_array($method,['GET','POST','PUT'],true))throw new Problem('method_not_allowed',405);
$owner=HttpRequest::session('admin',new WebSessions($db),$write,time());
$largeArticleWrite=is_string($path)&&preg_match('#^/api/v2/admin/operations/articles(?:/|$)#D',$path)===1;
$largeCatalogImport=is_string($path)&&preg_match('#^/api/v2/admin/operations/ui-catalog/import$#D',$path)===1;
$productImageWrite=$method==='POST'&&preg_match('#^/api/v2/admin/operations/products/([a-z][a-z0-9_-]{2,31})/image$#D',$path,$productImageRoute)===1;
$largeReleaseWrite=is_string($path)&&preg_match('#^/api/v2/admin/operations/products/[a-z][a-z0-9_-]{2,31}/releases$#D',$path)===1;
if($productImageWrite)HttpRequest::assertOrigin();
$input=$write&&!$productImageWrite?HttpRequest::json(true,$largeArticleWrite?524288:($largeCatalogImport?2097152:($largeReleaseWrite?524288:32768))):[];
$service=new Backoffice($db);
$adminId=(int)$owner['id'];
$context=Audit::context($_SERVER);
$now=time();
$route=substr($path,strlen('/api/v2/admin/operations'));
$articleTranslations=new ArticleTranslations($db);
$uiTranslationCatalogs=new UiTranslationCatalogs($db,new D2S\services\LocaleRegistry());

if($productImageWrite){
    $expected=filter_var($_POST['lock_version']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if($expected===false)throw new Problem('record_changed',409);
    $data=(new D2S\services\ProductImages($db,D2S\services\ProductImages::directory()))
        ->upload($adminId,$productImageRoute[1],$_FILES['image']??[],(int)$expected,$context,$now,true);
}elseif(preg_match('#^/products/([a-z][a-z0-9_-]{2,31})/releases$#D',$route,$productReleaseRoute)){
    $manager=new D2S\services\ProductReleaseManager($db,D2S\services\SecretBox::fromEnvironment());
    $data=$write?$manager->saveRelease($adminId,$productReleaseRoute[1],$input,$context,$now):$manager->releases($adminId,$productReleaseRoute[1]);
}elseif($method==='POST'&&preg_match('#^/products/([a-z][a-z0-9_-]{2,31})/releases/([a-f0-9]{32})/(test|publish|withdraw)$#D',$route,$releaseActionRoute)){
    $manager=new D2S\services\ProductReleaseManager($db,D2S\services\SecretBox::fromEnvironment());
    $data=match($releaseActionRoute[3]){
        'test'=>$manager->markTested($adminId,$releaseActionRoute[1],$releaseActionRoute[2],$context,$now),
        'publish'=>$manager->publish($adminId,$releaseActionRoute[1],$releaseActionRoute[2],$context,$now),
        default=>$manager->withdraw($adminId,$releaseActionRoute[1],$releaseActionRoute[2],$context,$now),
    };
}elseif($method==='GET'&&preg_match('#^/products/([a-z][a-z0-9_-]{2,31})/release-history$#D',$route,$releaseHistoryRoute)){
    $manager=new D2S\services\ProductReleaseManager($db,D2S\services\SecretBox::fromEnvironment());
    $data=$manager->releaseHistory($adminId,$releaseHistoryRoute[1]);
}elseif($method==='POST'&&preg_match('#^/products/([a-z][a-z0-9_-]{2,31})/channels/([a-f0-9]{32})/attest$#D',$route,$attestDownloadChannelRoute)){
    $manager=new D2S\services\ProductReleaseManager($db,D2S\services\SecretBox::fromEnvironment());
    $data=$manager->attestDownloadChannel($adminId,$attestDownloadChannelRoute[1],$attestDownloadChannelRoute[2],$context,$now);
}elseif($method==='POST'&&preg_match('#^/products/([a-z][a-z0-9_-]{2,31})/channels/([a-f0-9]{32})/check$#D',$route,$checkDownloadChannelRoute)){
    $manager=new D2S\services\ProductReleaseManager($db,D2S\services\SecretBox::fromEnvironment());
    $data=$manager->checkDownloadChannel($adminId,$checkDownloadChannelRoute[1],$checkDownloadChannelRoute[2],$context,$now);
}elseif($method==='POST'&&preg_match('#^/products/([a-z][a-z0-9_-]{2,31})/channels/([a-f0-9]{32})(?:/(remove))?$#D',$route,$downloadChannelRoute)){
    $manager=new D2S\services\ProductReleaseManager($db,D2S\services\SecretBox::fromEnvironment());
    $data=$manager->updateDownloadChannel($adminId,$downloadChannelRoute[1],$downloadChannelRoute[2],$input,$context,$now,
        ($downloadChannelRoute[3]??'')==='remove');
}elseif($route==='/download-hosts'){
    $manager=new D2S\services\ProductReleaseManager($db,D2S\services\SecretBox::fromEnvironment());
    if($write){HttpRequest::strings($input,['hostname']);$data=$manager->allowDownloadHost($adminId,$input['hostname'],$context,$now);}
    else $data=$manager->downloadHosts($adminId);
}elseif($method==='POST'&&preg_match('#^/download-hosts/([^/]+)/disable$#D',$route,$disableDownloadHostRoute)){
    $manager=new D2S\services\ProductReleaseManager($db,D2S\services\SecretBox::fromEnvironment());
    $data=$manager->disableDownloadHost($adminId,rawurldecode($disableDownloadHostRoute[1]),$context,$now);
}elseif(preg_match('#^/products/([a-z][a-z0-9_-]{2,31})/update-policy$#D',$route,$updatePolicyRoute)&&$write){
    $manager=new D2S\services\ProductReleaseManager($db,D2S\services\SecretBox::fromEnvironment());
    $data=$manager->savePolicy($adminId,$updatePolicyRoute[1],$input,$context,$now);
}elseif(preg_match('#^/products/([a-z][a-z0-9_-]{2,31})/product-operations$#D',$route,$productOperationsRoute)){
    $manager=new D2S\services\ProductReleaseManager($db,D2S\services\SecretBox::fromEnvironment());
    $data=$write
        ?$manager->saveProductOperations($adminId,$productOperationsRoute[1],$input,$context,$now)
        :$manager->productOperations($adminId,$productOperationsRoute[1]);
}elseif($write&&preg_match('#^/products/([a-z][a-z0-9_-]{2,31})/delete$#D',$route,$deleteProductRoute)){
    $data=(new D2S\services\ProductCatalog($db))->deleteAllLanguages($adminId,$deleteProductRoute[1],$context,$now);
}elseif($route==='/products'&&in_array($method,['GET','POST'],true)){
    $catalog=new D2S\services\ProductCatalog($db);
    $data=$write?$catalog->save($adminId,$input,$context,$now):[
        'items'=>$catalog->adminList($adminId,(string)($_GET['locale']??d2s_locale_code())),
        'locales'=>(new D2S\services\LocaleRegistry())->enabledForAdmin(),
    ];
}elseif($method==='GET'&&$route==='/publication-progress'){
    $data=(new D2S\services\ContentPublicationLanguages($db))->progress($adminId,
        (string)($_GET['type']??''),(string)($_GET['id']??''));
}elseif($method==='GET'&&$route==='/dashboard'){
    $data=$service->dashboard($adminId,$now);
}elseif($route==='/locales/publish'&&$write){
    $data=(new D2S\services\LocaleSettings($db))->publish($adminId,$input,$context,$now);
}elseif($route==='/locales/catalog-rollback'&&$write){
    $data=(new D2S\services\LocaleSettings($db))->rollbackCatalog($adminId,$input,$context,$now);
}elseif($route==='/locales'){
    $settings=new D2S\services\LocaleSettings($db);
    $data=$write?$settings->save($adminId,$input,$context,$now):$settings->view($adminId);

}elseif($route==='/ui-catalog/export'&&$method==='GET'){
    $locale=$_GET['locale']??'';
    if(!is_string($locale))throw new Problem('translation_locale_invalid');
    $namespace=$_GET['namespace']??null;
    if($namespace!==null&&!is_string($namespace))throw new Problem('translation_namespace_invalid');
    $data=$uiTranslationCatalogs->exportPackage($adminId,$locale,$namespace);
}elseif($route==='/ui-catalog/import'&&$write){
    $data=$uiTranslationCatalogs->importPackage($adminId,$input,$context,$now);

}elseif($method==='POST'&&preg_match('#^/users/([1-9][0-9]*)/client-session/logout$#D',$route,$clientLogoutRoute)){
    HttpRequest::strings($input,['product','device_hash']);
    if(!is_int($input['session_version']??null))throw new Problem('client_session_invalid');
    (new D2S\services\LicenseOverview($db))->logoutByAdmin($adminId,Backoffice::id($clientLogoutRoute[1]),
        $input['device_hash'],$input['session_version'],$context,$now,$input['product']);
    $data=['logged_out'=>true,'product'=>$input['product']];
}elseif($method==='GET'&&$route==='/users'){
    $data=$service->users($adminId,$_GET);
}elseif(preg_match('#^/users/([1-9][0-9]*)(/wallet)?$#D',$route,$matches)){
    $id=Backoffice::id($matches[1]);
    if($method==='GET'&&!isset($matches[2]))$data=$service->user($adminId,$id,$context,$now);
    elseif($write&&($matches[2]??'')==='/wallet')$data=$service->adjustWallet($adminId,$id,$input,$context,$now);
    elseif($write&&!isset($matches[2]))$data=$service->updateUser($adminId,$id,$input,$context,$now);
    else throw new Problem('not_found',404);
}elseif($method==='GET'&&$route==='/ledger'){
    $data=$service->ledger($adminId,$_GET);
}elseif($method==='GET'&&in_array($route,['/orders','/orders/export'],true)){
    $data=$service->orders($adminId,$_GET,$route==='/orders/export');
    if($route==='/orders/export'){
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="orders-'.gmdate('Ymd-His').'.csv"');
        $stream=fopen('php://output','wb');fwrite($stream,"\xEF\xBB\xBF");
        $columns=['order_no','username','product','currency','amount','refunded_amount','payment_method','status','created_at','paid_at'];
        fputcsv($stream,$columns,',','"','');
        foreach($data['items']as$row){
            $values=[];foreach($columns as$key){
                $value=$row[$key]??'';
                if(is_string($value)&&preg_match('/^[=+@\\t\\r-]/',$value))$value="'".$value;
                $values[]=$value;
            }
            fputcsv($stream,$values,',','"','');
        }
        fclose($stream);exit;
    }
}elseif($method==='GET'&&preg_match('#^/orders/([a-f0-9]{32})/diagnostics$#D',$route,$matches)){
    $data=$service->diagnostics($adminId,$matches[1]);
}elseif($route==='/payment-interfaces'){
    $interfaces=new D2S\services\PaymentInterfaces($db,D2S\services\SecretBox::fromEnvironment());
    $data=$write?$interfaces->save($adminId,$input,$context,$now):$interfaces->view($adminId);

}elseif($route==='/wallet-recharge'&&$method==='POST'){
    if(!is_bool($input['enabled']??null))throw new Problem('boolean_required');
    (new D2S\services\WalletRecharges($db))->setEnabled($adminId,$input['enabled'],$context,$now);
    $data=['enabled'=>$input['enabled']];
}elseif($route==='/payment-configurations'){
    $configurations=new D2S\services\PaymentConfigurations($db,D2S\services\SecretBox::fromEnvironment());
    $data=$write?$configurations->save($adminId,$input,$context,$now):$configurations->view($adminId);
}elseif($write&&$route==='/external/refunds'){
    $data=(new D2S\services\ExternalPayments($db,D2S\services\SecretBox::fromEnvironment()))->requestRefund($adminId,$input,$context,$now);
}elseif($method==='GET'&&preg_match('#^/external/orders/([a-f0-9]{32})$#D',$route,$externalMatch)){
    $data=(new D2S\services\ExternalPayments($db,D2S\services\SecretBox::fromEnvironment()))->overview($adminId,$externalMatch[1]);
}elseif(preg_match('#^/products/([A-Za-z0-9_-]{1,32})/translations/([A-Za-z0-9_-]+)/translate$#D',$route,$productTranslationJobRoute)&&$write){
    $data=(new D2S\services\TranslationJobs($db))->enqueueProduct($adminId,$productTranslationJobRoute[1],$productTranslationJobRoute[2],$input,$context,$now);
}elseif(preg_match('#^/product/translations/([A-Za-z0-9_-]+)/save-and-translate$#D',$route,$atomicProductTranslationRoute)&&$write){
    $targetLocale=$input['target_locale']??null;
    if(!is_string($targetLocale))throw new Problem('locale_unsupported',400);
    $data=Database::transaction($db,function(PDO $tx)use($adminId,$atomicProductTranslationRoute,$targetLocale,$input,$context,$now):array{
        $source=$tx->prepare('SELECT source_locale FROM portal_products WHERE code=? FOR UPDATE');
        $source->execute(['desktop2stereo']);
        if($source->fetchColumn()!==($input['locale']??null))throw new Problem('translation_target_is_source',409);
        $translations=new D2S\services\ProductTranslations($tx);
        $saved=$translations->save($adminId,'desktop2stereo',$atomicProductTranslationRoute[1],$input,$context,$now);
        $job=(new D2S\services\TranslationJobs($tx))->enqueueProduct($adminId,'desktop2stereo',$targetLocale,[], $context,$now);
        return ['saved'=>$saved,'translation_job'=>$job];
    });
}elseif(preg_match('#^/announcements/([a-f0-9]{16})/translations/([A-Za-z0-9_-]+)/translate$#D',$route,$announcementTranslationJobRoute)&&$write){
    $data=(new D2S\services\TranslationJobs($db))->enqueueAnnouncement($adminId,$announcementTranslationJobRoute[1],$announcementTranslationJobRoute[2],$input,$context,$now);
}elseif(preg_match('#^/policies/([A-Z]+)/([A-Za-z0-9._-]{1,40})/translations/([A-Za-z0-9_-]+)/translate$#D',$route,$policyTranslationJobRoute)&&$write){
    $data=(new D2S\services\TranslationJobs($db))->enqueuePolicy($adminId,$policyTranslationJobRoute[1],$policyTranslationJobRoute[2],$policyTranslationJobRoute[3],$input,$context,$now);
}elseif(preg_match('#^/articles/([a-f0-9]{32})/translations/([A-Za-z0-9_-]+)/translate$#D',$route,$translationJobRoute)&&$write){
    $data=(new D2S\services\TranslationJobs($db))->enqueueArticle($adminId,$translationJobRoute[1],$translationJobRoute[2],$input,$context,$now);
}elseif(preg_match('#^/articles/([a-f0-9]{32})/translations/([A-Za-z0-9_-]+)/save-and-translate$#D',$route,$atomicArticleTranslationRoute)&&$write){
    $targetLocale=$input['target_locale']??null;
    if(!is_string($targetLocale))throw new Problem('locale_unsupported',400);
    $data=Database::transaction($db,function(PDO $tx)use($adminId,$atomicArticleTranslationRoute,$targetLocale,$input,$context,$now):array{
        $source=$tx->prepare('SELECT source_locale FROM portal_articles WHERE id=? FOR UPDATE');
        $source->execute([$atomicArticleTranslationRoute[1]]);
        if($source->fetchColumn()!==($input['locale']??null))throw new Problem('translation_target_is_source',409);
        $translations=new D2S\services\ArticleTranslations($tx);
        $saved=$translations->save($adminId,$atomicArticleTranslationRoute[1],$atomicArticleTranslationRoute[2],$input,$context,$now);
        $job=(new D2S\services\TranslationJobs($tx))->enqueueArticle($adminId,$atomicArticleTranslationRoute[1],$targetLocale,[], $context,$now);
        return ['saved'=>$saved,'translation_job'=>$job];
    });
}elseif(preg_match('#^/translation-jobs/([1-9][0-9]*)$#D',$route,$translationJobStatusRoute)&&$method==='GET'){
    $data=(new D2S\services\TranslationJobs($db))->status($adminId,Backoffice::id($translationJobStatusRoute[1]));
}elseif($route==='/translation-settings'){
    $settings=new D2S\services\TranslationSettings($db,D2S\services\SecretBox::fromEnvironment());
    $data=$write?$settings->save($adminId,$input,$context,$now):$settings->view($adminId);
}elseif($route==='/translation-glossary'){
    $glossary=new D2S\services\TranslationGlossary($db);
    $data=$write?$glossary->save($adminId,$input,$context,$now):
        $glossary->view($adminId,(string)($_GET['source_locale']??''),(string)($_GET['target_locale']??''));
}elseif($route==='/translation-glossary/history'&&$method==='GET'){
    $data=(new D2S\services\TranslationGlossary($db))->history($adminId,(string)($_GET['source_locale']??''),(string)($_GET['target_locale']??''));
}elseif($route==='/translation-glossary/restore'&&$write){
    $data=(new D2S\services\TranslationGlossary($db))->restore($adminId,$input,$context,$now);
}elseif(preg_match('#^/articles/([a-f0-9]{32})/translations/([A-Za-z0-9_-]+)(?:/(publish))?$#D',$route,$translationRoute)){
    $articleId=$translationRoute[1];$locale=$translationRoute[2];
    if($method==='GET'&&empty($translationRoute[3]))$data=$articleTranslations->get($adminId,$articleId,$locale);
    elseif($write&&!empty($translationRoute[3]))$data=$articleTranslations->publish($adminId,$articleId,$locale,$context,$now);
    elseif($write)$data=$articleTranslations->save($adminId,$articleId,$locale,$input,$context,$now);
    else throw new Problem('method_not_allowed',405);
}elseif(preg_match('#^/articles/([a-f0-9]{32})/translations$#D',$route,$translationCatalogRoute)&&$method==='GET'){
    $data=$articleTranslations->catalog($adminId,$translationCatalogRoute[1]);
}elseif($route==='/articles/publish-languages'&&$write){
    $data=$articleTranslations->publishLanguages($adminId,$input,$context,$now);
}elseif(preg_match('#^/articles/([a-f0-9]{32})/delete$#D',$route,$deleteArticleRoute)&&$write){
    $data=$articleTranslations->deleteAllLanguages($adminId,$deleteArticleRoute[1],$context,$now);
}elseif($route==='/articles/locales'&&$method==='GET'){
    $data=$articleTranslations->locales($adminId);
}elseif($route==='/articles/categories'&&$method==='GET'){
    $data=(new D2S\services\ArticleCategories($db))->adminList($adminId,(string)($_GET['locale']??$_SERVER['HTTP_X_D2S_LOCALE']??'zh-Hans'));
}elseif($write&&$route==='/articles/categories'){
    $data=(new D2S\services\ArticleCategories($db))->saveTranslation($adminId,$input,$context,$now);
}elseif($route==='/articles'){
    if($method==='GET')$data=$service->articles($adminId,(string)($_GET['locale']??$_SERVER['HTTP_X_D2S_LOCALE']??'zh-Hans'));
    elseif(isset($input['locale']))$data=$articleTranslations->create($adminId,$input,$context,$now);
    else throw new Problem('article_translation_required',409);
}elseif($route==='/product/locales'&&$method==='GET'){
    $data=(new D2S\services\ProductTranslations($db))->locales($adminId);
}elseif(preg_match('#^/product/translations/([A-Za-z0-9_-]+)(?:/(publish))?$#D',$route,$productTranslationRoute)){
    $translations=new D2S\services\ProductTranslations($db);
    $locale=$productTranslationRoute[1];
    if($method==='GET'&&empty($productTranslationRoute[2]))$data=$translations->get($adminId,'desktop2stereo',$locale);
    elseif($write&&!empty($productTranslationRoute[2]))$data=$translations->publish($adminId,'desktop2stereo',$locale,$context,$now);
    elseif($write)$data=$translations->save($adminId,'desktop2stereo',$locale,$input,$context,$now);
    else throw new Problem('method_not_allowed',405);
}elseif($route==='/product'){
    $data=$service->product($adminId,$write?$input:null,$context,$now);
}elseif($route==='/staff'){
    $data=$write?$service->saveStaff($adminId,$input,$context,$now):$service->staff($adminId);
}elseif($route==='/upgrade/check'&&$method==='GET'){
    $data=(new D2S\services\SystemOperations($db))->checkGitHubUpdate($adminId,$_GET['address']??null);
}elseif($route==='/upgrade/download'&&$method==='POST'){
    $data=(new D2S\services\SystemOperations($db))->queueGitHubDownload($adminId,$input);
}elseif($route==='/upgrade/stage'&&$write){
    $data=(new D2S\services\SystemOperations($db))->stageOnlineUpdate($adminId,$input,$context,$now);
}elseif($route==='/upgrade/prepare'&&$write){
    $data=(new D2S\services\SystemOperations($db))->prepareOnlineUpdate($adminId,$input,$context,$now);
}elseif($route==='/upgrade/install'&&$method==='POST'){
    $data=(new D2S\services\SystemOperations($db))->startOnlineUpdateInstall($adminId,$input,$context,$now);
}elseif($route==='/upgrade/progress'&&$method==='GET'){
    $taskId=$_GET['task_id']??'';
    if(!is_string($taskId))throw new Problem('update_install_task_invalid',400);
    $data=(new D2S\services\SystemOperations($db))->onlineUpdateProgress($adminId,$taskId,$context,$now);
}elseif(in_array($route,['/maintenance','/upgrade'],true)){
    if($write)throw new Problem('method_not_allowed',405);
    $system=new D2S\services\SystemOperations($db);
    $data=$route==='/maintenance'?$system->maintenance($adminId):$system->upgrade($adminId);
}elseif($route==='/tasks'){
    $tasks=new D2S\services\TaskSettings($db);
    $data=$write?$tasks->save($adminId,$input,$context,$now):$tasks->overview($adminId,$now);
}else{
    throw new Problem('not_found',404);
}
echo json_encode(['success'=>true,'data'=>$data],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
