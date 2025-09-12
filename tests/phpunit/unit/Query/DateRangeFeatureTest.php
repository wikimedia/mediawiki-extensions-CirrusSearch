<?php

namespace CirrusSearch\Query;

use CirrusSearch\CirrusTestCase;
use Elastica\Query\Range;
use MediaWiki\Config\HashConfig;
use MediaWiki\MainConfigNames;

/**
 * @covers \CirrusSearch\Query\DateRangeFeature
 * @group CirrusSearch
 */
class DateRangeFeatureTest extends CirrusTestCase {
	use SimpleKeywordFeatureTestTrait;

	public static function parseProvider() {
		return [
			'basic date equality' => [
				[
					'condition' => 'eq',
					'date' => [
						'format' => 'date',
						'value' => '2024-01-15',
						'precision' => 'd',
					],
				],
				[],
				'lasteditdate:2024-01-15',
			],
			'greater than date' => [
				[
					'condition' => 'gt',
					'date' => [
						'format' => 'date',
						'value' => '2024-01-15',
						'precision' => 'd',
					],
				],
				[],
				'lasteditdate:>2024-01-15',
			],
			'greater than or equal date' => [
				[
					'condition' => 'gte',
					'date' => [
						'format' => 'date',
						'value' => '2024-01-15',
						'precision' => 'd',
					],
				],
				[],
				'lasteditdate:>=2024-01-15',
			],
			'less than date' => [
				[
					'condition' => 'lt',
					'date' => [
						'format' => 'date',
						'value' => '2024-01-15',
						'precision' => 'd',
					],
				],
				[],
				'lasteditdate:<2024-01-15',
			],
			'less than or equal date' => [
				[
					'condition' => 'lte',
					'date' => [
						'format' => 'date',
						'value' => '2024-01-15',
						'precision' => 'd',
					],
				],
				[],
				'lasteditdate:<=2024-01-15',
			],
			'year only format' => [
				[
					'condition' => 'eq',
					'date' => [
						'format' => 'year',
						'value' => '2024',
						'precision' => 'y',
					],
				],
				[],
				'lasteditdate:2024',
			],
			'year-month format' => [
				[
					'condition' => 'eq',
					'date' => [
						'format' => 'year_month',
						'value' => '2024-01',
						'precision' => 'M',
					],
				],
				[],
				'lasteditdate:2024-01',
			],
			'now without offset' => [
				[
					'condition' => 'eq',
					'date' => [
						'value' => 'now',
						'precision' => 'h',
					],
				],
				[],
				'lasteditdate:now',
			],
			'today without offset' => [
				[
					'condition' => 'eq',
					'date' => [
						'value' => 'now',
						'precision' => 'd',
					],
				],
				[],
				'lasteditdate:today',
			],
			'now with day offset' => [
				[
					'condition' => 'gte',
					'date' => [
						'value' => 'now',
						'precision' => 'h',
						'subtract' => [ '7', 'd' ],
					],
				],
				[],
				'lasteditdate:>=now-7d',
			],
			'today with day offset' => [
				[
					'condition' => 'gte',
					'date' => [
						'value' => 'now',
						'precision' => 'd',
						'subtract' => [ '7', 'd' ],
					],
				],
				[],
				'lasteditdate:>=today-7d',
			],
			'now with hour offset' => [
				[
					'condition' => 'gt',
					'date' => [
						'value' => 'now',
						'precision' => 'h',
						'subtract' => [ '2', 'h' ],
					],
				],
				[],
				'lasteditdate:>now-2h',
			],
			'now with month offset' => [
				[
					'condition' => 'lte',
					'date' => [
						'value' => 'now',
						'precision' => 'h',
						'subtract' => [ '3', 'M' ],
					],
				],
				[],
				'lasteditdate:<=now-3m',
			],
			'today with month offset' => [
				[
					'condition' => 'lte',
					'date' => [
						'value' => 'now',
						'precision' => 'd',
						'subtract' => [ '3', 'M' ],
					],
				],
				[],
				'lasteditdate:<=today-3m',
			],
			'now with year offset' => [
				[
					'condition' => 'lt',
					'date' => [
						'value' => 'now',
						'precision' => 'h',
						'subtract' => [ '1', 'y' ],
					],
				],
				[],
				'lasteditdate:<now-1y',
			],
			'today with year offset' => [
				[
					'condition' => 'lt',
					'date' => [
						'value' => 'now',
						'precision' => 'd',
						'subtract' => [ '1', 'y' ],
					],
				],
				[],
				'lasteditdate:<today-1y',
			],
			'invalid date format' => [
				[],
				[ [ 'cirrussearch-feature-invalid-date-range' ] ],
				'lasteditdate:2024-15-45',
			],
			'invalid relative date' => [
				[],
				[ [ 'cirrussearch-feature-invalid-date-range' ] ],
				'lasteditdate:tomorrow',
			],
			'invalid now syntax' => [
				[],
				[ [ 'cirrussearch-feature-invalid-date-range' ] ],
				'lasteditdate:now-1x',
			],
			'invalid month in date' => [
				[],
				[ [ 'cirrussearch-feature-invalid-date-range' ] ],
				'lasteditdate:2024-15',
			],
			'invalid day in date' => [
				[],
				[ [ 'cirrussearch-feature-invalid-date-range' ] ],
				'lasteditdate:2024-02-30',
			],
		];
	}

	/**
	 * @dataProvider parseProvider
	 */
	public function testParse(
		array $expected, array $expectedWarnings, string $term
	) {
		$feature = new DateRangeFeature( 'lasteditdate', 'last_edit_date', 'UTC' );
		$this->assertParsedValue( $feature, $term, $expected, $expectedWarnings );
	}

	public static function filterProvider() {
		return [
			'basic date equality creates range with gte and lte' => [
				new Range( 'last_edit_date', [
					'format' => 'date',
					'time_zone' => 'UTC',
					'gte' => '2024-01-15||/d',
					'lte' => '2024-01-15||/d',
				] ),
				[],
				'lasteditdate:2024-01-15',
			],
			'greater than creates range with gt only' => [
				new Range( 'last_edit_date', [
					'format' => 'date',
					'time_zone' => 'UTC',
					'gt' => '2024-01-15||/d',
				] ),
				[],
				'lasteditdate:>2024-01-15',
			],
			'greater than or equal creates range with gte only' => [
				new Range( 'last_edit_date', [
					'format' => 'date',
					'time_zone' => 'UTC',
					'gte' => '2024-01-15||/d',
				] ),
				[],
				'lasteditdate:>=2024-01-15',
			],
			'less than creates range with lt only' => [
				new Range( 'last_edit_date', [
					'format' => 'date',
					'time_zone' => 'UTC',
					'lt' => '2024-01-15||/d',
				] ),
				[],
				'lasteditdate:<2024-01-15',
			],
			'less than or equal creates range with lte only' => [
				new Range( 'last_edit_date', [
					'format' => 'date',
					'time_zone' => 'UTC',
					'lte' => '2024-01-15||/d',
				] ),
				[],
				'lasteditdate:<=2024-01-15',
			],
			'year format uses correct precision' => [
				new Range( 'last_edit_date', [
					'format' => 'year',
					'time_zone' => 'UTC',
					'gte' => '2024||/y',
					'lte' => '2024||/y',
				] ),
				[],
				'lasteditdate:2024',
			],
			'year-month format uses correct precision' => [
				new Range( 'last_edit_date', [
					'format' => 'year_month',
					'time_zone' => 'UTC',
					'gte' => '2024-01||/M',
					'lte' => '2024-01||/M',
				] ),
				[],
				'lasteditdate:2024-01',
			],
			'now without offset' => [
				new Range( 'last_edit_date', [
					'time_zone' => 'UTC',
					'gte' => 'now/h',
					'lte' => 'now/h',
				] ),
				[],
				'lasteditdate:now',
			],
			'today without offset' => [
				new Range( 'last_edit_date', [
					'time_zone' => 'UTC',
					'gte' => 'now/d',
					'lte' => 'now/d',
				] ),
				[],
				'lasteditdate:today',
			],
			'now with day offset includes math expression' => [
				new Range( 'last_edit_date', [
					'time_zone' => 'UTC',
					'gte' => 'now-7d/h',
				] ),
				[],
				'lasteditdate:>=now-7d',
			],
			'today with day offset includes math expression' => [
				new Range( 'last_edit_date', [
					'time_zone' => 'UTC',
					'gte' => 'now-7d/d',
				] ),
				[],
				'lasteditdate:>=today-7d',
			],
			'now with hour offset' => [
				new Range( 'last_edit_date', [
					'time_zone' => 'UTC',
					'gt' => 'now-2h/h',
				] ),
				[],
				'lasteditdate:>now-2h',
			],
			'now with month offset uses uppercase M' => [
				new Range( 'last_edit_date', [
					'time_zone' => 'UTC',
					'lte' => 'now-3M/h',
				] ),
				[],
				'lasteditdate:<=now-3m',
			],
			'today with month offset uses uppercase M' => [
				new Range( 'last_edit_date', [
					'time_zone' => 'UTC',
					'lte' => 'now-3M/d',
				] ),
				[],
				'lasteditdate:<=today-3m',
			],
			'invalid date format returns null filter' => [
				null,
				[ [ 'cirrussearch-feature-invalid-date-range' ] ],
				'lasteditdate:invalid-date',
			],
		];
	}

	/**
	 * @dataProvider filterProvider
	 */
	public function testFilter( $expectedFilter, array $expectedWarnings, string $term ) {
		$feature = new DateRangeFeature( 'lasteditdate', 'last_edit_date', 'UTC' );
		$this->assertFilter( $feature, $term, $expectedFilter, $expectedWarnings );
	}

	public function testNoResultsPossibleWithInvalidDate() {
		$feature = new DateRangeFeature( 'lasteditdate', 'last_edit_date', 'UTC' );
		$this->assertNoResultsPossible( $feature, 'lasteditdate:invalid-date',
			[ [ 'cirrussearch-feature-invalid-date-range' ] ] );
	}

	public function testMultipleFormatsWithDifferentKeywords() {
		// Test that different instances can use different keywords and field names
		$lastEditFeature = new DateRangeFeature( 'lasteditdate', 'last_edit_date', 'UTC' );
		$createDateFeature = new DateRangeFeature( 'createdate', 'create_timestamp', 'UTC' );

		$this->assertParsedValue( $lastEditFeature, 'lasteditdate:2024-01-15', [
			'condition' => 'eq',
			'date' => [
				'format' => 'date',
				'value' => '2024-01-15',
				'precision' => 'd',
			],
		] );

		$this->assertParsedValue( $createDateFeature, 'createdate:>=2024', [
			'condition' => 'gte',
			'date' => [
				'format' => 'year',
				'value' => '2024',
				'precision' => 'y',
			],
		] );

		// Test that wrong keyword doesn't match
		$this->assertNotConsumed( $lastEditFeature, 'createdate:2024-01-15' );
		$this->assertNotConsumed( $createDateFeature, 'lasteditdate:2024-01-15' );
	}

	public function testTimezoneHandling() {
		// Test that different timezones are properly handled
		$utcFeature = new DateRangeFeature( 'lasteditdate', 'last_edit_date', 'UTC' );
		$estFeature = new DateRangeFeature( 'lasteditdate', 'last_edit_date', 'America/New_York' );

		// Both should parse the same way
		$this->assertParsedValue( $utcFeature, 'lasteditdate:2024-01-15', [
			'condition' => 'eq',
			'date' => [
				'format' => 'date',
				'value' => '2024-01-15',
				'precision' => 'd',
			],
		] );

		$this->assertParsedValue( $estFeature, 'lasteditdate:2024-01-15', [
			'condition' => 'eq',
			'date' => [
				'format' => 'date',
				'value' => '2024-01-15',
				'precision' => 'd',
			],
		] );

		// But should generate different filter queries with different time zones
		$this->assertFilter( $utcFeature, 'lasteditdate:2024-01-15',
			new Range( 'last_edit_date', [
				'format' => 'date',
				'time_zone' => 'UTC',
				'gte' => '2024-01-15||/d',
				'lte' => '2024-01-15||/d',
			] )
		);

		$this->assertFilter( $estFeature, 'lasteditdate:2024-01-15',
			new Range( 'last_edit_date', [
				'format' => 'date',
				'time_zone' => 'America/New_York',
				'gte' => '2024-01-15||/d',
				'lte' => '2024-01-15||/d',
			] )
		);
	}

	public function testTodayVsNowPrecision() {
		$feature = new DateRangeFeature( 'lasteditdate', 'last_edit_date', 'UTC' );

		// Test that 'now' defaults to hour precision
		$this->assertParsedValue( $feature, 'lasteditdate:now', [
			'condition' => 'eq',
			'date' => [
				'value' => 'now',
				'precision' => 'h',
			],
		] );

		// Test that 'today' defaults to day precision
		$this->assertParsedValue( $feature, 'lasteditdate:today', [
			'condition' => 'eq',
			'date' => [
				'value' => 'now',
				'precision' => 'd',
			],
		] );
	}

	public function testFactoryMethod() {
		// Test the static factory method
		$config = new HashConfig( [
			MainConfigNames::Localtimezone => 'America/Chicago'
		] );
		$feature = DateRangeFeature::factory( $config, 'testdate', 'test_field' );

		// Test that the feature was created with the correct timezone
		$this->assertFilter( $feature, 'testdate:2024-01-15',
			new Range( 'test_field', [
				'format' => 'date',
				'time_zone' => 'America/Chicago',
				'gte' => '2024-01-15||/d',
				'lte' => '2024-01-15||/d',
			] )
		);
	}
}
