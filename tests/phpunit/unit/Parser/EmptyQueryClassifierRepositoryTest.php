<?php declare( strict_types=1 );

namespace phpunit\unit\Parser;

use CirrusSearch\Parser\AST\ParsedQuery;
use CirrusSearch\Parser\EmptyQueryClassifiersRepository;
use CirrusSearch\Parser\ParsedQueryClassifier;
use CirrusSearch\Parser\ParsedQueryClassifierException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \CirrusSearch\Parser\EmptyQueryClassifiersRepository
 * @group CirrusSearch
 * @license GPL-2.0-or-later
 */
class EmptyQueryClassifierRepositoryTest extends TestCase {

	public function testGetConfig() {
		$repo = new EmptyQueryClassifiersRepository();
		$this->expectException( RuntimeException::class );
		$repo->getConfig();
	}

	public function testGetClassifier() {
		$repo = new EmptyQueryClassifiersRepository();
		$this->expectException( ParsedQueryClassifierException::class );
		$repo->getClassifier( 'anything' );
	}

	public function testGetKnownClassifiers() {
		$repo = new EmptyQueryClassifiersRepository();
		$this->assertSame( [], $repo->getKnownClassifiers() );
	}

	public function testRegister() {
		$repo = new EmptyQueryClassifiersRepository();

		$repo->registerClassifier(
			new class implements ParsedQueryClassifier {
				public function classify( ParsedQuery $query ): array {
					return [ 'hook1' ];
				}

				public function classes(): array {
					return [ 'hook1' ];
				}
			}
		);

		$repo->registerClassifierAsCallable(
			[ 'hook2' ],
			static function ( ParsedQuery $query ): array {
				return [ 'hook2' ];
			}
		);

		$this->assertSame( [], $repo->getKnownClassifiers() );
	}

}
