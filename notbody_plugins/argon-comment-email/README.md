# Argon 评论邮件美化

将 WordPress 评论审核 / 通知邮件（管理员收到）美化为 **Argon 主题风格**，复用站点实际自定义主色，并附带纯文本备选，兼容各类邮件客户端。

## 效果

- 渐变头部（站点名 + 标题）
- 评论者头像（Gravatar，失败回退 identicon）
- 结构化元信息（邮箱 / 网址 / 时间 / IP）
- **回复邮件中展示被回复的评论**（父评论引用卡片，头像 + 作者 + 内容）
- 引用式评论正文卡片
- 一键操作按钮：批准并发布 / 标记为垃圾 / 移至回收站 / 查看原文
- 统一页脚：管理评论入口
- **双格式**：HTML + 纯文本备选（multipart/alternative），纯文本客户端也能正常阅读
- **回复评论者通知**：当访客回复某条评论时，自动向该评论作者发送 Argon 风格邮件（含「退订该评论的邮件提醒」链接）

## 配色（取自站点实际 Argon 自定义色）

- 主色 `#1e7bc7`（RGB 30,123,199，蓝色）
- 渐变二阶 `#4aa3e0`
- 卡片阴影随主色自动计算 rgba

> 你站点的 Argon 自定义色是 `#1e7bc7`（非主题默认靛紫 `#5e72e4`）。
> 若以后在后台改了 Argon 主色，用下方过滤器同步即可。

## 支持的邮件

| 邮件类型 | 触发 | 动作按钮 |
|---------|------|---------|
| 评论审核（管理员） | `comment_moderation_text` | 批准 / 垃圾 / 回收站 / 查看 |
| 文章作者通知 | `comment_notification_text` | 查看评论 / 管理评论 |
| 回复评论者通知 | `comment_post` / `transition_comment_status` | 查看全部回复 / 退订提醒 |

仅对评论类邮件设置 HTML + 备选，其它邮件（密码重置、注册等）保持原样。

## 安装

1. 将 `argon-comment-email` 文件夹上传到 `/wp-content/plugins/`
   （路径应为 `wp-content/plugins/argon-comment-email/argon-comment-email.php`）
2. 后台 → 插件 → 启用「Argon 评论邮件美化」
3. 发一条评论触发审核邮件即可看到效果

## 自定义

### 修改主色（与站点 Argon 配色保持一致）

在主题的 `functions.php` 或 Code Snippets 中加入：

```php
// 改成你站点的 Argon 主色（例如改回默认靛紫 #5e72e4，或任意色）
add_filter('argon_comment_email_accent',  function(){ return '#1e7bc7'; });
add_filter('argon_comment_email_accent2', function(){ return '#4aa3e0'; });
```

### 主题加 emoji 前缀

```php
add_filter('argon_comment_email_subject_prefix', function(){ return '🔔'; });
```

## 兼容性说明

- 邮件为 `multipart/alternative`（HTML + 纯文本），兼容 Gmail / QQ 邮箱 / 163 / Apple Mail / Outlook / 纯文本客户端。
- 通过 PHPMailer 的 `AltBody` 实现双格式，无需手动拼接 MIME boundary。
- 按钮链接指向 `wp-admin`，需管理员登录态。

## 版本

- 1.2.0 — 新增回复评论者邮件通知（`comment_post` + `transition_comment_status` 双钩子）；回复邮件底部增加「退订该评论的邮件提醒」链接；管理员/作者邮件在评论为回复时展示被回复的父评论引用卡片。
- 1.1.0 — 主色改为站点实际 Argon 自定义色 `#1e7bc7`；新增纯文本备选（multipart/alternative）。
- 1.0.0 — 初版，基于 Argon 默认主色 `#5e72e4`。
