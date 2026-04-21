<?php

namespace CirrusSearch;

use MediaWiki\MainConfigNames;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * @covers \CirrusSearch\NamespaceMatcher
 */
class NamespaceMatcherIntegrationTest extends CirrusIntegrationTestCase {

	/**
	 * @dataProvider provideTestIdentifyNamespace
	 * @param string $namespace
	 * @param int|null $expected
	 * @param string $method
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 */
	public function testIdentifyNamespace( string $namespace, ?int $expected, string $method ): void {
		$this->overrideConfigValues( [
			MainConfigNames::ExtraNamespaces => [
				100 => 'Maçon',
				101 => 'Cédille',
				102 => 'Groß',
				103 => 'Norræn goðafræði',
				104 => 'لَحَم', // لحم
				105 => 'Thảo_luận',
			],
			MainConfigNames::NamespaceAliases => [
				'Mañsoner' => 100,
			],
			'CirrusSearchNamespaceResolutionMethod' => $method
		] );
		$namespaceMatcher = $this->getServiceContainer()->get( NamespaceMatcher::SERVICE );
		$this->assertEquals( $expected, $namespaceMatcher->identifyNamespace( $namespace ) );
	}

	public static function provideTestIdentifyNamespace(): array {
		return [
			'simple' => [ 'macon', 100, 'naive' ],
			'simple utr30' => [ 'macon', 100, 'utr30' ],
			'both sides' => [ 'mäcon', 100, 'naive' ],
			'both sides utr30' => [ 'mäcon', 100, 'utr30' ],
			'simple alias' => [ 'mansoner', 100, 'naive' ],
			'simple alias utr30' => [ 'mansoner', 100, 'utr30' ],
			'no match' => [ 'maçons', null, 'naive' ],
			'no match utr30' => [ 'maçons', null, 'utr30' ],
			'arabic' => [ 'لحم', 104, 'naive' ],
			'arabic utr30' => [ 'لحم', 104, 'utr30' ],
			'gods are not naive' => [ 'norræn godafræði', null, 'naive' ],
			'gods are weak with utr30' => [ 'norraen godafraeði', 103, 'utr30' ],
			'case folding can be gross' => [ 'gross', 102, 'naive' ],
			'case folding can be gross even with utr30' => [ 'gross', 102, 'utr30' ]
		];
	}

}
