#!/bin/bash

# Some systems, like mediawiki-vagrant, don't have realpath
if ! which realpath > /dev/null; then
       realpath() {
               php -r "echo realpath('$*');"
       }
fi

MW_PREFIX=$(realpath "${MW_PREFIX:-$(dirname "$0")/../../..}")

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

SED_PATTERN=$(echo $MW_PREFIX | sed 's/[\/&]/\\&/g')

docker run \
	--volume="$MW_PREFIX:/mnt/src" \
	--rm \
	--user "$(id -u):$(id -g)" \
	cloudflare/phan:edge \
	--project-root-directory /mnt/src/extensions/CirrusSearch \
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
rm /tmp/phan.out
exit $RETVAL

