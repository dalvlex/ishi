# ishi
Ishi site management

### Install ishi
1. git clone https://github.com/dalvlex/ishi /root/ishi

### Amazon S3 backup storage
1. Install **s3fs** from [](https://github.com/s3fs-fuse/s3fs-fuse)  
2. Create a new bucket *ishi-backups-bucket* on Amazon S3  
3. Add a new user *ishi-backups-user* through Amazon IAM with programatic access  
4. Create and attach a policy *ishi-backups-policy* to the above user *ishi-backups-user* with list, read, write permissions only to the above bucket *ishi-backups-bucket*, and take note of the user's access_key and secret.  
5. Create s3fs password file  
`echo "*access_key*:*secret*" > /etc/passwd-s3fs; chmod 600 /etc/passwd-s3fs`  
7. Insert in /etc/fstab  
`ishi-backups-bucket /root/ishi/var/backups fuse.s3fs _netdev,retries=5,url=https://s3-eu-central-1.amazonaws.com 0 0`  
Be sure to change *eu-central-1 to whatever you Amazon S3 zone is, because s3fs loses proper auth between redirects and it will not work otherwise.  
8. Mount the bucket  
mount /root/ishi/var/backups  

If you want to just mount it manually without adding it to /etc/fstab  
`s3fs ishi-backups-bucket /root/ishi/var/backups` -o url="https://s3-eu-central-1.amazonaws.com"  
Be sure to change *eu-central-1 to whatever you Amazon S3 zone is, because s3fs loses proper auth between redirects and it will not work otherwise.  
Use `-o dbglevel=info -f -o curldbg` for debugging  

### Generic prerequisites
sed -i 's/AcceptEnv LANG LC_\*/#AcceptEnv LANG LC_\*/g' /etc/ssh/sshd_config  
sed -i 's/PasswordAuthentication yes/PasswordAuthentication no/g' /etc/ssh/sshd_config  
service ssh restart

apt update

apt install debconf-utils mysql-server #take note of mySQL password if auth isn't made with PAM  
apt install php-fpm php-cli php-mysql  
apt install git fail2ban letsencrypt

apt install apache2 apache2-suexec-custom  
a2enmod suexec proxy_fcgi actions alias rewrite headers ssl  
service apache2 restart

apt install nginx

apt install postfix postgrey postsrsd spamassassin spamc  
groupadd spamd  
useradd -g spamd -s /bin/false -d /var/log/spamassassin spamd  
mkdir /var/log/spamassassin  
chown spamd:spamd /var/log/spamassassin  
service spamassassin restart

