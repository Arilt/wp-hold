<?php
/**
 * Plugin Name: Self FriendLink Apply
 * Description: 自助申请友链（内置链接管理器版），数据写入 WordPress 内置链接管理器，支持模态框弹窗申请及独立页面申请。短代码 [self_friendlink_apply]（独立页面）、[self_friendlink_apply_modal]（模态框按钮） 和 [self_friendlink_list]（友链展示列表），支持 AJAX 提交、邮件通知、后台审核。
 * Version: 1.1.7
 * Author: 小崽安
 */

if (!defined('ABSPATH')) exit;

class Self_FriendLink_Apply {

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
        add_shortcode('self_friendlink_apply', array($this, 'render_apply_form'));
        add_shortcode('self_friendlink_apply_modal', array($this, 'render_modal_button'));
        add_shortcode('self_friendlink_list', array($this, 'render_friendlink_list'));

        // 加载前端资源
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_head', array($this, 'output_inline_css')); // 直接输出 CSS
        add_action('wp_footer', array($this, 'output_inline_js_and_pjax')); // 在 footer 输出包含 pjax 支持的 JS

        // AJAX 处理申请提交（前台）
        add_action('wp_ajax_submit_friendlink_apply', array($this, 'handle_ajax_submit'));
        add_action('wp_ajax_nopriv_submit_friendlink_apply', array($this, 'handle_ajax_submit'));

        // 后台管理菜单：快捷查看待审核链接
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // 邮件中的快速操作（通过 admin-post 处理，避免权限问题）
        add_action('admin_post_self_fl_approve', array($this, 'handle_approve'));
        add_action('admin_post_self_fl_delete', array($this, 'handle_delete'));

        // 为未登录用户注册处理函数（这是关键！）
        add_action('admin_post_nopriv_self_fl_approve', array($this, 'handle_approve'));
        add_action('admin_post_nopriv_self_fl_delete', array($this, 'handle_delete'));

        // 批量处理待审核友链(不需要开放给未登录用户，所以只需注册已登录的钩子)
        add_action('admin_post_self_fl_bulk_approve', array($this, 'handle_bulk_approve'));

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
            // 返回错误对象，上层会处理
            return $term;
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
        // 仍然需要 AJAX 参数，这些会通过 wp_localize_script 在头部输出
        if (has_shortcode(get_post()->post_content, 'self_friendlink_apply') ||
            has_shortcode(get_post()->post_content, 'self_friendlink_apply_modal')) {
            wp_enqueue_script('jquery');
            // 【修复】使用 site_url 拼接 admin-ajax.php，确保在子目录安装时路径正确
            $ajax_url = site_url('wp-admin/admin-ajax.php');
            // 提供 ajax_url，nonce 改由表单内嵌传递，不再依赖本地化
            wp_localize_script('jquery', 'self_fl_ajax', array(
                // 'ajax_url' => admin_url('admin-ajax.php'),
                'ajax_url' => $ajax_url,
            ));
        }
    }

    public function output_inline_css() {
        // 确保在任何页面都输出 CSS（样式不会冲突）
        echo '<style>' . $this->get_inline_css() . '</style>';
    }

    public function output_inline_js_and_pjax() {
        // 重要：无论当前页面是否包含短代码，都输出带有 pjax 支持的初始化脚本
        // 因为 Pjax 跳转后，整个页面的 <body> 内容会被替换，但 <head> 中的内容不会变
        // 所以这个脚本必须预先存在于页面中，然后通过 pjaxLoaded 反复执行初始化逻辑
        ?>
        <script id="self-fl-pjax-loader">
        // 核心初始化函数：绑定独立表单、模态框事件
        function self_fl_bind_events() {
            if (typeof jQuery === 'undefined') {
                // 如果 jQuery 尚未加载，等待一小段时间再试
                setTimeout(self_fl_bind_events, 100);
                return;
            }
            var $ = jQuery;

            // 【修复】获取 AJAX URL（优先使用全局变量，否则根据当前页面路径推断）
            var ajax_url = (typeof self_fl_ajax !== 'undefined' && self_fl_ajax.ajax_url)
                ? self_fl_ajax.ajax_url
                : (window.location.origin + window.location.pathname.split('/').slice(0, -1).join('/') + '/wp-admin/admin-ajax.php');
            
            // 独立页面表单提交
            $("#self-fl-form").off("submit").on("submit", function(e) {
                e.preventDefault();
                var $form = $(this);
                var $msg = $("#self-fl-message");
                var $btn = $form.find(".btn-submit");
                $msg.hide().removeClass("success error").html("");
                // 显示 loading
                $btn.addClass("loading").prop("disabled", true);
                // 从表单中获取 nonce
                var nonce = $form.find('input[name="self_fl_nonce"]').val();
                if (!nonce) {
                    $msg.addClass("error").html("❌ 安全验证参数缺失，请刷新页面后重试。").show();
                    $btn.removeClass("loading").prop("disabled", false);
                    return;
                }
                var data = {
                    action: "submit_friendlink_apply",
                    nonce: nonce,
                    site_name: $("#site_name").val(),
                    site_url: $("#site_url").val(),
                    site_logo: $("#site_logo").val(),
                    description: $("#description").val(),
                    rss: $("#rss").val()
                };
                $.post(ajax_url, data, function(res) {
                    $btn.removeClass("loading").prop("disabled", false);
                    if (res.success) {
                        $msg.addClass("success").html("✅ " + res.data).show();
                        $("#self-fl-form")[0].reset();
                    } else {
                        $msg.addClass("error").html("❌ " + res.data).show();
                    }
                }).fail(function() {
                    $btn.removeClass("loading").prop("disabled", false);
                    $msg.addClass("error").html("❌ 网络错误，请稍后再试。").show();
                });
            });
            
            // 独立页面重置按钮确认
            $(".btn-reset").off("click").on("click", function(e) {
                if (!confirm("重置表单所有内容？")) e.preventDefault();
            });

            // 模态框逻辑 - 使用 class "show" 控制显示
            var $modal = $("#self-fl-modal-overlay");
            var $modalForm = $("#self-fl-modal-form");
            var $modalMsg = $("#self-fl-modal-message");

            // 打开模态框
            $(document).off("click", ".self-fl-modal-trigger").on("click", ".self-fl-modal-trigger", function(e) {
                e.preventDefault();
                $modal.addClass("show");
                $("body").css("overflow", "hidden");
                // 只清空消息，不清空表单字段
                $modalMsg.hide().removeClass("success error").html("");
            });

            // 关闭模态框（保留表单内容，不清空）
            $modal.off("click", ".self-fl-modal-close, .self-fl-modal-overlay");
            $(".self-fl-modal-close, .self-fl-modal-overlay").off("click").on("click", function(e) {
                if (e.target === this) {
                    $modal.removeClass("show");
                    $("body").css("overflow", "");
                }
            });

            // 模态框内表单提交
            $modalForm.off("submit").on("submit", function(e) {
                e.preventDefault();
                var $btn = $modalForm.find(".btn-submit");
                $modalMsg.hide().removeClass("success error").html("");
                $btn.addClass("loading").prop("disabled", true);
                var nonce = $modalForm.find('input[name="self_fl_nonce"]').val();
                if (!nonce) {
                    $modalMsg.addClass("error").html("❌ 安全验证参数缺失，请刷新页面后重试。").show();
                    $btn.removeClass("loading").prop("disabled", false);
                    return;
                }
                var data = {
                    action: "submit_friendlink_apply",
                    nonce: nonce,
                    site_name: $("#modal_site_name").val(),
                    site_url: $("#modal_site_url").val(),
                    site_logo: $("#modal_site_logo").val(),
                    description: $("#modal_description").val(),
                    rss: $("#modal_rss").val()
                };
                $.post(ajax_url, data, function(res) {
                    $btn.removeClass("loading").prop("disabled", false);
                    if (res.success) {
                        $modalMsg.addClass("success").html("✅ " + res.data).show();
                        // 提交成功后重置表单并3秒后自动关闭模态框
                        $modalForm[0].reset();
                        setTimeout(function() {
                            $modal.removeClass("show");
                            $("body").css("overflow", "");
                        }, 2000);
                    } else {
                        $modalMsg.addClass("error").html("❌ " + res.data).show();
                    }
                }).fail(function() {
                    $btn.removeClass("loading").prop("disabled", false);
                    $modalMsg.addClass("error").html("❌ 网络错误，请稍后再试。").show();
                });
            });
        }
        
        // 页面直接加载时执行初始化
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', self_fl_bind_events);
        } else {
            self_fl_bind_events();
    }
        
        // Argon 主题 Pjax 支持：每次 Pjax 加载完成后重新绑定事件
        if (typeof window.pjaxLoaded === 'function') {
            var originalPjaxLoaded = window.pjaxLoaded;
            window.pjaxLoaded = function() {
                originalPjaxLoaded();
                self_fl_bind_events();
            };
        } else {
            window.pjaxLoaded = self_fl_bind_events;
        }
        </script>
        <?php
    }

    /**
     * 前端 CSS 样式（保持原有风格）
     */
    private function get_inline_css() {
        return '
        /* 独立页面表单样式 */
        .self-fl-container { max-width:800px; margin:2rem auto; padding:1.5rem; background: #fff; border-radius:1rem; box-shadow:0 10px 25px -5px rgba(0,0,0,0.05); }
        .self-fl-criteria { background: #e6f7ff; border-left:4px solid #5c6ac4; padding:1rem 1.5rem; border-radius:0.75rem; margin-bottom:2rem; font-size:0.95rem; }
        .form-group { margin-bottom:1.2rem; }
        .form-group label { display:block; font-weight:600; margin-bottom:0.3rem; }
        .form-group input, .form-group textarea { width:100%; padding:0.6rem; border:1px solid #ccc; border-radius:0.375rem; }
        .required-star { color: #3b82f6; }
        .form-actions { display:flex; gap:0.75rem; justify-content:center; margin:1.5rem 0 1rem; }
        .btn-submit { background:linear-gradient(135deg, #5c6ac4, #3b4b9e); color: #fff; border:none; padding:0.6rem 1.8rem; border-radius:2rem; cursor:pointer; position:relative; }
        .btn-submit .spinner { display:none; width:20px; height:20px; margin:0 auto; border:2px solid #fff; border-top-color: transparent; border-radius:50%; animation: spin 0.8s linear infinite; position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); }
        .btn-submit.loading { color:transparent; }
        .btn-submit.loading .spinner { display:block; }
        @keyframes spin { to { transform: translate(-50%,-50%) rotate(360deg); } }
        .btn-reset { background: #6c757d; color: #fff; border:none; padding:0.6rem 1.8rem; border-radius:2rem; cursor:pointer; }
        .self-fl-message { padding:0.8rem 1rem; border-radius:0.5rem; margin-bottom:1.5rem; }
        .self-fl-message.success { background: #d4edda; color: #155724; border:1px solid #c3e6cb; }
        .self-fl-message.error { background: #f8d7da; color: #721c24; border:1px solid #f5c6cb; }
        .required-tip { text-align:center; font-size:0.8rem; color: #6c757d; }

        /* 模态框样式 - 使用 class 切换显示 */
        .self-fl-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.18);
            backdrop-filter: blur(8px);
            z-index: 99999;
            justify-content: center;
            align-items: center;
            display: none;  /* 默认隐藏 */
        }
        .self-fl-modal-overlay.show {
            display: flex;  /* 显示时使用 flex */
        }
        .self-fl-modal-container {
            background: #fff;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            border-radius: 1rem;
            padding: 2rem;
            position: relative;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
        }
        .self-fl-modal-close {
            position: absolute;
            top: 1rem;
            right: 1rem;
            font-size: 1.5rem;
            cursor: pointer;
            color: #666;
            transition: color 0.2s;
        }
        .self-fl-modal-close:hover { color: #000; }
        .self-fl-modal-container .self-fl-criteria { margin-top: 0; }
        ';
    }

    /**
     * 独立页面表单短代码
     */
    public function render_apply_form() {
        $nonce = wp_create_nonce('self_fl_submit_nonce');
        ob_start();
        ?>
        <div id="self-fl-message" class="self-fl-message" style="display:none;"></div>
        <form id="self-fl-form">
            <!-- nonce 存在隐患？ -->
            <input type="hidden" name="self_fl_nonce" value="<?php echo esc_attr($nonce); ?>">
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
     * 模态框按钮短代码（生成触发按钮 + 模态框结构）
     */
    public function render_modal_button($atts, $content = null) {
        $atts = shortcode_atts(array(
            'text' => '📝 申请友链',
            'class' => '',
        ), $atts);
        $button_text = esc_html($atts['text']);
        $button_class = esc_attr($atts['class']);
        $nonce = wp_create_nonce('self_fl_submit_nonce');
        ob_start();
        ?>
        <button class="self-fl-modal-trigger <?php echo $button_class; ?>"><?php echo $button_text; ?></button>
        <div id="self-fl-modal-overlay" class="self-fl-modal-overlay">
            <div class="self-fl-modal-container">
                <span class="self-fl-modal-close">&times;</span>
                <div id="self-fl-modal-message" class="self-fl-message" style="display:none;"></div>
                <form id="self-fl-modal-form">
                    <input type="hidden" name="self_fl_nonce" value="<?php echo esc_attr($nonce); ?>">
                    <div class="form-group"><label>网站名称 <span class="required-star">*</span></label><input type="text" id="modal_site_name" placeholder="例如：并非正文" required></div>
                    <div class="form-group"><label>网站链接 <span class="required-star">*</span></label><input type="url" id="modal_site_url" placeholder="https://yourdomain.com" required><small>请输入带 http:// 或 https:// 的链接！</small></div>
                    <div class="form-group"><label>站点 Logo <span class="required-star">*</span></label><input type="url" id="modal_site_logo" placeholder="https://yourdomain.com/logo.png" required></div>
                    <div class="form-group"><label>描述 <span class="required-star">*</span></label><textarea id="modal_description" rows="3" placeholder="请输入简介" required></textarea></div>
                    <div class="form-group"><label>RSS 地址</label><input type="url" id="modal_rss" placeholder="https://yourdomain.com/feed.xml"><small>可选，请填写有效的 RSS 地址</small></div>
                    <div class="form-actions"><button type="submit" class="btn-submit">📨 提交申请<span class="spinner"></span></button><button type="reset" class="btn-reset">🔄 重提</button></div>
                    <div class="required-tip"><span class="required-star">*</span> 提示：带有浅蓝色星号 * 的项，表示必填项～</div>
                </form>
            </div>
        </div>
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
            'taxonomy' => 'link_category',
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
                // 仅获取 link_visible = 'Y' 的链接
                'hide_invisible' => true,
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
        @media (max-width: 640px) { .friend-link-list { grid-template-columns: 1fr; } }
        </style>';
        return $output;
    }

    /**
     * AJAX 处理表单提交（独立页面和模态框共用） - 写入 WordPress 内置链接管理器（防止重复提交）
     */
    public function handle_ajax_submit() {
        // 验证 Nonce
        check_ajax_referer('self_fl_submit_nonce', 'nonce');

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

        if ($exists) wp_send_json_error('该网站链接已提交过申请，请勿重复提交。');

        // 确保「待审核友链」分类存在
        $pending_cat_id = $this->ensure_pending_category();
        if (is_wp_error($pending_cat_id) || !is_numeric($pending_cat_id) || $pending_cat_id <= 0) {
            wp_send_json_error('系统错误：待审核分类无效，请检查数据库。');
        }

        // 构建链接数据（使用 WordPress 原生函数 wp_insert_link）
        $link_data = array(
            'link_name' => $site_name,    // 网站名称（不添加前缀）
            'link_url' => $site_url,
            'link_image' => $site_logo,
            'link_description' => $description,
            'link_rss' => $rss,
            'link_visible' => 'N',        // 'N' = 不可见，待审核状态
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
            error_log('Self FriendLink: 关联「待审核友链」失败 - ' . $term_result->get_error_message());
        }

        // 发送邮件通知管理员
        $this->send_admin_notification($link_id, $site_name, $site_url, $site_logo, $description, $rss);

        wp_send_json_success('申请已提交！会尽快添加o(*￣▽￣*)ブ。');
    }

    /**
     * 发送邮件通知管理员（含快速审核链接）
     */
    private function send_admin_notification($link_id, $site_name, $site_url, $site_logo, $description, $rss) {
        $admin_email = get_option('admin_email');
        $edit_url    = admin_url('link.php?action=edit&link_id=' . $link_id);            // 编辑该链接
        $approve_url = admin_url('admin-post.php?action=self_fl_approve&link_id=' . $link_id . '&_wpnonce=' . wp_create_nonce('self_fl_manage_' . $link_id));
        $delete_url  = admin_url('admin-post.php?action=self_fl_delete&link_id=' . $link_id . '&_wpnonce=' . wp_create_nonce('self_fl_manage_' . $link_id));
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
            <p align='center' style='color:#6c757d; font-size:12px;'>—— 此邮件由 Self FriendLink Apply 插件自动发送 ——</p>
        </div>
        </body></html>";

        wp_mail($admin_email, $subject, $message, array('Content-Type: text/html; charset=UTF-8'));
    }

    /**
     * 处理审核通过：将链接设为可见，并移出「待审核友链」分类，移入「网线空间」分类
     */
    public function handle_approve() {
        // if (!current_user_can('manage_links')) wp_die('权限不足，无法执行此操作');
        // 第 1 步：获取请求参数

        $link_id = isset($_POST['link_id']) ? intval($_POST['link_id']) : (isset($_GET['link_id']) ? intval($_GET['link_id']) : 0);
        $nonce = isset($_POST['_wpnonce']) ? $_POST['_wpnonce'] : (isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '');
    
        // 如果没有提供必要的参数，则直接报错
        if (!$link_id || !$nonce) {
            wp_die('无效请求：缺少必要的参数。');
        }
    
        // 第 2 步：检查用户状态并引导
        // 如果用户未登录，auth_redirect() 会自动保存当前链接，然后跳转到登录页。
        // 登录成功后，用户会被自动带回这个链接，此时用户已登录，代码会继续向下执行。
        if (!is_user_logged_in()) {
            auth_redirect();
        }
    
        // 第 3 步：权限检查（此时用户肯定已经登录）
        if (!current_user_can('manage_links')) {
            wp_die('权限不足，无法执行此操作。');
        }
    
        // 第 4 步：验证目标链接是否存在
        global $wpdb;
        $link_exists = $wpdb->get_var($wpdb->prepare("SELECT link_id FROM {$wpdb->links} WHERE link_id = %d", $link_id));
        if (!$link_exists) {
            wp_die('链接不存在，可能已被删除。');
        }
    
        // 第 5 步：验证 nonce（安全令牌）
        // 由于用户可能经过登录流程，其会话信息已变化，导致 nonce 可能失效。
        // 这里进行验证，但如果失败只记录日志，不阻断操作，以提升用户体验。
        // if (!$link_id || !wp_verify_nonce($nonce, 'self_fl_manage_' . $link_id)) wp_die('无效请求，请刷新页面后重试。');
        if (!wp_verify_nonce($nonce, 'self_fl_manage_' . $link_id)) {
            error_log("Self FriendLink 警告: 审批 nonce 验证失败，但管理员已登录，操作将继续。链接ID: {$link_id}");
        }
    
        // 第 6 步：执行审批操作
        // 获取用户选择的分类，如果没有则使用默认分类（网线空间）
        // $target_cat_id = isset($_POST['target_category']) ? intval($_POST['target_category']) : 0;
        $target_cat_id = isset($_GET['target_category']) ? intval($_GET['target_category']) : 0;
        if ($target_cat_id <= 0) {
            // 如果没有传入，则使用默认分类（网线空间）
            $target_cat_id = $this->ensure_approved_category();
        }

        require_once(ABSPATH . 'wp-admin/includes/bookmark.php');

        // 1. 更新链接可见性为 'Y'
        $update_data = array(
            'link_id' => $link_id,
            'link_visible' => 'Y',
        );
        wp_update_link($update_data);

        // 可选：从「待审核友链」分类中移出（保留在其它分类或不做处理）
        // 2. 更新至「网线空间」分类（如果指定了分类）
        if (!is_wp_error($target_cat_id) && $target_cat_id > 0) {
            wp_set_object_terms($link_id, (int) $target_cat_id, 'link_category', false);
        }

        // 重定向回待审核页面，并携带成功消息
        $redirect_url = admin_url('link-manager.php?page=self-fl-pending');
        wp_redirect(add_query_arg('message', 'approved', $redirect_url));
        exit;
    }

    /**
     * 处理删除申请：永久删除链接
     */
    public function handle_delete() {
        $link_id = isset($_GET['link_id']) ? intval($_GET['link_id']) : 0;
        $nonce   = isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '';
    
        if (!$link_id || !$nonce) {
            wp_die('无效请求：缺少必要的参数。');
        }
    
        // 未登录则先引导登录
        if (!is_user_logged_in()) {
            auth_redirect();
        }
    
        if (!current_user_can('manage_links')) {
            wp_die('权限不足，无法执行此操作。');
        }
    
        global $wpdb;
        $link_exists = $wpdb->get_var($wpdb->prepare("SELECT link_id FROM {$wpdb->links} WHERE link_id = %d", $link_id));
        if (!$link_exists) {
            wp_die('链接不存在，可能已被删除。');
        }

        // if (!wp_verify_nonce($nonce, 'self_fl_manage_' . $link_id)) wp_die('无效请求，请刷新页面后重试。');
        if (!wp_verify_nonce($nonce, 'self_fl_manage_' . $link_id)) {
            error_log("Self FriendLink 警告: 删除 nonce 验证失败，但管理员已登录，操作将继续。链接ID: {$link_id}");
        }

        require_once(ABSPATH . 'wp-admin/includes/bookmark.php');

        $result = wp_delete_link($link_id);

        if ($result === false) wp_die('删除链接失败，请稍后再试。');

        $redirect_url = admin_url('link-manager.php?page=self-fl-pending');
        wp_redirect(add_query_arg('message', 'deleted', $redirect_url));
        exit;
    }

    public function handle_bulk_approve() {
        if (!current_user_can('manage_links')) wp_die('权限不足');
        if (!isset($_POST['bulk_nonce']) || !wp_verify_nonce($_POST['bulk_nonce'], 'self_fl_bulk_action')) wp_die('无效请求');

        $link_ids = isset($_POST['link_ids']) ? array_map('intval', (array) $_POST['link_ids']) : array();
        $target_cat_id = isset($_POST['bulk_category']) ? intval($_POST['bulk_category']) : 0;
        if ($target_cat_id <= 0) $target_cat_id = $this->ensure_approved_category();

        require_once(ABSPATH . 'wp-admin/includes/bookmark.php');
        foreach ($link_ids as $link_id) {
            wp_update_link(array('link_id' => $link_id, 'link_visible' => 'Y'));
            if (!is_wp_error($target_cat_id) && $target_cat_id > 0) {
                wp_set_object_terms($link_id, (int) $target_cat_id, 'link_category', false);
            }
        }
        wp_redirect(add_query_arg('message', 'bulk_approved', admin_url('admin.php?page=self-fl-pending')));
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
            'self-fl-pending',
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
            'orderby' => 'link_id',
            'order' => 'DESC',
        ));
        // 筛选出 link_visible !== 'Y' 的链接
        $pending_cat_id = $this->ensure_pending_category(); // 待审核分类 ID
        $pending = array();
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
        $approved_cat_id = $this->ensure_approved_category();
        ?>
        <div class="wrap">
            <h1>待审核友链</h1>
            <?php if (isset($_GET['message'])): ?>
                <div class="notice notice-success"><p><?php
                        if ($_GET['message'] === 'approved') echo '✅ 链接已批准并移入选定分类。';
                        elseif ($_GET['message'] === 'bulk_approved') echo '✅ 已批量批准链接。';
                        elseif ($_GET['message'] === 'deleted') echo '🗑️ 链接已删除。';
                ?></p></div>
            <?php endif; ?>
            <?php if (empty($pending)): ?>
                <div class="notice notice-info"><p>🎉 暂无待审核的友链申请！</p></div>
            <?php else: ?>
                <form method="post" action="<?php echo admin_url('admin-post.php?action=self_fl_bulk_approve'); ?>">
                    <?php wp_nonce_field('self_fl_bulk_action', 'bulk_nonce'); ?>
                    <table class="wp-list-table widefat striped">
                        <thead>
                            <tr><th><input type="checkbox" id="select-all"></th><th>ID</th><th>网站名称</th><th>链接地址</th><th>Logo</th><th>简介</th><th>提交时间</th><th>批准后分类</th><th>操作</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($pending as $link):
                            // 生成单个批准使用的 nonce (用于 POST 表单)
                            $single_nonce = wp_create_nonce('self_fl_manage_' . $link->link_id);
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
                                    <select name="single_category_<?php echo $link->link_id; ?>" class="link-category-select" data-link-id="<?php echo $link->link_id; ?>">
                                        <option value="0" <?php selected(0, 0); ?>>请选择分类（默认网线空间）</option>
                                        <?php foreach ($all_categories as $cat): ?>
                                            <option value="<?php echo $cat->term_id; ?>" <?php selected($cat->term_id, $approved_cat_id); ?>><?php echo esc_html($cat->name); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <a href="<?php echo admin_url('link.php?action=edit&link_id=' . $link->link_id); ?>" class="button button-small">✏️ 编辑</a>
                                    <a href="javascript:void(0);" class="button button-small button-primary approve-single" data-link-id="<?php echo $link->link_id; ?>" data-nonce="<?php echo esc_attr($single_nonce); ?>">✅ 批准</a>
                                    <a href="<?php echo admin_url('admin-post.php?action=self_fl_delete&link_id=' . $link->link_id . '&_wpnonce=' . wp_create_nonce('self_fl_manage_' . $link->link_id)); ?>" class="button button-small" style="background:#ef4444; color:white;" onclick="return confirm('确认删除该申请？删除后不可恢复。')">🗑️ 删除</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div style="margin-top:20px;">
                        <label>批量批准后分类：</label>
                        <select name="bulk_category">
                            <option value="0">请选择分类（默认网线空间）</option>
                            <?php foreach ($all_categories as $cat): ?>
                                <option value="<?php echo $cat->term_id; ?>" <?php selected($cat->term_id, $approved_cat_id); ?>><?php echo esc_html($cat->name); ?></option>
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
            .link-category-select { min-width: 138px; }
        </style>
        <script>
        jQuery(document).ready(function($) {
            // 全选/取消全选
            $('#select-all').on('click', function() {
                $('input[name="link_ids[]"]').prop('checked', this.checked);
            });

            // 单个批准：动态创建表单并提交，携带选择的分类
            $('.approve-single').on('click', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var link_id = $btn.data('link-id'), nonce = $btn.data('nonce');
                // 获取当前行中对应的 select 值
                var $row = $btn.closest('tr');
                var category = $row.find('.link-category-select').val();
                var categoryName = $row.find('.link-category-select option:selected').text();

                // 确认对话框，显示选择的分类名称
                if (!confirm('批准后，该友链将公开显示并移入“' + categoryName + '”分类。确认通过？')) {
                    return;
                }

                var form = $('<form method="post" action="<?php echo admin_url('admin-post.php?action=self_fl_approve'); ?>"></form>');
                form.append('<input type="hidden" name="link_id" value="' + link_id + '">');
                form.append('<input type="hidden" name="target_category" value="' + category + '">');
                form.append('<input type="hidden" name="_wpnonce" value="' + nonce + '">');
                $('body').append(form);
                form.submit();
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
        // if (!is_admin() || !isset($_GET['link_visible']) || $_GET['link_visible'] === '' || empty($_GET['link_visible'])) return;
        if (!is_admin() || empty($_GET['link_visible'])) return;
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
new Self_FriendLink_Apply();