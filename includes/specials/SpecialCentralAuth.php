<?php

use MediaWiki\Block\Restriction\NamespaceRestriction;
use MediaWiki\Block\Restriction\PageRestriction;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserNameUtils;

class SpecialCentralAuth extends SpecialPage {
	/** @var string */
	private $mUserName;
	/** @var bool */
	private $mCanUnmerge;
	/** @var bool */
	private $mCanLock;
	/** @var bool */
	private $mCanOversight;
	/** @var bool */
	private $mCanEdit;
	/** @var bool */
	private $mCanChangeGroups;

	/**
	 * @var CentralAuthUser
	 */
	private $mGlobalUser;

	/**
	 * @var array[]
	 */
	private $mAttachedLocalAccounts;

	/**
	 * @var array
	 */
	private $mUnattachedLocalAccounts;

	/** @var string */
	private $mMethod;

	/** @var bool */
	private $mPosted;

	/** @var string[] */
	private $mWikis;

	/** @var UserNameUtils */
	private $userNameUtils;

	public function __construct( UserNameUtils $userNameUtils ) {
		parent::__construct( 'CentralAuth' );
		$this->userNameUtils = $userNameUtils;
	}

	public function doesWrites() {
		return true;
	}

	public function execute( $subpage ) {
		$this->setHeaders();
		$this->addHelpLink( 'Extension:CentralAuth' );

		$authority = $this->getContext()->getAuthority();
		$this->mCanUnmerge = $authority->isAllowed( 'centralauth-unmerge' );
		$this->mCanLock = $authority->isAllowed( 'centralauth-lock' );
		$this->mCanOversight = $authority->isAllowed( 'centralauth-oversight' );
		$this->mCanEdit = $this->mCanUnmerge || $this->mCanLock || $this->mCanOversight;
		$this->mCanChangeGroups = $authority->isAllowed( 'globalgroupmembership' );

		$this->getOutput()->setPageTitle(
			$this->msg( $this->mCanEdit ? 'centralauth' : 'centralauth-ro' )
		);
		$this->getOutput()->addModules( 'ext.centralauth' );
		$this->getOutput()->addModules( 'ext.centralauth.globaluserautocomplete' );
		$this->getOutput()->addModuleStyles( 'ext.centralauth.misc.styles' );
		$this->getOutput()->addJsConfigVars(
			'wgMergeMethodDescriptions', $this->getMergeMethodDescriptions()
		);

		$this->mUserName =
			trim(
				str_replace( '_', ' ',
					$this->getRequest()->getText( 'target', $subpage ) ) );

		$this->mUserName = MediaWikiServices::getInstance()->getContentLanguage()
			->ucfirst( $this->mUserName );

		$this->mPosted = $this->getRequest()->wasPosted();
		$this->mMethod = $this->getRequest()->getVal( 'wpMethod' );
		$this->mWikis = (array)$this->getRequest()->getArray( 'wpWikis' );

		// Possible demo states

		// success, all accounts merged
		// successful login, some accounts merged, others left
		// successful login, others left
		// not account owner, others left

		// is owner / is not owner
		// did / did not merge some accounts
		// do / don't have more accounts to merge

		if ( $this->mUserName === '' ) {
			# First time through
			$this->getOutput()->addWikiMsg( 'centralauth-admin-intro' );
			$this->showUsernameForm();
			return;
		}

		$userPage = Title::makeTitleSafe( NS_USER, $this->mUserName );
		if ( $userPage ) {
			$localUser = User::newFromName( $userPage->getText(), false );
			$this->getSkin()->setRelevantUser( $localUser );
		}

		// per bug 47991
		$this->getOutput()->setHTMLTitle( $this->msg(
			'pagetitle',
			$this->msg(
				$this->mCanEdit ? 'centralauth-admin-title' : 'centralauth-admin-title-ro',
				$this->mUserName
			)->plain()
		) );

		$globalUser = $this->getRequest()->wasPosted()
			? CentralAuthUser::getMasterInstanceByName( $this->mUserName )
			: CentralAuthUser::getInstanceByName( $this->mUserName );
		$this->mGlobalUser = $globalUser;

		if ( ( $globalUser->isOversighted() || $globalUser->isHidden() ) &&
			!$this->mCanOversight
		) {
			// Claim that there's nothing if the global account is hidden and the user is not
			// allowed to see it.
			$this->showNonexistentError();
			return;
		}

		$continue = true;
		if ( $this->mCanEdit && $this->mPosted ) {
			if ( wfReadOnly() || CentralAuthUtils::getCentralDB()->isReadOnly() ) {
				throw new ReadOnlyError();
			}
			$continue = $this->doSubmit();
		}

		// Show just a user friendly message when a rename is in progress
		try {
			$this->mAttachedLocalAccounts = $globalUser->queryAttached();
		} catch ( Exception $e ) {
			if ( $globalUser->renameInProgress() ) {
				$this->showRenameInProgressError();
				return;
			}
			// Rethrow
			throw $e;
		}

		$this->mUnattachedLocalAccounts = $globalUser->queryUnattached();

		if ( !$globalUser->exists() && !count( $this->mUnattachedLocalAccounts ) ) {
			// Nothing to see here
			$this->showNonexistentError();
			return;
		}

		$this->showUsernameForm();
		if ( $continue && $globalUser->exists() ) {
			$this->showInfo();
			if ( $this->mCanLock ) {
				$this->showStatusForm();
			}
			if ( $this->mCanUnmerge ) {
				$this->showActionForm( 'delete' );
			}
			$this->showLogExtract();
			$this->showWikiLists();
		} elseif ( $continue && !$globalUser->exists() ) {
			// No global account, but we can still list the local ones
			$this->showError( 'centralauth-admin-nonexistent', $this->mUserName );
			$this->showWikiLists();
		}
	}

	private function showNonexistentError() {
		$this->showError( 'centralauth-admin-nonexistent', $this->mUserName );
		$this->showUsernameForm();
	}

	private function showRenameInProgressError() {
		$this->showError( 'centralauth-admin-rename-in-progress', $this->mUserName );
		$special = new SpecialGlobalRenameProgress( $this->userNameUtils );
		$special->setContext( $this->getContext() );
		$renameStatus = new GlobalRenameUserStatus( $this->mUserName );
		$names = $renameStatus->getNames();
		$special->showLogExtract( $names[1] );
	}

	/**
	 * @return bool Returns true if the normal form should be displayed
	 */
	public function doSubmit() {
		$deleted = false;
		$globalUser = $this->mGlobalUser;
		$request = $this->getRequest();
		$stateCheck = $request->getVal( 'wpUserState' ) === $globalUser->getStateHash( true );

		if ( !$this->getUser()->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
			$this->showError( 'centralauth-token-mismatch' );
		} elseif ( $this->mMethod == 'unmerge' && $this->mCanUnmerge ) {
			$status = $globalUser->adminUnattach( $this->mWikis );
			if ( !$status->isGood() ) {
				$this->showStatusError( $status->getWikiText() );
			} else {
				$this->showSuccess( 'centralauth-admin-unmerge-success',
					$this->getLanguage()->formatNum( $status->successCount ),
					/* deprecated */ $status->successCount );
			}
		} elseif ( $this->mMethod == 'delete' && $this->mCanUnmerge ) {
			$status = $globalUser->adminDelete( $request->getVal( 'reason' ), $this->getUser() );
			if ( !$status->isGood() ) {
				$this->showStatusError( $status->getWikiText() );
			} else {
				$this->showSuccess( 'centralauth-admin-delete-success', $this->mUserName );
				$deleted = true;
			}
		} elseif ( $this->mMethod == 'set-status' && !$stateCheck ) {
			$this->showError( 'centralauth-state-mismatch' );
		} elseif ( $this->mMethod == 'set-status' && $this->mCanLock ) {
			$setLocked = $request->getBool( 'wpStatusLocked' );
			$setHidden = $request->getVal( 'wpStatusHidden' );
			$reason = $request->getText( 'wpReasonList' );
			$reasonDetail = $request->getText( 'wpReason' );

			if ( $reason == 'other' ) {
				$reason = $reasonDetail;
			} elseif ( $reasonDetail ) {
				$reason .= $this->msg( 'colon-separator' )->inContentLanguage()->text() .
					$reasonDetail;
			}

			$status = $globalUser->adminLockHide(
				$setLocked,
				$setHidden,
				$reason,
				$this->getContext()
			);

			// Tell the user what happened
			if ( !$status->isGood() ) {
				$this->showStatusError( $status->getWikiText() );
			} elseif ( $status->successCount > 0 ) {
				$this->showSuccess( 'centralauth-admin-setstatus-success', $this->mUserName );
			}
		} else {
			$this->showError( 'centralauth-admin-bad-input' );
		}
		return !$deleted;
	}

	/**
	 * @param string $wikitext
	 */
	private function showStatusError( $wikitext ) {
		$this->getOutput()->wrapWikiTextAsInterface(
			'error', $wikitext
		);
	}

	private function showError( ...$args ) {
		$this->getOutput()->wrapWikiMsg( '<div class="error">$1</div>', $args );
	}

	private function showSuccess( ...$args ) {
		$this->getOutput()->wrapWikiMsg( '<div class="success">$1</div>', $args );
	}

	private function showUsernameForm() {
		$lookup = $this->msg(
			$this->mCanEdit ? 'centralauth-admin-lookup-rw' : 'centralauth-admin-lookup-ro'
		)->text();

		$formDescriptor = [
			'user' => [
				'class' => HTMLGlobalUserTextField::class,
				'name' => 'target',
				'label-message' => 'centralauth-admin-username',
				'size' => 25,
				'id' => 'target',
				'default' => $this->mUserName
			]
		];

		$legend = $this->msg( $this->mCanEdit ? 'centralauth-admin-manage' : 'centralauth-admin-view' )
		->text();

		$context = new DerivativeContext( $this->getContext() );
		$context->setTitle( $this->getPageTitle() ); // Remove subpage
		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $context );
		$htmlForm
			->setMethod( 'get' )
			->setSubmitText( $lookup )
			->setSubmitID( 'centralauth-submit-find' )
			->setWrapperLegend( $legend )
			->prepareForm()
			->displayForm( false );
	}

	/**
	 * @param int $span
	 * @return string
	 */
	public function prettyTimespan( $span ) {
		// @FIXME: This is nastily being used in SpecialMultiLock... fix that
		$units = [
			'seconds' => 60,
			'minutes' => 60,
			'hours' => 24,
			'days' => 30.417,
			'months' => 12,
			'years' => 1
		];
		foreach ( $units as $unit => $chunk ) {
			// Used messaged (to make sure that grep finds them):
			// 'centralauth-seconds-ago', 'centralauth-minutes-ago', 'centralauth-hours-ago'
			// 'centralauth-days-ago', 'centralauth-months-ago', 'centralauth-years-ago'
			if ( $span < 2 * $chunk ) {
				return $this->msg( "centralauth-$unit-ago" )->numParams( $span )->text();
			}
			$span = intval( $span / $chunk );
		}

		end( $units );
		$lastUnit = key( $units );

		return $this->msg( "centralauth-$lastUnit-ago" )->numParams( $span )->text();
	}

	private function showInfo() {
		$attribs = $this->getInfoFields();

		// Give grep a chance to find the usages:
		// centralauth-admin-info-username, centralauth-admin-info-registered,
		// centralauth-admin-info-editcount, centralauth-admin-info-locked,
		// centralauth-admin-info-hidden, centralauth-admin-info-groups
		$content = Xml::openElement( "ul" );
		foreach ( $attribs as $tag => $data ) {
			$content .= Xml::openElement( "li" ) . Xml::openElement( "strong" );
			$msg = $this->msg( "centralauth-admin-info-$tag" );
			if ( $tag === 'groups' ) {
				// @TODO: This special case is ugly
				$msg->numParams( count( $this->mGlobalUser->getGlobalGroups() ) );
			}
			$content .= $msg->escaped();
			$content .= Xml::closeElement( "strong" ) . ' ' . $data . Xml::closeElement( "li" );
		}
		$content .= Xml::closeElement( "ul" );
		$out = Xml::fieldset(
			$this->msg( 'centralauth-admin-info-header' )->text(),
			$content,
			[ "id" => "mw-centralauth-info" ]
		);
		$this->getOutput()->addHTML( $out );
	}

	/**
	 * @return array
	 */
	private function getInfoFields() {
		$globalUser = $this->mGlobalUser;

		$reg = $globalUser->getRegistration();
		$age = $this->prettyTimespan( (int)wfTimestamp( TS_UNIX ) - (int)wfTimestamp( TS_UNIX, $reg ) );
		$attribs = [
			'username' => htmlspecialchars( $globalUser->getName() ),
			'registered' => htmlspecialchars(
				$this->getLanguage()->timeanddate( $reg, true ) . " ($age)" ),
			'editcount' => htmlspecialchars(
				$this->getLanguage()->formatNum( $this->evaluateTotalEditcount() ) ),
			'attached' => htmlspecialchars(
				$this->getLanguage()->formatNum( count( $this->mAttachedLocalAccounts ) ) ),
		];

		if ( count( $this->mUnattachedLocalAccounts ) ) {
			$attribs['unattached'] = htmlspecialchars(
				$this->getLanguage()->formatNum( count( $this->mUnattachedLocalAccounts ) ) );
		}

		if ( $globalUser->isLocked() ) {
			$attribs['locked'] = $this->msg( 'centralauth-admin-yes' )->escaped();
		}

		if ( $this->mCanOversight ) {
			$attribs['hidden'] = $this->formatHiddenLevel( $globalUser->getHiddenLevel() );
		}

		$groups = $globalUser->getGlobalGroups();
		if ( $groups ) {
			$groups = array_map( function ( $group ) {
				return $this->getLinkRenderer()->makeLink(
					SpecialPage::getTitleFor( 'GlobalGroupPermissions', $group ),
					UserGroupMembership::getGroupName( $group )
				);
			}, $groups );
			$attribs['groups'] = $this->getLanguage()->commaList( $groups );
		}

		if ( $this->mCanChangeGroups ) {
			if ( !isset( $attribs['groups'] ) ) {
				$attribs['groups'] = $this->msg( 'rightsnone' )->escaped();
			}

			$manageGroupsLink = $this->getLinkRenderer()->makeKnownLink(
				SpecialPage::getTitleFor( 'GlobalGroupMembership', $globalUser->getName() ),
				$this->msg( 'centralauth-admin-info-groups-manage' )->text()
			);

			$attribs['groups'] .= $this->msg( 'word-separator' )->text();
			$attribs['groups'] .= $this->msg( 'parentheses', $manageGroupsLink )->text();
		}

		return $attribs;
	}

	private function showWikiLists() {
		$merged = $this->mAttachedLocalAccounts;
		$remainder = $this->mUnattachedLocalAccounts;

		$legend = $this->mCanUnmerge && $this->mGlobalUser->exists() ?
			$this->msg( 'centralauth-admin-list-legend-rw' )->text() :
			$this->msg( 'centralauth-admin-list-legend-ro' )->text();

		$this->getOutput()->addHTML( Xml::fieldset( $legend ) );
		$this->getOutput()->addHTML( $this->listHeader() );
		$this->getOutput()->addHTML( $this->listAccounts( $merged ) );
		if ( $remainder ) {
			$this->getOutput()->addHTML( $this->listAccounts( $remainder ) );
		}
		$this->getOutput()->addHTML( $this->listFooter() );
		$this->getOutput()->addHTML( Xml::closeElement( 'fieldset' ) );
		$this->getOutput()->addModuleStyles( 'jquery.tablesorter.styles' );
		$this->getOutput()->addModules( 'jquery.tablesorter' );
	}

	/**
	 * @return string
	 */
	private function listHeader() {
		$columns = [
			"localwiki",   // centralauth-admin-list-localwiki
			"attached-on", // centralauth-admin-list-attached-on
			"method",      // centralauth-admin-list-method
			"blocked",     // centralauth-admin-list-blocked
			"editcount",   // centralauth-admin-list-editcount
			"groups",      // centralauth-admin-list-groups
		];
		$header = Xml::openElement( 'form', [
			'method' => 'post',
			'action' =>
			$this->getPageTitle( $this->mUserName )->getLocalUrl( 'action=submit' ),
			'id' => 'mw-centralauth-merged'
		] );
		$header .= Html::hidden( 'wpMethod', 'unmerge' ) .
			Html::hidden( 'wpEditToken', $this->getUser()->getEditToken() ) .
			Xml::openElement(
				'table', [ 'class' => 'wikitable sortable mw-centralauth-wikislist' ] ) .
			"\n" . Xml::openElement( 'thead' ) . Xml::openElement( 'tr' );
		if ( $this->mCanUnmerge && $this->mGlobalUser->exists() ) {
			$header .= Xml::openElement( 'th' ) . Xml::closeElement( 'th' );
		}
		foreach ( $columns as $c ) {
			$header .= Xml::openElement( 'th' ) .
				$this->msg( "centralauth-admin-list-$c" )->escaped() .
				Xml::closeElement( 'th' );
		}
		$header .= Xml::closeElement( 'tr' ) .
			Xml::closeElement( 'thead' ) .
			Xml::openElement( 'tbody' );

		return $header;
	}

	/**
	 * @return string
	 */
	private function listFooter() {
		$footer = Xml::closeElement( 'tbody' ) .
			Xml::closeElement( 'table' );

		if ( $this->mCanUnmerge && $this->mGlobalUser->exists() ) {
			$footer .= Xml::submitButton( $this->msg( 'centralauth-admin-unmerge' )->text() );
		}

		return $footer . Xml::closeElement( 'form' );
	}

	/**
	 * @param array $list
	 * @return string
	 */
	private function listAccounts( array $list ) {
		ksort( $list );
		return implode( "\n", array_map( [ $this, 'listWikiItem' ], $list ) );
	}

	/**
	 * @param array $row
	 * @return string
	 */
	private function listWikiItem( array $row ) {
		$html = Xml::openElement( 'tr' );

		if ( $this->mCanUnmerge && $this->mGlobalUser->exists() ) {
			if ( !empty( $row['attachedMethod'] ) ) {
				$html .= Xml::openElement( 'td' ) .
					$this->adminCheck( $row['wiki'] ) .
					Xml::closeElement( 'td' );
			} else {
				// Account is unattached, don't show checkbox to detach
				$html .= Xml::element( 'td' );
			}
		}

		$html .= Xml::openElement( 'td' ) .
			$this->foreignUserLink( $row['wiki'] ) .
			Xml::closeElement( 'td' );

		$attachedTimestamp = $row['attachedTimestamp'] ?? '';

		$html .= $this->getAttachedTimestampField( $attachedTimestamp ) .
			Xml::openElement( 'td', [ 'style' => "text-align: center;" ] );

		if ( empty( $row['attachedMethod'] ) ) {
			$html .= $this->msg( 'centralauth-admin-unattached' )->parse();
		} else {
			$html .= $this->formatMergeMethod( $row['attachedMethod'] );
		}

		$html .= Xml::closeElement( 'td' ) .
			Xml::openElement( 'td' ) .
			$this->formatBlockStatus( $row ) .
			Xml::closeElement( 'td' ) .
			Xml::openElement( 'td', [ 'style' => "text-align: right;" ] ) .
			$this->formatEditcount( $row ) .
			Xml::closeElement( 'td' ) .
			Xml::openElement( 'td' ) .
			$this->formatGroups( $row ) .
			Xml::closeElement( 'td' ) .
			Xml::closeElement( 'tr' );

		return $html;
	}

	/**
	 * @param string|null $attachedTimestamp
	 *
	 * @return string
	 */
	private function getAttachedTimestampField( $attachedTimestamp ) {
		if ( !$attachedTimestamp ) {
			$html = Xml::openElement( 'td', [ 'data-sort-value' => '0' ] ) .
				$this->msg( 'centralauth-admin-unattached' )->parse();
		} else {
			$html = Xml::openElement( 'td',
				[ 'data-sort-value' => $attachedTimestamp ] ) .
				// visible date and time in users preference
				htmlspecialchars( $this->getLanguage()->timeanddate( $attachedTimestamp, true ) );
		}

		$html .= Xml::closeElement( 'td' );
		return $html;
	}

	/**
	 * @param string $method
	 * @return string
	 */
	private function formatMergeMethod( $method ) {
		global $wgExtensionAssetsPath;

		// Give grep a chance to find the usages:
		// centralauth-merge-method-primary, centralauth-merge-method-empty,
		// centralauth-merge-method-mail, centralauth-merge-method-password,
		// centralauth-merge-method-admin, centralauth-merge-method-new,
		// centralauth-merge-method-login
		$brief = $this->msg( "centralauth-merge-method-{$method}" )->text();
		$html =
			Html::element(
				'img', [
					'src' => "{$wgExtensionAssetsPath}/CentralAuth/icons/merged-{$method}.png",
					'alt' => $brief,
					'title' => $brief,
				]
			)
			. Html::element(
				'span', [
					'class' => 'merge-method-help',
					'title' => $brief,
					'data-centralauth-mergemethod' => $method
				],
				$this->msg( 'centralauth-merge-method-questionmark' )->text()
			);

		return $html;
	}

	/**
	 * @param array $row
	 * @return string
	 */
	private function formatBlockStatus( $row ) {
		$additionalHtml = '';
		if ( isset( $row['blocked'] ) && $row['blocked'] ) {
			$optionMessage = $this->formatBlockParams( $row );
			if ( $row['block-expiry'] == 'infinity' ) {
				$text = $this->msg( 'centralauth-admin-blocked2-indef' )->text();
			} else {
				$expiry = $this->getLanguage()->timeanddate( $row['block-expiry'], true );
				$expiryd = $this->getLanguage()->date( $row['block-expiry'], true );
				$expiryt = $this->getLanguage()->time( $row['block-expiry'], true );

				$text = $this->msg( 'centralauth-admin-blocked2', $expiry, $expiryd, $expiryt )
					->text();
			}

			if ( $row['block-reason'] ) {
				$reason = Sanitizer::escapeHtmlAllowEntities( $row['block-reason'] );
				$reason = Linker::formatLinksInComment(
					$reason,
					null,
					false,
					$row['wiki']
				);

				$msg = $this->msg( 'centralauth-admin-blocked-reason' );
				$msg->rawParams( '<span class="plainlinks">' . $reason . '</span>' );

				$additionalHtml .= Html::rawElement( 'br' ) . $msg->parse();
			}

			$additionalHtml .= ' ' . $optionMessage;

		} else {
			$text = $this->msg( 'centralauth-admin-notblocked' )->text();
		}

		return self::foreignLink(
			$row['wiki'],
			'Special:Log/block',
			$text,
			$this->msg( 'centralauth-admin-blocklog' )->text(),
			'page=User:' . urlencode( $this->mUserName )
		) . $additionalHtml;
	}

	/**
	 * Format a block's parameters.
	 *
	 * @see BlockListPager::formatValue()
	 *
	 * @param array $row
	 * @return string
	 */
	private function formatBlockParams( $row ) {
		global $wgConf;

		// Ensure all the data is loaded before trying to use.
		$wgConf->loadFullData();

		$properties = [];

		if ( $row['block-sitewide'] ) {
			$properties[] = $this->msg( 'blocklist-editing-sitewide' )->escaped();
		}

		if ( !$row['block-sitewide'] && $row['block-restrictions'] ) {
			$list = $this->getRestrictionListHTML( $row );
			if ( $list ) {
				$properties[] = $this->msg( 'blocklist-editing' )->escaped() . $list;
			}
		}

		$options = [
			'anononly' => 'anononlyblock',
			'nocreate' => 'createaccountblock',
			'noautoblock' => 'noautoblockblock',
			'noemail' => 'emailblock',
			'nousertalk' => 'blocklist-nousertalk',
		];
		foreach ( $options as $option => $msg ) {
			if ( $row['block-' . $option] ) {
				$properties[] = $this->msg( $msg )->escaped();
			}
		}

		if ( !$properties ) {
			return '';
		}

		return Html::rawElement(
			'ul',
			[],
			implode( '', array_map( static function ( $prop ) {
				return Html::rawElement(
					'li',
					[],
					$prop
				);
			}, $properties ) )
		);
	}

	/**
	 * @see BlockListPager::getRestrictionListHTML()
	 *
	 * @param array $row
	 *
	 * @return string
	 */
	private function getRestrictionListHTML( array $row ) {
		$count = array_reduce( $row['block-restrictions'], static function ( $carry, $restriction ) {
			$carry[$restriction->getType()] += 1;
			return $carry;
		}, [
			PageRestriction::TYPE => 0,
			NamespaceRestriction::TYPE => 0,
		] );

		$restrictions = [];
		foreach ( $count as $type => $value ) {
			if ( $value === 0 ) {
				continue;
			}

			$restrictions[] = Html::rawElement(
				'li',
				[],
				self::foreignLink(
					$row['wiki'],
					'Special:BlockList/' . $row['name'],
					$this->msg( 'centralauth-block-editing-' . $type, $value )->text()
				)
			);
		}

		if ( count( $restrictions ) === 0 ) {
			return '';
		}

		return Html::rawElement(
			'ul',
			[],
			implode( '', $restrictions )
		);
	}

	/**
	 * @param array $row
	 * @return string
	 * @throws Exception
	 */
	private function formatEditcount( $row ) {
		$wiki = WikiMap::getWiki( $row['wiki'] );
		if ( !$wiki ) {
			throw new Exception( "Invalid wiki: {$row['wiki']}" );
		}
		$wikiname = $wiki->getDisplayName();
		$editCount = $this->getLanguage()->formatNum( intval( $row['editCount'] ) );

		return self::foreignLink(
			$row['wiki'],
			'Special:Contributions/' . $this->mUserName,
			$editCount,
			$this->msg( 'centralauth-foreign-contributions' )
				->params( $editCount, $wikiname )->text()
		);
	}

	/**
	 * @param array $row
	 * @return string
	 */
	private function formatGroups( $row ) {
		if ( !count( $row['groupMemberships'] ) ) {
			return '';
		}

		// We place temporary groups before non-expiring groups in the list.
		// This is to avoid the ambiguity of something like
		// "sysop, bureaucrat (temporary)" -- users might wonder whether the
		// "temporary" indication applies to both groups, or just the last one
		$listTemporary = [];
		$list = [];
		/** @var UserGroupMembership $ugm */
		foreach ( $row['groupMemberships'] as $group => $ugm ) {
			if ( $ugm->getExpiry() ) {
				$listTemporary[] = $this->msg( 'centralauth-admin-group-temporary',
					wfEscapeWikitext( $group ) )->parse();
			} else {
				$list[] = htmlspecialchars( $group );
			}
		}
		return $this->getLanguage()->commaList( array_merge( $listTemporary, $list ) );
	}

	/**
	 * @param string $level
	 * @return string Already html escaped
	 */
	public function formatHiddenLevel( $level ) {
		// @FIXME: This shouldn't be used in SpecialMultiLock
		switch ( $level ) {
			case CentralAuthUser::HIDDEN_NONE:
				return $this->msg( 'centralauth-admin-no' )->escaped();
			case CentralAuthUser::HIDDEN_LISTS:
				return $this->msg( 'centralauth-admin-hidden-list' )->escaped();
			case CentralAuthUser::HIDDEN_OVERSIGHT:
				return $this->msg( 'centralauth-admin-hidden-oversight' )->escaped();
		}
		return '';
	}

	/**
	 * @param string|WikiReference $wikiID
	 * @param string $title
	 * @param string $text not HTML escaped
	 * @param string $hint
	 * @param string $params
	 * @return string
	 * @throws Exception
	 */
	public static function foreignLink( $wikiID, $title, $text, $hint = '', $params = '' ) {
		if ( $wikiID instanceof WikiReference ) {
			$wiki = $wikiID;
		} else {
			$wiki = WikiMap::getWiki( $wikiID );
			if ( !$wiki ) {
				throw new Exception( "Invalid wiki: $wikiID" );
			}
		}

		$url = $wiki->getFullUrl( $title );
		if ( $params ) {
			$url .= '?' . $params;
		}
		return Xml::element( 'a',
			[
				'href' => $url,
				'title' => $hint,
			],
			$text );
	}

	/**
	 * @param string $wikiID
	 * @return string
	 * @throws Exception
	 */
	private function foreignUserLink( $wikiID ) {
		$wiki = WikiMap::getWiki( $wikiID );
		if ( !$wiki ) {
			throw new Exception( "Invalid wiki: $wikiID" );
		}

		$wikiname = $wiki->getDisplayName();
		return self::foreignLink(
			$wiki,
			MWNamespace::getCanonicalName( NS_USER ) . ':' . $this->mUserName,
			$wikiname,
			$this->msg( 'centralauth-foreign-link', $this->mUserName, $wikiname )->text()
		);
	}

	/**
	 * @param string $wikiID
	 * @return string
	 */
	private function adminCheck( $wikiID ) {
		return Xml::check( 'wpWikis[]', false, [ 'value' => $wikiID ] );
	}

	/**
	 * @param string $action Only 'delete' supported
	 */
	private function showActionForm( $action ) {
		$this->getOutput()->addHTML(
			# to be able to find messages: centralauth-admin-delete-title,
			# centralauth-admin-delete-description, centralauth-admin-delete-button
			Xml::fieldset( $this->msg( "centralauth-admin-{$action}-title" )->text() ) .
			Xml::openElement( 'form', [
				'method' => 'POST',
				'action' => $this->getPageTitle()->getFullUrl(
					'target=' . urlencode( $this->mUserName )
				),
				'id' => "mw-centralauth-$action" ] ) .
			Html::hidden( 'wpMethod', $action ) .
			Html::hidden( 'wpEditToken', $this->getUser()->getEditToken() ) .
				$this->msg( "centralauth-admin-{$action}-description" )->parseAsBlock() .
			// @phan-suppress-next-line SecurityCheck-DoubleEscaped taint-check tracks keys and values together
			Xml::buildForm(
				[ 'centralauth-admin-reason' => Xml::input( 'reason',
					false, false, [ 'id' => "{$action}-reason" ] ) ],
				"centralauth-admin-{$action}-button"
			) . Xml::closeElement( 'form' ) . Xml::closeElement( 'fieldset' ) );
	}

	private function showStatusForm() {
		// Allows locking, hiding, locking and hiding.
		$form = '';
		$form .= Xml::fieldset( $this->msg( 'centralauth-admin-status' )->text() );
		$form .= Html::hidden( 'wpMethod', 'set-status' );
		$form .= Html::hidden( 'wpEditToken', $this->getUser()->getEditToken() );
		$form .= Html::hidden( 'wpUserState', $this->mGlobalUser->getStateHash( false ) );
		$form .= $this->msg( 'centralauth-admin-status-intro' )->parseAsBlock();

		// Radio buttons
		$radioLocked =
			Xml::radioLabel(
				$this->msg( 'centralauth-admin-status-locked-no' )->text(),
				'wpStatusLocked',
				'0',
				'mw-centralauth-status-locked-no',
				!$this->mGlobalUser->isLocked() ) .
			'<br />' .
			Xml::radioLabel(
				$this->msg( 'centralauth-admin-status-locked-yes' )->text(),
				'wpStatusLocked',
				'1',
				'mw-centralauth-status-locked-yes',
				$this->mGlobalUser->isLocked() );

		$radioHidden =
			Xml::radioLabel(
				$this->msg( 'centralauth-admin-status-hidden-no' )->text(),
				'wpStatusHidden',
				CentralAuthUser::HIDDEN_NONE,
				'mw-centralauth-status-hidden-no',
				$this->mGlobalUser->getHiddenLevel() == CentralAuthUser::HIDDEN_NONE );
		if ( $this->mCanOversight ) {
			$radioHidden .= '<br />' .
				Xml::radioLabel(
					$this->msg( 'centralauth-admin-status-hidden-list' )->text(),
					'wpStatusHidden',
					CentralAuthUser::HIDDEN_LISTS,
					'mw-centralauth-status-hidden-list',
					$this->mGlobalUser->getHiddenLevel() == CentralAuthUser::HIDDEN_LISTS
				) .
				'<br />' .
				Xml::radioLabel(
					$this->msg( 'centralauth-admin-status-hidden-oversight' )->text(),
					'wpStatusHidden',
					CentralAuthUser::HIDDEN_OVERSIGHT,
					'mw-centralauth-status-hidden-oversight',
					$this->mGlobalUser->getHiddenLevel() == CentralAuthUser::HIDDEN_OVERSIGHT
				);
		}

		$reasonList = Xml::listDropDown(
			'wpReasonList',
			$this->msg( 'centralauth-admin-status-reasons' )->inContentLanguage()->text(),
			$this->msg( 'centralauth-admin-reason-other-select' )->inContentLanguage()->text()
		);
		$reasonField = Xml::input( 'wpReason', 45, false );

		// @phan-suppress-next-line SecurityCheck-DoubleEscaped taint-check tracks keys and values together
		$form .= Xml::buildForm(
			[
				'centralauth-admin-status-locked' => $radioLocked,
				'centralauth-admin-status-hidden' => $radioHidden,
				'centralauth-admin-reason' => $reasonList,
				'centralauth-admin-reason-other' => $reasonField ],
				'centralauth-admin-status-submit'
		);

		$form .= Xml::closeElement( 'fieldset' );
		$form = Xml::tags(
			'form',
			[
				'method' => 'POST',
				'action' => $this->getPageTitle()->getFullURL(
					[ 'target' => $this->mUserName ]
				),
			],
			$form
		);
		$this->getOutput()->addHTML( $form );
	}

	private function showLogExtract() {
		$user = $this->mGlobalUser->getName();
		$title = Title::newFromText( MWNamespace::getCanonicalName( NS_USER ) . ":{$user}@global" );
		if ( !$title ) {
			// Don't fatal even if a Title couldn't be generated
			// because we've invalid usernames too :/
			return;
		}
		$logTypes = [ 'globalauth' ];
		if ( $this->mCanOversight ) {
			$logTypes[] = 'suppress';
		}
		$text = '';
		$numRows = LogEventsList::showLogExtract(
			$text,
			$logTypes,
			$title->getPrefixedText(),
			'',
			[ 'showIfEmpty' => true ] );
		if ( $numRows ) {
			$this->getOutput()->addHTML( Xml::fieldset(
				$this->msg( 'centralauth-admin-logsnippet' )->text(),
				$text
			) );
		}
	}

	/**
	 * @return int
	 */
	private function evaluateTotalEditcount() {
		$total = 0;
		foreach ( $this->mAttachedLocalAccounts as $acc ) {
			$total += $acc['editCount'];
		}
		return $total;
	}

	/**
	 * @return array
	 */
	private function getMergeMethodDescriptions() {
		// Give grep a chance to find the usages:
		// centralauth-merge-method-primary, centralauth-merge-method-new,
		// centralauth-merge-method-empty, centralauth-merge-method-password,
		// centralauth-merge-method-mail, centralauth-merge-method-admin,
		// centralauth-merge-method-login
		// Give grep a chance to find the usages:
		// centralauth-merge-method-primary-desc, centralauth-merge-method-new-desc,
		// centralauth-merge-method-empty-desc, centralauth-merge-method-password-desc,
		// centralauth-merge-method-mail-desc, centralauth-merge-method-admin-desc,
		// centralauth-merge-method-login-desc
		$mergeMethodDescriptions = [];
		foreach ( [ 'primary', 'new', 'empty', 'password', 'mail', 'admin', 'login' ] as $method ) {
			$mergeMethodDescriptions[$method] = [
				'short' => $this->getLanguage()->ucfirst(
					$this->msg( "centralauth-merge-method-{$method}" )->escaped()
				),
				'desc' => $this->msg( "centralauth-merge-method-{$method}-desc" )->escaped()
			];
		}
		return $mergeMethodDescriptions;
	}

	protected function getGroupName() {
		return 'users';
	}
}
