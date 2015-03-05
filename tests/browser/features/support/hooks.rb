# encoding: utf-8

World(CirrusSearchApiHelper)

main = false
Before("@setup_main, @filters, @prefix, @bad_syntax, @wildcard, @exact_quotes") do
  unless main
    steps %(
      Given a page named Template:Template Test exists with contents pickles [[Category:TemplateTagged]]
      And a page named Catapult/adsf exists with contents catapult subpage [[Catapult]]
      And a page named Links To Catapult exists with contents [[Catapult]]
      And a page named Catapult exists with contents ♙ asdf [[Category:Weaponry]]
      And a page named Amazing Catapult exists with contents test [[Catapult]] [[Category:Weaponry]]
      And a page named Two Words exists with contents ffnonesenseword catapult {{Template_Test}} anotherword [[Category:TwoWords]] [[Category:Categorywith Twowords]] [[Category:Categorywith " Quote]]
      And a page named AlphaBeta exists with contents [[Category:Alpha]] [[Category:Beta]]
      And a page named IHaveATwoWordCategory exists with contents [[Category:CategoryWith ASpace]]
      And a page named Functional programming exists
      And a page named वाङ्मय exists
      And a page named वाङ्‍मय exists
      And a page named वाङ‍्मय exists
      And a page named वाङ्‌मय exists
        )
    main = true
  end
end

redirect_loop = false
Before("@redirect_loop") do
  unless redirect_loop
    steps %(
      Given a page named Redirect Loop exists with contents #REDIRECT [[Redirect Loop 1]]
      And a page named Redirect Loop 1 exists with contents #REDIRECT [[Redirect Loop 2]]
      And a page named Redirect Loop 2 exists with contents #REDIRECT [[Redirect Loop 1]]
        )
    redirect_loop = true
  end
end

main2 = false
Before("@setup_main, @prefix, @bad_syntax") do
  unless main2
    steps %(
      Given a page named Rdir exists with contents #REDIRECT [[Two Words]]
      And a file named File:Savepage-greyed.png exists with contents Savepage-greyed.png and description Screenshot, for test purposes, associated with https://bugzilla.wikimedia.org/show_bug.cgi?id=52908 .
      And a page named IHaveAVideo exists with contents [[File:How to Edit Article in Arabic Wikipedia.ogg|thumb|267x267px]]
      And a page named IHaveASound exists with contents [[File:Serenade for Strings -mvt-1- Elgar.ogg]]
        )
    main2 = true
  end
end

africa = false
Before("@setup_main, @prefix, @go, @bad_syntax") do
  unless africa
    steps %(
      Given a page named África exists with contents for testing
        )
    africa = true
  end
end

prefix = false
Before("@prefix") do
  unless prefix
    steps %(
      Given a page named L'Oréal exists
      And a page named Jean-Yves Le Drian exists
        )
    prefix = true
  end
end

headings = false
Before("@headings") do
  unless headings
    steps %(
      Given a page named HasHeadings exists with contents @has_headings.txt
      And a page named HasReferencesInText exists with contents References [[Category:HeadingsTest]]
      And a page named HasHeadingsWithHtmlComment exists with contents @has_headings_with_html_comment.txt
      And a page named HasHeadingsWithReference exists with contents @has_headings_with_reference.txt
        )
    headings = true
  end
end

javascript_injection = false
Before("@javascript_injection") do
  unless javascript_injection
    steps %(
      Given a page named Javascript Direct Inclusion exists with contents @javascript.txt
      Given a page named Javascript Pre Tag Inclusion exists with contents @javascript_in_pre.txt
        )
    javascript_injection = true
  end
end

setup_namespaces = false
Before("@setup_namespaces") do
  unless setup_namespaces
    steps %(
      Given a page named Talk:Two Words exists with contents why is this page about catapults?
      And a page named Help:Smoosh exists with contents test
      And a page named File:Nothingasdf exists with contents nothingasdf
        )
    setup_namespaces = true
  end
end

suggestions = false
Before("@suggestions") do
  unless suggestions
    steps %(
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
        )
    suggestions = true
  end
end

suggestions_stemming = false
Before("@suggestions", "@stemming") do
  unless suggestions_stemming
    steps %(
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
        )
    suggestions_stemming = true
  end
end

highlighting = false
Before("@highlighting") do
  unless highlighting
    steps %(
      Given a page named Rashidun Caliphate exists with contents @rashidun_caliphate.txt
      And a page named Crazy Rdir exists with contents #REDIRECT [[Two Words]]
      And a page named Insane Rdir exists with contents #REDIRECT [[Two Words]]
      And a page named The Once and Future King exists
      And a page named User_talk:Test exists
      And a page named Rose Trellis Faberge Egg exists with contents @rose_trellis_faberge_egg.txt
        )
  end
  highlighting = true
end

highlighting_references = false
Before("@highlighting", "@references") do
  unless highlighting_references
    steps %(
      Given a page named References Highlight Test exists with contents @references_highlight_test.txt
        )
  end
  highlighting_references = true
end

more_like_this = false
Before("@more_like_this") do
  unless more_like_this
    # The MoreLikeMe term must appear in "a bunch" of pages for it to be used in morelike: searches
    steps %(
      Given a page named More Like Me 1 exists with contents morelikesetone morelikesetone
      And a page named More Like Me 2 exists with contents morelikesetone morelikesetone morelikesetone morelikesetone
      And a page named More Like Me 3 exists with contents morelikesetone morelikesetone morelikesetone morelikesetone
      And a page named More Like Me 4 exists with contents morelikesetone morelikesetone morelikesetone morelikesetone
      And a page named More Like Me 5 exists with contents morelikesetone morelikesetone morelikesetone morelikesetone
      And a page named More Like Me Rdir exists with contents #REDIRECT [[More Like Me 1]]
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
        )
  end
  more_like_this = true
end

phrase_rescore = false
Before("@setup_phrase_rescore") do
  unless phrase_rescore
    steps %(
      Given a page named Rescore Test Words Chaff exists
      And a page named Test Words Rescore Rescore Test Words exists
      And a page named Rescore Test TextContent exists with contents Chaff
      And a page named Rescore Test HasTextContent exists with contents Rescore Test TextContent
        )
  end
  phrase_rescore = true
end

exact_quotes = false
Before("@exact_quotes") do
  unless exact_quotes
    steps %(
      Given a page named Contains A Stop Word exists
      And a page named Doesn't Actually Contain Stop Words exists
      And a page named Pick* exists
        )
  end
  exact_quotes = true
end

programmer_friendly = false
Before("@programmer_friendly") do
  unless programmer_friendly
    steps %(
      Given a page named $wgNamespaceAliases exists
      And a page named PFSC exists with contents snake_case
      And a page named PascalCase exists
      And a page named NumericCase7 exists
      And a page named this.getInitial exists
      And a page named RefToolbarBase.js exists
      And a page named PFTest Paren exists with contents this.isCamelCased()
        )
    programmer_friendly = true
  end
end

stemmer = false
Before("@stemmer") do
  unless stemmer
    steps %(
      Given a page named StemmerTest Aliases exists
      And a page named StemmerTest Alias exists
      And a page named StemmerTest Used exists
      And a page named StemmerTest Guidelines exists
        )
  end
  stemmer = true
end

prefix_filter = false
Before("@prefix_filter") do
  unless prefix_filter
    steps %(
      Given a page named Prefix Test exists
      And a page named Prefix Test Redirect exists with contents #REDIRECT [[Prefix Test]]
      And a page named Foo Prefix Test exists with contents [[Prefix Test]]
      And a page named Prefix Test/AAAA exists with contents [[Prefix Test]]
      And a page named Prefix Test AAAA exists with contents [[Prefix Test]]
      And a page named Talk:Prefix Test exists with contents [[Prefix Test]]
      And a page named User_talk:Prefix Test exists with contents [[Prefix Text]]
        )
  end
  prefix_filter = true
end

prefer_recent = false
Before("@prefer_recent") do
  unless prefer_recent
    # These are updated per process instead of per test because of the 20 second wait
    # Note that the scores have to be close together because 20 seconds doesn't mean a whole lot
    steps %(
      Given a page named PreferRecent First exists with contents %{epoch}
      And a page named PreferRecent Second Second exists with contents %{epoch}
      And wait 20 seconds
      And a page named PreferRecent Third exists with contents %{epoch}
        )
  end
  prefer_recent = true
end

hastemplate = false
Before("@hastemplate") do
  unless hastemplate
    steps %(
      Given a page named MainNamespaceTemplate exists
      And a page named HasMainNSTemplate exists with contents {{:MainNamespaceTemplate}}
      And a page named Talk:TalkTemplate exists
      And a page named HasTTemplate exists with contents {{Talk:TalkTemplate}}
        )
  end
  hastemplate = true
end

boost_template = false
Before("@boost_template") do
  unless boost_template
    steps %(
      Given a page named Template:BoostTemplateHigh exists with contents BoostTemplateTest
      And a page named Template:BoostTemplateLow exists with contents BoostTemplateTest
      And a page named NoTemplates BoostTemplateTest exists with contents nothing important
      And a page named HighTemplate exists with contents {{BoostTemplateHigh}}
      And a page named LowTemplate exists with contents {{BoostTemplateLow}}
        )
  end
  boost_template = true
end

go = false
Before("@go") do
  unless go
    steps %(
      Given a page named MixedCapsAndLowerCase exists
        )
  end
  go = true
end

go_options = false
Before("@go", "@options") do
  unless go_options
    steps %(
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
        )
  end
  go_options = true
end

redirect = false
Before("@redirect") do
  unless redirect
    steps %(
      Given a page named SEO Redirecttest exists with contents #REDIRECT [[Search Engine Optimization Redirecttest]]
      And a page named Redirecttest Yikes exists with contents #REDIRECT [[Redirecttest Yay]]
      And a page named User_talk:SEO Redirecttest exists with contents #REDIRECT [[User_talk:Search Engine Optimization Redirecttest]]
      And wait 3 seconds
      And a page named Seo Redirecttest exists
      And a page named Search Engine Optimization Redirecttest exists
      And a page named Redirecttest Yay exists
      And a page named User_talk:Search Engine Optimization Redirecttest exists
      And a page named PrefixRedirectRanking 1 exists
      And a page named LinksToPrefixRedirectRanking 1 exists with contents [[PrefixRedirectRanking 1]]
      And a page named TargetOfPrefixRedirectRanking 2 exists
      And a page named PrefixRedirectRanking 2 exists with contents #REDIRECT [[TargetOfPrefixRedirectRanking 2]]
        )
  end
  redirect = true
end

file_text = false
Before("@file_text") do
  unless file_text
    steps %(
      Given a file named File:Linux_Distribution_Timeline_text_version.pdf exists with contents Linux_Distribution_Timeline_text_version.pdf and description Linux distribution timeline.
        )
  end
  file_text = true
end

match_stopwords = false
Before("@match_stopwords") do
  unless match_stopwords
    steps %(
      Given a page named To exists
        )
  end
  match_stopwords = true
end

many_redirects = false
Before("@many_redirects") do
  unless many_redirects
    steps %(
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
        )
  end
  many_redirects = true
end

relevancy = false
Before("@relevancy") do
  unless relevancy
    steps %(
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
      And a page named Relevancyclosetest Foô exists
      And a page named Relevancyclosetest Foo exists
      And a page named Foo Relevancyclosetest exists
        )
  end
  relevancy = true
end

fallback_finder = false
Before("@fallback_finder") do
  unless fallback_finder
    steps %{
      Given a page named $US exists
      And a page named US exists
      And a page named Uslink exists with contents [[US]]
      And a page named Cent (currency) exists
      And a page named ¢ exists with contents #REDIRECT [[Cent (currency)]]
    }
  end
  fallback_finder = true
end

js_and_css = false
Before("@js_and_css") do
  unless js_and_css
    steps %(
      Given a page named User:Tools/Some.js exists with contents @some.js
      And a page named User:Tools/Some.css exists with contents @some.css
        )
  end
  js_and_css = true
end

special_random = false
Before("@special_random") do
  unless special_random
    steps %(
      Given a page named User:Random Test exists
      And a page named User_talk:Random Test exists
        )
  end
  special_random = true
end

regex = false
Before("@regex") do
  unless regex
    steps %(
      Given a page named RegexEscapedForwardSlash exists with contents a/b
      And a page named RegexEscapedBackslash exists with contents a\\b
      And a page named RegexEscapedDot exists with contents a.b
      And a page named RegexSpaces exists with contents a b c
      And a page named RegexComplexResult exists with contents aaabacccccccccccccccdcccccccccccccccccccccccccccccdcccc
        )
  end
  regex = true
end

linksto = false
Before("@linksto") do
  unless linksto
    steps %(
      Given a page named LinksToTest Target exists
      And a page named LinksToTest Plain exists with contents [[LinksToTest Target]]
      And a page named LinksToTest OtherText exists with contents [[LinksToTest Target]] and more text
      And a page named LinksToTest No Link exists with contents LinksToTest Target
      And a page named Template:LinksToTest Template exists with contents [[LinksToTest Target]]
      And a page named LinksToTest Using Template exists with contents {{LinksToTest Template}}
      And a page named LinksToTest LinksToTemplate exists with contents [[Template:LinksToTest Template]]
        )
    linksto = true
  end
end

filenames = false
Before("@filenames") do
  unless filenames
    steps %(
      Given a file named File:No_SVG.svg exists with contents No_SVG.svg and description [[Category:Red circle with left slash]]
      And a file named File:Somethingelse_svg_SVG.svg exists with contents Somethingelse_svg_SVG.svg and description [[Category:Red circle with left slash]]
        )
    filenames = true
  end
end

removed_text = false
Before("@removed_text") do
  unless removed_text
    steps %(
      Given a page named Autocollapse Example exists with contents <div class="autocollapse">in autocollapse</div>
        )
    removed_text = true
  end
end

accent_squashing = false
Before("@accent_squashing") do
  unless accent_squashing
    steps %(
      Given a page named Áccent Sorting exists
        And a page named Accent Sorting exists
        )
    accent_squashing = true
  end
end

accented_namespace = false
Before("@accented_namespace") do
  unless removed_text
    steps %(
      Given a page named Mó:Test exists with contents some text
        )
    accented_namespace = true
  end
end
