<?php
/**
 * Plugin Name: Argon 评论邮件美化
 * Plugin URI:  https://bfzw.top
 * Description:  将 WordPress 评论审核/通知邮件（管理员收到）美化为站点 Argon 主题风格，含 HTML 排版 + 纯文本备选（multipart/alternative），兼容各类邮件客户端。
 * Version:     1.1.0
 * Author:      小崽安
 * Author URI:  https://bfzw.top
 * License:     GPL v3
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 核心类：拦截评论邮件，重写为 Argon 风格 HTML，并附带纯文本备选
 */
class Argon_Comment_Email {

    /** @var string|null 纯文本备选内容（非空即代表当前是评论类邮件） */
    private static $plain_body = null;

    public static function init() {
        // 重写邮件正文（审核 + 通知），并返回 HTML
        add_filter( 'comment_moderation_text',   array( __CLASS__, 'moderation_text' ),   10, 2 );
        add_filter( 'comment_notification_text', array( __CLASS__, 'notification_text' ), 10, 2 );
        // 将邮件内容类型切为 text/html（仅对评论邮件）
        add_filter( 'wp_mail_content_type', array( __CLASS__, 'set_html_content_type' ), 99 );
        // 通过 PHPMailer 注入 AltBody，生成 multipart/alternative 双格式
        add_action( 'phpmailer_init', array( __CLASS__, 'add_plain_alternative' ) );
        // 可选：主题加 emoji 前缀（默认关闭）
        add_filter( 'comment_moderation_subject', array( __CLASS__, 'subject_prefix' ), 10, 2 );
        add_filter( 'comment_notification_subject', array( __CLASS__, 'subject_prefix' ), 10, 2 );
    }

    /**
     * 审核邮件（管理员收到）
     */
    public static function moderation_text( $text, $comment_id ) {
        self::$plain_body = self::render_plain( $comment_id, 'moderation' );
        return self::render( $comment_id, 'moderation' );
    }

    /**
     * 作者通知邮件（文章作者收到，通常也是管理员）
     */
    public static function notification_text( $text, $comment_id ) {
        self::$plain_body = self::render_plain( $comment_id, 'notification' );
        return self::render( $comment_id, 'notification' );
    }

    /**
     * 仅对评论类邮件返回 text/html，其余邮件保持原样
     */
    public static function set_html_content_type( $content_type ) {
        if ( self::$plain_body !== null ) {
            return 'text/html';
        }
        return $content_type;
    }

    /**
     * 注入纯文本备选，PHPMailer 会自动组装为 multipart/alternative
     */
    public static function add_plain_alternative( $phpmailer ) {
        if ( self::$plain_body !== null ) {
            $phpmailer->AltBody = self::$plain_body;
            $phpmailer->IsHTML( true );
            self::$plain_body = null; // 消费，避免影响后续邮件
        }
    }

    /**
     * 可选主题前缀（默认不加，留空）
     */
    public static function subject_prefix( $subject, $comment_id ) {
        $prefix = apply_filters( 'argon_comment_email_subject_prefix', '' );
        if ( $prefix && is_string( $prefix ) ) {
            return $prefix . ' ' . $subject;
        }
        return $subject;
    }

    /**
     * 渲染 Argon 风格 HTML 邮件
     */
    private static function render( $comment_id, $type ) {
        $comment = get_comment( $comment_id );
        if ( ! $comment ) {
            return '';
        }

        $post       = get_post( $comment->comment_post_ID );
        $post_title = $post ? $post->post_title : __( '(已删除文章)', 'default' );
        $post_url   = $post ? get_permalink( $post ) : home_url();
        $site_name  = get_bloginfo( 'name' );
        $site_url   = home_url();

        $author       = $comment->comment_author ?: __( '匿名', 'default' );
        $author_email = $comment->comment_author_email;
        $author_url   = $comment->comment_author_url;
        $comment_ip   = $comment->comment_author_IP;
        $comment_date = date_i18n(
            get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
            strtotime( $comment->comment_date )
        );
        $content = nl2br( esc_html( $comment->comment_content ) );

        // 头像（Gravatar，失败回退 identicon）
        $avatar = get_avatar_url(
            $author_email ? $author_email : $comment->comment_author_IP,
            array( 'size' => 96, 'default' => 'identicon' )
        );

        // 配色（默认取自站点实际 Argon 自定义色 #1e7bc7，可过滤器覆盖）
        $accent  = apply_filters( 'argon_comment_email_accent',  '#1e7bc7' );
        $accent2 = apply_filters( 'argon_comment_email_accent2', '#4aa3e0' );
        $rgb     = self::hex_to_rgb( $accent );
        $shadow  = sprintf( '0 10px 40px rgba(%d,%d,%d,0.15)', $rgb[0], $rgb[1], $rgb[2] );

        $is_reply     = ! empty( $comment->comment_parent );
        $header_title = ( 'moderation' === $type )
            ? ( $is_reply ? '💬 有一条新回复待审核' : '💬 有一条新评论待审核' )
            : ( $is_reply ? '💬 你的文章收到了新回复' : '💬 你的文章收到了新评论' );

        // 元信息行
        $meta_lines = array();
        if ( $author_email ) {
            $meta_lines[] = '📧 ' . esc_html( $author_email );
        }
        if ( $author_url ) {
            $meta_lines[] = '🔗 ' . esc_html( $author_url );
        }
        $meta_lines[] = '🕒 ' . esc_html( $comment_date );
        if ( $comment_ip && 'moderation' === $type ) {
            $meta_lines[] = '🌐 IP：' . esc_html( $comment_ip );
        }
        $meta_html = implode( '<br>', $meta_lines );

        // 操作按钮
        $buttons = '';
        if ( 'moderation' === $type ) {
            $approve = admin_url( 'comment.php?action=approve&c=' . $comment->comment_ID . '#wpbody-content' );
            $spam    = admin_url( 'comment.php?action=spam&c=' . $comment->comment_ID . '#wpbody-content' );
            $trash   = admin_url( 'comment.php?action=trash&c=' . $comment->comment_ID . '#wpbody-content' );
            $view    = get_comment_link( $comment );
            $buttons = self::button( $approve, '批准并发布', $accent, $accent2, true )
                     . self::button( $spam,    '标记为垃圾', $accent, $accent2, false )
                     . self::button( $trash,   '移至回收站', $accent, $accent2, false )
                     . self::button( $view,    '查看原文',   $accent, $accent2, false );
        } else {
            $view   = get_comment_link( $comment );
            $manage = admin_url( 'edit-comments.php' );
            $buttons = self::button( $view, '查看评论', $accent, $accent2, true )
                     . self::button( $manage, '管理评论', $accent, $accent2, false );
        }

        $manage_url = admin_url( 'edit-comments.php' );

        $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background:#eef0f6;-webkit-text-size-adjust:100%;font-family:-apple-system,BlinkMacSystemFont,'PingFang SC','Microsoft YaHei','Segoe UI',sans-serif;">
  <div style="padding:32px 16px;">
    <div style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:{$shadow};">

      <!-- 头部 -->
      <div style="background:linear-gradient(135deg, {$accent} 0%, {$accent2} 100%);padding:26px 32px;color:#ffffff;">
        <div style="font-size:12px;opacity:.85;letter-spacing:1px;text-transform:uppercase;">{$site_name}</div>
        <div style="font-size:19px;font-weight:700;margin-top:6px;line-height:1.4;">{$header_title}</div>
      </div>

      <!-- 内容区 -->
      <div style="padding:26px 32px;">

        <!-- 作者信息 -->
        <div style="display:flex;align-items:center;gap:14px;margin-bottom:18px;">
          <img src="{$avatar}" width="48" height="48" alt="" style="border-radius:50%;border:2px solid #eef0f6;display:block;width:48px;height:48px;">
          <div style="font-size:14px;color:#6b6e82;line-height:1.5;">
            <span style="font-size:16px;font-weight:600;color:#2d2d3a;">{$author}</span><br>
            在《<a href="{$post_url}" style="color:{$accent};text-decoration:none;font-weight:600;">{$post_title}</a>》发表评论
          </div>
        </div>

        <!-- 元信息 -->
        <div style="background:#f7f8fc;border-radius:10px;padding:14px 16px;font-size:13px;color:#6b6e82;line-height:1.9;margin-bottom:18px;">
          {$meta_html}
        </div>

        <!-- 评论正文 -->
        <div style="border-left:4px solid {$accent};background:#f7f8fc;border-radius:0 10px 10px 0;padding:16px 18px;font-size:15px;line-height:1.85;color:#3a3d4f;margin-bottom:24px;word-break:break-word;">
          {$content}
        </div>

        <!-- 操作按钮 -->
        <div style="display:flex;gap:12px;flex-wrap:wrap;">
          {$buttons}
        </div>

      </div>

      <!-- 页脚 -->
      <div style="padding:16px 32px;background:#fafbff;border-top:1px solid #eef0f6;font-size:12px;color:#a0a3b5;text-align:center;line-height:1.6;">
        本邮件由 <strong>Argon 评论邮件美化</strong> 插件自动发送<br>
        <a href="{$manage_url}" style="color:{$accent};text-decoration:none;">前往后台管理评论</a> · <a href="{$site_url}" style="color:{$accent};text-decoration:none;">{$site_name}</a>
      </div>

    </div>
  </div>
</body>
</html>
HTML;

        return $html;
    }

    /**
     * 生成纯文本备选（multipart/alternative 的 text/plain 部分）
     */
    private static function render_plain( $comment_id, $type ) {
        $comment = get_comment( $comment_id );
        if ( ! $comment ) {
            return '';
        }
        $post       = get_post( $comment->comment_post_ID );
        $post_title = $post ? $post->post_title : '(已删除文章)';
        $post_url   = $post ? get_permalink( $post ) : home_url();
        $author     = $comment->comment_author ?: '匿名';
        $email      = $comment->comment_author_email;
        $url        = $comment->comment_author_url;
        $ip         = $comment->comment_author_IP;
        $date       = date_i18n(
            get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
            strtotime( $comment->comment_date )
        );
        $content    = $comment->comment_content;
        $site_name  = get_bloginfo( 'name' );

        if ( 'moderation' === $type ) {
            $title = $comment->comment_parent ? '【新回复待审核】' : '【新评论待审核】';
        } else {
            $title = $comment->comment_parent ? '【文章收到新回复】' : '【文章收到新评论】';
        }

        $out  = $site_name . "\n";
        $out .= $title . "\n";
        $out .= "--------------------------------\n";
        $out .= $author . ' 在《' . $post_title . '》发表评论' . "\n";
        if ( $email ) { $out .= '邮箱：' . $email . "\n"; }
        if ( $url )   { $out .= '网址：' . $url . "\n"; }
        $out .= '时间：' . $date . "\n";
        if ( $ip && 'moderation' === $type ) { $out .= 'IP：' . $ip . "\n"; }
        $out .= "--------------------------------\n";
        $out .= $content . "\n";
        $out .= "--------------------------------\n";
        if ( 'moderation' === $type ) {
            $out .= "操作链接（需登录后台）：\n";
            $out .= '批准并发布：' . admin_url( 'comment.php?action=approve&c=' . $comment->comment_ID ) . "\n";
            $out .= '标记为垃圾：' . admin_url( 'comment.php?action=spam&c=' . $comment->comment_ID ) . "\n";
            $out .= '移至回收站：' . admin_url( 'comment.php?action=trash&c=' . $comment->comment_ID ) . "\n";
            $out .= '查看原文：' . get_comment_link( $comment ) . "\n";
        } else {
            $out .= '查看评论：' . get_comment_link( $comment ) . "\n";
        }
        $out .= '管理评论：' . admin_url( 'edit-comments.php' ) . "\n";
        $out .= '站点：' . $post_url . "\n";
        return $out;
    }

    /**
     * 生成邮件按钮
     */
    private static function button( $url, $label, $accent, $accent2, $primary = false ) {
        if ( $primary ) {
            $style = "display:inline-block;padding:10px 18px;border-radius:8px;font-size:14px;font-weight:600;text-decoration:none;background:linear-gradient(135deg, {$accent} 0%, {$accent2} 100%);color:#ffffff;";
        } else {
            $style = "display:inline-block;padding:10px 18px;border-radius:8px;font-size:14px;font-weight:600;text-decoration:none;background:#ffffff;color:{$accent};border:1px solid {$accent};";
        }
        return '<a href="' . esc_url( $url ) . '" style="' . $style . '">' . esc_html( $label ) . '</a>';
    }

    /**
     * 十六进制颜色转 RGB 数组
     */
    private static function hex_to_rgb( $hex ) {
        $hex = ltrim( $hex, '#' );
        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $int = hexdec( $hex );
        return array( ( $int >> 16 ) & 255, ( $int >> 8 ) & 255, $int & 255 );
    }
}

Argon_Comment_Email::init();
