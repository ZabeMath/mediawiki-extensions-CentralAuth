<?php

use MediaWiki\Extension\CentralAuth\CentralAuthSessionManager;
use MediaWiki\Extension\CentralAuth\CentralAuthUtilityService;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\Session\SessionInfo;
use MediaWiki\Session\UserInfo;
use MediaWiki\User\UserNameUtils;
use Wikimedia\LightweightObjectStore\ExpirationAwareness;

/**
 * Base class for token based CentralAuth SessionProviders for use with different kinds of APIs.
 *
 * This base class provides functionality for looking at a token from the request,
 * and checking that it corresponds to an existing token generated by
 * CentralAuthTokenProvider.
 * If the token is present but invalid, a CentralAuthTokenSessionProvider returns a
 * bogus SessionInfo to prevent other SessionProviders from establishing a session.
 */
abstract class CentralAuthTokenSessionProvider extends \MediaWiki\Session\SessionProvider {
	/** @var CentralAuthSessionManager */
	private $sessionManager;

	/** @var CentralAuthUtilityService */
	private $utilityService;

	/**
	 * @param CentralAuthSessionManager $sessionManager
	 * @param CentralAuthUtilityService $utilityService
	 */
	public function __construct(
		CentralAuthSessionManager $sessionManager,
		CentralAuthUtilityService $utilityService
	) {
		parent::__construct();
		$this->sessionManager = $sessionManager;
		$this->utilityService = $utilityService;
	}

	/**
	 * Returns a bogus session, which can be used to prevent other SessionProviders
	 * from attemption to establish a session.
	 *
	 * May be overridden by subclasses to somehow cause error handling ot be triggered later.
	 *
	 * Per default, it just returns null.
	 *
	 * @param string $code Error code
	 * @param string|array $error Error message key, or key+parameters
	 * @return SessionInfo|null
	 */
	protected function makeBogusSessionInfo( $code, $error ) {
		// Then return an appropriate SessionInfo
		$id = $this->hashToSessionId( 'bogus' );
		return new SessionInfo( SessionInfo::MAX_PRIORITY, [
			'provider' => $this,
			'id' => $id,
			'userInfo' => UserInfo::newAnonymous(),
			'persisted' => false,
			'metadata' => [
				'error-code' => $code,
				'error' => $error,
			],
		] );
	}

	/**
	 * @param WebRequest $request
	 *
	 * @return string|null
	 */
	abstract protected function getTokenFromRequest( WebRequest $request );

	public function provideSessionInfo( WebRequest $request ) {
		$oneTimeToken = $this->getTokenFromRequest( $request );
		if ( $oneTimeToken === null ) {
			return null;
		}

		$this->logger->debug( __METHOD__ . ': Found a token!' );

		$tokenStore = $this->sessionManager->getTokenStore();
		$key = $this->sessionManager->memcKey( 'api-token', $oneTimeToken );
		$timeout = $this->getConfig()->get( 'CentralAuthTokenSessionTimeout' );
		$data = $this->utilityService->getKeyValueUponExistence( $tokenStore, $key, $timeout );
		if ( !is_array( $data ) ||
			!isset( $data['userName'] ) ||
			!isset( $data['token'] ) ||
			!isset( $data['origin'] ) ||
			!isset( $data['originSessionId'] )
		) {
			$this->logger->debug( __METHOD__ . ': centralauthtoken is invalid' );
			return $this->makeBogusSessionInfo( 'badtoken', 'apierror-centralauth-badtoken' );
		}

		$userName = $data['userName'];
		$authToken = $data['token'];

		// Clean up username
		$userName = $this->userNameUtils->getCanonical( $userName, UserNameUtils::RIGOR_VALID );
		if ( !$userName ) {
			$this->logger->debug( __METHOD__ . ': invalid username' );
			return $this->makeBogusSessionInfo( 'badtoken', 'apierror-centralauth-badtoken' );
		}
		if ( !$this->userNameUtils->isUsable( $userName ) ) {
			$this->logger->debug( __METHOD__ . ': unusable username' );
			return $this->makeBogusSessionInfo( 'badusername',
				[ 'apierror-centralauth-badusername', wfEscapeWikiText( $userName ) ] );
		}

		// Try the central user
		$centralUser = CentralAuthUser::getInstanceByName( $userName );

		// Skip if they're being renamed
		if ( $centralUser->renameInProgress() ) {
			$this->logger->debug( __METHOD__ . ': rename in progress' );
			return $this->makeBogusSessionInfo(
				'renameinprogress', 'apierror-centralauth-renameinprogress'
			);
		}

		if ( !$centralUser->exists() ) {
			$this->logger->debug( __METHOD__ . ': global account doesn\'t exist' );
			return $this->makeBogusSessionInfo( 'badtoken', 'apierror-centralauth-badtoken' );
		}
		if ( !$centralUser->isAttached() && User::idFromName( $userName ) ) {
			$this->logger->debug( __METHOD__ . ': not attached and local account exists' );
			return $this->makeBogusSessionInfo( 'badtoken', 'apierror-centralauth-badtoken' );
		}

		$key = $this->sessionManager->memcKey( 'api-token-blacklist', (string)$centralUser->getId() );
		$sessionStore = $this->sessionManager->getSessionStore();
		if ( $sessionStore->get( $key ) ) {
			$this->logger->debug( __METHOD__ . ': user is blacklisted' );
			return $this->makeBogusSessionInfo( 'badtoken', 'apierror-centralauth-badtoken' );
		}

		if ( $centralUser->authenticateWithToken( $authToken ) != 'ok' ) {
			$this->logger->debug( __METHOD__ . ': token mismatch' );
			return $this->makeBogusSessionInfo( 'badtoken', 'apierror-centralauth-badtoken' );
		}

		$this->logger->debug( __METHOD__ . ': logged in from session' );

		$info = [
			'userInfo' => UserInfo::newFromName( $userName, true ),
			'provider' => $this,
			'id' => $this->hashToSessionId( implode( "\n", $data ) ),
			'persisted' => true,
			'forceUse' => true,
		];

		if ( !$this->consumeToken( $oneTimeToken ) ) {
			// Raced out trying to mark the token as expired
			return $this->makeBogusSessionInfo( 'badtoken', 'apierror-centralauth-badtoken' );
		}

		return new SessionInfo( SessionInfo::MAX_PRIORITY, $info );
	}

	/**
	 * @param string $token
	 *
	 * @return bool
	 */
	protected function consumeToken( $token ) {
		$tokenStore = $this->sessionManager->getTokenStore();
		$key = $this->sessionManager->memcKey( 'api-token', $token );

		if ( !$tokenStore->changeTTL( $key, time() - 3600, BagOStuff::WRITE_SYNC ) ) {
			$this->logger->error( 'Raced out trying to mark the token as expired' );
			return false;
		}

		return true;
	}

	public function persistsSessionId() {
		return false;
	}

	public function canChangeUser() {
		return false;
	}

	public function persistSession(
		MediaWiki\Session\SessionBackend $session, WebRequest $request
	) {
		// Nothing to do
	}

	public function unpersistSession( WebRequest $request ) {
		// Nothing to do
	}

	public function invalidateSessionsForUser( User $user ) {
		$centralUser = CentralAuthUser::getPrimaryInstance( $user );
		if ( $centralUser->exists() && ( $centralUser->isAttached() || !$user->isRegistered() ) ) {
			$centralUser->resetAuthToken();
		}
	}

	public function preventSessionsForUser( $username ) {
		$username = $this->userNameUtils->getCanonical( $username, UserNameUtils::RIGOR_VALID );
		if ( !$username ) {
			return;
		}

		$centralUser = CentralAuthUser::getInstanceByName( $username );
		if ( !$centralUser->exists() ) {
			return;
		}

		// Assume blacklisting for a day will be enough because we assume by
		// then CentralAuth itself will have been instructed to more
		// permanently block the user.
		$sessionStore = $this->sessionManager->getSessionStore();
		$key = $this->sessionManager->memcKey( 'api-token-blacklist', (string)$centralUser->getId() );
		$sessionStore->set( $key, true, ExpirationAwareness::TTL_DAY, BagOStuff::WRITE_SYNC );
	}

}
