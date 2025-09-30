<?php
/**
 * Plugin Name: Smart Content Pro - AI Content Generator
 * Description: OpenAI GPT API ile tam otomatik içerik üretimi, zamanlama ve yönetim sistemi
 * Version: 1.0.0
 * Author: Smart Tech
 * Text Domain: smart-content-pro
 */

if (!defined('ABSPATH')) { exit; }

class Smart_Content_Pro {
    const VERSION      = '1.0.0';
    const OPT_KEY      = 'smart_content_pro_settings';
    const CRON_PREFIX  = 'scp_task_';
    const LOG_TABLE    = 'scp_logs';
    const STATS_TABLE  = 'scp_stats';
    private $db_version = '1.0';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_pages']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_ajax_scp_preview', [$this, 'ajax_preview']);
        add_action('wp_ajax_scp_generate_keywords', [$this, 'ajax_generate_keywords']);
        add_action('wp_ajax_scp_test_api', [$this, 'ajax_test_api']);
        add_action('wp_ajax_scp_manual_generate', [$this, 'ajax_manual_generate']);
        add_action('wp_ajax_scp_approve_content', [$this, 'ajax_approve_content']);
        
        
        add_action('wp_ajax_scp_run_task_now', [$this, 'ajax_run_task_now']);
 
add_action('rest_api_init', [$this, 'register_rest_routes']);

        


        // Form handler'lar
        add_action('admin_post_scp_add_task',    [$this, 'handle_add_task']);
        add_action('admin_post_scp_delete_task', [$this, 'handle_delete_task']);

        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        add_filter('cron_schedules', [$this, 'add_cron_schedules']);

        $this->init_cron_hooks();
    }


    public function activate() {
        $this->create_tables();
        $this->set_default_options();
        update_option('scp_db_version', $this->db_version);

        $opts = get_option(self::OPT_KEY, []);
        if (!empty($opts['tasks'])) {
            foreach ($opts['tasks'] as $task_id => $task) {
                $this->schedule_task_cron($task_id, $task);
            }
        }
        $this->init_cron_hooks();
    }

    public function deactivate() {
        $opts = get_option(self::OPT_KEY, []);
        if (!empty($opts['tasks'])) {
            foreach ($opts['tasks'] as $task_id => $task) {
                $hook = self::CRON_PREFIX . $task_id;
                $timestamp = wp_next_scheduled($hook, [$task_id]);
                if ($timestamp) {
                    wp_unschedule_event($timestamp, $hook, [$task_id]);
                }
                // Arg'sız kayıt varsa onu da temizle
                $timestamp2 = wp_next_scheduled($hook);
                if ($timestamp2) {
                    wp_unschedule_event($timestamp2, $hook);
                }
            }
        }
    }

    private function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $log_table = $wpdb->prefix . self::LOG_TABLE;
        $sql1 = "CREATE TABLE IF NOT EXISTS $log_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            task_id varchar(50) NOT NULL,
            action varchar(100) NOT NULL,
            status enum('success','error','warning') DEFAULT 'success',
            message text,
            post_id bigint(20) NULL,
            tokens_used int(11) DEFAULT 0,
            cost decimal(10,6) DEFAULT 0.000000,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY task_id (task_id),
            KEY created_at (created_at),
            KEY status (status)
        ) $charset;";

        $stats_table = $wpdb->prefix . self::STATS_TABLE;
        $sql2 = "CREATE TABLE IF NOT EXISTS $stats_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            date date NOT NULL,
            total_posts int(11) DEFAULT 0,
            total_tokens int(11) DEFAULT 0,
            total_cost decimal(10,6) DEFAULT 0.000000,
            model varchar(50) DEFAULT '',
            sector varchar(100) DEFAULT '',
            PRIMARY KEY (id),
            UNIQUE KEY date_model_sector (date, model, sector),
            KEY date (date)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql1);
        dbDelta($sql2);
    }

    private function set_default_options() {
        $defaults = [
            'api_key' => '',
            'model'   => 'gpt-4o-mini',
            'tasks'   => [],
            'sectors' => [
                'evden_eve'  => 'Evden eve nakliyat, şehir içi ve şehirlerarası ev taşıma konularında',
                'ofis_tasima'=> 'Ofis taşıma, kurumsal nakliyat, iş yeri taşımacılığı konularında',
                'parca_yuk'  => 'Parça eşya taşıma, küçük yük taşımacılığı, öğrenci evi taşıma konularında',
                'depolama'   => 'Eşya depolama, geçici depolama, güvenli depo hizmetleri konularında',
                'nakliyat'   => 'Genel nakliyat sektörü, taşımacılık hizmetleri, yük ve lojistik konularında'
            ],
            'keyword_ai_enabled' => true,
            'url_optimization'   => true,
            'auto_tags'          => true,
            'content_quality'    => 'high',
            'internal_linking'   => true,
            'seo_optimization'   => true
        ];
        $current = get_option(self::OPT_KEY, []);
        update_option(self::OPT_KEY, wp_parse_args($current, $defaults));
    }

    public function add_admin_pages() {
        $cap = 'manage_options';
        add_menu_page('Smart Content Pro', 'Smart Content Pro', $cap, 'smart-content-pro', [$this, 'render_dashboard'], 'dashicons-welcome-write-blog', 30);
        add_submenu_page('smart-content-pro', 'Dashboard', 'Dashboard', $cap, 'smart-content-pro', [$this, 'render_dashboard']);
        add_submenu_page('smart-content-pro', 'API Ayarları', 'API Ayarları', $cap, 'scp-api', [$this, 'render_api_settings']);
        add_submenu_page('smart-content-pro', 'Görevler', 'Görevler', $cap, 'scp-tasks', [$this, 'render_tasks']);
        add_submenu_page('smart-content-pro', 'Önizleme', 'Önizleme', $cap, 'scp-preview', [$this, 'render_preview']);
        add_submenu_page('smart-content-pro', 'Loglar', 'Loglar', $cap, 'scp-logs', [$this, 'render_logs']);
        add_submenu_page('smart-content-pro', 'Sektörler', 'Sektörler', $cap, 'scp-sectors', [$this, 'render_sectors']);
        add_submenu_page('smart-content-pro', 'Debug & Test', 'Debug & Test', $cap, 'scp-debug', [$this, 'render_debug']);
          add_submenu_page('smart-content-pro', 'Url', 'Otomotik çalıştırcı', $cap, 'render_debugs', [$this, 'render_debugs']);
          
    }

public function enqueue_admin_assets($hook) {
    if (strpos($hook, 'smart-content') === false && strpos($hook, 'scp-') === false) return;

    wp_enqueue_style('bootstrap-5', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css', [], '5.3.3');
    wp_enqueue_script('bootstrap-5', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js', ['jquery'], '5.3.3', true);

    // 1) admin.js kendi handle'ı ile
    $admin_ver = file_exists(__DIR__.'/admin.js') ? filemtime(__DIR__.'/admin.js') : self::VERSION;
    wp_enqueue_script('scp-admin', plugin_dir_url(__FILE__) . 'admin.js', ['jquery','bootstrap-5'], $admin_ver, true);

    // 2) ajax.js için AYRI handle
    $ajax_ver = file_exists(__DIR__.'/ajax.js') ? filemtime(__DIR__.'/ajax.js') : self::VERSION;
    wp_enqueue_script('scp-ajax', plugin_dir_url(__FILE__) . 'ajax.js', ['jquery','bootstrap-5'], $ajax_ver, true);

    // 3) localize'i ajax.js handle'ına yapın
    wp_localize_script('scp-ajax', 'scp_ajax', [
        'url'   => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('scp_nonce'),
    ]);

    wp_add_inline_style('bootstrap-5', '.scp-wrap{padding:20px}.scp-wrap .card{margin-bottom:20px;border-radius:8px}.scp-wrap .status-badge{padding:4px 8px;border-radius:4px;font-size:0.85em;font-weight:500}.scp-wrap .status-success{background:#d1e7dd;color:#0f5132}.scp-wrap .status-error{background:#f8d7da;color:#842029}.scp-wrap .status-warning{background:#fff3cd;color:#664d03}');
}


    public function register_settings() {
        register_setting('scp_settings_group', self::OPT_KEY);
    }

    public function handle_add_task() {
        if (!current_user_can('manage_options')) wp_die('Yetki yok');
        check_admin_referer('scp_add_task');

        $res = $this->add_task($_POST);
        $notice = is_wp_error($res) ? 'add_error' : 'add_ok';
        $msg = is_wp_error($res) ? $res->get_error_message() : 'Görev eklendi ve zamanlandı';
        
        

        $url = add_query_arg([
            'page'       => 'scp-tasks',
            'scp_notice' => $notice,
            'scp_msg'    => rawurlencode($msg),
        ], admin_url('admin.php'));
        wp_redirect($url);
        exit;
    }

    public function handle_delete_task() {
        if (!current_user_can('manage_options')) wp_die('Yetki yok');
        check_admin_referer('scp_delete_task');

        $this->delete_task(sanitize_text_field($_POST['task_id'] ?? ''));
        $url = add_query_arg([
            'page'       => 'scp-tasks',
            'scp_notice' => 'del_ok',
            'scp_msg'    => rawurlencode('Görev silindi'),
        ], admin_url('admin.php'));
        wp_redirect($url);
        exit;
    }


public function ajax_run_task_now() {
    check_ajax_referer('scp_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Yetki yok');

    $task_id = sanitize_text_field($_POST['task_id'] ?? '');
    if (!$task_id) wp_send_json_error('Geçersiz görev ID');

    $opts = get_option(self::OPT_KEY, []);
    $task = $opts['tasks'][$task_id] ?? null;
    if (!$task) wp_send_json_error('Görev bulunamadı');

    // (Varsa) tarih aralığını denetle
    $today = current_time('Y-m-d');
    if (!empty($task['start_date']) && $today < $task['start_date']) {
        wp_send_json_error('Görev başlangıç tarihine daha var');
    }
    if (!empty($task['end_date']) && $today > $task['end_date']) {
        wp_send_json_error('Görev bitiş tarihini geçti');
    }

    // Aylık limit kontrolü
    $monthly_count = $this->get_task_monthly_count($task_id);
    $monthly_limit = intval($task['monthly_limit'] ?? 30);
    if ($monthly_count >= $monthly_limit) {
        $this->log_activity($task_id, 'manual_run_now_skipped', 'Aylık limit dolu', null, 0, 0, 'warning');
        wp_send_json_error('Aylık limit dolu');
    }

    // Günlük kaç içerik? (planlı çalışmayla aynı mantık)
    $daily_count = max(1, intval($task['daily_count'] ?? 1));
    $remaining   = $monthly_limit - $monthly_count;
    $to_generate = min($daily_count, $remaining);

    $generated = 0;
    $results   = [];

    for ($i = 0; $i < $to_generate; $i++) {
        $res = $this->generate_content_for_task($task);
        if (!empty($res['success'])) {
            $generated++;
            $this->log_activity($task_id, 'manual_run_now', 'Şimdi Çalıştır ile üretildi', $res['post_id'], 0, 0, 'success');
            $results[] = ['success' => true, 'post_id' => $res['post_id'], 'url' => $res['post_url'] ?? ''];
        } else {
            $this->log_activity($task_id, 'manual_run_now_error', $res['message'] ?? 'Bilinmeyen hata', null, 0, 0, 'error');
            $results[] = ['success' => false, 'message' => $res['message'] ?? 'Bilinmeyen hata'];
        }
    }

    wp_send_json_success([
        'generated' => $generated,
        'results'   => $results
    ]);
}


public function register_rest_routes() {
    register_rest_route('scp/v1', '/run', [
        'methods'  => 'POST',
        'callback' => [$this, 'rest_run_task'],
        'permission_callback' => '__return_true', // token ile koruyoruz
        'args' => [
            'task_id' => ['required' => true,  'type' => 'string'],
            'token'   => ['required' => true,  'type' => 'string'],
            'count'   => ['required' => false, 'type' => 'integer'], // opsiyonel: kaç içerik
        ],
    ]);
}

public function rest_run_task(\WP_REST_Request $req) {
    $token   = sanitize_text_field($req->get_param('token'));
    $task_id = sanitize_text_field($req->get_param('task_id'));
    $count   = max(0, intval($req->get_param('count') ?? 0)); // 0 => daily_count kullan

    // Token kontrolü
    if ($token !== $this->get_webhook_token()) {
        return new \WP_REST_Response(['success' => false, 'error' => 'invalid_token'], 403);
    }

    // Görev kontrolü
    $opts = get_option(self::OPT_KEY, []);
    $task = $opts['tasks'][$task_id] ?? null;
    if (!$task) {
        return new \WP_REST_Response(['success' => false, 'error' => 'task_not_found'], 404);
    }

    // Tarih aralığı kontrolü (senin mevcut mantığınla uyumlu)
    $today = current_time('Y-m-d');
    if (!empty($task['start_date']) && $today < $task['start_date']) {
        return new \WP_REST_Response(['success' => false, 'error' => 'not_started_yet'], 409);
    }
    if (!empty($task['end_date']) && $today > $task['end_date']) {
        return new \WP_REST_Response(['success' => false, 'error' => 'task_expired'], 409);
    }

    // Aylık limit
    $monthly_count = $this->get_task_monthly_count($task_id);
    $monthly_limit = intval($task['monthly_limit'] ?? 30);
    if ($monthly_count >= $monthly_limit) {
        $this->log_activity($task_id, 'rest_run_skipped', 'Aylık limit dolu', null, 0, 0, 'warning');
        return new \WP_REST_Response(['success' => false, 'error' => 'monthly_limit_reached'], 409);
    }

    // Kaç içerik üretilecek?
    $daily_count = max(1, intval($task['daily_count'] ?? 1));
    $remaining   = $monthly_limit - $monthly_count;
    $to_generate = min(($count > 0 ? $count : $daily_count), $remaining);

    $generated = 0;
    $results   = [];

    for ($i = 0; $i < $to_generate; $i++) {
        $res = $this->generate_content_for_task($task);
        if (!empty($res['success'])) {
            $generated++;
            $this->log_activity($task_id, 'rest_run', 'REST ile üretildi', $res['post_id'], 0, 0, 'success');
            $results[] = ['success' => true, 'post_id' => $res['post_id'], 'url' => $res['post_url'] ?? ''];
        } else {
            $this->log_activity($task_id, 'rest_run_error', $res['message'] ?? 'Bilinmeyen hata', null, 0, 0, 'error');
            $results[] = ['success' => false, 'message' => $res['message'] ?? 'Bilinmeyen hata'];
        }
    }

    return new \WP_REST_Response([
        'success'   => true,
        'generated' => $generated,
        'results'   => $results
    ], 200);
}


    public function render_dashboard() {
        $stats  = $this->get_dashboard_stats();
        $recent = $this->get_recent_generated_posts(5);
        ?>
        <div class="wrap scp-wrap">
            <div class="container-fluid">
                <h1 class="mb-4">Smart Content Pro Dashboard</h1>
                <div class="row g-3 mb-4">
                    <div class="col-md-3"><div class="card"><div class="card-body"><h3><?php echo number_format_i18n($stats['total_posts']); ?></h3><p class="text-muted mb-0">Toplam İçerik</p></div></div></div>
                    <div class="col-md-3"><div class="card"><div class="card-body"><h3><?php echo number_format_i18n($stats['total_tokens']); ?></h3><p class="text-muted mb-0">Toplam Token</p></div></div></div>
                    <div class="col-md-3"><div class="card"><div class="card-body"><h3>$<?php echo number_format($stats['total_cost'], 4); ?></h3><p class="text-muted mb-0">Toplam Maliyet</p></div></div></div>
                    <div class="col-md-3"><div class="card"><div class="card-body"><h3><?php echo $stats['active_tasks']; ?></h3><p class="text-muted mb-0">Aktif Görev</p></div></div></div>
                </div>
                <div class="row">
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-header"><strong>Son İçerikler</strong></div>
                            <div class="card-body">
                                <?php if (empty($recent)): ?>
                                    <p class="text-muted">Henüz içerik üretilmedi.</p>
                                <?php else: ?>
                                    <div class="list-group list-group-flush">
                                        <?php foreach ($recent as $post): ?>
                                            <div class="list-group-item">
                                                <h6><a href="<?php echo get_permalink($post->ID); ?>" target="_blank"><?php echo esc_html($post->post_title); ?></a></h6>
                                                <small class="text-muted"><?php echo get_the_date('d.m.Y H:i', $post->ID); ?></small>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="card">
                            <div class="card-header"><strong>Hızlı İşlemler</strong></div>
                            <div class="card-body">
                                <button class="btn btn-primary w-100 mb-2 scp-manual-generate">Manuel İçerik Üret</button>
                                <button class="btn btn-secondary w-100 mb-2 scp-test-api">API Test</button>
                                <div id="scp-quick-result" class="mt-3"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }


private function get_webhook_token() {
    $opts = get_option(self::OPT_KEY, []);
    if (empty($opts['webhook_token'])) {
        $opts['webhook_token'] = wp_generate_password(32, false, false);
        update_option(self::OPT_KEY, $opts);
    }
    return $opts['webhook_token'];
}


    public function render_api_settings() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('scp_api_settings')) {
            $this->save_api_settings($_POST);
            echo '<div class="alert alert-success">API ayarları kaydedildi!</div>';
        }
        $opts = get_option(self::OPT_KEY, []);
        $webhook_token = $this->get_webhook_token();

        ?>
        <div class="wrap scp-wrap">
            <div class="container-fluid">
                <h1 class="mb-4">API Ayarları</h1>
                <form method="post">
                    <?php wp_nonce_field('scp_api_settings'); ?>
                    <div class="card mb-3">
                        <div class="card-header"><strong>Temel Ayarlar</strong></div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">OpenAI API Key</label>
                                    <input type="password" name="api_key" class="form-control" value="<?php echo esc_attr($opts['api_key'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Model</label>
                                    <select name="model" class="form-select">
                                        <option value="gpt-4o" <?php selected($opts['model'] ?? '', 'gpt-4o'); ?>>GPT-4o</option>
                                        <option value="gpt-4o-mini" <?php selected($opts['model'] ?? '', 'gpt-4o-mini'); ?>>GPT-4o Mini</option>
                                        <option value="gpt-4" <?php selected($opts['model'] ?? '', 'gpt-4'); ?>>GPT-4</option>
                                        <option value="gpt-3.5-turbo" <?php selected($opts['model'] ?? '', 'gpt-3.5-turbo'); ?>>GPT-3.5 Turbo</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">İçerik Kalitesi</label>
                                    <select name="content_quality" class="form-select">
                                        <option value="high"   <?php selected($opts['content_quality'] ?? '', 'high'); ?>>Yüksek</option>
                                        <option value="medium" <?php selected($opts['content_quality'] ?? '', 'medium'); ?>>Orta</option>
                                        <option value="fast"   <?php selected($opts['content_quality'] ?? '', 'fast'); ?>>Hızlı</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

<div class="card mb-3">
  <div class="card-header"><strong>Webhook / Otomatik Tetikleme</strong></div>
  <div class="card-body">
    <div class="mb-2">
      <label class="form-label">Webhook Token</label>
      <input type="text" class="form-control" value="<?php echo esc_attr($webhook_token); ?>" readonly>
      <div class="form-text">Bu token, REST endpoint’e erişim içindir. Gizli tutun.</div>
    </div>
    <div class="mb-2">
      <code><?php echo esc_html( home_url('/wp-json/scp/v1/run') ); ?></code>
      <div class="form-text">POST isteği: body içinde <code>task_id</code>, <code>token</code> ve opsiyonel <code>count</code>.</div>
    </div>
  </div>
</div>

                    <div class="card mb-3">
                        <div class="card-header"><strong>Ek Özellikler</strong></div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="keyword_ai_enabled" value="1" <?php checked($opts['keyword_ai_enabled'] ?? false); ?>><label class="form-check-label">AI Anahtar Kelime</label></div></div>
                                <div class="col-md-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="url_optimization" value="1" <?php checked($opts['url_optimization'] ?? false); ?>><label class="form-check-label">SEO URL</label></div></div>
                                <div class="col-md-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="auto_tags" value="1" <?php checked($opts['auto_tags'] ?? false); ?>><label class="form-check-label">Otomatik Etiket</label></div></div>
                                <div class="col-md-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="internal_linking" value="1" <?php checked($opts['internal_linking'] ?? false); ?>><label class="form-check-label">İç Bağlantı</label></div></div>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">Ayarları Kaydet</button>
                </form>
            </div>
        </div>
        <?php
    }

    private function save_api_settings($data) {
        $opts = get_option(self::OPT_KEY, []);
        $opts['api_key']           = sanitize_text_field($data['api_key']);
        $opts['model']             = sanitize_text_field($data['model']);
        $opts['content_quality']   = sanitize_text_field($data['content_quality']);
        $opts['keyword_ai_enabled']= !empty($data['keyword_ai_enabled']);
        $opts['url_optimization']  = !empty($data['url_optimization']);
        $opts['auto_tags']         = !empty($data['auto_tags']);
        $opts['internal_linking']  = !empty($data['internal_linking']);
        update_option(self::OPT_KEY, $opts);
    }

public function render_tasks() {
    $opts  = get_option(self::OPT_KEY, []);
    $tasks = $opts['tasks'] ?? [];

    if (!empty($_GET['scp_notice'])) {
        $class = in_array($_GET['scp_notice'], ['add_ok','del_ok']) ? 'success' : 'danger';
        $msg   = isset($_GET['scp_msg']) ? esc_html($_GET['scp_msg']) : (
            $_GET['scp_notice'] === 'add_ok' ? 'Görev eklendi.' :
            ($_GET['scp_notice'] === 'del_ok' ? 'Görev silindi.' : 'İşlem başarısız.')
        );
        echo '<div class="alert alert-' . $class . '">' . $msg . '</div>';
    }
    ?>
    <div class="mt-2 small scp-run-now-result"></div>

    <div class="wrap scp-wrap">
      <div class="container-fluid">
        <h1 class="mb-4">Görev Yönetimi</h1>
        <div class="row">
          <!-- Sol sütun: Yeni Görev -->
          <div class="col-lg-5">
            <div class="card">
              <div class="card-header"><strong>Yeni Görev Ekle</strong></div>
              <div class="card-body">
                <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                  <?php wp_nonce_field('scp_add_task'); ?>
                  <input type="hidden" name="action" value="scp_add_task">

                  <div class="mb-3">
                    <label class="form-label">Görev Adı *</label>
                    <input type="text" name="task_name" class="form-control" required>
                  </div>

                  <div class="mb-3">
                    <label class="form-label">Sektör *</label>
                    <select name="sector" class="form-select" required>
                      <?php foreach ($opts['sectors'] ?? [] as $key => $desc): ?>
                        <option value="<?php echo esc_attr($key); ?>">
                          <?php echo esc_html($key . ' - ' . $desc); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <!-- Firma/Konum/Başlık -->
                  <div class="row g-3 mb-3">
                    <div class="col-md-6">
                      <label class="form-label">Firma Adı (opsiyonel)</label>
                      <input type="text" name="company_name" class="form-control" placeholder="Örn: Uğur Nakliyat">
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">Konum (il/ilçe, opsiyonel)</label>
                      <input type="text" name="location_hint" class="form-control" placeholder="Örn: Maltepe">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="brand_in_title" value="1" id="brand_in_title">
                        <label class="form-check-label" for="brand_in_title">Başlıkta marka</label>
                      </div>
                    </div>
                  </div>

                  <!-- Post type & Sıklık -->
                  <div class="row g-3 mb-3">
                    <div class="col-md-6">
                      <label class="form-label">Post Type</label>
                      <select name="post_type" class="form-select">
                        <?php
                        $preferred = ['post','page','attachment'];
                        $all = get_post_types(['public'=>true], 'objects');
                        foreach ($preferred as $p) {
                          if (isset($all[$p])) {
                            echo '<option value="'.esc_attr($p).'">'.esc_html($all[$p]->labels->name).'</option>';
                            unset($all[$p]);
                          }
                        }
                        foreach ($all as $pt) {
                          echo '<option value="'.esc_attr($pt->name).'">'.esc_html($pt->labels->name).'</option>';
                        }
                        ?>
                      </select>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">Sıklık</label>
                      <select name="frequency" class="form-select">
                        <option value="daily">Günlük</option>
                        <option value="weekly">Haftalık</option>
                        <option value="monthly">Aylık</option>
                      </select>
                    </div>
                  </div>

                  <!-- Tarih aralığı -->
                  <div class="row g-3 mb-3">
                    <div class="col-md-6">
                      <label class="form-label">Başlangıç Tarihi</label>
                      <input type="date" name="start_date" class="form-control" value="<?php echo esc_attr(date('Y-m-d')); ?>">
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">Bitiş Tarihi (opsiyonel)</label>
                      <input type="date" name="end_date" class="form-control">
                    </div>
                  </div>

                  <div class="row g-3 mb-3">
                    <div class="col-md-6">
                      <label class="form-label">Çalışma Saati</label>
                      <input type="time" name="schedule_time" value="09:00" class="form-control">
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">Günlük İçerik Sayısı</label>
                      <input type="number" name="daily_count" value="1" min="1" max="20" class="form-control">
                    </div>
                  </div>

                  <div class="row g-3 mb-3">
                    <div class="col-md-4">
                      <label class="form-label">Min Kelime</label>
                      <input type="number" name="min_words" value="500" min="200" max="5000" class="form-control">
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">Max Kelime</label>
                      <input type="number" name="max_words" value="800" min="200" max="5000" class="form-control">
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">Aylık Limit</label>
                      <input type="number" name="monthly_limit" value="30" min="1" max="500" class="form-control">
                    </div>
                  </div>

                  <div class="mb-3">
                    <label class="form-label">Kategori</label>
                    <select name="category_id" class="form-select">
                      <option value="">Seçiniz...</option>
                      <?php foreach (get_categories() as $cat): ?>
                        <option value="<?php echo $cat->term_id; ?>"><?php echo esc_html($cat->name); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <!-- Anahtar Kaynağı -->
                  <div class="card mb-3">
                    <div class="card-header"><strong>Anahtar Kelime Kaynağı</strong></div>
                    <div class="card-body">
                      <div class="row g-3">
                        <div class="col-md-4">
                          <label class="form-label">Kaynak</label>
                          <select name="keyword_source" class="form-select">
                            <option value="manual">Manuel (aşağıdaki kutu)</option>
                            <option value="ai">AI (model ile üret)</option>
                            <option value="google">Google Autocomplete</option>
                          </select>
                          <div class="form-text">Google seçilirse popüler sorgular çekilir.</div>
                        </div>
                        <div class="col-md-8">
                          <label class="form-label">Anahtar Kelimeler (her satırda bir)</label>
                          <textarea name="keywords" rows="4" class="form-control" placeholder="evden eve nakliyat&#10;maltepe nakliyat&#10;eşya depolama fiyatları"></textarea>
                          <button type="button" class="btn btn-sm btn-secondary mt-2 scp-ai-keywords" data-source="auto">
                            AI / Google ile Öneri Getir
                          </button>
                        </div>
                      </div>
                    </div>
                  </div>

                  <button type="submit" class="btn btn-primary w-100">Görev Ekle</button>
                </form>
              </div>
            </div>
          </div>

          <!-- Sağ sütun: Mevcut Görevler -->
          <div class="col-lg-7">
            <div class="card">
              <div class="card-header"><strong>Mevcut Görevler (<?php echo count($tasks); ?>)</strong></div>
              <div class="card-body">
                <?php if (empty($tasks)): ?>
                  <p class="text-muted">Henüz görev eklenmemiş.</p>
                <?php else: ?>
                  <div class="row g-3">
                    <?php foreach ($tasks as $task_id => $task): ?>
                      <div class="col-md-6">
                        <div class="card">
                          <div class="card-body">
                            <h6><?php echo esc_html($task['name']); ?></h6>
                            <p class="mb-2 small">
                              <span class="badge bg-secondary"><?php echo esc_html($task['sector']); ?></span>
                              <span class="badge bg-info"><?php echo esc_html($task['frequency']); ?></span>
                              <span class="badge bg-light text-dark"><?php echo esc_html($task['time']); ?></span>
                            </p>
                            <p class="mb-1 small">
                              <strong>Firma:</strong> <?php echo esc_html($task['company_name'] ?? '—'); ?><br>
                              <strong>Konum:</strong> <?php echo esc_html($task['location_hint'] ?? '—'); ?><br>
                              <strong>Post Type:</strong> <?php echo esc_html($task['post_type']); ?><br>
                              <strong>Günlük:</strong> <?php echo intval($task['daily_count']); ?> içerik<br>
                              <strong>Kelime:</strong> <?php echo intval($task['min_words']); ?>–<?php echo intval($task['max_words']); ?><br>
                              <strong>Tarih:</strong> <?php echo esc_html(($task['start_date'] ?? '-') . ' → ' . (($task['end_date'] ?? '') ?: 'süresiz')); ?><br>
                              <strong>Bu ay:</strong> <?php echo $this->get_task_monthly_count($task_id); ?>/<?php echo intval($task['monthly_limit']); ?><br>
                              <strong>Sonraki:</strong> <?php echo esc_html($this->get_next_run_time($task_id)); ?>
                            </p>

                            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" class="d-inline">
                              <?php wp_nonce_field('scp_delete_task'); ?>
                              <input type="hidden" name="action" value="scp_delete_task">
                              <input type="hidden" name="task_id" value="<?php echo esc_attr($task_id); ?>">
                              <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Emin misiniz?')">Sil</button>
                            </form>

                            <button type="button" class="btn btn-sm btn-primary scp-run-now" data-task="<?php echo esc_attr($task_id); ?>">
                              Şimdi Çalıştır
                            </button>
                            <div class="mt-2 small scp-run-now-result"></div>
                          </div>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>

        </div>
      </div>
    </div>
    
  <?php
    }
    
   private function add_task($data) {
    if (!current_user_can('manage_options')) {
        return new WP_Error('cap', 'Yetki yok.');
    }
    $opts = get_option(self::OPT_KEY, []);
    if (!isset($opts['tasks'])) $opts['tasks'] = [];

    if (empty($data['task_name'])) return new WP_Error('missing_name', 'Görev adı gerekli.');
    if (empty($data['sector']))    return new WP_Error('missing_sector', 'Sektör seçmelisiniz.');

    $task_id  = 'task_' . uniqid('', true) . '_' . wp_generate_password(6, false, false);
    $keywords = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $data['keywords'] ?? '')));

    // Tarihler
    $start_date = sanitize_text_field($data['start_date'] ?? '');
    $end_date   = sanitize_text_field($data['end_date'] ?? '');
    $tz         = wp_timezone();

    $sd_ok = $start_date && DateTime::createFromFormat('Y-m-d', $start_date, $tz) !== false;
    $ed_ok = $end_date   && DateTime::createFromFormat('Y-m-d', $end_date,   $tz) !== false;
    if ($sd_ok && $ed_ok) {
        $sd = new DateTime($start_date, $tz);
        $ed = new DateTime($end_date,   $tz);
        if ($ed < $sd) { $end_date = ''; }
    }

    $task = [
        'id'            => $task_id,
        'name'          => sanitize_text_field($data['task_name']),
        'sector'        => sanitize_text_field($data['sector']),
        'post_type'     => sanitize_text_field($data['post_type'] ?? 'post'),
        'frequency'     => in_array($data['frequency'] ?? '', ['daily','weekly','monthly']) ? $data['frequency'] : 'daily',
        'time'          => sanitize_text_field($data['schedule_time'] ?? '09:00'),
        'daily_count'   => max(1, intval($data['daily_count'] ?? 1)),
        'monthly_limit' => max(1, intval($data['monthly_limit'] ?? 30)),
        'category_id'   => intval($data['category_id'] ?? 0),
        'min_words'     => max(200, intval($data['min_words'] ?? 500)),
        'max_words'     => max(200, intval($data['max_words'] ?? 800)),
        'keywords'      => $keywords,
        'created_at'    => current_time('mysql'),
        'status'        => 'active',
        'start_date'    => $sd_ok ? $start_date : date('Y-m-d'),
        'end_date'      => $ed_ok ? $end_date   : '',

        // YENİ ALANLAR:
        'company_name'   => sanitize_text_field($data['company_name']   ?? ''),
        'location_hint'  => sanitize_text_field($data['location_hint']  ?? ''),
        'brand_in_title' => !empty($data['brand_in_title']) ? 1 : 0,
        'keyword_source' => in_array(($data['keyword_source'] ?? 'manual'), ['manual','ai','google']) ? $data['keyword_source'] : 'manual',
    ];

    if ($task['min_words'] > $task['max_words']) {
        [$task['min_words'], $task['max_words']] = [$task['max_words'], $task['min_words']];
    }

    $opts['tasks'][$task_id] = $task;
    $saved = update_option(self::OPT_KEY, $opts, false);
    if (!$saved) {
        $this->log_activity($task_id, 'task_create_error', 'update_option başarısız', null, 0, 0, 'error');
        return new WP_Error('save_failed', 'Görev kaydedilemedi.');
    }

    wp_cache_delete(self::OPT_KEY, 'options');

    $this->schedule_task_cron($task_id, $task);
    $this->init_cron_hooks();

    $check  = get_option(self::OPT_KEY, []);
    $exists = isset($check['tasks'][$task_id]);
    $this->log_activity($task_id, 'task_created', 'Görev oluşturuldu: ' . $task['name'] . ' | exists=' . ($exists ? '1':'0'));

    return $task_id;
}


    private function delete_task($task_id) {
        $opts = get_option(self::OPT_KEY, []);
        if (isset($opts['tasks'][$task_id])) {
            unset($opts['tasks'][$task_id]);
            update_option(self::OPT_KEY, $opts);
            $this->unschedule_task($task_id);
            $this->log_activity($task_id, 'task_deleted', 'Görev silindi');
        }
    }

    public function add_cron_schedules($schedules) {
        if (!isset($schedules['weekly']))  $schedules['weekly']  = ['interval' => 7 * DAY_IN_SECONDS,  'display' => __('Once Weekly')];
        if (!isset($schedules['monthly'])) $schedules['monthly'] = ['interval' => 30 * DAY_IN_SECONDS, 'display' => __('Once Monthly')];
        return $schedules;
    }

    private function schedule_task_cron($task_id, $task) {
        $hook = self::CRON_PREFIX . $task_id;

        // Var olan kayıtları temizle (args'lı ve args'sız)
        $ts = wp_next_scheduled($hook, [$task_id]);
        if ($ts) wp_unschedule_event($ts, $hook, [$task_id]);
        $ts2 = wp_next_scheduled($hook);
        if ($ts2) wp_unschedule_event($ts2, $hook);

        // Saat/dakika
        $time_parts = explode(':', $task['time'] ?? '09:00');
        $hour   = intval($time_parts[0]);
        $minute = intval($time_parts[1] ?? 0);

        // İlk çalışma: BAŞLANGIÇ TARİHİNDE belirtilen SAATTE
        $tz        = wp_timezone();
        $now       = new DateTime('now', $tz);
        $start_str = !empty($task['start_date']) ? $task['start_date'] : date('Y-m-d');
        $first_run = DateTime::createFromFormat('Y-m-d H:i:s', $start_str . sprintf(' %02d:%02d:00', $hour, $minute), $tz);

        if (!$first_run) {
            // Güvenli geri dönüş
            $first_run = new DateTime('today ' . $hour . ':' . $minute . ':00', $tz);
        }

        // Başlangıç zamanını geçtiysek frekansa göre ileri al
        if ($first_run <= $now) {
            $freq = in_array($task['frequency'], ['daily','weekly','monthly']) ? $task['frequency'] : 'daily';
            switch ($freq) {
                case 'weekly':  $first_run->modify('+1 week');  break;
                case 'monthly': $first_run->modify('+1 month'); break;
                default:        $first_run->modify('+1 day');   break;
            }
        }

        $recurrence = in_array($task['frequency'], ['daily','weekly','monthly']) ? $task['frequency'] : 'daily';
        wp_schedule_event($first_run->getTimestamp(), $recurrence, $hook, [$task_id]);

        $this->log_activity($task_id, 'task_scheduled',
            'Görev zamanlandı: ' . $first_run->format('Y-m-d H:i:s') . ' | freq=' . $recurrence);
    }

    private function init_cron_hooks() {
        $opts = get_option(self::OPT_KEY, []);
        if (!empty($opts['tasks'])) {
            foreach ($opts['tasks'] as $task_id => $task) {
                $hook = self::CRON_PREFIX . $task_id;
                if (!has_action($hook, [$this, 'execute_scheduled_task'])) {
                    add_action($hook, [$this, 'execute_scheduled_task'], 10, 1);
                }
            }
        }
    }

    public function execute_scheduled_task($task_id) {
        $opts = get_option(self::OPT_KEY, []);
        $task = $opts['tasks'][$task_id] ?? null;
        if (!$task) {
            $this->log_activity($task_id, 'task_error', 'Görev bulunamadı', null, 0, 0, 'error');
            return;
        }

        // === TARİH ARALIĞI KONTROLLERİ ===
        $tz    = wp_timezone();
        $now   = new DateTime('now', $tz);

        // Başlangıç tarihinden önce ise çalışmasın
        if (!empty($task['start_date'])) {
            $start = DateTime::createFromFormat('Y-m-d H:i:s', $task['start_date'].' 00:00:00', $tz);
            if ($start && $now < $start) {
                $this->log_activity($task_id, 'task_skipped', 'Tarih aralığı henüz başlamadı (start=' . $start->format('Y-m-d') . ')', null, 0, 0, 'warning');
                return;
            }
        }

        // Bitiş tarihinden sonra ise cron'u kapat ve görevi expire et
        if (!empty($task['end_date'])) {
            $end = DateTime::createFromFormat('Y-m-d H:i:s', $task['end_date'].' 23:59:59', $tz);
            if ($end && $now > $end) {
                $this->unschedule_task($task_id);
                $opts['tasks'][$task_id]['status'] = 'expired';
                update_option(self::OPT_KEY, $opts);
                $this->log_activity($task_id, 'task_expired', 'Tarih aralığı bitti (end=' . $end->format('Y-m-d') . ')');
                return;
            }
        }
        // === /TARİH ARALIĞI KONTROLLERİ ===

        // Aylık limit
        $monthly_count = $this->get_task_monthly_count($task_id);
        $monthly_limit = intval($task['monthly_limit']);
        if ($monthly_count >= $monthly_limit) {
            $this->log_activity($task_id, 'task_skipped', 'Aylık limit doldu (' . $monthly_count . '/' . $monthly_limit . ')', null, 0, 0, 'warning');
            return;
        }

        // Üretim adedi
        $daily_count = max(1, intval($task['daily_count'] ?? 1));
        $remaining   = $monthly_limit - $monthly_count;
        $to_generate = min($daily_count, $remaining);

        for ($i = 0; $i < $to_generate; $i++) {
            $result = $this->generate_content_for_task($task);
            if ($result['success']) {
                $this->log_activity($task_id, 'content_generated', $result['message'], $result['post_id'], 0, 0, 'success');
            } else {
                $this->log_activity($task_id, 'content_error', $result['message'], null, 0, 0, 'error');
            }
        }
    }

    private function unschedule_task($task_id) {
        $hook = self::CRON_PREFIX . $task_id;
        $ts   = wp_next_scheduled($hook, [$task_id]);
        if ($ts)  wp_unschedule_event($ts, $hook, [$task_id]);
        $ts2  = wp_next_scheduled($hook);
        if ($ts2) wp_unschedule_event($ts2, $hook);
    }

private function generate_content_for_task($task) {
    $opts = get_option(self::OPT_KEY, []);
    if (empty($opts['api_key'])) return ['success' => false, 'message' => 'API key tanımlanmamış'];

    // 1) Anahtar kelime seçimi (var olan mantığınız neyse koruyun)
    $pool = is_array($task['keywords']) ? $task['keywords'] : [];
    $pool = array_values(array_unique(array_map([$this,'normalize_keyword'], $pool)));
    if (empty($pool)) return ['success'=>false,'message'=>'Anahtar kelime bulunamadı'];

    $keyword_raw = $pool[array_rand($pool)];
    $keyword     = $this->sanitize_keyword_for_content($keyword_raw); // fiyat vb. kırp

    // 2) İçerik üret
    $sector_desc = $opts['sectors'][$task['sector']] ?? 'genel';
    $prompts     = $this->build_prompts($keyword, $sector_desc, $task, $opts);
    $model       = $opts['model'] ?? 'gpt-4o-mini';

    $raw_title = $this->call_openai($opts['api_key'], $model, $prompts['title']);
    $excerpt   = $this->call_openai($opts['api_key'], $model, $prompts['excerpt']);
    $content   = $this->call_openai($opts['api_key'], $model, $prompts['content']);

    if (!$raw_title || !$content) return ['success' => false, 'message' => 'İçerik üretilemedi'];

    // 3) Başlığı ve içeriği yasak terimlerden arındır
    if (method_exists($this, 'normalize_title')) {
        $title = $this->normalize_title($raw_title, $keyword, $task);
    } else {
        $title = wp_strip_all_tags($raw_title);
        $title = $this->sanitize_pricing_from_text($title);
        $title = $this->dedupe_location_phrases($title);
    }

    // Marka gövdeden temizle (başlıkta markaya izin yoksa)
    $content = $this->sanitize_brand_mentions($content, $task);

    // Yasak terimler geçen blokları tamamen kaldır
    $content = $this->remove_pricing_from_html($content);

    // 4) Rehber kurallarına göre işle (intro anahtar, H2/H3 anchor, ToC, iç link)
    $post_html = $this->process_content($content, $opts, $keyword);

    // 5) Yazı ekle
    $post_data = [
        'post_title'   => wp_strip_all_tags($title),
        'post_content' => $post_html,
        'post_excerpt' => wp_strip_all_tags($excerpt ? $this->sanitize_pricing_from_text($excerpt) : ''),
        'post_type'    => $task['post_type'],
        'post_status'  => 'publish',
        'meta_input'   => [
            '_scp_keyword_raw' => $keyword_raw,
            '_scp_keyword'     => $keyword,
            '_scp_sector'      => $task['sector'],
            '_scp_task_id'     => $task['id'],
            '_scp_generated_at'=> current_time('mysql'),
            '_scp_company'     => $task['company_name'] ?? '',
            '_scp_location'    => $task['location_hint'] ?? '',
        ]
    ];

    if (!empty($opts['url_optimization'])) {
        $post_data['post_name'] = $this->create_seo_slug($post_data['post_title'], $keyword);
    }
    if (!empty($task['category_id']) && $task['post_type'] === 'post') {
        $post_data['post_category'] = [$task['category_id']];
    }

    $post_id = wp_insert_post($post_data, true);
    if (is_wp_error($post_id)) return ['success' => false, 'message' => 'Post oluşturulamadı: ' . $post_id->get_error_message()];

    if (($opts['auto_tags'] ?? false) && !empty($content)) {
        $tags = $this->generate_tags_from_content($content, $keyword);
        wp_set_post_tags($post_id, $tags);
    }
    if (class_exists('WPSEO_Options')) {
        update_post_meta($post_id, '_yoast_wpseo_title',    $post_data['post_title'] . ' %%sep%% %%sitename%%');
        update_post_meta($post_id, '_yoast_wpseo_metadesc', $post_data['post_excerpt']);
        update_post_meta($post_id, '_yoast_wpseo_focuskw',  $keyword);
    }
    
     if (defined('RANK_MATH_VERSION') || class_exists('\\RankMath\\Helper')) {
        // Rank Math değişken yazımı: %sep%, %sitename%
        update_post_meta($post_id, 'rank_math_title',       $title . ' %sep% %sitename%');
        update_post_meta($post_id, 'rank_math_description', $desc);
      
            update_post_meta($post_id, 'rank_math_focus_keyword', $keyword);
        }
        

    return ['success' => true, 'message' => 'İçerik başarıyla oluşturuldu', 'post_id' => $post_id, 'post_url' => get_permalink($post_id)];
}





private function build_prompts($keyword, $sector_desc, $task, $opts) {
    $min_words = intval($task['min_words'] ?? 700);
    $max_words = intval($task['max_words'] ?? 1500);

    $brand_title_rule = (!empty($task['brand_in_title']) && !empty($task['company_name']))
        ? "Başlıkta marka adı '{$task['company_name']}' kısa ve sonda olabilir baş harfleri büyük yap (örn: '… – {$task['company_name']}')."
        : "Başlıkta şirket/marka adı kullanma.";

    $brand_body_rule = (!empty($task['brand_in_title']) && !empty($task['company_name']))
        ? "Gövde metninde marka adı en fazla bir kez, doğal akışta ve reklam dili olmadan geçebilir."
        : "Gövde metninde hiçbir şekilde şirket/marka adı kullanma.";

    $loc_rule = !empty($task['location_hint'])
        ? "Lokasyon tercihi: '{$task['location_hint']}'. Birden fazla il/ilçe yazma; tek lokasyon kullan."
        : "Lokasyon eklemek zorunlu değil; birden fazla il/ilçe yazma.";

    $title_guard = "Türkçe imla; tırnaksız; 50–60 karakter; clickbait yok; 've Hizmetler' gibi gereksiz son ekleri at.
Örnek yanlış: 'Ataşehir Maltepe Uğur Nakliyat Fiyatları ve Hizmetler' → Doğru: 'Maltepe Nakliyat Fiyatları'.";

    // YASAK KELİMELER: 'fiyat/ücret/maliyet/indirim/teklif/kampanya' + para sembolleri
    $banned = "fiyat, fiyatları, ücret, maliyet, indirim, teklif, kampanya, ucuz, en uygun, hesaplı, ₺, $, €";
    $rewrite_rule = "Bu terimler kullanıcı anahtar kelimesinde geçse bile metne ALMA; gerekirse anahtar kelimeyi güvenli varyantına dönüştür (örn. 'şehirlerarası nakliyat fiyatları' → 'şehirlerarası nakliyat').";

    return [
        'title' => "SEO uyumlu, tırnaksız bir başlık yaz:
- Ana konu: {$keyword} ({$sector_desc})
- {$brand_title_rule}
- {$loc_rule}
- {$title_guard}
- YASAK: {$banned}. {$rewrite_rule}
Yalnızca başlığı döndür.",

        'excerpt' => "{$keyword} için 140–155 karakter aralığında, merak uyandıran meta açıklaması yaz. {$sector_desc} bağlamında. Tırnak kullanma. YASAK: {$banned}. {$rewrite_rule} Sadece metni döndür.",

        'content' => "Aşağıdaki kurallara göre {$min_words}-{$max_words} kelimelik, üst sınıra yakın uzunlukta, tamamı Türkçe bir makale yaz. Konu: {$keyword} ({$sector_desc}).
{$brand_body_rule}
YASAK TERİMLER: {$banned}. {$rewrite_rule}

BAŞLIK HİYERARŞİSİ:
- H1 KULLANMA. H1, WordPress başlığı olacaktır.
- H2: Ana bölümler (6–10 adet). Her H2 altında toplam 200–400 kelime olacak şekilde 2–4 kısa paragraf yaz.
- H3: Gerekli alt konular (uzun kuyruk anahtar kelimeler). Her H2 altında 1–2 H3 tercih sebebi.

YAZIM:
- İlk paragrafta konuyu 1–2 cümleyle özetle ve ana anahtar kelimeyi doğal biçimde ilk 100 kelime içinde bir kez <strong>kalın</strong> geçir. Anahtar yoğunluğu ~%1–%2; anahtar yığma yapma.
- Kısa paragraflar (en fazla 3–4 cümle).
- Bulleted list (ul/li) ile okunabilirliği artır.
- LSI/semantik yakın terimleri doğal dağıt.

SEO:
- İç/dış bağlantı vaadi veren ama marka vermeyen cümleler ekleyebilirsin.
- Görsel/tablo üretme; metin odaklı kal.

E-E-A-T:
- Deneyim temelli küçük ipuçları ver; asılsız istatistik yazma; kesin söz verme.

YAPI:
- Kısa açılış paragrafı.
- 6–10 H2 + gerekli H3'ler.
- 5–8 SSS (H2 altında 'Sıkça Sorulan Sorular' gibi bir H2 veya en sonda ayrı bir H2). YASAK terimler SSS'lerde de geçmesin.

BİÇİM:
- SADECE şu HTML etiketleri: p, h2, h3, ul, li, strong, em.
- Tablo/kod/emoji yok.
- Alıntı ve tırnak yok.

Sadece içerik HTML'ini döndür (başlık ve meta hariç)."
    ];
}







    private function call_openai($api_key, $model, $prompt) {
        $endpoint = 'https://api.openai.com/v1/chat/completions';
        $payload  = [
            'model'    => $model,
            'messages' => [
                ['role' => 'system', 'content' => <<<SCP
Kimliğin: Kıdemli Türkçe içerik stratejisti ve SEO danışmanı.
Hedefin: Kullanıcı niyeti ve arama niyetine tam uyumlu, kurumsal üslupta, özgün ve hatasız içerik üretmek.

Yazım ve Üslup:
- Akıcı, doğal ve güven veren kurumsal ton; gereksiz süsleme ve abartı yok.
- Aktif dil, kısa-paragraflar, açık cümleler; imla ve noktalama hatası yapma.
- Gerekli olduğunda örnek, madde işaretleri ve net çıkarımlar kullan.

Yapı ve Biçim:
- İçeriği mantıklı başlıklar altında kurgula (H2/H3), 2–3 cümlelik paragraflar tercih et.
- Sadece şu HTML etiketlerini kullan: p, h2, ul, li, strong, em. Kod bloğu/emoji ekleme.
- Gerektiğinde 3–5 SSS ve sonuç paragrafı ekle.

SEO İlkeleri:
- Anahtar kelimeyi ilk 100 kelime içinde doğal geçir; anahtar yığma yapma.
- Eşanlamlılar ve semantik varyasyonları dengeli kullan.
- Meta açıklamayı 140–155 karakter aralığında, tırnaksız ve etkileyici yaz.
- İç bağlantı/faydalı kaynak önermek uygunsa kısa ve ilgili tut.

Başlık Kuralları:
- 35–60 karakter aralığı; tırnaksız, net, tıklama tuzağı (clickbait) olmayan form.
- Türkçe imla ve anlam bütünlüğüne dikkat; benzersiz ve çekici olsun.

Gerçeklik ve Güvenilirlik:
- Doğrulanamayan istatistik/söz vermekten kaçın; gerektiğinde ihtiyatlı dil kullan.
- Telif ihlali yapma; özgün ve üretken ol.

Çıktı Disiplini:
- Yalnızca istenen alanları döndür (örn. title, excerpt, content); açıklama ekleme.
- Başlık ve meta çıktılarında tırnak veya gereksiz süs işareti kullanma.
SCP
],
                ['role' => 'user',   'content' => $prompt]
            ],
            'temperature' => 0.8,
            'max_tokens'  => 2000
        ];
        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json'
            ],
            'body'    => wp_json_encode($payload),
            'timeout' => 120
        ];
        $response = wp_remote_post($endpoint, $args);
        if (is_wp_error($response)) {
            $this->log_activity('api_call', 'api_error', 'HTTP Error: ' . $response->get_error_message(), null, 0, 0, 'error');
            return false;
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200 || !isset($body['choices'][0]['message']['content'])) {
            $error_msg = $body['error']['message'] ?? 'Unknown error';
            $this->log_activity('api_call', 'api_error', 'API Error: ' . $error_msg, null, 0, 0, 'error');
            return false;
        }
        $tokens = intval($body['usage']['total_tokens'] ?? 0);
        $cost   = $this->calculate_cost($model, $tokens);
        $this->update_daily_stats($tokens, $cost, $model);

        return trim($body['choices'][0]['message']['content']);
    }





// Google Suggest (Autocomplete) — API’siz popüler sorgular
private function suggest_keywords_from_google($base) {
    $url = 'https://suggestqueries.google.com/complete/search?client=firefox&hl=tr&q=' . rawurlencode($base);
    $res = wp_remote_get($url, ['timeout'=>12, 'headers'=>['Referer'=>home_url()]]);
    if (is_wp_error($res)) return [];
    $code = wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);
    if ($code !== 200 || !$body) return [];
    $json = json_decode($body, true);
    if (!is_array($json) || !isset($json[1]) || !is_array($json[1])) return [];
    $out = [];
    foreach ($json[1] as $s) {
        $s = trim((string)$s);
        if ($s !== '') $out[] = $s;
    }
    return $out;
}

// Anahtar kelime normalizasyonu
public function normalize_keyword($kw) {
    $kw = trim(mb_strtolower($kw, 'UTF-8'));
    $map = [
        'nakliye' => 'nakliyat',
        'ev taşıma' => 'evden eve nakliyat',
        'ev taşıma fiyatları' => 'evden eve nakliyat fiyatları',
        'eşya depolama ve nakliye' => 'eşya depolama',
        'eşya depolama ve taşıma' => 'eşya depolama',
    ];
    foreach ($map as $from=>$to) {
        $kw = preg_replace('/\b'.preg_quote($from,'/').'\b/u', $to, $kw);
    }
    $kw = preg_replace('/\s{2,}/u', ' ', $kw);
    $kw = trim($kw, "-–— \t\n\r\0\x0B");
    return $kw;
}

// Son X günde aynı anahtar kullanıldı mı?
private function has_recent_keyword_usage($keyword, $days = 60) {
    $after = gmdate('Y-m-d H:i:s', time() - $days*DAY_IN_SECONDS);
    $q = new WP_Query([
        'post_type'      => 'any',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'meta_query'     => [['key'=>'_scp_keyword','value'=>$keyword,'compare'=>'=']],
        'date_query'     => [['after' => $after]],
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ]);
    return $q->have_posts();
}

// Başlık düzeltici (lokasyon/marka/çöp ekler)
private function normalize_title($raw_title, $keyword, $task) {
    $t = wp_strip_all_tags((string)$raw_title);
    $t = trim($t, " \t\n\r\0\x0B\"'“”‚‚«»‹›");

    // gereksiz ekler
    $t = preg_replace('/\s+ve\s+hizmetler(i)?\b/iu', '', $t);
    $t = preg_replace('/eşya depolama ve (?:nakliye|taşıma)(\s+(fiyatları|ücretleri))?/iu', 'eşya depolama$1', $t);

    // yasak terimleri temizle
    $t = $this->sanitize_pricing_from_text($t);

    // lokasyon / yinelenen kalıp düzeltme
    $t = $this->dedupe_location_phrases($t);

    // lokasyon zorlaması (tek lokasyon)
    $loc      = trim((string)($task['location_hint'] ?? ''));
    $brand_in = !empty($task['brand_in_title']);
    $company  = trim((string)($task['company_name'] ?? ''));

    if ($loc !== '') {
        $t = preg_replace('/\b([A-ZÇĞİÖŞÜ][a-zçğıöşü]+(?:\s+[A-ZÇĞİÖŞÜ][a-zçğıöşü]+){0,3})\s+(nakliyat|evden eve nakliyat|eşya depolama)\b/iu', $loc.' $2', $t, 1);
    }

    // marka kullanımı
    if (!$brand_in && $company) {
        $t = preg_replace('/\s*[–—-]\s*'.preg_quote($company, '/').'\b/iu', '', $t);
        $t = preg_replace('/\b'.preg_quote($company, '/').'\b/iu', '', $t);
        $t = preg_replace('/\s{2,}/u', ' ', $t);
        $t = trim($t);
    }

    // uzunluk koruması
    $max = 60;
    if (mb_strlen($t,'UTF-8') > $max) {
        $cut = mb_substr($t, 0, $max, 'UTF-8');
        $sp  = mb_strrpos($cut, ' ', 0, 'UTF-8');
        if ($sp !== false) $cut = mb_substr($cut, 0, $sp, 'UTF-8');
        $t = rtrim($cut, "-–—,:;");
    }

    // marka açık ise sonda kısa ek
    if ($brand_in && $company && !preg_match('/\b'.preg_quote($company,'/').'\b/iu', $t) && mb_strlen($t.' – '.$company,'UTF-8') <= 65) {
        $t .= ' – ' . $company;
    }

    return $t;
}



    private function calculate_cost($model, $tokens) {
        $pricing = [
            'gpt-4o'        => 0.005,
            'gpt-4o-mini'   => 0.0001,
            'gpt-4'         => 0.030,
            'gpt-4-turbo'   => 0.010,
            'gpt-3.5-turbo' => 0.002
        ];
        $rate = $pricing[$model] ?? 0.002;
        return ($tokens / 1000) * $rate;
    }

private function process_content($content, $opts, $keyword = '') {
    // Temel temizlik
    $html = wpautop(wp_kses_post($content));

    // H1'leri at
    $html = preg_replace('#<h1[^>]*>.*?</h1>#is', '', $html);

    // (Güvenlik) Yasak terimler içerikte kalmışsa temizle
    $html = $this->remove_pricing_from_html($html);

    // İlk paragrafta anahtarı garanti et (güvenli anahtar kullanılıyor)
    if ($keyword) {
        $html = $this->ensure_keyword_in_intro($html, $keyword);
    }

    // H2/H3 anchor ve ToC
    $html = $this->add_anchor_ids_to_headings($html);
    $html = $this->insert_toc_from_headings($html, 'İçindekiler');

    // İç bağlantılar
    if ($opts['internal_linking'] ?? false) {
        $html = $this->add_internal_links($html);
    }
    return $html;
}


private function ensure_keyword_in_intro($html, $keyword) {
    $kw = trim($keyword);
    if ($kw === '') return $html;

    if (preg_match('#<p>(.*?)</p>#is', $html, $m)) {
        $p = trim($m[1]);
        // çok kısa ise yeni bir cümle ekleme
        if (!preg_match('/'.preg_quote($kw,'/').'/iu', $p) && mb_strlen(strip_tags($p),'UTF-8') > 50) {
            $addon = ' Bu rehber, <strong>'.esc_html($kw).'</strong> üzerine pratik bilgiler sunar.';
            $newp  = $p . $addon;
            return preg_replace('#<p>.*?</p>#is', '<p>'.$newp.'</p>', $html, 1);
        }
    } else {
        // hiç paragraf yoksa başa kısa bir giriş ekle
        $intro = '<p><strong>'.esc_html($kw).'</strong> hakkında aradığınız temel noktaları bu rehberde bulabilirsiniz.</p>';
        $html  = $intro . $html;
    }
    return $html;
}


private function add_anchor_ids_to_headings($html) {
    $cb = function($m){
        $tag = strtolower($m[1]); // h2|h3
        $txt = trim(strip_tags($m[2]));
        if ($txt === '') return '';
        $id  = $this->slugify_heading($txt);
        return '<'.$tag.' id="'.$id.'">'.$txt.'</'.$tag.'>';
    };
    $html = preg_replace_callback('#<(h2)\b[^>]*>(.*?)</h2>#is', $cb, $html);
    $html = preg_replace_callback('#<(h3)\b[^>]*>(.*?)</h3>#is', $cb, $html);
    return $html;
}

private function insert_toc_from_headings($html, $title = 'İçindekiler') {
    if (!preg_match_all('#<(h2)\s+id="([^"]+)">(.+?)</h2>#is', $html, $h2s, PREG_SET_ORDER)) {
        return $html;
    }
    $items = [];
    foreach ($h2s as $h) {
        $items[] = '<li><a href="#'.esc_attr($h[2]).'">'.esc_html(strip_tags($h[3])).'</a></li>';
    }
    if (!$items) return $html;

    $toc = '<nav class="scp-toc" aria-label="İçindekiler"><h2>'.$title.'</h2><ul>'.implode('', $items).'</ul></nav>';

    // ilk paragraftan hemen sonra ekle
    if (strpos($html, '</p>') !== false) {
        return preg_replace('/<\/p>/i', '</p>'.$toc, $html, 1);
    }
    return $toc . $html;
}

private function slugify_heading($text) {
    $tr_map = ['ç'=>'c','ğ'=>'g','ı'=>'i','ö'=>'o','ş'=>'s','ü'=>'u','Ç'=>'c','Ğ'=>'g','İ'=>'i','Ö'=>'o','Ş'=>'s','Ü'=>'u'];
    $s = strtr($text, $tr_map);
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9\s\-]/', '', $s);
    $s = preg_replace('/\s+/', '-', trim($s));
    $s = preg_replace('/-+/', '-', $s);
    return $s ?: 'bolum';
}

private function sanitize_brand_mentions($html, $task) {
    $company = trim((string)($task['company_name'] ?? ''));
    if (!$company || !empty($task['brand_in_title'])) return $html; // başlıkta markaya izin verilmişse gövdede 1 defa bırakabiliriz (basitleştirme: temizlemiyoruz)
    $pattern = '/\b' . preg_quote($company, '/') . '\b/iu';
    $clean = preg_replace($pattern, '', $html);
    $clean = preg_replace('/\s{2,}/u', ' ', $clean);
    $clean = preg_replace('#<p>\s*</p>#i', '', $clean);
    return trim($clean);
}



 





private function create_seo_slug($title, $keyword) {
    $title = $this->sanitize_pricing_from_text($title);
    $keyword = $this->sanitize_keyword_for_content($keyword);

    $slug   = sanitize_title($title);
    $tr_map = ['ç'=>'c','ğ'=>'g','ı'=>'i','ö'=>'o','ş'=>'s','ü'=>'u','Ç'=>'c','Ğ'=>'g','İ'=>'i','Ö'=>'o','Ş'=>'s','Ü'=>'u'];
    $slug   = strtr($slug, $tr_map);
    $slug   = strtolower(preg_replace('/[^a-z0-9\-]/', '', $slug));
    $slug   = preg_replace('/-+/', '-', trim($slug, '-'));

    // "tum-turkiye-...-tum-turkiye-icin" vb. düzelt
    $slug   = preg_replace('/\b(tum-turkiye)(?:-[a-z0-9\-]+)*-(tum-turkiye-icin)\b/', 'tum-turkiye', $slug);
    $slug   = str_replace('icin', '', $slug);
    $slug   = preg_replace('/--+/', '-', $slug);
    $slug   = trim($slug, '-');

    // max uzunluk
    if (strlen($slug) > 60) {
        $slug = substr($slug, 0, 60);
        $pos  = strrpos($slug, '-');
        if ($pos) $slug = substr($slug, 0, $pos);
    }

    $kw_slug = sanitize_title(strtr($keyword, $tr_map));
    if ($kw_slug && strpos($slug, $kw_slug) === false && strlen($slug) + strlen($kw_slug) + 1 <= 60) {
        $slug = $kw_slug . '-' . $slug;
    }
    // tekrar yasak kırpması
    $slug = preg_replace('/\b(fiyat|ucret|maliyet|indirim|teklif|kampanya|ucuz|en-uygun|hesapli)\b/', '', $slug);
    $slug = preg_replace('/--+/', '-', $slug);
    $slug = trim($slug, '-');

    return $slug ?: 'icerik';
}


    private function generate_tags_from_content($content, $keyword) {
        $text  = strtolower(wp_strip_all_tags($content));
        $words = str_word_count($text, 1, 'ğüşiöçı');
        $stopwords = ['bir','bu','da','de','den','için','ile','kadar','olan','ve','veya','daha','en','çok'];
        $freq  = array_count_values($words);
        foreach ($stopwords as $stop) unset($freq[$stop]);
        $freq  = array_filter($freq, function($word){ return strlen($word) > 3; }, ARRAY_FILTER_USE_KEY);
        arsort($freq);
        $tags = array_slice(array_keys($freq), 0, 8);
        if ($keyword && !in_array(mb_strtolower($keyword), array_map('mb_strtolower', $tags))) array_unshift($tags, $keyword);
        return array_slice($tags, 0, 10);
    }

    private function add_internal_links($content) {
        $posts = get_posts([
            'numberposts' => 20,
            'post_status' => 'publish',
            'meta_query'  => [
                ['key' => '_scp_keyword', 'compare' => 'EXISTS']
            ]
        ]);
        if (empty($posts)) return $content;

        foreach ($posts as $post) {
            $keyword = get_post_meta($post->ID, '_scp_keyword', true);
            if (!$keyword) continue;
            $count   = 0;
            $content = preg_replace_callback('/\b' . preg_quote($keyword, '/') . '\b/ui', function($m) use ($post, &$count) {
                if ($count >= 2) return $m[0];
                $count++;
                return '<a href="' . esc_url(get_permalink($post->ID)) . '">' . $m[0] . '</a>';
            }, $content, 2);
        }
        return $content;
    }

    public function render_preview() {
        $opts = get_option(self::OPT_KEY, []);
        ?>
        <div class="wrap scp-wrap">
            <div class="container-fluid">
                <h1 class="mb-4">İçerik Önizleme</h1>
                <div class="card">
                    <div class="card-body">
                        <form id="scp-preview-form">
                            <div class="row g-3 mb-3">
                                <div class="col-md-4">
                                    <label class="form-label">Sektör</label>
                                    <select name="sector" class="form-select" required>
                                        <?php foreach ($opts['sectors'] ?? [] as $key => $desc): ?>
                                            <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($key); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Anahtar Kelime</label>
                                    <input type="text" name="keyword" class="form-control" required placeholder="Örn: evden eve nakliyat">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Model</label>
                                    <select name="model" class="form-select">
                                        <option value="gpt-4o-mini">GPT-4o Mini</option>
                                        <option value="gpt-4o">GPT-4o</option>
                                        <option value="gpt-4">GPT-4</option>
                                    </select>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary">Önizleme Oluştur</button>
                        </form>

                        <div id="scp-preview-result" class="mt-4" style="display:none;">
                            <hr>
                            <div id="scp-preview-content"></div>
                            <button type="button" class="btn btn-success mt-3 scp-approve-preview">İçeriği Onayla ve Yayınla</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_logs() {
        global $wpdb;
        $table    = $wpdb->prefix . self::LOG_TABLE;
        $page     = max(1, intval($_GET['paged'] ?? 1));
        $per_page = 20;
        $offset   = ($page - 1) * $per_page;

        $where  = '1=1';
        $params = [];
        if (!empty($_GET['status']))  { $where .= ' AND status = %s';  $params[] = sanitize_text_field($_GET['status']); }
        if (!empty($_GET['task_id'])) { $where .= ' AND task_id = %s'; $params[] = sanitize_text_field($_GET['task_id']); }

        $total = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE $where", $params));
        $logs  = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE $where ORDER BY created_at DESC LIMIT %d OFFSET %d",
            array_merge($params, [$per_page, $offset])
        ));
        $total_pages = max(1, ceil($total / $per_page));
        ?>
        <div class="wrap scp-wrap">
            <div class="container-fluid">
                <h1 class="mb-4">Sistem Logları</h1>

                <div class="card mb-3">
                    <div class="card-body">
                        <form method="get" class="row g-2">
                            <input type="hidden" name="page" value="scp-logs">
                            <div class="col-md-3">
                                <select name="status" class="form-select">
                                    <option value="">Tüm Durumlar</option>
                                    <option value="success" <?php selected($_GET['status'] ?? '', 'success'); ?>>Başarılı</option>
                                    <option value="error"   <?php selected($_GET['status'] ?? '', 'error'); ?>>Hata</option>
                                    <option value="warning" <?php selected($_GET['status'] ?? '', 'warning'); ?>>Uyarı</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <button type="submit" class="btn btn-primary">Filtrele</button>
                                <a href="<?php echo admin_url('admin.php?page=scp-logs'); ?>" class="btn btn-secondary">Temizle</a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="table-responsive">
                        <table class="table table-striped mb-0">
                            <thead><tr><th>Tarih</th><th>Görev</th><th>İşlem</th><th>Durum</th><th>Mesaj</th><th>Post ID</th></tr></thead>
                            <tbody>
                            <?php if (empty($logs)): ?>
                                <tr><td colspan="6" class="text-center text-muted">Log kaydı bulunamadı.</td></tr>
                            <?php else: foreach ($logs as $log): ?>
                                <tr>
                                    <td><?php echo date('d.m.Y H:i', strtotime($log->created_at)); ?></td>
                                    <td><code><?php echo esc_html($log->task_id); ?></code></td>
                                    <td><?php echo esc_html($log->action); ?></td>
                                    <td><span class="status-badge status-<?php echo esc_attr($log->status); ?>"><?php echo esc_html($log->status); ?></span></td>
                                    <td><?php echo esc_html(mb_strimwidth($log->message, 0, 80, '...')); ?></td>
                                    <td><?php echo $log->post_id ? '<a href="' . get_edit_post_link($log->post_id) . '">#' . $log->post_id . '</a>' : '-'; ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if ($total_pages > 1): ?>
                <nav class="mt-3">
                    <?php echo paginate_links([
                        'base'      => add_query_arg('paged', '%#%'),
                        'format'    => '',
                        'current'   => $page,
                        'total'     => $total_pages,
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;'
                    ]); ?>
                </nav>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function render_sectors() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('scp_sectors')) {
            $opts = get_option(self::OPT_KEY, []);
            if (isset($_POST['save_sectors'])) {
                $text   = wp_unslash($_POST['sectors_text'] ?? '');
                $parsed = $this->parse_sectors_text($text);
                if (!empty($parsed)) {
                    $opts['sectors'] = $parsed;
                    update_option(self::OPT_KEY, $opts);
                    echo '<div class="alert alert-success">Sektörler kaydedildi.</div>';
                } else {
                    echo '<div class="alert alert-danger">Geçerli sektör listesi girilmedi.</div>';
                }
            }
        }
        $opts    = get_option(self::OPT_KEY, []);
        $sectors = $opts['sectors'] ?? [];
        $lines   = [];
        foreach ($sectors as $slug => $desc) $lines[] = $slug . ' - ' . $desc;
        $prefill = implode("\n", $lines);
        ?>
        <div class="wrap scp-wrap">
            <div class="container-fluid">
                <h1 class="mb-4">Sektör Yönetimi</h1>
                <div class="card">
                    <div class="card-body">
                        <form method="post">
                            <?php wp_nonce_field('scp_sectors'); ?>
                            <p class="text-muted">Her satıra bir sektör: <code>slug - açıklama</code></p>
                            <textarea name="sectors_text" rows="12" class="form-control mb-3"><?php echo esc_textarea($prefill); ?></textarea>
                            <button type="submit" name="save_sectors" class="btn btn-primary">Kaydet</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        
        
<?php
    }

public function render_debugs() {
    if (!current_user_can('manage_options')) wp_die('Yetki yok');

    /* ---------- Test Aksiyonları (mevcut) ---------- */
    if (isset($_POST['test_action'])) {
        $action = sanitize_text_field($_POST['test_action']);

        if ($action === 'test_cron') {
            $opts = get_option(self::OPT_KEY, []);
            echo '<div class="alert alert-info"><h4>Cron Test Sonuçları:</h4><strong>Zamanlanmış görevler:</strong><br>';
            foreach ($opts['tasks'] ?? [] as $task_id => $task) {
                $hook = self::CRON_PREFIX . $task_id;
                $next = wp_next_scheduled($hook, [$task_id]);
                if (!$next) $next = wp_next_scheduled($hook);
                echo "- Task: " . esc_html($task['name'] ?? $task_id) . " | Next: " . ($next ? esc_html(date('Y-m-d H:i:s', (int)$next)) : 'YOK') . "<br>";
            }
            echo '</div>';
        }

        if ($action === 'test_db') {
            global $wpdb;
            $log_table   = $wpdb->prefix . self::LOG_TABLE;
            $stats_table = $wpdb->prefix . self::STATS_TABLE;
            echo '<div class="alert alert-info"><h4>Veritabanı Test:</h4>';
            echo "Log tablosu: "   . ($wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $log_table) )   ? 'EVET ✓' : 'HAYIR ✗') . "<br>";
            echo "Stats tablosu: " . ($wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $stats_table) ) ? 'EVET ✓' : 'HAYIR ✗') . "<br>";
            $all = get_option(self::OPT_KEY, []);
            $cnt = isset($all['tasks']) && is_array($all['tasks']) ? count($all['tasks']) : 0;
            echo "Kayıtlı görev sayısı: " . (int)$cnt . "<br></div>";
        }

        if ($action === 'force_task_create') {
            $opts = get_option(self::OPT_KEY, []);
            if (!isset($opts['tasks']) || !is_array($opts['tasks'])) $opts['tasks'] = [];
            $test_task_id = 'task_test_' . time();
            $opts['tasks'][$test_task_id] = [
                'id'            => $test_task_id,
                'name'          => 'Test Görevi',
                'sector'        => array_key_first($opts['sectors'] ?? ['nakliyat' => 'test']),
                'post_type'     => 'post',
                'frequency'     => 'daily',
                'time'          => '10:00',
                'daily_count'   => 1,
                'monthly_limit' => 10,
                'category_id'   => 0,
                'min_words'     => 500,
                'max_words'     => 800,
                'keywords'      => ['test keyword'],
                'created_at'    => current_time('mysql'),
                'status'        => 'active',
                'start_date'    => date('Y-m-d'),
                'end_date'      => ''
            ];
            $updated = update_option(self::OPT_KEY, $opts);
            echo '<div class="alert alert-' . ($updated ? 'success' : 'danger') . '">';
            echo $updated ? 'Test görevi oluşturuldu!' : 'Görev oluşturulamadı!';
            echo '</div>';
        }
    }

    /* ---------- Cron URL Üretici için verileri hazırla ---------- */
    $opts   = get_option(self::OPT_KEY, []);
    $token  = (string)($opts['webhook_token'] ?? '');
    $tasks  = (array)($opts['tasks'] ?? []);
    $count  = isset($_GET['count']) ? max(1, min(10, (int)$_GET['count'])) : 1;
    $sel_id = isset($_GET['task_id']) ? sanitize_text_field((string)$_GET['task_id']) : '';

    // cron.php bu dosyayla aynı klasörde varsayılıyor
    $cron_base = plugins_url('cron.php', __FILE__);

    // seçili göreve ve token'a göre hazır URL'yi oluştur
    $built_url = '';
    if ($sel_id && isset($tasks[$sel_id]) && !empty($token)) {
        $built_url = add_query_arg([
            'token'   => $token,
            'task_id' => $sel_id,
            'count'   => $count,
        ], $cron_base);
    }
    ?>

    <div class="container">
      <h1 class="h3 mb-4">Cron URL Üretici</h1>

      <?php if (empty($token)): ?>
        <div class="alert alert-danger">Webhook token ayarlarda bulunamadı. Lütfen eklenti “API Ayarları” sayfasını açıp kaydedin.</div>
      <?php endif; ?>

      <div class="container mb-3">
        <div class="card-header">URL Oluştur</div>
        <div class="card-body">
          <form method="get" class="row g-3 align-items-end">
            <div class="col-md-7">
              <label class="form-label">Görev Seç</label>
              <select name="task_id" class="form-select" required>
                <option value="">— Seçiniz —</option>
                <?php foreach ($tasks as $id => $t): ?>
                  <option value="<?php echo esc_attr($id); ?>" <?php selected($sel_id, $id); ?>>
                    <?php echo esc_html(($t['name'] ?? 'Görev') . '  |  ID: ' . $id); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">Count</label>
              <input type="number" name="count" min="1" max="10" class="form-control" value="<?php echo esc_attr($count); ?>">
            </div>
            <div class="col-md-3">
              <button class="btn btn-primary w-100" type="submit">URL Üret</button>
            </div>
          </form>

          <?php if ($built_url): ?>
            <hr>
            <label class="form-label">Hazır URL</label>
            <input type="text" class="form-control mb-2" readonly
                   value="<?php echo esc_attr($built_url); ?>">
            <div class="d-flex gap-2">
              <a class="btn btn-outline-secondary" href="<?php echo esc_url($built_url); ?>" target="_blank" rel="noopener">
                Yeni sekmede aç
              </a>
              <button class="btn btn-outline-primary" type="button" onclick="navigator.clipboard.writeText('<?php echo esc_js($built_url); ?>')">
                Kopyala
              </button>
            </div>
            <p class="text-muted mt-2 mb-0"><small>
              Örnek: <code class="small"><?php echo esc_html($cron_base); ?>?token=TOKEN&amp;task_id=TASK_ID&amp;count=1</code>
            </small></p>
          <?php endif; ?>
        </div>
      </div>

      <div class="container">
        <div class="card-header">Görevler</div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
              <thead>
                <tr>
                  <th>Ad</th><th>Sektör</th><th>Sıklık</th><th>Saat</th><th>ID</th><th style="width:1%"></th>
                </tr>
              </thead>
              <tbody>
              <?php if (empty($tasks)): ?>
                <tr><td colspan="6" class="text-center text-muted">Görev bulunamadı.</td></tr>
              <?php else: foreach ($tasks as $id => $t):
                  $row_url = ($token) ? add_query_arg(['token'=>$token,'task_id'=>$id,'count'=>1], $cron_base) : '';
              ?>
                <tr>
                  <td><?php echo esc_html($t['name'] ?? '—'); ?></td>
                  <td><span class="badge bg-secondary"><?php echo esc_html($t['sector'] ?? ''); ?></span></td>
                  <td><span class="badge bg-info text-dark"><?php echo esc_html($t['frequency'] ?? ''); ?></span></td>
                  <td><span class="badge bg-light text-dark"><?php echo esc_html($t['time'] ?? ''); ?></span></td>
                  <td><code class="small"><?php echo esc_html($id); ?></code></td>
                  <td>
                    <?php if ($row_url): ?>
                      <a class="btn btn-sm btn-outline-primary" href="<?php echo esc_url($row_url); ?>" target="_blank" rel="noopener">Çalıştır</a>
                    <?php else: ?>
                      <span class="text-muted small">token yok</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <p class="text-muted mt-3"><small>
        Not: Bu sayfa yalnızca giriş yapmış yöneticilere görünür. URL’ler <code>cron.php</code> dosyasını tetikler.
      </small></p>
    </div>
 
        
        
        
        
        <?php
    }

    public function render_debug() {
        if (!current_user_can('manage_options')) wp_die('Yetki yok');

        if (isset($_POST['test_action'])) {
            $action = sanitize_text_field($_POST['test_action']);

            if ($action === 'test_cron') {
                $opts = get_option(self::OPT_KEY, []);
                echo '<div class="alert alert-info"><h4>Cron Test Sonuçları:</h4><strong>Zamanlanmış görevler:</strong><br>';
                foreach ($opts['tasks'] ?? [] as $task_id => $task) {
                    $hook = self::CRON_PREFIX . $task_id;
                    $next = wp_next_scheduled($hook, [$task_id]);
                    if (!$next) $next = wp_next_scheduled($hook);
                    echo "- Task: {$task['name']} | Next: " . ($next ? date('Y-m-d H:i:s', $next) : 'YOK') . "<br>";
                }
                echo '</div>';
            }

            if ($action === 'test_db') {
                global $wpdb;
                $log_table   = $wpdb->prefix . self::LOG_TABLE;
                $stats_table = $wpdb->prefix . self::STATS_TABLE;
                echo '<div class="alert alert-info"><h4>Veritabanı Test:</h4>';
                echo "Log tablosu: "   . ($wpdb->get_var("SHOW TABLES LIKE '$log_table'")   ? 'EVET ✓' : 'HAYIR ✗') . "<br>";
                echo "Stats tablosu: " . ($wpdb->get_var("SHOW TABLES LIKE '$stats_table'") ? 'EVET ✓' : 'HAYIR ✗') . "<br>";
                echo "Kayıtlı görev sayısı: " . count(get_option(self::OPT_KEY, [])['tasks'] ?? []) . "<br></div>";
            }

            if ($action === 'force_task_create') {
                $opts = get_option(self::OPT_KEY, []);
                if (!isset($opts['tasks'])) $opts['tasks'] = [];
                $test_task_id = 'task_test_' . time();
                $opts['tasks'][$test_task_id] = [
                    'id'            => $test_task_id,
                    'name'          => 'Test Görevi',
                    'sector'        => array_key_first($opts['sectors'] ?? ['nakliyat' => 'test']),
                    'post_type'     => 'post',
                    'frequency'     => 'daily',
                    'time'          => '10:00',
                    'daily_count'   => 1,
                    'monthly_limit' => 10,
                    'category_id'   => 0,
                    'min_words'     => 500,
                    'max_words'     => 800,
                    'keywords'      => ['test keyword'],
                    'created_at'    => current_time('mysql'),
                    'status'        => 'active',
                    'start_date'    => date('Y-m-d'),
                    'end_date'      => ''
                ];
                $updated = update_option(self::OPT_KEY, $opts);
                echo '<div class="alert alert-' . ($updated ? 'success' : 'danger') . '">';
                echo $updated ? 'Test görevi oluşturuldu!' : 'Görev oluşturulamadı!';
                echo '</div>';
            }
        }

        $opts = get_option(self::OPT_KEY, []);
        ?>
        <div class="wrap scp-wrap">
            <div class="container-fluid">
                <h1 class="mb-4">Debug & Test Paneli</h1>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header"><strong>Sistem Bilgileri</strong></div>
                            <div class="card-body">
                                <table class="table table-sm">
                                    <tr><td>PHP:</td><td><?php echo PHP_VERSION; ?></td></tr>
                                    <tr><td>WordPress:</td><td><?php echo get_bloginfo('version'); ?></td></tr>
                                    <tr><td>Plugin:</td><td><?php echo self::VERSION; ?></td></tr>
                                    <tr><td>API Key:</td><td><?php echo !empty($opts['api_key']) ? 'EVET ✓' : 'HAYIR ✗'; ?></td></tr>
                                    <tr><td>Görev Sayısı:</td><td><?php echo count($opts['tasks'] ?? []); ?></td></tr>
                                    <tr><td>Sektör Sayısı:</td><td><?php echo count($opts['sectors'] ?? []); ?></td></tr>
                                    <tr><td>WP Cron:</td><td><?php echo defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? 'HAYIR ✗' : 'EVET ✓'; ?></td></tr>
                                    <tr><td>Timezone:</td><td><?php echo wp_timezone_string(); ?></td></tr>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header"><strong>Hızlı Testler</strong></div>
                            <div class="card-body">
                                <form method="post" class="mb-2"><button type="submit" name="test_action" value="test_cron" class="btn btn-primary w-100">Cron Kontrolü</button></form>
                                <form method="post" class="mb-2"><button type="submit" name="test_action" value="test_db" class="btn btn-info w-100">Veritabanı Kontrolü</button></form>
                                <form method="post" class="mb-2"><button type="submit" name="test_action" value="force_task_create" class="btn btn-warning w-100">Zorla Test Görevi Oluştur</button></form>
                                <a href="<?php echo admin_url('admin.php?page=scp-tasks'); ?>" class="btn btn-secondary w-100">Görevler Sayfası</a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row mt-3">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header"><strong>Tüm Ayarlar (JSON)</strong></div>
                            <div class="card-body">
                                <pre style="max-height:400px;overflow:auto;background:#f5f5f5;padding:15px;"><?php
                                    //echo esc_html(json_encode($opts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                                ?></pre>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private function parse_sectors_text($text) {
        $out   = [];
        $lines = preg_split('/\r\n|\r|\n/', $text);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            if (strpos($line, ' - ') !== false) {
                list($slug, $desc) = explode(' - ', $line, 2);
            } else {
                $slug = $line;
                $desc = $line;
            }
            $slug = trim($slug);
            $desc = trim($desc);
            if ($slug) {
                $slug     = sanitize_title($slug);
                $out[$slug] = $desc;
            }
        }
        return $out;
    }

    public function ajax_test_api() {
        check_ajax_referer('scp_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Yetki yok');
        $opts = get_option(self::OPT_KEY, []);
        if (empty($opts['api_key'])) wp_send_json_error('API key tanımlı değil');
        $result = $this->call_openai($opts['api_key'], $opts['model'], 'Merhaba, bu bir test mesajıdır.');
        if ($result) {
            wp_send_json_success(['message' => 'API bağlantısı başarılı!']);
        } else {
            wp_send_json_error('API bağlantısı başarısız');
        }
    }

    public function ajax_generate_keywords() {
        check_ajax_referer('scp_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Yetki yok');
        $sector = sanitize_text_field($_POST['sector'] ?? '');
        $opts   = get_option(self::OPT_KEY, []);
        if (empty($opts['api_key'])) wp_send_json_error('API key tanımlı değil');
        $sector_desc = $opts['sectors'][$sector] ?? 'genel';
        $prompt      = "Bu sektör için 20 adet popüler, aranabilir Türkçe anahtar kelime üret: {$sector_desc}. Sadece anahtar kelimeleri listele, numara verme.";
        $response    = $this->call_openai($opts['api_key'], $opts['model'], $prompt);
        if (!$response) wp_send_json_error('Anahtar kelimeler üretilemedi');
        $lines    = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $response)));
        $keywords = array_map(function($kw){ return preg_replace('/^[0-9\.\-\*\+\s]+/', '', $kw); }, $lines);
        wp_send_json_success(array_values(array_unique($keywords)));
    }

    public function ajax_preview() {
        check_ajax_referer('scp_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Yetki yok');

        $form = [];
        if (!empty($_POST['form_data'])) {
            parse_str($_POST['form_data'], $form);
        } else {
            $form = $_POST;
        }
        $sector = sanitize_text_field($form['sector'] ?? '');
        $keyword= sanitize_text_field($form['keyword'] ?? '');
        $model  = sanitize_text_field($form['model'] ?? 'gpt-4o-mini');

        if (!$sector || !$keyword) wp_send_json_error('Sektör ve anahtar kelime gerekli');

        $opts = get_option(self::OPT_KEY, []);
        if (empty($opts['api_key'])) wp_send_json_error('API key tanımlı değil');

        $sector_desc = $opts['sectors'][$sector] ?? 'genel';
        $task        = ['min_words' => 500, 'max_words' => 800];
        $prompts     = $this->build_prompts($keyword, $sector_desc, $task, $opts);

        $title   = $this->call_openai($opts['api_key'], $model, $prompts['title']);
        $excerpt = $this->call_openai($opts['api_key'], $model, $prompts['excerpt']);
        $content = $this->call_openai($opts['api_key'], $model, $prompts['content']);

        if (!$title || !$content) wp_send_json_error('İçerik üretilemedi');

        wp_send_json_success([
            'title'   => wp_strip_all_tags($title),
            'excerpt' => wp_strip_all_tags($excerpt),
            'content' => $content,
            'keyword' => $keyword,
            'sector'  => $sector,
            'model'   => $model
        ]);
    }

    public function ajax_approve_content() {
    check_ajax_referer('scp_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Yetki yok');

    $data = $_POST['preview_data'] ?? [];
    if (is_string($data)) $data = json_decode(stripslashes($data), true);

    $title   = wp_strip_all_tags($data['title'] ?? '');
    $excerpt = wp_strip_all_tags($data['excerpt'] ?? '');
    $content = wp_kses_post($data['content'] ?? '');
    $keyword = sanitize_text_field($data['keyword'] ?? '');

    if (!$title || !$content) wp_send_json_error('Eksik veri');

    $opts = get_option(self::OPT_KEY, []);
    // >>> tek fark: process_content’i keyword ile çağırıyoruz
    $content_processed = $this->process_content($content, $opts, $keyword);

    $post_data = [
        'post_title'   => $title,
        'post_content' => $content_processed,
        'post_excerpt' => $excerpt,
        'post_status'  => 'publish',
        'post_type'    => 'post',
        'meta_input'   => [
            '_scp_keyword'          => $keyword,
            '_scp_preview_approved' => current_time('mysql')
        ]
    ];
    if (!empty($opts['url_optimization'])) {
        $post_data['post_name'] = $this->create_seo_slug($title, $keyword);
    }

    $post_id = wp_insert_post($post_data, true);
    if (is_wp_error($post_id)) wp_send_json_error($post_id->get_error_message());

    wp_send_json_success([
        'post_id'   => $post_id,
        'edit_url'  => get_edit_post_link($post_id),
        'permalink' => get_permalink($post_id)
    ]);
}


    private function log_activity($task_id, $action, $message, $post_id = null, $tokens = 0, $cost = 0.0, $status = 'success') {
        global $wpdb;
        $table = $wpdb->prefix . self::LOG_TABLE;
        $wpdb->insert($table, [
            'task_id'     => $task_id,
            'action'      => $action,
            'status'      => $status,
            'message'     => $message,
            'post_id'     => $post_id,
            'tokens_used' => $tokens,
            'cost'        => $cost,
            'created_at'  => current_time('mysql')
        ]);
    }
    
    private function update_daily_stats($tokens, $cost, $model, $sector = '') {
    global $wpdb;
    $table = $wpdb->prefix . self::STATS_TABLE;
    $date  = current_time('Y-m-d');

    $wpdb->query($wpdb->prepare(
        "INSERT INTO $table (date, total_posts, total_tokens, total_cost, model, sector)
         VALUES (%s, 1, %d, %f, %s, %s)
         ON DUPLICATE KEY UPDATE
            total_posts  = total_posts + 1,
            total_tokens = total_tokens + %d,
            total_cost   = total_cost + %f",
        $date, $tokens, $cost, $model, $sector, $tokens, $cost
    ));
}


private function get_banned_regex() {
    // 'en uygun', 'bütçe dostu' gibi iki kelimelileri önce yakalamak için sırada önde
    $terms = [
        'en uygun','bütçe dostu','hesaplı','ucuz',
        'fiyatları','fiyatlar','fiyat',
        'ücretleri','ücretler','ücret',
        'maliyetleri','maliyetler','maliyet',
        'indirimleri','indirimler','indirim',
        'teklifleri','teklifler','teklif',
        'kampanyaları','kampanyalar','kampanya',
        '₺','\\$','€'
    ];
    $parts = array_map(function($t){
        return preg_quote($t, '/');
    }, $terms);
    return '/(' . implode('|', $parts) . ')/iu';
}

private function sanitize_keyword_for_content($kw) {
    $kw = trim((string)$kw);
    if ($kw === '') return $kw;
    // fiyat/ücret/maliyet vb. temizle
    $kw = preg_replace($this->get_banned_regex(), '', $kw);
    // yinelenen boşluk/ekleri düzelt
    $kw = preg_replace('/\s{2,}/u',' ', $kw);
    $kw = $this->dedupe_location_phrases($kw);
    $kw = trim($kw, "-–—,:;.!? ");
    return $kw ?: 'nakliyat';
}

private function sanitize_pricing_from_text($text) {
    $text = preg_replace($this->get_banned_regex(), '', $text);
    // '  ' -> ' '
    $text = preg_replace('/\s{2,}/u',' ', $text);
    return trim($text);
}

private function remove_pricing_from_html($html) {
    $pat = $this->get_banned_regex();

    // İçerikte yasak geçen <h2>/<h3>/<p>/<li> bloklarını komple sil
    foreach (['h2','h3','p','li'] as $tag) {
        $html = preg_replace_callback('#<'.$tag.'\b[^>]*>.*?</'.$tag.'>#is', function($m) use ($pat, $tag){
            return preg_match($pat, $m[0]) ? '' : $m[0];
        }, $html);
    }
    // Artık boş kalan başlıkları da ayıkla (örn. <h2></h2>)
    $html = preg_replace('#<h[23]\b[^>]*>\s*</h[23]>#i','', $html);
    $html = preg_replace('#<p>\s*</p>#i','', $html);
    // fazla boşluk
    $html = preg_replace('/\n{3,}/', "\n\n", $html);

    return $html;
}

private function dedupe_location_phrases($text) {
    // "tüm türkiye ... tüm türkiye için" vb.
    $t = mb_strtolower($text,'UTF-8');
    $t = preg_replace('/\btüm türkiye\b.*?\btüm türkiye için\b/iu', 'tüm türkiye', $t);
    $t = preg_replace('/\btüm türkiye için\b/iu', 'tüm türkiye', $t);
    $t = preg_replace('/\s{2,}/u', ' ', $t);
    return trim($t);
}



        private function get_dashboard_stats() {
        global $wpdb;
        $log_table = $wpdb->prefix . self::LOG_TABLE;

        return [
            'total_posts'  => (int) $wpdb->get_var("
                SELECT COUNT(*) 
                FROM $log_table 
                WHERE action = 'content_generated' AND status = 'success'
            "),
            'total_tokens' => (int) $wpdb->get_var("SELECT SUM(tokens_used) FROM $log_table"),
            'total_cost'   => (float) $wpdb->get_var("SELECT SUM(cost) FROM $log_table"),
            'active_tasks' => count(get_option(self::OPT_KEY, [])['tasks'] ?? []),
        ];
    }

    private function get_recent_generated_posts($limit = 5) {
        global $wpdb;
        $log_table = $wpdb->prefix . self::LOG_TABLE;

        $post_ids = $wpdb->get_col($wpdb->prepare("
            SELECT post_id 
            FROM $log_table 
            WHERE action = 'content_generated' 
              AND status = 'success' 
              AND post_id IS NOT NULL 
            ORDER BY created_at DESC 
            LIMIT %d
        ", $limit));

        if (empty($post_ids)) {
            return [];
        }

        return get_posts([
            'post__in'   => $post_ids,
            'orderby'    => 'post__in',
            'post_status'=> 'any',
            'numberposts'=> $limit,
        ]);
    }

    private function get_task_monthly_count($task_id) {
        global $wpdb;
        $log_table  = $wpdb->prefix . self::LOG_TABLE;
        $start_date = date('Y-m-01 00:00:00');

        return (int) $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) 
            FROM $log_table 
            WHERE task_id = %s 
              AND action = 'content_generated' 
              AND status = 'success' 
              AND created_at >= %s
        ", $task_id, $start_date));
    }

private function get_next_run_time($task_id) {
    $hook = self::CRON_PREFIX . $task_id;

    $timestamp = wp_next_scheduled($hook, [$task_id]);
    if (!$timestamp) $timestamp = wp_next_scheduled($hook);

    // WP saat dilimi ile göster
    return $timestamp ? wp_date('d.m.Y H:i', $timestamp, wp_timezone()) : 'Planlanmamış';
}


} // class Smart_Content_Pro

// Initialize plugin
new Smart_Content_Pro();
