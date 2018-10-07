<?php

$backup_types=array('daily'=>1,'weekly'=>1,'user'=>1);

function backup_site($name="ALL",$type="ALL"){
	global $f_settings, $backup_types, $pwd;
	$settings=read_settings($f_settings);
	$list=read_list();

	if($name=="ALL"){
		if($type=="ALL"){
			foreach($list as $nk => $nv){
				foreach($backup_types as $bk => $bv){
					if(!backup_site($nk,$bk)){
						echo "No backup for $nk\n";
						//return FALSE;
					}
				}
			}
		}
		elseif(isset($backup_types[$type])){
			foreach($list as $nk => $nv){
				if(!backup_site($nk,$type)){
					echo "No backup for $nk\n";
					//return FALSE;
				}
			}
		}
		return TRUE;
	}
	elseif($name!="ALL" && $type=="ALL"){
		foreach($backup_types as $bk => $bv){
			if(!backup_site($name,$bk)){
				echo "No backup for $nk\n";
				//return FALSE;
			}
		}
		return TRUE;
	}

	//incorect site name & happens only on manual
	if(!isset($list[$name])||!is_array($list[$name])||!isset($backup_types[$type])){
		return FALSE;
	}

	//check if site backups are disabled for and and it wasn't a user request.
	if(!$list[$name]['backups']&&$type!='user'){
		//skip backup nicely
		echo "Backup skipped for $name\n";
		return TRUE;
	}

	$date=date("Ymd_His",time());

	$keep=`mysqldump {$name} > {$pwd}/tmp/database_dump.sql; chown -R {$name}:{$name} {$pwd}/tmp/database_dump.sql`;
	$keep=`tar czf {$pwd}/tmp/{$name}-{$type}_{$date}.tar.gz -C /home/{$name} www -C {$pwd}/tmp database_dump.sql`;

	// check and reconnect mountpoint if necessary
	check_mountpoint($settings['.backup_path']);

	// move backup to Amazon S3
	$keep=`mv {$pwd}/tmp/{$name}-{$type}_{$date}.tar.gz {$settings['.backup_path']}/{$name}-{$type}_{$date}.tar.gz`;
	$keep=`rm -rf {$pwd}/tmp/database_dump.sql`;

	if(is_file("{$settings['.backup_path']}/{$name}-{$type}_{$date}.tar.gz")){
		return TRUE;
	}

	return FALSE;
}

function check_mountpoint($path, $count = 0){
	// give up after three retries
	if($count > 3) return;

	// check mountpoint
	$mountpoint = trim(`mount |grep -c {$path}`);
	if(!$mountpoint) {

		// reconnect mountpoint
		$mountpoint = `umount -l {$path} > /dev/null 2>&1`;
		$mountpoint = `umount -f {$path} > /dev/null 2>&1`;
		$mountpoint = `mount {$path} > /dev/null 2>&1`;
		sleep(1);

		// recheck whole procedure
		$count++;
		check_mountpoint($path, $count);
	}
	else {
		// all is fine, exit
		return;
	}
}

function list_backups($name="ALL"){
	global $f_settings, $pwd;
	$settings=read_settings($f_settings);
	$path=$settings['.backup_path'];

	$list=array();

	$file = `ls -lah {$path} |grep '.tar.gz' |awk '{print \$NF}'`;
	$file = explode("\n", $file);

	foreach($file as $fv){
		if(strpos($fv,'.tar.gz')!==FALSE){
			$user = @reset(explode('-', $fv));
			$type = @reset(explode('_',end(explode('-', $fv))));
			$date = explode('_', $fv);
			$date = str_replace('.tar.gz', '', $date[2].'_'.$date[3]);

			$list[$user][$type][$date] = $fv;
		}
	}

	if($name!="ALL"&&isset($list[$name])){
		return array($name=>$list[$name]);
	}
	elseif($name=="ALL"){
		return $list;
	}
	else{
		return FALSE;
	}
}

function backup_rotate($site="ALL"){
	global $f_settings;
	$settings=read_settings($f_settings);

	// check and reconnect mountpoint on Amazon S3
	check_mountpoint($settings['.backup_path']);

	if($site=="ALL"){
		$list=list_backups();
		foreach($list as $nk => $nv){
			backup_rotate($nk);
		}
	}
	else{
		$c=array();
		$c['daily']=$settings['.keep_daily'];
		$c['weekly']=$settings['.keep_weekly'];
		$c['user']=$settings['.keep_user'];

		$list=list_backups($site);
		foreach($c as $ck => $cv){
			if(isset($list[$site][$ck])&&is_array($list[$site][$ck])){
				while(count($list[$site][$ck])>$cv){
					$key=min(array_keys($list[$site][$ck]));
					$keep=`rm -rf {$settings['.backup_path']}/{$list[$site][$ck][$key]}`;
					unset($list[$site][$ck][$key]);
				}
			}
		}//fend
	}
	return TRUE;
}

function list_backups_nice($name="ALL"){
	$list=list_backups($name);

	echo "Name\t\t\t\tType\t\t\tArchive\n";
	if(!isset($list)||!is_array($list)) return FALSE;

	foreach($list as $nk => $nv){
		echo "----------------------------------------------------------------------------------------------------------------\n";
		foreach($nv as $tk => $tv){
			foreach($tv as $dk => $dv){
				$t=4-floor(strlen($nk)/8);
				$tt='';
				for($i=0;$i<$t;$i++){
					$tt.="\t";
				}
				$t2=4-floor(strlen($tk)/4);
				$tt2='';
				for($i=0;$i<$t2;$i++){
					$tt2.="\t";
				}
				echo "{$nk}{$tt}{$tk}{$tt2}{$dv}\n";
			}
		}
	}
}

function restore_from($name,$file){
	global $pwd, $f_settings;
	$settings=read_settings($f_settings);

	//check if we have a backup with that file name
	$isit = strpos( json_encode(list_backups()), '"'.$file.'"');

	if($isit === FALSE){
		return FALSE;
	}

	//delete current www and extact new one
	if(file_exists("{$settings['.backup_path']}/{$file}")&&is_file("{$settings['.backup_path']}/{$file}")){
		$keep=`rm -rf /home/{$name}/www`;
	}else{
		return FALSE;
	}
	$keep.=`cp {$settings['.backup_path']}/{$file} {$pwd}/tmp/{$file}`;
	$keep.=`tar xzf {$pwd}/tmp/{$file} -C /home/{$name}/`;
	$keep.=`rm -rf {$pwd}/tmp/{$file}`;

	//empty database and load new one
	$keep.=`mysql --execute="DROP DATABASE IF EXISTS {$name};"`;
	$keep.=`mysql --execute="CREATE DATABASE {$name};"`;
	$keep.=`mysql -h localhost {$name} < /home/{$name}/database_dump.sql`;
	$keep.=`rm -rf /home/{$name}/database_dump.sql`;

	return TRUE;
}

?>