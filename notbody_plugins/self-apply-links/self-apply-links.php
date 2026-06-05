<?php
/**
 * Plugin Name: Argon FriendLink Apply
 * Description: 自助申请友链，支持AJAX提交、邮件通知、后台审核。短代码 [argon_friendlink_apply] 和 [argon_friendlink_list]
 * Version: 0.0.1
 * Author: 小崽安
 */

if (!defined('ABSPATH')) exit;

class Argon_FriendLink_Apply {
    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'argon_friendlink_applications';
        register_activation_hook(__FILE__, array($this, 'activate'));
        add_action('init', array($this, 'init'));
        add_shortcode('argon_friendlink_apply', array($this, 'render_apply_form'));
        add_shortcode('argon_friendlink_list', array($this, 'render_friendlink_list'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_submit_friendlink_apply', array($this, 'handle_ajax_submit'));
        add_action('wp_ajax_nopriv_submit_friendlink_apply', array($this, 'handle_ajax_submit'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_post_argon_fl_approve', array($this, 'handle_approve'));
        add_action('admin_post_argon_fl_delete', array($this, 'handle_delete'));
    }

    public function activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id int(11) NOT NULL AUTO_INCREMENT,
            orig_name varchar(200) NOT NULL,
            site_name varchar(200) NOT NULL,
            site_url varchar(500) NOT NULL,
            site_logo varchar(500) DEFAULT NULL,
            description text NOT NULL,
            rss varchar(500) DEFAULT NULL,
            status enum('pending','approved','rejected') DEFAULT 'pending',
            visibility enum('public','private') DEFAULT 'private',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function init() {}

    public function enqueue_scripts() {
        if (has_shortcode(get_post()->post_content, 'argon_friendlink_apply')) {
            wp_add_inline_style('wp-block-library', $this->get_inline_css());
            wp_enqueue_script('jquery');
            wp_add_inline_script('jquery', $this->get_inline_js());
            wp_localize_script('jquery', 'argon_fl_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('argon_fl_submit_nonce'),
            ));
        }
    }

    private function get_inline_css() {
        return '
        .argon-fl-container { max-width:800px; margin:2rem auto; padding:1.5rem; background:#fff; border-radius:1rem; box-shadow:0 10px 25px -5px rgba(0,0,0,0.05); }
        .argon-fl-criteria { background:#e6f7ff; border-left:4px solid #5c6ac4; padding:1rem 1.5rem; border-radius:0.75rem; margin-bottom:2rem; font-size:0.95rem; }
        .form-group { margin-bottom:1.2rem; }
        .form-group label { display:block; font-weight:600; margin-bottom:0.3rem; }
        .form-group input, .form-group textarea { width:100%; padding:0.6rem; border:1px solid #ccc; border-radius:0.375rem; }
        .required-star { color:#3b82f6; }
        .form-actions { display:flex; gap:0.75rem; justify-content:center; margin:1.5rem 0 1rem; }
        .btn-submit { background:linear-gradient(135deg,#5c6ac4,#3b4b9e); color:#fff; border:none; padding:0.6rem 1.8rem; border-radius:2rem; cursor:pointer; }
        .btn-reset { background:#6c757d; color:#fff; border:none; padding:0.6rem 1.8rem; border-radius:2rem; cursor:pointer; }
        .argon-fl-message { padding:0.8rem 1rem; border-radius:0.5rem; margin-bottom:1.5rem; }
        .argon-fl-message.success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
        .argon-fl-message.error { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
        .required-tip { text-align:center; font-size:0.8rem; color:#6c757d; }
        ';
    }

    private function get_inline_js() {
        return '
        jQuery(document).ready(function($) {
            $("#argon-fl-form").on("submit", function(e) {
                e.preventDefault();
                var $form = $(this);
                var $msg = $("#argon-fl-message");
                $msg.hide().removeClass("success error").html("");
                var data = {
                    action: "submit_friendlink_apply",
                    nonce: argon_fl_ajax.nonce,
                    site_name: $("#site_name").val(),
                    site_url: $("#site_url").val(),
                    site_logo: $("#site_logo").val(),
                    description: $("#description").val(),
                    rss: $("#rss").val()
                };
                $.post(argon_fl_ajax.ajax_url, data, function(res) {
                    if (res.success) {
                        $msg.addClass("success").html("✅ " + res.data).show();
                        $("#argon-fl-form")[0].reset();
                    } else {
                        $msg.addClass("error").html("❌ " + res.data).show();
                    }
                }).fail(function() {
                    $msg.addClass("error").html("❌ 网络错误，请稍后再试。").show();
                });
            });
            $(".btn-reset").on("click", function(e) {
                if (!confirm("重置表单所有内容？")) e.preventDefault();
            });
        });
        ';
    }

    public function render_apply_form() {
        ob_start();
        ?>
        <div class="argon-fl-container">
            <div class="argon-fl-criteria">
                <p>✔ 您的网站已稳定运行，且有一定的文章量；</p>
                <p>✔ 网站内容健康、积极向上、维护中国共产党的领导，凡内容污秽不堪、反动反共的都不会通过申请。</p>
                <p>✔ 请在您的网站友情链接中增加本站信息。审核时，若未在您的站点中检测到本站链接，审核不予通过。</p>
                <p>✔ 如果申请后，长时间未通过审核，有可能是博主太忙未看到，可以通过联系告知我，谢谢～</p>
            </div>
            <div id="argon-fl-message" class="argon-fl-message" style="display:none;"></div>
            <form id="argon-fl-form">
                <div class="form-group"><label>网站名称 <span class="required-star">*</span></label><input type="text" id="site_name" placeholder="例如：并非正文" required></div>
                <div class="form-group"><label>网站链接 <span class="required-star">*</span></label><input type="url" id="site_url" placeholder="https://yourdomain.com" required><small>请输入带 http:// 或 https:// 的链接！</small></div>
                <div class="form-group"><label>站点 Logo <span class="required-star">*</span></label><input type="url" id="site_logo" placeholder="https://yourdomain.com/logo.png" required></div>
                <div class="form-group"><label>描述 <span class="required-star">*</span></label><textarea id="description" rows="3" placeholder="请输入简介" required></textarea></div>
                <div class="form-group"><label>RSS 地址 <span class="required-star">*</span></label><input type="url" id="rss" placeholder="https://yourdomain.com/feed.xml" required></div>
                <div class="form-actions"><button type="submit" class="btn-submit">📨 提交申请</button><button type="reset" class="btn-reset">🔄 重提</button></div>
                <div class="required-tip"><span class="required-star">*</span> 提示：带有浅蓝色星号 * 的项，表示必填项～</div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    public function render_friendlink_list() {
        global $wpdb;
        $links = $wpdb->get_results("SELECT * FROM {$this->table_name} WHERE status='approved' AND visibility='public' ORDER BY created_at DESC");
        if (!$links) return '<p>暂无友链。</p>';
        $html = '<ul class="argon-fl-list">';
        foreach ($links as $link) {
            $html .= '<li><a href="' . esc_url($link->site_url) . '" target="_blank">';
            if ($link->site_logo) $html .= '<img src="' . esc_url($link->site_logo) . '" style="width:32px;height:32px;margin-right:8px;">';
            $html .= esc_html($link->orig_name) . '</a> - ' . esc_html($link->description) . '</li>';
        }
        $html .= '</ul>';
        return $html;
    }

    public function handle_ajax_submit() {
        check_ajax_referer('argon_fl_submit_nonce', 'nonce');
        $site_name = sanitize_text_field($_POST['site_name']);
        $site_url  = esc_url_raw($_POST['site_url']);
        $site_logo = esc_url_raw($_POST['site_logo']);
        $description = sanitize_textarea_field($_POST['description']);
        $rss = esc_url_raw($_POST['rss']);

        $errors = [];
        if (!$site_name) $errors[] = '网站名称不能为空';
        if (!$site_url || !filter_var($site_url, FILTER_VALIDATE_URL)) $errors[] = '网站链接无效';
        if (!$site_logo || !filter_var($site_logo, FILTER_VALIDATE_URL)) $errors[] = 'Logo无效';
        if (!$description) $errors[] = '简介不能为空';
        if (!$rss || !filter_var($rss, FILTER_VALIDATE_URL)) $errors[] = 'RSS无效';
        if ($errors) wp_send_json_error(implode('<br>', $errors));

        global $wpdb;
        $wpdb->insert($this->table_name, [
            'orig_name'   => $site_name,
            'site_name'   => '【待审核】——' . $site_name,
            'site_url'    => $site_url,
            'site_logo'   => $site_logo,
            'description' => $description,
            'rss'         => $rss,
            'status'      => 'pending',
            'visibility'  => 'private',
            'created_at'  => current_time('mysql')
        ]);
        $apply_id = $wpdb->insert_id;

        $admin_email = get_option('admin_email');
        $blog_name   = get_bloginfo('name');
        $manage_url  = admin_url('admin.php?page=argon-friendlink-apply');
        $approve_url = admin_url('admin-post.php?action=argon_fl_approve&id=' . $apply_id . '&_wpnonce=' . wp_create_nonce('argon_fl_manage_' . $apply_id));
        $delete_url  = admin_url('admin-post.php?action=argon_fl_delete&id=' . $apply_id . '&_wpnonce=' . wp_create_nonce('argon_fl_manage_' . $apply_id));

        $message = "<html><body><p>「{$blog_name}」 提交了待审核的交换友链申请<br>@{$site_name} 的信息:</p>
        <ul><li><strong>网站名称:</strong> {$site_name}</li><li><strong>链接地址:</strong> <a href='{$site_url}'>{$site_url}</a></li>
        <li><strong>Logo:</strong> <a href='{$site_logo}'>{$site_logo}</a></li><li><strong>简介:</strong> {$description}</li>
        <li><strong>RSS:</strong> <a href='{$rss}'>{$rss}</a></li></ul>
        <p><a href='{$manage_url}'>前往审核</a> | <a href='{$approve_url}'>通过审核</a> | <a href='{$delete_url}'>删除该链接</a></p></body></html>";
        wp_mail($admin_email, "【新友链】-- 「{$site_name}」 提交了友链申请", $message, ['Content-Type: text/html; charset=UTF-8']);

        wp_send_json_success('申请已提交！管理员将尽快审核。');
    }

    public function add_admin_menu() {
        add_menu_page('友链申请管理', '友链申请', 'manage_options', 'argon-friendlink-apply', [$this, 'admin_page'], 'dashicons-links', 30);
    }

    public function admin_page() {
        global $wpdb;
        $apps = $wpdb->get_results("SELECT * FROM {$this->table_name} ORDER BY created_at DESC");
        echo '<div class="wrap"><h1>友链申请管理</h1>';
        if (isset($_GET['msg'])) echo '<div class="notice notice-success"><p>' . esc_html($_GET['msg']) . '</p></div>';
        echo '<table class="wp-list-table widefat striped"><thead><tr><th>ID</th><th>网站名称</th><th>链接</th><th>Logo</th><th>简介</th><th>RSS</th><th>状态</th><th>可见性</th><th>申请时间</th><th>操作</th></tr></thead><tbody>';
        if (!$apps) echo '<tr><td colspan="10">暂无申请记录。</td></tr>';
        foreach ($apps as $app) {
            echo '<tr><td>' . $app->id . '</td><td>' . esc_html($app->site_name) . '<br><small>原名:' . esc_html($app->orig_name) . '</small></td>';
            echo '<td><a href="' . esc_url($app->site_url) . '" target="_blank">链接</a></td>';
            echo '<td>' . ($app->site_logo ? '<img src="' . esc_url($app->site_logo) . '" style="max-width:60px;">' : '') . '</td>';
            echo '<td>' . esc_html(mb_substr($app->description, 0, 50)) . '…</td>';
            echo '<td><a href="' . esc_url($app->rss) . '" target="_blank">RSS</a></td>';
            echo '<td>' . ($app->status == 'pending' ? '待审核' : ($app->status == 'approved' ? '已通过' : '拒绝')) . '</td>';
            echo '<td>' . ($app->visibility == 'private' ? '私密' : '公开') . '</td>';
            echo '<td>' . $app->created_at . '</td>';
            echo '<td>';
            if ($app->status == 'pending') {
                echo '<a href="' . admin_url('admin-post.php?action=argon_fl_approve&id=' . $app->id . '&_wpnonce=' . wp_create_nonce('argon_fl_manage_' . $app->id)) . '" class="button button-primary" onclick="return confirm(\'通过审核？\')">通过</a> ';
            }
            echo '<a href="' . admin_url('admin-post.php?action=argon_fl_delete&id=' . $app->id . '&_wpnonce=' . wp_create_nonce('argon_fl_manage_' . $app->id)) . '" class="button button-link-delete" onclick="return confirm(\'确认删除？\')">删除</a>';
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public function handle_approve() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_GET['_wpnonce'], 'argon_fl_manage_' . intval($_GET['id']))) wp_die('无权操作');
        global $wpdb;
        $id = intval($_GET['id']);
        $orig = $wpdb->get_var($wpdb->prepare("SELECT orig_name FROM {$this->table_name} WHERE id=%d", $id));
        $wpdb->update($this->table_name, ['status' => 'approved', 'visibility' => 'public', 'site_name' => $orig], ['id' => $id]);
        wp_redirect(admin_url('admin.php?page=argon-friendlink-apply&msg=' . urlencode('已通过审核')));
        exit;
    }

    public function handle_delete() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_GET['_wpnonce'], 'argon_fl_manage_' . intval($_GET['id']))) wp_die('无权操作');
        global $wpdb;
        $wpdb->delete($this->table_name, ['id' => intval($_GET['id'])]);
        wp_redirect(admin_url('admin.php?page=argon-friendlink-apply&msg=' . urlencode('已删除申请')));
        exit;
    }
}

new Argon_FriendLink_Apply();