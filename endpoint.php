<?php
/**
 * TSpamReview 预审核端点 - 独立版本
 * 不依赖 Plugin.php，直接实现核心逻辑
 */

error_reporting(0);
ini_set('display_errors', '0');

// 仅允许 POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	header('Content-Type: application/json; charset=UTF-8');
	echo json_encode(['ok' => false, 'decision' => 'deny', 'error' => 'method_not_allowed']);
	exit;
}

// 加载 Typecho 配置
$rootDir = dirname(dirname(dirname(__DIR__)));
if (!defined('__TYPECHO_ROOT_DIR__')) {
	define('__TYPECHO_ROOT_DIR__', $rootDir);
}

try {
	// 最小化加载 Typecho
	@include_once $rootDir . '/config.inc.php';
	@include_once $rootDir . '/var/Typecho/Common.php';
	@include_once $rootDir . '/var/Typecho/Db.php';
	@include_once $rootDir . '/var/Typecho/Widget.php';
	@include_once $rootDir . '/var/Typecho/Request.php';
	@include_once $rootDir . '/var/Typecho/Response.php';
	@include_once $rootDir . '/var/Typecho/Cookie.php';
	@include_once $rootDir . '/var/Widget/Options.php';
	@include_once $rootDir . '/var/Widget/User.php';
	
	// 初始化请求上下文（必须，否则无法读取 Cookie）
	if (!isset($_SESSION)) {
		@session_start();
	}
	
	// 获取参数
	$text = isset($_POST['text']) ? trim($_POST['text']) : '';
	$author = isset($_POST['author']) ? trim($_POST['author']) : '';
	$mail = isset($_POST['mail']) ? trim($_POST['mail']) : '';
	
	// 获取插件配置
	$db = Typecho_Db::get();
	$options = Typecho_Widget::widget('Widget_Options');
	$opts = $options->plugin('TSpamReview');
	
	$reasons = [];
	
	// 辅助函数：检测中文
	function hasChinese($str) {
		return preg_match('/[\x{4e00}-\x{9fa5}]/u', $str) > 0;
	}
	
	// 辅助函数：解析敏感词列表
	function parseSensitiveWords($input) {
		if (empty($input)) return [];
		$lines = preg_split('/\r\n|\r|\n/', trim($input));
		$words = [];
		foreach ($lines as $line) {
			$word = trim($line);
			if ($word !== '') {
				$words[] = $word;
			}
		}
		return $words;
	}
	
	// 辅助函数：检查敏感词
	function hasSensitiveWord($fields, $words) {
		foreach ($fields as $field) {
			if (empty($field)) continue;
			$lower = mb_strtolower($field, 'UTF-8');
			foreach ($words as $word) {
				$w = mb_strtolower(trim($word), 'UTF-8');
				if ($w === '') continue;
				if (mb_strpos($lower, $w) !== false) {
					return true;
				}
			}
		}
		return false;
	}
	
	// 辅助函数：检查当前用户是否为管理员
	function isAdmin() {
		try {
			// 方法1：通过 Cookie 直接检查数据库（最可靠）
			// Typecho 的 Cookie 名称带有前缀，需要遍历查找
			$cookieUid = null;
			foreach ($_COOKIE as $name => $value) {
				if (strpos($name, '__typecho_uid') !== false) {
					$cookieUid = intval($value);
					break;
				}
			}
			
			if ($cookieUid) {
				$db = Typecho_Db::get();
				$prefix = $db->getPrefix();
				
				try {
					$user = $db->fetchRow($db->select()
						->from($prefix . 'users')
						->where('uid = ?', $cookieUid)
						->limit(1));
					
					if ($user && isset($user['group']) && $user['group'] === 'administrator') {
						return true;
					}
				} catch (Exception $dbErr) {
					// 数据库查询失败，继续尝试其他方法
				}
			}
			
			// 方法2：使用 Widget_User（备用）
			if (class_exists('Widget_User')) {
				$user = Typecho_Widget::widget('Widget_User');
				if (method_exists($user, 'hasLogin') && $user->hasLogin()) {
					if (isset($user->group) && $user->group === 'administrator') {
						return true;
					}
					if (method_exists($user, 'pass') && $user->pass('administrator', true)) {
						return true;
					}
				}
			}
			
			return false;
		} catch (Exception $e) {
			return false;
		}
	}
	
	// 检查管理员豁免
	$skipAdmin = isset($opts->skipAdminReview) && is_array($opts->skipAdminReview) && in_array('enable', $opts->skipAdminReview, true);
	if ($skipAdmin && isAdmin()) {
		header('Content-Type: application/json; charset=UTF-8');
		echo json_encode(['ok' => true, 'decision' => 'allow', 'message' => 'admin_bypass']);
		exit;
	}
	
	// 1. 敏感词检测
	$sensitiveList = parseSensitiveWords(isset($opts->sensitiveWords) ? $opts->sensitiveWords : '');
	if (!empty($sensitiveList) && hasSensitiveWord([$text, $author, $mail], $sensitiveList)) {
		$reasons[] = 'sensitive';
	}
	
	// 2. 中文检测
	$needHold = false;
	$contentAction = isset($opts->contentChineseAction) ? (string)$opts->contentChineseAction : 'A';
	if (!hasChinese($text)) {
		if ($contentAction === 'C') {
			$reasons[] = 'content_no_cn';
		} elseif ($contentAction === 'B') {
			$needHold = true;
		}
	}
	
	$authorAction = isset($opts->authorChineseAction) ? (string)$opts->authorChineseAction : 'A';
	if (!hasChinese($author)) {
		if ($authorAction === 'C') {
			$reasons[] = 'author_no_cn';
		} elseif ($authorAction === 'B') {
			$needHold = true;
		}
	}
	
	// 本地验证失败直接返回
	if (!empty($reasons)) {
		header('Content-Type: application/json; charset=UTF-8');
		echo json_encode(['ok' => false, 'decision' => 'deny', 'reasons' => $reasons]);
		exit;
	}
	
	// 如果需要待审核，直接返回
	if ($needHold) {
		header('Content-Type: application/json; charset=UTF-8');
		echo json_encode(['ok' => true, 'decision' => 'hold', 'reasons' => ['content_or_author_no_cn']]);
		exit;
	}
	
	// 3. 百度审核
	$enabled = isset($opts->baiduEnable) && is_array($opts->baiduEnable) && in_array('enable', $opts->baiduEnable, true);
	if (!$enabled) {
		header('Content-Type: application/json; charset=UTF-8');
		echo json_encode(['ok' => true, 'decision' => 'allow']);
		exit;
	}
	
	$apiKey = isset($opts->baiduApiKey) ? trim((string)$opts->baiduApiKey) : '';
	$secretKey = isset($opts->baiduSecretKey) ? trim((string)$opts->baiduSecretKey) : '';
	if ($apiKey === '' || $secretKey === '') {
		header('Content-Type: application/json; charset=UTF-8');
		echo json_encode(['ok' => true, 'decision' => 'allow']);
		exit;
	}
	
	// 调用百度审核 API
	$tokenFile = __DIR__ . '/.baidu_token.json';
	$accessToken = null;
	
	// 读取缓存的 token
	if (file_exists($tokenFile)) {
		$cached = @json_decode(file_get_contents($tokenFile), true);
		if ($cached && isset($cached['token']) && isset($cached['expire']) && $cached['expire'] > time()) {
			$accessToken = $cached['token'];
		}
	}
	
	// 获取新 token
	if (!$accessToken) {
		$tokenUrl = 'https://aip.baidubce.com/oauth/2.0/token?grant_type=client_credentials&client_id=' . $apiKey . '&client_secret=' . $secretKey;
		$tokenResp = @file_get_contents($tokenUrl);
		if ($tokenResp) {
			$tokenData = @json_decode($tokenResp, true);
			if (isset($tokenData['access_token'])) {
				$accessToken = $tokenData['access_token'];
				@file_put_contents($tokenFile, json_encode(['token' => $accessToken, 'expire' => time() + 25 * 60 * 60]));
			}
		}
	}
	
	if (!$accessToken) {
		// Token 获取失败，进入待审核（避免漏掉重要信息）
		header('Content-Type: application/json; charset=UTF-8');
		echo json_encode(['ok' => true, 'decision' => 'hold']);
		exit;
	}
	
	// 调用审核接口
	$apiUrl = 'https://aip.baidubce.com/rest/2.0/solution/v1/text_censor/v2/user_defined?access_token=' . $accessToken;
	$postData = http_build_query(['text' => $text]);
	$context = stream_context_create([
		'http' => [
			'method' => 'POST',
			'header' => 'Content-Type: application/x-www-form-urlencoded',
			'content' => $postData,
			'timeout' => 8,
		]
	]);
	
	$apiResp = @file_get_contents($apiUrl, false, $context);
	if (!$apiResp) {
		// API 调用失败，进入待审核（避免漏掉重要信息）
		header('Content-Type: application/json; charset=UTF-8');
		echo json_encode(['ok' => true, 'decision' => 'hold']);
		exit;
	}
	
	$apiData = @json_decode($apiResp, true);
	if (!$apiData || !isset($apiData['conclusionType'])) {
		// 解析失败，进入待审核（避免漏掉重要信息）
		header('Content-Type: application/json; charset=UTF-8');
		echo json_encode(['ok' => true, 'decision' => 'hold']);
		exit;
	}
	
	$conclusionType = (int)$apiData['conclusionType'];
	$reviewAction = isset($opts->baiduReviewAction) ? (string)$opts->baiduReviewAction : 'B';
	
	if ($conclusionType === 1) {
		// 合规
		header('Content-Type: application/json; charset=UTF-8');
		echo json_encode(['ok' => true, 'decision' => 'allow']);
		exit;
	} elseif ($conclusionType === 2) {
		// 疑似/需审核
		if ($reviewAction === 'C') {
			header('Content-Type: application/json; charset=UTF-8');
			echo json_encode(['ok' => false, 'decision' => 'deny', 'reasons' => ['baidu_review_deny']]);
			exit;
		}
		header('Content-Type: application/json; charset=UTF-8');
		echo json_encode(['ok' => true, 'decision' => 'hold']);
		exit;
	} elseif ($conclusionType === 3) {
		// 不合规
		header('Content-Type: application/json; charset=UTF-8');
		echo json_encode(['ok' => false, 'decision' => 'deny', 'reasons' => ['baidu_block']]);
		exit;
	}
	
	// 默认允许
	header('Content-Type: application/json; charset=UTF-8');
	echo json_encode(['ok' => true, 'decision' => 'allow']);
	exit;
	
} catch (Exception $e) {
	// 异常情况降级为允许
	header('Content-Type: application/json; charset=UTF-8');
	echo json_encode(['ok' => true, 'decision' => 'allow', 'error' => 'exception']);
	exit;
}
