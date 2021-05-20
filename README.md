# ishi
Ishi site management (for Ubuntu)

### System prerequisites
#### 1. Fix environment lang variables in ssh and disable password auth  
`sed -i 's/AcceptEnv LANG LC_\*/#AcceptEnv LANG LC_\*/g' /etc/ssh/sshd_config`  
`sed -i 's/PasswordAuthentication yes/PasswordAuthentication no/g' /etc/ssh/sshd_config`  
`sed -i 's/#PasswordAuthentication no/PasswordAuthentication no/g' /etc/ssh/sshd_config`  
`service ssh restart`  
**relogin to SSH**

#### 2. Update the system  
`apt update`  
`apt upgrade`

### Install ishi
#### 1. Clone git repo and configure
`git clone https://github.com/dalvlex/ishi /root/ishi`  
Ishi is meant to be used as root and installed under /root/ishi, probably it would work as another user with sudo, but this is not tested!  
**Don't forget to configure ishi in etc/settings**  

#### 2. Install crontab
`crontab /root/ishi/etc/templates/crontab.template`  

### Enable swap on low resource servers (*optional*)
`fallocate -l 2G /swapfile;`  
`chmod 600 /swapfile;`  
`mkswap /swapfile;`  
`swapon /swapfile;`  
`swapon --show;`  
`echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab;`  
`sysctl vm.swappiness=10;`  
`echo 'vm.swappiness=10' | sudo tee -a /etc/sysctl.conf;`  
`sysctl vm.vfs_cache_pressure=50;`  
`echo 'vm.vfs_cache_pressure=50' | sudo tee -a /etc/sysctl.conf;`  

### Configure Amazon S3 backup storage (*optional*)
1. Install **s3fs**  
`apt install s3fs`  
2. Create a new bucket *ishi-backups-bucket* on Amazon S3  
3. Add a new user *ishi-backups-user* through Amazon IAM with programatic access  
4. Create and attach a policy *ishi-backups-policy* to the above user *ishi-backups-user* with list, read, write permissions only to the above bucket *ishi-backups-bucket*, and take note of the user's access_key and secret.  
5. Create s3fs password file  
`echo "access_key:secret" > /etc/passwd-s3fs; chmod 600 /etc/passwd-s3fs`  
6. Insert in /etc/fstab  
`ishi-backups-bucket /root/ishi/var/backups fuse.s3fs _netdev,multireq_max=2,retries=5,nonempty,url=https://s3-eu-central-1.amazonaws.com 0 0`  
Be sure to change *eu-central-1* to whatever you Amazon S3 zone is, because s3fs loses proper auth between redirects and it will not work otherwise.  
7. Mount the bucket  
`mount /root/ishi/var/backups`  
The mount will be available after reboots.

If you want to just mount it manually without adding it to /etc/fstab  
`s3fs ishi-backups-bucket /root/ishi/var/backups -o url="https://s3-eu-central-1.amazonaws.com"`  
Be sure to change *eu-central-1* to whatever you Amazon S3 zone is, because s3fs loses proper auth between redirects and it will not work otherwise.  
Use `-o dbglevel=info -f -o curldbg` for debugging.  


### Install software
#### 1. Add general packages
`apt install mysql-server apache2-utils php-fpm php-cli php-mysql php-curl git sqlite3 fail2ban letsencrypt`  
and take note of mySQL password if auth isn't made with PAM  

#### 2. Configure webserver
##### **Either install apache2**  
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
    Check to see if the IPs match the ones on this page https://www.cloudflare.com/ips/  
    ```
    echo 'RemoteIPHeader CF-Connecting-IP  
    RemoteIPTrustedProxy 103.21.244.0/22 103.22.200.0/22 103.31.4.0/22 104.16.0.0/12 108.162.192.0/18 131.0.72.0/22 141.101.64.0/18 162.158.0.0/15 172.64.0.0/13 173.245.48.0/20 188.114.96.0/20 190.93.240.0/20 197.234.240.0/22 198.41.128.0/17 2400:cb00::/32 2405:8100::/32 2405:b500::/32 2606:4700::/32 2803:f800::/32 2c0f:f248::/32 2a06:98c0::/29' > /etc/apache2/conf-available/remoteip.conf;  
    a2enmod remoteip;  
    a2enconf remoteip;  

    # also edit /etc/apache2/apache2.conf and for LogFormat directives replace %h with %a everywhere!
    ```

    Restart web server  
    `service apache2 restart`

##### **Or install nginx**  
    `apt install nginx nginx-extras`
    
    *Disable default page*  
    `rm -rf /etc/nginx/sites-enabled/default`
    
    *Configure password for unlocked sites*  
    `htpasswd -c /etc/nginx/.htpasswd username_here`  

    *Log real IP address of web users coming through CloudFlare for fail2ban use*  
    Check to see if the IPs match the ones on this page https://www.cloudflare.com/ips/  
    ```
    # insert the following into /etc/nginx.conf http {} context
    set_real_ip_from 103.21.244.0/22;
    set_real_ip_from 103.22.200.0/22;
    set_real_ip_from 103.31.4.0/22;
    set_real_ip_from 104.16.0.0/13;
    set_real_ip_from 104.24.0.0/14;
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

    Restart web server  
    `service nginx restart`

#### 3. Install mail server (*optional*)  
```
# install services
apt install postfix postgrey postsrsd spamassassin spamc
groupadd spamd
useradd -g spamd -s /bin/false -d /var/log/spamassassin spamd
mkdir /var/log/spamassassin
chown spamd:spamd /var/log/spamassassin
echo '-d 127.0.0.1' > /etc/spamassassin/spamc.conf
```

```
# change in /etc/postfix/master.cf first line to output mails through spamassassin
smtp      inet  n       -       y       -       -       smtpd -o content_filter=spamassassin

# append to /etc/postfix/master.cf
spamassassin unix -     n       n       -       -       pipe
                user=spamd argv=/usr/bin/spamc -f -e
                /usr/sbin/sendmail -oi -f ${sender} ${recipient}
```

```
# replace /etc/postix/main.cf with the following, and replace IPV4_address, IPV6_address, DOMAIN_main, DOMAIN_secondary, SENDGRID_apikey with the corresponding values
# IPV4_address = public IPv4 server address;
# IPV6_address = public IPv6 server address;
# DOMAIN_main = the main domain of the server, you need at least one;
# DOMAIN_secondary = any number of additional domains that the server will handle mail for - these are optional;
# SENDGRID_apikey = this will be generated and obtained from an account on https://sendgrid.com
# See /usr/share/postfix/main.cf.dist for a commented, more complete version

smtpd_banner = $myhostname ESMTP $mail_name (Ubuntu)
biff = no

# appending .domain is the MUA's job.
append_dot_mydomain = no

# Uncomment the next line to generate "delayed mail" warnings
#delay_warning_time = 4h

readme_directory = no

# See /usr/share/doc/postfix/TLS_README.gz in the postfix-doc package for
# information on enabling SSL in the smtp client.
# TLS parameters
smtpd_tls_cert_file=/etc/ssl/certs/ssl-cert-snakeoil.pem
smtpd_tls_key_file=/etc/ssl/private/ssl-cert-snakeoil.key
smtpd_use_tls=yes
smtpd_tls_session_cache_database = btree:${data_directory}/smtpd_scache
smtp_tls_session_cache_database = btree:${data_directory}/smtp_scache

myhostname = DOMAIN_main
mydestination = localhost
mynetworks = 127.0.0.0/8 [::ffff:127.0.0.0]/104 [::1]/128
mailbox_size_limit = 0
recipient_delimiter = +
inet_interfaces = IPV4_address IPV6_address
inet_protocols = all

smtpd_relay_restrictions = permit_mynetworks, permit_sasl_authenticated, reject_unauth_destination, check_policy_service inet:127.0.0.1:10023

smtp_sasl_auth_enable = yes
smtp_sasl_password_maps = static:apikey:SENDGRID_apikey
smtp_sasl_security_options = noanonymous
smtp_tls_security_level = encrypt
header_size_limit = 4096000
relayhost = [smtp.sendgrid.com]:587

virtual_alias_domains = DOMAIN_main, DOMAIN_secondary, DOMAIN_secondary, DOMAIN_secondary
virtual_alias_maps = hash:/etc/postfix/virtual_alias_domains
alias_maps = 

sender_canonical_maps = tcp:localhost:10001
sender_canonical_classes = envelope_sender
recipient_canonical_maps = tcp:localhost:10002
recipient_canonical_classes= envelope_recipient,header_recipient
```

```
# create virtual alias file for mail forwarding
touch /etc/postfix/virtual_alias_domains;
postmap /etc/postfix/virtual_alias_domains;
```

```
# replace in /etc/default/postgrey
POSTGREY_OPTS="--inet=10023 --delay 2 --max-age=20"
```

```
# replace in /etc/default/postsrsd with DOMAIN_main, see above
SRS_DOMAIN=DOMAIN_main
```

```
# restart services
service spamassassin restart
service postgrey restart
service postsrsd restart
service postfix restart
```


