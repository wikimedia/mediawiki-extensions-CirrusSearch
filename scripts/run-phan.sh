#!/bin/bash

MW_PREFIX="/vagrant/mediawiki"
PHAN_DIR="/vagrant/srv/phan"
CIRRUS="extensions/CirrusSearch/includes/ extensions/CirrusSearch/maintenance/ extensions/CirrusSearch/profiles"
DEPS="extensions/Elastica/ extensions/BetaFeatures includes vendor/ maintenance/ languages/ extensions/CirrusSearch/vendor"
PACKAGES="php7.0-cli php7.0-bz2 php7.0-dev php7.0-json php7.0-mbstring php7.0-curl php7.0-sqlite3 php7.0-xml php7.0-zip php7.0-mysql \
          php5.6-cli php5.6-bz2 php5.6-dev php5.6-json php5.6-mbstring php5.6-curl php5.6-sqlite3 php5.6-xml php5.6-zip php5.6-mysql \
          php-redis php-igbinary"

set -e

if [ ! -d /vagrant ]; then
	echo "This script must be run inside a mediawiki vagrant box"
	exit 1
fi

if [ ! -f /etc/apt/sources.list.d/ondrej-php-trusty.list ]; then
	echo "Adding ppa:ondrej/php which contains php7"
	# apt has an issue with onrej's name if utf-8 isn't used
	export LC_ALL=en_US.UTF-8
	export LANG=en_US.UTF-8
	sudo add-apt-repository -y ppa:ondrej/php
fi

for i in $PACKAGES; do
	PACKAGE_MATCHER="$PACKAGE_MATCHER|^$i\$"
done
PACKAGE_MATCHER="${PACKAGE_MATCHER:1}"

if ! dpkg --get-selections | cut -d '	' -f 1 | egrep "$PACKAGE_MATCHER" > /dev/null; then
	echo "Didn't find all required packages, installing..."
	sudo apt-get update
	sudo apt-get install -y $PACKAGES
fi

if ! which php5 > /dev/null; then
	# Sadly the php7 packages also require installing a new version of php5, and
	# it doesn't include a BC symlink from php5 -> php5.6. So lets just make one
	sudo ln -s "$(which php5.6)" /usr/local/bin/php5
fi

if ! dpkg -s php-ast > /dev/null 2>&1; then
	echo "Installing php-ast extension"
	sudo apt-get install -y php-ast
fi

if [ ! -f /etc/php/7.0/cli/conf.d/20-ast.ini ]; then
	# can't use phpenmod, it will also install a symlink for php5 which doesn't work
	echo "Enabling usage of php-ast in php7"
	sudo ln -s /etc/php/7.0/mods-available/ast.ini /etc/php/7.0/cli/conf.d/20-ast.ini
fi

if [ ! -d "$PHAN_DIR" ]; then
	echo "Didn't find phan, cloning"
	git clone https://github.com/etsy/phan.git "$PHAN_DIR"
fi

if [ ! -f "$PHAN_DIR/vendor/autoload.php" ]; then
	echo "Installing phan dependencies"
	pushd "$PHAN_DIR"
	php7.0 $(which composer) install
	popd
	if [ ! -f "$PHAN_DIR/vendor/autoload.php" ]; then
		echo "Failed initializing composer for phan"
		exit 1
	fi
fi

echo "Collecting files to run phan against..."
for i in $CIRRUS; do
  ALL_DIRS="$ALL_DIRS $MW_PREFIX/$i"
done
for i in $DEPS; do
  SKIP_ANALYSIS="$SKIP_ANALYSIS,$MW_PREFIX/$i"
  ALL_DIRS="$ALL_DIRS $MW_PREFIX/$i"
done
# Strip leading comma
SKIP_ANALYSIS="${SKIP_ANALYSIS:1}"

# Build list of files
echo "Sourcing files from: $ALL_DIRS"
PHAN_IN=/tmp/phan.in.$$
find $ALL_DIRS -iname '*.php' > "$PHAN_IN"
echo "/vagrant/mediawiki/extensions/CirrusSearch/scripts/phan.stubs.php" >> "$PHAN_IN"
# Run phan
echo "Running phan..."
echo "Parsing but not analyzing: $SKIP_ANALYSIS"
echo "Number of files to handle: " $(wc -l < "$PHAN_IN")
php7.0 "$PHAN_DIR/phan" -f "$PHAN_IN" -3 "$SKIP_ANALYSIS" -o /tmp/phan.out -p
rm "$PHAN_IN"
echo
echo "Done. Results are in /tmp/phan.out"
