<?php
/**
 * Plugin Name: Argon FriendLink Apply (内置链接管理器版)
 * Description: 自助申请友链，数据写入 WordPress 内置链接管理器（wp_links），支持 AJAX 提交、邮件通知、后台审核。短代码 [argon_friendlink_apply] 和 [argon_friendlink_list]
 * Version: 0.0.7
 * Author: 小崽安
 */

if (!defined('ABSPATH')) exit;

class Argon_FriendLink_Apply {

    /**
     * 待审核链接的默认分类名称（如果不存在则会自动创建）
     */
    const PENDING_CATEGORY = '待审核友链';
    const PENDING_CATEGORY_SLUG = 'wait-approved';

    /**
     * 审核通过后默认移入的分类名称
     */
    const APPROVED_CATEGORY = '网线空间';
    const APPROVED_CATEGORY_SLUG = 'blog-line';

    /**
     * 构造函数
     */
    public function __construct() {
        // 确保链接管理器功能可用（WordPress 3.5+ 默认隐藏链接管理器，需要显式启用）
        add_filter('pre_option_link_manager_enabled', '__return_true');

        // 注册激活钩子：创建必要的链接分类
        register_activation_hook(__FILE__, array($this, 'activate'));

        // 注册短代码
        add_shortcode('argon_friendlink_apply', array($this, 'render_apply_form'));
        add_shortcode('argon_friendlink_list', array($this, 'render_friendlink_list'));

        // 加载前端资源
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        // AJAX 处理申请提交（前台）
        add_action('wp_ajax_submit_friendlink_apply', array($this, 'handle_ajax_submit'));
        add_action('wp_ajax_nopriv_submit_friendlink_apply', array($this, 'handle_ajax_submit'));

        // 后台管理菜单：快捷查看待审核链接
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // 邮件中的快速操作（通过 admin-post 处理，避免权限问题）
        add_action('admin_post_argon_fl_approve', array($this, 'handle_approve'));
        add_action('admin_post_argon_fl_delete', array($this, 'handle_delete'));

        // 在链接列表页添加「待审核」筛选
        add_action('restrict_manage_links', array($this, 'add_link_visibility_filter'));
        add_filter('pre_get_links', array($this, 'filter_links_by_visibility'));

        // 修改链接管理页面的「可见性」字段文案，使审核状态更清晰
        add_action('admin_footer-link-manager.php', array($this, 'modify_visibility_text'));
    }

    /**
     * 激活插件：创建「待审核友链」和「网线空间」分类
     */
    public function activate() {
        $this->ensure_pending_category();
        $this->ensure_approved_category();
    }

    /**
     * 确保「待审核友链」分类存在，返回其 term_id（整数）
     * 修复：强制返回整数类型，避免返回数组或 WP_Error
     *
     * @__return int|WP_Error
     */
    private function ensure_pending_category() {
        $term_name = self::PENDING_CATEGORY;
        $taxonomy = 'link_category';
        $slug = self::PENDING_CATEGORY_SLUG;

        // 1. 先按名称查找
        $term_existing = term_exists($term_name, $taxonomy);
        if ($term_existing !== 0 && $term_existing !== null) {
            $term_id = is_array($term_existing) ? $term_existing['term_id'] : $term_existing;
            if (is_numeric($term_id) && $term_id > 0) return (int) $term_id;
        }
        
        // 2. 按 slug 查找（防止名称冲突）
        $by_slug = get_term_by('slug', $slug, $taxonomy);
        if ($by_slug instanceof WP_Term) return (int) $by_slug->term_id;

        // 3. 不存在则创建
        $term = wp_insert_term($term_name, $taxonomy, array(
            'description' => '用于存放待审核的友链申请',
            'slug'        => sanitize_title($slug)
        ));

        if (is_wp_error($term)) {
            // 如果创建失败（例如 slug 冲突），再次尝试通过 slug 获取
            $fallback = get_term_by('slug', $slug, $taxonomy);
            if ($fallback instanceof WP_Term) return (int) $fallback->term_id;
            return $term; // 返回错误对象，上层会处理
        }

        return (int) $term['term_id'];
    }

    /**
     * 确保「网线空间」分类存在，返回其 term_id（整数）
     */
    private function ensure_approved_category() {
        $term_name = self::APPROVED_CATEGORY;
        $taxonomy = 'link_category';
        $slug = self::APPROVED_CATEGORY_SLUG;

        $term_existing = term_exists($term_name, $taxonomy);
        if ($term_existing !== 0 && $term_existing !== null) {
            $term_id = is_array($term_existing) ? $term_existing['term_id'] : $term_existing;
            if (is_numeric($term_id) && $term_id > 0) return (int) $term_id;
        }

        $by_slug = get_term_by('slug', $slug, $taxonomy);
        if ($by_slug instanceof WP_Term) return (int) $by_slug->term_id;

        $term = wp_insert_term($term_name, $taxonomy, array(
            'description' => '审核通过的友链默认分类',
            'slug'        => sanitize_title($slug)
        ));

        if (is_wp_error($term)) {
            $fallback = get_term_by('slug', $slug, $taxonomy);
            if ($fallback instanceof WP_Term) return (int) $fallback->term_id;
            return $term;
        }

        return (int) $term['term_id'];
    }

    /**
     * 加载前端资源（内联 CSS + JS）
     */
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

    /**
     * 前端 CSS 样式（保持原有风格）
     */
    private function get_inline_css() {
        return '
        .argon-fl-container { max-width:800px; margin:2rem auto; padding:1.5rem; background:#fff; border-radius:1rem; box-shadow:0 10px 25px -5px rgba(0,0,0,0.05); }
        .argon-fl-criteria { background:#e6f7ff; border-left:4px solid #5c6ac4; padding:1rem 1.5rem; border-radius:0.75rem; margin-bottom:2rem; font-size:0.95rem; }
        .form-group { margin-bottom:1.2rem; }
        .form-group label { display:block; font-weight:600; margin-bottom:0.3rem; }
        .form-group input, .form-group textarea { width:100%; padding:0.6rem; border:1px solid #ccc; border-radius:0.375rem; }
        .required-star { color:#3b82f6; }
        .form-actions { display:flex; gap:0.75rem; justify-content:center; margin:1.5rem 0 1rem; }
        .btn-submit { background:linear-gradient(135deg,#5c6ac4,#3b4b9e); color:#fff; border:none; padding:0.6rem 1.8rem; border-radius:2rem; cursor:pointer; position:relative; }
        .btn-submit .spinner { display:none; width:20px; height:20px; margin:0 auto; border:2px solid #fff; border-top-color: transparent; border-radius:50%; animation: spin 0.8s linear infinite; position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); }
        .btn-submit.loading { color:transparent; }
        .btn-submit.loading .spinner { display:block; }
        @keyframes spin { to { transform: translate(-50%,-50%) rotate(360deg); } }
        .btn-reset { background:#6c757d; color:#fff; border:none; padding:0.6rem 1.8rem; border-radius:2rem; cursor:pointer; }
        .argon-fl-message { padding:0.8rem 1rem; border-radius:0.5rem; margin-bottom:1.5rem; }
        .argon-fl-message.success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
        .argon-fl-message.error { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
        .required-tip { text-align:center; font-size:0.8rem; color:#6c757d; }
        ';
    }

    /**
     * 前端 JS 脚本（AJAX 提交）
     */
    private function get_inline_js() {
        return '
        jQuery(document).ready(function($) {
            $("#argon-fl-form").on("submit", function(e) {
                e.preventDefault();
                var $form = $(this);
                var $msg = $("#argon-fl-message");
                var $btn = $form.find(".btn-submit");
                $msg.hide().removeClass("success error").html("");
                // 显示 loading
                $btn.addClass("loading").prop("disabled", true);
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
                    $btn.removeClass("loading").prop("disabled", false);
                    if (res.success) {
                        $msg.addClass("success").html("✅ " + res.data).show();
                        $("#argon-fl-form")[0].reset();
                    } else {
                        $msg.addClass("error").html("❌ " + res.data).show();
                    }
                }).fail(function() {
                    $btn.removeClass("loading").prop("disabled", false);
                    $msg.addClass("error").html("❌ 网络错误，请稍后再试。").show();
                });
            });
            $(".btn-reset").on("click", function(e) {
                if (!confirm("重置表单所有内容？")) e.preventDefault();
            });
        });
        ';
    }

    /**
     * 渲染申请表单短代码
     */
    public function render_apply_form() {
        ob_start();
        ?>
        <div id="argon-fl-message" class="argon-fl-message" style="display:none;"></div>
        <form id="argon-fl-form">
            <div class="form-group"><label>网站名称 <span class="required-star">*</span></label><input type="text" id="site_name" placeholder="例如：并非正文" required></div>
            <div class="form-group"><label>网站链接 <span class="required-star">*</span></label><input type="url" id="site_url" placeholder="https://yourdomain.com" required><small>请输入带 http:// 或 https:// 的链接！</small></div>
            <div class="form-group"><label>站点 Logo <span class="required-star">*</span></label><input type="url" id="site_logo" placeholder="https://yourdomain.com/logo.png" required></div>
            <div class="form-group"><label>描述 <span class="required-star">*</span></label><textarea id="description" rows="3" placeholder="请输入简介" required></textarea></div>
            <div class="form-group"><label>RSS 地址</label><input type="url" id="rss" placeholder="https://yourdomain.com/feed.xml"><small>可选，请填写有效的 RSS 地址</small></div>
            <div class="form-actions"><button type="submit" class="btn-submit">📨 提交申请<span class="spinner"></span></button><button type="reset" class="btn-reset">🔄 重提</button></div>
            <div class="required-tip"><span class="required-star">*</span> 提示：带有浅蓝色星号 * 的项，表示必填项～</div>
        </form>
        <?php
        return ob_get_clean();
    }

    /**
     * 渲染友链列表（按分类展示，参考 neighbors 页面风格）
     * 使用 get_bookmarks() 获取内置链接管理器中的公开链接
     */
    public function render_friendlink_list() {
        // 获取所有链接分类（link_category）
        $categories = get_terms(array(
            'taxonomy'   => 'link_category',
            'hide_empty' => true,
        ));

        if (is_wp_error($categories) || empty($categories)) return '<p>暂无友链分类。</p>';

        $output = '<div class="friend-links friend-links-style1">';

        foreach ($categories as $category) {
            // 跳过「待审核友链」分类（不对外展示）
            if ($category->name === self::PENDING_CATEGORY) continue;

            // 获取该分类下所有可见链接（link_visible = 'Y'）
            $bookmarks = get_bookmarks(array(
                'category' => $category->term_id,
                'hide_invisible' => true,   // 仅获取 link_visible = 'Y' 的链接
                'orderby' => 'name',
                'order' => 'ASC',
            ));

            if (empty($bookmarks)) continue;

            $output .= '<div class="friend-link-category">';
            $output .= '<h3 class="category-title">' . esc_html($category->name) . '</h3>';
            $output .= '<ul class="friend-link-list">';

            foreach ($bookmarks as $bookmark) {
                $output .= '<li class="friend-link-item">';
                $output .= '<a href="' . esc_url($bookmark->link_url) . '" target="_blank" rel="noopener noreferrer nofollow">';

                if (!empty($bookmark->link_image)) {
                    $output .= '<img src="' . esc_url($bookmark->link_image) . '" alt="' . esc_attr($bookmark->link_name) . '" class="friend-link-avatar">';
                } else {
                    $output .= '<div class="friend-link-avatar-placeholder">' . mb_substr($bookmark->link_name, 0, 1) . '</div>';
                }

                $output .= '<div class="friend-link-info">';
                $output .= '<span class="friend-link-name">' . esc_html($bookmark->link_name) . '</span>';
                if (!empty($bookmark->link_description)) {
                    $output .= '<span class="friend-link-desc">' . esc_html($bookmark->link_description) . '</span>';
                }
                $output .= '</div></a></li>';
            }

            $output .= '</ul></div>';
        }

        $output .= '</div>';

        // 内嵌友链列表样式（参考 neighbors 页面风格）
        $output .= '<style>
        .friend-links-style1 { max-width: 1200px; margin: 0 auto; }
        .friend-link-category { margin-bottom: 2rem; }
        .category-title { font-size: 1.5rem; font-weight: 600; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 2px solid #5c6ac4; display: inline-block; }
        .friend-link-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem; list-style: none; margin: 0; padding: 0; }
        .friend-link-item { background: var(--card-bg, #fff); border-radius: 0.75rem; box-shadow: 0 2px 8px rgba(0,0,0,0.06); transition: all 0.3s ease; overflow: hidden; }
        .friend-link-item:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.1); }
        .friend-link-item a { display: flex; align-items: center; padding: 1rem; text-decoration: none; color: inherit; gap: 0.75rem; }
        .friend-link-avatar { width: 48px; height: 48px; border-radius: 50%; object-fit: cover; flex-shrink: 0; }
        .friend-link-avatar-placeholder { width: 48px; height: 48px; border-radius: 50%; background: linear-gradient(135deg, #5c6ac4, #3b4b9e); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; font-weight: 600; flex-shrink: 0; }
        .friend-link-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 0.25rem; }
        .friend-link-name { font-size: 1rem; font-weight: 600; color: #2d3748; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .friend-link-desc { font-size: 0.8rem; color: #718096; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        @media (max-width: 640px) {
            .friend-link-list { grid-template-columns: 1fr; }
        }
        </style>';

        return $output;
    }

    /**
     * AJAX 处理表单提交 - 写入 WordPress 内置链接管理器（防止重复提交）
     */
    public function handle_ajax_submit() {
        // 验证 Nonce
        check_ajax_referer('argon_fl_submit_nonce', 'nonce');

        // 获取并清理表单数据
        $site_name = sanitize_text_field($_POST['site_name']);
        $site_url  = esc_url_raw($_POST['site_url']);
        $site_logo = esc_url_raw($_POST['site_logo']);
        $description = sanitize_textarea_field($_POST['description']);
        $rss = esc_url_raw($_POST['rss']);

        // 表单验证
        $errors = array();
        if (empty($site_name)) $errors[] = '网站名称不能为空';
        if (empty($site_url) || !filter_var($site_url, FILTER_VALIDATE_URL)) $errors[] = '网站链接无效（需包含 http:// 或 https://）';
        if (empty($site_logo) || !filter_var($site_logo, FILTER_VALIDATE_URL)) $errors[] = 'Logo 地址无效';
        if (empty($description)) $errors[] = '简介不能为空';
        if (!empty($rss) && !filter_var($rss, FILTER_VALIDATE_URL)) $errors[] = 'RSS 地址格式无效';
        if (!empty($errors)) wp_send_json_error(implode('<br>', $errors));

        global $wpdb;

        // 🔍 防止重复提交：检查 wp_links 表中是否已存在相同的 link_url
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT link_id FROM {$wpdb->links} WHERE link_url = %s",
            $site_url
        ));

        if ($exists) {
            wp_send_json_error('该网站链接已提交过申请，请勿重复提交。');
        }

        // 确保「待审核友链」分类存在
        $pending_cat_id = $this->ensure_pending_category();
        if (is_wp_error($pending_cat_id) || !is_numeric($pending_cat_id) || $pending_cat_id <= 0) {
            wp_send_json_error('系统错误：待审核分类无效，请检查数据库。');
        }

        // 构建链接数据（使用 WordPress 原生函数 wp_insert_link）
        $link_data = array(
            'link_name' => $site_name,                      // 网站名称（不添加前缀）
            'link_url' => $site_url,
            'link_image' => $site_logo,
            'link_description' => $description,
            'link_rss' => $rss,
            'link_visible' => 'N',                              // 'N' = 不可见，待审核状态
            'link_target' => '_blank',
            'link_notes' => '来自友链插件',
        );

        // 包含 wp_insert_link 函数所需的文件
        require_once(ABSPATH . 'wp-admin/includes/bookmark.php');

        $link_id = wp_insert_link($link_data);
        if (is_wp_error($link_id) || $link_id === 0) wp_send_json_error('链接写入失败，请稍后再试。');

        // 修复 link_updated 字段（强制写入当前时间）
        $wpdb->update($wpdb->links, array('link_updated' => current_time('mysql')), array('link_id' => $link_id));

        // 将链接关联到「待审核友链」分类
        $term_result = wp_set_object_terms($link_id, (int) $pending_cat_id, 'link_category');
        if (is_wp_error($term_result)) {
            // 分类关联失败时记录日志但不阻止提交（链接本身已存在）
            error_log('Argon FriendLink: 关联「待审核友链」失败 - ' . $term_result->get_error_message());
        }

        // 发送邮件通知管理员
        $this->send_admin_notification($link_id, $site_name, $site_url, $site_logo, $description, $rss);

        wp_send_json_success('申请已提交！会尽快审核的o(*￣▽￣*)ブ。');
    }

    /**
     * 发送邮件通知管理员（含快速审核链接）
     */
    private function send_admin_notification($link_id, $site_name, $site_url, $site_logo, $description, $rss) {
        $admin_email = get_option('admin_email');
        $edit_url    = admin_url('link.php?action=edit&link_id=' . $link_id);            // 编辑该链接
        $approve_url = admin_url('admin-post.php?action=argon_fl_approve&link_id=' . $link_id . '&_wpnonce=' . wp_create_nonce('argon_fl_manage_' . $link_id));
        $delete_url  = admin_url('admin-post.php?action=argon_fl_delete&link_id=' . $link_id . '&_wpnonce=' . wp_create_nonce('argon_fl_manage_' . $link_id));

        $subject = "【新友链】-- 「{$site_name}」 提交了友链申请";
        $message = "<html><body>
        <div style='background:#fff; border-radius:6px; padding:35px 30px; max-width:1200px; margin:15px auto;'>
            <div style='font-size:24px; text-align:center;'>「{$site_name}」 申请交换友链</div>
            <div style='height:1px; background:rgba(0,0,0,0.15); width:300px; margin:20px auto;'></div>
            <div style='border-left:4px solid rgba(0,0,0,0.15); padding:20px 30px; background:rgba(0,0,0,0.05); border-radius:6px; margin-bottom:30px;'>
                <div><strong><span style='color:#5e72e4;'>@{$site_name}</span> 的信息：</strong></div>
                <ul>
                    <li><strong>网站名称：</strong> {$site_name}</li>
                    <li><strong>链接地址：</strong> <a href='{$site_url}'>{$site_url}</a></li>
                    <li><strong>Logo：</strong> <a href='{$site_logo}'>{$site_logo}</a></li>
                    <li><strong>简介：</strong> {$description}</li>
                    " . (!empty($rss) ? "<li><strong>RSS：</strong> <a href='{$rss}'>{$rss}</a></li>" : "") . "
                </ul>
            </div>
            <p style='text-align:center; margin-top:20px;'>
                <a href='{$edit_url}' style='background:#5e72e4; color:#fff; padding:10px 15px; border-radius:4px; text-decoration:none; margin:5px; display:inline-block;'>前往编辑</a>
                <a href='{$approve_url}' style='background:#1174e6; color:#fff; padding:10px 15px; border-radius:4px; text-decoration:none; margin:5px; display:inline-block;'>审核通过</a>
                <a href='{$delete_url}' style='background:#e45e5e; color:#fff; padding:10px 15px; border-radius:4px; text-decoration:none; margin:5px; display:inline-block;'>删除该链接</a>
            </p>
            <p align='center' style='color:#6c757d; font-size:12px;'>—— 此邮件由 Argon FriendLink Apply 插件自动发送 ——</p>
        </div>
        </body></html>";

        wp_mail($admin_email, $subject, $message, array('Content-Type: text/html; charset=UTF-8'));
    }

    /**
     * 处理审核通过：将链接设为可见，并移出「待审核友链」分类，移入「网线空间」分类
     */
    public function handle_approve() {
        if (!current_user_can('manage_links')) wp_die('权限不足，无法执行此操作');

        $link_id = intval($_GET['link_id']);
        $nonce = $_GET['_wpnonce'] ?? '';

        if (!wp_verify_nonce($nonce, 'argon_fl_manage_' . $link_id)) wp_die('无效请求，请刷新页面后重试。');

        // 获取用户选择的分类（如果有）
        $target_cat_id = isset($_POST['target_category']) ? intval($_POST['target_category']) : 0;
        if ($target_cat_id <= 0) {
            // 如果没有传入，则使用默认分类（网线空间）
            $target_cat_id = $this->ensure_approved_category();
        }

        require_once(ABSPATH . 'wp-admin/includes/bookmark.php');

        // 1. 更新链接可见性为 'Y'
        $update_data = array(
            'link_id'      => $link_id,
            'link_visible' => 'Y',
        );
        wp_update_link($update_data);

        // 可选：从「待审核友链」分类中移出（保留在其它分类或不做处理）
        // 2. 更新至「网线空间」分类
        // $approved_cat_id = $this->ensure_approved_category();
        // if (is_wp_error($approved_cat_id) || !is_numeric($approved_cat_id) || $approved_cat_id <= 0) {
        //     error_log('Argon FriendLink: 「网线空间」分类无效 - ' . (is_wp_error($approved_cat_id) ? $approved_cat_id->get_error_message() : 'ID为空'));
        // } else {
        //     // 3. 将链接的分类替换为「网线空间」
        //     $set_terms = wp_set_object_terms($link_id, (int) $approved_cat_id, 'link_category', false);
        //     if (is_wp_error($set_terms)) {
        //         error_log('Argon FriendLink: 将链接移入「网线空间」失败 - ' . $set_terms->get_error_message());
        //     }
        // }
        if (!is_wp_error($target_cat_id) && $target_cat_id > 0) {
            wp_set_object_terms($link_id, (int) $target_cat_id, 'link_category', false);
        }

        // 重定向回待审核页面，并携带成功消息
        $redirect_url = admin_url('link-manager.php?page=argon-fl-pending');
        wp_redirect(add_query_arg('message', 'approved', $redirect_url));
        exit;
    }

    /**
     * 处理删除申请：永久删除链接
     */
    public function handle_delete() {
        if (!current_user_can('manage_links')) wp_die('权限不足，无法执行此操作');

        $link_id = intval($_GET['link_id']);
        $nonce = $_GET['_wpnonce'] ?? '';

        if (!wp_verify_nonce($nonce, 'argon_fl_manage_' . $link_id)) wp_die('无效请求，请刷新页面后重试。');

        require_once(ABSPATH . 'wp-admin/includes/bookmark.php');

        $result = wp_delete_link($link_id);

        if ($result === false) wp_die('删除链接失败，请稍后再试。');

        $redirect_url = admin_url('link-manager.php?page=argon-fl-pending');
        wp_redirect(add_query_arg('message', 'deleted', $redirect_url));
        exit;
    }

    /**
     * 添加后台管理菜单：快捷查看待审核链接
     */
    public function add_admin_menu() {
        // 添加子菜单到「链接」菜单下
        add_submenu_page(
            'link-manager.php',
            '待审核友链',
            '待审核友链',
            'manage_links',
            'argon-fl-pending',
            array($this, 'pending_links_page')
        );
    }

    /**
     * 待审核链接管理页面（显示所有 link_visible='N' 的链接）
     */
    public function pending_links_page() {
        // 获取所有不可见链接（待审核）
        $all_links = get_bookmarks(array(
            'hide_invisible' => false,           // 获取所有链接（包括不可见的）
            'orderby'        => 'link_id',
            'order'          => 'DESC',
        ));
        // 筛选出 link_visible !== 'Y' 的链接
        // $pending = array_filter($pending_links, function($link) { return $link->link_visible !== 'Y'; });
        $pending = array();
        $pending_cat_id = $this->ensure_pending_category(); // 待审核分类 ID
        $uncat_id = get_term_by('slug', 'uncategorized', 'link_category') ? get_term_by('slug', 'uncategorized', 'link_category')->term_id : 0;

        foreach ($all_links as $link) {
            if ($link->link_visible === 'Y') continue; // 已通过的跳过
            $link_cats = wp_get_object_terms($link->link_id, 'link_category', array('fields' => 'ids'));
            // 筛选条件：没有分类（即未分类）或 分类中包含待审核友链
            if (empty($link_cats) || in_array($pending_cat_id, $link_cats)) {
                $pending[] = $link;
            }
        }

        // 获取所有链接分类，用于下拉菜单
        $all_categories = get_terms(array('taxonomy' => 'link_category', 'hide_empty' => false));
        ?>
        <div class="wrap">
            <h1>待审核友链申请</h1>
            <?php if (isset($_GET['message'])): ?>
                <div class="notice notice-success">
                    <p><?php echo $_GET['message'] === 'approved' ? '✅ 链接已批准并移入选定分类。' : '🗑️ 链接已删除。'; ?></p>
                </div>
            <?php endif; ?>

            <?php if (empty($pending)): ?>
                <div class="notice notice-info"><p>🎉 暂无待审核的友链申请！</p></div>
            <?php else: ?>
                <form method="post" id="bulk-approve-form">
                    <table class="wp-list-table widefat striped">
                        <thead>
                            <tr><th><input type="checkbox" id="select-all"></th><th>ID</th><th>网站名称</th><th>链接地址</th><th>Logo</th><th>简介</th><th>提交时间</th><th>批准后分类</th><th>操作</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($pending as $link): 
                            $link_cats = wp_get_object_terms($link->link_id, 'link_category', array('fields' => 'names'));
                            $current_cat = !empty($link_cats) ? $link_cats[0] : '未分类';
                        ?>
                        <tr>
                                <td><input type="checkbox" name="link_ids[]" value="<?php echo $link->link_id; ?>"></td>
                                <td><?php echo $link->link_id; ?></td>
                                <td><strong><?php echo esc_html($link->link_name); ?></strong></td>
                                <td><a href="<?php echo esc_url($link->link_url); ?>" target="_blank"><?php echo esc_url($link->link_url); ?></a></td>
                                <td><?php if ($link->link_image) echo '<img src="' . esc_url($link->link_image) . '" style="max-width:45px;">'; ?></td>
                                <td><?php echo esc_html($link->link_description); ?></td>
                                <td><?php echo esc_html($link->link_updated); ?></td>
                                <td>
                                    <select name="category_<?php echo $link->link_id; ?>" class="link-category-select">
                                        <?php foreach ($all_categories as $cat): ?>
                                            <option value="<?php echo $cat->term_id; ?>" <?php selected($cat->name, self::APPROVED_CATEGORY); ?>><?php echo esc_html($cat->name); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <a href="<?php echo admin_url('link.php?action=edit&link_id=' . $link->link_id); ?>" class="button button-small">✏️ 编辑</a>
                                    <a href="<?php echo admin_url('admin-post.php?action=argon_fl_approve&link_id=' . $link->link_id . '&_wpnonce=' . wp_create_nonce('argon_fl_manage_' . $link->link_id)); ?>" class="button button-small button-primary" onclick="return confirm('批准后，该友链将公开显示并移入「网线空间」分类。确认通过？')">✅ 批准</a>
                                    <a href="<?php echo admin_url('admin-post.php?action=argon_fl_delete&link_id=' . $link->link_id . '&_wpnonce=' . wp_create_nonce('argon_fl_manage_' . $link->link_id)); ?>" class="button button-small" style="background:#ef4444; color:white;" onclick="return confirm('确认删除该申请？删除后不可恢复。')">🗑️ 删除</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div style="margin-top:20px;">
                        <label>批量批准后分类：</label>
                        <select name="bulk_category">
                            <?php foreach ($all_categories as $cat): ?>
                                <option value="<?php echo $cat->term_id; ?>" <?php selected($cat->name, self::APPROVED_CATEGORY); ?>><?php echo esc_html($cat->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" name="bulk_approve" class="button button-primary">批量批准</button>
                    </div>
                </form>
            <?php endif; ?>
            <p style="margin-top: 20px;">
                <a href="<?php echo admin_url('link-manager.php'); ?>" class="button">📁 前往完整链接管理</a>
            </p>
        </div>
        <style>
            .wp-list-table td { vertical-align: middle; }
            .button-primary { background: #10b981 !important; border-color: #10b981 !important; }
            .link-category-select { min-width: 120px; }
        </style>
        <script>
        jQuery(document).ready(function($) {
            // 全选/取消全选
            $('#select-all').on('click', function() {
                $('input[name="link_ids[]"]').prop('checked', this.checked);
            });
            // 单个批准按钮提交（携带对应的分类选择）
            $('.approve-single').on('click', function(e) {
                e.preventDefault();
                var $row = $(this).closest('tr');
                var link_id = $(this).val();
                var category = $row.find('.link-category-select').val();
                var form = $('<form method="post" action="<?php echo admin_url('admin-post.php?action=argon_fl_approve'); ?>"></form>');
                form.append('<input type="hidden" name="link_id" value="' + link_id + '">');
                form.append('<input type="hidden" name="target_category" value="' + category + '">');
                form.append('<input type="hidden" name="_wpnonce" value="<?php echo wp_create_nonce('argon_fl_manage_'); ?>' + link_id + '">');
                $('body').append(form);
                form.submit();
            });
            // 批量批准
            $('#bulk-approve-form').on('submit', function(e) {
                if ($('input[name="bulk_approve"]').is(':focus')) {
                    // 批量批准逻辑：收集选中的 link_ids 和批量分类
                    var selected = [];
                    $('input[name="link_ids[]"]:checked').each(function() { selected.push($(this).val()); });
                    if (selected.length === 0) {
                        alert('请至少选择一个链接。');
                        e.preventDefault();
                        return false;
                    }
                    var bulkCat = $('select[name="bulk_category"]').val();
                    // 为每个选中的链接动态生成一个表单并提交（或使用 AJAX，此处使用逐个提交）
                    for (var i = 0; i < selected.length; i++) {
                        var form = $('<form method="post" action="<?php echo admin_url('admin-post.php?action=argon_fl_approve'); ?>"></form>');
                        form.append('<input type="hidden" name="link_id" value="' + selected[i] + '">');
                        form.append('<input type="hidden" name="target_category" value="' + bulkCat + '">');
                        form.append('<input type="hidden" name="_wpnonce" value="<?php echo wp_create_nonce('argon_fl_manage_'); ?>' + selected[i] + '">');
                        $('body').append(form);
                        form.submit();
                    }
                    e.preventDefault();
                    return false;
                }
            });
        });
        </script>
        <?php
    }

    /**
     * 在链接列表页添加「待审核」筛选下拉框
     */
    public function add_link_visibility_filter() {
        $current = isset($_GET['link_visible']) ? $_GET['link_visible'] : '';
        ?>
        <select name="link_visible">
            <option value="">所有链接</option>
            <option value="Y" <?php selected($current, 'Y'); ?>>已公开</option>
            <option value="N" <?php selected($current, 'N'); ?>>待审核（私密）</option>
        </select>
        <?php
    }

    /**
     * 根据筛选条件修改链接查询
     *
     * @——param object $query WP_Link_Query 对象
     */
    public function filter_links_by_visibility($query) {
        if (!is_admin() || !isset($_GET['link_visible']) || $_GET['link_visible'] === '' || empty($_GET['link_visible'])) return;
        $vis = $_GET['link_visible'];
        if ($vis === 'Y' || $vis === 'N') $query->query_vars['link_visible'] = $vis;
    }

    /**
     * 修改链接管理页面的可见性字段显示文案
     * 使「不可见」更明确地表示为「待审核」
     */
    public function modify_visibility_text() {
        ?>
        <script>
        jQuery(function($) {
            // 修改链接列表中「可见性」列的显示
            $('.wp-list-table td.column-visible').each(function() {
                var text = $(this).text().trim();
                if (text === '不可见') $(this).html('<span style="color:#f59e0b; background:#fff3e0; padding:2px 8px; border-radius:20px;">⏳ 待审核</span>');
                else if (text === '可见') $(this).html('<span style="color:#10b981; background:#e8faf0; padding:2px 8px; border-radius:20px;">✅ 已通过</span>');
            });
        });
        </script>
        <?php
    }
}

// 初始化插件
new Argon_FriendLink_Apply();