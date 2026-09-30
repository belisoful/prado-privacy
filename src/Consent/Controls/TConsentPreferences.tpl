<com:TActivePanel ID="Panel" CssClass="prado-consent-preferences">
<com:TPlaceHolder ID="Body">
<div class="prado-consent-preferences-inner" role="region" aria-label="<%= \Prado\Web\THttpUtility::htmlEncode($this->getTitle()) %>">
	<h2 class="prado-consent-title"><com:TLiteral ID="TitleText" Encode="true" /></h2>
	<com:TPlaceHolder ID="Categories" />
	<div class="prado-consent-actions">
		<com:TActiveButton ID="RejectAll" CssClass="prado-consent-button" CausesValidation="false" OnClick="rejectAllClicked" OnCallback="refreshConsentControls" />
		<com:TActiveButton ID="AcceptAll" CssClass="prado-consent-button" CausesValidation="false" OnClick="acceptAllClicked" OnCallback="refreshConsentControls" />
		<com:TActiveButton ID="Save" CssClass="prado-consent-button" CausesValidation="false" OnClick="saveClicked" OnCallback="refreshConsentControls" />
		<com:TActiveButton ID="Close" CssClass="prado-consent-button-secondary" CausesValidation="false" OnClick="closeClicked" OnCallback="refreshConsentControls" />
	</div>
</div>
</com:TPlaceHolder>
</com:TActivePanel>
