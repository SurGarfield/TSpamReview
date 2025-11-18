<?php
/**
 * 拦截日志查看页面
 * 
 * @package TSpamReview
 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

// 检查管理员权限
$user = Typecho_Widget::widget('Widget_User');
if (!$user->pass('administrator', true)) {
    throw new Typecho_Widget_Exception(_t('禁止访问'), 403);
}

// AJAX 请求处理
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    
    $action = isset($_GET['action']) ? $_GET['action'] : '';
    $logDir = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
    
    // 获取日志文件列表
    if ($action === 'list') {
        $logFiles = [];
        if (is_dir($logDir)) {
            $files = scandir($logDir);
            foreach ($files as $file) {
                if (preg_match('/^blocked_(\d{4}-\d{2}-\d{2})\.log$/', $file, $matches)) {
                    $filePath = $logDir . DIRECTORY_SEPARATOR . $file;
                    $logFiles[] = [
                        'filename' => $file,
                        'date' => $matches[1],
                        'size' => filesize($filePath),
                        'count' => count(file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
                    ];
                }
            }
            // 按日期倒序排序
            usort($logFiles, function($a, $b) {
                return strcmp($b['date'], $a['date']);
            });
        }
        echo json_encode(['success' => true, 'files' => $logFiles]);
        exit;
    }
    
    // 获取指定日志文件内容（支持分页）
    if ($action === 'view') {
        $file = isset($_GET['file']) ? $_GET['file'] : '';
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $perPage = 20; // 每页显示20条
        
        if ($file && preg_match('/^blocked_\d{4}-\d{2}-\d{2}\.log$/', $file)) {
            $logPath = $logDir . DIRECTORY_SEPARATOR . $file;
            if (file_exists($logPath)) {
                $logContent = [];
                $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    $data = json_decode($line, true);
                    if ($data) {
                        $logContent[] = $data;
                    }
                }
                // 倒序显示（最新的在前）
                $logContent = array_reverse($logContent);
                
                // 分页处理
                $total = count($logContent);
                $totalPages = ceil($total / $perPage);
                $offset = ($page - 1) * $perPage;
                $pagedLogs = array_slice($logContent, $offset, $perPage);
                
                echo json_encode([
                    'success' => true, 
                    'logs' => $pagedLogs, 
                    'file' => $file,
                    'pagination' => [
                        'current' => $page,
                        'total' => $totalPages,
                        'perPage' => $perPage,
                        'totalRecords' => $total
                    ]
                ]);
                exit;
            }
        }
        echo json_encode(['success' => false, 'error' => '日志文件不存在']);
        exit;
    }
    
    // 删除日志文件
    if ($action === 'delete') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => '请求方法错误']);
            exit;
        }
        
        $file = isset($_POST['file']) ? $_POST['file'] : '';
        if ($file && preg_match('/^blocked_\d{4}-\d{2}-\d{2}\.log$/', $file)) {
            $logPath = $logDir . DIRECTORY_SEPARATOR . $file;
            if (file_exists($logPath)) {
                if (unlink($logPath)) {
                    echo json_encode(['success' => true, 'message' => '日志文件已删除']);
                } else {
                    echo json_encode(['success' => false, 'error' => '删除失败，请检查文件权限']);
                }
                exit;
            }
        }
        echo json_encode(['success' => false, 'error' => '日志文件不存在']);
        exit;
    }
    
    // 批量删除日志文件
    if ($action === 'batch_delete') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => '请求方法错误']);
            exit;
        }
        
        $files = isset($_POST['files']) ? json_decode($_POST['files'], true) : [];
        if (!is_array($files) || empty($files)) {
            echo json_encode(['success' => false, 'error' => '未选择要删除的文件']);
            exit;
        }
        
        $deleted = 0;
        $failed = 0;
        foreach ($files as $file) {
            if (preg_match('/^blocked_\d{4}-\d{2}-\d{2}\.log$/', $file)) {
                $logPath = $logDir . DIRECTORY_SEPARATOR . $file;
                if (file_exists($logPath)) {
                    if (unlink($logPath)) {
                        $deleted++;
                    } else {
                        $failed++;
                    }
                }
            }
        }
        
        echo json_encode([
            'success' => true, 
            'deleted' => $deleted, 
            'failed' => $failed,
            'message' => "成功删除 {$deleted} 个文件" . ($failed > 0 ? "，{$failed} 个文件删除失败" : '')
        ]);
        exit;
    }
    
    echo json_encode(['success' => false, 'error' => '无效的请求']);
    exit;
}

// 正常页面渲染
include 'header.php';
include 'menu.php';

?>

<style>
/* 统计信息 - 使用原生 message 样式 */
.log-stats-message {
    padding: 10px 15px;
    margin-bottom: 15px;
    background: #fffbf0;
    border: 1px solid #e9e9e6;
}

.log-stats-message p {
    margin: 0;
    color: #666;
    font-size: 13px;
}

.log-stats-message strong {
    color: #444;
    font-weight: bold;
}

/* 日期筛选面板 */
.log-filter-panel {
    background: #ffd;
    border: 1px solid #e9e9e6;
    padding: 15px 20px;
    margin-bottom: 15px;
    display: none;
}

.log-filter-panel.show {
    display: block;
}

.log-filter-title {
    font-weight: bold;
    color: #444;
    margin-bottom: 12px;
    font-size: 13px;
}

.log-date-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.log-date-btn {
    padding: 6px 12px;
    background: #fff;
    border: 1px solid #d9d9d6;
    color: #444;
    cursor: pointer;
    font-size: 13px;
    transition: all 0.2s;
}

.log-date-btn:hover {
    background: #f6f6f3;
    border-color: #999;
}

.log-date-btn.active {
    background: #467B96;
    border-color: #467B96;
    color: #fff;
}

/* 日志列表容器 */
#logList {
    width: 100%;
    max-width: 100%;
    clear: both;
}

/* 日志表格 */
.log-table-wrap {
    background: #fff;
    border: 1px solid #e9e9e6;
    width: 100%;
    max-width: 100%;
    overflow: visible;
}

.log-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: auto;
}

.log-table thead {
    background: #f6f6f3;
}

.log-table th {
    padding: 10px 15px;
    text-align: left;
    font-weight: bold;
    font-size: 13px;
    color: #666;
    border-bottom: 1px solid #e9e9e6;
}

.log-table td {
    padding: 12px 15px;
    border-bottom: 1px solid #e9e9e6;
    font-size: 13px;
    vertical-align: top;
}

.log-table tbody tr:hover {
    background: #fffbf0;
}

.log-reason-label {
    display: inline-block;
    padding: 3px 8px;
    background: #fff;
    border: 1px solid #d9d9d6;
    color: #444;
    font-size: 12px;
    font-weight: bold;
}

.log-reason-label.type-spam {
    background: #c9302c;
    border-color: #ac2925;
    color: #fff;
}

.log-reason-label.type-sensitive {
    background: #ec971f;
    border-color: #d58512;
    color: #fff;
}

.log-reason-label.type-baidu {
    background: #5bc0de;
    border-color: #46b8da;
    color: #fff;
}

.log-table code {
    background: #f6f6f3;
    padding: 2px 6px;
    border: 1px solid #e9e9e6;
    font-family: Monaco, Menlo, Consolas, "Courier New", monospace;
    font-size: 12px;
    color: #c7254e;
}

.log-comment-text {
    color: #666;
    line-height: 1.6;
    max-width: 520px;
}

/* 空状态 */
.log-empty,
.log-loading,
.log-error {
    padding: 60px 20px;
    text-align: center;
    background: #fff;
    border: 1px solid #e9e9e6;
}

.log-empty-icon,
.log-loading-icon,
.log-error-icon {
    font-size: 48px;
    color: #ccc;
    margin-bottom: 15px;
}

.log-empty-text,
.log-loading-text,
.log-error-text {
    color: #999;
    font-size: 14px;
    margin-bottom: 8px;
}

.log-empty-desc {
    color: #bbb;
    font-size: 13px;
}

.log-error .log-error-icon {
    color: #c9302c;
}

.log-error .log-error-text {
    color: #c9302c;
}

/* 加载动画 */
.log-spinner {
    display: inline-block;
    width: 40px;
    height: 40px;
    border: 3px solid #e9e9e6;
    border-top-color: #467B96;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* 响应式 */
@media (max-width: 768px) {
    .log-date-list {
        flex-direction: column;
    }

    .log-date-btn {
        width: 100%;
        text-align: left;
    }

    .log-table-wrap {
        overflow: hidden;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
    }

    .log-table,
    .log-table thead,
    .log-table tbody,
    .log-table tr,
    .log-table th,
    .log-table td {
        display: block;
        width: 100%;
    }

    .log-table thead {
        display: none;
    }

    .log-table tbody tr {
        border-bottom: 1px solid #f0f0ef;
        padding: 12px 16px;
        background: #fff;
    }

    .log-table tbody tr:hover {
        background: #fff7df;
    }

    .log-table td {
        border: none;
        padding: 8px 0;
        position: relative;
        font-size: 14px;
    }

    .log-table td::before {
        content: attr(data-label);
        display: block;
        font-size: 12px;
        color: #999;
        margin-bottom: 2px;
    }

    .log-comment-text {
        max-width: 100%;
    }

    #pagination {
        text-align: left !important;
    }
}
</style>

<div class="main">
    <div class="body container">
        <div class="colgroup">
            <div class="col-mb-12">
                <div class="typecho-page-title">
                    <h2>评论拦截日志</h2>
                </div>
            </div>
        </div>
        
        <div class="colgroup typecho-page-main">
            <div class="col-mb-12">
                
                <!-- 统计信息 -->
                <div class="log-stats-message">
                    <p>共 <strong id="totalFiles">-</strong> 个日志文件，<strong id="totalRecords">-</strong> 条拦截记录，当前显示 <strong id="currentView">-</strong> 条</p>
                </div>

                <!-- 操作栏 -->
                <div class="typecho-list-operate clearfix">
                    <form method="get">
                        <div class="operate">
                            <label><i class="sr-only">全选</i><input type="checkbox" class="typecho-table-select-all" disabled /></label>
                            <div class="btn-group btn-drop">
                                <button class="btn dropdown-toggle btn-s" type="button" disabled><i class="sr-only">操作</i>操作 <i class="i-caret-down"></i></button>
                            </div>
                        </div>
                        <div class="search" role="search">
                            <button type="button" class="btn btn-s" id="filterToggle">筛选日期</button>
                            <button type="button" class="btn btn-s" id="batchDeleteBtn" style="display:none;color:#c33;">批量删除</button>
                            <button type="button" class="btn btn-s primary" id="refreshBtn">刷新</button>
                        </div>
                    </form>
                </div>

                <!-- 日期筛选面板 -->
                <div class="log-filter-panel" id="filterPanel">
                    <div class="log-filter-title">选择日期:</div>
                    <div class="log-date-list" id="dateList">
                        <span class="log-date-btn active" data-date="">全部日期 (<span id="allCount">0</span>)</span>
                    </div>
                </div>

                <!-- 日志列表 -->
                <div id="logList">
                    <div class="log-loading">
                        <div class="log-spinner"></div>
                        <div class="log-loading-text">正在加载日志数据...</div>
                    </div>
                </div>
                
                <!-- 分页 -->
                <div id="pagination" style="margin-top:15px;display:none;text-align:center;"></div>

            </div>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';
    
    var state = {
        files: [],
        currentFile: null,
        currentPage: 1,
        pagination: null,
        selectedFiles: []
    };
    
    var els = {
        totalFiles: document.getElementById('totalFiles'),
        totalRecords: document.getElementById('totalRecords'),
        currentView: document.getElementById('currentView'),
        filterToggle: document.getElementById('filterToggle'),
        filterPanel: document.getElementById('filterPanel'),
        refreshBtn: document.getElementById('refreshBtn'),
        batchDeleteBtn: document.getElementById('batchDeleteBtn'),
        dateList: document.getElementById('dateList'),
        logList: document.getElementById('logList'),
        pagination: document.getElementById('pagination'),
        allCount: document.getElementById('allCount')
    };
    
    var reasonMap = {
        'spam': { label: '广告内容', type: 'spam' },
        'sensitive': { label: '敏感词汇', type: 'sensitive' },
        'content_no_cn': { label: '内容缺少中文', type: 'spam' },
        'author_no_cn': { label: '昵称缺少中文', type: 'spam' },
        'author_too_long': { label: '昵称过长', type: 'spam' },
        'garbled_content': { label: '乱码内容', type: 'spam' },
        'garbled_author': { label: '乱码内容', type: 'spam' },
        'invalid_email': { label: '邮箱格式错误', type: 'spam' },
        'foreign_language': { label: '外语内容', type: 'spam' },
        'baidu_block': { label: '百度AI拦截', type: 'baidu' },
        'baidu_review': { label: '待人工审核', type: 'baidu' },
        'baidu_review_deny': { label: '审核不通过', type: 'sensitive' },
        'baidu_error': { label: '审核异常', type: 'sensitive' }
    };
    
    function ajax(url, method, data, callback) {
        var xhr = new XMLHttpRequest();
        xhr.open(method || 'GET', url, true);
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    callback(null, JSON.parse(xhr.responseText));
                } catch (e) {
                    callback('解析失败');
                }
            } else {
                callback('请求失败');
            }
        };
        xhr.onerror = function() {
            callback('网络错误');
        };
        if (method === 'POST' && data) {
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.send(data);
        } else {
            xhr.send();
        }
    }
    
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }
    
    function formatReason(reason) {
        return reasonMap[reason] || { label: reason, type: 'spam' };
    }
    
    function loadData() {
        showLoading();
        els.pagination.style.display = 'none';
        
        ajax('?panel=TSpamReview/logs.php&ajax=1&action=list', 'GET', null, function(err, res) {
            if (err || !res.success) {
                showError(err || '加载失败');
                return;
            }
            
            state.files = res.files;
            state.selectedFiles = [];
            updateBatchDeleteBtn();
            updateStats();
            renderDateFilter();
            
            if (state.files.length === 0) {
                showEmpty();
            } else {
                // 如果当前没有选中文件或选中的文件已被删除，自动加载最新的日志
                if (!state.currentFile || !state.files.some(function(f) { return f.filename === state.currentFile; })) {
                    state.currentFile = state.files[0].filename; // 第一个就是最新的（已按日期倒序）
                    state.currentPage = 1;
                }
                loadLogFile(state.currentFile, 1);
            }
        });
    }
    
    function updateStats() {
        var totalRecords = state.files.reduce(function(sum, f) { return sum + f.count; }, 0);
        els.totalFiles.textContent = state.files.length;
        els.totalRecords.textContent = totalRecords;
        els.allCount.textContent = totalRecords;
        updateCurrentView();
    }
    
    function updateCurrentView() {
        if (state.currentFile) {
            var file = state.files.filter(function(f) { return f.filename === state.currentFile; })[0];
            els.currentView.textContent = file ? file.count : 0;
        } else {
            els.currentView.textContent = 0;
        }
    }
    
    function updateBatchDeleteBtn() {
        if (state.selectedFiles.length > 0) {
            els.batchDeleteBtn.style.display = 'inline-block';
            els.batchDeleteBtn.textContent = '批量删除 (' + state.selectedFiles.length + ')';
        } else {
            els.batchDeleteBtn.style.display = 'none';
        }
    }
    
    function toggleFileSelection(filename, checkbox) {
        var index = state.selectedFiles.indexOf(filename);
        if (checkbox.checked && index === -1) {
            state.selectedFiles.push(filename);
        } else if (!checkbox.checked && index > -1) {
            state.selectedFiles.splice(index, 1);
        }
        updateBatchDeleteBtn();
    }
    
    function renderDateFilter() {
        var html = '';
        
        state.files.forEach(function(file) {
            var isActive = state.currentFile === file.filename;
            var isChecked = state.selectedFiles.indexOf(file.filename) > -1;
            html += '<div style="display:inline-flex;align-items:center;margin-right:8px;margin-bottom:8px;">';
            html += '<input type="checkbox" id="chk_' + file.filename + '" ' + (isChecked ? 'checked' : '') + ' style="margin:0 5px 0 0;">';
            html += '<span class="log-date-btn' + (isActive ? ' active' : '') + '" data-date="' + file.filename + '" style="margin:0;">' +
                    file.date + ' (' + file.count + ')' +
                    '</span>';
            html += '</div>';
        });
        
        els.dateList.innerHTML = html;
        
        // 绑定点击事件
        state.files.forEach(function(file) {
            var checkbox = document.getElementById('chk_' + file.filename);
            if (checkbox) {
                checkbox.addEventListener('change', function() {
                    toggleFileSelection(file.filename, this);
                });
            }
        });
        
        els.dateList.querySelectorAll('.log-date-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var filename = this.getAttribute('data-date');
                state.currentFile = filename;
                state.currentPage = 1;
                
                els.dateList.querySelectorAll('.log-date-btn').forEach(function(el) {
                    el.classList.remove('active');
                });
                this.classList.add('active');
                
                if (filename) {
                    loadLogFile(filename, 1);
                }
            });
        });
    }
    
    function loadLogFile(filename, page) {
        showLoading();
        state.currentPage = page || 1;
        
        ajax('?panel=TSpamReview/logs.php&ajax=1&action=view&file=' + encodeURIComponent(filename) + '&page=' + state.currentPage, 
            'GET', null,
            function(err, res) {
                if (err || !res.success) {
                    showError(err || '加载失败');
                    return;
                }
                
                state.pagination = res.pagination;
                renderLogs(res.logs);
                renderPagination();
                updateCurrentView();
            }
        );
    }
    
    function renderLogs(logs) {
        if (logs.length === 0) {
            showEmpty();
            return;
        }
        
        var html = '<div class="log-table-wrap"><table class="log-table">' +
                   '<thead><tr>' +
                   '<th width="12%">拦截原因</th>' +
                   '<th width="15%">时间</th>' +
                   '<th width="10%">昵称</th>' +
                   '<th width="15%">邮箱</th>' +
                   '<th width="10%">IP地址</th>' +
                   '<th>评论内容</th>' +
                   '</tr></thead><tbody>';
        
        logs.forEach(function(log) {
            var reason = formatReason(log.reason);
            
            html += '<tr>' +
                    '<td data-label="拦截原因"><span class="log-reason-label type-' + reason.type + '">' + escapeHtml(reason.label) + '</span></td>' +
                    '<td data-label="时间">' + escapeHtml(log.time) + '</td>' +
                    '<td data-label="昵称">' + escapeHtml(log.author) + '</td>' +
                    '<td data-label="邮箱">' + escapeHtml(log.mail) + '</td>' +
                    '<td data-label="IP地址"><code>' + escapeHtml(log.ip) + '</code></td>' +
                    '<td data-label="评论内容"><div class="log-comment-text">' + escapeHtml(log.text).replace(/\n/g, '<br>') + '</div></td>' +
                    '</tr>';
        });
        
        html += '</tbody></table></div>';
        els.logList.innerHTML = html;
    }
    
    function showLoading() {
        els.logList.innerHTML = 
            '<div class="log-loading">' +
            '<div class="log-spinner"></div>' +
            '<div class="log-loading-text">正在加载数据...</div>' +
            '</div>';
    }
    
    function showEmpty() {
        var html = '<div class="log-table-wrap"><table class="log-table">' +
                   '<thead><tr>' +
                   '<th width="12%">拦截原因</th>' +
                   '<th width="15%">时间</th>' +
                   '<th width="10%">昵称</th>' +
                   '<th width="15%">邮箱</th>' +
                   '<th width="10%">IP地址</th>' +
                   '<th>评论内容</th>' +
                   '</tr></thead><tbody>' +
                   '<tr><td colspan="6" style="text-align:center;padding:40px 20px;color:#999;">' +
                   '<div>📋</div>' +
                   '<div style="margin-top:10px;">暂无拦截记录</div>' +
                   '<div style="font-size:12px;margin-top:5px;">当插件拦截评论时，记录会自动显示在这里</div>' +
                   '</td></tr>' +
                   '</tbody></table></div>';
        els.logList.innerHTML = html;
        els.pagination.style.display = 'none';
    }
    
    function showError(msg) {
        els.logList.innerHTML = 
            '<div class="log-error">' +
            '<div class="log-error-icon">&#10060;</div>' +
            '<div class="log-error-text">加载失败</div>' +
            '<div class="log-empty-desc">' + escapeHtml(msg) + '</div>' +
            '</div>';
        els.pagination.style.display = 'none';
    }
    
    function renderPagination() {
        if (!state.pagination || state.pagination.total <= 1) {
            els.pagination.style.display = 'none';
            return;
        }
        
        var p = state.pagination;
        var html = '<div style="display:inline-block;padding:10px;background:#fff;border:1px solid #e9e9e6;">';
        html += '<span style="margin-right:15px;">第 ' + p.current + ' / ' + p.total + ' 页，共 ' + p.totalRecords + ' 条记录</span>';
        
        // 上一页
        if (p.current > 1) {
            html += '<button class="btn btn-s" onclick="return false;" data-page="' + (p.current - 1) + '">上一页</button> ';
        }
        
        // 页码
        var start = Math.max(1, p.current - 2);
        var end = Math.min(p.total, p.current + 2);
        
        for (var i = start; i <= end; i++) {
            if (i === p.current) {
                html += '<button class="btn btn-s primary" disabled>' + i + '</button> ';
            } else {
                html += '<button class="btn btn-s" onclick="return false;" data-page="' + i + '">' + i + '</button> ';
            }
        }
        
        // 下一页
        if (p.current < p.total) {
            html += '<button class="btn btn-s" onclick="return false;" data-page="' + (p.current + 1) + '">下一页</button>';
        }
        
        html += '</div>';
        els.pagination.innerHTML = html;
        els.pagination.style.display = 'block';
        
        // 绑定页码点击事件
        els.pagination.querySelectorAll('button[data-page]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var page = parseInt(this.getAttribute('data-page'));
                if (state.currentFile) {
                    loadLogFile(state.currentFile, page);
                }
            });
        });
    }
    
    function deleteFile(filename) {
        if (!confirm('确定要删除 ' + filename + ' 吗？')) {
            return;
        }
        
        var data = 'file=' + encodeURIComponent(filename);
        ajax('?panel=TSpamReview/logs.php&ajax=1&action=delete', 'POST', data, function(err, res) {
            if (err || !res.success) {
                alert('删除失败：' + (res.error || err));
                return;
            }
            alert('删除成功');
            loadData();
        });
    }
    
    function batchDelete() {
        if (state.selectedFiles.length === 0) {
            alert('请先选择要删除的文件');
            return;
        }
        
        if (!confirm('确定要删除选中的 ' + state.selectedFiles.length + ' 个日志文件吗？')) {
            return;
        }
        
        var data = 'files=' + encodeURIComponent(JSON.stringify(state.selectedFiles));
        ajax('?panel=TSpamReview/logs.php&ajax=1&action=batch_delete', 'POST', data, function(err, res) {
            if (err || !res.success) {
                alert('删除失败：' + (res.error || err));
                return;
            }
            alert(res.message);
            loadData();
        });
    }
    
    els.filterToggle.addEventListener('click', function() {
        els.filterPanel.classList.toggle('show');
    });
    
    els.refreshBtn.addEventListener('click', function() {
        loadData();
    });
    
    els.batchDeleteBtn.addEventListener('click', function() {
        batchDelete();
    });
    
    loadData();
    
})();
</script>

<?php
include 'copyright.php';
include 'footer.php';
?>
