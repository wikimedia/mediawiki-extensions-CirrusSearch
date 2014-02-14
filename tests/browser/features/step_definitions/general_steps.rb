Given(/^I am at a random page.*$/) do
  visit RandomPage
end

Given(/wait ([0-9]+) seconds/) do |seconds|
  sleep(Integer(seconds))
end
