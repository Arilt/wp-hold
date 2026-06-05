<?php
/**
 * Plugin Name: Argon 404 Page
 * Plugin URI: https://github.com/iGocsen/wp-hold/tree/main/notbody_plugins/argon-404-page
 * Description: 仿 Argon 主题风格的 404 错误页面，包含返回上一页和回到首页按钮
 * Version: 1.0.2
 * Author: 小崽安
 * Author URI: https://bfzw.top
 * License: GPL-3.0
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: argon-404
 * Requires at least: 5.0
 * Requires PHP: 7.2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Argon_404_Page {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // ✅ 仅在 404 页面注入样式
        add_action( 'wp_head', array( $this, 'inject_styles' ), 999 );
        // ✅ 安全的模板替换（不破坏 WordPress 核心）
        add_action( 'template_redirect', array( $this, 'handle_404_template' ) );
        add_filter( 'body_class', array( $this, 'add_body_class' ) );
    }

    /**
     * ✅ 修复1：仅在 404 页面注入样式（现在仅加载 CSS 文件）
     */
    public function inject_styles() {
        if ( ! is_404() ) {
            return; // 非 404 页面直接退出
        }
        ?>
        <link rel="stylesheet" href="<?php echo plugin_dir_url( __FILE__ ); ?>style.css">
        <?php
    }

    /**
     * ✅ 修复2：安全的模板替换（不使用 remove_all_actions）
     */
    public function handle_404_template() {
        if ( ! is_404() ) {
            return;
        }

        // 仅替换模板，不破坏 WordPress 核心
        add_filter( 'template_include', array( $this, 'load_404_template' ), 999 );
        
        // 确保发送 404 状态码
        status_header( 404 );
        nocache_headers();
    }

    /**
     * 加载自定义 404 模板
     */
    public function load_404_template( $template ) {
        if ( is_404() ) {
            $custom_template = plugin_dir_path( __FILE__ ) . '404-template.php';
            if ( file_exists( $custom_template ) ) {
                return $custom_template;
            }
        }
        return $template;
    }

    /**
     * ✅ 修复3：仅在 404 页面添加 body class
     */
    public function add_body_class( $classes ) {
        if ( is_404() ) {
            $classes[] = 'a404-page';
        }
        return $classes;
    }
}

// 初始化
Argon_404_Page::get_instance();
