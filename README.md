# TSpamReview - Typecho 评论审核插件

typecho评论审核插件，支持敏感词过滤、中文检测、百度文本审核

### 1. **敏感词检测**
- 检测范围：评论内容、昵称、邮箱
- 配置方式：后台文本框，每行一个词汇
- 检测方式：不区分大小写的子字符串匹配

### 2. **中文字符检测**
- 检测范围：评论内容、昵称
- 可配置操作：
  - A：无操作（允许）
  - B：待审核
  - C：评论失败（直接拒绝）

### 3. **百度文本审核**（可选）
- 支持自动 Token 缓存（25小时）
- 结果处理：
  - `conclusionType=1`（合规）→ 允许
  - `conclusionType=2`（疑似）→ 根据配置待审核/拒绝
  - `conclusionType=3`（不合规）→ 拒绝
- 网络异常降级：统一进入待审核（避免误杀）

在百度Ai控制台的 [产品服务 / 内容审核 - 应用列表](https://console.bce.baidu.com/ai/?fromai=1#/ai/antiporn/app/list) 创建应用 后获取 AppID、API Key、Secret Key
