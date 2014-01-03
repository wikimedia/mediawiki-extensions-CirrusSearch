<?php

/**
 * Messages for CirrusSearch extension
 */

$messages = array();

/**
 * English
 */
$messages['en'] = array(
	'cirrussearch-desc' => 'Elasticsearch-powered search for MediaWiki',
	'cirrussearch-backend-error' => 'We could not complete your search due to a temporary problem. Please try again later.',
	'cirrussearch-parse-error' => "Query was not understood. Please make it simpler. The query was logged to improve the search system.",
	'cirrussearch-now-using' => 'This wiki is using a new search engine. ([[mw:Special:MyLanguage/Help:CirrusSearch|Learn more]])',
	'cirrussearch-ignored-headings' => ' #<!-- leave this line exactly as it is --> <pre>
# Headings that will be ignored by search.
# Changes to this take effect as soon as the page with the heading is indexed.
# You can force page reindexing by doing a null edit.
# Syntax is as follows:
#   * Everything from a "#" character to the end of the line is a comment
#   * Every non-blank line is the exact title to ignore, case and everything
References
External links
See also
 #</pre> <!-- leave this line exactly as it is -->',
	'cirrussearch-boost-templates' => ' #<!-- leave this line exactly as it is --> <pre>
# If a page contains one of these templates then its search score is multiplied by the configured percentage.
# Changes to this take effect immediately.
# Syntax is as follows:
#   * Everything from a "#" character to the end of the line is a comment
#   * Every non-blank line is the exact template name to boost, namespace, case and everything, followed by a "|" character followed by a number followed by a "%" character.
# Examples of good lines:
# Template:Good|150%
# Template:Very Very Good|300%
# Template:Bad|50%
# Examples of non-working lines:
# Template:Foo|150.234234% <-- no decimal points allowed!
# Foo|150% <--- technically works, but for transclusions of the Foo page from the main namespace
# You can test configuration changes by performing a query prefixed with boost-templates:"XX" where XX is all of the templates you want to boost separated by spaces instead of line breaks.
# Queries that specify boost-templates:"XX" ignore the contents of this field.
 #</pre> <!-- leave this line exactly as it is -->',
	'cirrussearch-pref-label' => 'New search',
	'cirrussearch-pref-desc' => 'Try our new search which supports a greater number of languages, provides more up-to-date results, and can even find text inside of templates.',
);

/** Message documentation (Message documentation)
 * @author Kunal Mehta
 * @author Shirayuki
 * @author Siebrand
 */
$messages['qqq'] = array(
	'cirrussearch-desc' => '{{desc|name=Cirrus Search|url=http://www.mediawiki.org/wiki/Extension:CirrusSearch}}
"Elasticsearch" is a full-text search engine. See http://www.elasticsearch.org/',
	'cirrussearch-backend-error' => 'Error message shown to the users when we have an issue communicating with our search backend',
	'cirrussearch-parse-error' => "Error message shown to the users when we couldn't understand the query.  For the most part we don't expect users to see this because we retry retry queries that we don't unserstand using a (hopefully) fail safe method.",
	'cirrussearch-now-using' => "Note that this wiki is using a new search engine with a link for people to learn more.  That'll contain information on filing a bug, new syntax, etc.",
	'cirrussearch-ignored-headings' => 'Headings that will be ignored by search. You can translate the text, including "Leave this line exactly as it is". Some lines of this messages have one (1) leading space.',
	'cirrussearch-boost-templates' => 'Templates that if contained on a page will raise or lower the score of the page in search results.  They must be specied Namespace:Template Name|<boost factor>%. You can translate the text, including "Leave this line exactly as it is". Some lines of this messages have one (1) leading space.',
	'cirrussearch-pref-label' => 'Preference label for option to enable CirrusSearch by default',
	'cirrussearch-pref-desc' => 'Description for option to enable CirrusSearch by default',
);

/** Arabic (العربية)
 * @author Asaifm
 */
$messages['ar'] = array(
	'cirrussearch-desc' => 'عملية البحث مدعومة من قبل Elasticsearch لميدياويكي',
	'cirrussearch-backend-error' => 'لم نستطع إكمال بحثك بسبب مشكلة مؤقتة. الرجاء المحاولة لاحقاً.',
	'cirrussearch-now-using' => 'تستخدم الويكي محرك بحث جديد. ([[mw:Special:MyLanguage/Help:CirrusSearch|إضغط هنا للمزيد من المعلومات]])',
	'cirrussearch-ignored-headings' => '# <!-- أترك هذا السطر كما هو --> <pre>
# سيتم تجاهل الترويسات خلال عملية البحث
#ا لتغييرات ستأخذ مجراها ما أن يتم فهرسة الصفحة التي تحتوي على ترويسات
# يمكنك فرض عملية فهرسة الصفحة من خلال تعديل فارغ
# الصيغة هي كالأتي:
# * كل ما يكتب بعد "#" إلى آخر السطر يعتبر تعليق
# * كل سطر غير فارغ سيكون العنوان الذي سيتم تجاهله (سيأخذ العنوان كما هو بالضبط بالتشكيل وخلافه)
المراجع
الوصلات الخارجية
أنظر أيضا
#</pre><!--أترك هذا السطر كما هو -->',
	'cirrussearch-boost-templates' => '# <!-- أترك هذا السطر كما هو --> <pre>
# إذا كانت الصفحة تحتوى على إحدى القوالب المذكورة أدناه سيتم ضرب درجة البحث بالنسبة المئوية المحددة.
#التغييرات هذه نافذة المفعول فور حدوثها.
#الصياغة تكون كالآتي:
# *كل ما يبدأ بالعلامة "#" إلى آخر السطر سيتم إعتباره على أنه تعليق
# * كل سطر غير فارغ سيكون إسم القالب الذي سيتم دعم وزيادة نتيجته (سيأخذ الإسم كما هو بالضبط بالتشكيل وخلافه). بعد الإسم ستوضع علامة "|" ثم الرقم ثم علامة "%".
#بعض الأمثلة الجيدة:
#قالب:جيد|150%
#قالب:جيد جداً|300%
#قالب:سيئ|50%
#بعض الأمثلة التي لن تعمل:
#قالب:مغفل|150.234234% <-- لا يسمح بإستخدام الفاصلة العشرية!
#فو|150% <-- تقنياً ستعمل ولكن لتضمينات صفحة فو من النطاق الرئيسي
#يمكنك تجربة التغييرات التي تمت على الإعدادت عن طريقة كتابة إستعلام يبدأ بالقوالب المدعومة: "XX" هي كل القوالب التي ترغب في دعمها مفصولة بمسافات عوضاً عن فواصل الأسطر (line breaks).
# الإستعلامات التي تحدد القوالب المدعومة:"XX" تجاهل محتويات هذا الحقل.
#</pre> <!-- أترك هذا السطر كما هو -->',
	'cirrussearch-pref-label' => 'بحث جديد',
	'cirrussearch-pref-desc' => 'جرب طريقة البحث الجديدة التي تدعم عدد أكبر من اللغات وتوفر نتائج أفضل من ناحية التحديثات. كما يمكنها أيضا البحث لك عن نصوص داخل قوالب.',
	'cirrussearch-file-contents-match' => 'محتويات الملف المطابقة للبحث: $1',
);

/** Asturian (asturianu)
 * @author Xuacu
 */
$messages['ast'] = array(
	'cirrussearch-desc' => 'Gueta col motor Elasticsearch pa MediaWiki',
	'cirrussearch-backend-error' => 'Nun pudimos completar la gueta por un problema temporal. Por favor, vuelva a intentalo más sero.',
	'cirrussearch-now-using' => 'Esta wiki ta usando un motor de gueta nuevu. ([[mw:Special:MyLanguage/Help:CirrusSearch|Ver más]])',
	'cirrussearch-ignored-headings' => " #<!-- dexar esta llinia exactamente como ta --> <pre>
# Testeres que nun se tendrán en cuenta na gueta.
# Los cambios fechos equí son efeutivos nel momentu que s'indexa la páxina cola testera.
# Pue forzar el reindexáu d'una páxina faciendo una edición nula.
# La sintaxis ye la siguiente:
#   * Tolo qu'hai dende un caráuter \"#\" al fin de llinia ye un comentariu
#   * Cada llinia non-balera ye'l títulu exactu a descartar, incluyendo mayúscules y demás
Referencies
Enllaces esternos
Ver tamién
 #</pre> <!-- dexar esta llinia exactamente como ta -->",
	'cirrussearch-boost-templates' => ' #<!-- dexar esta llinia exactamente como ta --> <pre>
# Si una páxina contién una d\'estes plantíes, la so puntuación na gueta multiplícase pol porcentax configuráu.
# Los cambios equí son efeutivos darréu.
# La sintaxis ye como sigue:
#   * Cualquier cosa dende un caráuter "#" al fin de llinia ye un comentariu
#   * Cada llinia non-balera ye\'l nome de plantía exactu a aumentar, con espaciu de nomes, mayúscules, etc, siguíu por un caráuter "|", siguíu por un númberu, siguíu por un caráuter "%".
# Exemplos de llinies correutes:
# Plantía:Novedaes|150%
# Plantía:Destacaos|300%
# Plantía:Correxir|50%
# Exemplos de llinies incorreutes:
# Plantía:Foo|150,234234% <-- nun se permiten los decimales
# Foo|150% <--- téunicamente funciona, pero sólo pa tresclusiones de la páxina Foo nel espaciu de nomes principal
# Pue probar los cambios na configuración faciendo una consulta col prefixu boost-templates:"XX" onde XX son toles plantíes que quiera aumentar separaes con espacios en llugar de saltos de llinia.
# Les consultes qu\'especifiquen boost-templates:"XX" saltense\'l conteníu d\'esti campu.
 #</pre> <!-- dexar esta llinia exactamente como ta -->',
	'cirrussearch-pref-label' => 'Gueta nueva',
	'cirrussearch-pref-desc' => 'Pruebe la nuesa gueta nueva que tien sofitu pa más llingües, ufre resultaos más actuales, ya inda pue alcontrar testu dientro de les plantíes.',
	'cirrussearch-file-contents-match' => 'Conteníu del ficheru que casa: $1',
);

/** Bikol Central (Bikol Central)
 * @author Geopoet
 */
$messages['bcl'] = array(
	'cirrussearch-desc' => 'Elastikongpaghanap-makusugon na panhanap para sa MediaWiki',
	'cirrussearch-backend-error' => 'Dae nyamo makukumpleto an saimong paghahanap nin huli sa sarong temporaryong problema.
Tabi man paki-otroha giraray oro-atyan.',
	'cirrussearch-ignored-headings' => ' #<!-- walaton ining linya eksaktong siring sana kaini --> <pre> 
# Mga Kapamayuhanan na pinagpapabayaan sa paghahanap. 
# Mga Kaliwatan kaini magkaka-epekto matapos na an pahina na igwang kapamayuhanan maipaghukdo. 
# Ika makakapagpuwersa sa pahina na maihuhukdo otro sa paagi nin paghimo nin sarong blangko na pagliwat. # An Sintaks iyo ining minasunod: 
# * An gabos magpoon sa sarong karakter na "#" sagkod sa tapos kan linya iyo an sarong komento 
# * An lambang linya na bakong blangko iyo an eksaktong titulo na pababayaan, kaso asin gabos na bagay 
Mga Panultulan
Panluwas na mga sugpon
Hilingon man 
#</pre> <!-- walaton ining linya eksaktong siring sana kaini -->',
);

/** Belarusian (Taraškievica orthography) (беларуская (тарашкевіца)‎)
 * @author Wizardist
 */
$messages['be-tarask'] = array(
	'cirrussearch-desc' => 'Пошук у MediaWiki з дапамогай ElasticSearch',
	'cirrussearch-backend-error' => 'Мы не змаглі выканаць пошукавы запыт з-за часовых праблемаў. Паспрабуйце пазьней, калі ласка.',
	'cirrussearch-ignored-headings' => ' #<!-- не зьмяняйце гэты радок --> <pre>
# Загалоўкі, якія мусіць ігнараваць пошукавы рухавік.
# Зьмены будуць ужытыя па наступным індэксаваньні старонкі.
# Вы можаце змусіць пераіндэксаваць старонку пустым рэдагаваньнем.
# Сынтакс наступны:
#   * Усё, што пачынаецца з "#" — камэнтар
#   * Усякі непусты радок — загаловак, які трэба ігнараваць
Крыніцы
Вонкавыя спасылкі
Глядзіце таксама
 #</pre> <!-- не зьмяняйце гэты радок -->',
);

/** Bengali (বাংলা)
 * @author Aftab1995
 */
$messages['bn'] = array(
	'cirrussearch-backend-error' => 'একটি সাময়িক সমস্যার কারণে আমরা আপনার অনুসন্ধান সম্পন্ন করতে পারেনি। দয়া করে পরে আবার চেষ্টা করুন।',
	'cirrussearch-now-using' => 'এই উইকি একটি নতুন অনুসন্ধান ইঞ্জিন ব্যবহার করছে।([[mw:Special:MyLanguage/Help:CirrusSearch|আরো জানুন]])',
	'cirrussearch-pref-label' => 'নতুন অনুসন্ধান',
	'cirrussearch-pref-desc' => 'আমাদের নতুন অনুসন্ধান ব্যবহার করে দেখুন যা অধিক সংখ্যক ভাষা সমর্থন করে, আরও হালনাগাদকৃত ফলাফল প্রদান করে এবং এমনকি টেমপ্লেট ভিতরের পাঠ্যও অনুসন্ধান করতে পারে।',
);

/** Breton (brezhoneg)
 * @author Fohanno
 */
$messages['br'] = array(
	'cirrussearch-backend-error' => "N'hon eus ket gallet kas hoc'h enklask da benn abalamour d'ur gudenn dibad. Esaeit en-dro diwezhatoc'h, mar plij.",
	'cirrussearch-pref-label' => 'Enklask nevez',
);

/** Catalan (català)
 * @author Fitoschido
 * @author QuimGil
 * @author Vriullop
 */
$messages['ca'] = array(
	'cirrussearch-desc' => 'Cerca realitzada amb Elasticsearch per a MediaWiki',
	'cirrussearch-backend-error' => 'La seva cerca no ha pogut ser completada degut a un problema temporal. Si us plau, provi-ho més tard.',
	'cirrussearch-now-using' => 'Aquest wiki està utilitzant un nou cercador. ([[mw:Special:MyLanguage/Help:CirrusSearch|Més informació]])',
	'cirrussearch-ignored-headings' => ' #<!-- deixeu aquesta línia tal com està --> <pre>
# Títols que seran ignorats pel cercador.
# Els canvis fets aquí tindran efecte tant aviat com la pàgina amb el títol sigui indexada.
# Podeu forçar que una pàgina s\'indexi de nou fent una edició nul·la.
# La sintaxi és la següent:
#   * Tot el que hi hagi des d\'un caràcter "#" fins el final de línia és un comentari
#   * Tota línia no buida és el títol exacte a ignorar, amb les majúscules i complet
Referències
Enllaços externs
Vegeu també
 #</pre> <!-- deixeu aquesta línia tal com està -->',
	'cirrussearch-pref-desc' => 'Usa un motor de cerca nou, que indexa plantilles expandides, admet més idiomes i s’actualitza més freqüentment.', # Fuzzy
);

/** Czech (čeština)
 * @author Matěj Grabovský
 * @author Mormegil
 * @author Paxt
 */
$messages['cs'] = array(
	'cirrussearch-desc' => 'Vyhledávání v MediaWiki běžící na Elasticsearch',
	'cirrussearch-backend-error' => 'Kvůli dočasnému problému jsme nemohli provést požadované vyhledávání. Zkuste to znovu později.',
	'cirrussearch-parse-error' => 'Dotaz nebyl pochopen. Prosíme o jeho zjednodušení. Dotaz byl přihlášen ke zlepšení vyhledávacího systému.',
	'cirrussearch-now-using' => 'Tato wiki používá nový vyhledávač. ([[mw:Special:MyLanguage/Help:CirrusSearch|Více informací]])',
	'cirrussearch-ignored-headings' => ' #<!-- tento řádek ponechte beze změny --> <pre>
# Zde uvedené nadpisy budou ignorovány vyhledáváním.
# Změny této stránky se projeví ve chvíli, kdy je stránka používající příslušný nadpis indexována.
# Přeindexování stránky můžete vynutit prázdnou editací.
# Syntaxe je taková:
#   * Cokoli od znaku „#“ do konce řádky je komentář.
#   * Každá neprázdná řádka je přesný nadpis, který se má ignorovat, včetně velikosti písmen a tak.
Reference
Externí odkazy
Související články
Související stránky
 #</pre> <!-- tento řádek ponechte beze změny -->',
	'cirrussearch-boost-templates' => ' #<!-- tuto řádku ponechte přesně takto --> <pre>
# Pokud stránka obsahuje jednu z těchto šablon, je její vyhledávací skóre vynásobeno nastaveným procentem.
# Změny této stránky se projeví okamžitě.
# Syntax je následující:
#   * Všechno od znaku „#“ do konce řádky je komentář.
#   * Každý neprázdný řádek je přesný název šablony, která se má bonifikovat, včetně jmenného prostoru, přesné velikosti písmen a tak, následovaný znakem „|“ následovaný číslem následovaným znakem „%“.
# Příklad správných řádek:
# Šablona:Dobré|150%
# Šablona:Velmi velmi dobré|300%
# Šablona:Špatné|50%
# Příklady nefunkčních řádek:
# Šablona:Foo|150.234234% <-- desetinná tečka/čárka není dovolena!
# Foo|150% <--- technicky vzato funguje, ale pro vložení stránky Foo v hlavním jmenném prostoru
# Změny konfigurace můžete otestovat vyhledávacím dotazem, před který uvedete boost-templates:"XX", kde XX je seznam všech šablon, které chcete bonifikovat, oddělené mezerou místo konce řádky.
# Dotazy uvádějící boost-templates:"XX" ignorují obsah tohoto pole.
#</pre> <!-- tuto řádku ponechte přesně takto -->',
	'cirrussearch-pref-label' => 'Nové hledání',
	'cirrussearch-pref-desc' => 'Vyzkoušejte nový vyhledávač, který podporuje více jazyků, zobrazuje novější výsledky a dokonce hledá text uvnitř šablon.',
	'cirrussearch-file-contents-match' => 'Odpovídající obsah souboru: $1',
);

/** Danish (dansk)
 * @author Christian List
 */
$messages['da'] = array(
	'cirrussearch-desc' => 'Søgning for MediaWiki drevet af Elasticsearch',
	'cirrussearch-backend-error' => 'Vi kunne ikke fuldføre søgningen på grund af et midlertidigt problem.  Prøv igen senere.',
	'cirrussearch-now-using' => 'Denne wiki bruger en ny søgemaskine. ([[mw:Special:MyLanguage/Help:CirrusSearch|Læs mere]])',
	'cirrussearch-ignored-headings' => ' #<!-- lad denne linje være præcis som den er --> <pre>
# Overskrifter, der vil blive ignoreret af søgning.
# Ændringer til dette træder i kraft så snart siden med overskriften er indekseret.
# Du kan tvinge siden til genindeksering ved at lave en nul redigering.
# syntaksen er som følger:
#   * Alt fra en tegnet "#" til slutningen af linjen er en kommentar
#   * Hver ikke-tomme linje er den nøjagtige titel der skal ignoreres, der skelnes også mellem store og små bogstaver
Referencer
Eksterne henvisninger
Se også
Kilder og henvisninger
Eksterne kilder/henvisninger
Kilder
 #</pre> <!-- lad denne linje være præcis som den er -->',
	'cirrussearch-pref-label' => 'Ny søgning',
	'cirrussearch-pref-desc' => 'Prøv vores nye søgning, som understøtter et større antal sprog, giver mere opdaterede resultater og kan endda finde tekst inden i skabeloner.',
);

/** German (Deutsch)
 * @author Kghbln
 * @author Metalhead64
 * @author Michawiki
 */
$messages['de'] = array(
	'cirrussearch-desc' => 'Ermöglicht eine „elasticsearch“-gestütze Suche',
	'cirrussearch-backend-error' => 'Deine Suche konnte aufgrund eines vorübergehenden Problems nicht abgeschlossen werden. Bitte später erneut versuchen.',
	'cirrussearch-parse-error' => 'Die Suchanfrage wurde nicht verstanden. Bitte mache sie einfacher. Die Anfrage wurde protokolliert, um das Suchsystem zu verbessern.',
	'cirrussearch-now-using' => 'Dieses Wiki verwendet eine neue Suchmaschine. ([[mw:Special:MyLanguage/Help:CirrusSearch|Mehr erfahren]])',
	'cirrussearch-ignored-headings' => ' #<!-- diese Zeile nicht verändern --> <pre>
# Überschriften, die von der Suche ignoriert werden.
# Diese Änderungen werden wirksam, sobald die Seite mit der Überschrift indexiert wurde.
# Du kannst die Seitenindexierung erzwingen, indem du einen Nulledit durchführst.
# Syntax:
#   * Alles, was einer Raute („#“) bis zum Zeilenende folgt, ist ein Kommentar.
#   * Jede nicht-leere Zeile ist der exakte zu ignorierende Titel.
Einzelnachweise
Weblinks
Siehe auch
 #</pre> <!-- diese Zeile nicht verändern -->',
	'cirrussearch-boost-templates' => ' #<!-- Diese Zeile nicht verändern. --> <pre>
# Falls eine Seite eine dieser Vorlagen enthält, wird der Such-Score mit dem konfigurierten Prozentsatz multipliziert.
# Änderungen werden sofort wirksam.
# Syntax:
#   * Alles ab einer Raute („#“) bis zum Zeilenende ist ein Kommentar.
#   * Jede nicht-leere Zeile ist der genaue Name der zu optimierenden Vorlage mit Namensraum und Unterscheidung zwischen Groß-/Kleinschreibung, gefolgt von einem Pipe-Symbol („|“), einer Zahl und einem Prozentzeichen („%“).
# Beispiele funktionierender Zeilen:
# Vorlage:Gut|150%
# Vorlage:Sehr gut|300%
# Vorlage:Schlecht|50%
# Beispiele nicht funktionierender Zeilen:
# Vorlage:Foo|150.234234% <-- Keine Dezimalstellen erlaubt!
# Foo|150% <-- Technisch möglich, allerdings für Einbindungen der Seite „Foo“ aus dem Haupt-Namensraum.
# Du kannst die Konfiguration durch eine Abfrage mit dem Präfix boost-templates:"XX" testen, wobei XX für alle Vorlagen steht, die du optimieren möchtest, getrennt durch Leerzeichen anstelle von Zeilenumbrüchen.
# Abfragen mit boost-templates:"XX" ignorieren die Inhalte dieses Feldes.
 #</pre> <!-- Diese Zeile nicht verändern. -->',
	'cirrussearch-pref-label' => 'Neue Suche',
	'cirrussearch-pref-desc' => 'Teste unsere neue Suchmaschine, die eine größere Anzahl an Sprachen unterstützt, aktuellere Ergebnisse liefert und auch Text innerhalb Vorlagen finden kann.',
	'cirrussearch-file-contents-match' => 'Dateiinhaltstreffer: $1',
);

/** Swiss High German (Schweizer Hochdeutsch)
 * @author Filzstift
 */
$messages['de-ch'] = array(
	'cirrussearch-desc' => 'Ermöglicht eine durch «Elasticsearch» gestützte Suche',
);

/** Lower Sorbian (dolnoserbski)
 * @author Michawiki
 */
$messages['dsb'] = array(
	'cirrussearch-desc' => 'Pytanje na zakłaźe "elasticsearch" za MediaWiki',
	'cirrussearch-backend-error' => 'Twójo pytanje njedajo se nachylnego problema dla skóńcyś. Pšosym wopytaj pózdźej hyšći raz.',
	'cirrussearch-now-using' => 'Toś ten wiki wužywa nowu pytnicu ([[mw:Special:MyLanguage/Help:CirrusSearch|Dalšne informacije]])',
	'cirrussearch-ignored-headings' => ' #<!-- njezměń toś tu smužku --> <pre>
# Nadpisma, kótarež pytanje ignorěrujo.
# Toś te změny budu se wustatkowaś, za tym až bok jo se indicěrował.
# Móžoš indicěrowanje bokow wunuźiś, z tym až pśewjedujoš proznu změnu.
# Syntaksa:
#   * Wšykno, což slědujo znamušku "#" až do kóńca smužki, jo komentar
#   * Kuzda njeprozna smužka jo eksaktny titel, kótaryž ma se ignorěrowaś
Žrědła
Eksterne wótkaze
Glědaj teke
 #</pre> <!-- njezměń toś tu smužku -->',
	'cirrussearch-boost-templates' => ' #<!-- Njezměń toś tu smužku. --> <pre>
# Jolic bok wopśimujo jadnu z toś tych pśedłogow, buźo se pytańske pogódnośenje z konfigurěrowaneju procentoweju sajźbu multiplicěrowaś.
# Změny se ned wustatkuju.
# Syntaksa:
#   * Wšykno za znamuškom „#“ až do kóńca smužki jo komentar.
#   * Kužda njeprozna smužka jo eksaktne mě pśedłogi, kótaraž ma se optiměrowaś, z mjenjowym rumom, wjelikopisanim, jo wšykno, slědowane pśez znamuško "|", licbu a znamuško "%".
# Pśikłady funkcioněrujucych smužkow:
# Pśedłoga:Dobry|150%
# Pśedłoga:Wjelgin dobry|300%
# Pśedłoga:Špatny|50%
# Pśikłady njefunkcioněrujucych smužkow:
# Pśedłoga:Foo|150.234234% <-- Decimalne městna njejsu dowólone!
# Foo|150% <-- Techniski móžno, ale za zapśěgowanja boka "Foo" z głownego mjenjowego ruma.
# Móžoš konfiguraciju pśez napšašowanje z prefiksom boost-templates:"XX"" testowaś, pśi comž XX stoj za wšykne pśedłogi, kótarež coš optiměrowaś, źělone pśez prozne znamje město łamanja smužki.
# Napšašowanja z boost-templates:"XX" ignorěruju wopśimjeśe toś togo póla.
 #</pre> <!-- Njezměń toś tu smužku. -->',
	'cirrussearch-pref-label' => 'Nowe pytanje',
	'cirrussearch-pref-desc' => 'Wopytaj našo nowe pytanje, kótarež pódpěra wětšu licbu rěcow, pódawa aktualnjejše wuslědki a móžo samo tekst w pśedłogacj namakaś.',
	'cirrussearch-file-contents-match' => 'Trjefaŕ datajowego wopśimjeśa: $1',
);

/** Spanish (español)
 * @author Ciencia Al Poder
 * @author Csbotero
 * @author Fitoschido
 * @author Ihojose
 * @author Luis Felipe Schenone
 */
$messages['es'] = array(
	'cirrussearch-desc' => 'Hace que la búsqueda sea con Solr',
	'cirrussearch-backend-error' => 'No pudimos completar tu búsqueda debido a un problema temporario. Por favor intenta de nuevo más tarde.',
	'cirrussearch-parse-error' => 'La queja no fue entendida. Hágala más sencilla. La queja fue registrada para mejorar el sistema de búsqueda.',
	'cirrussearch-now-using' => 'Esta wiki está utilizando un nuevo motor de búsqueda. ([[mw:Special:MyLanguage/Help:CirrusSearch|Ver más información]])',
	'cirrussearch-ignored-headings' => ' #<!-- deje esta línea tal y como está --> <pre>
# Títulos que serán ignorados por la búsqueda.
# Los cambios estarán en vigor tan pronto como la página con el título esté indexada.
# Puede forzar la página a ser reindexada haciendo una edición nula.
# La sintaxis es la siguiente: .N!
#   * Todo, desde un carácter "#" al final de la línea es un comentario
#   * Todas las líneas en blanco es un título exacto para ignorar, caso y cualquier 
Referencia
Enlaces externos
Véase también
 #</pre> <!-- deje esta línea tal y como está -->',
	'cirrussearch-boost-templates' => ' #<!-- deja esta línea exactamente como está --> <pre>
# Si una página contiene una de estas plantillas, entonces su puntuación en la búsqueda se multiplicará por el porcentaje configurado
# Los cambios realizados aquí tendrán efecto de forma inmediata.
# La sintaxis es la siguiente:
#   * Todo el contenido desde un caracter "#" hasta el final de la línea se tomará como un comentario
#   * Toda línea que no esté en blanco será el nombre exacto de la plantilla a impulsar, espacio de nombres, mayúsculas/minúsculas y todo, seguido por un caracter "|", un número y el carácter "%".
# Ejemplos de líneas correctas:
# Plantilla:Bueno|150%
# Plantilla:Muy muy bueno|300%
# Plantilla:Malo|50%
# Ejemplos de líneas que no funcionarán:
# Plantilla:Foo|150.234234% <-- no se permiten decimales!
# Foo|150% <--- técnicamente funciona, pero para transclusiones de la página Foo del espacio de nombres principal
# Puedes probar cambios en la configuración realizando una búsqueda que contenga como prefijo boost-templates:"XX", donde XX son todas las plantillas que quieras impulsar, separadas por espacios en lugar de saltos de línea.
# Búsquedas que especifiquen boost-templates:"XX" ignorarán el contenido de este campo.
 #</pre> <!-- deja esta línea exactamente como está -->',
	'cirrussearch-pref-label' => 'Búsqueda nueva',
	'cirrussearch-pref-desc' => 'Prueba nuestra nueva búsqueda que admite un mayor número de idiomas, ofrece resultados más actualizados hasta puede encontrar texto dentro de las plantillas.',
	'cirrussearch-file-contents-match' => 'Coincidencia en el contenido del archivo: $1',
);

/** Persian (فارسی)
 * @author Armin1392
 * @author Ebraminio
 */
$messages['fa'] = array(
	'cirrussearch-desc' => 'جستجوی قدرت‌گرفته از Elasticsearch برای مدیاویکی',
	'cirrussearch-backend-error' => 'ما نمی‌توانیم جستجویتان به دلیل یک مشکل موقت کامل کنیم. لطفاً بعداً دوباره تلاش کنید.',
	'cirrussearch-now-using' => 'این ویکی از یک موتور جستجوی جدید استفاده می‌کند.
([[mw:Special:MyLanguage/Help:CirrusSearch|Learn more]])',
	'cirrussearch-ignored-headings' => '#<!-- این صفحه را درست همانطور که هست رها کنید --> <pre>
#سر‌فصل‌هایی که توسط تحقیق نادیده گرفته خواهندشد.‌
#به محض اینکه صفحه با سرفصل، فهرست شده‌است،تغییرات متاثر می‌شود.
#شما می‌توانید با انجام یک ویرایش پوچ صفحه را وادار به دوباره فهرست کردن کنید.
#نحو به شرح زیر است:
#  *همه چیز از یک خصیصهٔ "#" گرفته تا آخر خط، یک نظر است
#  *هر خط بدون فاصله، عنوان دقیق برای نادیده گرفتن،موضوع و همه چیز منابع است
اتصالات خارجی
همچنین مشاهده کنید
#</pre> <!-- leave this line exactly as it is -->',
	'cirrussearch-boost-templates' => '#<!--این خط را همانطور که هست رها کنید--> <pre>
#اگز صفحه‌ای شامل یکی از این الگوها است سپس نتیجهٔ جستجو توسط درصد پیکربندی افزایش یافته‌است.
#فوراً تغییرات متأثر می‌شوند.
#نحو به شرح زیر است:
#   *همه‌ چیز از یک خصیصهٔ "#" گرفته تا آخر خط یک نظر است.
#   *هر خط بدون فاصله نام دقیق الگو برای افزایش، فضای نام،وضعیت و همه‌ چیز،توسط خصیصهٔ  "|"،توسط تعدادی،توسط خصیصهٔ "%" دنبال شده.
#مثال‌های خط‌های مطلوب:
#الگو:خوب|۱۵۰٪
#الگو:خیلی خیلی خوب|۳۰۰٪
#الگو:بد|۵۰٪
#مثال‌های خط های بدون کارایی:
#الگو:فو|۱۵۰.۲۳۴۲۳۴% <--هیچ نقطه‌ٔ اعشاری مجاز نیست!
#فو|۱۵۰٪ <--- اما برای ترنسکلوژن صفحهٔ فو از فضای نامی،به طور دقیق کار می‌کند.
#شما می‌توانید تغییرات پیکربندی را توسط انجام یک سوال عنوان شده با افزایش الگوها امتحان کنید:"ایکس‌ایکس" جایی که ایکس‌ایکس همهٔ الگوهایی است که می‌خواهید به طور مجزا توسط فاصله‌ها به جای شکستگی خط‌ها، افزایش یابد.
#سوالاتی که الگوهای افزایش یافته تعیین من‌کنند:"ایکس‌ایکس" محتویات این زمینه را رد می‌کنند.
#</pre> <!--این خط را همانطور که هست رها کنید-->',
	'cirrussearch-pref-label' => 'جستجوی جدید',
	'cirrussearch-pref-desc' => 'جستجوی جدید ما را که از تعداد بیشتر زبان‌ها پشتیبانی می‌کند،نتایج به روز بیشتری فراهم می‌کند، و حتی می‌تواند متن درون الگو را پیدا کند،امتحان کنید.',
	'cirrussearch-file-contents-match' => 'هماهنگی محتویات پرونده: $1',
);

/** Finnish (suomi)
 * @author Nike
 * @author Stryn
 */
$messages['fi'] = array(
	'cirrussearch-desc' => '"Elasticsearch"-käyttöinen haku MediaWikille',
	'cirrussearch-backend-error' => 'Emme voineet suorittaa hakuasi väliaikaisen ongelman vuoksi. Yritä myöhemmin uudelleen.',
	'cirrussearch-now-using' => 'Tämä wiki käyttää uutta hakukonetta. ([[mw:Special:MyLanguage/Help:CirrusSearch|Lue lisää]])',
	'cirrussearch-ignored-headings' => '#<!-- jätä tämä rivi sellaiseksi kuin se on --> <pre>
# Otsikot, jotka haku ohittaa.
# Muutokset tulevat voimaan heti, kun otsikon sivu indeksoidaan.
# Voit pakottaa sivun indeksoimisen tekemällä nollamuokkauksen.
# Syntaksi on seuraava:
#   * Kaikki "#"-merkistä rivin loppuun asti on kommenttia
#   * Kaikki ei-tyhjät rivit ovat otsikoita, jotka ohitetaan.
Lähteet
Aiheesta muualla
Katso myös
#</pre> <!-- jätä tämä rivi sellaiseksi kuin se on -->',
	'cirrussearch-pref-label' => 'Uusi haku',
);

/** French (français)
 * @author Gomoko
 * @author Jean-Frédéric
 * @author Linedwell
 * @author Robby
 */
$messages['fr'] = array(
	'cirrussearch-desc' => 'Fait effectuer la recherche par Solr',
	'cirrussearch-backend-error' => 'Nous n’avons pas pu mener à bien votre recherche à cause d’un problème temporaire. Veuillez réessayer ultérieurement.',
	'cirrussearch-parse-error' => 'La demande n’a pas été comprise. Veuillez la simplifier. La requête a été tracée pour améliorer le système de recherche.',
	'cirrussearch-now-using' => 'Ce wiki utilise un nouveau moteur de recherche. ([[mw:Special:MyLanguage/Help:CirrusSearch|en savoir plus]])',
	'cirrussearch-ignored-headings' => ' #<!-- laisser cette ligne comme telle --> <pre>
# Titres de sections qui seront ignorés par la recherche
# Les changements effectués ici prennent effet dès lors que la page avec le titre est indexée.
# Vous pouvez forcer la réindexation de la page en effectuant une modification vide
# La syntaxe est la suivante :
#   * Toute ligne précédée d’un « # » est un commentaire
#   * Toute ligne non-vide est le titre exact à ignorer, casse comprise
Références
Liens externes
Voir aussi
 #</pre> <!-- laisser cette ligne comme telle -->',
	'cirrussearch-boost-templates' => " #<!-- laisser cette ligne exactement en l’état --> <pre>
# Si une page contient un de ces modèles alors son score de recherche sera multiplié par un pourcentage configuré.
# Les modifications prennent effet immédiatement.
# La syntaxe est la suivante :
#   * Tout ce qui est entre un caractère '#' et la fin de la ligne est un commentaire
#   * Toute ligne non vide est le nom exact dEvery non-blank line is the exact d’un modèle à promouvoir, avec espace de noms, casse exacte et complète, suivi d’un caractère '|' suivi d’un nombre suivi d’un caractère '%'.
# Exemples de lignes correctes :
# Modèle:Bon|150%
# Modèle:Très Très bon|300%
# Modèle:Mauvais|50%
# Exemples de lignes non valides :
# Modèle:Foo|150.234234% <-- pas de décimale autorisée !
# Foo|150% <--- fonctionne techniquement, mais pour des inclusiosn de la page Foo depuis l’espace de noms principal
# Vous pouvez tester les modifications de configuration en effectuant une recherche préfixée par boost-templates:\"XX\" où XX est l’ensemble des modèles que vous voulez promouvoir, séparés par des espaces au lieu de sauts de ligne.
# Les requêtes qui spécifient boost-templates:\"XX\" ignorent le contenu de ce champ-ci.
 #</pre> <!-- laisser cette ligne exactement en l’état -->",
	'cirrussearch-pref-label' => 'Nouvelle recherche',
	'cirrussearch-pref-desc' => 'Essayer notre nouvelle recherche qui supporte un plus grand nombre de langues, fournit davantage de résultats à jour, et peut même trouver du texte dans les modèles.',
	'cirrussearch-file-contents-match' => 'Correspondance du contenu du fichier : $1',
);

/** Galician (galego)
 * @author Elisardojm
 * @author Toliño
 * @author Vivaelcelta
 */
$messages['gl'] = array(
	'cirrussearch-desc' => 'Procura baseada en Elasticsearch para MediaWiki',
	'cirrussearch-backend-error' => 'Non puidemos completar a súa procura debido a un problema temporal. Inténteo de novo máis tarde.',
	'cirrussearch-parse-error' => 'Non se entendeu a pescuda. Fágaa máis sinxela. Rexistrouse a pescuda para mellorar o sistema de procuras.',
	'cirrussearch-now-using' => 'Este wiki utiliza un novo motor de procuras. ([[mw:Special:MyLanguage/Help:CirrusSearch|Máis información]])',
	'cirrussearch-ignored-headings' => ' #<!-- Deixe esta liña tal e como está --> <pre>
# Cabeceiras que serán ignoradas nas buscas.
# Os cambios feitos aquí realízanse en canto se indexa a páxina coa cabeceira.
# Pode forzar o reindexado da páxina facendo unha edición baleira.
# A sintaxe é a seguinte:
#   * Todo o que vaia despois dun carácter "#" ata o final da liña é un comentario
#   * Toda liña que non estea en branco é o título exacto que ignorar, coas maiúsculas e minúsculas
Referencias
Ligazóns externas
Véxase tamén
 #</pre> <!-- Deixe esta liña tal e como está -->',
	'cirrussearch-boost-templates' => ' #<!-- Deixe esta liña tal e como está --> <pre>
# Se unha páxina contén un destes modelos, entón a súa puntuación de procura multiplícase pola porcentaxe configurada.
# Calquera cambio feito aplícase inmediatamente.
# A sintaxe é a seguinte:
#   * Todo o que vaia despois dun carácter "#" ata o final da liña é un comentario
#   * Toda liña que non estea en branco é o modelo exacto que promover, co espazo de nomes, coas maiúsculas e minúsculas e completo, seguido dun carácter "|", seguido dun número, seguido dun carácter "%".
# Exemplos de liñas correctas:
# Modelo:Ben|150%
# Modelo:Moi ben|300%
# Modelo:Mal|50%
# Exemplos de liñas que non funcionan:
# Modelo:Erro|150.234234% <-- non están permitidos os puntos ou comas decimais!
# Erro|150% <--- tecnicamente funciona, pero para as transclusións da páxina "Erro" desde o espazo de nomes principal
# Pode probar os cambios na configuración levando a cabo unha pescuda con boost-templates:"XX", onde XX son todos os modelos que quere promover, separados por espazos no canto de saltos de liña.
# As pescudas que especifican boost-templates:"XX" ignoran os contidos deste campo.
 #</pre> <!-- Deixe esta liña tal e como está -->',
	'cirrussearch-pref-label' => 'Nova pescuda',
	'cirrussearch-pref-desc' => 'Probe o noso novo buscador, que soporta un maior número de linguas, proporciona resultados máis actulizados e mesmo pode atopar texto dentro dos modelos.',
	'cirrussearch-file-contents-match' => 'Coincidencia cos contidos do ficheiro: $1',
);

/** Hebrew (עברית)
 * @author Amire80
 */
$messages['he'] = array(
	'cirrussearch-desc' => 'חיפוש במדיה־ויקי באמצעות Elasticsearch',
	'cirrussearch-backend-error' => 'לא הצלחנו להשלים את החיפוש שלך בשל בעיה זמנית. נא לנסות שוב מאוחר יותר.',
	'cirrussearch-parse-error' => 'השאילתה לא הייתה ברורה. נא לפשט אותה. השאילתה נרשמה ביומן כדי לשפר את מערכת החיפוש.',
	'cirrussearch-now-using' => 'הוויקי הזה משתמש במנוע חיפוש חדש. ([[mw:Special:MyLanguage/Help:CirrusSearch|מידע נוסף]])',
	'cirrussearch-ignored-headings' => ' #<!-- leave this line exactly as it is --> <pre>
# כותרות של פסקאות שהחיפוש יתעלם מהן
# שינויים כאן ייכנסו לתוקף כשדף עם הכותרת הזאת ייכנס לאינדקס החיפוש
# אפשר לכפות הכנסה מחדש לאינדקס על־ידי עשיית עריכה אפסית
# התחביר הוא
#   * כל דבר שמתחילת בתו # ועד סוף השורה הוא הערה
#   * כל שורה שאינה ריקה היא כותרת שיש להתעלם ממנה, כולל רישיות האותיות וכיו"ב
הערות שוליים
קישורים חיצוניים
לקריאה נוספת
 #</pre> <!-- leave this line exactly as it is -->',
	'cirrussearch-pref-label' => 'חיפוש חדש',
	'cirrussearch-pref-desc' => 'נסו את החיפוש החדש שלנו, שתומך ביותר שפות, מספק תוצאות עדכניות יותר ואפילו מוצא טקסט בתוך תבניות.',
	'cirrussearch-file-contents-match' => 'תוכן הקבצים תואם: $1',
);

/** Upper Sorbian (hornjoserbsce)
 * @author Michawiki
 */
$messages['hsb'] = array(
	'cirrussearch-desc' => 'Pytanje na zakładźe "elasticsearch" za MediaWiki',
	'cirrussearch-backend-error' => 'Twoje pytanje njeda so nachwilneho problema dla skónčić. Prošu spytaj pozdźišo hišće raz.',
	'cirrussearch-now-using' => 'Tutón wiki wužiwa nowu pytawu. ([[mw:Special:MyLanguage/Help:CirrusSearch|Dalše informacije]])',
	'cirrussearch-ignored-headings' => ' #<!-- njezměń tutu linku --> <pre>
# Nadpisma, kotrež pytanje ignoruje.
# Tute změny budu so wuskutkować, po tym zo strona bě so indikowała.
# Móžeš indikowanje stronow wunuzować, přewjedujo prózdnu změnu.
# Syntaksa:
#   * Wšitko, štož znamješku "#" hač do kónca linki slěduje, je komentar
#   * Kózda njeprózdna linka je eksaktny titul, kotryž dyrbi so ignorować
Žórła
Eksterne wotkazy
Hlej tež
 #</pre> <!-- njezměń tutu linku -->',
	'cirrussearch-boost-templates' => ' #<!-- Njezměń tutu linku. --> <pre>
# Jeli strona wobsahuje jednu z tutych předłohow, budźe so pytanske pohódnoćenje z konfigurowanej procentowej sadźbu multiplikować.
# Změny so hnydom wuskutkuja.
# Syntaksa:
#   * Wšitko za znamješkom „#“ hač do kónca linki je komentar.
#   * Kóžda njeprózdna linka je eksaktne mjeno předłohi, kotraž ma so zesylnić, z mjenowym rumom, wulkopisanjom, haj wšitko, slědowane přez znamješko "|", ličbu a znamješko "%".
# Přikłady fungowacych linkow:
# Předłoha:Dobry|150%
# Předłoha:Jara dobry|300%
# Předłoha:Špatny|50%
# Přikłady njefungowacych linkow:
# Předłoha:Foo|150.234234% <-- Decimalne městna dowolene njejsu!
# Foo|150% <-- Technisce móžno, ale za zapřijimanja strony "Foo" z hłowneho mjenoweho ruma.
# Móžeš konfiguraciju přez naprašowanje z prefiksom boost-templates:"XX"" testować, při čimž XX za wšě předłohi steji, kotrež chceš optimizować, dźělene přez mjezeru město łamanja linki.
# Naprašowanja z boost-templates:"XX" ignoruja wobsah tutoho pola.
 #</pre> <!-- Njezměń tutu linku. -->',
	'cirrussearch-pref-label' => 'Nowe pytanje',
	'cirrussearch-pref-desc' => 'Spytaj naše nowe pytanje, kotrež podpěruje wjetšu ličbu rěčow, podawa bóle aktualne wuslědki a móže samo tekst znutřka předłohow namakać.',
	'cirrussearch-file-contents-match' => 'Wotpowědnik datajoweho wobsaha: $1',
);

/** Interlingua (interlingua)
 * @author McDutchie
 */
$messages['ia'] = array(
	'cirrussearch-desc' => 'Recerca pro MediaWiki actionate per Elasticsearch',
	'cirrussearch-backend-error' => 'Un problema temporari ha impedite le completion del recerca. Per favor reproba plus tarde.',
	'cirrussearch-now-using' => 'Iste wiki usa un nove motor de recerca. ([[mw:Special:MyLanguage/Help:CirrusSearch|Leger plus]])',
	'cirrussearch-ignored-headings' => ' #<!-- non modificar in alcun modo iste linea --> <pre>
# Titulos de sectiones que essera ignorate per le recerca.
# Cambiamentos in isto habera effecto post le indexation del paginas con iste sectiones.
# Tu pote fortiar le re-indexation de un pagina per medio de un modification nulle.
# Le syntaxe es:
#   * Toto a partir de un character "#" usque al fin del linea es un commento
#   * Cata linea non vacue es un titulo exacte a ignorar, con distinction inter majusculas e minusculas
Referentias
Ligamines externe
Vide etiam
 #</pre> <!-- non modificar in alcun modo iste linea -->',
	'cirrussearch-boost-templates' => ' #<!-- non modificar in alcun modo iste linea --> <pre>
# Si un pagina contine un de iste patronos alora su punctage de recerca es multiplicate per le percentage configurate.
# Cambios a isto essera effective immediatemente.
# Le syntaxe es le sequente:
#   * Toto ab un character "#" al fin del linea es un commento
#   * Cata linea non vacue debe continer le nomine exacte del patrono a promover, incluse le spatio de nomines, majusculas e minusculas correcte e toto altere, sequite per un character "|", un numero e un character "%".
# Exemplos de bon lineas:
# Patrono:Bon|150%
# Patrono:Multo multo bon|300%
# Patrono:Mal|50%
# Exemplos de lineas que non functiona:
# Patrono:Exemplo|150.234234% <-- decimales non permittite!
# Exemplo|150% <--- functiona technicamente, ma solo pro transclusiones del pagina Exemplo ab le spatio de nomines principal
# Pro testar le cambios de configuration, exeque un recerca prefixate con boost-templates:"XX" ubi XX representa tote le patronos que tu vole promover, separate per spatios e non saltas de linea.
# Le recercas que specifica boost-templates:"XX" ignora le contento de iste campo.
 #</pre> <!-- non modificar in alcun modo iste linea -->',
	'cirrussearch-pref-label' => 'Nove recerca',
	'cirrussearch-pref-desc' => 'Essaya nostre nove motor de recerca que supporta un numero major de linguas, forni resultatos plus actual e pote mesmo cercar texto intra patronos.',
	'cirrussearch-file-contents-match' => 'Contento del file correspondente: $1',
);

/** Italian (italiano)
 * @author Beta16
 * @author Rosh
 */
$messages['it'] = array(
	'cirrussearch-desc' => 'Ricerca realizzata con Elasticsearch per MediaWiki',
	'cirrussearch-backend-error' => 'Non si è riuscito a completare la tua ricerca a causa di un problema temporaneo. Riprova più tardi.',
	'cirrussearch-parse-error' => 'Non è possibile interpretare la query. Si prega di renderla più semplice. La query è stata registrata per migliorare il sistema di ricerca.',
	'cirrussearch-now-using' => 'Questo wiki usa un nuovo motore di ricerca. ([[mw:Special:MyLanguage/Help:CirrusSearch|Ulteriori informazioni]])',
	'cirrussearch-ignored-headings' => ' #<!-- lascia questa riga esattamente come è --> <pre>
# Elenco delle intestazioni che saranno ignorate dalla ricerca.
# Le modifiche a questa pagina saranno effettive non appena la pagina sarà indicizzata.
# Puoi forzare la re-indicizzazione di una pagina effettuando una modifica nulla.
# La sintassi è la seguente:
#   * Tutto dal carattere "#" alla fine della riga è un commento
#   * Tutte le righe non vuote sono le intestazioni esatte da ignorare, maiuscolo/minuscolo e tutto
Note
Voci correlate
Collegamenti esterni
 #</pre> <!-- lascia questa riga esattamente come è -->',
	'cirrussearch-boost-templates' => ' #<!-- lascia questa riga esattamente come è --> <pre>
# Se una pagina contiene uno dei seguenti template, allora il suo punteggio di ricerca è moltiplicato per la percentuale indicata.
# Le modifiche a questa pagina saranno effettive immediatamente.
# La sintassi è la seguente:
#   * Tutto dal carattere "#" alla fine della riga è un commento
#   * Tutte le righe non vuote sono i template esatti da modificare, namespace, maiuscolo/minuscolo e tutto, seguiti da un carattere "|", da un numero, e da un carattere "%".
# Esempi di righe corrette:
# Template:Buono|150%
# Template:Molto molto buono|300%
# Template:Male|50%
# Esempi di righe errate:
# Template:Prova|150.234234% <-- non sono consentiti decimali!
# Prova|150% <--- tecnicamente funziona, ma per inclusioni della pagina "Prova" dal namespace principale
# Puoi provare le modifiche alla configurazione eseguendo una ricerca inserendo il prefisso boost-templates:"XX" dove XX sono tutti i template da modificare, separati da uno spazio.
# Le ricerche con boost-templates:"XX" ignorano il contenuto di questa pagina.
 #</pre> <!-- lascia questa riga esattamente come è -->',
	'cirrussearch-pref-label' => 'Nuova ricerca',
	'cirrussearch-pref-desc' => "Prova la nostra nuova ricerca, che supporta un numero maggiore di lingue, fornisce risultati più aggiornati e può anche trovare il testo all'interno di template.",
	'cirrussearch-file-contents-match' => 'Contenuto del file corrispondente: $1',
);

/** Japanese (日本語)
 * @author Fryed-peach
 * @author Shirayuki
 */
$messages['ja'] = array(
	'cirrussearch-desc' => 'MediaWiki 用の Elasticsearch 検索',
	'cirrussearch-backend-error' => '一時的な問題により検索を実行できませんでした。後でやり直してください。',
	'cirrussearch-parse-error' => 'クエリを理解できませんでした。より単純なものにしてください。検索システムの改善のため、クエリを記録しました。',
	'cirrussearch-now-using' => 'このウィキでは新しい検索エンジンを使用しています。([[mw:Special:MyLanguage/Help:CirrusSearch|詳細]])',
	'cirrussearch-pref-label' => '新規検索',
	'cirrussearch-pref-desc' => '数多くの言語に対応、より新しい検索結果を提供、テンプレート内のテキストも検索可能、という特徴がある新しい検索を試用',
	'cirrussearch-file-contents-match' => 'ファイルの内容との一致: $1',
);

/** Korean (한국어)
 * @author Hym411
 * @author Priviet
 * @author 아라
 */
$messages['ko'] = array(
	'cirrussearch-desc' => '미디어위키를 위한 Elasticsearch가 공급하는 검색',
	'cirrussearch-backend-error' => '일시적인 문제 때문에 검색을 완료할 수 없습니다. 나중에 다시 시도하세요.',
	'cirrussearch-now-using' => '이 위키는 새로운 검색 엔진을 사용합니다. ([[mw:Special:MyLanguage/Help:CirrusSearch|더 알아보기]])',
	'cirrussearch-ignored-headings' => ' #<!-- 이 줄은 그대로 두십시오 --> <pre>
# 검색에서 무시되는 문단 제목입니다.
# 이 문서에 대한 바뀜은 즉시 문단 제목으로 된 문서가 다시 색인됩니다.
# null 편집을 하여 문서 다시 색인을 강제할 수 있습니다.
# 문법은 다음과 같습니다:
#   * "#" 문자에서 줄의 끝까지는 주석입니다
#   * 빈 줄이 아닌 줄은 무시할 정확한 제목이며, 대소문자를 무시합니다
참고
참조
출처
바깥 링크
바깥 고리
같이 보기
함께 보기
 #</pre> <!-- 이 줄은 그대로 두십시오 -->',
	'cirrussearch-boost-templates' => ' #<!-- 이 줄은 그대로 남겨두세요 --> <pre>
#  문서가 이 틀 중 하나를 포함하고 있다면 검색 점수에 설정된 비율이 곱해질 것입니다. 
# 이에 대한 바뀜은 나타날 것입니다.
# 구문은 다음과 같습니다:
#   * "#" 문자에서 마지막 줄까지 다 주석입니다.
#   * 공백이 아닌 모든 줄은 "|" 문자가 뒤에 붙고 그뒤에 "%" 문자가 뒤따라 붙는 부스트, 이름 공간, 케이스와 모든 것에 대한 정확한 틀 이름입니다.
# 좋은 줄의 예:
# 틀:좋음|150%
# 틀:아주 아주 좋음|300%
# 틀:나쁨|50%
# 작동되지 않는 틀의 예:
# 틀:가나다|150.234234% <-- 소수점은 인정되지 않습니다!
# 가나다|150% <--- 기술적으로 작동은 하나, 일반 이름공간의 가나다 문서의 끼워넣기용입니다
# 부스트 틀들을 접두어로 하는 쿼리를 실행하여 설정변경을 시험해볼 수 있습니다:  줄 개행(엔터) 대신 간격(스페이스 바)으로 구분되며, XX는 부스트 하려는 모든 틀들 입니다. 
# 부스트 틀을 특정하는 쿼리: "XX"는 이 필드의 내용을 무시합니다.
 #</pre> <!-- 이 줄은 그대로 남겨두세요 -->',
	'cirrussearch-pref-label' => '새 검색',
	'cirrussearch-pref-desc' => '언어의 더 많은 수를 지원하고, 더 최신의 결과를 제공하고, 심지어 틀의 안쪽의 텍스트를 찾을 수 있는 우리의 새 검색을 시도합니다.',
	'cirrussearch-file-contents-match' => '파일 내용 일치: $1',
);

/** Colognian (Ripoarisch)
 * @author Purodha
 */
$messages['ksh'] = array(
	'cirrussearch-desc' => 'Söhke em MedijaWikki met <i lang="en" xml:lang="en">Elasticsearch</i> dohenger.',
	'cirrussearch-backend-error' => 'Mer hatte e problem, wat ävver flök verbei sin sullt. Bes esu jood u versöhg et schpääder norr_ens.',
	'cirrussearch-now-using' => 'Heh dat Wikke hädd_en neu Söhkmaschiin. ([[mw:Special:MyLanguage/Help:CirrusSearch|Mieh drövver lässe]])',
	'cirrussearch-pref-label' => 'Et neue Söhke',
	'cirrussearch-pref-desc' => 'Probeer ons neu Projrammdeil zum Söhke. Et kann met mieh Schprohche ömjonn, brängk flöker un neue Antwoote un kann esujaa Täx em Ennere vun Schablohne fenge.',
	'cirrussearch-file-contents-match' => 'Der Enhalld vun dä Dattei paß: $1',
);

/** Luxembourgish (Lëtzebuergesch)
 * @author Robby
 */
$messages['lb'] = array(
	'cirrussearch-desc' => 'Elasticsearch-Sichfonctioun fir MediaWiki',
	'cirrussearch-backend-error' => 'Mir konnten Är Sich wéint engem temporäre Problem net maachen. Probéiert w.e.g. méi spéit nach eng Kéier.',
	'cirrussearch-now-using' => 'Dës Wiki benotzt eng nei Sichmaschinn.([[mw:Special:MyLanguage/Help:CirrusSearch|Fir méi ze wëssen]])',
	'cirrussearch-ignored-headings' => " #<!-- dës Zeil net änneren --> <pre>
# Iwwerschrëften, déi vun der Sich ignoréiert ginn.
# Dës Ännerunge gi wirksam, soubal déi Säit mat der Iwwerschrëft indexéiert gouf.
# Dir kënnt déi Säitenindexéierung erzwéngen, andeem dir eng Nullännerung maacht.
# Syntax:
# * Alles, wat no enger Raut („#“) bis zum Ënn vun der Zeil steet, ass eng Bemierkung.
# * All net-eidel Zeil ass de geneeën Titel fir z'ignoréieren.
Referenzen
Weblinken
Kuckt och
 #</pre> <!-- dës Zeil net änneren -->",
	'cirrussearch-pref-label' => 'Nei sichen',
);

/** Macedonian (македонски)
 * @author Bjankuloski06
 */
$messages['mk'] = array(
	'cirrussearch-desc' => 'Пребарување со Solr',
	'cirrussearch-backend-error' => 'Не можам наполно да го изведам пребарувањето поради привремен проблем. Обидете се подоцна.',
	'cirrussearch-parse-error' => 'Не го разбрав барањето. Упростете го. Ова го заведувам за да го подобриме пребарувачкиот систем.',
	'cirrussearch-now-using' => 'Ова вики користи нов пребарувач. ([[mw:Special:MyLanguage/Help:CirrusSearch|Дознајте повеќе]])',
	'cirrussearch-ignored-headings' => ' #<!-- не менувајте ништо во овој ред --> <pre>
# Заглавија што ќе се занемарат при пребарувањето.
# Измените во ова ќе стапат на сила штом ќе се индексира страницата со заглавието.
# Можете да наметнете преиндексирање на страницата ако извршите празно уредување.
# Синтаксата е следнава:
#   * Сето она што од знакот „#“ до крајот на редот е коментар
#   * Секој непразен ред е точниот наслов што треба да се занемари, разликувајќи големи од мали букви и сето останато
Наводи
Надворешни врски
Поврзано
 #</pre> <!-- не менувајте ништо во овој ред -->',
	'cirrussearch-boost-templates' => ' #<!-- не менувајте го овој ред --> <pre>
# Ако една страница содржи еден од овие шаблони, тогаш добиеното салдо од пребарувањето се множи со зададениот постоток.
# Измените во ова веднаш стапуваат на сила.
# Синтаксата е следнава:
#   * Сето она што стои од знакот „#“ до крајот на редот е коментар
#   * Секој непразен ред претставува точно има на шаблонот за оптимизирање (сосе именскиот простор, запазени големи/мали букви и сето останато), проследено од знакот „|“, па број, па знакот „%“.
# Примери за редови што работат:
# Шаблон:Добро|150%
# Шаблон:Многу добро|300%
# Шаблон:Лошо|50%
# Примери за редови што не би работеле:
# Шаблон:Foo|150.234234% <-- не се дозволени децимали!
# Foo|150% <--- технички работи, но за превметнување на страницата „Foo“ од главниот именски простор
# Можете да ги испробате измените во поставките извршувајќи барање со претставката boost-templates:„XX“, каде XX се сите шаблони што сакате да ги оптимизирате одделени со празни места наместо нови редови.
# Барањата што укажуваат boost-templates:„XX“ ја занемаруваат содржината на ова поле.
 #</pre> <!-- не менувајте го овој ред -->',
	'cirrussearch-pref-label' => 'Ново пребарување',
	'cirrussearch-pref-desc' => 'Пробајте го нашето ново пребарување кое поддржува поголем број јазици, дава потековни и понавремени резултати, па дури и наоѓа текст во шаблони.',
	'cirrussearch-file-contents-match' => 'Совпадната содржина на податотеката: $1',
);

/** Marathi (मराठी)
 * @author V.narsikar
 */
$messages['mr'] = array(
	'cirrussearch-pref-label' => 'नविन शोध',
);

/** Malay (Bahasa Melayu)
 * @author Anakmalaysia
 */
$messages['ms'] = array(
	'cirrussearch-desc' => 'Enjin pencarian yang dikuasakan oleh Elasticsearch untuk MediaWiki',
	'cirrussearch-backend-error' => 'Kami tidak dapat melengkapkan pencarian anda disebabkan masalah yang sementara. Sila cuba lagi nanti.',
);

/** Dutch (Nederlands)
 * @author Bluyten
 * @author Breghtje
 * @author Romaine
 * @author Siebrand
 * @author Sjoerddebruin
 */
$messages['nl'] = array(
	'cirrussearch-desc' => 'Zoeken via Solr',
	'cirrussearch-backend-error' => 'Als gevolg van een tijdelijk probleem kon uw zoekopdracht niet worden voltooid. Probeer het later opnieuw.',
	'cirrussearch-now-using' => 'Deze wiki maakt gebruik van een nieuwe zoekmachine. ([[mw:Special:MyLanguage/Help:CirrusSearch|Meer lezen]])',
	'cirrussearch-ignored-headings' => ' #<!-- leave this line exactly as it is --> <pre>
# Koppen die worden genegeerd tijdens het zoeken.
# Wijzigingen worden van kracht als een kop wordt geïndexeerd.
# U kunt opnieuw indexeren afdwingen door het uitvoeren van een nullbewerking.
# De syntaxis is al volgt:
#   * All tekst vanaf het teken "#" tot het einde van de regel wordt gezien als een opmerking;
#   * Iedere niet-lege regel is de precieze te negeren kop, inclusief hoofdlettergebruik en degelijke.
Referenties
Externe links
Zie ook
 #</pre> <!-- leave this line exactly as it is -->',
	'cirrussearch-pref-label' => 'Nieuwe zoekopdracht',
	'cirrussearch-pref-desc' => 'Probeer de nieuwe zoekfunctie die een groter aantal talen ondersteunt, meer recente zoekresultaten geeft, en zelfs tekst in sjablonen kan vinden.',
	'cirrussearch-file-contents-match' => 'Gevonden in de bestandsinhoud: $1',
);

/** Occitan (occitan)
 * @author Cedric31
 */
$messages['oc'] = array(
	'cirrussearch-desc' => 'Fa efectuar la recèrca per Solr',
	'cirrussearch-backend-error' => 'Avèm pas pogut menar corrèctament vòstra recèrca a causa d’un problèma temporari. Ensajatz tornarmai ulteriorament.',
);

/** Polish (polski)
 * @author Chrumps
 * @author Tar Lócesilion
 */
$messages['pl'] = array(
	'cirrussearch-pref-label' => 'Nowe wyszukiwanie',
	'cirrussearch-pref-desc' => 'Wypróbuj naszą nową wyszukiwarkę, która obsługuje większą liczbę języków, podaje bardziej aktualne wyniki wyszukiwania, a nawet umożliwia odnalezienie tekstu wewnątrz szablonów.',
);

/** Portuguese (português)
 * @author Vitorvicentevalente
 */
$messages['pt'] = array(
	'cirrussearch-desc' => 'Mecanismo de procura "Elasticsearch" para o MediaWiki',
	'cirrussearch-backend-error' => 'Não foi possível completar a sua pesquisa devido a um problema temporário. Por favor, tente novamente mais tarde.',
	'cirrussearch-now-using' => 'Esta wiki está a utilizar um novo motor de busca.
([[mw:Special:MyLanguage/Help:CirrusSearch|Saiba mais]])',
	'cirrussearch-pref-label' => 'Nova procura',
	'cirrussearch-pref-desc' => 'Experimente a nossa nova forma de pesquisa que suporta um maior número de idiomas, fornece resultados mais actualizados e pode ainda encontrar o texto de predefinições.',
	'cirrussearch-file-contents-match' => 'Conteúdos de ficheiros correspondentes: $1',
);

/** Brazilian Portuguese (português do Brasil)
 * @author Jaideraf
 */
$messages['pt-br'] = array(
	'cirrussearch-desc' => "Mecanismo de busca ''Elasticsearch'' para MediaWiki",
	'cirrussearch-backend-error' => 'Não foi possível completar a busca devido a um problema temporário. Por favor, tente novamente mais tarde.',
	'cirrussearch-now-using' => 'Este wiki está utilizando um novo mecanismo de busca. ([[mw:Special:MyLanguage/Help:CirrusSearch|Learn more]])',
	'cirrussearch-ignored-headings' => ' #<!-- deixe esta linha exatamente como está --> <pre>
# Subtítulos que serão ignorados pela busca.
# Mudanças feitas aqui têm efeito quando a página com o subtítulo é indexada.
# Você pode forçar a reindexação realizando uma edição nula.
# A sintaxe é a seguinte:
#   * Tudo a partir do caractere "#", até o final da linha, é um comentário
#   * Cada linha não vazia é o título exato a ser ignorado, inclusive no uso de maiúsculas
Referências
Ligações externas
Ver também
 #</pre> <!-- deixe esta linha exatamente como está -->',
	'cirrussearch-pref-label' => 'Nova busca',
	'cirrussearch-pref-desc' => 'Utilizar o novo mecanismo de busca que indexa predefinições, suporta mais idiomas e atualiza mais rápido.',
);

/** tarandíne (tarandíne)
 * @author Joetaras
 */
$messages['roa-tara'] = array(
	'cirrussearch-desc' => 'Ricerche Elasticsearch-powered pe MediaUicchi',
	'cirrussearch-backend-error' => "Non ge putime combletà 'a ricerca toje pe 'nu probbleme tembonarèe. Pe piacere pruève cchiù tarde.",
	'cirrussearch-ignored-headings' => " #<!-- lasse sta linèe accume ste --> <pre>
# Testate ca avène scettate jndr'à le ricerche.
# Le cangiaminde devendane effettive quanne 'a pàgene avène indicizzate.
# Tu puè forzà 'a reindicizzazzione d'a pàgene facenne 'nu cangiamende vecande.
# 'A sindasse jè 'a seguende:
#   * Ogneccose da 'u carattere \"#\" 'nzigne a fine d'a linèe jè 'nu commende
#   * Ogne linèa chiene jè 'u titole esatte da ignorà, case e ogneccose
Refereminde
Collegaminde de fore
'Ndruche pure
 #</pre> <!-- lasse sta linèe accume ste -->",
);

/** Russian (русский)
 * @author Okras
 */
$messages['ru'] = array(
	'cirrussearch-desc' => 'Поиск для MediaWiki на базе Elasticsearch',
	'cirrussearch-backend-error' => 'Нам не удалось завершить поиск из-за временной проблемы. Пожалуйста, повторите попытку позже.',
	'cirrussearch-parse-error' => 'Запрос не был понят. Пожалуйста, сделайте его проще. Запрос был записан для улучшения поисковой системы.',
	'cirrussearch-now-using' => 'Эта вики использует новый поисковый движок. ([[mw:Special:MyLanguage/Help:CirrusSearch|Подробнее]])',
	'cirrussearch-ignored-headings' => ' #<!-- оставьте эту строку как есть --> <pre>
# Заголовки, которые будут игнорироваться поиском.
# Изменения вступают в силу, как только страница с заголовком индексируется.
# Вы можете принудить переиндексировать страницу с помощью нулевой правки.
# Синтаксис выглядит следующим образом:
#   * Всё, начинающееся на символ «#» и до конца строки является комментарием
#   * Каждая непустая строка является точным названием того, что будет игнорироваться, включая регистр и прочее
Примечания
Ссылки
См. также
 #</pre> <!-- оставьте эту строку как есть -->',
	'cirrussearch-boost-templates' => ' #<!-- оставьте эту строку как есть --> <pre>
# Если страница содержит один из этих шаблонов, её вес при поиске умножается на указанный процент.
# Изменения вступают в силу немедленно.
# Синтаксис выглядит следующим образом:
# * Всё, что начинается с символа «#» (и до конца строки) является комментарием
# * Каж̠дая непустая строка — это точное имя шаблона для повышения веса с указанием пространства имён, с последующим символом «|», за которым следует число со знаком «%».
# Примеры правильных строк:
# Template:Good|150%
# Template:Very Very Good|300%
# Template:Bad|50%
# Примеры неправильных строк:
# Template:Foo|150.234234% <-- десятичный разделитель недопустим!
# Foo|150% <--- технически это работает, но только для включений страницы Foo из основного пространства имён
# Вы можете протестировать изменение настроек, выполнив запрос с префиксом boost-templates:"XX", где XX — это шаблоны, которые вы хотите использовать, разделенных пробелами вместо символов разрыва строки.
# Запросы, которые определяют boost-templates:"XX", игнорируют содержимое этого поля.
 #</pre> <!-- оставьте эту строку как есть -->',
	'cirrussearch-pref-label' => 'Новый поиск',
	'cirrussearch-pref-desc' => 'Попробуйте наш новый поиск, который поддерживает большее количество языков, предоставляет более свежие результаты, и может даже найти текст внутри шаблонов.',
	'cirrussearch-file-contents-match' => 'Содержимое файла совпадает: $1',
);

/** Slovak (slovenčina)
 * @author Sudo77(new)
 */
$messages['sk'] = array(
	'cirrussearch-backend-error' => 'Kvôli dočasnému problému sme nemohli dokončiť požadované vyhľadávanie. Skúste to znovu neskôr.',
);

/** Swedish (svenska)
 * @author Bengt B
 * @author Jopparn
 * @author Lokal Profil
 * @author WikiPhoenix
 */
$messages['sv'] = array(
	'cirrussearch-desc' => 'Elasticsearch-driven sökning för Mediawiki',
	'cirrussearch-backend-error' => 'Vi kunde inte slutföra din sökning på grund av ett tillfälligt problem. Försök igen senare.',
	'cirrussearch-now-using' => 'Denna wiki använder en ny sökmotor ([[mw:Special:MyLanguage/Help:CirrusSearch|Läs mer]])',
	'cirrussearch-ignored-headings' => '#<!-- leave this line exactly as it is --> <pre>
 # Rubriker som kommer att ignoreras av sökningen.
 # Ändringar till detta kommer att gälla så fort sidan med rubriken är indexerad.
 # Du kan tvinga sidan omindexera genom att göra en null redigering.
 # syntaxen är då följande:
 #   * Allt från ett "#" tecken till slutet av raden är en kommentar
 #   * Varje icke-tom rad är den exakta titeln som kommer att ignoreras, shiftläge och allt
Referenser
Externa länkar
Se också
 #</pre> <!-- leave this line exactly as it is -->',
	'cirrussearch-boost-templates' => ' #<!-- lämna denna rad precis som den är --> <pre>
# Om en sida innehåller en av följande mallar multipliceras dess sökbetyg med den konfigurerade procentsatsen.
# Ändringar till detta träder i kraft med omedelbar verkan.
# Syntaxen är följande:
#   * Alt efter en "#"-tecknet till slutet på raden är en kommentar
#   * Var icke-tom rad är exakt det mallnamn som ska förstärkas, namnrymd, versalisering och allt, följt av ett "|"-tecken, följt av ett nummer, följt av ett "%"-tecken.
# Exempel på välformaterade rader:
# Mall:Bra|150%
# Mall:Väldigt Väldigt bra|300%
# Mall:Dålig|50%
# Exempel på ogiltiga rader:
# Mall:Foo|150.234234% <-- decimaltal tillåts inte!
# Foo|150% <--- fungerar tekniskt sett men för mallinkluderingar av Foo-sidan i huvudnamnrymden
# Du kan testa konfigurationsändringar genom att utföra en förfrågan med boost-templates:"XX" där XX är alla de mallar du önskar förstärka, separerade med blanksteg istället för radbrytningar.
# Förfrågor som anger boost-templates:"XX" ignorerar innehållet av detta fält.
 #</pre> <!-- lämna denna rad precis som den är -->',
	'cirrussearch-pref-label' => 'Ny sökning',
	'cirrussearch-pref-desc' => 'Prova vår nya sökning som stöder ett större antal språk, ger fler uppdaterade resultat och kan även hitta text inuti mallar.',
	'cirrussearch-file-contents-match' => 'Filinnehållsträff: $1',
);

/** Tagalog (Tagalog)
 * @author Jewel457
 */
$messages['tl'] = array(
	'cirrussearch-parse-error' => 'Ang pagtatanong ay hindi naintindihan. Mangyaring gawin itong payak. Ang pagtatanong ay naitala upang pag-ibayuhin ang paraan ng paghahanap.',
);

/** Ukrainian (українська)
 * @author Andriykopanytsia
 * @author Ата
 */
$messages['uk'] = array(
	'cirrussearch-desc' => 'Вмикає пошук з допомогою Solr',
	'cirrussearch-backend-error' => 'Нам не вдалося завершити ваш пошук через тимчасову проблему. Спробуйте ще раз пізніше.',
	'cirrussearch-parse-error' => 'Запит не зрозуміли. Будь ласка, зробіть його простішим. Запит був записаний для поліпшення пошукової системи.',
	'cirrussearch-now-using' => 'Це вікі використовує новий пошуковий рушій. ([[mw:Special:MyLanguage/Help:CirrusSearch|Докладніше]])',
	'cirrussearch-ignored-headings' => ' #<!-- залиште цей рядок точно таким, яким він є --> <pre>
# Заголовки, які будуть ігноруватися при пошуці.
# Зміни, які набирають сили при індексуванні сторінки з заголовком.
# Ви можете примусити переіндексувати сторінку з нульовим редагуванням.
# Синтаксис наступний:
#   * Усе, що починається з символу "#" до кінця рядка, є коментарем
#   * Кожний непорожній рядок є точним заголовком для ігнорування
Посилання
Зовнішні посилання
Див. також
 #</pre> <!-- залиште цей рядок точно таким, яким він є -->',
	'cirrussearch-boost-templates' => ' #<!-- залиште цей рядок таким, яким він є --> <pre>
# Якщо сторінка містить один із цих шаблонів, то оцінка пошуку множиться на налаштований відсоток.
# Зміни цього вступають у силу негайно.
# Синтаксис виглядає наступним чином:
#   * Усе від символу "#" до кінця рядка є коментарем
#   * Кожний непорожній рядок - це точна назва шаблону для завантаження, простору імен, справи та і усього, після якого слідує символ "|", число та символ "%".
# Взірці вірних рядків:
# Шаблон:Добрий|150%
# Шаблон:Дуже дуже добрий|300%
# Шаблон:Поганий|50%
# Взірці непрацюючих рядків:
# Шаблон:Foo|150.234234% <-- десяткова крапка чи кома не дозволені!
# Foo|150% <--- технічно працює, але для включень сторінки Foo із головного простору імен
# Ви можете тестувати зміни конфігурації, виконавши запит із префіксом boost-templates:"XX", де XX - це усі шаблони, які ви хочете завантажити, відокремлені пробілами замість розривів рядків.
# Запити, які визначають boost-templates:"XX" ігнорувати вміст цього поля.
 #</pre> <!-- залиште цей рядок точно таким, яким він є -->',
	'cirrussearch-pref-label' => 'Новий пошук',
	'cirrussearch-pref-desc' => 'Спробуйте наш новий пошук, який підтримує більше число мов, надає більше оновлених результатів і навіть може шукати текст всередині шаблону.',
	'cirrussearch-file-contents-match' => 'Збіг вмісту файлу: $1',
);

/** Vietnamese (Tiếng Việt)
 * @author Minh Nguyen
 */
$messages['vi'] = array(
	'cirrussearch-desc' => 'Công cụ tìm kiếm Elasticsearch dành cho MediaWiki',
	'cirrussearch-backend-error' => 'Không thể hoàn tất truy vấn của bạn vì một vấn đề tạm thời. Xin vui lòng thử lại sau.',
	'cirrussearch-parse-error' => 'Không hiểu rõ truy vấn. Xin hãy làm nó đơn giản hơn. Truy vấn này được ghi vào nhật trình để giúp cải thiện công cụ tìm kiếm.',
	'cirrussearch-now-using' => 'Wiki này đang sử dụng một công cụ tìm kiếm mới. ([[mw:Special:MyLanguage/Help:CirrusSearch|Tìm hiểu thêm]])',
	'cirrussearch-ignored-headings' => ' #<!-- để yên dòng này --> <pre>
# Công cụ tìm kiếm sẽ bỏ qua các đề mục này.
# Các thay đổi trên danh sách này sẽ có hiệu lực một khi trang có đề mục được đưa vào chỉ mục.
# Để bắt trang phải được đưa lại vào chỉ mục, thực hiện một sửa đổi vô hiệu quả.
# Cú pháp:
#   * Tất cả mọi thứ từ ký hiệu “#” để cuối dòng là chú thích.
#   * Mỗi dòng có nội dung là đúng tên đề mục để bỏ qua, phân biệt chữ hoa/thường.
Tham khảo
Chú thích
Liên kết ngoài
Xem thêm
Đọc thêm
 #</pre> <!-- để yên dòng này -->',
	'cirrussearch-boost-templates' => ' #<!-- xin để yên dòng này --> <pre>
# Nếu trang chứa một trong những bản mẫu này, điểm tìm kiếm của nó được nhân bằng số phần trăm được định rõ.
# Các thay đổi tại thông điệp này được áp dụng ngay.
# Cú pháp là:
#   * Bất cứ gì từ dấu “#” đến cuối dòng là chú thích
#   * Mọi dòng không để trống là đúng tên bản mẫu đúng để nâng lên, kể cả không gian tên, phân biệt chữ hoa/thường, đằng sau là dấu “|”, số, và dấu “%”.
# Ví dụ dòng hợp lệ:
# Bản mẫu:Tốt|150%
# Bản mẫu:Tốt thật tốt|300%
# Bản mẫu:Dở|50%
# Ví dụ dòng không hợp lệ:
# Bản mẫu:Foo|150.234234% <-- không cho phép số thập phân!
# Bản mẫu:Foo|1.000.000% <-- không phân tách số!
# Foo|150% <--- hợp lệ, nhưng chỉ trùng với những lần nhúng trang Foo thuộc không gian tên chính
# Để kiểm thử các thay đổi thiết lập, thực hiện một truy vấn có tiền tố boost-templates:"XX" trong đó XX là tất cả các bản mẫu bạn muốn nâng lên, phân cách bằng khoảng cách thay vì ngắt dòng.
# Các truy vấn định rõ boost-templates:"XX" sẽ bỏ qua nội dung của thông điệp này.
 #</pre> <!-- xin để yên dòng này -->',
	'cirrussearch-pref-label' => 'Công cụ tìm kiếm mới',
	'cirrussearch-pref-desc' => 'Thử công cụ tìm kiếm mới hỗ trợ nhiều ngôn ngữ hơn, cung cấp kết quả tức thời hơn, có khả năng tìm văn bản được bung từ bản mẫu.',
	'cirrussearch-file-contents-match' => 'Nội dung tập tin khớp: $1',
);

/** Simplified Chinese (中文（简体）‎)
 * @author Cwek
 * @author Linxue9786
 * @author Liuxinyu970226
 * @author Qiyue2001
 * @author Shizhao
 * @author TianyinLee
 * @author Xiaomingyan
 * @author Yfdyh000
 */
$messages['zh-hans'] = array(
	'cirrussearch-desc' => '搜索由Elasticsearch为MediaWiki提供',
	'cirrussearch-backend-error' => '由于出现暂时性的问题，我们未能完成你的搜寻。请稍后再试。',
	'cirrussearch-now-using' => '这个wiki使用了新的搜索引擎。（[[mw:Special:MyLanguage/Help:CirrusSearch|详情]]）',
	'cirrussearch-boost-templates' => ' #<!-- 此行绝对保持原状 --> <pre>
# 如果页面中含有这些模板中的任何一个，那么其搜素得分要乘以配置百分比。
# 更改至此立即生效。
# 句法如下所示：
#   *从"#"符到行末的所有信息是注释
#   * 每一非空白行均为确切的模板名称，如加速器，域名空间， 事件以所有信息，接以"|"符，再接以数字及"%"符。
# 良好行目示例：
# 模板：良好|150%
# 模板：非常非常好|300%
# 模板：不良|50%
# 失效行目示例：
# 模板：Foo|150.234234% <-- 不允许使用小数点!
# Foo|150% <--- 技术上可行，但不能用于从主域名空间将Foo页面切换插入至其他页面。
# 你可以通过操作带有加速模板前置"XX"的质询来检测配置的变化：XX 是所有你想加速的模板名称，被空格键分开而不是行间隔。
# 特定加速模板的质询："XX"通常忽略其所涉及的内容。
 #</pre> <!-- 此行绝对保持原状 -->',
	'cirrussearch-pref-label' => '新搜索',
	'cirrussearch-pref-desc' => '试试我们的新搜索引擎，它支持更多语言，能提供更多最新的结果，甚至还能找到模板里面的文本。',
	'cirrussearch-file-contents-match' => '文件内容匹配：$1',
);

/** Traditional Chinese (中文（繁體）‎)
 * @author Justincheng12345
 * @author Liuxinyu970226
 */
$messages['zh-hant'] = array(
	'cirrussearch-desc' => 'MediaWiki的Solr搜尋', # Fuzzy
	'cirrussearch-backend-error' => '由於出現暫時性的問題，我們未能完成你的搜尋。請稍後再試。',
	'cirrussearch-boost-templates' => ' #<!-- leave this line exactly as it is --> <pre>
# 如果一個頁面包含下述模板之一，其搜尋結果將帶百分比。
# 對之更改將即行生效。
# 句法如下：
#  * 從「#」位元至頁尾所有內容為注釋
#  * 每一非空白行均為確切模板名稱，如加速器、名字空間，接於「|」位元、數位和「%」位元。
# 良好行目如下：
# 模板：良好|150%
# 模板：非常非常良好|300%
# 模板：糟糕|50%
# 無效行目如下：
# 模板:Foo|150.234234% <-- 不得使用小數點！
# Foo|150% <--- 技術可行，唯主名字空間與其他頁面之超連接將因此失效
# You can test configuration changes by performing a query prefixed with boost-templates:"XX" where XX is all of the templates you want to boost separated by spaces instead of line breaks.
# Queries that specify boost-templates:"XX" ignore the contents of this field.
 #</pre> <!-- leave this line exactly as it is -->',
);
