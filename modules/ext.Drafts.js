/* JavaScript for Drafts extension */

/*jshint -W066 */

var wgDraft;

function Draft() {

	/* Private Members */

	// Reference to object's self
	var self = this,
		// Configuration settings
		configuration = null,
		// State of the draft as it pertains to asynchronous saving
		state = 'unchanged',
		// Timer handle for auto-saving
		timer = null,
		// Reference to edit form draft is being edited with
		form = null,
		editIsPageForm = false;
	
	var debugMode = true;

	/* Functions */
	
	this.setStateClass = function() {
		$(form.wpDraftSave).removeClass('drafts-unchanged');
		$(form.wpDraftSave).removeClass('drafts-changed');
		$(form.wpDraftSave).removeClass('drafts-saved');
		$(form.wpDraftSave).removeClass('drafts-saving');
		$(form.wpDraftSave).removeClass('drafts-error');
		switch ( state ) {
			case 'unchanged':
				$(form.wpDraftSave).addClass('drafts-unchanged');
				break;
			case 'changed':
				$(form.wpDraftSave).addClass('drafts-changed');
				break;
			case 'saved':
				$(form.wpDraftSave).addClass('drafts-saved');
				break;
			case 'saving':
				$(form.wpDraftSave).addClass('drafts-saving');
				break;
			case 'error':
				$(form.wpDraftSave).addClass('drafts-error');
				break;
			default: break;
		}
	}

	/**
	 * Sets the state of the draft
	 * @param {String} newState
	 */
	this.setState = function(newState) {
		if (state !== newState) {
			// Stores state information
			state = newState;
			// Updates UI elements
			self.setStateClass();
			switch ( state ) {
				case 'unchanged':
					form.wpDraftSave.disabled = true;
					form.wpDraftSave.value = mediaWiki.message( 'drafts-save-save' ).text();
					break;
				case 'changed':
					form.wpDraftSave.disabled = false;
					form.wpDraftSave.value = mediaWiki.message( 'drafts-save-save' ).text();
					break;
				case 'saved':
					form.wpDraftSave.disabled = true;
					form.wpDraftSave.value = mediaWiki.message( 'drafts-save-saved' ).text();
					break;
				case 'saving':
					form.wpDraftSave.disabled = true;
					form.wpDraftSave.value = mediaWiki.message( 'drafts-save-saving' ).text();
					break;
				case 'error':
					form.wpDraftSave.disabled = true;
					form.wpDraftSave.value = mediaWiki.message( 'drafts-save-error' ).text();
					break;
				default: break;
			}
		}
	};

	/**
	 * Gets the state of the draft
	 */
	this.getState = function() {
		return state;
	};

	/**
	 * Sends draft data to server to be saved
	 */
	this.save = function( event ) {
		if (typeof event != 'undefined') {
			event.preventDefault();
		}
		// Checks if a save is already taking place
		if (state === 'saving') {
			// Exits function immediately
			return;
		}
		// Sets state to saving
		self.setState( 'saving' );
		var params;

		if( ! editIsPageForm) {
			params = {
				action: 'savedrafts',
				drafttoken: form.wpDraftToken.value,
				token: form.wpEditToken.value,
				id: form.wpDraftID.value,
				title: form.wpDraftTitle.value,
				section: form.wpSection.value,
				starttime: form.wpStarttime.value,
				edittime: form.wpEdittime.value,
				scrolltop: form.wpTextbox1.scrollTop,
				text: form.wpTextbox1.value,
				summary: form.wpSummary.value
			};
		} else {
			var formdata = JSON.stringify( $(form).serializeArray() );

			params = {
				action: 'savedrafts',
				drafttoken: form.wpDraftToken.value,
				token: form.wpEditToken.value,
				id: form.wpDraftID.value,
				title: form.wpDraftTitle.value,
				section: '',//form.wpSection.value,
				starttime: '',//form.wpStarttime.value,
				edittime: '',//form.wpEdittime.value,
				scrolltop: '',//form.wpTextbox1.scrollTop,
				text: formdata,//form.wpTextbox1.value,
				isPageFormData: true,
				summary: '',//form.wpSummary.value
			};
		}

		if ( form.wpMinoredit !== undefined && form.wpMinoredit.checked ) {
			params.minoredit = 1;
		}

		// Performs asynchronous save on server
		var api = new mediaWiki.Api();
		api.post(params).done( self.respond ).fail( self.respond );

		// Ensure timer is cleared in case we saved manually before it expired
		clearTimeout( timer );
		timer = null;
	};

	/**
	 * Updates the user interface to represent being out of sync with the server
	 */
	this.change = function() {
		// Sets state to changed
		// Checks if timer is pending and if we want to wait for user input
		if ( !configuration.autoSaveBasedOnInput ) {
			if ( timer ) {
				return;
			}
			if ( configuration.autoSaveWait && configuration.autoSaveWait > 0 ) {
				// Sets timer to save automatically after a period of time
				timer = setTimeout(
						self.save, configuration.autoSaveWait * 1000
					);
			}
			return;
		}

		if ( timer ) {
			// Clears pending timer
			clearTimeout( timer );
		}
		// Checks if auto-save wait time was set, and that it's greater than 0
		if ( configuration.autoSaveWait && configuration.autoSaveWait > 0 ) {
			// Sets timer to save automatically after a period of time
			timer = setTimeout(
					self.save, configuration.autoSaveWait * 1000
			);
		}
	};

	/**
	 * Initializes the user interface
	 */
	this.initialize = function() {
		// Cache edit form reference
		form = document.editform;
		// Check to see that the form and controls exist
		if ( form && form.wpDraftSave ) {
			// Handle manual draft saving through clicking the save draft button
			jQuery( form.wpDraftSave ).on( 'click', self.save );
			// Handle keeping track of state by watching for changes to fields
			jQuery( form.wpTextbox1 ).on( 'keypress keyup keydown paste cut', self.change );
			jQuery( form.wpSummary ).on( 'keypress keyup keydown paste cut', self.change );
			if ( form.wpMinoredit ) {
				jQuery( form.wpMinoredit ).on( 'change', self.change );
			}
			// Gets configured specific values
			configuration = {
				autoSaveWait: mediaWiki.config.get( 'wgDraftAutoSaveWait' ),
				autoSaveTimeout: mediaWiki.config.get( 'wgDraftAutoSaveTimeout' ),
				autoSaveBasedOnInput: mediaWiki.config.get( 'wgDraftAutoSaveInputBased' )
			};
		} else {
			// init for formEdit action (PageForm extension)
			form = document.forms['pfForm']
			if ( form && form.wpDraftSave ) {
				editIsPageForm = true;
				jQuery( form.wpDraftSave ).on( 'click', self.pfSave );
				// Handle keeping track of state by watching for changes to fields
				$( form )
					.on( 'keyup', 'input,select,textarea', function ( event ) {
						if ( event.which < 32 ){
							return true;
						}

						return self.change ( event );
					} )
					.on( 'change', 'input,select,textarea', self.change  )
					.on( 'click', '.multipleTemplateAdder,.removeButton,.rearrangerImage', self.change  )
					.on( 'mousedown', '.rearrangerImage',self.change  );

				// Gets configured specific values
				configuration = {
					autoSaveWait: mediaWiki.config.get( 'wgDraftAutoSaveWait' ),
					autoSaveTimeout: mediaWiki.config.get( 'wgDraftAutoSaveTimeout' ),
					autoSaveBasedOnInput: mediaWiki.config.get( 'wgDraftAutoSaveInputBased' )
				};
			}
		}



	};

	/**
	 * Responds to the server after a save request has been handled
	 * @param {Object} data
	 */
	this.respond = function( data ) {

		// Checks that an error did not occur
		if ( data.savedrafts && data.savedrafts.id ) {
			// Changes state to saved
			self.setState( 'saved' );
			// Gets id of newly inserted draft (or updates if it already exists)
			// and stores it in a hidden form field
			form.wpDraftID.value = data.savedrafts.id;
		} else {
			// Changes state to error
			self.setState( 'error' );
		}
	};
}

wgDraft = new Draft();
$(function() {
	// Handler for .ready() called.
	wgDraft.initialize();
});

