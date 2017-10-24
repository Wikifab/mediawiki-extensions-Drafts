<?php
/**
 * Hooks for Drafts extension
 *
 * @file
 * @ingroup Extensions
 */

class DraftHooks {

	private static $buttonAdded = false;
	/**
	 * @param $defaultOptions array
	 * @return bool
	 */
	public static function onUserGetDefaultOptions( &$defaultOptions ) {
		$defaultOptions['extensionDrafts_enable'] = true;
		return true;
	}

	/**
	 * @param $user User
	 * @param $preferences array
	 * @return bool
	 */
	public static function onGetPreferences( User $user, array &$preferences ) {
		$preferences['extensionDrafts_enable'] = array(
			'type' => 'toggle',
			'label-message' => 'drafts-enable',
			'section' => 'editing/extension-drafts'
		);
		return true;
	}

	/**
	 * @param $updater DatabaseUpdater
	 * @return bool
	 */
	public static function schema( $updater = null ) {
		$updater->addExtensionUpdate( array( 'addTable', 'drafts',
			__DIR__ . '/Drafts.sql', true ) );
		if ( $updater->getDb()->getType() != 'sqlite' ) {
			$updater->addExtensionUpdate( array( 'modifyField', 'drafts', 'draft_token',
				__DIR__ . '/patch-draft_token.sql', true ) );
		}
		return true;
	}

	/**
	 * SpecialMovepageAfterMove hook
	 */
	public static function onSpecialMovepageAfterMove( $this, $ot, $nt ) {
		// Update all drafts of old article to new article for all users
		Drafts::move( $ot, $nt );
		// Continue
		return true;
	}

	/**
	 * PageContentSaveComplete hook
	 *
	 */
	public static function onPageContentSaveComplete( WikiPage $article, $user, $content, $summary, $isMinor,
		$isWatch, $section, $flags, $revision, $status, $baseRevId
	) {
		global $wgRequest;

		// Check if the save occurred from a draft
		$draft = Draft::newFromID( $wgRequest->getIntOrNull( 'wpDraftID' ) );
		if ( $draft->exists() ) {
			// Discard the draft
			$draft->discard( $user );
		}
		$title = $article->getTitle();
		// or other param : when saving a page, we delete every draft of this page, for the current user
		$drafts = Drafts::get( $title, $user->getID());
		foreach ($drafts as $draft) {
			echo 'DELETE DRAFT ' . $draft->getId() . '<br/>';
			$draft->discard( $user );
		}
		// Continue
		return true;
	}

	/**
	 * EditPage::showEditForm:initial hook
	 * Load draft...
	 */
	public static function loadForm( EditPage $editpage ) {
		$context = $editpage->getArticle()->getContext();
		$user = $context->getUser();

		if ( !$user->getOption( 'extensionDrafts_enable', 'true' ) ) {
			return true;
		}

		// Check permissions
		$request = $context->getRequest();
		if ( $user->isAllowed( 'edit' ) && $user->isLoggedIn() ) {
			// Get draft
			$draft = Draft::newFromID( $request->getIntOrNull( 'draft' ) );
			// Load form values
			if ( $draft->exists() ) {
				// Override initial values in the form with draft data
				$editpage->textbox1 = $draft->getText();
				$editpage->summary = $draft->getSummary();
				$editpage->scrolltop = $draft->getScrollTop();
				$editpage->minoredit = $draft->getMinorEdit() ? true : false;
			}

			// Save draft on non-save submission
			if ( $request->getVal( 'action' ) == 'submit' &&
				$user->matchEditToken( $request->getText( 'wpEditToken' ) ) &&
				is_null( $request->getText( 'wpDraftTitle' ) ) )
			{
				// If the draft wasn't specified in the url, try using a
				// form-submitted one
				if ( !$draft->exists() ) {
					$draft = Draft::newFromID(
						$request->getIntOrNull( 'wpDraftID' )
					);
				}
				// Load draft with info
				$draft->setTitle( Title::newFromText(
						$request->getText( 'wpDraftTitle' ) )
				);
				$draft->setSection( $request->getInt( 'wpSection' ) );
				$draft->setStartTime( $request->getText( 'wpStarttime' ) );
				$draft->setEditTime( $request->getText( 'wpEdittime' ) );
				$draft->setSaveTime( wfTimestampNow() );
				$draft->setScrollTop( $request->getInt( 'wpScrolltop' ) );
				$draft->setText( $request->getText( 'wpTextbox1' ) );
				$draft->setSummary( $request->getText( 'wpSummary' ) );
				$draft->setMinorEdit( $request->getInt( 'wpMinoredit', 0 ) );
				// Save draft
				$draft->save();
				// Use the new draft id
				$request->setVal( 'draft', $draft->getID() );
			}
		}

		$out = $context->getOutput();

		$numDrafts = Drafts::num( $context->getTitle() );
		// Show list of drafts
		if ( $numDrafts  > 0 ) {
			if ( $request->getText( 'action' ) !== 'submit' ) {
				$out->addHTML( Xml::openElement(
					'div', array( 'id' => 'drafts-list-box' ) )
				);
				$out->addHTML( Xml::element(
					'h3', null, $context->msg( 'drafts-view-existing' )->text() )
				);
				$out->addHTML( Xml::element(
					'p', null, $context->msg( 'drafts-view-existing-message' )->text() )
				);
				$out->addHTML( Drafts::display( $context->getTitle() ) );
				$out->addHTML( Xml::closeElement( 'div' ) );
			} else {
				$jsWarn = "if( !wgAjaxSaveDraft.insync ) return confirm(" .
					Xml::encodeJsVar( $context->msg( 'drafts-view-warn' )->text() ) .
					")";
				$link = Xml::element( 'a',
					array(
						'href' => $context->getTitle()->getFullURL( 'action=edit' ),
						'onclick' => $jsWarn
					),
					$context->msg( 'drafts-view-notice-link' )->numParams( $numDrafts )->text()
				);
				$out->addHTML( $context->msg( 'drafts-view-notice' )->rawParams( $link )->escaped() );
			}
		}
		// Continue
		return true;
	}

	/**
	 * EditFilter hook
	 * Intercept the saving of an article to detect if the submission was from
	 * the non-javascript save draft button
	 */
	public static function onEditFilter( EditPage $editor, $text, $section, $error ) {
		// Don't save if the save draft button caused the submit
		if ( $editor->getArticle()->getContext()->getRequest()->getText( 'wpDraftSave' ) !== '' ) {
			// Modify the error so it's clear we want to remain in edit mode
			$error = ' ';
		}
		// Continue
		return true;
	}


	//'PageForms::EditFormPreloadText', array( &$preloadContent, $targetTitle, $formTitle ) );

	public static function pfEditFormInitContent(& $preloadContent, $targetTitle, $formTitle) {

		// if a draft id is passed in request, change loaded content with draft content
		global $wgRequest, $wgUser;

		if ( $wgUser->isAllowed( 'edit' ) && $wgUser->isLoggedIn() ) {
			// Get draft
			$draft = Draft::newFromID( $wgRequest->getIntOrNull( 'draft' ) );
			// Load form values
			if ( $draft->exists() ) {
				$preloadContent = $draft->getText();
			}
		}

	}

	/**
	 * for use with PageForm extension
	 * FormEdit::showEditForm:initial hook
	 * Load draft...
	 */
	public static function pfLoadForm( PFFormEdit $editpage ) {
		$context = $editpage->getContext();
		$user = $context->getUser();
		global $wgRequest;
		$warningDeprecatedDraft = false;


		$wgRequest->setVal('Tuto Details[Description]', 'Big description');


		global $pfpreloadContent ;

		if ( !$user->getOption( 'extensionDrafts_enable', 'true' ) ) {
			return true;
		}

		// Check permissions
		$request = $context->getRequest();
		if ( $user->isAllowed( 'edit' ) && $user->isLoggedIn() ) {
			// Get draft
			$draft = Draft::newFromID( $request->getIntOrNull( 'draft' ) );
			// Load form values
			if ( $draft->exists() ) {
				$pfpreloadContent = $draft->getText();
				$editpage->mForm = $draft->getText();
				$pfpreloadContent = $editpage->mForm;
				// Override initial values in the form with draft data
				$editpage->textbox1 = $draft->getText();
				$editpage->summary = $draft->getSummary();
				$editpage->scrolltop = $draft->getScrollTop();
				$editpage->minoredit = $draft->getMinorEdit() ? true : false;

				// check if actual Draft is out of date :
				// get page last Edit time
				$title = $draft->getTitle();
				if($title) {
					$lastEdit = Revision::getTimestampFromId($title, $title->getLatestRevID());
					$draftStartTimestamp = $draft->getStartTime();
					if($lastEdit > $draftStartTimestamp) {
						$warningDeprecatedDraft = true;
					}
				}

			}

			// Save draft on non-save submission
			if ( $request->getVal( 'action' ) == 'submit' &&
					$user->matchEditToken( $request->getText( 'wpEditToken' ) ) &&
					is_null( $request->getText( 'wpDraftTitle' ) ) )
			{
				// If the draft wasn't specified in the url, try using a
				// form-submitted one
				if ( !$draft->exists() ) {
					$draft = Draft::newFromID(
							$request->getIntOrNull( 'wpDraftID' )
							);
				}
				// Load draft with info
				$draft->setTitle( Title::newFromText(
						$request->getText( 'wpDraftTitle' ) )
						);
				$draft->setSection( $request->getInt( 'wpSection' ) );
				$draft->setStartTime( $request->getText( 'wpStarttime' ) );
				$draft->setEditTime( $request->getText( 'wpEdittime' ) );
				$draft->setSaveTime( wfTimestampNow() );
				$draft->setScrollTop( $request->getInt( 'wpScrolltop' ) );
				$draft->setText( $request->getText( 'wpTextbox1' ) );
				$draft->setSummary( $request->getText( 'wpSummary' ) );
				$draft->setMinorEdit( $request->getInt( 'wpMinoredit', 0 ) );
				// Save draft
				$draft->save();
				// Use the new draft id
				$request->setVal( 'draft', $draft->getID() );
			}
		}

		$out = $context->getOutput();



		$numDrafts = Drafts::num( $context->getTitle() );
		// Show list of drafts
		if ( $numDrafts  > 0 ) {
			if ( $request->getText( 'action' ) !== 'submit' ) {
				$out->addHTML( Xml::openElement(
						'div', array( 'id' => 'drafts-list-box' ) )
						);
				$out->addHTML( Xml::element(
						'h3', null, $context->msg( 'drafts-view-existing' )->text() )
						);
				$out->addHTML( Xml::element(
						'p', null, $context->msg( 'drafts-view-existing-message' )->text() )
						);
				$out->addHTML( Drafts::display( $context->getTitle() ) );

				if ($warningDeprecatedDraft) {
					$out->addHTML( Xml::element(
						'p', null, $context->msg( 'drafts-warning-deprecated-draft' )->text() )
					);
				}
				$out->addHTML( Xml::closeElement( 'div' ) );
			} else {
				$jsWarn = "if( !wgAjaxSaveDraft.insync ) return confirm(" .
						Xml::encodeJsVar( $context->msg( 'drafts-view-warn' )->text() ) .
						")";
						$link = Xml::element( 'a',
								array(
										'href' => $context->getTitle()->getFullURL( 'action=edit' ),
										'onclick' => $jsWarn
								),
								$context->msg( 'drafts-view-notice-link' )->numParams( $numDrafts )->text()
								);
						$out->addHTML( $context->msg( 'drafts-view-notice' )->rawParams( $link )->escaped() );
			}
		}
		// Continue
		return true;
	}

	/**
	 * for use with PageForm extension : initialize on formEdit page
	 * @param PF_FormPrinter $formPrinter
	 */
	public static function onFormPrinterSetup ( $formPrinter ) {
		// register new input button for pageForms templates
		//$formPrinter->setInputTypeHook('saveDraft', 'DraftHooks::pageFormInputSaveDraft', array());
	}


	/**
	 * for use with PageForm extension (wikifab forked version)
	 *
	 * @param string $html
	 * @param string $inputName
	 * @return boolean
	 */
	public static function onDisplayStandardInputButton(& $html, $inputName) {
		global $wgUser, $wgOut, $wgRequest;


		if ($inputName != 'saveDraft') {
			return true;
		}
		if ( ! $wgUser || !$wgUser->getOption( 'extensionDrafts_enable', 'true' ) ) {
			return true;
		}
		$wgOut->addModules( 'ext.Drafts' );
		//$title = Title::newFromText( $wgRequest->getText('title') );

		// Build XML
		$html = Xml::openElement( 'script',
				array(
						'type' => 'text/javascript',
						'language' => 'javascript'
				)
				);
		$buttonAttribs = array(
				'id' => 'wpDraftSave',
				'name' => 'wpDraftSave',
				'class' => 'mw-ui-button',
				'value' =>  wfMessage( 'drafts-save-save' )->text(),
		);
		$attribs = Linker::tooltipAndAccesskeyAttribs( 'drafts-save' );
		if ( isset( $attribs['accesskey'] ) ) {
			$buttonAttribs['accesskey'] = $attribs['accesskey'];
		}
		if ( isset( $attribs['tooltip'] ) ) {
			$buttonAttribs['title'] = $attribs['title'];
		}
		$html .= Xml::encodeJsCall(
				'document.write',
				array( Xml::element( 'input',
						array( 'type' => 'submit' ) + $buttonAttribs
						+ ( $wgRequest->getText( 'action' ) !== 'submit' ?
								array ( 'disabled' => 'disabled' )
								: array()
								)
						) )
				);
		$html .= Xml::closeElement( 'script' );
		$html .= Xml::openElement( 'noscript' );
		$html .= Xml::element( 'input',
				array( 'type' => 'submit' ) + $buttonAttribs
				);
		$html .= Xml::closeElement( 'noscript' );
		$html .= Xml::element( 'input',
				array(
						'type' => 'hidden',
						'name' => 'wpDraftToken',
						'value' => MWCryptRand::generateHex( 32 )
				)
				);
		$html .= Xml::element( 'input',
				array(
						'type' => 'hidden',
						'name' => 'wpDraftID',
						'value' => $wgRequest->getInt( 'draft', '' )
				)
				);
		$html .= Xml::element( 'input',
				array(
						'type' => 'hidden',
						'name' => 'wpDraftTitle',
						'value' => $wgRequest->getText('title') //$title->getPrefixedText()
				)
				);

		self::$buttonAdded = true;

		return true;
	}

	/**
	 * @param OutputPage $out
	 * @param Skin $skin
	 * @return bool
	 * Hook: BeforePageDisplay
	 */
	public static function addModules( $out, $skin ) {

		// add modal div to display error message when saving draft fail
		if(self::$buttonAdded) {
			$out->addHTML(self::getErrorModalHtml());
		}
	}

	private static function getErrorModalHtml() {
		$connexionUrl = SpecialPage::getTitleFor('connexion')->getFullURL();
		$connextionLink = '<a href="' . $connexionUrl . '" target="_blank">' . wfMessage('login') . '</a>';
		return '
			<div id="draft-error-modal" class="modal fade" role="dialog">
			  <div class="modal-dialog">

				<!-- Modal content-->
				<div class="modal-content">
				  <div class="modal-header">
					<button type="button" class="close" data-dismiss="modal">&times;</button>
					<h4 class="modal-title">'.wfMessage('drafts-save-error')->plain() .'</h4>
				  </div>
				  <div class="modal-body">
						'. wfMessage('draft-saving-error-message', $connextionLink)->plain() . '
				  </div>
				  <div class="modal-footer">
					<button type="button" class="btn btn-default" data-dismiss="modal">'.wfMessage('cancel')->plain().'</button>
				  </div>
				</div>

			  </div>
			</div>';
	}

	/**
	 * EditPageBeforeEditButtons hook
	 * Add draft saving controls
	 */
	public static function onEditPageBeforeEditButtons( EditPage $editpage, &$buttons, &$tabindex ) {
		global $egDraftsAutoSaveWait, $egDraftsAutoSaveTimeout, $egDraftsAutoSaveInputBased;

		$context = $editpage->getArticle()->getContext();
		$user = $context->getUser();

		if ( !$user->getOption( 'extensionDrafts_enable', 'true' ) ) {
			return true;
		}
		// Check permissions
		if ( $user->isAllowed( 'edit' ) && $user->isLoggedIn() ) {
			$request = $context->getRequest();
			$context->getOutput()->addModules( 'ext.Drafts' );

			// Build XML
			$buttons['savedraft'] = Xml::openElement( 'script',
				array(
					'type' => 'text/javascript',
					'language' => 'javascript'
				)
			);
			$buttonAttribs = array(
				'id' => 'wpDraftSave',
				'name' => 'wpDraftSave',
				'class' => 'mw-ui-button',
				'tabindex' => ++$tabindex,
				'value' => $context->msg( 'drafts-save-save' )->text(),
			);
			$attribs = Linker::tooltipAndAccesskeyAttribs( 'drafts-save' );
			if ( isset( $attribs['accesskey'] ) ) {
				$buttonAttribs['accesskey'] = $attribs['accesskey'];
			}
			if ( isset( $attribs['tooltip'] ) ) {
				$buttonAttribs['title'] = $attribs['title'];
			}
			$buttons['savedraft'] .= Xml::encodeJsCall(
				'document.write',
				array( Xml::element( 'input',
					array( 'type' => 'submit' ) + $buttonAttribs
					+ ( $request->getText( 'action' ) !== 'submit' ?
						array ( 'disabled' => 'disabled' )
						: array()
					)
				) )
			);
			$buttons['savedraft'] .= Xml::closeElement( 'script' );
			$buttons['savedraft'] .= Xml::openElement( 'noscript' );
			$buttons['savedraft'] .= Xml::element( 'input',
				array( 'type' => 'submit' ) + $buttonAttribs
			);
			$buttons['savedraft'] .= Xml::closeElement( 'noscript' );
			$buttons['savedraft'] .= Xml::element( 'input',
				array(
					'type' => 'hidden',
					'name' => 'wpDraftToken',
					'value' => MWCryptRand::generateHex( 32 )
				)
			);
			$buttons['savedraft'] .= Xml::element( 'input',
				array(
					'type' => 'hidden',
					'name' => 'wpDraftID',
					'value' => $request->getInt( 'draft', '' )
				)
			);
			$buttons['savedraft'] .= Xml::element( 'input',
				array(
					'type' => 'hidden',
					'name' => 'wpDraftTitle',
					'value' => $context->getTitle()->getPrefixedText()
				)
			);
		}
		// Continue
		return true;
	}

	/**
	 * Hook for ResourceLoaderGetConfigVars
	 *
	 * @param array $vars
	 * @return bool
	 */
	public static function onResourceLoaderGetConfigVars( &$vars ) {
		global $egDraftsAutoSaveWait, $egDraftsAutoSaveTimeout,
			   $egDraftsAutoSaveInputBased;
		$vars['wgDraftAutoSaveWait'] = $egDraftsAutoSaveWait;
		$vars['wgDraftAutoSaveTimeout'] = $egDraftsAutoSaveTimeout;
		$vars['wgDraftAutoSaveInputBased'] = $egDraftsAutoSaveInputBased;
		return true;
	}

}
