<?php

namespace CirrusSearch\Query\Builder;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\HashSearchConfig;
use Elastica\Query\MatchNone;

class NearMatchFieldQueryBuilderTest extends CirrusTestCase {
	public function provideTestExtraction(): array {
		$profile = [ "fields" => [ [ "name" => "a_field", "weight" => 0.5 ] ] ];
		$toMultiMatch = static function ( string $str ): array {
			return [ 'multi_match' => [ 'query' => $str , 'fields' => [ 'a_field^0.5' ] ] ];
		};
		return [
			'simple' => [
				'simple',
				$profile,
				$toMultiMatch( 'simple' )
			],
			'intitle' => [
				'keep intitle:it simple',
				$profile,
				$toMultiMatch( 'keep it simple' )
			],
			'intitle with phrase and other keyword' => [
				'-incategory:please keep intitle:"it" NOT incategory:so simple intitle:/stupid/',
				$profile,
				$toMultiMatch( 'keep "it" NOT simple' )
			],
			'intitle with phrase' => [
				'keep intitle:"it not so" simple',
				$profile,
				$toMultiMatch( 'keep "it not so" simple' )
			],
			'keep non keyword syntax features' => [
				'"phrase" fuz~1 wild* "phrase pref*" AND word OR word && word || word',
				$profile,
				$toMultiMatch( '"phrase" fuz~1 wild* "phrase pref*" AND word OR word && word || word' )
			],
			'bogus syntax' => [
				'!%@@ random - AND thing " ( -',
				$profile,
				$toMultiMatch( '!%@@ random - AND thing " ( -' )
			],
			'with namespace prefix' => [
				'File:Es-us-espanol.ogg',
				$profile,
				$toMultiMatch( 'Es-us-espanol.ogg' )
			],
			'noting' => [
				'File:incategory:test',
				$profile,
				( new MatchNone() )->toArray()
			]
		];
	}

	/**
	 * @dataProvider provideTestExtraction
	 * @covers \CirrusSearch\Query\Builder\NearMatchFieldQueryBuilder
	 */
	public function testExtraction( string $inputQuery, array $profile, array $expectedQuery ) {
		$parsedQuery = $this->createNewFullTextQueryParser( new HashSearchConfig( [] ) )->parse( $inputQuery );
		$nearMatchFieldQueryBuilder = new NearMatchFieldQueryBuilder( $profile );
		$actualQuery = $nearMatchFieldQueryBuilder->buildFromParsedQuery( $parsedQuery )->toArray();
		$this->assertArrayEquals( $actualQuery, $expectedQuery );
	}

	/**
	 * @covers \CirrusSearch\Query\Builder\NearMatchFieldQueryBuilder::defaultFromSearchConfig
	 * @covers \CirrusSearch\Query\Builder\NearMatchFieldQueryBuilder::defaultFromWeight
	 */
	public function testDefaultFactory() {
		$conf = $this->newHashSearchConfig( [ 'CirrusSearchNearMatchWeight' => 3 ] );
		$nearMatchFieldQueryBuilder = NearMatchFieldQueryBuilder::defaultFromSearchConfig( $conf );
		$actualQuery = $nearMatchFieldQueryBuilder->buildFromQueryString( "my text" );
		$expectedQuery = [ 'multi_match' => [
			'query' => 'my text' ,
			'fields' => [ 'all_near_match^3', 'all_near_match.asciifolding^2.25' ]
		] ];
		$this->assertArrayEquals( $expectedQuery, $actualQuery->toArray() );
	}
}
