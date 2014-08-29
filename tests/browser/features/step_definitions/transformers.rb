start_time = Time.now

Transform(/%{epoch}/) do |param|
  param.gsub("%{epoch}", start_time.to_i.to_s)
end

# Allow sending strings with trailing spaces
Transform(/%{exact:[^}]*}/) do |param|
  param[8..-2]
end
