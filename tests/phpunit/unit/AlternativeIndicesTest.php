<?php

namespace CirrusSearch;

use CirrusSearch\Search\AlternativeIndex;

/**
 * @covers \CirrusSearch\AlternativeIndices \CirrusSearch\AlternativeIndex
 */
class AlternativeIndicesTest extends CirrusTestCase {
	public function testHappyPath() {
		$hostConfig = $this->newHashSearchConfig( [
			'CirrusSearchIndexBaseName' => 'mywiki',
			'CirrusSearchOverriddenConfig' => 'host',
			'CirrusSearchHostConfig' => 'host',
			'CirrusSearchClusters' => [
				'mycluster' => [],
			],
			'CirrusSearchDefaultCluster' => 'mycluster',
			'CirrusSearchWriteClusters' => null,
			'CirrusSearchReplicaGroup' => 'default',
			'CirrusSearchAlternativeIndices' => [
				'completion' => [
					[
						'index_id' => 1,
						'use' => true,
						'config_overrides' => [
							'CirrusSearchOverriddenConfig' => 'overridden',
							'CirrusSearchNewConfig' => 'overridden',
						],
					]
				]
			],
		] );

		$altIndices = AlternativeIndices::build( $hostConfig );
		$altIndex = $altIndices->getAlternativeIndexById( AlternativeIndices::COMPLETION, 1 );
		$this->assertSame( 1, $altIndex->getId() );
		$this->assertTrue( $altIndex->isUse() );
		$this->assertEquals( AlternativeIndices::COMPLETION, $altIndex->getType() );
		$this->assertEquals( 'host', $altIndex->getConfig()->get( 'CirrusSearchHostConfig' ) );
		$this->assertEquals( 'overridden', $altIndex->getConfig()->get( 'CirrusSearchOverriddenConfig' ) );
		$this->assertEquals( 'overridden', $altIndex->getConfig()->get( 'CirrusSearchNewConfig' ) );
		$connection = new Connection( $hostConfig );

		$this->assertEquals(
			$connection->getIndex( 'mywiki', Connection::TITLE_SUGGEST_INDEX_SUFFIX, false, true, 1 ),
			$altIndex->getIndex( $connection )
		);
	}

	public function providesBadConfig(): \Generator {
		yield 'missing index_id' => [
			[
				'CirrusSearchAlternativeIndices' => [
					'completion' => [ [] ]
				],
			]
		];
		yield 'invalid index_id' => [
			[
				'CirrusSearchAlternativeIndices' => [
					'completion' => [
						[ 'index_id' => 'foo' ]
					]
				]
			]
		];
		yield 'duplicated index_id' => [
			[
				'CirrusSearchAlternativeIndices' => [
					'completion' => [
						[ 'index_id' => 0 ],
						[ 'index_id' => 0 ],
					]
				]
			]
		];
		yield 'use is boolean index_id' => [
			[
				'CirrusSearchAlternativeIndices' => [
					'completion' => [
						[ 'index_id' => 0, 'use' => 'yes' ],
					]
				]
			]
		];
		yield 'config overrides is an array' => [
			[
				'CirrusSearchAlternativeIndices' => [
					'completion' => [
						[ 'index_id' => 0, 'config_overrides' => 'yes' ],
					]
				]
			]
		];
	}

	/**
	 * @dataProvider providesBadConfig
	 */
	public function testBadConfig( array $config, string $type = AlternativeIndices::COMPLETION ): void {
		$this->expectException( \ConfigException::class );
		AlternativeIndices::build( $this->newHashSearchConfig( $config ) )->getAlternativeIndices( $type );
	}

	public function testIsInstance() {
		$hostConfig = $this->newHashSearchConfig( [
			'CirrusSearchClusters' => [
				'mycluster' => [],
			],
			'CirrusSearchDefaultCluster' => 'mycluster',
			'CirrusSearchWriteClusters' => null,
			'CirrusSearchReplicaGroup' => 'default'
		] );
		$connection = new Connection( $hostConfig );
		$altIndex = new AlternativeIndex( 1, AlternativeIndices::COMPLETION, false,
			$this->newHashSearchConfig( [ 'CirrusSearchIndexBaseName' => 'mywiki' ] ), [] );
		$this->assertTrue( $altIndex->isInstanceIndex( "mywiki_titlesuggest_alt_1_123", $connection ) );
		$this->assertTrue( $altIndex->isInstanceIndex( "mywiki_titlesuggest_alt_1_2348", $connection ) );
		$this->assertFalse( $altIndex->isInstanceIndex( "mywiki_titlesuggest_alt_2_2348", $connection ) );
		$this->assertFalse( $altIndex->isInstanceIndex( "mywiki_othertype_alt_1_2348", $connection ) );
		$this->assertFalse( $altIndex->isInstanceIndex( "mywiki_othertype_alt_1", $connection ) );
	}

	public function provideIsValidIndexId(): \Generator {
		yield 'good int' => [ 0, true ];
		yield 'good string' => [ "0", true ];
		yield 'negative int' => [ -1, false ];
		yield 'bad string' => [ "-1", false ];
		yield 'empty string' => [ "", false ];
		yield 'array' => [ [ 0 ], false ];
		yield 'class' => [ new \stdClass(), false ];
	}

	/**
	 * @dataProvider provideIsValidIndexId
	 */
	public function testIdValidIndexId( mixed $id, bool $valid ): void {
		$this->assertEquals( $valid, AlternativeIndices::isValidAltIndexId( $id ) );
	}
}
