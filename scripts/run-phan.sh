#!/bin/bash

# Some systems, like mediawiki-vagrant, don't have realpath
if ! which realpath > /dev/null; then
       realpath() {
               php -r "echo realpath('$*');"
       }
fi

if ! which php7.0 > /dev/null; then
	echo "PHP 7.0 not installed. For ubuntu/debian based systems see https://deb.sury.org/"
	echo "Suggested packages:"
	echo -e "\tapt-get install php7.0-cli php7.0-common php7.0-curl php7.0-intl php7.0-mysql php7.0-xmlrpc php7.0-dev php7.0-ldap php7.0-gd php7.0-pgsql php7.0-sqlite3 php7.0-tidy php7.0-phpdbg php7.0-bcmath php7.0-mbstring php7.0-xml php-imagick php-memcached php-redis php-ast"
	echo ""
	exit 1
fi

if [ ! -f "$PHAN" ]; then
	echo "The PHAN environment variable must point to a valid phan checkout"
	echo ""
	exit 1
fi

if [ ! -d "$(dirname $PHAN)/vendor" ]; then
	echo "phan is not configured. Run \`composer install\` in the phan directory"
	echo
	exit 1
fi

SED_PATTERN=$(echo $MW_PREFIX | sed 's/[\/&]/\\&/g')
OUTFILE="phan.out.$$"

php7.0 $PHAN \
	--project-root-directory $(realpath $(dirname $0)/..) \
	--output "php://stdout" \
	| php $(dirname $0)/postprocess-phan.php \
	> $OUTFILE

RETVAL=0
if [ "$(wc -l < $OUTFILE)" -ne 0 ]; then
	RETVAL=1
fi

cat $OUTFILE
rm $OUTFILE
exit $RETVAL

