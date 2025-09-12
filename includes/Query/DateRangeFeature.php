<?php

namespace CirrusSearch\Query;

use CirrusSearch\Parser\AST\KeywordFeatureNode;
use CirrusSearch\Query\Builder\QueryBuildingContext;
use CirrusSearch\Search\SearchContext;
use CirrusSearch\SearchConfig;
use CirrusSearch\WarningCollector;
use DateTime;
use DateTimeZone;
use Elastica\Query\Range;
use MediaWiki\Config\Config;
use MediaWiki\MainConfigNames;

/**
 * Support for date range queries.
 *
 * Examples:
 *   lasteditdate:>=now-1d
 */
class DateRangeFeature extends SimpleKeywordFeature implements FilterQueryFeature {
	/**
	 * @var array Mapping from syntax prefix to range query param key
	 */
	private static $PREFIXES = [
		// two char must come before one char variants
		'<=' => 'lte',
		'<' => 'lt',
		'>=' => 'gte',
		'>' => 'gt'
	];

	/**
	 * @var array[] Configuration of supported date formats
	 */
	private static $DATE_FORMAT = [
		[
			// php format
			'php' => 'Y',
			// opensearch format
			'opensearch' => 'year',
			// precision to round query to
			'precision' => 'y'
		],
		[
			'php' => 'Y-m',
			'opensearch' => 'year_month',
			'precision' => 'M', // upper case for months
		],
		[
			'php' => 'Y-m-d',
			'opensearch' => 'date',
			'precision' => 'd',
		],
	];

	/**
	 * @var string The keyword to respond to
	 */
	private string $keyword;

	/**
	 * @var string The field to range query against
	 */
	private string $fieldName;

	/**
	 * @var string The timezone to use for date parsing and rounding
	 */
	private string $tz;

	/**
	 * @param Config $config MediaWiki configuration to source tz from
	 * @param string $keyword The keyword to respond to
	 * @param string $fieldName The field to range query against
	 * @return DateRangeFeature
	 */
	public static function factory(
		Config $config,
		string $keyword,
		string $fieldName
	): DateRangeFeature {
		return new self(
			$keyword,
			$fieldName,
			$config->get( MainConfigNames::Localtimezone ) ?? 'UTC',
		);
	}

	/**
	 * @param string $keyword The keyword to responsd to
	 * @param string $fieldName The field to range query against
	 * @param string $tz The timezone to use for date parsing and rounding
	 */
	public function __construct( string $keyword, string $fieldName, string $tz ) {
		$this->keyword = $keyword;
		$this->fieldName = $fieldName;
		$this->tz = $tz;
	}

	/** @inheritDoc */
	public function getFilterQuery( KeywordFeatureNode $node, QueryBuildingContext $context ) {
		return $this->doGetFilterQuery( $node->getParsedValue(), $context->getSearchConfig() );
	}

	private function parseNow( string $value ): ?array {
		if ( preg_match( '/^(now|today)(?:-(\d+)([ymdh]))?$/', $value, $matches ) !== 1 ) {
			return null;
		}

		$date = [
			'value' => 'now',
			'precision' => $matches[1] === 'now' ? 'h' : 'd',
		];
		if ( isset( $matches[2] ) ) {
			$date['subtract'] = [ $matches[2], $matches[3] ];
			if ( $date['subtract'][1] === 'm' ) {
				// m is minutes, we want M for months.
				$date['subtract'][1] = 'M';
			}
		}
		return $date;
	}

	private function parseDate( string $value ): ?array {
		$tz = new DateTimeZone( $this->tz );
		foreach ( self::$DATE_FORMAT as $settings ) {
			$dt = DateTime::createFromFormat( $settings['php'], $value, $tz );
			// must not only parse, but round trip. Avoids things like
			// 2025-15 parsing as 2026-03.
			if ( $dt !== false && $dt->format( $settings['php'] ) === $value ) {
				return [
					'format' => $settings['opensearch'],
					'value' => $value,
					'precision' => $settings['precision'],
				];
			}
		}
		return null;
	}

	/** @inheritDoc */
	public function parseValue( $key, $value, $quotedValue, $valueDelimiter, $suffix, WarningCollector $warningCollector ) {
		// Parse out the prefix, specifying direction of the condition
		$cond = 'eq';
		foreach ( self::$PREFIXES as $prefix => $condition ) {
			if ( str_starts_with( $value, $prefix ) ) {
				$cond = $condition;
				$value = substr( $value, strlen( $prefix ) );
				break;
			}
		}
		// Remaining text must be either a date or now
		$date = $this->parseNow( $value ) ?? $this->parseDate( $value );
		if ( $date === null ) {
			$warningCollector->addWarning( 'cirrussearch-feature-invalid-date-range' );
			return [];
		}
		return [
			'condition' => $cond,
			'date' => $date,
		];
	}

	private function formatDate( array $date ): string {
		$math = '';
		if ( $date['subtract'] ?? null ) {
			[ $count, $unit ] = $date['subtract'];
			$math .= "-{$count}{$unit}";
		}
		if ( $date['precision'] ?? null ) {
			$math .= '/' . $date['precision'];
		}

		if ( $date['value'] === 'now' ) {
			return "now{$math}";
		} elseif ( $math ) {
			return "{$date['value']}||{$math}";
		} else {
			return $date['value'];
		}
	}

	/** @inheritDoc */
	protected function doGetFilterQuery( array $parsedValue, SearchConfig $searchConfig ) {
		if ( !$parsedValue ) {
			return null;
		}

		// timezone will be used for parsing and rounding
		$params = [ 'time_zone' => $this->tz ];
		// "now" doesn't have a format
		if ( $parsedValue['date']['value'] !== 'now' ) {
			$params['format'] = $parsedValue['date']['format'];
		}
		$date = $this->formatDate( $parsedValue['date'] );
		if ( $parsedValue['condition'] == 'eq' ) {
			$params['gte'] = $date;
			$params['lte'] = $date;
		} else {
			$params[$parsedValue['condition']] = $date;
		}

		return new Range( $this->fieldName, $params );
	}

	/** @inheritDoc */
	protected function getKeywords() {
		return [ $this->keyword ];
	}

	/** @inheritDoc */
	protected function doApply( SearchContext $context, $key, $value, $quotedValue, $negated ) {
		$filter = $this->doGetFilterQuery(
			$this->parseValue( $key, $value, $quotedValue, '', '', $context ),
			$context->getConfig()
		);
		if ( !$filter ) {
			$context->setResultsPossible( false );
		}
		return [ $filter, false ];
	}
}
