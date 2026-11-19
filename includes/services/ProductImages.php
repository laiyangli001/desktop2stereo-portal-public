<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;

final readonly class ProductImages
{
    public function __construct(private PDO $db,private string $directory) {}

    /** Decode and re-encode raster data, discarding metadata and appended payloads. */
    public static function normalize(string $bytes): string
    {
        if(strlen($bytes)<1||strlen($bytes)>5*1024*1024)throw new Problem('product_image_size_invalid',413);
        $info=@getimagesizefromstring($bytes);
        if(!$info||!in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG],true)||
            $info[0]<1||$info[1]<1||$info[0]>8000||$info[1]>8000||$info[0]*$info[1]>12000000){
            throw new Problem('product_image_type_invalid');
        }
        $source=@imagecreatefromstring($bytes);
        if(!$source)throw new Problem('product_image_type_invalid');
        $scale=min(1,1600/max($info[0],$info[1]));
        $width=max(1,(int)round($info[0]*$scale));$height=max(1,(int)round($info[1]*$scale));
        $target=imagecreatetruecolor($width,$height);
        if(!$target)throw new Problem('product_image_processing_failed',503);
        try{
            imagealphablending($target,false);imagesavealpha($target,true);
            imagecopyresampled($target,$source,0,0,0,0,$width,$height,$info[0],$info[1]);
            ob_start();
            try{if(!imagepng($target,null,6))throw new Problem('product_image_processing_failed',503);$clean=ob_get_contents();}
            finally{ob_end_clean();}
            if(!is_string($clean)||strlen($clean)>10*1024*1024)throw new Problem('product_image_size_invalid',413);
            return $clean;
        }finally{imagedestroy($source);imagedestroy($target);}
    }

    public function upload(int $adminId,string $code,array $file,int $expectedVersion,array $context,int $now,bool $draft=false): array
    {
        Permissions::require($this->db,$adminId,'products.manage');
        ProductCatalog::code($code);
        $temporary=$file['tmp_name']??null;
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_string($temporary)||
            !is_file($temporary)||filesize($temporary)>5*1024*1024||
            (PHP_SAPI!=='cli'&&!is_uploaded_file($temporary)))throw new Problem('product_image_upload_invalid');
        $bytes=file_get_contents($temporary);
        if(!is_string($bytes))throw new Problem('product_image_upload_invalid');
        $clean=self::normalize($bytes);
        $hash=hash('sha256',$clean);
        $path='/product-images/'.$hash.'.png';
        return Database::transaction($this->db,function(PDO $db)use($adminId,$code,$expectedVersion,$context,$now,$clean,$hash,$path,$draft):array{
            $q=$db->prepare('SELECT lock_version,draft_json FROM portal_products WHERE code=? AND deleted_at IS NULL FOR UPDATE');
            $q->execute([$code]);$product=$q->fetch();
            if(!$product)throw new Problem('product_not_found',404);
            if((int)$product['lock_version']!==$expectedVersion||($draft&&$product['draft_json']===null)){
                throw new Problem('record_changed',409);
            }
            if(!is_dir($this->directory)&&!mkdir($this->directory,0700,true)&&!is_dir($this->directory)){
                throw new Problem('product_image_storage_unavailable',503);
            }
            $file=$this->directory.DIRECTORY_SEPARATOR.$hash.'.png';
            if(!is_file($file)){
                $stage=$this->directory.DIRECTORY_SEPARATOR.bin2hex(random_bytes(16)).'.tmp';
                try{
                    if(file_put_contents($stage,$clean,LOCK_EX)!==strlen($clean)||!rename($stage,$file)){
                        throw new Problem('product_image_storage_unavailable',503);
                    }
                    chmod($file,0600);
                }finally{if(is_file($stage))unlink($stage);}
            }
            if($draft){
                $db->prepare("UPDATE portal_products SET draft_json=JSON_SET(draft_json,'$.image_path',?),lock_version=lock_version+1 WHERE code=?")
                    ->execute([$path,$code]);
            }else{
                $db->prepare('UPDATE portal_products SET image_path=?,lock_version=lock_version+1,updated_at=? WHERE code=?')
                    ->execute([$path,$now,$code]);
            }
            (new Backoffice($db))->audit($adminId,$draft?'STAGE_PRODUCT_IMAGE':'UPDATE_PRODUCT_IMAGE',$code,['sha256'=>$hash],$context,$now);
            return ['code'=>$code,'image_path'=>$path,'lock_version'=>$expectedVersion+1];
        });
    }

    public static function directory(): string
    {
        return getenv('D2S_PRODUCT_IMAGE_DIR')?:D2S_ROOT.'/storage/product-images';
    }
}
