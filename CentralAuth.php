<?php

/**
 * Database name you keep central auth data in.
 *
 * If this is not on the primary database connection, don't forget
 * to also set up $wgDBservers to have an entry with a groupLoads
 * setting for the 'CentralAuth' group.
 */
$wgCentralAuthDatabase = 'centralauth';

/**
 * If true, new account registrations will be registered globally if
 * the username hasn't been used elsewhere.
 */
$wgCentralAuthAutoNew = false;

/**
 * If true, existing unattached accounts will be automatically migrated
 * if possible at first login.
 *
 * Any new account creations will be required to attach.
 *
 * If false, unattached accounts will not be harassed unless the individual
 * account has opted in to migration.
 */
$wgCentralAuthAutoMigrate = false;

/**
 * If true, remaining accounts which have not been attached will be forbidden
 * from logging in until they are resolved.
 */
$wgCentralAuthStrict = false;

/**
 * If true, merging won't actually be possible through the Special:MergeAccount
 * interface.
 */
$wgCentralAuthDryRun = false;

$wgExtensionCredits['specialpage'][] = array(
	'name' => 'Central Auth',
	'url' => 'http://www.mediawiki.org/wiki/Extension:CentralAuth',
	'version' => preg_replace('/^.* (\d\d\d\d-\d\d-\d\d) .*$/', '\1', '$LastChangedDate$'), #just the date of the last change
	'author' => 'Brion Vibber',
	'description' => 'Merge Account across Wikimedia Foundation wikis',
	'descriptionmsg' => 'centralauth-desc',
);

/**
 * Initialization of the autoloaders, and special extension pages.
 */
$caBase = dirname( __FILE__ );
$wgAutoloadClasses['SpecialCentralAuth'] = "$caBase/SpecialCentralAuth.php";
$wgAutoloadClasses['SpecialMergeAccount'] = "$caBase/SpecialMergeAccount.php";
$wgAutoloadClasses['CentralAuthUser'] = "$caBase/CentralAuthUser.php";
$wgAutoloadClasses['CentralAuthPlugin'] = "$caBase/CentralAuthPlugin.php";
$wgAutoloadClasses['WikiMap'] = "$caBase/WikiMap.php";
$wgAutoloadClasses['WikiReference'] = "$caBase/WikiMap.php";
$wgExtensionMessagesFiles['SpecialCentralAuth'] = "$caBase/CentralAuth.i18n.php";

$wgHooks['AuthPluginSetup'][] = 'wfSetupCentralAuthPlugin';
$wgHooks['AddNewAccount'][] = 'wfCentralAuthAddNewAccount';
$wgHooks['PreferencesUserInformationPanel'][] = 'wfCentralAuthInformationPanel';
$wgHooks['AbortNewAccount'][] = 'wfCentralAuthAbortNewAccount';

// For interaction with the Special:Renameuser extension
$wgHooks['RenameUserAbort'][] = 'wfCentralAuthRenameUserAbort';
$wgHooks['RenameUserComplete'][] = 'wfCentralAuthRenameUserComplete';

$wgGroupPermissions['steward']['centralauth-admin'] = true;
$wgGroupPermissions['*']['centralauth-merge'] = true;

$wgSpecialPages['CentralAuth'] = 'SpecialCentralAuth';
$wgSpecialPages['MergeAccount'] = 'SpecialMergeAccount';

function wfSetupCentralAuthPlugin( &$auth ) {
	$auth = new StubObject( 'wgAuth', 'CentralAuthPlugin' );
	return true;
}

/**
 * Add a little pretty to the preferences user info section
 */
function wfCentralAuthInformationPanel( $prefsForm, &$html ) {
	global $wgUser;

	if ( !$wgUser->isAllowed( 'centralauth-merge' ) ) {
		// Not allowed to merge, don't display merge information
		return true;
	}

	wfLoadExtensionMessages('SpecialCentralAuth');
	$skin = $wgUser->getSkin();
	$special = SpecialPage::getTitleFor( 'MergeAccount' );


	// Possible states:
	// - account not merged at all
	// - global accounts exists, but this local account is unattached
	// - this local account is attached, but migration incomplete
	// - all local accounts are attached

	$global = new CentralAuthUser( $wgUser->getName() );
	if( $global->exists() ) {
		if( $global->isAttached() ) {
			// Local is attached...
			$attached = count( $global->listAttached() );
			$unattached = count( $global->listUnattached() );
			if( $unattached ) {
				// Migration incomplete
				$message = '<strong>' . wfMsgHtml( 'centralauth-prefs-migration' ) . '</strong>' .
					'<br />' .
					htmlspecialchars( wfMsgExt( 'centralauth-prefs-count-attached', array( 'parsemag' ), $attached ) ) .
					'<br />' .
					htmlspecialchars( wfMsgExt( 'centralauth-prefs-count-unattached', array( 'parsemag' ), $unattached ) );
			} else {
				// Migration complete
				$message = '<strong>' . wfMsgHtml( 'centralauth-prefs-complete' ) . '</strong>' .
					'<br />' .
					htmlspecialchars( wfMsgExt( 'centralauth-prefs-count-attached', array( 'parsemag' ), $attached ) );
			}
		} else {
			// Account is in migration, but the local account is not attached
			$message = '<strong>' . wfMsgHtml( 'centralauth-prefs-unattached' ) . '</strong>' .
				'<br />' .
				wfMsgHtml( 'centralauth-prefs-detail-unattached' );
		}
	} else {
		// Not migrated.
		$message = wfMsgHtml( 'centralauth-prefs-not-managed' );
	}

	$manageLink = $skin->makeKnownLinkObj( $special,
		wfMsgHtml( 'centralauth-prefs-manage' ) );
	$html .= $prefsForm->tableRow(
		wfMsgHtml( 'centralauth-prefs-status' ),
		"$message<br />($manageLink)" );
	return true;
}

/**
 * Make sure migration information in localuser table is populated
 * on local account creation
 */
function wfCentralAuthAddNewAccount( $user ) {
	global $wgDBname;
	$central = new CentralAuthUser( $user->getName() );
	$central->addLocalName( $wgDBname );
	return true;
}

/**
 * Don't allow an attached local account to be renamed with the old system.
 */
function wfCentralAuthRenameUserAbort( $userId, $oldName, $newName ) {
	$oldCentral = new CentralAuthUser( $oldName );
	if ( $oldCentral->exists() && $oldCentral->isAttached() ) {
		global $wgOut;
		wfLoadExtensionMessages('SpecialCentralAuth');
		$wgOut->addWikiMsg( 'centralauth-renameuser-abort', $oldName, $newName );
		return false;
	}
	$newCentral = new CentralAuthUser( $newName );
	if ( $newCentral->exists() ) {
		global $wgOut;
		wfLoadExtensionMessages('SpecialCentralAuth');
		$wgOut->addWikiMsg( 'centralauth-renameuser-exists', $oldName, $newName );
		return false;
	}

	// If no central record is present or this local account isn't attached,
	// do as thou wilt.
	return true;
}

/**
 * When renaming an account, ensure that the presence records are updated.
 */
function wfCentralAuthRenameUserComplete( $userId, $oldName, $newName ) {
	global $wgDBname;

	$oldCentral = new CentralAuthUser( $oldName );
	$oldCentral->removeLocalName( $wgDBname );

	$newCentral = new CentralAuthUser( $newName );
	$newCentral->addLocalName( $wgDBname );

	return true;
}

function wfCentralAuthAbortNewAccount( $user, &$abortError ) {
	$centralUser = new CentralAuthUser( $user->getName() );
	if ( $centralUser->exists() ) {
		wfLoadExtensionMessages('SpecialCentralAuth');
		$abortError = wfMsg( 'centralauth-account-exists' );
		return false;
	}
	return true;
}

