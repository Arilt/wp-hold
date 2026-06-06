<?php
/**
 * Plugin Name: Argon FriendLink Apply
 * Plugin URI:  https://bfzw.top/
 * Description: 自助申请友链，支持AJAX提交、邮件通知、后台审核。可选择存储到WordPress内置链接管理器或自定义表。短代码 [argon_friendlink_apply] 和 [argon_friendlink_list]
 * Version:     0.0.3
 * Author:      小崽安
 * License:     GPL v2 or later
 * Text Domain: argon-friendlink
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 主类 Argon_FriendLink_Apply
 */
class Argon_FriendLink_Apply {

    /**
     * 自定义表名
     */
    private $table_name;

    /**
     * 存储模式选项名
     */
    const STORAGE_MODE_OPTION = 'argon_fl_storage_mode';

    /**
     * 存储模式：自定义表
     */
    const MODE_CUSTOM_TABLE = 'custom';

    /**
     * 存储模式：内置链接管理器
     */
    const MODE_WP_LINKS = 'wp_links';

    /**
     * 存储模式配置
     */
    private $storage_mode;

    /**
     * 链接分类配置（仅在 MODE_WP_LINKS 模式下生效）
     */
    const LINK_CATEGORY_OPTION = 'argon_fl_link_category';

    /**
     * 构造函数
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'argon_friendlink_applications';

        // 获取存储模式配置
        $this->storage_mode = get_option(self::STORAGE_MODE_OPTION, self::MODE_CUSTOM_TABLE);

        // 激活钩子：创建数据表并设置默认选项
        register_activation_hook(__FILE__, array($this, 'activate'));

        // 初始化插件
        add_action('init', array($this, 'init'));

        // 注册短代码
        add_shortcode('argon_friendlink_apply', array($this, 'render_apply_form'));
        add_shortcode('argon_friendlink_list', array($this, 'render_friendlink_list'));

        // 加载前端脚本和样式
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        // AJAX 处理申请提交
        add_action('wp_ajax_submit_friendlink_apply', array($this, 'handle_ajax_submit'));
        add_action('wp_ajax_nopriv_submit_friendlink_apply', array($this, 'handle_ajax_submit'));

        // 后台管理菜单
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // 处理管理操作（通过/删除）
        add_action('admin_post_argon_fl_approve', array($this, 'handle_approve'));
        add_action('admin_post_argon_fl_delete', array($this, 'handle_delete'));

        // 处理存储模式切换（仅管理员）
        add_action('admin_post_argon_fl_switch_mode', array($this, 'handle_switch_mode'));

        // 插件设置页面
        add_action('admin_menu', array($this, 'add_settings_menu'));

        // 确保链接管理器可用（如果使用内置模式）
        add_action('init', array($this, 'ensure_link_manager_enabled'));
    }

    /**
     * 激活插件
     */
    public function activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // 创建自定义表（始终创建，无论哪种模式都保留申请记录）
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
            target_link_id int(11) DEFAULT NULL COMMENT '关联的wp_links表ID（MODE_WP_LINKS模式使用）',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status (status),
            KEY idx_target_link_id (target_link_id)
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // 设置默认选项
        if (!get_option(self::STORAGE_MODE_OPTION)) {
            add_option(self::STORAGE_MODE_OPTION, self::MODE_CUSTOM_TABLE);
        }

        // 设置默认链接分类（仅在 MODE_WP_LINKS 模式下生效）
        if (!get_option(self::LINK_CATEGORY_OPTION)) {
            add_option(self::LINK_CATEGORY_OPTION, 0);
        }
    }

    /**
     * 确保链接管理器可用（WordPress 3.5+ 默认隐藏）
     */
    public function ensure_link_manager_enabled() {
        if ($this->storage_mode === self::MODE_WP_LINKS) {
            add_filter('pre_option_link_manager_enabled', '__return_true');
        }
    }

    /**
     * 初始化：加载文本域
     */
    public function init() {
        // 可加载语言包
    }

    /**
     * 添加设置页面菜单
     */
    public function add_settings_menu() {
        add_submenu_page(
            'argon-friendlink-apply',
            __('插件设置', 'argon-friendlink'),
            __('设置', 'argon-friendlink'),
            'manage_options',
            'argon-friendlink-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * 渲染设置页面
     */
    public function render_settings_page() {
        $current_mode = get_option(self::STORAGE_MODE_OPTION, self::MODE_CUSTOM_TABLE);
        $current_category = get_option(self::LINK_CATEGORY_OPTION, 0);
        ?>
        <div class="wrap">
            <h1><?php _e('友链插件设置', 'argon-friendlink'); ?></h1>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('argon_fl_switch_mode', 'argon_fl_mode_nonce'); ?>
                <input type="hidden" name="action" value="argon_fl_switch_mode">

                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('友链存储模式', 'argon-friendlink'); ?></th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="radio" name="storage_mode" value="<?php echo self::MODE_CUSTOM_TABLE; ?>" <?php checked($current_mode, self::MODE_CUSTOM_TABLE); ?>>
                                    <?php _e('自定义表管理（插件自带友链管理页）', 'argon-friendlink'); ?>
                                </label>
                                <br>
                                <label>
                                    <input type="radio" name="storage_mode" value="<?php echo self::MODE_WP_LINKS; ?>" <?php checked($current_mode, self::MODE_WP_LINKS); ?>>
                                    <?php _e('WordPress内置链接管理器', 'argon-friendlink'); ?>
                                </label>
                                <p class="description">
                                    <?php _e('选择"WordPress内置链接管理器"后，审核通过的友链将自动写入WordPress的链接管理器（wp_links表），您可以在后台"链接"菜单中查看和管理。', 'argon-friendlink'); ?>
                                </p>
                            </fieldset>
                        </td>
                    </tr>

                    <?php if ($this->storage_mode === self::MODE_WP_LINKS): ?>
                    <tr id="link-category-row">
                        <th scope="row"><?php _e('友链默认分类', 'argon-friendlink'); ?></th>
                        <td>
                            <?php
                            $categories = get_terms(array(
                                'taxonomy' => 'link_category',
                                'hide_empty' => false,
                            ));
                            ?>
                            <select name="link_category">
                                <option value="0"><?php _e('-- 请选择分类 --', 'argon-friendlink'); ?></option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat->term_id; ?>" <?php selected($current_category, $cat->term_id); ?>>
                                        <?php echo esc_html($cat->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php _e('审核通过后，友链将自动归入此分类。您可以在"链接" → "链接分类目录"中管理分类。', 'argon-friendlink'); ?>
                            </p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>

                <?php submit_button(__('保存设置', 'argon-friendlink')); ?>
            </form>

            <?php if ($this->storage_mode === self::MODE_WP_LINKS): ?>
            <hr>
            <div class="notice notice-info inline">
                <p><?php _e('💡 提示：内置链接管理器在 WordPress 3.5+ 中默认隐藏，插件已自动启用。您可以在后台左侧菜单中看到"链接"选项，用于管理友链分类和查看已通过审核的友链。', 'argon-friendlink'); ?></p>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * 处理存储模式切换
     */
    public function handle_switch_mode() {
        if (!current_user_can('manage_options')) {
            wp_die(__('权限不足', 'argon-friendlink'));
        }

        check_admin_referer('argon_fl_switch_mode', 'argon_fl_mode_nonce');

        $new_mode = sanitize_text_field($_POST['storage_mode'] ?? self::MODE_CUSTOM_TABLE);
        if (!in_array($new_mode, [self::MODE_CUSTOM_TABLE, self::MODE_WP_LINKS])) {
            $new_mode = self::MODE_CUSTOM_TABLE;
        }

        // 更新存储模式
        update_option(self::STORAGE_MODE_OPTION, $new_mode);
        $this->storage_mode = $new_mode;

        // 更新链接分类设置
        if (isset($_POST['link_category'])) {
            $cat_id = intval($_POST['link_category']);
            if ($cat_id >= 0) {
                update_option(self::LINK_CATEGORY_OPTION, $cat_id);
            }
        }

        wp_redirect(add_query_arg('settings-updated', 'true', wp_get_referer()));
        exit;
    }

    /**
     * 加载前端资源
     */
    public function enqueue_scripts() {
        global $post;
        if (has_shortcode($post->post_content, 'argon_friendlink_apply')) {
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
     * 内联 CSS 样式
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
        .btn-submit { background:linear-gradient(135deg,#5c6ac4,#3b4b9e); color:#fff; border:none; padding:0.6rem 1.8rem; border-radius:2rem; cursor:pointer; }
        .btn-reset { background:#6c757d; color:#fff; border:none; padding:0.6rem 1.8rem; border-radius:2rem; cursor:pointer; }
        .argon-fl-message { padding:0.8rem 1rem; border-radius:0.5rem; margin-bottom:1.5rem; }
        .argon-fl-message.success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
        .argon-fl-message.error { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
        .required-tip { text-align:center; font-size:0.8rem; color:#6c757d; }
        /* 友链列表美化样式 */
        .argon-fl-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; list-style: none; padding: 0; margin: 0; }
        .argon-fl-list li { background: #fff; border-radius: 12px; padding: 16px 20px; transition: all 0.3s ease; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border: 1px solid #f0f0f0; list-style: none; display: flex; align-items: center; gap: 15px; }
        .argon-fl-list li:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.12); background: #fff; border-color: #e0e0e0; }
        .argon-fl-list .link-avatar { flex-shrink: 0; width: 48px; height: 48px; border-radius: 50%; overflow: hidden; background: #f5f5f5; display: flex; align-items: center; justify-content: center; }
        .argon-fl-list .link-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .argon-fl-list .link-avatar .no-logo { width: 48px; height: 48px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: bold; }
        .argon-fl-list .link-info { flex: 1; min-width: 0; }
        .argon-fl-list .link-info .link-name { font-size: 16px; font-weight: 600; margin-bottom: 6px; display: block; }
        .argon-fl-list .link-info .link-name a { color: #2c3e50; text-decoration: none; transition: color 0.2s; }
        .argon-fl-list .link-info .link-name a:hover { color: #5c6ac4; }
        .argon-fl-list .link-info .link-desc { font-size: 13px; color: #8595a6; line-height: 1.4; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
        .argon-fl-category-filter { margin-bottom: 30px; text-align: center; }
        .argon-fl-category-filter .filter-btn { display: inline-block; margin: 0 5px 10px; padding: 6px 16px; border-radius: 30px; background: #f5f5f5; color: #666; text-decoration: none; transition: all 0.2s; font-size: 14px; }
        .argon-fl-category-filter .filter-btn.active, .argon-fl-category-filter .filter-btn:hover { background: #5c6ac4; color: #fff; }
        @media (max-width: 640px) { .argon-fl-list { grid-template-columns: 1fr; } .argon-fl-list li { padding: 12px 16px; } }
        ';
    }

    /**
     * 内联 JavaScript
     */
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
            // 分类筛选功能
            $(".filter-btn").on("click", function(e) {
                e.preventDefault();
                var cat = $(this).data("cat");
                $(".filter-btn").removeClass("active");
                $(this).addClass("active");
                if (cat === "all") {
                    $(".argon-fl-list .link-item").show();
                } else {
                    $(".argon-fl-list .link-item").hide();
                    $(".argon-fl-list .link-item[data-cat=\"" + cat + "\"]").show();
                }
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
        <!--<div class="argon-fl-container">-->
            <!--<div class="argon-fl-criteria">-->
            <!--    <p>✔ 您的网站已稳定运行，且有一定的文章量；</p>-->
            <!--    <p>✔ 网站内容健康、积极向上、维护中国共产党的领导，凡内容污秽不堪、反动反共的都不会通过申请。</p>-->
            <!--    <p>✔ 请在您的网站友情链接中增加本站信息。审核时，若未在您的站点中检测到本站链接，审核不予通过。</p>-->
            <!--    <p>✔ 如果申请后，长时间未通过审核，有可能是博主太忙未看到，可以通过联系告知我，谢谢～</p>-->
            <!--</div>-->
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
        <!--</div>-->
        <?php
        return ob_get_clean();
    }

    /**
     * 渲染友链列表短代码（美化版，支持按分类展示）
     */
    public function render_friendlink_list() {
        global $wpdb;

        // 根据存储模式获取链接
        if ($this->storage_mode === self::MODE_WP_LINKS) {
            return $this->render_friendlink_list_from_wp_links();
        } else {
            return $this->render_friendlink_list_from_custom_table();
        }
    }

    /**
     * 从内置链接管理器获取友链（支持分类筛选）
     */
    private function render_friendlink_list_from_wp_links() {
        $default_category = get_option(self::LINK_CATEGORY_OPTION, 0);

        // 获取所有链接分类
        $categories = get_terms(array(
            'taxonomy' => 'link_category',
            'hide_empty' => false,
        ));

        // 构建分类筛选 HTML
        $filter_html = '<div class="argon-fl-category-filter">';
        $filter_html .= '<a href="#" class="filter-btn active" data-cat="all">全部</a>';
        foreach ($categories as $cat) {
            $filter_html .= '<a href="#" class="filter-btn" data-cat="' . $cat->term_id . '">' . esc_html($cat->name) . '</a>';
        }
        $filter_html .= '</div>';

        // 获取已通过审核的链接（visible = 'Y'）
        $bookmarks = get_bookmarks(array(
            'category' => '', // 获取所有分类
            'orderby' => 'link_name',
            'order' => 'ASC',
            'hide_invisible' => true,
        ));

        if (empty($bookmarks)) {
            return '<p>暂无友链。</p>';
        }

        $html = $filter_html . '<ul class="argon-fl-list">';
        foreach ($bookmarks as $bookmark) {
            // 获取链接的分类 ID
            $link_categories = wp_get_object_terms($bookmark->link_id, 'link_category', array('fields' => 'ids'));
            $cat_id = !empty($link_categories) ? $link_categories[0] : 0;

            $logo = $bookmark->link_image;
            $name = $bookmark->link_name;
            $url = $bookmark->link_url;
            $desc = $bookmark->link_description;

            $html .= '<li class="link-item" data-cat="' . $cat_id . '">';
            $html .= '<div class="link-avatar">';
            if ($logo) {
                $html .= '<img src="' . esc_url($logo) . '" alt="' . esc_attr($name) . '">';
            } else {
                $html .= '<div class="no-logo">' . mb_substr($name, 0, 1) . '</div>';
            }
            $html .= '</div>';
            $html .= '<div class="link-info">';
            $html .= '<span class="link-name"><a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($name) . '</a></span>';
            if ($desc) {
                $html .= '<div class="link-desc">' . esc_html($desc) . '</div>';
            }
            $html .= '</div>';
            $html .= '</li>';
        }
        $html .= '</ul>';

        return $html;
    }

    /**
     * 从自定义表获取友链
     */
    private function render_friendlink_list_from_custom_table() {
        global $wpdb;
        $links = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE status = %s AND visibility = %s ORDER BY created_at DESC",
                'approved',
                'public'
            )
        );

        if (!$links) {
            return '<p>暂无友链。</p>';
        }

        $html = '<ul class="argon-fl-list">';
        foreach ($links as $link) {
            $html .= '<li>';
            $html .= '<div class="link-avatar">';
            if ($link->site_logo) {
                $html .= '<img src="' . esc_url($link->site_logo) . '" alt="' . esc_attr($link->orig_name) . '">';
            } else {
                $html .= '<div class="no-logo">' . mb_substr($link->orig_name, 0, 1) . '</div>';
            }
            $html .= '</div>';
            $html .= '<div class="link-info">';
            $html .= '<span class="link-name"><a href="' . esc_url($link->site_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($link->orig_name) . '</a></span>';
            if ($link->description) {
                $html .= '<div class="link-desc">' . esc_html($link->description) . '</div>';
            }
            $html .= '</div>';
            $html .= '</li>';
        }
        $html .= '</ul>';

        return $html;
    }

    /**
     * AJAX 处理表单提交
     */
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

        // 插入申请记录
        $insert_data = [
            'orig_name'   => $site_name,
            'site_name'   => '【待审核】——' . $site_name,
            'site_url'    => $site_url,
            'site_logo'   => $site_logo,
            'description' => $description,
            'rss'         => $rss,
            'status'      => 'pending',
            'visibility'  => 'private',
            'created_at'  => current_time('mysql')
        ];

        // 如果是内置链接管理器模式，暂不写入 wp_links，待审核通过后写入
        $wpdb->insert($this->table_name, $insert_data);
        $apply_id = $wpdb->insert_id;

        // 发送邮件通知管理员
        $this->send_approval_email($apply_id, $site_name, $site_url, $site_logo, $description, $rss);

        wp_send_json_success('申请已提交！管理员将尽快审核。');
    }

    /**
     * 发送审核通知邮件
     */
    private function send_approval_email($apply_id, $site_name, $site_url, $site_logo, $description, $rss) {
        $admin_email = get_option('admin_email');
        $blog_name   = get_bloginfo('name');
        $manage_url  = admin_url('admin.php?page=argon-friendlink-apply');
        $approve_url = admin_url('admin-post.php?action=argon_fl_approve&id=' . $apply_id . '&_wpnonce=' . wp_create_nonce('argon_fl_manage_' . $apply_id));
        $delete_url  = admin_url('admin-post.php?action=argon_fl_delete&id=' . $apply_id . '&_wpnonce=' . wp_create_nonce('argon_fl_manage_' . $apply_id));

        $message = "<html>
        <body>
            <div style='background: #fff;box-shadow: 0 15px 35px rgba(50,50,93,.1), 0 5px 15px rgba(0,0,0,.07);border-radius: 6px;margin: 15px auto 50px auto;padding: 35px 30px;max-width: min(calc(100% - 100px), 1200px);'>
                <div style='font-size:24px;text-align:center;margin-bottom:15px;line-height:120%;'>
                    <p>「{$site_name}」 申请交换友链</p>
                </div>
                <div style='background: rgba(0, 0, 0, .15);height: 1px;width: 100%;max-width: 300px;margin: auto;margin-bottom: 35px;'></div>
                <div style='font-size: 18px;border-left: 4px solid rgba(0, 0, 0, .15);margin: auto;padding: 20px 30px;background: rgba(0,0,0,.08);border-radius: 6px;box-shadow: 0 2px 4px rgba(0,0,0,.075)!important;min-width: 60%;max-width: 90%;margin-bottom: 40px;'>
                <div style='margin-bottom: 10px;'>
                    <strong><span style='color: #5e72e4;'>@{$site_name} </span> 的信息：</strong>
                </div>
                <ul>
                    <li><strong>网站名称:</strong> {$site_name}</li>
                    <li><strong>链接地址:</strong> <a href='{$site_url}'>{$site_url}</a></li>
                    <li><strong>Logo:</strong> <a href='{$site_logo}'>{$site_logo}</a></li>
                    <li><strong>简介:</strong> {$description}</li>
                    <li><strong>RSS:</strong> <a href='{$rss}'>{$rss}</a></li>
                </ul>
            </div>
            <p style='box-sizing:border-box' align='center'>
                <a href='{$manage_url}' style='line-height: 1; color: #fff;background-color: #5e72e4;border-color: #5e72e4;box-shadow: 0 4px 6px rgba(50,50,93,.11), 0 1px 3px rgba(0,0,0,.08);padding: 10px 15px;font-size: 18px;border-radius: 4px;text-decoration: none; margin: 10px;'>前往审核</a>
                <a href='{$approve_url}' style='line-height: 1; color: #fff;background-color: #1174e6;border-color: #5e72e4;box-shadow: 0 4px 6px rgba(50,50,93,.11), 0 1px 3px rgba(0,0,0,.08);padding: 10px 15px;font-size: 18px;border-radius: 4px;text-decoration: none; margin: 10px;'>通过审核</a>
                <a href='{$delete_url}' style='line-height: 1; color: #fff;background-color: #e45e5e;border-color: #5e72e4;box-shadow: 0 4px 6px rgba(50,50,93,.11), 0 1px 3px rgba(0,0,0,.08);padding: 10px 15px;font-size: 18px;border-radius: 4px;text-decoration: none; margin: 10px;'>删除该链接</a>
            </p>
        </body>
        </html>";

        wp_mail($admin_email, "【新友链】-- 「{$site_name}」 提交了友链申请", $message, ['Content-Type: text/html; charset=UTF-8']);
    }

    /**
     * 添加后台管理菜单
     */
    public function add_admin_menu() {
        add_menu_page(
            '友链申请管理',
            '友链申请',
            'manage_options',
            'argon-friendlink-apply',
            array($this, 'admin_page'),
            'dashicons-links',
            30
        );
    }

    /**
     * 后台管理页面
     */
    public function admin_page() {
        global $wpdb;
        $storage_mode = $this->storage_mode;
        $apps = $wpdb->get_results("SELECT * FROM {$this->table_name} ORDER BY created_at DESC");

        echo '<div class="wrap"><h1>友链申请管理</h1>';

        // 显示当前存储模式提示
        $mode_label = ($storage_mode === self::MODE_WP_LINKS) ? 'WordPress内置链接管理器' : '插件自定义表';
        echo '<div class="notice notice-info inline"><p>当前存储模式：<strong>' . $mode_label . '</strong> | <a href="' . admin_url('admin.php?page=argon-friendlink-settings') . '">切换模式</a></p></div>';

        if (isset($_GET['msg'])) echo '<div class="notice notice-success"><p>' . esc_html($_GET['msg']) . '</p></div>';

        echo '<table class="wp-list-table widefat striped"><thead>';
        echo '<tr><th>ID</th><th>网站名称</th><th>链接</th><th>Logo</th><th>简介</th><th>RSS</th><th>状态</th><th>可见性</th><th>申请时间</th><th>操作</th></tr>';
        echo '</thead><tbody>';

        if (!$apps) {
            echo '<tr><td colspan="10">暂无申请记录。</td></tr>';
        }

        foreach ($apps as $app) {
            echo '<tr>';
            echo '<td>' . $app->id . '</td>';
            echo '<td>' . esc_html($app->site_name) . '<br><small>原名:' . esc_html($app->orig_name) . '</small></td>';
            echo '<td><a href="' . esc_url($app->site_url) . '" target="_blank">链接</a></td>';
            echo '<td>' . ($app->site_logo ? '<img src="' . esc_url($app->site_logo) . '" style="max-width:60px;">' : '') . '</td>';
            echo '<td>' . esc_html(mb_substr($app->description, 0, 50)) . '…</td>';
            echo '<td><a href="' . esc_url($app->rss) . '" target="_blank">RSS</a></td>';
            echo '<td>' . ($app->status == 'pending' ? '<span style="color:orange;">待审核</span>' : ($app->status == 'approved' ? '<span style="color:green;">已通过</span>' : '拒绝')) . '</td>';
            echo '<td>' . ($app->visibility == 'private' ? '私密' : '公开') . '</td>';
            echo '<td>' . $app->created_at . '</td>';
            echo '<td>';
            if ($app->status == 'pending') {
                echo '<a href="' . admin_url('admin-post.php?action=argon_fl_approve&id=' . $app->id . '&_wpnonce=' . wp_create_nonce('argon_fl_manage_' . $app->id)) . '" class="button button-primary" onclick="return confirm(\'通过审核？\')">通过</a> ';
            }
            echo '<a href="' . admin_url('admin-post.php?action=argon_fl_delete&id=' . $app->id . '&_wpnonce=' . wp_create_nonce('argon_fl_manage_' . $app->id)) . '" class="button button-link-delete" onclick="return confirm(\'确认删除？\')">删除</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    /**
     * 处理审核通过
     */
    public function handle_approve() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_GET['_wpnonce'], 'argon_fl_manage_' . intval($_GET['id']))) {
            wp_die('无权操作');
        }

        global $wpdb;
        $id = intval($_GET['id']);
        $application = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $id));

        if (!$application) {
            wp_die('申请记录不存在');
        }

        if ($this->storage_mode === self::MODE_WP_LINKS) {
            // 内置链接管理器模式：写入 wp_links 表
            $default_category = get_option(self::LINK_CATEGORY_OPTION, 0);

            // 准备链接数据
            $link_data = array(
                'link_name'        => $application->orig_name,  // 使用原始名称，不带待审核标记
                'link_url'         => $application->site_url,
                'link_image'       => $application->site_logo,
                'link_description' => $application->description,
                'link_rss'         => $application->rss,
                'link_visible'     => 'Y',  // 可见
                'link_owner'       => get_current_user_id(),
                'link_target'      => '_blank',
            );

            // 加载 bookmark.php 以使用 wp_insert_link 函数
            if (!function_exists('wp_insert_link')) {
                require_once(ABSPATH . 'wp-admin/includes/bookmark.php');
            }

            // 插入链接到 wp_links 表
            $link_id = wp_insert_link($link_data);

            if (is_wp_error($link_id)) {
                wp_die('写入链接管理器失败：' . $link_id->get_error_message());
            }

            // 设置链接分类
            if ($default_category > 0) {
                wp_set_object_terms($link_id, array($default_category), 'link_category');
            }

            // 更新申请记录：状态改为已通过，并记录关联的 link_id
            $wpdb->update($this->table_name, [
                'status' => 'approved',
                'visibility' => 'public',
                'site_name' => $application->orig_name,
                'target_link_id' => $link_id
            ], ['id' => $id]);

        } else {
            // 自定义表模式：直接更新状态
            $wpdb->update($this->table_name, [
                'status' => 'approved',
                'visibility' => 'public',
                'site_name' => $application->orig_name
            ], ['id' => $id]);
        }

        wp_redirect(admin_url('admin.php?page=argon-friendlink-apply&msg=' . urlencode('已通过审核')));
        exit;
    }

    /**
     * 处理删除申请
     */
    public function handle_delete() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_GET['_wpnonce'], 'argon_fl_manage_' . intval($_GET['id']))) {
            wp_die('无权操作');
        }

        global $wpdb;
        $id = intval($_GET['id']);

        // 如果是内置链接管理器模式，且已通过审核，需要同时删除 wp_links 中的链接
        if ($this->storage_mode === self::MODE_WP_LINKS) {
            $application = $wpdb->get_row($wpdb->prepare("SELECT target_link_id, status FROM {$this->table_name} WHERE id = %d", $id));
            if ($application && $application->status === 'approved' && $application->target_link_id) {
                if (!function_exists('wp_delete_link')) {
                    require_once(ABSPATH . 'wp-admin/includes/bookmark.php');
                }
                wp_delete_link($application->target_link_id);
            }
        }

        $wpdb->delete($this->table_name, ['id' => $id]);

        wp_redirect(admin_url('admin.php?page=argon-friendlink-apply&msg=' . urlencode('已删除申请')));
        exit;
    }
}

// 初始化插件
new Argon_FriendLink_Apply();