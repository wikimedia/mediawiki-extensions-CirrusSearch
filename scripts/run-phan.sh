#!/bin/bash

# Some systems, like mediawiki-vagrant, don't have realpath
if ! which realpath > /dev/null; then
	realpath() {
		php -r "echo realpath('$*');"
	}
fi

MW_PREFIX=$(realpath "${MW_PREFIX:-$(dirname "$0")/../../..}")
CIRRUS="extensions/CirrusSearch/includes/ extensions/CirrusSearch/maintenance/ extensions/CirrusSearch/profiles"
DEPS="extensions/Elastica/ extensions/BetaFeatures includes vendor/ maintenance/ languages/ extensions/CirrusSearch/vendor"

set -e

if [ ! -f "$MW_PREFIX/includes/MediaWiki.php" ]; then
	echo "Could not find MediaWiki installation at $MW_PREFIX"
	echo "Please specify with MW_PREFIX environment variable"
	echo
	exit 1
fi

if ! which docker > /dev/null; then
	echo "Docker not installed. Press any key to install docker or Ctrl-C to quit"
	read -n 1
	sudo apt-get -y install docker.io 
	if [ -d /vagrant ]; then
		# May also be required elsewhere..but not comfortable just installing
		# cgroup-lite to random peoples machines.
		sudo apt-get -y install cgroup-lite
	fi
fi

if ! id -Gn | grep docker > /dev/null; then
	sudo adduser $(id -un) docker
	echo "User added to docker group. You need to log out and log back in to continue."
	echo
	exit 1
fi

if ! docker images | grep cloudflare/phan > /dev/null; then
	git clone https://github.com/cloudflare/docker-phan.git /tmp/docker-phan.$$
	pushd /tmp/docker-phan.$$
	./build
	popd
	# Once build we can safely remove this repo
	rm -rf /tmp/docker-phan.$$
fi

for i in $CIRRUS; do
  ALL_DIRS="$ALL_DIRS $MW_PREFIX/$i"
done
for i in $DEPS; do
  SKIP_ANALYSIS="$SKIP_ANALYSIS,/mnt/src/$i"
  ALL_DIRS="$ALL_DIRS $MW_PREFIX/$i"
done
# Strip leading comma
SKIP_ANALYSIS="${SKIP_ANALYSIS:1}"

PHAN_IN=$MW_PREFIX/phan.in.$$
SED_PATTERN=$(echo $MW_PREFIX | sed 's/[\/&]/\\&/g')
find $ALL_DIRS -iname '*.php' | sed "s/${SED_PATTERN}/\/mnt\/src/" > $PHAN_IN
echo "/mnt/src/extensions/CirrusSearch/scripts/phan.stubs.php" >> $PHAN_IN

docker run \
	--volume="$MW_PREFIX:/mnt/src" \
	--rm \
	--user "$(id -u):$(id -g)" \
	cloudflare/phan:latest \
	--file-list "/mnt/src/phan.in.$$" \
	--exclude-directory-list "$SKIP_ANALYSIS" \
	--output "php://stdout" \
	| sed "s/\/mnt\/src/$SED_PATTERN/" \
	| php $(dirname $0)/postprocess-phan.php \
	| sed "s/$SED_PATTERN//" \
	> /tmp/phan.out


RETVAL=0
if [ "$(wc -l < /tmp/phan.out)" -ne 0 ]; then
	RETVAL=1
fi

cat /tmp/phan.out
rm "$PHAN_IN" /tmp/phan.out
exit $RETVAL

