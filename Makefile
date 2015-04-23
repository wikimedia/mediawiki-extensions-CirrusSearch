MW_INSTALL_PATH ?= ../..

# mediawiki-vagrant defaults to hhvm rather than php5, which is mostly
# fine but crazy slow for commands like phplint
PHP ?= `command -v php5 || command -v php`

lint: phplint grunt rubocop

phplint:
	@find ./ -type f -iname '*.php' -print0 | xargs -0 -P 12 -L 1 ${PHP} -l | \
		(grep -v '^No syntax errors detected in' || true)

rubocop: bundlecheck
	@cd tests/browser && bundle exec rubocop

nodecheck:
	@which npm > /dev/null && npm install \
		|| (echo "You need to install Node.JS and npm! See http://nodejs.org/" && \
		    echo "Or just try `apt-get install nodejs nodejs-legacy npm`" && false)

grunt: nodecheck
	@npm test

phpunit:
	@${PHP} ${MW_INSTALL_PATH}/tests/phpunit/phpunit.php ${MW_INSTALL_PATH}/extensions/CirrusSearch/tests/unit/

bundlecheck:
	@which bundle > /dev/null && cd tests/browser && bundle install \
		|| ( echo "You need to install bundler! See http://bundler.io/" && \
		     echo "Or just try `apt-get install bundler`" && false)

browsertest: bundlecheck
	@cd tests/browser && . ./selenium_exports_for_vagrant.sh && bundle exec parallel_cucumber features/

installhooks:
	ln -s ../../scripts/pre-commit .git/hooks/pre-commit
