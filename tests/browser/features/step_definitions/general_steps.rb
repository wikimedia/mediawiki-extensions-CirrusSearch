Given(/wait ([0-9]+) seconds/) do |seconds|
  sleep(Integer(seconds))
end
