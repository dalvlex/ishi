# ishi
Ishi site management

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

