# ishi
Ishi site management (for Ubuntu)

### Install ishi
`git clone https://github.com/dalvlex/ishi /root/ishi`  
Ishi is meant to be used as root and installed under /root/ishi, probably it would work as another user with sudo, but this is not tested!

### Amazon S3 backup storage
1. Install **s3fs**  
`apt install s3fs`  
2. Create a new bucket *ishi-backups-bucket* on Amazon S3  
3. Add a new user *ishi-backups-user* through Amazon IAM with programatic access  
4. Create and attach a policy *ishi-backups-policy* to the above user *ishi-backups-user* with list, read, write permissions only to the above bucket *ishi-backups-bucket*, and take note of the user's access_key and secret.  
5. Create s3fs password file  
`echo "access_key:secret" > /etc/passwd-s3fs; chmod 600 /etc/passwd-s3fs`  
6. Insert in /etc/fstab  
`ishi-backups-bucket /root/ishi/var/backups fuse.s3fs _netdev,retries=5,url=https://s3-eu-central-1.amazonaws.com 0 0`  
Be sure to change *eu-central-1* to whatever you Amazon S3 zone is, because s3fs loses proper auth between redirects and it will not work otherwise.  
7. Mount the bucket  
`mount /root/ishi/var/backups`  
The mount will be available after reboots.

If you want to just mount it manually without adding it to /etc/fstab  
`s3fs ishi-backups-bucket /root/ishi/var/backups -o url="https://s3-eu-central-1.amazonaws.com"`  
Be sure to change *eu-central-1* to whatever you Amazon S3 zone is, because s3fs loses proper auth between redirects and it will not work otherwise.  
Use `-o dbglevel=info -f -o curldbg` for debugging.  

### System prerequisites
1. Fix environment lang variables in ssh and disable password auth  
`sed -i 's/AcceptEnv LANG LC_\*/#AcceptEnv LANG LC_\*/g' /etc/ssh/sshd_config`  
`sed -i 's/PasswordAuthentication yes/PasswordAuthentication no/g' /etc/ssh/sshd_config`  
`service ssh restart`

2. Update the system
`apt update`

3. Add general packages
`apt install debconf-utils mysql-server apache2-utils`  
and take note of mySQL password if auth isn't made with PAM  
`apt install php-fpm php-cli php-mysql`  
`apt install git sqlite3 fail2ban letsencrypt`

4. Configure webserver
  * *Install apache2*  
    `apt install apache2 apache2-suexec-custom`  
    `a2enmod suexec proxy_fcgi actions alias rewrite headers ssl`
    
    *Disable default page*  
    ```
    echo '<Directory />  
    Order Deny,Allow  
    Deny from all  
    Options None  
    AllowOverride None  
    </Directory>' |cat - /etc/apache2/apache2.conf > temp_file && mv temp_file /etc/apache2/apache2.conf
    ```

    *Configure password for unlocked sites*  
    `htpasswd -c /etc/apache2/.htpasswd username_here`  

    *Log real IP address of web users coming through CloudFlare for fail2ban use*
    ```
    echo 'RemoteIPHeader CF-Connecting-IP  
    RemoteIPTrustedProxy 103.21.244.0/22 103.22.200.0/22 103.31.4.0/22 104.16.0.0/12 108.162.192.0/18 131.0.72.0/22 141.101.64.0/18 162.158.0.0/15 172.64.0.0/13 173.245.48.0/20 188.114.96.0/20 190.93.240.0/20 197.234.240.0/22 198.41.128.0/17 2400:cb00::/32 2405:8100::/32 2405:b500::/32 2606:4700::/32 2803:f800::/32 2c0f:f248::/32 2a06:98c0::/29' > /etc/apache2/conf-available/remoteip.conf;  
    a2enmod remoteip;  
    a2enconf remoteip;  
    ```

    **Restart web server**  
    `service apache2 restart`

  * *Install nginx*  
    `apt install nginx`
    
    *Disable default page*  
    `rm -rf /etc/nginx/sites-enabled/default`
    
    *Configure password for unlocked sites*  
    `htpasswd -c /etc/nginx/.htpasswd username_here`  

    *Log real IP address of web users coming through CloudFlare for fail2ban use*
    ```
    # insert the following into /etc/nginx.conf http {} context
    set_real_ip_from 103.21.244.0/22;
    set_real_ip_from 103.22.200.0/22;
    set_real_ip_from 103.31.4.0/22;
    set_real_ip_from 104.16.0.0/12;
    set_real_ip_from 108.162.192.0/18;
    set_real_ip_from 131.0.72.0/22;
    set_real_ip_from 141.101.64.0/18;
    set_real_ip_from 162.158.0.0/15;
    set_real_ip_from 172.64.0.0/13;
    set_real_ip_from 173.245.48.0/20;
    set_real_ip_from 188.114.96.0/20;
    set_real_ip_from 190.93.240.0/20;
    set_real_ip_from 197.234.240.0/22;
    set_real_ip_from 198.41.128.0/17;
    set_real_ip_from 2400:cb00::/32;
    set_real_ip_from 2405:8100::/32;
    set_real_ip_from 2405:b500::/32;
    set_real_ip_from 2606:4700::/32;
    set_real_ip_from 2803:f800::/32;
    set_real_ip_from 2c0f:f248::/32;
    set_real_ip_from 2a06:98c0::/29;
    real_ip_header CF-Connecting-IP;
    ```

    **Restart web server**  
    `service nginx restart`

5. Install mail server if needed  
`apt install postfix postgrey postsrsd spamassassin spamc`  
`groupadd spamd`  
`useradd -g spamd -s /bin/false -d /var/log/spamassassin spamd`  
`mkdir /var/log/spamassassin`  
`chown spamd:spamd /var/log/spamassassin`  
`service spamassassin restart`  

6. Install crontab  
`crontab /root/ishi/etc/templates/crontab.template`  


