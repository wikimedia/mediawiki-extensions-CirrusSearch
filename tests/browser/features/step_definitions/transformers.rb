$start_time = Time.now

Transform(/%{epoch}/) do |param|
  param.gsub('%{epoch}', $start_time.to_i.to_s)
end
