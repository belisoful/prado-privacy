<com:TActivePanel ID="Panel" CssClass="prado-consent-banner">
<com:TPlaceHolder ID="Body">
<div class="prado-consent-banner-inner" role="region" aria-label="<%= \Prado\Web\THttpUtility::htmlEncode($this->getLabel()) %>">
	<p class="prado-consent-text"><com:TLiteral ID="MessageText" Encode="true" /> <com:THyperLink ID="PolicyLink" CssClass="prado-consent-policy" /></p>
	<com:TPlaceHolder ID="GpcNotice"><p class="prado-consent-gpc"><com:TLiteral ID="GpcText" Encode="true" /></p></com:TPlaceHolder>
	<div class="prado-consent-actions">
		<com:TActiveButton ID="RejectAll" CssClass="prado-consent-button" CausesValidation="false" OnClick="rejectAllClicked" OnCallback="refreshConsentControls" />
		<com:TActiveButton ID="AcceptAll" CssClass="prado-consent-button" CausesValidation="false" OnClick="acceptAllClicked" OnCallback="refreshConsentControls" />
		<com:TActiveButton ID="Customize" CssClass="prado-consent-button-secondary" CausesValidation="false" OnClick="customizeClicked" OnCallback="refreshConsentControls" />
	</div>
</div>
</com:TPlaceHolder>
</com:TActivePanel>
