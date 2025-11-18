<?php
header('Content-Type: application/javascript; charset=UTF-8');
?>
(function () {
	'use strict';
	try {
		var config = window.TSpamReviewConfig || {};
		var words = Array.isArray(config.words) ? config.words : [];
		var contentAction = config.contentAction || 'A';
		var authorAction = config.authorAction || 'A';
		var preAuditUrl = config.preAuditUrl || '';
		var skipAdmin = !!config.skipAdmin;
		var isAdmin = !!config.isAdmin;

		if (window.__TSpamReviewHooked__) {
			return;
		}
		window.__TSpamReviewHooked__ = true;

		var CN_RE = /[\u4e00-\u9fa5]/;
		var submitting = false;
		var lastDecision = 'allow';
		var lastDecisionMessage = '';

		var reasonTextMap = {
			'spam': '疑似广告或推广信息',
			'sensitive': '包含敏感词',
			'content_no_cn': '评论内容缺少中文',
			'author_no_cn': '昵称缺少中文',
			'content_or_author_no_cn': '评论内容或昵称缺少中文',
			'author_too_long': '昵称长度超出限制',
			'garbled_content': '内容疑似乱码',
			'garbled_author': '昵称疑似乱码',
			'invalid_email': '邮箱格式不合法',
			'foreign_language': '评论内容为纯外语',
			'baidu_block': '百度审核判定违规',
			'baidu_review': '百度审核提示需人工复核',
			'baidu_review_deny': '百度审核未通过',
			'baidu_error': '百度审核异常，请稍后再试'
		};

		function toast(msg) {
			try {
				if (window.SM && SM.UI && typeof SM.UI.toast === 'function') {
					SM.UI.toast(msg);
					return;
				}
				if (window.toastr && typeof toastr.error === 'function') {
					toastr.error(msg);
					return;
				}
				if (window.iziToast && typeof iziToast.error === 'function') {
					iziToast.error({ title: '提示', message: msg });
					return;
				}
				if (window.Swal && Swal.fire) {
					Swal.fire({
						icon: 'info',
						title: '提示',
						text: msg,
						timer: 2200,
						showConfirmButton: false
					});
					return;
				}
			} catch (e) {}
			var box = document.createElement('div');
			box.textContent = msg;
			box.style.cssText = 'position:fixed;top:16px;right:16px;background:rgba(0,0,0,.92);color:#fff;padding:10px 14px;border-radius:8px;z-index:2147483647;font-size:14px;line-height:1.5;box-shadow:0 4px 14px rgba(0,0,0,.3);pointer-events:none;white-space:pre-line;';
			document.body.appendChild(box);
			setTimeout(function () {
				if (box && box.parentNode) {
					box.parentNode.removeChild(box);
				}
			}, 2600);
		}

		function hasCn(str) {
			return CN_RE.test(str || '');
		}

		function hitWord(str) {
			if (!str) {
				return '';
			}
			var lower = String(str).toLowerCase();
			for (var i = 0; i < words.length; i++) {
				var word = String(words[i] || '').trim();
				if (!word) {
					continue;
				}
				if (lower.indexOf(word.toLowerCase()) !== -1) {
					return word;
				}
			}
			return '';
		}

		function translateReasons(reasons, fallback) {
			if (!Array.isArray(reasons) || !reasons.length) {
				return fallback || '评论提交失败';
			}
			var mapped = [];
			for (var i = 0; i < reasons.length; i++) {
				var key = reasons[i];
				mapped.push(reasonTextMap[key] || key);
			}
			return '评论提交失败：' + mapped.join('、');
		}

		function pick(form) {
			var content = form && form.querySelector ? form.querySelector('textarea[name=text],textarea[name=comment]') : null;
			var author = form && form.querySelector ? form.querySelector('input[name=author]') : null;
			var mail = form && form.querySelector ? form.querySelector('input[name=mail],input[name=email]') : null;
			return {
				content: content ? content.value : '',
				author: author ? author.value : '',
				mail: mail ? mail.value : ''
			};
		}

		function validateValues(values) {
			if (skipAdmin && isAdmin) {
				return true;
			}
			var hitSensitive = hitWord(values.content) || hitWord(values.author) || hitWord(values.mail);
			if (hitSensitive) {
				toast('评论提交失败：包含敏感词“' + hitSensitive + '”');
				return false;
			}
			if (!hasCn(values.content) && contentAction === 'C') {
				toast('评论提交失败：评论内容需包含中文字符');
				return false;
			}
			if (!hasCn(values.author) && authorAction === 'C') {
				toast('评论提交失败：昵称需包含中文字符');
				return false;
			}
			return true;
		}

		function doPrecheck(values, callback) {
			lastDecision = 'allow';
			lastDecisionMessage = '';
			if (skipAdmin && isAdmin) {
				callback(true);
				return;
			}
			if (!preAuditUrl) {
				callback(true);
				return;
			}
			var fd = new FormData();
			fd.append('text', values.content || '');
			fd.append('author', values.author || '');
			fd.append('mail', values.mail || '');

			fetch(preAuditUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: fd
			}).then(function (resp) {
				return resp.json();
			}).then(function (res) {
				if (res && res.ok) {
					lastDecision = res.decision || 'allow';
					if (lastDecision === 'hold') {
						lastDecisionMessage = '评论已提交，正在等待管理员审核。';
					} else {
						lastDecisionMessage = '';
					}
					callback(true);
					return;
				}
				var message = '评论提交失败，请稍后再试';
				if (res && res.error) {
					if (res.error === 'method_not_allowed') {
						message = '提交方式有误，请刷新页面后重试';
					} else {
						message = '评论提交失败：' + res.error;
					}
				} else if (res && res.reasons) {
					message = translateReasons(res.reasons);
				}
				toast(message);
				callback(false);
			}).catch(function () {
				toast('网络波动，已跳过预检直接提交');
				callback(true);
			});
		}

		function isSubmitControl(el) {
			if (!el) {
				return false;
			}
			var type = (el.getAttribute && el.getAttribute('type')) || '';
			type = type ? type.toLowerCase() : '';
			if (type === 'submit') {
				return true;
			}
			var name = (el.getAttribute && el.getAttribute('name')) || '';
			if (name === 'submit') {
				return true;
			}
			if (el.matches) {
				if (el.matches('input[type=submit],button[type=submit]')) {
					return true;
				}
				if (el.matches('[data-submit],.submit')) {
					return true;
				}
			}
			return false;
		}

		function isEmojiControl(el) {
			if (!el || !el.matches) {
				return false;
			}
			if (el.matches('.emoji,.emojis,.smilies,.smiley,.OwO,.OwO-logo,.OwO-body,.OwO-emoji,.OwO-item,[data-emoji],[data-smilies],[data-owo]')) {
				return true;
			}
			var parent = el.closest && el.closest('.OwO,[data-emoji],[data-smilies],[data-owo],.smilies,.emoji');
			return !!parent;
		}

		function fixEmojiButtons() {
			try {
				var buttons = document.querySelectorAll('button:not([type])');
				for (var i = 0; i < buttons.length; i++) {
					var btn = buttons[i];
					if (isEmojiControl(btn)) {
						btn.setAttribute('type', 'button');
					}
				}
			} catch (e) {}
		}

		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', fixEmojiButtons, { once: true });
		} else {
			fixEmojiButtons();
		}

		var hasThemeHandler = false;
		setTimeout(function () {
			if (window.handleCommentSubmit || window.SM) {
				hasThemeHandler = true;
			}
		}, 100);

		document.addEventListener('submit', function (evt) {
			var form = evt.target;
			if (!form || form.tagName !== 'FORM') {
				return;
			}
			var textarea = form.querySelector && form.querySelector('textarea[name=text],textarea[name=comment]');
			if (!textarea) {
				return;
			}
			if (form.__precheckPassed) {
				delete form.__precheckPassed;
				return true;
			}
			if (submitting) {
				evt.preventDefault();
				evt.stopPropagation();
				evt.stopImmediatePropagation();
				return false;
			}

			var submitter = evt.submitter || document.activeElement;
			if (submitter && !isSubmitControl(submitter) && isEmojiControl(submitter)) {
				evt.preventDefault();
				evt.stopPropagation();
				return false;
			}

			var payload = pick(form);
			if (!validateValues(payload)) {
				evt.preventDefault();
				evt.stopPropagation();
				return false;
			}

			evt.preventDefault();
			evt.stopPropagation();
			evt.stopImmediatePropagation();
			submitting = true;

			var btn = form.querySelector('button[type=submit],.submit');
			var btnHtml = '';
			if (btn) {
				btnHtml = btn.innerHTML;
				btn.disabled = true;
				btn.classList.add('loading');
				btn.innerHTML = '<i class="loading-icon"></i> 检测中...';
			}

			doPrecheck(payload, function (passed) {
				if (!passed) {
					submitting = false;
					if (btn) {
						btn.disabled = false;
						btn.classList.remove('loading');
						btn.innerHTML = btnHtml;
					}
					return;
				}

				if (hasThemeHandler) {
					form.__precheckPassed = true;
					submitting = false;
					if (btn) {
						btn.disabled = false;
						btn.classList.remove('loading');
						btn.innerHTML = btnHtml;
					}
					btn && btn.click();
					return;
				}

				var actionUrl = form.getAttribute('action') || location.href;
				if (btn) {
					btn.innerHTML = '<i class="loading-icon"></i> 提交中...';
				}

				fetch(actionUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: new FormData(form)
				}).then(function (resp) {
					return resp.text().then(function (html) {
						submitting = false;
						if (btn) {
							btn.disabled = false;
							btn.classList.remove('loading');
							btn.innerHTML = btnHtml;
						}

						var decision = lastDecision;
						var successMessage = decision === 'hold'
							? (lastDecisionMessage || '评论已提交，等待管理员审核。')
							: '评论提交成功';

						if (resp.ok && resp.redirected) {
							toast(successMessage);
							lastDecision = 'allow';
							lastDecisionMessage = '';
							if (decision !== 'hold') {
								setTimeout(function () {
									location.reload();
								}, 1000);
							}
							return;
						}

						if (html.indexOf('<h1>评论失败</h1>') !== -1 || html.indexOf('Typecho\\Widget\\Exception') !== -1) {
							var match = html.match(/Exception:\\s*([^\\n<]+)/);
							var message = '评论提交失败';
							if (match && match[1]) {
								message = match[1].trim();
								var pos = message.indexOf(' in ');
								if (pos !== -1) {
									message = message.substring(0, pos).trim();
								}
							}
							toast(message);
							return;
						}

						toast(successMessage);
						if (decision === 'hold') {
							textarea.value = '';
						} else {
							setTimeout(function () {
								location.reload();
							}, 1000);
						}
						lastDecision = 'allow';
						lastDecisionMessage = '';
					});
				}).catch(function () {
					submitting = false;
					if (btn) {
						btn.disabled = false;
						btn.classList.remove('loading');
						btn.innerHTML = btnHtml;
					}
					toast('网络异常，评论提交未完成，请稍后重试');
				});
			});
		}, true);

		document.addEventListener('click', function (evt) {
			var target = evt.target;
			if (!target) {
				return;
			}
			var submitButton = target.closest && target.closest('button[type=submit], input[type=submit]');
			if (!submitButton) {
				return;
			}
			var form = submitButton.form || (target.closest && target.closest('form'));
			if (!form || !form.querySelector || !form.querySelector('textarea[name=text],textarea[name=comment]')) {
				return;
			}
			var payload = pick(form);
			if (!validateValues(payload)) {
				evt.preventDefault();
				evt.stopImmediatePropagation();
			}
		}, true);
	} catch (err) {
		if (window.console && console.warn) {
			console.warn('[TSpamReview] front precheck error:', err);
		}
	}
})();
