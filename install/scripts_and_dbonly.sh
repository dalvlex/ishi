/*
 * DB only config & other commands
 * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

/*
//for DB only server you should run it without any preconfigured sites, or make these changes into the install first:
//- set GRANT rights for private IP in .functions_main
//- delete everything from ufw except ssh and mysql
//- disable all extra services
echo manual | sudo tee /etc/init/postfix.override
echo manual | sudo tee /etc/init/postgrey.override
echo manual | sudo tee /etc/init/spamassassin.override
echo manual | sudo tee /etc/init/php7.0-fpm.override
echo manual | sudo tee /etc/init/nginx.override
systemctl disable postfix
systemctl disable postgrey
systemctl disable spamassassin
systemctl disable php7.0-fpm
systemctl disable nginx
//set all process values to 1 in _template_phpfpm and in /etc/php/7.0/fpm/pool.d/ files
*/

/*
//various commands
#find size of /home/
du -h --max-depth=1 /home/ | sort -hr
#find and replace in all .php files
find /home/live_mycz/www/ -type f -name '*.php' -exec grep '/home/mycashco/public_html' {} + | awk -F ':' '{print $1;}' | xargs sed -i 's/\/home\/mycashco\/public_html/\/home\/live_mycashcow\/www/g'
#generate letsencrypt cert - might have to do it for www. domain separately
./letsencrypt-auto certonly --webroot --webroot-path /home/live_mycz/www/ --renew-by-default --email vlad.protopopescu@gmail.com --text --agree-tos -d mycz.com.au
#replace deprecated <? with <?php
find /home/live_mycz/public_html/ -type f -name '*.php' -exec grep '<?' {} + | grep -v '<?php' | awk -F ':' '{print $1;}' | xargs sed -i 's/<? /<?php/g'
*/
