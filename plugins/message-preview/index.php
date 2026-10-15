<?php
/**
 * Message Preview: a line of body text after the subject in the message list.
 *
 * The core only fills the message list "preview" field when the IMAP server
 * advertises PREVIEW (RFC 8970), and many servers do not. For those, the
 * browser asks this hook for the visible messages in batches, and each one is
 * read through MailClient::Message(), which opens the folder with EXAMINE.
 * Nothing is ever marked as read.
 */
class MessagePreviewPlugin extends \Tachyon\Plugins\AbstractPlugin
{
	const
		NAME = 'Message Preview',
		AUTHOR = 'Dan Clarke',
		URL = 'https://github.com/kimusan/Tachyon',
		VERSION = '1.0',
		RELEASE = '2026-10-16',
		REQUIRED = '4.4.0',
		CATEGORY = 'General',
		LICENSE = 'MIT',
		DESCRIPTION = 'Show a line of the message body after the subject in the message list, also on IMAP servers without PREVIEW (RFC 8970)';

	const
		MAX_UIDS = 50,   // per request
		MAX_CHARS = 200; // per preview

	public function Init() : void
	{
		$this->addCss('style.css');
		$this->addJs('preview.js');
		$this->addJsonHook('MessagePreview', 'DoMessagePreview');
	}

	/**
	 * POST PluginMessagePreview  { folder: "INBOX", uids: "12,13,14" }
	 * Returns { "12": "preview…", "13": "", … }
	 */
	public function DoMessagePreview() : array
	{
		$aResult = [];

		$sFolder = (string) ($this->jsonParam('folder') ?? '');
		$aUids = \array_slice(
			\array_values(\array_unique(\array_filter(
				\array_map('intval', \explode(',', (string) ($this->jsonParam('uids') ?? '')))
			))),
			0, self::MAX_UIDS
		);

		if (!\strlen($sFolder) || !$aUids) {
			return $this->jsonResponse(__FUNCTION__, $aResult);
		}

		// Same steps as the core's (protected) initMailClientConnection(),
		// using only public methods.
		$oActions = \Tachyon\Api::Actions();
		$oAccount = $oActions->getAccountFromToken(false);
		if (!$oAccount) {
			return $this->jsonResponse(__FUNCTION__, false);
		}
		$oImapClient = $oActions->ImapClient();
		if (!$oImapClient->IsLoggined()) {
			$oAccount->ImapConnectAndLogin($oActions->Plugins(), $oImapClient, $oActions->Config());
		}
		$oMailClient = $oActions->MailClient();

		foreach ($aUids as $iUid) {
			try {
				// MailClient::Message() opens the folder with EXAMINE, so the
				// \Seen flag is never touched.
				$oMessage = $oMailClient->Message($sFolder, $iUid, true);
				$aResult[$iUid] = $oMessage ? static::previewText($oMessage->jsonSerialize()) : '';
			} catch (\Throwable $oException) {
				$aResult[$iUid] = '';
			}
		}

		return $this->jsonResponse(__FUNCTION__, $aResult);
	}

	private static function previewText(array $aMessage) : string
	{
		$sText = isset($aMessage['plain']) ? \trim((string) $aMessage['plain']) : '';

		if ('' === $sText && !empty($aMessage['html'])) {
			$sHtml = (string) $aMessage['html'];
			$sHtml = \preg_replace('#<(head|style|script|title|noscript)\b[^>]*>.*?</\1\s*>#is', ' ', $sHtml) ?? $sHtml;
			$sHtml = \preg_replace('#<br\s*/?>|</(p|div|tr|td|li|h[1-6])\s*>#i', ' ', $sHtml) ?? $sHtml;
			$sText = \html_entity_decode(\strip_tags($sHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		}

		$aRules = [
			'/^\s*>.*$/mu' => ' ',                    // quoted reply lines
			'/<https?:\/\/[^>\s]+>/iu' => ' ',        // <https://…> link targets in plain text
			'/\[https?:\/\/[^\]\s]+\]/iu' => ' ',     // [https://…]
			'/https?:\/\/\S+/iu' => ' ',              // bare URLs
			// zero-width / invisible padding newsletters use to hide their preheader
			'/[\x{00A0}\x{00AD}\x{034F}\x{061C}\x{115F}\x{1160}\x{17B4}\x{17B5}\x{180E}\x{2000}-\x{200F}\x{2028}-\x{202F}\x{205F}-\x{206F}\x{3000}\x{FEFF}]+/u' => ' ',
			'/\s+/u' => ' ',
		];
		foreach ($aRules as $sPattern => $sReplace) {
			$sText = \preg_replace($sPattern, $sReplace, $sText) ?? $sText;
		}
		$sText = \trim($sText);

		return \mb_strlen($sText) > self::MAX_CHARS
			? \rtrim(\mb_substr($sText, 0, self::MAX_CHARS)) . '…'
			: $sText;
	}
}
