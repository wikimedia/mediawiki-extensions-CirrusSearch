<?php

namespace CirrusSearch\SecondTry;

/**
 * English Frames.
 *
 * @license GPL-2.0-or-later
 */

/**
 * Extract plausible titles from questions and other framing devices in English.
 */
class SecondTryEnglishFrames implements SecondTrySearch {

	private const QUERY_LEN_LIMIT = 120; // longest query we could possibly get anything from
	private const SPACE_COUNT_LIMIT = 8; // one less than the max number of words worth exploring
	private const MIN_EXTRACT_LEN = 3;   // min len for extracts with no following frame text
	private const MAX_EXTRACT_LEN = 50;  // max len for extracts

	/** @var array<string,string> Normalization steps, applied in order */
	private const CLEANUP = [
		// remove invisibles
		'/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{FEFF}\x{00AD}\x{0640}\x{FFFC}]/u' => '',

		// condense whitespace (and convert misc Unicodean whitespace)
		'/\s+/u' => ' ',

		// strip leading punct and spaces
		'/^[ _\-–—.,;:!?\/|\\\\\'+*=~•·]+/u' => '',

		// strip trailing punct and spaces
		'/[ _\-–—.,;:!?\/|\\\\\'+*=~•·]+$/u' => '',
	];

	/** @var list<string> queries to skip */
	private const PREFILTER = [
		// ignore list of <x> or wiki category <x>
		'/^(?:list of|wiki category) /',

		// ignore keywords, namespaces, etc.
		'/\b(?:insource|intitle|incategory|hastemplate|deepcat|articletopic|prefix|' .
			'linksto|morelike|category|talk|user|wikipedia|draft|site|file|wp|template|' .
			'discussion|fichier|benutzer|wiki) ?:/',

		// ignore quotes as search syntax
		'/"/',

		// ignore urls & internet cruft
		'/https?:\/\/|www\.|\.(?:com|org|net|gov|edu|io|co\.uk|svg|jpe?g|png|gif|pdf)\b|@/',
	];

	/**
	 * @var list<string> standard identify-and-extract rules
	 *
	 * These are slightly tricky; if there is a second (mandatory or optional) capture
	 * group that follows the first extraction capture group, there is no min length for
	 * the extract. So "how old was f" will not extract "f" because it is too short, and
	 * the user may still be typing. But "how old was f when he died" will extract "f"
	 * because the user typing something else after, so they really meant "f".
	 */
	private const STANDARD_RULES = [
		// tell me about <x>
		'/^(?:tell|give) me (?:info(?:rmation)? )?(?:for|of|on|about) (?:a |an |the )?(.+)$/',

		// how do you say <x>
		'/^how (?:do you|to) (?:pronounce|spell|say) (?:the (?:word|name) )?(.+)$/',

		// what is the meaning of <x>
		'/^(?:(?:what\'?s|what (?:is|are)) the )?(?:meaning|definition|def)s? of ' .
			'(?:the (?:word|name) )?(?:a |an |the )?(.+)$/',

		// what does <x> mean
		'/^what does (?:the (?:word|name) )?(?:a |an |the )?(.+?) (mean|means|stand for)$/',

		// define <x>
		'/^define (?:the (?:word|name) )?(?:a |an |the )?(.+)$/',

		// what is <x>
		'/^(?:what\'?s|(?:what|wat|wht|waht) (?:is|are|was|were)) (?:a |an |the )?(.+?)' .
			'((?: most| best| good)?(?: made of| called| known for| made from| used? for|' .
			' for| famous for| about)?)$/',

		// what happened to <x>
		'/^what happened to (?:the )?(.+)$/',

		// who is <x>
		'/^(?:who\'?s|who (?:is|are|was|were)) (?:a |an |the )?(.+?)( named for| married to)?$/',

		// who <did> <x>
		'/^who (?:plays?|played|wrote|writes|owns|owned|won|wins|invented|killed|made|created|' .
			'sang|sings?|built|discovered|discover|founded|found|designed|directed) (?:the )?(.+)$/',

		// where is <x> [located]
		'/^where (?:is|are|was|were) (?:the )?(.+?)( (?:located|situated|from|born|buried|filmed|streaming|set))?$/',

		// where did <x> live
		'/^where (?:did|does|do) (?:a |an |the )?(?:name |surname |phrase |word )?' .
			'(.+?) (created|originate|start|begin|started|began|begun|go|emerged?|' .
			'live(?: now)?|reside|died?|come from|grow(?: up)?|study|work|teach|take place|' .
			'happen|end(?: up)?|ended|(?:go to |graduate from |attend )(?:(?:high |' .
			'elementary )?school|college))$/',

		// when was <x> born
		'/^(?:when|what year|what date) (?:is|was|are|were|did|do|does|will) (?:the )?' .
			'(.+?)( (?:born|die|died|come out|came out|coming out|released|release|' .
			'start|started|end|ended|made|built|founded|found|formed|created|invented|written|' .
			'begin|began|happen|happened|take place|took place|live|lived|retire|retired|' .
			'buried|get married|got married|married))?$/',

		// how tall|old is <x>
		'/^how (?:old|tall) (?:is|was|are|were) (.+?)( when s?he died)?$/',

		// how did <x> die
		'/^how did (.+?)( die)$/',

		// is <x> dead
		'/^is (?:a |an |the )?(.+?) (dead|alive|still alive|dead or alive|alive or dead|real)$/',

		// the cast of <x>
		'/^(?:the )?cast of (?:the )?(.+?)((?: the)?(?: (?:movie|film|tv series|series|tv show|show))?)$/',

		// pics of <x>
		'/^(?:pictures?|images?|photos?|photographs?|pics?|maps?|types?) of (?:a |an |the )?(.+)$/',

		// biography of <x>
		'/^(?:biography|bio|summary|plot) of (?:a |an |the )?(.+)$/',

		// info about <x>
		'/^(?:facts|information|info) (?:about|on) (?:a |an |the )?(.+)$/',

		// lyrics to <x>
		'/^lyrics (?:to|of|for)(?: the song)? (.+?)( song)?$/',

		// wiki <x>
		'/^(?:wiki|wik[iae]?p[ei]?d[iae]{1,2}|wickipedia) (.+)$/',

		// <x> wiki
		'/^(.+?) (wiki|wik[iae]?p[ei]?d[iae]{1,2}|wickipedia)$/',

		// <x> definition
		'/^(.+?) ((?:full )?(?:(?:and )?(?:name |its |their )?meaning(?: in \w+)?|' .
			'definitions?|pronunciation|synonyms?|lyrics|summary|population))$/u',
	];

	/** @var string This long list gets used twice, so abstract it out */
	private const ACTOR_ADJ = 'adult|african|american|and|australian|black|bollywood|' .
		'brazill?ian|british|canadian|child|chinese|comedy|english|famous|female|film|' .
		'french|gay|greek|indian|irish|japanese|korean|malayalam|male|movie|porn|pro|' .
		'professional|russian|scottish|singer|song|supporting|tamil|thai|the|tv|voice|' .
		'welsh|white';

	/** @var array<string,true> Single words that cannot be extracted titles */
	private const ONE_WORD_NON_TITLES = [
		'a' => true, 'i' => true, 'an' => true, 'at' => true, 'be' => true,
		'by' => true, 'do' => true, 'he' => true, 'if' => true, 'in' => true,
		'is' => true, 'it' => true, 'me' => true, 'my' => true, 'of' => true,
		'on' => true, 'or' => true, 'to' => true, 'us' => true, 'vs' => true,
		'we' => true, 'and' => true, 'are' => true, 'did' => true, 'for' => true,
		'her' => true, 'him' => true, 'his' => true, 'its' => true, 'new' => true,
		'old' => true, 'our' => true, 'she' => true, 'the' => true, 'was' => true,
		'www' => true, 'you' => true, 'about' => true, 'does' => true, 'from' => true,
		'here' => true, 'hers' => true, 'once' => true, 'ours' => true, 'that' => true,
		'them' => true, 'they' => true, 'this' => true, 'were' => true, 'with' => true,
		'your' => true, 'their' => true, 'there' => true, 'these' => true,
		'those' => true, 'yours' => true, 'anyone' => true, 'theirs' => true,
		'someone' => true, 'anything' => true, 'everyone' => true, 'something' => true,
	];

	/** @var array<string,true> Words that cannot be the last word of extracted titles */
	private const NOT_TITLE_ENDER = [
		'a' => true, 'an' => true, 'at' => true, 'be' => true, 'by' => true,
		'if' => true, 'in' => true, 'is' => true, 'my' => true, 'of' => true,
		'on' => true, 'or' => true, 'to' => true, 'vs' => true, 'and' => true,
		'are' => true, 'for' => true, 'his' => true, 'its' => true, 'the' => true,
		'was' => true, 'from' => true, 'were' => true, 'with' => true, 'your' => true,
		'about' => true, 'their' => true,
	];

	private static function extractTitle( string $q ): ?string {
		// lowercase, normalize common quotes
		$q = strtr( mb_strtolower( $q ), [ '“' => '"', '”' => '"', '‘' => "'", '’' => "'" ] );

		// strip external site: syntax and mark change
		$q = preg_replace( '/site:(?:[a-z]{2,3}\.)?wikipedia\.org\S*/', '', $q, -1, $siteStripped );

		// cleanup common cruft
		$q = preg_replace( array_keys( self::CLEANUP ), self::CLEANUP, $q );

		$spaces = substr_count( $q, ' ' );
		if ( ( $spaces == 0 && !$siteStripped )
				|| $spaces > self::SPACE_COUNT_LIMIT
				|| mb_strlen( $q ) > self::QUERY_LEN_LIMIT ) {
			// bail if only one word, too many words, or just too long
			return null;
		}

		// skip queries with certain properties or formats
		foreach ( self::PREFILTER as $filt ) {
			if ( preg_match( $filt, $q ) ) {
				return null;
			}
		}

		// simple likely title extractions
		foreach ( self::STANDARD_RULES as $rule ) {
			if ( preg_match( $rule, $q, $m ) ) {
				// if there is following frame text or the extract is at least the min
				// length, keep it
				if ( ( isset( $m[2] ) && $m[2] !== '' )
					 || mb_strlen( $m[1] ) >= self::MIN_EXTRACT_LEN ) {
					return $m[1];
				} else {
					return null;
				}
			}
		}

		// a few more complex title extractions with post-extraction filters or cleanup
		// these all have required following frame text, so we don't need to check that
		if ( preg_match( '/^(.+?) (?:latest |the |an? )?(?:(?:in )?(?:full )?(?:movie|film)s?|' .
			'(?:movie |film )?(?:full )?cast|(?:tv |television |netflix |web )?(?:series|show)|' .
			'season \d{1,2}|(?:the )?(?:video |board |card )?game|band|songs?|album|book|novel|' .
			'characters?|trailer|release date)$/', $q, $m ) ) {
			// <x> movie
			$extract = $m[1];
			// bail if it's just numbers, or a "best <x> of" type query
			if ( preg_match( '/[^\d ]/', $extract ) &&
					!preg_match( '/^(?:best|highest|most|oldest|top)\b/', $extract ) ) {
				return $extract;
			}
		}

		if ( preg_match( '/^(.+?) (?:(?:' . self::ACTOR_ADJ . ') ){0,3}(?:actors?|actress|singer|' .
			'writer|author|musician|artist|rapper|painter|poets?|directors?|footballer|' .
			'boxer|wrestler|comedian|politician|journalist|athlete)$/', $q, $m ) ) {
			// <x> actor
			$extract = $m[1];
			// bail if there's still an actor adjective, or a "best <x> of" type query
			if ( !preg_match( '/^(?:' . self::ACTOR_ADJ . ')$/', $extract ) &&
					!preg_match( '/\b(?:of|best|a|and|paid|by|an|top|oldest|highest)$/', $extract ) ) {
				return $extract;
			}
		}

		if ( preg_match( '/^(.+?) (?:(?:first|second|ex|former|current|late) )?' .
			'(?:net worth|age|height|wife|husband|spouse|(?:cause of )?death|biography|bio)$/', $q, $m )
			&& !preg_match( '/(?:stone|bronze|ice|iron|steel|golden|middle|dark|space|digital|jazz|' .
				'gilded|machine|information|victorian|modern|viking) age/', $q ) ) {
			// <x> height|age ...but not "stone age"
			$extract = $m[1];
			if ( str_ends_with( $extract, "'s" ) ) {
				$extract = substr( $extract, 0, -2 );
			}
			return $extract;
		}

		if ( $q == '' ) {
			return null;
		}

		if ( $siteStripped ) {
			return $q;
		}

		return null;
	}

	/**
	 * Extract title from English question/command frame.
	 *
	 * {@inheritDoc}
	 */
	public function candidates( string $searchQuery ): array {
		$newq = self::extractTitle( $searchQuery );

		if ( $newq === null // found nothing, return nothing;
			|| isset( self::ONE_WORD_NON_TITLES[$newq] ) // no forbidden one-word titles
			|| mb_strlen( $newq ) > self::MAX_EXTRACT_LEN // no very long extracts
			) {
			return [];
		}

		// drop titles that end in a function word
		$sp = strrpos( $newq, ' ' );
		if ( $sp !== false && isset( self::NOT_TITLE_ENDER[ substr( $newq, $sp + 1 ) ] ) ) {
			return [];
		}

		return [ $newq ];
	}
}
