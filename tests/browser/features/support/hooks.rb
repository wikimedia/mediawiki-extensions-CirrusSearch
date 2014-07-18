# encoding: utf-8

Before('@setup_main, @filters, @prefix, @bad_syntax, @wildcard, @exact_quotes') do
  if !$setup_main
    steps %Q{
      Given a page named Template:Template Test exists with contents pickles [[Category:TemplateTagged]]
      And a page named Catapult/adsf exists with contents catapult subpage [[Catapult]]
      And a page named Links To Catapult exists with contents [[Catapult]]
      And a page named Catapult exists with contents ♙ asdf [[Category:Weaponry]]
      And a page named Amazing Catapult exists with contents test [[Catapult]] [[Category:Weaponry]]
      And a page named Two Words exists with contents ffnonesenseword catapult {{Template_Test}} anotherword [[Category:TwoWords]] [[Category:Categorywith Twowords]]
      And a page named AlphaBeta exists with contents [[Category:Alpha]] [[Category:Beta]]
      And a page named IHaveATwoWordCategory exists with contents [[Category:CategoryWith ASpace]]
      And a page named वाङ्मय exists
      And a page named वाङ्‍मय exists
      And a page named वाङ‍्मय exists
      And a page named वाङ्‌मय exists
    }
    $setup_main = true
  end
end

Before('@setup_main, @prefix, @bad_syntax') do
  if !$setup_main2
    steps %Q{
      Given a page named Rdir exists with contents #REDIRECT [[Two Words]]
      And a file named File:Savepage-greyed.png exists with contents Savepage-greyed.png and description Screenshot, for test purposes, associated with https://bugzilla.wikimedia.org/show_bug.cgi?id=52908 .
      And a page named IHaveAVideo exists with contents [[File:How to Edit Article in Arabic Wikipedia.ogg|thumb|267x267px]]
      And a page named IHaveASound exists with contents [[File:Serenade for Strings -mvt-1- Elgar.ogg]]
    }
    $setup_main2 = true
  end
end

Before('@setup_main, @prefix, @go, @bad_syntax') do
  if !$africa
    steps %Q{
      Given a page named África exists with contents for testing
    }
    $africa = true
  end
end

Before('@prefix') do
  if !$prefix
    steps %Q{
      Given a page named L'Oréal exists
    }
    $prefix = true
  end
end

Before("@headings") do
  if !$headings
    steps %Q{
      Given a page named HasHeadings exists with contents @has_headings.txt
      And a page named HasReferencesInText exists with contents References [[Category:HeadingsTest]]
      And a page named HasHeadingsWithHtmlComment exists with contents @has_headings_with_html_comment.txt
      And a page named HasHeadingsWithReference exists with contents @has_headings_with_reference.txt
    }
    $headings = true
  end
end

Before("@javascript_injection") do
  if !$javascript_injection
    steps %Q{
      Given a page named Javascript Direct Inclusion exists with contents @javascript.txt
      Given a page named Javascript Pre Tag Inclusion exists with contents @javascript_in_pre.txt
    }
    $javascript_injection = true
  end
end

Before("@setup_namespaces") do
  if !$setup_namespaces
    steps %Q{
      Given a page named Talk:Two Words exists with contents why is this page about catapults?
      And a page named Help:Smoosh exists with contents test
      And a page named File:Nothingasdf exists with contents nothingasdf
    }
    $setup_namespaces = true
  end
end

Before("@suggestions") do
  if !$suggestions
    steps %Q{
      Given a page named Popular Culture exists with contents popular culture
      And a page named Nobel Prize exists with contents nobel prize
      And a page named Noble Gasses exists with contents noble gasses
      And a page named Noble Somethingelse exists with contents noble somethingelse
      And a page named Noble Somethingelse2 exists with contents noble somethingelse
      And a page named Noble Somethingelse3 exists with contents noble somethingelse
      And a page named Noble Somethingelse4 exists with contents noble somethingelse
      And a page named Noble Somethingelse5 exists with contents noble somethingelse
      And a page named Noble Somethingelse6 exists with contents noble somethingelse
      And a page named Noble Somethingelse7 exists with contents noble somethingelse
      And a page named Template:Noble Pipe 1 exists with contents pipes are so noble
      And a page named Template:Noble Pipe 2 exists with contents pipes are so noble
      And a page named Template:Noble Pipe 3 exists with contents pipes are so noble
      And a page named Template:Noble Pipe 4 exists with contents pipes are so noble
      And a page named Template:Noble Pipe 5 exists with contents pipes are so noble
      And a page named Rrr Word 1 exists with contents #REDIRECT [[Popular Culture]]
      And a page named Rrr Word 2 exists with contents #REDIRECT [[Popular Culture]]
      And a page named Rrr Word 3 exists with contents #REDIRECT [[Noble Somethingelse3]]
      And a page named Rrr Word 4 exists with contents #REDIRECT [[Noble Somethingelse4]]
      And a page named Rrr Word 5 exists with contents #REDIRECT [[Noble Somethingelse5]]
      And a page named Nobel Gassez exists with contents #REDIRECT [[Noble Gasses]]
    }
    $suggestions = true
  end
end

Before("@suggestions", "@stemming") do
  if !$suggestions_stemming
    steps %Q{
      Given a page named Stemming Multiwords exists
      And a page named Stemming Possessive’s exists
      And a page named Stemmingsinglewords exists
      And a page named Stemmingsinglewords Other 1 exists
      And a page named Stemmingsinglewords Other 2 exists
      And a page named Stemmingsinglewords Other 3 exists
      And a page named Stemmingsinglewords Other 4 exists
      And a page named Stemmingsinglewords Other 5 exists
      And a page named Stemmingsinglewords Other 6 exists
      And a page named Stemmingsinglewords Other 7 exists
      And a page named Stemmingsinglewords Other 8 exists
      And a page named Stemmingsinglewords Other 9 exists
      And a page named Stemmingsinglewords Other 10 exists
      And a page named Stemmingsinglewords Other 11 exists
      And a page named Stemmingsinglewords Other 12 exists
    }
    $suggestions_stemming = true
  end
end

Before("@highlighting") do
  if !$highlighting
    steps %Q{
      Given a page named Rashidun Caliphate exists with contents @rashidun_caliphate.txt
      And a page named Crazy Rdir exists with contents #REDIRECT [[Two Words]]
      And a page named Insane Rdir exists with contents #REDIRECT [[Two Words]]
      And a page named The Once and Future King exists
      And a page named User_talk:Test exists
      And a page named Rose Trellis Faberge Egg exists with contents @rose_trellis_faberge_egg.txt
    }
  end
  $highlighting = true
end

Before("@highlighting", "@references") do
  if !$highlighting_references
    steps %Q{
      Given a page named References Highlight Test exists with contents @references_highlight_test.txt
    }
  end
  $highlighting_references = true
end

Before("@more_like_this") do
  if !$setup_more_like_this
    # The MoreLikeMe term must appear in "a bunch" of pages for it to be used in morelike: searches
    steps %Q{
      Given a page named More Like Me 1 exists with contents morelikesetone morelikesetone
      And a page named More Like Me 2 exists with contents morelikesetone morelikesetone morelikesetone morelikesetone
      And a page named More Like Me 3 exists with contents morelikesetone morelikesetone morelikesetone morelikesetone
      And a page named More Like Me 4 exists with contents morelikesetone morelikesetone morelikesetone morelikesetone
      And a page named More Like Me 5 exists with contents morelikesetone morelikesetone morelikesetone morelikesetone
      And a page named More Like Me Set 2 Page 1 exists with contents morelikesettwo morelikesettwo morelikesettwo
      And a page named More Like Me Set 2 Page 2 exists with contents morelikesettwo morelikesettwo morelikesettwo
      And a page named More Like Me Set 2 Page 3 exists with contents morelikesettwo morelikesettwo morelikesettwo
      And a page named More Like Me Set 2 Page 4 exists with contents morelikesettwo morelikesettwo morelikesettwo
      And a page named More Like Me Set 2 Page 5 exists with contents morelikesettwo morelikesettwo morelikesettwo
      And a page named More Like Me Set 3 Page 1 exists with contents morelikesetthree morelikesetthree
      And a page named More Like Me Set 3 Page 2 exists with contents morelikesetthree morelikesetthree
      And a page named More Like Me Set 3 Page 3 exists with contents morelikesetthree morelikesetthree
      And a page named More Like Me Set 3 Page 4 exists with contents morelikesetthree morelikesetthree
      And a page named More Like Me Set 3 Page 5 exists with contents morelikesetthree morelikesetthree
    }
  end
  $setup_more_like_this = true
end

Before("@setup_phrase_rescore") do
  if !$setup_phrase_rescore
    steps %Q{
      Given a page named Rescore Test Words exists
      And a page named Test Words Rescore Rescore Test Words exists
      And a page named Rescore Test TextContent exists with contents Chaff
      And a page named Rescore Test HasTextContent exists with contents Rescore Test TextContent
    }
  end
  $setup_phrase_rescore = true
end

Before("@exact_quotes") do
  if !$exact_quotes
    steps %Q{
      Given a page named Contains A Stop Word exists
      And a page named Doesn't Actually Contain Stop Words exists
      And a page named Pick* exists
    }
  end
  $exact_quotes = true
end

Before("@programmer_friendly") do
  if !$programmer_friendly
    steps %Q{
      Given a page named $wgNamespaceAliases exists
      And a page named PFSC exists with contents snake_case
      And a page named PascalCase exists
      And a page named NumericCase7 exists
      And a page named this.getInitial exists
    }
    $programmer_friendly = true
  end
end

Before("@stemmer") do
  if !$stemmer
    steps %Q{
      Given a page named StemmerTest Aliases exists
      And a page named StemmerTest Alias exists
      And a page named StemmerTest Used exists
    }
  end
  $stemmer = true
end

Before("@prefix_filter") do
  if !$prefix_filter
    steps %Q{
      Given a page named Prefix Test exists
      And a page named Prefix Test Redirect exists with contents #REDIRECT [[Prefix Test]]
      And a page named Foo Prefix Test exists with contents [[Prefix Test]]
      And a page named Prefix Test/AAAA exists with contents [[Prefix Test]]
      And a page named Prefix Test AAAA exists with contents [[Prefix Test]]
      And a page named Talk:Prefix Test exists with contents [[Prefix Test]]
      And a page named User_talk:Prefix Test exists with contents [[Prefix Text]]
    }
  end
  $prefix_filter = true
end

Before("@prefer_recent") do
  if !$prefer_recent
    # These are updated per process instead of per test because of the 20 second wait
    # Note that the scores have to be close together because 20 seconds doesn't mean a whole lot
    steps %Q{
      Given a page named PreferRecent First exists with contents %{epoch}
      And a page named PreferRecent Second Second exists with contents %{epoch}
      And wait 20 seconds
      And a page named PreferRecent Third exists with contents %{epoch}
    }
  end
  $prefer_recent = true
end

Before("@hastemplate") do
  if !$hastemplate
    steps %Q{
      Given a page named MainNamespaceTemplate exists
      And a page named HasMainNSTemplate exists with contents {{:MainNamespaceTemplate}}
      And a page named Talk:TalkTemplate exists
      And a page named HasTTemplate exists with contents {{Talk:TalkTemplate}}
    }
  end
  $hastemplate = true
end

Before("@boost_template") do
  if !$boost_template
    steps %Q{
      Given a page named Template:BoostTemplateHigh exists with contents BoostTemplateTest
      And a page named Template:BoostTemplateLow exists with contents BoostTemplateTest
      And a page named NoTemplates BoostTemplateTest exists with contents nothing important
      And a page named HighTemplate exists with contents {{BoostTemplateHigh}}
      And a page named LowTemplate exists with contents {{BoostTemplateLow}}
    }
  end
  $boost_template = true
end

Before("@go") do
  if !$go
    steps %Q{
      Given a page named MixedCapsAndLowerCase exists
    }
  end
  $go = true
end

Before("@go", "@options") do
  if !$go_options
    steps %Q{
      Given a page named son Nearmatchflattentest exists
      And a page named Son Nearmatchflattentest exists
      And a page named SON Nearmatchflattentest exists
      And a page named soñ Nearmatchflattentest exists
      And a page named Son Nolower Nearmatchflattentest exists
      And a page named SON Nolower Nearmatchflattentest exists
      And a page named Soñ Nolower Nearmatchflattentest exists
      And a page named Son Titlecase Nearmatchflattentest exists
      And a page named Soñ Titlecase Nearmatchflattentest exists
      And a page named Soñ Onlyaccent Nearmatchflattentest exists
      And a page named Soñ Twoaccents Nearmatchflattentest exists
      And a page named Són Twoaccents Nearmatchflattentest exists
      And a page named son Double Nearmatchflattentest exists
      And a page named SON Double Nearmatchflattentest exists
      And a page named Bach Nearmatchflattentest exists with contents #REDIRECT [[Johann Sebastian Bach Nearmatchflattentest]]
      And a page named Bạch Nearmatchflattentest exists with contents Notice the dot under the a.
      And a page named Johann Sebastian Bach Nearmatchflattentest exists
      And a page named KOAN Nearmatchflattentest exists
      And a page named Kōan Nearmatchflattentest exists
      And a page named Koan Nearmatchflattentest exists with contents #REDIRECT [[Kōan Nearmatchflattentest]]
      And a page named Soñ Redirect Nearmatchflattentest exists
      And a page named Són Redirect Nearmatchflattentest exists
      And a page named Son Redirect Nearmatchflattentest exists with contents #REDIRECT [[Soñ Redirect Nearmatchflattentest]]
      And a page named Són Redirectnotbetter Nearmatchflattentest exists
      And a page named Soñ Redirectnotbetter Nearmatchflattentest exists with contents #REDIRECT [[Són Redirectnotbetter Nearmatchflattentest]]
      And a page named Són Redirecttoomany Nearmatchflattentest exists
      And a page named Soñ Redirecttoomany Nearmatchflattentest exists with contents #REDIRECT [[Són Redirecttoomany Nearmatchflattentest]]
      And a page named Søn Redirecttoomany Nearmatchflattentest exists
      And a page named Blah Redirectnoncompete Nearmatchflattentest exists
      And a page named Soñ Redirectnoncompete Nearmatchflattentest exists with contents #REDIRECT [[Blah Redirectnoncompete Nearmatchflattentest]]
      And a page named Søn Redirectnoncompete Nearmatchflattentest exists with contents #REDIRECT [[Blah Redirectnoncompete Nearmatchflattentest]]
    }
  end
  $go_options = true
end

Before("@redirect") do
  if !$go_options
    steps %Q{
      Given a page named SEO Redirecttest exists with contents #REDIRECT [[Search Engine Optimization Redirecttest]]
      And a page named Redirecttest Yikes exists with contents #REDIRECT [[Redirecttest Yay]]
      And a page named User_talk:SEO Redirecttest exists with contents #REDIRECT [[User_talk:Search Engine Optimization Redirecttest]]
      And wait 3 seconds
      And a page named Seo Redirecttest exists
      And a page named Search Engine Optimization Redirecttest exists
      And a page named Redirecttest Yay exists
      And a page named User_talk:Search Engine Optimization Redirecttest exists
    }
  end
  $go_options = true
end

Before("@file_text") do
  if !$file_text
    steps %Q{
      Given a file named File:Linux_Distribution_Timeline_text_version.pdf exists with contents Linux_Distribution_Timeline_text_version.pdf and description Linux distribution timeline.
    }
  end
  $file_text = true
end

Before("@match_stopwords") do
  if !$match_plain
    steps %Q{
      Given a page named To exists
    }
  end
  $match_plain = true
end

Before("@many_redirects") do
  if !$many_redirects
    steps %Q{
      Given a page named Manyredirectstarget exists with contents [[Category:ManyRedirectsTest]]
      And a page named Fewredirectstarget exists with contents [[Category:ManyRedirectsTest]]
      And a page named Many Redirects Test 1 exists with contents #REDIRECT [[Manyredirectstarget]]
      And a page named Many Redirects Test 2 exists with contents #REDIRECT [[Manyredirectstarget]]
      And a page named Useless redirect to target 1 exists with contents #REDIRECT [[Manyredirectstarget]]
      And a page named Useless redirect to target 2 exists with contents #REDIRECT [[Manyredirectstarget]]
      And a page named Useless redirect to target 3 exists with contents #REDIRECT [[Manyredirectstarget]]
      And a page named Useless redirect to target 4 exists with contents #REDIRECT [[Manyredirectstarget]]
      And a page named Useless redirect to target 5 exists with contents #REDIRECT [[Manyredirectstarget]]
      And a page named Many Redirects Test ToFew exists with contents #REDIRECT [[Fewredirectstarget]]
    }
  end
  $many_redirects = true
end

Before("@relevancy") do
  if !$relevancy
    steps %Q{
      Given a page named Relevancytest exists with contents not relevant
      And a page named Relevancytestviaredirect exists with contents not relevant
      And a page named Relevancytest Redirect exists with contents #REDIRECT [[Relevancytestviaredirect]]
      And a page named Relevancytestviacategory exists with contents Some opening text. [[Category:Relevancytest]]
      And a page named Relevancytestviaheading exists with contents ==Relevancytest==
      And a page named Relevancytestviaopening exists with contents @Relevancytestviaopening.txt
      And a page named Relevancytestviatext exists with contents [[Relevancytest]]
      And a page named Relevancytestviaauxtext exists with contents @Relevancytestviaauxtext.txt
      And a page named Relevancytestphrase phrase exists with contents not relevant
      And a page named Relevancytestphraseviaredirect exists with contents not relevant
      And a page named Relevancytestphrase Phrase Redirect exists with contents #REDIRECT [[Relevancytestphraseviaredirect]]
      And a page named Relevancytestphraseviacategory exists with contents not relevant [[Category:Relevancytestphrase phrase category]]
      And a page named Relevancytestphraseviaheading exists with contents ==Relevancytestphrase phrase heading==
      And a page named Relevancytestphraseviaopening exists with contents @Relevancytestphraseviaopening.txt
      And a page named Relevancytestphraseviatext exists with contents [[Relevancytestphrase phrase]]
      And a page named Relevancytestphraseviaauxtext exists with contents @Relevancytestphraseviaauxtext.txt
      And a page named Relevancytwo Wordtest exists
      And a page named Wordtest Relevancytwo exists
      And a page named Relevancynamespacetest exists
      And a page named Talk:Relevancynamespacetest exists
      And a page named File:Relevancynamespacetest exists
      And a page named Help:Relevancynamespacetest exists
      And a page named File talk:Relevancynamespacetest exists
      And a page named User talk:Relevancynamespacetest exists
      And a page named Template:Relevancynamespacetest exists
      And a page named Relevancylanguagetest/ja exists
      And a page named Relevancylanguagetest/en exists
      And a page named Relevancylanguagetest/ar exists
      And a page named Relevancylinktest Smaller exists
      And a page named Relevancylinktest Larger Extraword exists
      And a page named Relevancylinktest Larger/Link A exists with contents [[Relevancylinktest Larger Extraword]]
      And a page named Relevancylinktest Larger/Link B exists with contents [[Relevancylinktest Larger Extraword]]
      And a page named Relevancylinktest Larger/Link C exists with contents [[Relevancylinktest Larger Extraword]]
      And a page named Relevancylinktest Larger/Link D exists with contents [[Relevancylinktest Larger Extraword]]
      And a page named Relevancyredirecttest Smaller exists with contents Relevancyredirecttest text text text text text text text text text text text text text
      And a page named Relevancyredirecttest Smaller/A exists with contents [[Relevancyredirecttest Smaller]]
      And a page named Relevancyredirecttest Smaller/B exists with contents [[Relevancyredirecttest Smaller]]
      And a page named Relevancyredirecttest Larger exists with contents Relevancyredirecttest text text text text text text text text text text text text text
      And a page named Relevancyredirecttest Larger/Redirect exists with contents #REDIRECT [[Relevancyredirecttest Larger]]
      And a page named Relevancyredirecttest Larger/A exists with contents [[Relevancyredirecttest Larger]]
      And a page named Relevancyredirecttest Larger/B exists with contents [[Relevancyredirecttest Larger/Redirect]]
      And a page named Relevancyredirecttest Larger/C exists with contents [[Relevancyredirecttest Larger/Redirect]]
    }
  end
  $relevancy = true
end

Before("@fallback_finder") do
  if !$fallback_finder
    steps %Q{
      Given a page named $US exists
      And a page named US exists
      And a page named Uslink exists with contents [[US]]
      And a page named Cent (currency) exists
      And a page named ¢ exists with contents #REDIRECT [[Cent (currency)]]
    }
  end
  $fallback_finder = true
end

Before("@js_and_css") do
  if !$js_and_css
    steps %Q{
      Given a page named User:Tools/Some.js exists with contents @some.js
      And a page named User:Tools/Some.css exists with contents @some.css
    }
  end
  $js_and_css = true
end

Before("@special_random") do
  if !$special_random
    steps %Q{
      Given a page named User:Random Test exists
      And a page named User_talk:Random Test exists
    }
  end
  $special_random = true
end

Before("@regex") do
  if !$regex
    steps %Q{
      Given a page named RegexEscapedForwardSlash exists with contents a/b
      And a page named RegexEscapedBackslash exists with contents a\\b
      And a page named RegexEscapedDot exists with contents a.b
    }
  end
  $regex = true
end
