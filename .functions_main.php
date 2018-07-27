<?php
$f_settings='/root/ishi/.settings';

function arg_array($args){
	$ret=array('script_name'=>$args[0]);
	unset($args[0]);

	if(!count($args)) return $ret;

	foreach($args as $av){
		$av=explode('=',$av);
		$ret[trim(reset($av))]=trim(end($av));
	}
	return $ret;
}

function read_settings($file){
	$settings=array();

	$file=file_get_contents($file);
	$file=explode("\n",$file);

	//store vars & disregard comments
	foreach($file as $fv){
		if (strpos($fv, '.')===0&&strpos($fv, '=')!==FALSE){
			$fv=explode('=',$fv);
			$settings[$fv[0]]=$fv[1];
		}
	}
	return $settings;
}

function read_list($file){
	$list=array();

	$file=file_get_contents($file);
	$file=explode("\n",$file);

	foreach($file as $fv){
		if(strpos($fv,':')!==FALSE){
			$fv=explode(':',$fv);
			unset($list[$fv[0]]);
			$list[$fv[0]]=array('domain'=>$fv[1],'u_ssh'=>$fv[2],'p_ssh'=>$fv[3],'u_mysql'=>$fv[4],'p_mysql'=>$fv[5],'backups'=>$fv[6]);
		}
	}
	return $list;
}

function write_sites($action,$val){
	global $f_settings;
	
	$settings=read_settings($f_settings);
	$list=read_list($settings['.store_sites']);

	if($action=="add"){

		$new_site=key($val);
		unset($list[$new_site]);
		$list[$new_site]=$val[$new_site];
	}
	elseif($action=="del"){
		unset($list[$val]);
	}
	
	$file='';
	foreach($list as $ak => $av){
		$file.="{$ak}:{$list[$ak]['domain']}:{$list[$ak]['u_ssh']}:{$list[$ak]['p_ssh']}:{$list[$ak]['u_mysql']}:{$list[$ak]['p_mysql']}:{$list[$ak]['backups']}\n";
	}

	file_put_contents($settings['.store_sites'],$file);
}

function generate_password($length = 9, $add_dashes = false, $available_sets = 'lud'){
	$sets = array();
	if(strpos($available_sets, 'l') !== false)
		$sets[] = 'abcdefghjkmnpqrstuvwxyz';
	if(strpos($available_sets, 'u') !== false)
		$sets[] = 'ABCDEFGHJKMNPQRSTUVWXYZ';
	if(strpos($available_sets, 'd') !== false)
		$sets[] = '23456789';
	if(strpos($available_sets, 's') !== false)
		$sets[] = '!@#$%&*?';
 
	$all = '';
	$password = '';
	foreach($sets as $set)
	{
		$password .= $set[array_rand(str_split($set))];
		$all .= $set;
	}
 
	$all = str_split($all);
	for($i = 0; $i < $length - count($sets); $i++)
		$password .= $all[array_rand($all)];
 
	$password = str_shuffle($password);
 
	if(!$add_dashes)
		return $password;
 
	$dash_len = floor(sqrt($length));
	$dash_str = '';
	while(strlen($password) > $dash_len)
	{
		$dash_str .= substr($password, 0, $dash_len) . '-';
		$password = substr($password, $dash_len);
	}
	$dash_str .= $password;
	return $dash_str;
}

function set_ssh_key($name){
	global $f_settings;
	$settings=read_settings($f_settings);

	if($name=="ALL"){
		$sites=read_list('.store_sites');
		foreach($sites as $nk => $nv){
			set_ssh_key($nk);
		}
	}

	$keep=`mkdir -p /home/{$name}/.ssh`;
	$keep.=`cp {$settings['.store_rsa']} /home/{$name}/.ssh/authorized_keys`;
	$keep.=`chmod 644 /home/{$name}/.ssh/authorized_keys`;
}

function site_is_check($name){
	global $f_settings;
	$settings=read_settings($f_settings);

	if(strpos(file_get_contents('/etc/passwd'),$name.':')===FALSE){
		return FALSE;
	}else{
		return TRUE;
	}

	if(strpos(file_get_contents($settings['.store_sites']),$name.':')===FALSE){
		return FALSE;
	}else{
		return TRUE;
	}
}

function site_add($type,$name,$domain,$email=NULL,$backups=1){
	$name=$type.'_'.str_replace('.', '_', $name);

	//check if user exists
	if(site_is_check($name)){
		echo "bau";exit;
		return FALSE;
	}

	//create system account with ID over 2000.
	$keep=`useradd -K UID_MIN=9000 -K UID_MAX=10000 -s /bin/bash -m {$name}`;

	//check to see if user was created
	if(!site_is_check($name)){
		return FALSE;
	}

	$uid=trim(`grep {$name} /etc/passwd |awk -F ':' '{print \$3}'`);

	//create folders
	$keep.=`mkdir -p /home/{$name}/www`;

	//create mysql db
	$keep.=`mysql --execute="CREATE DATABASE {$name};"`;

	//set mysql pass
	$mysql_pass=generate_password(24);

	//create mysql account
	$keep.=`mysql --execute="GRANT ALL PRIVILEGES ON {$name}.* To '{$name}'@'localhost' IDENTIFIED BY '{$mysql_pass}';"`;
	//$keep.=`mysql --execute="GRANT ALL PRIVILEGES ON {$name}.* To '{$name}'@'10.130.4.119' IDENTIFIED BY '{$mysql_pass}';"`;

	//set mysql autologin file
	file_put_contents("/home/{$name}/.my.cnf", "[client]\nuser={$name}\npassword={$mysql_pass}\n");
	$keep.=`chmod 640 /home/{$name}/.my.cnf`;

	//set ssh key
	set_ssh_key($name);

	//add php-fpm pool
	$pwd=dirname(__FILE__);
	$keep.=`cp {$pwd}/.templates/_template_phpfpm /etc/php/7.0/fpm/pool.d/{$name}.conf`;
	$keep.=`sed -i 's/!USERNAME!/{$name}/g' /etc/php/7.0/fpm/pool.d/{$name}.conf`;
	$keep.=`sed -i 's/!PORT!/{$uid}/g' /etc/php/7.0/fpm/pool.d/{$name}.conf`;

	//add nginx
    $keep.=`cp {$pwd}/.templates/_template_nginx /etc/nginx/sites-available/{$name}`;
    $keep.=`sed -i 's/!USERNAME!/{$name}/g' /etc/nginx/sites-available/{$name}`;
    $keep.=`sed -i 's/!DOMAIN!/{$domain}/g' /etc/nginx/sites-available/{$name}`;
    $keep.=`sed -i 's/!PORT!/{$uid}/g' /etc/nginx/sites-available/{$name}`;
    $keep.=`touch /etc/nginx/sites-lock/{$name}`;

    //create locking template
    $keep.=`cp {$pwd}/.templates/_template_lock_conf /root/ishi/lock-conf/{$name}.conf`;
    $keep.=`sed -i 's/!USERNAME!/{$name}/g' /root/ishi/lock-conf/{$name}.conf`;

	//set privileges
	$keep.=`chown -R {$name}:{$name} /home/{$name}`;

	//make authorized_keys immovable, it's better
	$keep.=`chmod 400 /home/{$name}/.ssh/authorized_keys`;
	$keep.=`chattr +i /home/{$name}/.ssh/authorized_keys`;

	//add site to list
	$new_site[$name]=array('domain'=>$domain,'u_ssh'=>$name,'p_ssh'=>'','u_mysql'=>$name,'p_mysql'=>$mysql_pass,'backups'=>$backups);
	write_sites('add',$new_site);

	//enable nginx
	$keep.=`ln -s /etc/nginx/sites-available/{$name} /etc/nginx/sites-enabled/{$name}`;

	//restart servers
	//$keep.=`service php7.0-fpm restart`;
	$keep.=`service php5.6-fpm restart`;
	$keep.=`service nginx restart`;

	if(strpos($email,'@')!==FALSE){
	//get ssl certificate
	$keep.=`/opt/letsencrypt/letsencrypt-auto certonly --webroot --webroot-path /home/{$name}/www/ --renew-by-default --email {$email} --text --agree-tos -d {$domain}`;
	$keep.=`sed -i 's/#ssl#//g' /etc/nginx/sites-available/{$name}`;
	$keep.=`service nginx restart`;
	}

	return TRUE;
}

function site_del($name, $backups=0){
	//check if exists
	if(!site_is_check($name)){
		return FALSE;
	}

	//remove immutable flag
	$keep=`find /home/{$name}/ -type f -exec chattr -i {} \;`;

	//delete linux user & associated files
	$keep=`/usr/sbin/userdel -f -r {$name} > /dev/null 2>&1`;
	if(strpos($keep,'is currently used by process')!==FALSE){
		echo "Site could not be deleted because it is used by some process!\n";
	}

	//delete mysql user & db
	$keep.=`mysql --execute="DROP DATABASE IF EXISTS {$name};"`;

	$keep.=`mysql --execute="DROP USER '{$name}'@'localhost';"`;

	//delete site from list
	write_sites('del',$name);

	//delete nginx site
	$keep.=`rm -rf /etc/nginx/sites-available/{$name}`;
	$keep.=`rm -rf /etc/nginx/sites-enabled/{$name}`;
	$keep.=`rm -rf /etc/nginx/sites-lock/{$name}`;

	$keep.=`rm -rf /etc/php/7.0/fpm/pool.d/{$name}.conf`;
	$keep.=`rm -rf /root/ishi/lock-conf/{$name}.conf`;

	//delete logs
	$keep.=`rm -rf /var/log/nginx/access-{$name}.*`;
	$keep.=`rm -rf /var/log/nginx/error-{$name}.*`;

	//restart server
	$keep.=`service php5.6-fpm restart`;
	$keep.=`service nginx restart`;

	//delete backups if required
	if($backups){
		delete_site_backup($name);
	}

	if(site_is_check($name)){
		return FALSE;
	}

	return TRUE;
}

function list_sites(){
	global $f_settings;

	

	$settings=read_settings($f_settings);
	$list=read_list($settings['.store_sites']);
	ksort($list);
	$a2ensites=$settings['.a2ensites'];

	echo "Name\t\t\t\tBck\tActive\tDomain\n";
	foreach($list as $lk => $lv){
		$t=4-floor(strlen($lk)/8);
		$tt='';
		for($i=0;$i<$t;$i++){
			$tt.="\t";
		}
		echo "-----------------------------------------------------------------------------------\n";
		echo "{$lk}{$tt}{$lv['backups']}\t".((is_file($a2ensites.$lk)&&file_exists($a2ensites.$lk))?"1":"0")."\t{$lv['domain']}\n";
	}
}

function delete_site_backup($name){
	global $f_settings;
	$settings=read_settings($f_settings);

	$b_path=$settings['.backup_path'];
	$keep=`rm -rf {$b_path}{$name}-daily_*`;
	$keep.=`rm -rf {$b_path}{$name}-user_*`;
	$keep.=`rm -rf {$b_path}{$name}-weekly_*`;

	//replace backup store
	$b_store=$settings['.store_backups'];
	$b_store_c=`cat {$b_store} |grep -v ':{$name}-user_' |grep -v ':{$name}-daily_' |grep -v ':{$name}-weekly_'`;
	file_put_contents($b_store, $b_store_c);
}

?>
