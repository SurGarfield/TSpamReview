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

	// 辅助函数：检测电话号码
	function hasPhoneNumber($text) {
		if ($text === '') return false;
		// 手机号：1开头的11位数字
		if (preg_match('/1[3-9]\d{9}/', $text)) return true;
		// 固话：区号+号码
		if (preg_match('/0\d{2,3}[-\s]?\d{7,8}/', $text)) return true;
		// 400/800电话
		if (preg_match('/[48]00[-\s]?\d{3}[-\s]?\d{4}/', $text)) return true;
		return false;
	}

	// 辅助函数：检测微信号
	function hasWechatId($text) {
		if ($text === '') return false;
		$lower = mb_strtolower($text, 'UTF-8');
		// 检测 wx/weixin/微信 + 数字/字母组合
		if (preg_match('/(wx|weixin|微信)\s*[：:]\s*[a-z0-9_-]{5,}/ui', $lower)) return true;
		if (preg_match('/(微信号|微信|vx|VX)\s*[：:\s]*[a-z0-9_-]{5,}/ui', $text)) return true;
		// 单独的wx_或weixin_开头
		if (preg_match('/\b(wx|weixin)_[a-z0-9_-]{4,}\b/i', $lower)) return true;
		return false;
	}

	// 辅助函数：检测URL
	function hasUrl($text) {
		if ($text === '') return false;
		// 检测 http(s)://
		if (preg_match('/(https?:\/\/|ftp:\/\/)/i', $text)) return true;
		// 检测 www.
		if (preg_match('/\bwww\.[a-z0-9][a-z0-9-]*\.[a-z]{2,}/i', $text)) return true;
		// 检测域名模式
		if (preg_match('/\b[a-z0-9][-a-z0-9]{0,62}\.(com|cn|net|org|info|biz|cc|tv|me|io|co|top|xyz|site|online|tech|store|club|fun|icu|vip|shop|wang|ink|ltd|group|link|pro|kim|red|pet|art|design|wiki|pub|live|news|video|email|chat|zone|world|city|center|life|team|work|space|today|online|uno)\b/i', $text)) return true;
		return false;
	}

	// 辅助函数：检测重复内容
	function hasRepetitiveContent($text) {
		if ($text === '' || mb_strlen($text, 'UTF-8') < 15) return false;
		
		// 1. 检测单个字符重复（6次以上才算异常）
		if (preg_match('/(.)\1{5,}/u', $text)) return true;
		
		// 2. 检测较长短语的过度重复（3-8个字符重复3次以上）
		if (preg_match('/(.{3,8})\1{3,}/u', $text)) return true;
		
		// 3. 检测整句重复（10个字符以上重复2次以上）
		if (preg_match('/(.{10,})\1{2,}/u', $text)) return true;
		
		return false;
	}

	// 辅助函数：检测乱码昵称
	// 检测乱码内容（昵称、邮箱、评论内容）
	function hasGarbledContent($author, $mail, $text) {
		// 检测昵称
		if ($author !== '') {
			$len = mb_strlen($author, 'UTF-8');
			if ($len > 0) {
				$matches = array();
				$specialCount = preg_match_all('/[^\x{4e00}-\x{9fa5}a-zA-Z0-9\s\-_]/u', $author, $matches);
				if ($specialCount > $len * 0.5) return true;
				// 只检测真正的ASCII控制字符，避免误判UTF-8
				if (preg_match('/[\x00-\x08\x0B-\x0C\x0E-\x1F]+/', $author)) return true;
				if (preg_match('/^[^\x{4e00}-\x{9fa5}a-zA-Z0-9]+$/u', $author) && $len > 3) return true;
			}
		}
		
		// 检测邮箱本地部分
		if ($mail !== '' && strpos($mail, '@') !== false) {
			$localPart = substr($mail, 0, strpos($mail, '@'));
			$len = mb_strlen($localPart, 'UTF-8');
			if ($len > 0) {
				$matches = array();
				$specialCount = preg_match_all('/[^a-zA-Z0-9.\-_+]/', $localPart, $matches);
				if ($specialCount > $len * 0.6) return true;
				// 只检测真正的ASCII控制字符，避免误判
				if (preg_match('/[\x00-\x08\x0B-\x0C\x0E-\x1F]/', $localPart)) return true;
			}
		}
		
		// 检测评论内容 - 放宽检测标准
		if ($text !== '') {
			$matches = array();
			// 只检测真正的ASCII控制字符，避免误判UTF-8
			$controlCharCount = preg_match_all('/[\x00-\x08\x0B-\x0C\x0E-\x1F]/', $text, $matches);
			$textLen = mb_strlen($text, 'UTF-8');
			if ($textLen > 0 && $controlCharCount > $textLen * 0.5) return true;
			if (preg_match('/[^\x{4e00}-\x{9fa5}a-zA-Z0-9\s]{15,}/u', $text)) return true;
		}
		
		return false;
	}
	
	// 向后兼容
	function isGarbledAuthor($author) {
		return hasGarbledContent($author, '', '');
	}

	// 辅助函数：严格邮箱检查
	function isInvalidEmail($email) {
		if ($email === '') return false;
		// 基本格式检查
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return true;
		$lower = mb_strtolower($email, 'UTF-8');
		
		// 主流邮箱服务商白名单（这些邮箱允许纯数字用户名）
		$trustedDomains = [
			'qq.com', '163.com', '126.com', 'gmail.com', 'outlook.com', 'hotmail.com', 
			'yahoo.com', 'sina.com', 'sohu.com', '139.com', 'yeah.net', 'foxmail.com'
		];
		$domain = substr(strrchr($email, '@'), 1);
		$isTrusted = false;
		foreach ($trustedDomains as $trusted) {
			if ($domain === $trusted) {
				$isTrusted = true;
				break;
			}
		}
		
		// 如果是受信任的域名，直接通过
		if ($isTrusted) {
			return false;
		}
		
		// 检测临时邮箱关键词
		$suspiciousKeywords = ['test', 'temp', 'fake', 'spam', '123', 'aaa', 'example', 'sample', 'demo', 'xxx'];
		foreach ($suspiciousKeywords as $keyword) {
			if (strpos($lower, $keyword) !== false) return true;
		}
		// 检测是否全是数字的用户名（仅针对非受信任域名）
		if (preg_match('/^\d+@/', $email)) return true;
		return false;
	}

	// 辅助函数：检测纯外语
	function isPureForeignLanguage($text) {
		if ($text === '' || mb_strlen($text, 'UTF-8') < 3) return false;
		// 检测俄文字符（Cyrillic）
		$cyrillicCount = preg_match_all('/[\x{0400}-\x{04FF}]/u', $text);
		// 检测韩文字符（Hangul）
		$hangulCount = preg_match_all('/[\x{AC00}-\x{D7AF}\x{1100}-\x{11FF}]/u', $text);
		// 检测日文假名（Hiragana + Katakana）
		$kanaCount = preg_match_all('/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}]/u', $text);
		// 检测阿拉伯文
		$arabicCount = preg_match_all('/[\x{0600}-\x{06FF}]/u', $text);
		// 检测泰文
		$thaiCount = preg_match_all('/[\x{0E00}-\x{0E7F}]/u', $text);
		
		$totalForeignChars = $cyrillicCount + $hangulCount + $kanaCount + $arabicCount + $thaiCount;
		$totalLength = mb_strlen($text, 'UTF-8');
		
		// 如果外语字符占比超过60%，且没有中文，认为是纯外语
		if ($totalForeignChars > $totalLength * 0.6 && !hasChinese($text)) {
			return true;
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

	// 1.1 广告/垃圾内容检测
	$blockSpam = isset($opts->blockSpam) && is_array($opts->blockSpam) && in_array('enable', $opts->blockSpam, true);
	if ($blockSpam) {
		// 检测电话号码、微信号、URL、重复内容等
		if (hasPhoneNumber($text . ' ' . $author) || 
		    hasWechatId($text . ' ' . $author) || 
		    hasUrl($text) || 
		    hasRepetitiveContent($text)) {
			$reasons[] = 'spam';
		}
	}

	// 昵称长度检测
	$authorMaxLength = isset($opts->authorMaxLength) ? intval($opts->authorMaxLength) : 30;
	if ($authorMaxLength > 0 && mb_strlen($author, 'UTF-8') > $authorMaxLength) {
		$reasons[] = 'author_too_long';
	}

	// 乱码内容检测（昵称、邮箱、评论内容）
	$blockGarbledAuthor = isset($opts->blockGarbledAuthor) && is_array($opts->blockGarbledAuthor) && in_array('enable', $opts->blockGarbledAuthor, true);
	if ($blockGarbledAuthor && hasGarbledContent($author, $mail, $text)) {
		$reasons[] = 'garbled_content';
	}

	// 邮箱格式检测
	$strictEmailCheck = isset($opts->strictEmailCheck) && is_array($opts->strictEmailCheck) && in_array('enable', $opts->strictEmailCheck, true);
	if ($strictEmailCheck && isInvalidEmail($mail)) {
		$reasons[] = 'invalid_email';
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

	// 2.1 外语检测
	$blockForeignLanguage = isset($opts->blockForeignLanguage) && is_array($opts->blockForeignLanguage) && in_array('enable', $opts->blockForeignLanguage, true);
	if ($blockForeignLanguage && isPureForeignLanguage($text)) {
		$reasons[] = 'foreign_language';
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
