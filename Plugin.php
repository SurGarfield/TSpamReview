<?php
/**
 * 智能评论审核插件 - 敏感词检测、中文检测、百度内容审核、管理员豁免
 *
 * @package TSpamReview
 * @author 森木志
 * @version 1.0.6
 * @link https://oxxx.cn
 * @license MIT

 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
	exit;
}

class TSpamReview_Plugin implements Typecho_Plugin_Interface
{
	/** @var string */
	private static $tokenCacheFile = __DIR__ . DIRECTORY_SEPARATOR . '.baidu_token.json';

	public static function activate()
	{
		// 检查 PHP 版本
		if (version_compare(PHP_VERSION, '7.0.0', '<')) {
			throw new Typecho_Plugin_Exception(_t('TSpamReview 插件需要 PHP 7.0 或更高版本'));
		}

		// 检查必需的 PHP 扩展
		$requiredExtensions = ['json', 'mbstring'];
		foreach ($requiredExtensions as $ext) {
			if (!extension_loaded($ext)) {
				throw new Typecho_Plugin_Exception(_t('TSpamReview 插件需要 PHP %s 扩展', $ext));
			}
		}

		// 检查 Token 缓存文件是否可写
		$tokenFile = __DIR__ . DIRECTORY_SEPARATOR . '.baidu_token.json';
		$pluginDir = __DIR__;
		if (!is_writable($pluginDir)) {
			throw new Typecho_Plugin_Exception(_t('插件目录不可写，请检查权限：%s', $pluginDir));
		}

		// 如果 Token 文件不存在，创建默认文件
		if (!file_exists($tokenFile)) {
			$defaultToken = json_encode(['token' => '', 'expire' => 0], JSON_UNESCAPED_UNICODE);
			if (@file_put_contents($tokenFile, $defaultToken) === false) {
				throw new Typecho_Plugin_Exception(_t('无法创建 Token 缓存文件，请检查权限'));
			}
		}

		// 注册钩子
		Typecho_Plugin::factory('Widget_Feedback')->comment = [__CLASS__, 'onBeforeComment'];
		Typecho_Plugin::factory('Widget_Feedback')->finishComment = [__CLASS__, 'onFinishComment'];
		Typecho_Plugin::factory('Widget_Archive')->header = [__CLASS__, 'header'];
		Typecho_Plugin::factory('Widget_Archive')->footer = [__CLASS__, 'footer'];

		Helper::addAction('TSpamReview', 'TSpamReview_Action');

		return _t('TSpamReview 插件已成功激活！');
	}
	
	public static function deactivate()
	{
		if (class_exists('Helper')) {
			Helper::removeAction('TSpamReview');
		}
		return _t('TSpamReview 插件已禁用。');
	}

	public static function config(Typecho_Widget_Helper_Form $form)
	{
		// ==================== 基础设置 ====================
		$basicInfo = new Typecho_Widget_Helper_Layout('div', ['class' => 'typecho-option']);
		$basicInfo->html('<h3 style="margin-top:0;">基础设置</h3><p style="color:#999;">敏感词检测和中文检测配置</p>');
		$form->addItem($basicInfo);

		$sensitiveWords = new Typecho_Widget_Helper_Form_Element_Textarea(
			'sensitiveWords',
			null,
			'',
			_t('敏感词汇列表'),
			_t('每行一个词汇；将在评论内容、昵称、邮箱中检测，命中即拒绝评论。')
		);
		$sensitiveWords->setAttribute('rows', 8);
		$form->addInput($sensitiveWords);

		$actionOptions = [
			'A' => _t('A: 无操作（允许）'),
			'B' => _t('B: 待审核'),
			'C' => _t('C: 评论失败（阻止）'),
		];

		$contentChineseAction = new Typecho_Widget_Helper_Form_Element_Select(
			'contentChineseAction',
			$actionOptions,
			'A',
			_t('评论内容中文检测操作'),
			_t('当评论内容中不包含中文字符时执行该操作。')
		);
		$form->addInput($contentChineseAction->multiMode());

		$authorChineseAction = new Typecho_Widget_Helper_Form_Element_Select(
			'authorChineseAction',
			$actionOptions,
			'A',
			_t('昵称中文检测操作'),
			_t('当昵称中不包含中文字符时执行该操作。')
		);
		$form->addInput($authorChineseAction->multiMode());

		// ==================== 百度内容审核 ====================
		$baiduInfo = new Typecho_Widget_Helper_Layout('div', ['class' => 'typecho-option']);
		$baiduInfo->html('<h3>百度内容审核</h3><p style="color:#999;">使用百度AI进行智能内容审核（可选）。<a href="https://cloud.baidu.com/product/antiporn" target="_blank">申请百度API密钥 →</a></p>');
		$form->addItem($baiduInfo);

		$enableBaidu = new Typecho_Widget_Helper_Form_Element_Checkbox(
			'baiduEnable',
			['enable' => _t('启用百度文本内容审核')],
			[],
			_t('启用百度审核'),
			_t('勾选后将调用百度文本内容审核API，需要配置API密钥')
		);
		$form->addInput($enableBaidu->multiMode());

		$baiduApiKey = new Typecho_Widget_Helper_Form_Element_Text(
			'baiduApiKey',
			null,
			'',
			_t('百度 API Key'),
			_t('从百度智能云控制台获取。')
		);
		$form->addInput($baiduApiKey);

		$baiduSecretKey = new Typecho_Widget_Helper_Form_Element_Text(
			'baiduSecretKey',
			null,
			'',
			_t('百度 Secret Key'),
			_t('从百度智能云控制台获取。')
		);
		$form->addInput($baiduSecretKey);

		$baiduFailPolicy = new Typecho_Widget_Helper_Form_Element_Select(
			'baiduFailPolicy',
			[
				'allow' => _t('网络失败降级为允许'),
				'review' => _t('网络失败降级为待审核'),
			],
			'review',
			_t('百度审核网络失败降级策略'),
			_t('当调用百度接口失败时的降级行为。')
		);
		$form->addInput($baiduFailPolicy->multiMode());

		$baiduReviewAction = new Typecho_Widget_Helper_Form_Element_Select(
			'baiduReviewAction',
			[
				'B' => _t('百度返回“需审核” → 待审核'),
				'C' => _t('百度返回“需审核” → 直接失败'),
			],
			'B',
			_t('百度“需审核”处理方式'),
			_t('百度返回不确定时选择待审核或直接失败。')
		);
		$form->addInput($baiduReviewAction->multiMode());

		// ==================== 高级设置 ====================
		$advancedInfo = new Typecho_Widget_Helper_Layout('div', ['class' => 'typecho-option']);
		$advancedInfo->html('<h3>高级设置</h3><p style="color:#999;">前端预检、保存后复检、管理员豁免</p>');
		$form->addItem($advancedInfo);

		$frontCheck = new Typecho_Widget_Helper_Form_Element_Checkbox(
			'frontPrecheck',
			['enable' => _t('启用前端预检（提交前拦截并弹窗提示）')],
			['enable'],
			_t('前端预检')
		);
		$form->addInput($frontCheck->multiMode());

		$skipAdmin = new Typecho_Widget_Helper_Form_Element_Checkbox(
			'skipAdminReview',
			['enable' => _t('跳过管理员评论审核')],
			['enable'],
			_t('管理员豁免'),
			_t('已登录的管理员发表评论时跳过所有审核规则（敏感词、中文检测、百度审核）')
		);
		$form->addInput($skipAdmin->multiMode());

		// 调试选项（默认关闭）
		$debugLog = new Typecho_Widget_Helper_Form_Element_Checkbox(
			'debugLog',
			['enable' => _t('启用调试日志（写入 error_log ）')],
			[],
			_t('调试')
		);
		$form->addInput($debugLog->multiMode());
	}

	public static function personalConfig(Typecho_Widget_Helper_Form $form) {}


	public static function uninstall()
	{
		// 删除 Token 缓存文件（可选）
		$tokenFile = __DIR__ . DIRECTORY_SEPARATOR . '.baidu_token.json';
		if (file_exists($tokenFile)) {
			@unlink($tokenFile);
		}

	}

	public static function header()
	{
		self::emitFrontScript();
	}

	public static function footer()
	{
		self::emitFrontScript();
	}

	private static function emitFrontScript()
	{
		try {
			$opts = Typecho_Widget::widget('Widget_Options')->plugin('TSpamReview');
			$enabled = isset($opts->frontPrecheck) && is_array($opts->frontPrecheck) && in_array('enable', $opts->frontPrecheck, true);
			if (!$enabled) {
				return;
			}
			$rawList = isset($opts->sensitiveWords) ? (string)$opts->sensitiveWords : '';
			$words = array_values(self::parseSensitiveList($rawList));
			$wordsJs = json_encode($words, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			$contentAction = isset($opts->contentChineseAction) ? (string)$opts->contentChineseAction : 'A';
			$authorAction = isset($opts->authorChineseAction) ? (string)$opts->authorChineseAction : 'A';

			// Delegate to FrontScript emitter (heredoc) to avoid inline JS escaping issues
			if (!class_exists('TSpamReview_FrontScript')) {
				@include_once __DIR__ . '/FrontScript.php';
			}
			if (class_exists('TSpamReview_FrontScript')) {
				TSpamReview_FrontScript::emit($wordsJs, $contentAction, $authorAction);
			}
		} catch (Exception $e) {
			// ignore
		}
	}

	public static function onBeforeComment($comment, $post = null, $widget = null)
	{
		try {
			$opts = Typecho_Widget::widget('Widget_Options')->plugin('TSpamReview');
			self::debug('[hook] onBeforeComment called; author=' . (isset($comment['author']) ? $comment['author'] : '') . ' mail=' . (isset($comment['mail']) ? $comment['mail'] : '') . ' len(text)=' . strlen(isset($comment['text']) ? (string)$comment['text'] : ''));

		// 检查管理员豁免
		$skipAdmin = isset($opts->skipAdminReview) && is_array($opts->skipAdminReview) && in_array('enable', $opts->skipAdminReview, true);
		if ($skipAdmin && self::isAdmin()) {
			self::debug('[skip] admin user, bypass all reviews - returning comment');
			return $comment; // 管理员直接通过，返回原始评论数组
		}
		self::debug('[continue] not admin or bypass disabled, continue checking');

			// 1) 敏感词检测（内容/昵称/邮箱）
			$sensitiveList = self::parseSensitiveList(isset($opts->sensitiveWords) ? $opts->sensitiveWords : '');
			if (!empty($sensitiveList)) {
				if (self::hasSensitiveWord([
					isset($comment['text']) ? (string)$comment['text'] : '',
					isset($comment['author']) ? (string)$comment['author'] : '',
					isset($comment['mail']) ? (string)$comment['mail'] : '',
				], $sensitiveList)) {
					self::debug('[deny] sensitive word matched');
					throw new Typecho_Widget_Exception(_t('评论失败'));
				}
			}

			// 2) 中文检测：评论内容
			$contentAction = isset($opts->contentChineseAction) ? (string)$opts->contentChineseAction : 'A';
			if (!self::stringHasChinese(isset($comment['text']) ? (string)$comment['text'] : '')) {
				if ($contentAction === 'C') {
					self::debug('[deny] content no Chinese');
					throw new Typecho_Widget_Exception(_t('评论失败'));
				} elseif ($contentAction === 'B') {
					self::debug('[hold] content no Chinese → set status to waiting');
					$comment['status'] = 'waiting';
				}
			}

			// 3) 中文检测：昵称
			$authorAction = isset($opts->authorChineseAction) ? (string)$opts->authorChineseAction : 'A';
			if (!self::stringHasChinese(isset($comment['author']) ? (string)$comment['author'] : '')) {
				if ($authorAction === 'C') {
					self::debug('[deny] author no Chinese');
					throw new Typecho_Widget_Exception(_t('评论失败'));
				} elseif ($authorAction === 'B') {
					self::debug('[hold] author no Chinese → set status to waiting');
					$comment['status'] = 'waiting';
				}
			}

			// 4) 可选：百度文本审核
			$baiduEnabled = isset($opts->baiduEnable) && is_array($opts->baiduEnable) && in_array('enable', $opts->baiduEnable, true);
			if ($baiduEnabled) {
				$apiKey = isset($opts->baiduApiKey) ? trim((string)$opts->baiduApiKey) : '';
				$secretKey = isset($opts->baiduSecretKey) ? trim((string)$opts->baiduSecretKey) : '';
				self::debug('[baidu] precheck enabled, hasKey=' . ($apiKey !== '' && $secretKey !== '' ? 'yes' : 'no'));

				if ($apiKey !== '' && $secretKey !== '') {
					$audit = self::baiduTextAudit(isset($comment['text']) ? (string)$comment['text'] : '', $apiKey, $secretKey);
					if ($audit === 'block') {
						self::debug('[deny] baidu returns block');
						throw new Typecho_Widget_Exception(_t('评论失败'));
					} elseif ($audit === 'review') {
						$reviewAction = isset($opts->baiduReviewAction) ? (string)$opts->baiduReviewAction : 'B';
						if ($reviewAction === 'C') {
							self::debug('[deny] baidu returns review → deny by config');
							throw new Typecho_Widget_Exception(_t('评论失败'));
						} else {
							// B（待审核）- 修改评论状态为 waiting
							self::debug('[hold] baidu returns review → set status to waiting');
							$comment['status'] = 'waiting';
						}
					} elseif ($audit === 'error') {
						// 网络异常时进入待审核（避免漏掉重要信息）
						self::debug('[hold] baidu error → set status to waiting (avoid missing important info)');
						$comment['status'] = 'waiting';
					}
				}
			} else {
				self::debug('[baidu] precheck disabled');
			}

			self::debug('[pass] onBeforeComment - will check status in finishComment');
		} catch (Typecho_Widget_Exception $e) {
			self::debug('[exception] Typecho_Widget_Exception: ' . $e->getMessage());
			throw $e; // 重新抛出异常
		} catch (Exception $e) {
			self::debug('[error] onBeforeComment exception: ' . $e->getMessage());
		}
		self::debug('[hook] onBeforeComment completed successfully - returning comment');
		return $comment; // 必须返回修改后的评论数组
	}

	private static function stringHasChinese($text)
	{
		if ($text === '') {
			return false;
		}
		return (bool)preg_match('/[\x{4e00}-\x{9fa5}]/u', $text);
	}

	private static function parseSensitiveList($raw)
	{
		$items = preg_split('/\r?\n/', (string)$raw);
		$clean = [];
		foreach ($items as $item) {
			$word = trim($item);
			if ($word !== '') {
				$clean[$word] = true;
			}
		}
		$list = array_keys($clean);
		self::debug('[sens] parsed words count=' . count($list));
		return $list;
	}

	private static function hasSensitiveWord(array $fields, array $words)
	{
		foreach ($fields as $field) {
			$haystack = (string)$field;
			if ($haystack === '') {
				continue;
			}
			foreach ($words as $word) {
				if (function_exists('mb_stripos')) {
					if (mb_stripos($haystack, $word) !== false) {
						self::debug('[sens] hit word="' . $word . '"');
						return true;
					}
				} else {
					if (stripos($haystack, $word) !== false) {
						self::debug('[sens] hit word="' . $word . '"');
						return true;
					}
				}
			}
		}
		if (!empty($words)) {
			$preview = function ($s) {
				$s = (string)$s;
				if ($s === '') return '';
				$s = preg_replace('/\s+/', ' ', $s);
				return mb_substr($s, 0, 60, 'UTF-8');
			};
			self::debug('[sens] no hit; firstFieldPreview="' . $preview(isset($fields[0]) ? $fields[0] : '') . '" wordsFirst=' . (isset($words[0]) ? $words[0] : 'n/a'));
		}
		return false;
	}

	public static function baiduTextAudit($text, $apiKey, $secretKey)
	{
		if ($text === '') {
			return 'pass';
		}

		$accessToken = self::loadCachedToken();
		if (!$accessToken) {
			$accessToken = self::fetchBaiduAccessToken($apiKey, $secretKey);
			if ($accessToken) {
				self::storeCachedToken($accessToken, time() + 25 * 60 * 60);
			} else {
				self::debug('[baidu] token fetch failed');
				return 'error';
			}
		}

		$url = 'https://aip.baidubce.com/rest/2.0/solution/v1/text_censor/v2/user_defined?access_token=' . rawurlencode($accessToken);
		$resp = self::httpPostForm($url, ['text' => $text, 'riskWarning' => 'true'], 8);
		if ($resp === false) {
			self::debug('[baidu] http error');
			return 'error';
		}

		$data = json_decode($resp, true);
		if (!is_array($data)) {
			self::debug('[baidu] invalid json');
			return 'error';
		}

		if (isset($data['error_code'])) {
			if (in_array((int)$data['error_code'], [110, 111, 100, 18], true)) {
				$accessToken = self::fetchBaiduAccessToken($apiKey, $secretKey);
				if ($accessToken) {
					self::storeCachedToken($accessToken, time() + 25 * 60 * 60);
					$url = 'https://aip.baidubce.com/rest/2.0/solution/v1/text_censor/v2/user_defined?access_token=' . rawurlencode($accessToken);
					$resp = self::httpPostForm($url, ['text' => $text], 8);
					if ($resp === false) {
						self::debug('[baidu] http error after refresh');
						return 'error';
					}
					$data = json_decode($resp, true);
					if (!is_array($data)) {
						self::debug('[baidu] invalid json after refresh');
						return 'error';
					}
				} else {
					self::debug('[baidu] token refresh failed');
					return 'error';
				}
			}
		}

		if (isset($data['conclusionType'])) {
			$ct = (int)$data['conclusionType'];
			self::debug('[baidu] conclusionType=' . $ct . ' conclusion=' . (isset($data['conclusion']) ? $data['conclusion'] : 'N/A'));
			if ($ct === 1) return 'pass';
			if ($ct === 2) return 'review';
			if ($ct === 3) return 'block';
		}

		// 兼容另一种返回结构（老版本接口可能返回 result 里）
		if (isset($data['result']) && is_array($data['result']) && isset($data['result']['conclusionType'])) {
			$ct = (int)$data['result']['conclusionType'];
			self::debug('[baidu] result.conclusionType=' . $ct);
			if ($ct === 1) return 'pass';
			if ($ct === 2) return 'review';
			if ($ct === 3) return 'block';
		}
		self::debug('[baidu] unknown result payload: ' . json_encode($data));
		return 'error';
	}

	private static function fetchBaiduAccessToken($apiKey, $secretKey)
	{
		$url = 'https://aip.baidubce.com/oauth/2.0/token';
		$resp = self::httpPostForm($url, [
			'grant_type' => 'client_credentials',
			'client_id' => $apiKey,
			'client_secret' => $secretKey,
		], 6);
		if ($resp === false) {
			return false;
		}
		$data = json_decode($resp, true);
		if (isset($data['access_token']) && is_string($data['access_token'])) {
			return $data['access_token'];
		}
		return false;
	}

	private static function httpPostForm($url, array $fields, $timeout = 5)
	{
		$postFields = http_build_query($fields, '', '&');
		if (function_exists('curl_init')) {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
			curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
			curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
			$res = curl_exec($ch);
			if ($res === false) {
				curl_close($ch);
				return false;
			}
			curl_close($ch);
			return $res;
		}
		$ctx = stream_context_create([
			'http' => [
				'method' => 'POST',
				'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
				'content' => $postFields,
				'timeout' => $timeout,
			],
		]);
		$res = @file_get_contents($url, false, $ctx);
		return $res === false ? false : $res;
	}

	private static function loadCachedToken()
	{
		$file = self::$tokenCacheFile;
		if (!is_file($file)) {
			return false;
		}
		$raw = @file_get_contents($file);
		if ($raw === false) {
			return false;
		}
		$data = json_decode($raw, true);
		if (!is_array($data) || !isset($data['token']) || !isset($data['expire'])) {
			return false;
		}
		if ((int)$data['expire'] <= time()) {
			return false;
		}
		return (string)$data['token'];
	}

	private static function storeCachedToken($token, $expireTs)
	{
		$payload = json_encode([
			'token' => (string)$token,
			'expire' => (int)$expireTs,
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		@file_put_contents(self::$tokenCacheFile, $payload);
	}

	private static function debug($message)
	{
		try {
			$opts = Typecho_Widget::widget('Widget_Options')->plugin('TSpamReview');
			$enabled = isset($opts->debugLog) && is_array($opts->debugLog) && in_array('enable', $opts->debugLog, true);
			if ($enabled) {
				@error_log('[TSpamReview] ' . $message);
			}
		} catch (Exception $e) {}
	}

	/**
	 * 检查当前用户是否为管理员
	 */
	private static function isAdmin()
	{
		try {
			$user = Typecho_Widget::widget('Widget_User');
			if (!$user->hasLogin()) {
				return false;
			}
			// 检查用户组是否为 administrator
			if (isset($user->group) && $user->group === 'administrator') {
				self::debug('[isAdmin] uid=' . $user->uid . ' group=administrator');
				return true;
			}
			// 使用 pass 方法检查权限（静默模式）
			if (method_exists($user, 'pass') && $user->pass('administrator', true)) {
				self::debug('[isAdmin] uid=' . $user->uid . ' pass() check=true');
				return true;
			}
			return false;
		} catch (Exception $e) {
			self::debug('[isAdmin] exception: ' . $e->getMessage());
			return false;
		}
	}

	public static function onFinishComment()
	{
		try {
			self::debug('[hook] onFinishComment called (fallback mode)');
			$args = func_get_args();
			$widget = null;
			$comment = null;
			if (count($args) === 2) {
				$widget = $args[0];
				$comment = $args[1];
			} elseif (count($args) === 1) {
				$comment = $args[0];
			}

			$opts = Typecho_Widget::widget('Widget_Options')->plugin('TSpamReview');

			$type = (string)self::getFieldValue($comment, 'type');
			$text = (string)self::getFieldValue($comment, 'text');
			$author = (string)self::getFieldValue($comment, 'author');
			$mail = (string)self::getFieldValue($comment, 'mail');
			$status = (string)self::getFieldValue($comment, 'status');
			$coid = (int)self::getFieldValue($comment, 'coid');
			if (!$coid && is_object($widget)) {
				$coid = (int)self::getFieldValue($widget, 'coid');
			}
			self::debug('[fallback] extracted coid=' . ($coid ?: 0) . ' type=' . ($type !== '' ? $type : 'n/a') . ' status=' . ($status !== '' ? $status : 'n/a'));

			if ($type !== '' && $type !== 'comment') {
				return;
			}
			if ($status !== '' && in_array($status, ['waiting', 'hidden'], true)) {
				return;
			}

			// 检查管理员豁免
			$skipAdmin = isset($opts->skipAdminReview) && is_array($opts->skipAdminReview) && in_array('enable', $opts->skipAdminReview, true);
			if ($skipAdmin && self::isAdmin()) {
				self::debug('[fallback][skip] admin user, bypass all reviews');
				return;
			}

			$sensitiveList = self::parseSensitiveList(isset($opts->sensitiveWords) ? $opts->sensitiveWords : '');
			$willHold = false;
			$willDeny = false;
			if (!empty($sensitiveList)) {
				if (self::hasSensitiveWord([$text, $author, $mail], $sensitiveList)) {
					$willDeny = true;
				}
			}

			$contentAction = isset($opts->contentChineseAction) ? (string)$opts->contentChineseAction : 'A';
			if (!self::stringHasChinese($text)) {
				if ($contentAction === 'B') $willHold = true;
				elseif ($contentAction === 'C') $willDeny = true;
			}

			$authorAction = isset($opts->authorChineseAction) ? (string)$opts->authorChineseAction : 'A';
			if (!self::stringHasChinese($author)) {
				if ($authorAction === 'B') $willHold = true;
				elseif ($authorAction === 'C') $willDeny = true;
			}

			// 可选：保存后也执行百度审核（防止前置钩子未触发的环境）
			$baiduEnabled = isset($opts->baiduEnable) && is_array($opts->baiduEnable) && in_array('enable', $opts->baiduEnable, true);
			if ($baiduEnabled) {
				$apiKey = isset($opts->baiduApiKey) ? trim((string)$opts->baiduApiKey) : '';
				$secretKey = isset($opts->baiduSecretKey) ? trim((string)$opts->baiduSecretKey) : '';
				$failPolicy = isset($opts->baiduFailPolicy) ? (string)$opts->baiduFailPolicy : 'review';
				$reviewAction = isset($opts->baiduReviewAction) ? (string)$opts->baiduReviewAction : 'B';
				self::debug('[baidu][fallback] enabled, hasKey=' . ($apiKey !== '' && $secretKey !== '' ? 'yes' : 'no'));
				if ($apiKey !== '' && $secretKey !== '') {
					$audit = self::baiduTextAudit($text, $apiKey, $secretKey);
					if ($audit === 'block') {
						self::debug('[baidu][fallback] block → deny');
						$willDeny = true;
					} elseif ($audit === 'review') {
						if ($reviewAction === 'C') {
							self::debug('[baidu][fallback] review → deny by config');
							$willDeny = true;
						} else {
							self::debug('[baidu][fallback] review → hold');
							$willHold = true;
						}
					} elseif ($audit === 'error') {
						// 网络异常时进入待审核（避免漏掉重要信息）
						self::debug('[baidu][fallback] error → hold (avoid missing important info)');
						$willHold = true;
					}
				}
			}

			$db = Typecho_Db::get();
			$table = $db->getPrefix() . 'comments';
			if ($willDeny && $coid > 0) {
				try {
					$query = $db->delete($table)->where('coid = ?', $coid);
					$db->query($query);
					self::debug('[fallback] delete denied coid=' . $coid);
				} catch (Exception $e) {
					self::debug('[fallback][db-error-delete] ' . $e->getMessage());
				}
			} elseif ($willHold && $coid > 0) {
				try {
					$query = $db->update($table)->rows(['status' => 'waiting'])->where('coid = ?', $coid);
					$db->query($query);
					self::debug('[fallback] force waiting coid=' . $coid);
				} catch (Exception $e) {
					self::debug('[fallback][db-error] ' . $e->getMessage());
				}
			} else {
				self::debug('[fallback] no hold rule matched');
			}
		} catch (Exception $e) {
			self::debug('[fallback][error] ' . $e->getMessage());
		}
	}

	private static function getFieldValue($source, $key)
	{
		if (is_array($source)) {
			return isset($source[$key]) ? $source[$key] : null;
		}
		if (is_object($source)) {
			if (isset($source->$key)) return $source->$key;
			if ($source instanceof ArrayAccess) {
				try { return $source[$key]; } catch (Exception $e) {}
			}
			$cast = (array)$source;
			if (isset($cast[$key])) return $cast[$key];
			foreach ($cast as $k => $v) {
				if (substr($k, -strlen($key)) === $key) return $v;
			}
		}
		return null;
	}
}

/**
 * TSpamReview_Action - 已废弃的 Action 类
 * 
 * 保留此类仅为防止插件激活时出错（Typecho 要求 Helper::addAction 的类必须存在）
 * 实际的预审核功能已迁移到 endpoint.php
 */
class TSpamReview_Action extends Typecho_Widget implements Widget_Interface_Do
{
	public function action()
	{
		// 已废弃：所有预审核请求已转向 endpoint.php
		$this->response->throwJson([
			'ok' => true, 
			'decision' => 'allow', 
			'message' => 'This endpoint is deprecated. Please use endpoint.php instead.'
		]);
	}
}

