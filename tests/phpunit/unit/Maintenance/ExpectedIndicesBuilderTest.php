<?php

namespace extensions\CirrusSearch\tests\phpunit\unit\Maintenance;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\Maintenance\ExpectedIndicesBuilder;

class ExpectedIndicesBuilderTest extends CirrusTestCase {
	public static function provdeTestBuild(): \Generator {
		$config = [
			'_wikiID' => 'mywiki_id',
			'CirrusSearchIndexBaseName' => '__wikiid__',
			'CirrusSearchClusters' => [
				'one' => [
					'host' => '127.0.0.1',
					'secret' => 'somesecret',
				],
				'two' => [
					'host' => '127.0.0.2',
					'secret' => 'somesecret'
				],
			],
			'CirrusSearchDefaultCluster' => 'one',
			'CirrusSearchWriteClusters' => null,
			'CirrusSearchReplicaGroup' => 'mygroup',
			'CirrusSearchNamespaceMappings' => [
				NS_FILE => 'extraindex'
			],
			'CirrusSearchEnableArchive' => true
		];
		yield 'all with conn info' => [
			$config, true, null,
			[
				"dbname" => "mywiki_id",
				"clusters" => [
					"one" => [
						"aliases" => [
							"mywiki_id_content",
							"mywiki_id_general",
							"mywiki_id_archive",
							"mywiki_id_extraindex",
						],
						"group" => "mygroup",
						"connection" => [
							'host' => '127.0.0.1',
							'secret' => 'somesecret',
						],
					],
					"two" => [
						"aliases" => [
							"mywiki_id_content",
							"mywiki_id_general",
							"mywiki_id_archive",
							"mywiki_id_extraindex",
						],
						"group" => "mygroup",
						"connection" => [
							'host' => '127.0.0.2',
							'secret' => 'somesecret',
						],
					],
				]
			]
		];
		yield 'two with conn info' => [
			$config, true, "two",
			[
				"dbname" => "mywiki_id",
				"clusters" => [
					"two" => [
						"aliases" => [
							"mywiki_id_content",
							"mywiki_id_general",
							"mywiki_id_archive",
							"mywiki_id_extraindex",
						],
						"group" => "mygroup",
						"connection" => [
							'host' => '127.0.0.2',
							'secret' => 'somesecret',
						],
					],
				]
			]
		];
		yield 'all without conn info' => [
			$config, false, null,
			[
				"dbname" => "mywiki_id",
				"clusters" => [
					"one" => [
						"aliases" => [
							"mywiki_id_content",
							"mywiki_id_general",
							"mywiki_id_archive",
							"mywiki_id_extraindex",
						],
						"group" => "mygroup"
					],
					"two" => [
						"aliases" => [
							"mywiki_id_content",
							"mywiki_id_general",
							"mywiki_id_archive",
							"mywiki_id_extraindex",
						],
						"group" => "mygroup"
					],
				]
			]
		];
		yield 'one without conn info' => [
			$config, false, "one",
			[
				"dbname" => "mywiki_id",
				"clusters" => [
					"one" => [
						"aliases" => [
							"mywiki_id_content",
							"mywiki_id_general",
							"mywiki_id_archive",
							"mywiki_id_extraindex",
						],
						"group" => "mygroup"
					]
				]
			]
		];
	}

	/**
	 * @dataProvider provdeTestBuild
	 * @covers \CirrusSearch\Maintenance\ExpectedIndices
	 */
	public function testBuild( array $config, bool $withCon, ?string $cluster, array $expectedIndices ) {
		$config = $this->newHashSearchConfig( $config );
		$builder = new ExpectedIndicesBuilder( $config );
		$this->assertArrayEquals( $expectedIndices, $builder->build( $withCon, $cluster ) );
	}
}
