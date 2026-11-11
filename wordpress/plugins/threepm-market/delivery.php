<?php
/** Private delivery bytes are stored in bounded database chunks, never public Media Library URLs. */
final class ThreePM_Market_Delivery {
    public static function table(){global $wpdb;return $wpdb->prefix.'threepm_market_files';}
    public static function install(){global $wpdb;require_once ABSPATH.'wp-admin/includes/upgrade.php';$table=self::table();$charset=$wpdb->get_charset_collate();dbDelta("CREATE TABLE $table (
        file_key char(48) NOT NULL,
        chunk_no int unsigned NOT NULL,
        payload longblob NOT NULL,
        PRIMARY KEY  (file_key,chunk_no)
    ) ENGINE=InnoDB $charset;");if($wpdb->last_error)throw new RuntimeException('Private delivery storage installation failed.');}
    public static function upload($r){
        if(empty($_FILES['delivery_file']['name']))return null;
        if((int)$r['owner']!==get_current_user_id()||!in_array($r['state'],['active','revision_requested'],true))throw new RuntimeException('Delivery upload is not available.');
        if(count($r['data']['deliveries']??[])>=20)throw new RuntimeException('This engagement has reached its delivery file limit.');
        $f=$_FILES['delivery_file'];$size=(int)($f['size']??0);
        if(($f['error']??1)!==UPLOAD_ERR_OK||$size<1||$size>20*1024*1024||!is_uploaded_file($f['tmp_name']))throw new RuntimeException('Use a delivery file up to 20 MB.');
        $name=sanitize_file_name($f['name']);$ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
        if(!in_array($ext,['zip','wav','mp3','flac','aif','aiff','pdf','txt'],true))throw new RuntimeException('Use ZIP, WAV, MP3, FLAC, AIFF, PDF or TXT.');
        $key=bin2hex(random_bytes(24));$stream=fopen($f['tmp_name'],'rb');if(!$stream)throw new RuntimeException('Could not read the delivery file.');
        global $wpdb;$hash=hash_init('sha256');$bytes=0;$chunk=0;
        try{
            if($wpdb->query('START TRANSACTION')===false)throw new RuntimeException('Private storage is unavailable.');
            while(!feof($stream)){$part=fread($stream,65536);if($part===false)throw new RuntimeException('Delivery file read failed.');if($part==='')continue;$bytes+=strlen($part);if($bytes>20*1024*1024)throw new RuntimeException('Delivery file exceeds the limit.');hash_update($hash,$part);
                // Base64 avoids connection charset transformations of arbitrary audio bytes.
                if(!$wpdb->insert(self::table(),['file_key'=>$key,'chunk_no'=>$chunk++,'payload'=>base64_encode($part)]))throw new RuntimeException('Delivery storage failed.');
            }
            if($bytes!==$size)throw new RuntimeException('Delivery file changed during upload.');
            if($wpdb->query('COMMIT')===false)throw new RuntimeException('Delivery storage could not be committed.');
        }catch(Throwable $e){$wpdb->query('ROLLBACK');throw $e;}finally{fclose($stream);}
        return ['key'=>$key,'name'=>$name,'bytes'=>$bytes,'chunks'=>$chunk,'sha256'=>hash_final($hash),'uploaded_by'=>get_current_user_id(),'at'=>gmdate('c')];
    }
    public static function download(){
        if(!is_user_logged_in())wp_die('Sign in to view delivery files.','File unavailable',['response'=>403]);
        try{
            $id=absint($_GET['engagement']??0);$key=is_string($_GET['file']??null)?$_GET['file']:'';
            if(!preg_match('/^[a-f0-9]{48}$/D',$key))throw new RuntimeException('File unavailable.');
            check_admin_referer('threepm_delivery_'.$id.'_'.$key);
            $r=ThreePM_Market::record($id,'engagement');$file=null;foreach($r['data']['deliveries']??[]as $f)if(hash_equals($f['key'],$key))$file=$f;
            if(!$file)throw new RuntimeException('File unavailable.');global $wpdb;
            $rows=$wpdb->get_results($wpdb->prepare('SELECT chunk_no,payload FROM '.self::table().' WHERE file_key=%s ORDER BY chunk_no',$key),ARRAY_A);
            if(count($rows)!==$file['chunks'])throw new RuntimeException('Incomplete file. Contact 3PM.');
            $stream=fopen('php://temp/maxmemory:1048576','w+b');$hash=hash_init('sha256');$size=0;
            foreach($rows as $i=>$row){$part=base64_decode($row['payload'],true);if((int)$row['chunk_no']!==$i||$part===false)throw new RuntimeException('File integrity check failed.');$size+=strlen($part);hash_update($hash,$part);fwrite($stream,$part);}unset($rows);
            if($size!==$file['bytes']||!hash_equals($file['sha256'],hash_final($hash)))throw new RuntimeException('File integrity check failed.');
            rewind($stream);nocache_headers();header('Content-Type: application/octet-stream');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');header('Content-Disposition: attachment; filename="'.str_replace(['"',"\r","\n"],'',$file['name']).'"');header('Content-Length: '.$size);fpassthru($stream);fclose($stream);exit;
        }catch(Throwable $e){wp_die(esc_html($e->getMessage()),'File unavailable',['response'=>403]);}
    }
}
