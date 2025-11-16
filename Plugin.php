<?php
/**
 * 智能评论审核插件 - 敏感词检测、广告拦截、外语拦截、中文检测、百度内容审核、管理员豁免、一键拉黑、拦截日志
 *
 * @package TSpamReview
 * @author 森木志
 * @version 1.3.1
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
	
	/** @var string */
	private static $logDir = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
	
	/** @var string */
	private static $logFile = null;
	
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

		// 创建日志目录
		$logDir = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
		if (!is_dir($logDir)) {
			if (!@mkdir($logDir, 0755, true)) {
				throw new Typecho_Plugin_Exception(_t('无法创建日志目录，请检查权限'));
			}
		}
		
		// 创建 .gitignore 文件
		$gitignorePath = $logDir . DIRECTORY_SEPARATOR . '.gitignore';
		if (!file_exists($gitignorePath)) {
			@file_put_contents($gitignorePath, "*.log\n*.txt\n");
		}

		// 写入测试日志，验证日志功能正常
		$testLogFile = $logDir . DIRECTORY_SEPARATOR . 'blocked_' . date('Y-m-d') . '.log';
		$testLog = json_encode([
			'time' => date('Y-m-d H:i:s'),
			'author' => '测试日志',
			'mail' => 'test@example.com',
			'ip' => '127.0.0.1',
			'text' => '插件激活测试 - 如果看到此日志说明日志功能正常',
			'reason' => '插件激活测试'
		], JSON_UNESCAPED_UNICODE) . "\n";
		@file_put_contents($testLogFile, $testLog, FILE_APPEND | LOCK_EX);

	// 注册钩子
	Typecho_Plugin::factory('Widget_Feedback')->comment = [__CLASS__, 'onBeforeComment'];
	Typecho_Plugin::factory('Widget_Feedback')->finishComment = [__CLASS__, 'onFinishComment'];
	// 仅在页脚注入脚本，避免在 <body> 顶部插入节点破坏 margin collapsing
	Typecho_Plugin::factory('Widget_Archive')->footer = [__CLASS__, 'footer'];
	Typecho_Plugin::factory('admin/footer.php')->end = [__CLASS__, 'adminFooter'];

		Helper::addAction('TSpamReview', 'TSpamReview_Action');
		Helper::addAction('TSpamReviewBlacklist', 'TSpamReview_BlacklistAction');

		// 注册扩展页面（日志查看）
		Helper::addPanel(1, 'TSpamReview/logs.php', _t('TSpamReview 日志'), _t('查看评论拦截日志'), 'administrator');

		return _t('TSpamReview 插件已成功激活！');
	}
	
	public static function deactivate()
	{
		if (class_exists('Helper')) {
			Helper::removeAction('TSpamReview');
			Helper::removeAction('TSpamReviewBlacklist');
			Helper::removePanel(1, 'TSpamReview/logs.php');
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

		// ==================== 广告/垃圾内容拦截 ====================
		$spamInfo = new Typecho_Widget_Helper_Layout('div', ['class' => 'typecho-option']);
		$spamInfo->html('<h3>广告/垃圾内容拦截</h3><p style="color:#999;">拦截包含电话、微信、URL、重复内容等广告信息</p>');
		$form->addItem($spamInfo);

		$blockSpam = new Typecho_Widget_Helper_Form_Element_Checkbox(
			'blockSpam',
			['enable' => _t('启用广告内容拦截')],
			['enable'],
			_t('广告内容拦截'),
			_t('自动检测并拦截包含电话号码、微信号、URL链接、大量重复内容等广告信息')
		);
		$form->addInput($blockSpam->multiMode());

		$authorMaxLength = new Typecho_Widget_Helper_Form_Element_Text(
			'authorMaxLength',
			null,
			'30',
			_t('昵称最大长度'),
			_t('限制评论昵称的最大字符长度，超过则拒绝（0表示不限制）')
		);
		$form->addInput($authorMaxLength);

		$blockGarbledAuthor = new Typecho_Widget_Helper_Form_Element_Checkbox(
			'blockGarbledAuthor',
			['enable' => _t('拦截乱码内容')],
			['enable'],
			_t('乱码拦截'),
			_t('检测昵称、邮箱、网址、评论内容中是否包含大量特殊符号、emoji或不可读字符')
		);
		$form->addInput($blockGarbledAuthor->multiMode());

		$strictEmailCheck = new Typecho_Widget_Helper_Form_Element_Checkbox(
			'strictEmailCheck',
			['enable' => _t('启用严格邮箱格式检查')],
			['enable'],
			_t('邮箱格式验证'),
			_t('拦截格式不正确或临时邮箱（如包含test、temp、123等可疑邮箱）')
		);
		$form->addInput($strictEmailCheck->multiMode());

		$ipBlacklist = new Typecho_Widget_Helper_Form_Element_Textarea(
			'ipBlacklist',
			null,
			'',
			_t('IP地址黑名单'),
			_t('每行一个IP地址；黑名单中的IP将无法发表评论。可在评论管理页面使用"拉黑"功能快速添加。')
		);
		$ipBlacklist->setAttribute('rows', 6);
		$form->addInput($ipBlacklist);

		$emailBlacklist = new Typecho_Widget_Helper_Form_Element_Textarea(
			'emailBlacklist',
			null,
			'',
			_t('邮箱黑名单'),
			_t('每行一个邮箱地址；黑名单中的邮箱将无法发表评论。可在评论管理页面使用"拉黑"功能快速添加。')
		);
		$emailBlacklist->setAttribute('rows', 6);
		$form->addInput($emailBlacklist);

		// 拉黑后的操作
		$blacklistAction = new Typecho_Widget_Helper_Form_Element_Radio(
			'blacklistDeleteComment',
			[
				'0' => _t('保留评论（仅添加到黑名单）'),
				'1' => _t('删除评论（拉黑的同时删除该评论）')
			],
			'0',
			_t('拉黑后是否删除评论'),
			_t('选择拉黑操作后是否同时删除该条评论。注意：删除操作不可恢复！')
		);
		$form->addInput($blacklistAction);

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

		$blockForeignLanguage = new Typecho_Widget_Helper_Form_Element_Checkbox(
			'blockForeignLanguage',
			['enable' => _t('拦截纯外语评论（俄文、韩文、日文等）')],
			['enable'],
			_t('外语拦截'),
			_t('自动检测并拦截纯俄文、韩文、日文等外语评论（不影响包含中文或英文的评论）')
		);
		$form->addInput($blockForeignLanguage->multiMode());

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

		// 拦截日志
		$blockLog = new Typecho_Widget_Helper_Form_Element_Checkbox(
			'blockLog',
			['enable' => _t('记录被拦截的评论（保存到日志文件）')],
			['enable'],
			_t('拦截日志'),
			_t('记录被拦截评论的时间、昵称、邮箱、内容、IP地址和拦截原因')
		);
		$form->addInput($blockLog->multiMode());

		// 调试选项（默认关闭）
		$debugLog = new Typecho_Widget_Helper_Form_Element_Checkbox(
			'debugLog',
			['enable' => _t('启用调试日志（写入 error_log ）')],
			[],
			_t('调试')
		);
		$form->addInput($debugLog->multiMode());

		// 日志查看入口和测试
		$logInfo = new Typecho_Widget_Helper_Layout('div', ['class' => 'typecho-option']);
		$logViewUrl = Helper::options()->adminUrl . 'extending.php?panel=TSpamReview/logs.php';
		$logDir = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
		$logDirWritable = is_dir($logDir) && is_writable($logDir);
		$logStatusIcon = $logDirWritable ? '✅' : '❌';
		$logStatusText = $logDirWritable ? '可写' : '不可写';
		$logStatusColor = $logDirWritable ? '#27ae60' : '#e74c3c';
		
		$logInfo->html('
			<p style="color:#467B96;">
				📝 <a href="' . $logViewUrl . '" target="_blank">查看拦截日志</a> | 
				日志目录：<code>usr/plugins/TSpamReview/logs/</code>
				<span style="color:' . $logStatusColor . ';margin-left:10px;">' . $logStatusIcon . ' ' . $logStatusText . '</span>
			</p>
			<p style="color:#999;font-size:12px;margin-top:5px;">
				💡 提示：启用"拦截日志"后，所有被拦截的评论都会记录到日志文件中。
				如果日志未记录，请检查 <code>logs/</code> 目录的写入权限。
			</p>
		');
		$form->addItem($logInfo);
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
		// 不再在 header 中输出任何内容，避免影响首屏布局与 margin 折叠
		return; 
	}

	public static function footer()
	{
		self::emitFrontScript();
	}

	/**
	 * 后台页脚钩子 - 在评论管理页面注入拉黑功能
	 */
	public static function adminFooter()
	{
		// 只在评论管理页面加载
		$request = Typecho_Request::getInstance();
		if (strpos($request->getRequestUri(), 'manage-comments.php') === false) {
			return;
		}

		// 获取安全URL
		$securityUrl = Helper::security()->getIndex('/action/TSpamReviewBlacklist');

		// 获取插件配置URL（仅管理员可见）
		$pluginConfigUrl = '';
		try {
			$user = Typecho_Widget::widget('Widget_User');
			if ($user->pass('administrator', true)) {
				$pluginConfigUrl = Typecho_Widget::widget('Widget_Options')->adminUrl('options-plugin.php?config=TSpamReview', true);
			}
		} catch (Exception $e) {
			// 忽略错误
		}

		// 输出拉黑功能的样式和脚本
		?>
		<!-- TSpamReview 一键拉黑功能 -->
		<style>
			.tspam-blacklist-row {
				display: inline;
				position: relative;
				margin-left: 8px;
			}
			
			.tspam-blacklist-btn {
				color: #c33 !important;
				cursor: pointer !important;
				transition: color 0.2s;
				display: inline-block;
				user-select: none;
			}
			
			.tspam-blacklist-btn:hover {
				color: #d11 !important;
				text-decoration: underline;
			}
			
			.tspam-blacklist-btn:active {
				color: #a00 !important;
			}
			
			/* 移动端适配 */
			@media (max-width: 575px) {
				.tspam-blacklist-row {
					display: block;
					margin-left: 0;
					margin-top: 4px;
				}
			}
		</style>

		<script type="text/javascript">
		(function($) {
			'use strict';
			
			// 配置
			var securityUrl = '<?php echo $securityUrl; ?>';
			var pluginConfigUrl = '<?php echo $pluginConfigUrl; ?>';
			
			// 如果有配置URL，添加配置按钮
			if (pluginConfigUrl) {
				$('.typecho-list-operate .operate').append(
					'<button class="btn btn-s" onclick="window.location.href=\'' + pluginConfigUrl + '\'" type="button">拉黑管理</button>'
				);
			}
			
			// 为每个评论行添加拉黑按钮
			$('.typecho-list-table tbody tr').each(function() {
				var $row = $(this);
				var commentData = $row.data('comment');
				
				if (!commentData) {
					return;
				}
				
				// 获取评论ID
				var coid = $row.find('input[type=checkbox]').first().val();
				var ip = commentData.ip || '';
				var email = commentData.mail || '';
				var author = commentData.author || '匿名';
				
				// 如果既没有IP也没有邮箱，不显示拉黑按钮
				if (!ip && !email) {
					return;
				}
				
				// 构建拉黑按钮HTML（使用span避免链接被修改）
				var html = '<div class="tspam-blacklist-row">';
				html += '<span class="tspam-blacklist-btn" ';
				html += 'data-coid="' + coid + '" ';
				html += 'data-ip="' + (ip || '') + '" ';
				html += 'data-email="' + (email || '') + '" ';
				html += 'data-author="' + author + '" ';
				html += 'style="color:#c33;cursor:pointer;user-select:none;"';
				html += '>拉黑</span>';
				html += '</div>';
				
				// 插入到操作区域
				$row.find('.comment-action').append(html);
			});
			
			// 绑定拉黑按钮点击事件
			$(document).on('click', '.tspam-blacklist-btn', function(e) {
				e.preventDefault();
				e.stopPropagation();
				
				var $btn = $(this);
				var coid = $btn.data('coid');
				var ip = $btn.data('ip');
				var email = $btn.data('email');
				var author = $btn.data('author');
				
				// 构建确认消息
				var message = '确认拉黑该评论？\n\n';
				message += '评论者：' + author + '\n';
				if (ip) message += 'IP地址：' + ip + '\n';
				if (email) message += '邮箱：' + email + '\n';
				message += '\n拉黑后，该IP和邮箱将无法再发表评论。';
				
				if (!confirm(message)) {
					return false;
				}
				
				// 构建URL参数
				var params = [];
				if (ip) params.push('ip=' + encodeURIComponent(ip));
				if (email) params.push('email=' + encodeURIComponent(email));
				params.push('coid=' + coid);
				
				// 直接跳转到处理页面
				var targetUrl = securityUrl + '&' + params.join('&');
				window.location.href = targetUrl;
				
				return false;
			});
			
		})(jQuery);
		</script>
		<?php
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
		$contentAction = isset($opts->contentChineseAction) ? (string)$opts->contentChineseAction : 'A';
		$authorAction = isset($opts->authorChineseAction) ? (string)$opts->authorChineseAction : 'A';
		
		// 检查管理员豁免配置
		$skipAdmin = isset($opts->skipAdminReview) && is_array($opts->skipAdminReview) && in_array('enable', $opts->skipAdminReview, true);
		$isAdmin = self::isAdmin();

		// 直接输出配置与静态脚本，避免包含文件可能引入的 BOM 输出
		$site = Helper::options()->siteUrl;
		$asset = rtrim($site, '/') . '/usr/plugins/TSpamReview/static/front.js.php';
		$preAuditUrl = rtrim($site, '/') . '/usr/plugins/TSpamReview/endpoint.php';
		$config = json_encode([
			'words' => $words,
			'contentAction' => $contentAction,
			'authorAction' => $authorAction,
			'preAuditUrl' => $preAuditUrl,
			'skipAdmin' => $skipAdmin,
			'isAdmin' => $isAdmin,
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			echo '<script>window.TSpamReviewConfig=' . $config . '</script>';
			echo '<script src="' . htmlspecialchars($asset, ENT_QUOTES, 'UTF-8') . '"></script>';
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

			// 1) IP黑名单检测
			$ipBlacklist = self::parseBlacklist(isset($opts->ipBlacklist) ? $opts->ipBlacklist : '');
			if (!empty($ipBlacklist)) {
				$commentIp = isset($comment['ip']) ? (string)$comment['ip'] : '';
				if ($commentIp !== '' && in_array($commentIp, $ipBlacklist, true)) {
					self::debug('[deny] IP in blacklist: ' . $commentIp);
					self::logBlockedComment($comment, 'IP黑名单');
					throw new Typecho_Widget_Exception(_t('评论失败'));
				}
			}

			// 2) 邮箱黑名单检测
			$emailBlacklist = self::parseBlacklist(isset($opts->emailBlacklist) ? $opts->emailBlacklist : '');
			if (!empty($emailBlacklist)) {
				$commentEmail = isset($comment['mail']) ? (string)$comment['mail'] : '';
				if ($commentEmail !== '' && in_array($commentEmail, $emailBlacklist, true)) {
					self::debug('[deny] Email in blacklist: ' . $commentEmail);
					self::logBlockedComment($comment, '邮箱黑名单');
					throw new Typecho_Widget_Exception(_t('评论失败'));
				}
			}

			// 3) 敏感词检测（内容/昵称/邮箱）
			$sensitiveList = self::parseSensitiveList(isset($opts->sensitiveWords) ? $opts->sensitiveWords : '');
			if (!empty($sensitiveList)) {
				if (self::hasSensitiveWord([
					isset($comment['text']) ? (string)$comment['text'] : '',
					isset($comment['author']) ? (string)$comment['author'] : '',
					isset($comment['mail']) ? (string)$comment['mail'] : '',
				], $sensitiveList)) {
					self::debug('[deny] sensitive word matched');
					self::logBlockedComment($comment, '敏感词汇');
					throw new Typecho_Widget_Exception(_t('评论失败'));
				}
			}

			// 3.1) 广告/垃圾内容检测
			$commentText = isset($comment['text']) ? (string)$comment['text'] : '';
			$commentAuthor = isset($comment['author']) ? (string)$comment['author'] : '';
			$commentMail = isset($comment['mail']) ? (string)$comment['mail'] : '';

		// 统一的广告检测
		$blockSpam = isset($opts->blockSpam) && is_array($opts->blockSpam) && in_array('enable', $opts->blockSpam, true);
		if ($blockSpam) {
			$isSpam = false;
			$spamType = '';
			
			// 调试：记录检测内容
			self::debug('[spam check] text: ' . mb_substr($commentText, 0, 50) . ', author: ' . $commentAuthor);
			
			// 检测电话号码
			if (self::hasPhoneNumber($commentText . ' ' . $commentAuthor)) {
				$isSpam = true;
				$spamType = 'phone';
				self::debug('[spam] phone detected');
			}
			// 检测微信号
			elseif (self::hasWechatId($commentText . ' ' . $commentAuthor)) {
				$isSpam = true;
				$spamType = 'wechat';
				self::debug('[spam] wechat detected');
			}
			// 检测URL
			elseif (self::hasUrl($commentText)) {
				$isSpam = true;
				$spamType = 'url';
				self::debug('[spam] url detected in: ' . $commentText);
			}
			// 检测重复内容
			elseif (self::hasRepetitiveContent($commentText)) {
				$isSpam = true;
				$spamType = 'repeat';
				self::debug('[spam] repetitive detected');
			}
			
			if ($isSpam) {
				self::debug('[deny] spam detected: ' . $spamType);
				self::logBlockedComment($comment, '广告信息(' . $spamType . ')');
				throw new Typecho_Widget_Exception(_t('评论失败，疑似包含广告信息'));
			} else {
				self::debug('[spam check] passed');
			}
		}

			// 昵称长度检测
			$authorMaxLength = isset($opts->authorMaxLength) ? intval($opts->authorMaxLength) : 30;
			if ($authorMaxLength > 0 && mb_strlen($commentAuthor, 'UTF-8') > $authorMaxLength) {
				self::debug('[deny] author name too long: ' . mb_strlen($commentAuthor, 'UTF-8'));
				self::logBlockedComment($comment, '昵称过长');
				throw new Typecho_Widget_Exception(_t('评论失败'));
			}

		// 乱码检测（昵称、邮箱、评论内容）
		$blockGarbledAuthor = isset($opts->blockGarbledAuthor) && is_array($opts->blockGarbledAuthor) && in_array('enable', $opts->blockGarbledAuthor, true);
		if ($blockGarbledAuthor && self::hasGarbledContent($commentAuthor, $commentMail, $commentText)) {
			self::debug('[deny] garbled content detected');
			self::logBlockedComment($comment, '乱码内容');
			throw new Typecho_Widget_Exception(_t('评论失败'));
		}

			// 邮箱格式检测
			$strictEmailCheck = isset($opts->strictEmailCheck) && is_array($opts->strictEmailCheck) && in_array('enable', $opts->strictEmailCheck, true);
			if ($strictEmailCheck && self::isInvalidEmail($commentMail)) {
				self::debug('[deny] invalid email format');
				self::logBlockedComment($comment, '邮箱格式错误');
				throw new Typecho_Widget_Exception(_t('评论失败'));
			}

			// 4) 中文检测：评论内容
			$contentAction = isset($opts->contentChineseAction) ? (string)$opts->contentChineseAction : 'A';
			if (!self::stringHasChinese(isset($comment['text']) ? (string)$comment['text'] : '')) {
				if ($contentAction === 'C') {
					self::debug('[deny] content no Chinese');
					self::logBlockedComment($comment, '内容无中文');
					throw new Typecho_Widget_Exception(_t('评论失败'));
				} elseif ($contentAction === 'B') {
					self::debug('[hold] content no Chinese → set status to waiting');
					$comment['status'] = 'waiting';
				}
			}

			// 5) 中文检测：昵称
			$authorAction = isset($opts->authorChineseAction) ? (string)$opts->authorChineseAction : 'A';
			if (!self::stringHasChinese(isset($comment['author']) ? (string)$comment['author'] : '')) {
				if ($authorAction === 'C') {
					self::debug('[deny] author no Chinese');
					self::logBlockedComment($comment, '昵称无中文');
					throw new Typecho_Widget_Exception(_t('评论失败'));
				} elseif ($authorAction === 'B') {
					self::debug('[hold] author no Chinese → set status to waiting');
					$comment['status'] = 'waiting';
				}
			}

			// 5.1) 外语检测
			$blockForeignLanguage = isset($opts->blockForeignLanguage) && is_array($opts->blockForeignLanguage) && in_array('enable', $opts->blockForeignLanguage, true);
			if ($blockForeignLanguage && self::isPureForeignLanguage($commentText)) {
				self::debug('[deny] pure foreign language detected');
				self::logBlockedComment($comment, '纯外语评论');
				throw new Typecho_Widget_Exception(_t('评论失败'));
			}

			// 6) 可选：百度文本审核
			$baiduEnabled = isset($opts->baiduEnable) && is_array($opts->baiduEnable) && in_array('enable', $opts->baiduEnable, true);
			if ($baiduEnabled) {
				$apiKey = isset($opts->baiduApiKey) ? trim((string)$opts->baiduApiKey) : '';
				$secretKey = isset($opts->baiduSecretKey) ? trim((string)$opts->baiduSecretKey) : '';
				self::debug('[baidu] precheck enabled, hasKey=' . ($apiKey !== '' && $secretKey !== '' ? 'yes' : 'no'));

				if ($apiKey !== '' && $secretKey !== '') {
					$audit = self::baiduTextAudit(isset($comment['text']) ? (string)$comment['text'] : '', $apiKey, $secretKey);
					if ($audit === 'block') {
						self::debug('[deny] baidu returns block');
						self::logBlockedComment($comment, '百度审核:违规');
						throw new Typecho_Widget_Exception(_t('评论失败'));
					} elseif ($audit === 'review') {
						$reviewAction = isset($opts->baiduReviewAction) ? (string)$opts->baiduReviewAction : 'B';
						if ($reviewAction === 'C') {
							self::debug('[deny] baidu returns review → deny by config');
							self::logBlockedComment($comment, '百度审核:疑似');
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

	/**
	 * 检测文本中是否包含电话号码
	 */
	private static function hasPhoneNumber($text)
	{
		if ($text === '') {
			return false;
		}
		// 手机号：1开头的11位数字
		if (preg_match('/1[3-9]\d{9}/', $text)) {
			return true;
		}
		// 固话：区号+号码
		if (preg_match('/0\d{2,3}[-\s]?\d{7,8}/', $text)) {
			return true;
		}
		// 400/800电话
		if (preg_match('/[48]00[-\s]?\d{3}[-\s]?\d{4}/', $text)) {
			return true;
		}
		return false;
	}

	/**
	 * 检测文本中是否包含微信号
	 */
	private static function hasWechatId($text)
	{
		if ($text === '') {
			return false;
		}
		$lower = mb_strtolower($text, 'UTF-8');
		// 检测 wx/weixin/微信 + 数字/字母组合
		if (preg_match('/(wx|weixin|微信)\s*[：:]\s*[a-z0-9_-]{5,}/ui', $lower)) {
			return true;
		}
		if (preg_match('/(微信号|微信|vx|VX)\s*[：:\s]*[a-z0-9_-]{5,}/ui', $text)) {
			return true;
		}
		// 单独的wx_或weixin_开头
		if (preg_match('/\b(wx|weixin)_[a-z0-9_-]{4,}\b/i', $lower)) {
			return true;
		}
		return false;
	}

	/**
	 * 检测文本中是否包含URL
	 */
	private static function hasUrl($text)
	{
		if ($text === '') {
			return false;
		}
		// 检测 http(s)://
		if (preg_match('/(https?:\/\/|ftp:\/\/)/i', $text)) {
			return true;
		}
		// 检测 www.
		if (preg_match('/\bwww\.[a-z0-9][a-z0-9-]*\.[a-z]{2,}/i', $text)) {
			return true;
		}
		// 检测域名模式 (xxx.com, xxx.cn等)
		if (preg_match('/\b[a-z0-9][-a-z0-9]{0,62}\.(com|cn|net|org|info|biz|cc|tv|me|io|co|top|xyz|site|online|tech|store|club|fun|icu|vip|shop|wang|ink|ltd|group|link|pro|kim|red|pet|art|design|wiki|pub|live|news|video|email|chat|zone|world|city|center|life|team|work|space|today|online|uno)\b/i', $text)) {
			return true;
		}
		return false;
	}

	/**
	 * 检测文本是否存在大量重复内容
	 */
	private static function hasRepetitiveContent($text)
	{
		if ($text === '' || mb_strlen($text, 'UTF-8') < 15) {
			return false;
		}
		
		// 1. 检测单个字符重复（6次以上才算异常）
		if (preg_match('/(.)\1{5,}/u', $text)) {
			return true;
		}
		
		// 2. 检测较长短语的过度重复（3-8个字符重复3次以上）
		if (preg_match('/(.{3,8})\1{3,}/u', $text)) {
			return true;
		}
		
		// 3. 检测整句重复（10个字符以上重复2次以上）
		if (preg_match('/(.{10,})\1{2,}/u', $text)) {
			return true;
		}
		
		return false;
	}

	/**
	 * 检测昵称是否为乱码
	 */
	/**
	 * 检测文本中是否包含乱码
	 * 检测昵称、邮箱、网址、评论内容
	 */
	private static function hasGarbledContent($author, $mail, $text)
	{
		// 合并所有需要检测的内容
		$checkContent = trim($author . ' ' . $mail . ' ' . $text);
		
		if ($checkContent === '') {
			return false;
		}
		
		// 检测昵称
		if ($author !== '') {
			$len = mb_strlen($author, 'UTF-8');
			if ($len > 0) {
				// 统计特殊字符数量
				$matches = [];
				$specialCount = preg_match_all('/[^\x{4e00}-\x{9fa5}a-zA-Z0-9\s\-_]/u', $author, $matches);
				// 如果特殊字符占比超过50%，认为是乱码
				if ($specialCount > $len * 0.5) {
					return true;
				}
				// 检测是否包含真正的ASCII控制字符（只检测0x00-0x1F，不检测0x7F-0x9F以避免误判UTF-8）
				if (preg_match('/[\x00-\x08\x0B-\x0C\x0E-\x1F]+/', $author)) {
					return true;
				}
				// 检测是否全是特殊符号
				if (preg_match('/^[^\x{4e00}-\x{9fa5}a-zA-Z0-9]+$/u', $author) && $len > 3) {
					return true;
				}
			}
		}
		
		// 检测邮箱本地部分（@之前的部分）
		if ($mail !== '' && strpos($mail, '@') !== false) {
			$localPart = substr($mail, 0, strpos($mail, '@'));
			$len = mb_strlen($localPart, 'UTF-8');
			if ($len > 0) {
				// 邮箱本地部分包含大量特殊符号（正常邮箱允许 . - _ +）
				$matches = [];
				$specialCount = preg_match_all('/[^a-zA-Z0-9.\-_+]/', $localPart, $matches);
				if ($specialCount > $len * 0.6) {
					return true;
				}
				// 检测真正的ASCII控制字符（不检测0x7F-0x9F避免误判）
				if (preg_match('/[\x00-\x08\x0B-\x0C\x0E-\x1F]/', $localPart)) {
					return true;
				}
			}
		}
		
	// 检测评论内容 - 放宽检测标准，只检测真正的乱码
	if ($text !== '') {
		// 检测是否包含过多真正的ASCII控制字符，避免误判UTF-8
		$matches = [];
		$controlCharCount = preg_match_all('/[\x00-\x08\x0B-\x0C\x0E-\x1F]/', $text, $matches);
		$textLen = mb_strlen($text, 'UTF-8');
		// 提高阈值到50%，避免误判
		if ($textLen > 0 && $controlCharCount > $textLen * 0.5) {
			return true;
		}
		
		// 检测是否包含大量连续的特殊符号
		// 提高到15个字符，避免误判短评论
		if (preg_match('/[^\x{4e00}-\x{9fa5}a-zA-Z0-9\s]{15,}/u', $text)) {
			return true;
		}
	}
		
		return false;
	}
	
	/**
	 * 向后兼容的方法
	 * @deprecated 使用 hasGarbledContent 代替
	 */
	private static function isGarbledAuthor($author)
	{
		return self::hasGarbledContent($author, '', '');
	}

	/**
	 * 严格的邮箱格式检查
	 */
	private static function isInvalidEmail($email)
	{
		if ($email === '') {
			return false;
		}
		// 基本格式检查
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return true;
		}
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
			if (strpos($lower, $keyword) !== false) {
				return true;
			}
		}
		// 检测是否全是数字的用户名（仅针对非受信任域名）
		if (preg_match('/^\d+@/', $email)) {
			return true;
		}
		return false;
	}

	/**
	 * 检测文本是否为纯外语（俄文、韩文、日文等）
	 */
	private static function isPureForeignLanguage($text)
	{
		if ($text === '' || mb_strlen($text, 'UTF-8') < 3) {
			return false;
		}
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
		if ($totalForeignChars > $totalLength * 0.6 && !self::stringHasChinese($text)) {
			return true;
		}
		return false;
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

	/**
	 * 解析黑名单列表（IP或邮箱）
	 * @param string $raw 原始文本
	 * @return array 去重后的黑名单数组
	 */
	private static function parseBlacklist($raw)
	{
		$items = preg_split('/\r?\n/', (string)$raw);
		$clean = [];
		foreach ($items as $item) {
			$value = trim($item);
			if ($value !== '') {
				$clean[$value] = true;
			}
		}
		$list = array_keys($clean);
		self::debug('[blacklist] parsed items count=' . count($list));
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
	 * 记录被拦截的评论
	 */
	private static function logBlockedComment($comment, $reason = 'unknown')
	{
		try {
			$opts = Typecho_Widget::widget('Widget_Options')->plugin('TSpamReview');
			$enabled = isset($opts->blockLog) && is_array($opts->blockLog) && in_array('enable', $opts->blockLog, true);
			if (!$enabled) {
				return false;
			}

			// 确保日志目录存在
			if (!is_dir(self::$logDir)) {
				if (!mkdir(self::$logDir, 0755, true) && !is_dir(self::$logDir)) {
					self::debug('[log] Failed to create log directory');
					return false;
				}
			}

			// 确保日志目录可写
			if (!is_writable(self::$logDir)) {
				self::debug('[log] Log directory is not writable: ' . self::$logDir);
				return false;
			}

			// 日志文件按日期命名
			$logFile = self::$logDir . DIRECTORY_SEPARATOR . 'blocked_' . date('Y-m-d') . '.log';

			// 提取评论信息
			$time = date('Y-m-d H:i:s');
			$author = isset($comment['author']) ? (string)$comment['author'] : '未知';
			$mail = isset($comment['mail']) ? (string)$comment['mail'] : '未知';
			$ip = isset($comment['ip']) ? (string)$comment['ip'] : '未知';
			$text = isset($comment['text']) ? (string)$comment['text'] : '';
			
			// 截断过长的内容
			if (mb_strlen($text, 'UTF-8') > 200) {
				$text = mb_substr($text, 0, 200, 'UTF-8') . '...';
			}

			// 转义换行符
			$text = str_replace(["\r\n", "\r", "\n"], ' ', $text);
			$author = str_replace(["\r\n", "\r", "\n"], ' ', $author);
			$mail = str_replace(["\r\n", "\r", "\n"], ' ', $mail);

			// 构建日志条目（JSON格式，便于解析）
			$logEntry = json_encode([
				'time' => $time,
				'author' => $author,
				'mail' => $mail,
				'ip' => $ip,
				'text' => $text,
				'reason' => $reason
			], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

			// 写入日志（使用文件锁确保并发安全）
			$result = file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
			
			if ($result === false) {
				self::debug('[log] Failed to write to log file: ' . $logFile);
				return false;
			}
			
			self::debug('[log] Blocked comment recorded: ' . $reason . ' | author=' . $author . ' | file=' . $logFile);
			return true;
			
		} catch (Exception $e) {
			self::debug('[log] Exception while writing log: ' . $e->getMessage());
			// 即使日志写入失败，也不应该影响拦截功能
			return false;
		}
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
		$args = func_get_args();
		$widget = null;
		$comment = null;
		$commentParam = null;
		
		if (count($args) === 2) {
			$widget = $args[0];
			$comment = $args[1];
			$commentParam = $comment;
		} elseif (count($args) === 1) {
			$comment = $args[0];
			$commentParam = $comment;
		}
		
		try {
			self::debug('[hook] onFinishComment called (fallback mode)');

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
			self::debug('[hook] skip: not a comment type');
			goto end_hook;
		}
		if ($status !== '' && in_array($status, ['waiting', 'hidden'], true)) {
			self::debug('[hook] skip: comment is waiting or hidden');
			goto end_hook;
		}

		// 检查管理员豁免
		$skipAdmin = isset($opts->skipAdminReview) && is_array($opts->skipAdminReview) && in_array('enable', $opts->skipAdminReview, true);
		if ($skipAdmin && self::isAdmin()) {
			self::debug('[fallback][skip] admin user, bypass all reviews');
			// 跳过所有检测，直接返回
			goto end_hook;
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
	
	end_hook:
	// 返回评论对象
	return $commentParam;
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

/**
 * 加载 BlacklistAction 类
 */
if (!class_exists('TSpamReview_BlacklistAction')) {
    try {
        // 仅在后台或直接访问 Action 时加载，避免前台无谓包含引入潜在输出
        $isAdmin = defined('__TYPECHO_ADMIN__');
        $reqUri = '';
        try {
            $req = Typecho_Request::getInstance();
            $reqUri = $req ? (string)$req->getRequestUri() : '';
        } catch (Exception $e) {}
        if ($isAdmin || strpos($reqUri, 'action/TSpamReviewBlacklist') !== false) {
            require_once __DIR__ . '/BlacklistAction.php';
        }
    } catch (Exception $e) {}
}

