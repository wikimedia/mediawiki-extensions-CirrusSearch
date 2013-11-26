Given(/^I am logged in$/) do
  visit(LoginPage).login_with(ENV["MEDIAWIKI_USER"], ENV["MEDIAWIKI_PASSWORD"])
end

Given(/^I am at a random page.*$/) do
  visit RandomPage
end

Given(/wait ([0-9]+) seconds/) do |seconds|
  sleep(Integer(seconds))
end
