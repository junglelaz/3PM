<?php
final class ThreePM_Market_Store {
    public static function table(){global $wpdb;return $wpdb->prefix.'threepm_market';}
    public static function install(){
        global $wpdb;require_once ABSPATH.'wp-admin/includes/upgrade.php';$table=self::table();$charset=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            record_key varchar(100) NOT NULL,
            kind varchar(20) NOT NULL,
            owner bigint(20) unsigned NOT NULL,
            peer bigint(20) unsigned NOT NULL DEFAULT 0,
            state varchar(32) NOT NULL,
            version bigint(20) unsigned NOT NULL DEFAULT 1,
            payload longtext NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY record_key (record_key),
            KEY owner_kind (owner,kind),
            KEY peer_kind (peer,kind),
            KEY kind_state (kind,state)
        ) $charset;");
        if($wpdb->last_error)throw new RuntimeException('Market storage installation failed.');
    }
    public static function decode($r){if(!$r)return null;$r['data']=json_decode($r['payload'],true,512,JSON_THROW_ON_ERROR);unset($r['payload']);return $r;}
    public static function get($id){global $wpdb;return self::decode($wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE id=%d',$id),ARRAY_A));}
    public static function key($key){global $wpdb;return self::decode($wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE record_key=%s',$key),ARRAY_A));}
    public static function list($kind,$user=0,$public=false){global $wpdb;$q='SELECT * FROM '.self::table().' WHERE kind=%s';$args=[$kind];if($public){$q.=" AND state='published'";}elseif($user){$q.=' AND (owner=%d OR peer=%d)';$args[]=$user;$args[]=$user;}$q.=' ORDER BY id DESC'.($kind==='service'?'':' LIMIT 100');return array_map([__CLASS__,'decode'],$wpdb->get_results($wpdb->prepare($q,...$args),ARRAY_A));}
    public static function all_reviews(){global $wpdb;return array_map([__CLASS__,'decode'],$wpdb->get_results("SELECT * FROM ".self::table()." WHERE kind='review' ORDER BY id DESC",ARRAY_A));}
    public static function create($key,$kind,$owner,$peer,$state,$data){
        global $wpdb;$data['history']=[['event'=>'created','actor'=>get_current_user_id(),'at'=>gmdate('c'),'state'=>$state]];
        $ok=$wpdb->insert(self::table(),['record_key'=>$key,'kind'=>$kind,'owner'=>$owner,'peer'=>$peer,'state'=>$state,'version'=>1,'payload'=>wp_json_encode($data,JSON_THROW_ON_ERROR),'updated_at'=>gmdate('Y-m-d H:i:s')]);
        if(!$ok)throw new RuntimeException('This request already exists or could not be saved. Refresh before retrying.');return self::get((int)$wpdb->insert_id);
    }
    public static function save($r,$state,$data,$event){
        global $wpdb;$data['history']=$r['data']['history']??[];$data['history'][]=['event'=>$event,'actor'=>get_current_user_id(),'at'=>gmdate('c'),'state'=>$state];
        $ok=$wpdb->query($wpdb->prepare('UPDATE '.self::table().' SET state=%s,version=version+1,payload=%s,updated_at=%s WHERE id=%d AND version=%d',$state,wp_json_encode($data,JSON_THROW_ON_ERROR),gmdate('Y-m-d H:i:s'),$r['id'],$r['version']));
        if($ok!==1)throw new RuntimeException('The record changed. Refresh and review the latest version.');return self::get($r['id']);
    }
}
