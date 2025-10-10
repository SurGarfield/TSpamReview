<?php
/**
 * TSpamReview 黑名单操作处理类
 * 
 * @package TSpamReview
 * @author 森木志
 * @license MIT
 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
	exit;
}

/**
 * 黑名单Action类 - 处理拉黑操作
 */
class TSpamReview_BlacklistAction extends Typecho_Widget implements Widget_Interface_Do
{
	/**
	 * Action 入口
	 */
	public function action()
	{
		// 验证用户权限（仅编辑及以上权限）
		$user = Typecho_Widget::widget('Widget_User');
		$user->pass('editor');

		// 验证安全token
		$security = Typecho_Widget::widget('Widget_Security');
		$security->protect();

		// 获取参数
		$ip = trim($this->request->get('ip'));
		$email = trim($this->request->get('email'));
		$coid = (int)$this->request->get('coid');
		
		// 至少需要一个拉黑目标
		if (empty($ip) && empty($email)) {
			$this->widget('Widget_Notice')->set('没有可拉黑的内容', 'error');
			$this->response->goBack();
			return;
		}

		// 获取插件配置
		$config = Typecho_Widget::widget('Widget_Options')->plugin('TSpamReview');
		
		$successCount = 0;
		$messages = [];
		
		// 拉黑IP
		if (!empty($ip)) {
			list($result, $message) = $this->addToBlacklist($config, 'ipBlacklist', $ip, 'IP地址');
			if ($result) {
				$successCount++;
			}
			$messages[] = $message;
		}
		
		// 拉黑邮箱
		if (!empty($email)) {
			list($result, $message) = $this->addToBlacklist($config, 'emailBlacklist', $email, '邮箱地址');
			if ($result) {
				$successCount++;
			}
			$messages[] = $message;
		}

		// 如果拉黑成功，根据配置决定是否删除评论
		if ($successCount > 0 && $coid > 0) {
			$deleteComment = isset($config->blacklistDeleteComment) ? $config->blacklistDeleteComment : '0';
			
			if ($deleteComment === '1') {
				// 删除评论
				$deleted = $this->deleteComment($coid, $config);
				if ($deleted) {
					$messages[] = '评论已删除';
				} else {
					$messages[] = '评论删除失败';
				}
			}
		}

		// 返回结果
		if ($successCount > 0) {
			$this->widget('Widget_Notice')->set('拉黑成功：' . implode('；', $messages), 'success');
		} else {
			$this->widget('Widget_Notice')->set(implode('；', $messages), 'notice');
		}
		
		$this->response->goBack();
	}

	/**
	 * 添加到黑名单
	 * 
	 * @param object $config 插件配置对象
	 * @param string $field 配置字段名（ipBlacklist 或 emailBlacklist）
	 * @param string $value 要添加的值
	 * @param string $label 显示标签
	 * @return array [是否成功, 消息]
	 */
	private function addToBlacklist($config, $field, $value, $label)
	{
		// 获取现有黑名单
		$existingList = isset($config->$field) ? (string)$config->$field : '';
		
		// 解析现有列表
		$items = array_filter(array_map('trim', preg_split('/\r?\n/', $existingList)));
		
		// 检查是否已存在
		if (in_array($value, $items, true)) {
			return [false, $label . ' 已在黑名单中'];
		}

		// 添加新值
		$items[] = $value;
		$newList = implode(PHP_EOL, $items);

		// 更新插件配置
		try {
			$updateData = [$field => $newList];
			Helper::configPlugin('TSpamReview', $updateData);
			
			// 记录日志（如果开启了调试）
			if (isset($config->debugLog) && is_array($config->debugLog) && in_array('enable', $config->debugLog, true)) {
				@error_log('[TSpamReview] Added to ' . $field . ': ' . $value);
			}
			
			return [true, $label . ' 已成功添加到黑名单'];
		} catch (Exception $e) {
			return [false, '添加失败：' . $e->getMessage()];
		}
	}

	/**
	 * 删除评论
	 * 
	 * @param int $coid 评论ID
	 * @param object $config 插件配置对象
	 * @return bool 是否删除成功
	 */
	private function deleteComment($coid, $config)
	{
		try {
			$db = Typecho_Db::get();
			
			// 获取评论信息
			$comment = $db->fetchRow($db->select()->from('table.comments')
				->where('coid = ?', $coid)->limit(1));
			
			if (!$comment) {
				return false;
			}
			
			$cid = $comment['cid'];
			
			// 删除评论
			$deleteQuery = $db->delete('table.comments')->where('coid = ?', $coid);
			$db->query($deleteQuery);
			
			// 更新文章评论数 - 使用安全的方式
			// 先获取当前评论数
			$content = $db->fetchRow($db->select('commentsNum')->from('table.contents')
				->where('cid = ?', $cid)->limit(1));
			
			if ($content && isset($content['commentsNum'])) {
				$newCount = max(0, intval($content['commentsNum']) - 1);
				$db->query($db->update('table.contents')
					->rows(['commentsNum' => $newCount])
					->where('cid = ?', $cid));
			}
			
			// 记录日志（如果开启了调试）
			if (isset($config->debugLog) && is_array($config->debugLog) && in_array('enable', $config->debugLog, true)) {
				@error_log('[TSpamReview] Deleted comment: ' . $coid);
			}
			
			return true;
		} catch (Exception $e) {
			// 记录错误
			if (isset($config->debugLog) && is_array($config->debugLog) && in_array('enable', $config->debugLog, true)) {
				@error_log('[TSpamReview] Delete comment failed: ' . $e->getMessage());
			}
			return false;
		}
	}
}

