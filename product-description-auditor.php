<?php
/**
 * Plugin Name: Product Description Auditor
 * Plugin URI: https://example.com/product-description-auditor
 * Description: بررسی و دسته‌بندی توضیحات محصولات ووکامرس (بدون توضیحات، کپی/مشابه، سالم)
 * Version: 1.0.0
 * Author: Jules
 * Text Domain: product-description-auditor
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class Product_Description_Auditor {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_csv_export'));
    }

    /**
     * Add menu item to WordPress dashboard
     */
    public function add_admin_menu() {
        add_menu_page(
            'Product Description Auditor',
            'بررسی توضیحات محصولات',
            'manage_options',
            'product-description-auditor',
            array($this, 'render_admin_page'),
            'dashicons-search',
            56
        );
    }

    /**
     * Run audit on published WooCommerce products
     *
     * Priority:
     * 1. Copy/Similar check (MD5 exact match or similar_text > 85%)
     * 2. No Description check (Combined post_content + post_excerpt length < 50 characters)
     * 3. Healthy (Otherwise)
     */
    public static function audit_products() {
        $args = array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        );

        $products = get_posts($args);
        $data = array();

        foreach ($products as $product) {
            $clean_content = trim(wp_strip_all_tags($product->post_content));
            $clean_excerpt = trim(wp_strip_all_tags($product->post_excerpt));

            // Combine short description (post_excerpt) and long description (post_content)
            $full_text = trim($clean_content . ' ' . $clean_excerpt);
            // Normalize internal whitespace
            $full_text_normalized = preg_replace('/\s+/u', ' ', $full_text);

            $data[] = array(
                'id'        => $product->ID,
                'title'     => $product->post_title ? $product->post_title : ('#' . $product->ID),
                'edit_url'  => get_edit_post_link($product->ID),
                'permalink' => get_permalink($product->ID),
                'text'      => $full_text_normalized,
                'length'    => mb_strlen($full_text_normalized, 'UTF-8'),
                'md5'       => md5($full_text_normalized),
                'status'    => '',
            );
        }

        $count = count($data);

        // Pre-calculate hash frequencies for non-empty text
        $hash_counts = array();
        foreach ($data as $item) {
            if ($item['length'] > 0) {
                $h = $item['md5'];
                if (!isset($hash_counts[$h])) {
                    $hash_counts[$h] = 0;
                }
                $hash_counts[$h]++;
            }
        }

        // Determine product categorization
        for ($i = 0; $i < $count; $i++) {
            $is_copy_or_similar = false;
            $matched_detail = '';

            // 1. Check Copy / Similar (Priority 1)
            if ($data[$i]['length'] > 0) {
                // Check exact MD5 hash match
                if (isset($hash_counts[$data[$i]['md5']]) && $hash_counts[$data[$i]['md5']] > 1) {
                    $is_copy_or_similar = true;
                    $matched_detail = 'کپی دقیق';
                } else {
                    // Check similarity percentage via similar_text
                    for ($j = 0; $j < $count; $j++) {
                        if ($i === $j) {
                            continue;
                        }
                        if ($data[$j]['length'] === 0) {
                            continue;
                        }

                        $percent = 0;
                        similar_text($data[$i]['text'], $data[$j]['text'], $percent);

                        if ($percent > 85) {
                            $is_copy_or_similar = true;
                            $matched_detail = 'مشابه (' . round($percent, 1) . '%)';
                            break;
                        }
                    }
                }
            }

            if ($is_copy_or_similar) {
                $data[$i]['status'] = 'کپی‌شده یا بسیار مشابه (' . $matched_detail . ')';
            } elseif ($data[$i]['length'] < 50) {
                // 2. Check No Description (Priority 2)
                $data[$i]['status'] = 'بدون توضیحات';
            } else {
                // 3. Healthy (Priority 3)
                $data[$i]['status'] = 'سالم';
            }
        }

        return $data;
    }

    /**
     * Handle CSV Export HTTP request
     */
    public function handle_csv_export() {
        if (isset($_POST['pda_export_csv'])) {
            if (!current_user_can('manage_options')) {
                wp_die(__('شما اجازه دسترسی به این صفحه را ندارید.', 'product-description-auditor'));
            }

            if (!isset($_POST['pda_nonce']) || !wp_verify_nonce($_POST['pda_nonce'], 'pda_audit_action')) {
                wp_die(__('اعتبارسنجی امنیتی ناموفق بود.', 'product-description-auditor'));
            }

            $results = self::audit_products();

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=product-description-audit-' . date('Y-m-d') . '.csv');
            header('Pragma: no-cache');
            header('Expires: 0');

            $output = fopen('php://output', 'w');

            // Write UTF-8 BOM for Microsoft Excel Persian encoding compatibility
            fwrite($output, "\xEF\xBB\xBF");

            // Write CSV Header Row
            fputcsv($output, array('نام محصول', 'URL محصول', 'وضعیت'));

            // Write Product Data Rows
            foreach ($results as $item) {
                fputcsv($output, array(
                    $item['title'],
                    $item['permalink'],
                    $item['status']
                ));
            }

            fclose($output);
            exit;
        }
    }

    /**
     * Render WordPress Admin Page
     */
    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('شما اجازه دسترسی به این صفحه را ندارید.', 'product-description-auditor'));
        }

        $audit_results = null;

        if (isset($_POST['pda_run_audit'])) {
            if (isset($_POST['pda_nonce']) && wp_verify_nonce($_POST['pda_nonce'], 'pda_audit_action')) {
                $audit_results = self::audit_products();
            } else {
                echo '<div class="notice notice-error"><p>خطای اعتبارسنجی امنیتی. لطفا دوباره تلاش کنید.</p></div>';
            }
        }

        ?>
        <div class="wrap rtl" style="direction: rtl; text-align: right;">
            <h1 style="margin-bottom: 20px;">بررسی توضیحات محصولات (Product Description Auditor)</h1>

            <div class="card" style="max-width: 100%; padding: 20px; margin-bottom: 20px;">
                <p style="font-size: 15px; line-height: 1.8;">
                    این افزونه تمام محصولات منتشرشده ووکامرس را بررسی کرده و بر اساس توضیحات کوتاه و بلند، آن‌ها را به سه گروه دسته‌بندی می‌کند:
                </p>
                <ul style="list-style-type: disc; padding-right: 20px; font-size: 14px; line-height: 1.8;">
                    <li><strong>کپی‌شده یا بسیار مشابه:</strong> محصولات دارای هش MD5 یکسان یا شباهت بیشتر از ۸۵٪.</li>
                    <li><strong>بدون توضیحات:</strong> محصولات با مجموع طول توضیحات کمتر از ۵۰ کاراکتر.</li>
                    <li><strong>سالم:</strong> سایر محصولات دارای توضیحات اختصاصی و کافی.</li>
                </ul>

                <form method="post" action="" style="margin-top: 20px; display: flex; gap: 10px; align-items: center;">
                    <?php wp_nonce_field('pda_audit_action', 'pda_nonce'); ?>
                    <button type="submit" name="pda_run_audit" class="button button-primary button-large">
                        <span class="dashicons dashicons-search" style="vertical-align: middle; margin-left: 5px;"></span>
                        شروع بررسی
                    </button>

                    <?php if ($audit_results !== null): ?>
                        <button type="submit" name="pda_export_csv" class="button button-secondary button-large">
                            <span class="dashicons dashicons-download" style="vertical-align: middle; margin-left: 5px;"></span>
                            دانلود خروجی CSV
                        </button>
                    <?php endif; ?>
                </form>
            </div>

            <?php if ($audit_results !== null): ?>
                <?php
                $total_count = count($audit_results);
                $healthy_count = 0;
                $no_desc_count = 0;
                $copy_count = 0;

                foreach ($audit_results as $item) {
                    if (strpos($item['status'], 'بدون توضیحات') !== false) {
                        $no_desc_count++;
                    } elseif (strpos($item['status'], 'کپی') !== false || strpos($item['status'], 'مشابه') !== false) {
                        $copy_count++;
                    } else {
                        $healthy_count++;
                    }
                }
                ?>

                <div style="display: flex; gap: 15px; margin-bottom: 20px;">
                    <div style="background: #fff; border-right: 4px solid #2271b1; padding: 12px 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-radius: 4px; flex: 1;">
                        <span style="display: block; color: #646970; font-size: 13px;">کل محصولات</span>
                        <strong style="font-size: 20px; color: #1d2327;"><?php echo esc_html($total_count); ?></strong>
                    </div>
                    <div style="background: #fff; border-right: 4px solid #00a32a; padding: 12px 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-radius: 4px; flex: 1;">
                        <span style="display: block; color: #646970; font-size: 13px;">محصولات سالم</span>
                        <strong style="font-size: 20px; color: #00a32a;"><?php echo esc_html($healthy_count); ?></strong>
                    </div>
                    <div style="background: #fff; border-right: 4px solid #d63638; padding: 12px 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-radius: 4px; flex: 1;">
                        <span style="display: block; color: #646970; font-size: 13px;">بدون توضیحات</span>
                        <strong style="font-size: 20px; color: #d63638;"><?php echo esc_html($no_desc_count); ?></strong>
                    </div>
                    <div style="background: #fff; border-right: 4px solid #dba617; padding: 12px 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-radius: 4px; flex: 1;">
                        <span style="display: block; color: #646970; font-size: 13px;">کپی شده / مشابه</span>
                        <strong style="font-size: 20px; color: #dba617;"><?php echo esc_html($copy_count); ?></strong>
                    </div>
                </div>

                <table class="wp-list-table widefat fixed striped table-view-list" style="margin-top: 10px;">
                    <thead>
                        <tr>
                            <th scope="col" style="width: 40%; font-weight: bold;">نام محصول</th>
                            <th scope="col" style="width: 25%; font-weight: bold;">لینک ویرایش محصول</th>
                            <th scope="col" style="width: 35%; font-weight: bold;">وضعیت</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($audit_results)): ?>
                            <tr>
                                <td colspan="3" style="text-align: center; padding: 20px;">هیچ محصول منتشرشده‌ای یافت نشد.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($audit_results as $item): ?>
                                <?php
                                $badge_style = 'background-color: #f0f0f1; color: #3c434a;';
                                if (strpos($item['status'], 'بدون توضیحات') !== false) {
                                    $badge_style = 'background-color: #fcf0f1; color: #d63638; border: 1px solid #f8d7da;';
                                } elseif (strpos($item['status'], 'کپی') !== false || strpos($item['status'], 'مشابه') !== false) {
                                    $badge_style = 'background-color: #fcf9e8; color: #8a6d3b; border: 1px solid #fbeed5;';
                                } elseif ($item['status'] === 'سالم') {
                                    $badge_style = 'background-color: #edfaef; color: #00a32a; border: 1px solid #c3e6cb;';
                                }
                                ?>
                                <tr>
                                    <td>
                                        <strong>
                                            <a href="<?php echo esc_url($item['permalink']); ?>" target="_blank" rel="noopener noreferrer">
                                                <?php echo esc_html($item['title']); ?>
                                            </a>
                                        </strong>
                                    </td>
                                    <td>
                                        <?php if ($item['edit_url']): ?>
                                            <a href="<?php echo esc_url($item['edit_url']); ?>" class="button button-small" target="_blank">
                                                ویرایش محصول
                                            </a>
                                        <?php else: ?>
                                            <span style="color: #8c8f94;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span style="display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; <?php echo $badge_style; ?>">
                                            <?php echo esc_html($item['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}

// Initialize the plugin
new Product_Description_Auditor();
