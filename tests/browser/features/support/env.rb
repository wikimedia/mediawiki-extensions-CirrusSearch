require "mediawiki_selenium"
require "fileutils"

After do |scenario|
  if scenario.failed? && (ENV["SCREENSHOT_FAILURES"] == "true")
    FileUtils.mkdir_p "screenshots"
    name = test_name(scenario).gsub(/ /, '_')
    path = "screenshots/#{name}.png"
    $browser.screenshot.save path
    embed path, "image/png"
  end
end
