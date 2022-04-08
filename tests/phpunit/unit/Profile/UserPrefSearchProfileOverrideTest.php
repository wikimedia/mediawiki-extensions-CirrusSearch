<?php

namespace CirrusSearch\Profile;

use CirrusSearch\CirrusTestCase;
use MediaWiki\User\StaticUserOptionsLookup;
use MediaWiki\User\UserIdentityValue;
use const null;

/**
 * @group CirrusSearch
 * @covers \CirrusSearch\Profile\UserPrefSearchProfileOverride
 */
class UserPrefSearchProfileOverrideTest extends CirrusTestCase {

	public function testNormalUseCase() {
		$override = $this->getUserPrefSearchProfileOverride( 'test-profile-user-pref' );

		$this->assertEquals( SearchProfileOverride::USER_PREF_PRIO, $override->priority() );
		$this->assertEquals( 'overridden', $override->getOverriddenName( [] ) );
		$this->assertEquals(
			[
				'type' => 'userPreference',
				'priority' => SearchProfileOverride::USER_PREF_PRIO,
				'userPreference' => 'test-profile-user-pref'
			],
			$override->explain()
		);
	}

	public function testWithoutPref() {
		$override = $this->getUserPrefSearchProfileOverride( 'test-profile-user-pref2' );

		$this->assertNull( $override->getOverriddenName( [] ) );
	}

	public function testCustomPrio() {
		$username = 'test';
		$userOptionsLookup = new StaticUserOptionsLookup( [ $username => [
			'test-profile-user-pref' => 'overridden' ] ] );

		$override = new UserPrefSearchProfileOverride( new UserIdentityValue( 1, $username ),
			$userOptionsLookup, 'test-profile-user-pref', 123 );

		$this->assertEquals( 123, $override->priority() );
	}

	/**
	 * @return \User
	 */
	private function getMyTestUser() {
		$testUser = $this->getTestUser();
		$user = $testUser->getUser();
		$this->getServiceContainer()->getUserOptionsManager()->setOption( $user, 'test-profile-user-pref', 'overridden' );
		return $user;
	}

	/**
	 * @param string $preference
	 * @return UserPrefSearchProfileOverride
	 */
	private function getUserPrefSearchProfileOverride( string $preference ) {
		$username = 'test';
		$userOptionsLookup = new StaticUserOptionsLookup( [ $username => [
			'test-profile-user-pref' => 'overridden' ] ] );

		return new UserPrefSearchProfileOverride( new UserIdentityValue( 1, $username ),
			$userOptionsLookup, $preference );
	}

}
