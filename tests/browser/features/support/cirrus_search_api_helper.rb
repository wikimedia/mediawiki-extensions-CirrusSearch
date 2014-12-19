# Common code cirrus' test use when dealing with api.
module CirrusSearchApiHelper
  def edit_page(title, text, add)
    text = File.read("articles/" + text[1..-1]) if text.start_with?("@")
    fetched_text = get_page_text(title)
    # Note that the space keeps words from smashing together
    text = fetched_text + " " + text if add
    return if fetched_text.strip == text.strip
    api.log_in(ENV["MEDIAWIKI_USER"], ENV["MEDIAWIKI_PASSWORD"]) unless api.logged_in?
    result = api.create_page(title, text)
    expect(result.status).to eq 200
    expect(result.warnings?).to eq false
  end

  # Gets page text using the api.
  def get_page_text(title)
    fetched_text = api.get_wikitext(title)
    return "" if fetched_text.status == 404
    fetched_text.status.should eq 200
    fetched_text.body.strip.force_encoding("utf-8")
  end

  # Uploads a file if the file's MD5 doesn't match what is already uploaded.
  def upload_file(title, contents, description)
    contents = "articles/" + contents
    md5 = "md5: #{Digest::MD5.hexdigest(File.read(contents))}"
    visit(ArticlePage, using_params: { page_name: title }) do |page|
      page.last_file_comment_contains(md5)
      step "I am logged in" unless page.upload?
      page.upload
    end
    on(UploadFilePage).upload(contents, description, md5)
    on(UploadFilePage).error_element.should_not exist
  end
end
