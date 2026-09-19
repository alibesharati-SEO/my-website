<?php
/**
 * Plugin Name: Product Description Auditor
 * Plugin URI: https://example.com/product-description-auditor
 * Description: بررسی و دسته‌بندی توضیحات بلند محصولات ووکامرس به‌صورت دسته‌ای (۳۰تایی) برای جلوگیری از فشار به سرور.
 * Version: 1.1.0
 * Author: Jules
 * Text Domain: product-description-auditor
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class Product_Description_Auditor {

    const BATCH_SIZE = 30;

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_csv_export'));
        add_action('wp_ajax_pda_init_audit', array($this, 'ajax_init_audit'));
        add_action('wp_ajax_pda_process_batch', array($this, 'ajax_process_batch'));
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
     * Initialize audit session: retrieve total published products count and product IDs
     */
    public function ajax_init_audit() {
        check_ajax_referer('pda_audit_action', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'عدم دسترسی کافی'));
        }

        // Get all published product IDs and post_content
        $args = array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
        );

        $product_ids = get_posts($args);
        $total = count($product_ids);

        if ($total === 0) {
            wp_send_json_error(array('message' => 'هیچ محصول منتشرشده‌ای یافت نشد.'));
        }

        // Cache pre-computed hashes and long descriptions for similarity comparison
        $all_products_cache = array();
        $hash_counts = array();

        foreach ($product_ids as $id) {
            $post = get_post($id);
            // ONLY check long description (post_content)
            $clean_content = trim(wp_strip_all_tags($post->post_content));
            $clean_content_normalized = preg_replace('/\s+/u', ' ', $clean_content);
            $length = mb_strlen($clean_content_normalized, 'UTF-8');
            $md5 = md5($clean_content_normalized);

            $all_products_cache[$id] = array(
                'id'        => $id,
                'title'     => $post->post_title ? $post->post_title : ('#' . $id),
                'edit_url'  => get_edit_post_link($id, ''),
                'permalink' => get_permalink($id),
                'text'      => $clean_content_normalized,
                'length'    => $length,
                'md5'       => $md5,
            );

            if ($length > 0) {
                if (!isset($hash_counts[$md5])) {
                    $hash_counts[$md5] = 0;
                }
                $hash_counts[$md5]++;
            }
        }

        $session_id = 'pda_session_' . get_current_user_id() . '_' . time();

        set_transient($session_id . '_products', $all_products_cache, 3600);
        set_transient($session_id . '_hashes', $hash_counts, 3600);
        set_transient($session_id . '_results', array(), 3600);

        $total_batches = ceil($total / self::BATCH_SIZE);

        wp_send_json_success(array(
            'session_id'    => $session_id,
            'total_items'   => $total,
            'batch_size'    => self::BATCH_SIZE,
            'total_batches' => $total_batches,
        ));
    }

    /**
     * Process a batch of 30 products
     */
    public function ajax_process_batch() {
        check_ajax_referer('pda_audit_action', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'عدم دسترسی کافی'));
        }

        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
        $batch_index = isset($_POST['batch_index']) ? intval($_POST['batch_index']) : 0;

        $all_products_cache = get_transient($session_id . '_products');
        $hash_counts = get_transient($session_id . '_hashes');
        $current_results = get_transient($session_id . '_results');

        if (!$all_products_cache || !$hash_counts || $current_results === false) {
            wp_send_json_error(array('message' => 'جلسه کاری منقضی شده است. لطفا دوباره بررسی را آغاز کنید.'));
        }

        $product_keys = array_keys($all_products_cache);
        $total_items = count($product_keys);
        $offset = $batch_index * self::BATCH_SIZE;

        $batch_keys = array_slice($product_keys, $offset, self::BATCH_SIZE);
        $batch_results = array();

        foreach ($batch_keys as $id) {
            $item = $all_products_cache[$id];
            $is_copy_or_similar = false;
            $matched_detail = '';

            // Priority 1: Copy / Similar
            if ($item['length'] > 0) {
                // Check exact MD5 hash match
                if (isset($hash_counts[$item['md5']]) && $hash_counts[$item['md5']] > 1) {
                    $is_copy_or_similar = true;
                    $matched_detail = 'کپی دقیق';
                } else {
                    // Compare similarity percentage against all products
                    foreach ($all_products_cache as $other_id => $other_item) {
                        if ($id === $other_id || $other_item['length'] === 0) {
                            continue;
                        }

                        $percent = 0;
                        similar_text($item['text'], $other_item['text'], $percent);

                        if ($percent > 85) {
                            $is_copy_or_similar = true;
                            $matched_detail = 'مشابه (' . round($percent, 1) . '%)';
                            break;
                        }
                    }
                }
            }

            if ($is_copy_or_similar) {
                $status = 'کپی‌شده یا بسیار مشابه (' . $matched_detail . ')';
            } elseif ($item['length'] < 50) {
                // Priority 2: No Description (Long description < 50 chars)
                $status = 'بدون توضیحات';
            } else {
                // Priority 3: Healthy
                $status = 'سالم';
            }

            $res_item = array(
                'id'        => $item['id'],
                'title'     => $item['title'],
                'edit_url'  => $item['edit_url'],
                'permalink' => $item['permalink'],
                'status'    => $status,
            );

            $batch_results[] = $res_item;
            $current_results[] = $res_item;
        }

        set_transient($session_id . '_results', $current_results, 3600);

        $processed_items = min($offset + count($batch_keys), $total_items);
        $is_complete = ($processed_items >= $total_items);

        wp_send_json_success(array(
            'batch_results'   => $batch_results,
            'processed_items' => $processed_items,
            'total_items'     => $total_items,
            'is_complete'     => $is_complete,
            'all_results'     => $is_complete ? $current_results : null,
        ));
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

            $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
            $results = get_transient($session_id . '_results');

            if (!$results) {
                wp_die(__('اطلاعات خروجی یافت نشد یا منقضی شده است. لطفا دوباره بررسی را انجام دهید.', 'product-description-auditor'));
            }

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

        $nonce = wp_create_nonce('pda_audit_action');
        ?>
        <div class="wrap rtl" style="direction: rtl; text-align: right;">
            <h1 style="margin-bottom: 20px;">بررسی توضیحات محصولات (Product Description Auditor)</h1>

            <div class="card" style="max-width: 100%; padding: 20px; margin-bottom: 20px;">
                <p style="font-size: 15px; line-height: 1.8;">
                    این افزونه تمام محصولات منتشرشده ووکامرس را به‌صورت <strong>دسته‌ای (بخش‌های ۳۰تایی)</strong> بررسی کرده و بر اساس <strong>توضیحات بلند</strong>، آن‌ها را به سه گروه دسته‌بندی می‌کند:
                </p>
                <ul style="list-style-type: disc; padding-right: 20px; font-size: 14px; line-height: 1.8;">
                    <li><strong>کپی‌شده یا بسیار مشابه:</strong> محصولات دارای هش MD5 یکسان یا شباهت بیشتر از ۸۵٪ در توضیحات بلند.</li>
                    <li><strong>بدون توضیحات:</strong> محصولات با طول توضیحات بلند کمتر از ۵۰ کاراکتر.</li>
                    <li><strong>سالم:</strong> سایر محصولات دارای توضیحات بلند اختصاصی و کافی.</li>
                </ul>

                <div style="margin-top: 20px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <button type="button" id="pda-start-btn" class="button button-primary button-large">
                        <span class="dashicons dashicons-search" style="vertical-align: middle; margin-left: 5px;"></span>
                        شروع بررسی دسته‌ای
                    </button>

                    <form method="post" action="" id="pda-csv-form" style="display: none;">
                        <input type="hidden" name="pda_nonce" value="<?php echo esc_attr($nonce); ?>">
                        <input type="hidden" name="session_id" id="pda-session-id-input" value="">
                        <button type="submit" name="pda_export_csv" class="button button-secondary button-large">
                            <span class="dashicons dashicons-download" style="vertical-align: middle; margin-left: 5px;"></span>
                            دانلود خروجی CSV
                        </button>
                    </form>
                </div>

                <!-- Progress container -->
                <div id="pda-progress-container" style="display: none; margin-top: 20px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px; font-weight: 600;">
                        <span id="pda-progress-status">در حال آماده‌سازی و دریافت فهرست محصولات...</span>
                        <span id="pda-progress-percent">0%</span>
                    </div>
                    <div style="background-color: #e0e0e0; border-radius: 6px; height: 18px; width: 100%; overflow: hidden;">
                        <div id="pda-progress-bar" style="background-color: #2271b1; width: 0%; height: 100%; transition: width 0.3s ease;"></div>
                    </div>
                </div>
            </div>

            <!-- Results Section -->
            <div id="pda-results-section" style="display: none;">
                <div style="display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap;">
                    <div style="background: #fff; border-right: 4px solid #2271b1; padding: 12px 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-radius: 4px; flex: 1; min-width: 150px;">
                        <span style="display: block; color: #646970; font-size: 13px;">کل محصولات</span>
                        <strong id="pda-stat-total" style="font-size: 20px; color: #1d2327;">0</strong>
                    </div>
                    <div style="background: #fff; border-right: 4px solid #00a32a; padding: 12px 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-radius: 4px; flex: 1; min-width: 150px;">
                        <span style="display: block; color: #646970; font-size: 13px;">محصولات سالم</span>
                        <strong id="pda-stat-healthy" style="font-size: 20px; color: #00a32a;">0</strong>
                    </div>
                    <div style="background: #fff; border-right: 4px solid #d63638; padding: 12px 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-radius: 4px; flex: 1; min-width: 150px;">
                        <span style="display: block; color: #646970; font-size: 13px;">بدون توضیحات</span>
                        <strong id="pda-stat-nodesc" style="font-size: 20px; color: #d63638;">0</strong>
                    </div>
                    <div style="background: #fff; border-right: 4px solid #dba617; padding: 12px 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-radius: 4px; flex: 1; min-width: 150px;">
                        <span style="display: block; color: #646970; font-size: 13px;">کپی شده / مشابه</span>
                        <strong id="pda-stat-copy" style="font-size: 20px; color: #dba617;">0</strong>
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
                    <tbody id="pda-results-tbody">
                    </tbody>
                </table>
            </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var startBtn = document.getElementById('pda-start-btn');
            var csvForm = document.getElementById('pda-csv-form');
            var sessionIdInput = document.getElementById('pda-session-id-input');
            var progressContainer = document.getElementById('pda-progress-container');
            var progressBar = document.getElementById('pda-progress-bar');
            var progressStatus = document.getElementById('pda-progress-status');
            var progressPercent = document.getElementById('pda-progress-percent');
            var resultsSection = document.getElementById('pda-results-section');
            var tbody = document.getElementById('pda-results-tbody');

            var statTotal = document.getElementById('pda-stat-total');
            var statHealthy = document.getElementById('pda-stat-healthy');
            var statNoDesc = document.getElementById('pda-stat-nodesc');
            var statCopy = document.getElementById('pda-stat-copy');

            var nonce = '<?php echo esc_js($nonce); ?>';
            var ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';

            var totalItems = 0;
            var totalBatches = 0;
            var currentBatch = 0;
            var sessionId = '';
            var accumulatedResults = [];

            startBtn.addEventListener('click', function() {
                startBtn.disabled = true;
                csvForm.style.display = 'none';
                resultsSection.style.display = 'none';
                progressContainer.style.display = 'block';
                tbody.innerHTML = '';
                accumulatedResults = [];
                updateProgress(0, 'در حال آماده‌سازی و دریافت اطلاعات محصولات...');

                // Initialize session
                var formData = new FormData();
                formData.append('action', 'pda_init_audit');
                formData.append('nonce', nonce);

                fetch(ajaxUrl, {
                    method: 'POST',
                    body: formData
                })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (!data.success) {
                        alert(data.data.message || 'خطا در برقراری ارتباط');
                        resetUI();
                        return;
                    }

                    sessionId = data.data.session_id;
                    totalItems = data.data.total_items;
                    totalBatches = data.data.total_batches;
                    sessionIdInput.value = sessionId;

                    currentBatch = 0;
                    processNextBatch();
                })
                .catch(function(err) {
                    alert('خطا در اجرای مرحله اول بررسی');
                    resetUI();
                });
            });

            function processNextBatch() {
                var pct = Math.round((currentBatch / totalBatches) * 100);
                updateProgress(pct, 'در حال بررسی دسته ' + (currentBatch + 1) + ' از ' + totalBatches + ' (هر دسته ۳۰ محصول)...');

                var formData = new FormData();
                formData.append('action', 'pda_process_batch');
                formData.append('nonce', nonce);
                formData.append('session_id', sessionId);
                formData.append('batch_index', currentBatch);

                fetch(ajaxUrl, {
                    method: 'POST',
                    body: formData
                })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (!data.success) {
                        alert(data.data.message || 'خطا در پردازش دسته');
                        resetUI();
                        return;
                    }

                    var batchRes = data.data.batch_results;
                    accumulatedResults = accumulatedResults.concat(batchRes);

                    if (!data.data.is_complete) {
                        currentBatch++;
                        processNextBatch();
                    } else {
                        updateProgress(100, 'بررسی تمام محصولات با موفقیت تکمیل شد!');
                        startBtn.disabled = false;
                        csvForm.style.display = 'inline-block';
                        renderResults(data.data.all_results || accumulatedResults);
                    }
                })
                .catch(function(err) {
                    alert('خطا در بررسی دسته‌ای محصولات. لطفاً مجدداً امتحان کنید.');
                    resetUI();
                });
            }

            function updateProgress(percent, text) {
                progressBar.style.width = percent + '%';
                progressPercent.innerText = percent + '%';
                progressStatus.innerText = text;
            }

            function resetUI() {
                startBtn.disabled = false;
                progressContainer.style.display = 'none';
            }

            function renderResults(results) {
                resultsSection.style.display = 'block';
                var healthy = 0, noDesc = 0, copy = 0;

                var html = '';
                results.forEach(function(item) {
                    var badgeStyle = 'background-color: #f0f0f1; color: #3c434a;';
                    if (item.status.indexOf('بدون توضیحات') !== -1) {
                        noDesc++;
                        badgeStyle = 'background-color: #fcf0f1; color: #d63638; border: 1px solid #f8d7da;';
                    } else if (item.status.indexOf('کپی') !== -1 || item.status.indexOf('مشابه') !== -1) {
                        copy++;
                        badgeStyle = 'background-color: #fcf9e8; color: #8a6d3b; border: 1px solid #fbeed5;';
                    } else {
                        healthy++;
                        badgeStyle = 'background-color: #edfaef; color: #00a32a; border: 1px solid #c3e6cb;';
                    }

                    html += '<tr>';
                    html += '<td><strong><a href="' + escapeHtml(item.permalink) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(item.title) + '</a></strong></td>';
                    html += '<td>' + (item.edit_url ? '<a href="' + escapeHtml(item.edit_url) + '" class="button button-small" target="_blank">ویرایش محصول</a>' : '-') + '</td>';
                    html += '<td><span style="display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; ' + badgeStyle + '">' + escapeHtml(item.status) + '</span></td>';
                    html += '</tr>';
                });

                tbody.innerHTML = html;
                statTotal.innerText = results.length;
                statHealthy.innerText = healthy;
                statNoDesc.innerText = noDesc;
                statCopy.innerText = copy;
            }

            function escapeHtml(text) {
                if (!text) return '';
                return text
                    .replace(/&/g, "&amp;")
                    .replace(/</g, "&lt;")
                    .replace(/>/g, "&gt;")
                    .replace(/"/g, "&quot;")
                    .replace(/'/g, "&#039;");
            }
        });
        </script>
        <?php
    }
}

// Initialize the plugin
new Product_Description_Auditor();
