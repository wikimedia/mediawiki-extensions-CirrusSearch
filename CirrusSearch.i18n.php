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
	'cirrussearch-backend-error' => 'We could not complete your search due to a temporary problem.  Please try again later.',
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
);

/** Message documentation (Message documentation)
 * @author Shirayuki
 */
$messages['qqq'] = array(
	'cirrussearch-desc' => '{{desc|name=Cirrus Search|url=http://www.mediawiki.org/wiki/Extension:CirrusSearch}}
"Elasticsearch" and "Solr" are full-text search engines. See http://www.elasticsearch.org/',
	'cirrussearch-backend-error' => 'Error message shown to the users when we have an issue communicating with our search backend',
	'cirrussearch-ignored-headings' => 'Headings that will be ignored by search. You can translate the text, including "Leave this line exactly as it is". Some lines of this messages have one (1) leading space.',
);

/** Asturian (asturianu)
 * @author Xuacu
 */
$messages['ast'] = array(
	'cirrussearch-desc' => 'Gueta col motor Elasticsearch pa MediaWiki',
	'cirrussearch-backend-error' => 'Nun pudimos completar la gueta por un problema temporal. Por favor, vuelva a intentalo más sero.',
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

/** Breton (brezhoneg)
 * @author Fohanno
 */
$messages['br'] = array(
	'cirrussearch-backend-error' => "N'hon eus ket gallet kas hoc'h enklask da benn abalamour d'ur gudenn dibad. Esaeit en-dro diwezhatoc'h, mar plij.",
);

/** Catalan (català)
 * @author QuimGil
 */
$messages['ca'] = array(
	'cirrussearch-desc' => 'Cerca realitzada amb Elasticsearch per a MediaWiki',
	'cirrussearch-backend-error' => 'La seva cerca no ha pogut ser completada degut a un problema temporal. Si us plau, provi-ho més tard.',
);

/** Czech (česky)
 * @author Mormegil
 */
$messages['cs'] = array(
	'cirrussearch-desc' => 'Vyhledávání v MediaWiki běžící na Elasticsearch',
	'cirrussearch-backend-error' => 'Kvůli dočasnému problému jsme nemohli provést požadované vyhledávání. Zkuste to znovu později.',
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
);

/** German (Deutsch)
 * @author Metalhead64
 */
$messages['de'] = array(
	'cirrussearch-desc' => 'Solr-betriebene Suche',
	'cirrussearch-backend-error' => 'Deine Suche konnte aufgrund eines vorübergehenden Problems nicht abgeschlossen werden. Bitte später erneut versuchen.',
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
);

/** Spanish (español)
 * @author Luis Felipe Schenone
 */
$messages['es'] = array(
	'cirrussearch-desc' => 'Hace que la búsqueda sea con Solr',
	'cirrussearch-backend-error' => 'No pudimos completar tu búsqueda debido a un problema temporario. Por favor intenta de nuevo más tarde.',
);

/** French (français)
 * @author Gomoko
 * @author Jean-Frédéric
 */
$messages['fr'] = array(
	'cirrussearch-desc' => 'Fait effectuer la recherche par Solr',
	'cirrussearch-backend-error' => 'Nous n’avons pas pu mener à bien votre recherche à cause d’un problème temporaire. Veuillez réessayer ultérieurement.',
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
);

/** Galician (galego)
 * @author Toliño
 */
$messages['gl'] = array(
	'cirrussearch-desc' => 'Procura baseada en Elasticsearch para MediaWiki',
	'cirrussearch-backend-error' => 'Non puidemos completar a súa procura debido a un problema temporal. Inténteo de novo máis tarde.',
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
);

/** Hebrew (עברית)
 * @author Amire80
 */
$messages['he'] = array(
	'cirrussearch-desc' => 'חיפוש במדיה־ויקי באמצעות Elasticsearch',
	'cirrussearch-backend-error' => 'לא הצלחנו להשלים את החיפוש שלך בשל בעיה זמנית. נא לנסות שוב מאוחר יותר.',
);

/** Interlingua (interlingua)
 * @author McDutchie
 */
$messages['ia'] = array(
	'cirrussearch-desc' => 'Recerca pro MediaWiki actionate per Elasticsearch',
	'cirrussearch-backend-error' => 'Un problema temporari ha impedite le completion del recerca. Per favor reproba plus tarde.',
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
);

/** Italian (italiano)
 * @author Beta16
 */
$messages['it'] = array(
	'cirrussearch-desc' => 'Ricerca realizzata con Elasticsearch per MediaWiki',
	'cirrussearch-backend-error' => 'Non si è riuscito a completare la tua ricerca a causa di un problema temporaneo. Riprova più tardi.',
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
);

/** Japanese (日本語)
 * @author Fryed-peach
 * @author Shirayuki
 */
$messages['ja'] = array(
	'cirrussearch-desc' => 'MediaWiki 用の Elasticsearch 検索',
	'cirrussearch-backend-error' => '一時的な問題により検索を実行できませんでした。後でやり直してください。',
);

/** Korean (한국어)
 * @author 아라
 */
$messages['ko'] = array(
	'cirrussearch-desc' => '미디어위키를 위한 Elasticsearch 검색',
	'cirrussearch-backend-error' => '일시적인 문제 떄문에 찾기를 완료할 수 없습니다. 나중에 다시 시도하세요.',
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
);

/** Luxembourgish (Lëtzebuergesch)
 * @author Robby
 */
$messages['lb'] = array(
	'cirrussearch-desc' => 'Elasticsearch-Sichfonctioun fir MediaWiki',
	'cirrussearch-backend-error' => 'Mir konnten Är Sich wéint engem temporäre Problem net maachen. Probéiert w.e.g. méi spéit nach eng Kéier.',
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
);

/** Macedonian (македонски)
 * @author Bjankuloski06
 */
$messages['mk'] = array(
	'cirrussearch-desc' => 'Пребарување со Solr',
	'cirrussearch-backend-error' => 'Не можам наполно да го изведам пребарувањето поради привремен проблем. Обидете се подоцна.',
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
 * @author Siebrand
 */
$messages['nl'] = array(
	'cirrussearch-desc' => 'Zoeken via Solr',
	'cirrussearch-backend-error' => 'Als gevolg van een tijdelijk probleem kon uw zoekopdracht niet worden voltooit. Probeer het later opnieuw.',
);

/** Occitan (occitan)
 * @author Cedric31
 */
$messages['oc'] = array(
	'cirrussearch-desc' => 'Fa efectuar la recèrca per Solr',
	'cirrussearch-backend-error' => 'Avèm pas pogut menar corrèctament vòstra recèrca a causa d’un problèma temporari. Ensajatz tornarmai ulteriorament.',
);

/** Brazilian Portuguese (português do Brasil)
 * @author Jaideraf
 */
$messages['pt-br'] = array(
	'cirrussearch-desc' => "Mecanismo de busca ''Elasticsearch'' para MediaWiki",
	'cirrussearch-backend-error' => 'Não foi possível completar a busca devido a um problema temporário. Por favor, tente novamente mais tarde.',
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
	'cirrussearch-backend-error' => 'Нам не удалось завершить поиск из-за временной проблемы. Пожалуйста, повторите попытку позже.',
);

/** Swedish (svenska)
 * @author Jopparn
 */
$messages['sv'] = array(
	'cirrussearch-backend-error' => 'Vi kunde inte slutföra din sökning på grund av ett tillfälligt problem. Försök igen senare.',
);

/** Ukrainian (українська)
 * @author Andriykopanytsia
 * @author Ата
 */
$messages['uk'] = array(
	'cirrussearch-desc' => 'Вмикає пошук з допомогою Solr',
	'cirrussearch-backend-error' => 'Нам не вдалося завершити ваш пошук через тимчасову проблему. Спробуйте ще раз пізніше.',
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
);

/** Vietnamese (Tiếng Việt)
 * @author Minh Nguyen
 */
$messages['vi'] = array(
	'cirrussearch-desc' => 'Công cụ tìm kiếm Elasticsearch dành cho MediaWiki',
	'cirrussearch-backend-error' => 'Không thể hoàn tất truy vấn của bạn vì một vấn đề tạm thời. Xin vui lòng thử lại sau.',
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
);

/** Simplified Chinese (中文（简体）‎)
 * @author Qiyue2001
 * @author TianyinLee
 */
$messages['zh-hans'] = array(
	'cirrussearch-desc' => 'MediaWiki专用的Elasticsearch搜索',
	'cirrussearch-backend-error' => '由于出现暂时性的问题，我们未能完成你的搜寻。请稍后再试。',
);

/** Traditional Chinese (中文（繁體）‎)
 * @author Justincheng12345
 */
$messages['zh-hant'] = array(
	'cirrussearch-desc' => 'MediaWiki的Solr搜尋', # Fuzzy
	'cirrussearch-backend-error' => '由於出現暫時性的問題，我們未能完成你的搜尋。請稍後再試。',
);
