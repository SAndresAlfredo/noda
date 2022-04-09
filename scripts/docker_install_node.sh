#!/bin/bash
# setup node on ubuntu server 21.04, 20.04, 18.04
# one liner: curl -s https://raw.githubusercontent.com/phpcoinn/node/main/scripts/install_node.sh | bash

echo "PHPCoin node Installation"
echo "==================================================================================================="
echo "PHPCoin: define db user and pass"
echo "==================================================================================================="
export DB_NAME=phpcoin
export DB_USER=phpcoin
export DB_PASS=phpcoin

echo "PHPCoin: update system"
echo "==================================================================================================="
apt update
echo "install php with apache server"
apt install apache2 php libapache2-mod-php php-mysql php-gmp php-bcmath php-curl unzip git wget curl -y
apt install mysql-server -y
usermod -d /var/lib/mysql/ mysql
service mysql start

echo "PHPCoin: create database and set use"
echo "==================================================================================================="
mysql -e "create database $DB_NAME;"
mysql -e "create user '$DB_USER'@'localhost' identified by '$DB_PASS';"
mysql -e "grant all privileges on $DB_NAME.* to '$DB_USER'@'localhost';"

echo "PHPCoin: download node"
echo "==================================================================================================="
mkdir /var/www/phpcoin
cd /var/www/phpcoin
git clone https://github.com/phpcoinn/node .

echo "PHPCoin: Configure apache"
echo "==================================================================================================="
cat << EOF > /etc/apache2/sites-available/phpcoin.conf
<VirtualHost *:80>
        ServerAdmin webmaster@localhost
        DocumentRoot /var/www/phpcoin/web
        ErrorLog ${APACHE_LOG_DIR}/error.log
        CustomLog ${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
EOF
a2dissite 000-default
a2ensite phpcoin
service apache2 restart

echo "PHPCoin: setup config file"
echo "==================================================================================================="
cd /var/www/phpcoin
cp config/config-sample.inc.php config/config.inc.php
sed -i "s/ENTER-DB-NAME/$DB_NAME/g" config/config.inc.php
sed -i "s/ENTER-DB-USER/$DB_USER/g" config/config.inc.php
sed -i "s/ENTER-DB-PASS/$DB_PASS/g" config/config.inc.php

echo "PHPCoin: configure node"
echo "==================================================================================================="
mkdir tmp
mkdir web/apps
chown -R www-data:www-data tmp
chown -R www-data:www-data web/apps

export IP=$(curl -s http://whatismyip.akamai.com/)
echo "PHPCoin: open start page"
echo "==================================================================================================="
curl "http://127.0.0.1" > /dev/null 2>&1

sleep 5
mysql -e "update config set val='http://$IP' where cfg ='hostname'" $DB_NAME

echo "PHPCoin: import blockchain"
echo "==================================================================================================="
cd /var/www/phpcoin/tmp
wget -q https://phpcoin.net/download/blockchain.sql.zip
unzip blockchain.sql.zip
cd /var/www/phpcoin
php cli/util.php importdb tmp/blockchain.sql

echo "PHPCoin: synchronize apps"
echo "==================================================================================================="
php cli/util.php download-apps

rm -rf /var/www/phpcoin/tmp/sync-lock

echo "==================================================================================================="
echo "PHPCoin: Install finished"
echo "PHPCoin: Open your node at http://$IP"
