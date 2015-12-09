Given(/wait ([0-9]+) seconds/) do |seconds|
  sleep(Integer(seconds))
end
Then(/the page text contains (.*)/) do |text|
  expect(browser.html).to include(text)
end
Then(/the page text does not contain (.*)/) do |text|
  expect(browser.html).not_to include(text)
end
