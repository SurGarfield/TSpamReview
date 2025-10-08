<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
	exit;
}

class TSpamReview_FrontScript
{
	public static function emit($wordsJs, $contentAction, $authorAction)
	{
		$site = Helper::options()->siteUrl;
		$asset = rtrim($site, '/') . '/usr/plugins/TSpamReview/static/front.js.php';
		// 使用直接端点而不是 Action 路由
		$preAuditUrl = rtrim($site, '/') . '/usr/plugins/TSpamReview/endpoint.php';
		$config = json_encode([
			'words' => json_decode($wordsJs, true),
			'contentAction' => $contentAction,
			'authorAction' => $authorAction,
			'preAuditUrl' => $preAuditUrl,
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		echo '<script>window.TSpamReviewConfig=' . $config . '</script>';
		echo '<script src="' . htmlspecialchars($asset, ENT_QUOTES, 'UTF-8') . '"></script>';
	}
}


