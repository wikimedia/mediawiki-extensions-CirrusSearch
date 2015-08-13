export MEDIAWIKI_USER=admin
export MEDIAWIKI_PASSWORD=vagrant
export REUSE_BROWSER=true
export SCREENSHOT_FAILURES=true
export BROWSER=phantomjs
export HEADLESS=true

if [ -d /srv/mediawiki-vagrant ]; then
  export MEDIAWIKI_URL=http://cirrustest-${HOSTNAME}.wmflabs.org:8080/wiki/
  export MEDIAWIKI_API_URL=http://cirrustest-${HOSTNAME}.wmflabs.org:8080/w/api.php
  export MEDIAWIKI_COMMONS_API_URL=http://commons-${HOSTNAME}.wmflabs.org:8080/w/api.php
else
  export MEDIAWIKI_URL=http://cirrustest.wiki.local.wmftest.net:8080/wiki/
  export MEDIAWIKI_API_URL=http://cirrustest.wiki.local.wmftest.net:8080/w/api.php
  export MEDIAWIKI_COMMONS_API_URL=http://commons.wiki.local.wmftest.net:8080/w/api.php
fi
