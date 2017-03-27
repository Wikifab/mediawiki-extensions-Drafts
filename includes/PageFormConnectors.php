<?php

namespace NsDrafts;

class PageFormConnectors {

	/**
	 *
	 formData is with the following format : (in json)
		{
		{
		"name" => "<ModelName[fieldName]>",
		"value" => "<value>",
		},
		...
		}
		we must convert it to  :
		{
		"<ModelName>" => {
		"<fieldName>" => "<value>",
		}
		...
		}


	 * @param string $serializedForm json serialized form data
	 * @return array associative array
	 */
	private static function convertSerializedFormArrayToAssociativeArray($serializedForm) {
		$formData = json_decode($serializedForm, true);


		$result = array();

		foreach ($formData as $field) {
			self::recursiveAddValueToArray($result, $field['name'], $field['value']);
		}
		return $result;
	}

	private static function recursiveAddValueToArray(&$array, $key, $value) {

		if ( preg_match("/^([^\[\]]+)\[([^\[\]]+)\](.+)$/", $key, $matches)) {

			$firstKey = str_replace(' ', '_', $matches[1]);
			$secondKey = $matches[2];
			$rest = $matches[3];
			if (!isset($array[$firstKey])) {
				$array[$firstKey] = array();
			}
			self::recursiveAddValueToArray($array[$firstKey], $secondKey . $rest, $value);

		} else if (preg_match("/^([^\[\]]*)\[([^\[\]]*)\]$/", $key, $matches)) {
			// tableau simple :
			$firstKey = str_replace(' ', '_', $matches[1]);
			$secondKey = $matches[2];
			if (!isset($array[$firstKey])) {
				$array[$firstKey] = array();
			}
			$array[$firstKey][$secondKey] = $value;
		} else {
			$array[$key] = $value;
		}

	}

	/**
	 * For a page edited with PageForm (action = formedit) :
	 * convert a json serialized html form to page content in wikitext
	 *
	 * @param string formName : title of the form used to edit the page
	 * @param json $serializedForm
	 */
	public static function convertSerializeFormToSemanticTextContent($pageName, $serializedForm) {

		global $wgRequest, $wgPageFormsFormPrinter;

		$oldRequest = $wgRequest;

		$formData = self::convertSerializedFormArrayToAssociativeArray($serializedForm);

		// find the title of the form to be used
		$formTitle = self::getFormTitle($pageName);

		if( ! $formTitle ) {
			// if page doesn't exists yet, form name is in url : wpDraftTitle = 'Spécial:AjouterDonnées/Tutoriel/testdraft
			$draftTitleName = $formData['wpDraftTitle'];
			if (preg_match('#^([^/]+)/([^/]+)/([^/]+)$#', $draftTitleName, $matches) ) {
				$formTitle = \Title::makeTitleSafe( PF_NS_FORM, $matches[2]);
			}
		}

		// get the form content
		$formContent = \StringUtils::delimiterReplace(
				'<noinclude>', // start delimiter
				'</noinclude>', // end delimiter
				'', // replace by
				self::getTextForPage( $formTitle ) // subject
				);

		$formData['Tuto Details[Type]'] = "Création";
		$formData['Tuto Details'] = array('Type' => "Création");
		$wgRequest = new \FauxRequest( $formData, true );

		$isFormSubmitted = true;
		$pageExists = false;
		$formArticleId = $formTitle->getArticleID();
		$preloadContent = '';
		$targetName = 'temp';
		$targetNameFormula = null;

		list ( $formHTML, $targetContent, $generatedFormName, $generatedTargetNameFormula ) =
			$wgPageFormsFormPrinter->formHTML( $formContent, $isFormSubmitted, $pageExists, $formArticleId, $preloadContent, $targetName, $targetNameFormula );


		$wgRequest = $oldRequest;
		return $targetContent;
	}

	/**
	 * For a page edited with PageForm (action = formedit) :
	 * return the Form used to submit the page
	 *
	 * @param string formName : title of the form used to edit the page
	 * @param json $serializedForm
	 */
	public static function getFormTitle($pageName) {
		$formTitle = \Title::newFromText($pageName);
		$forms = \PFFormLinker::getDefaultFormsForPage($formTitle);

		if($forms) {
			return $formTitle = \Title::makeTitleSafe( PF_NS_FORM, $forms[0]);
		}
		return null;
	}

	public static function getTextForPage( $title ) {
		if(! $title) {
			return '';
		}
		$wikiPage = \WikiPage::factory( $title );
		return $wikiPage->getContent( \Revision::RAW )->getNativeData();
	}

}