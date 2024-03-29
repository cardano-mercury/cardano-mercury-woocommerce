<?php

namespace Mercury;

use stdClass;
use TaskManager;

if (!defined('ABSPATH')) {
    die();
}

class NativeAsset {

    const mercury_native_asset_plural = "Native Assets";
    const mercury_native_asset_singular = "Native Asset";

    const mercury_native_asset_post_type = "mercury-native-asset";

    const cron_log = ['source' => 'mercury_cron'];

    const cron_var = 'taptoolCronFrequency';

    /**
     * Singleton class instance.
     *
     * @return NativeAsset
     */
    public static function get_instance() {

        static $instance = null;

        if ($instance == null) {
            $instance = new self();
        }

        return $instance;
    }

    public static function init() {
        $options        = get_option('woocommerce_cardano_mercury_settings');
        $cron_frequency = $options[self::cron_var] ?? 60;
        if (!post_type_exists(self::mercury_native_asset_post_type)) {
            register_post_type(self::mercury_native_asset_post_type, self::get_post_type_args());
        }

        add_action('post_action_schedule_oracle', [
                __CLASS__,
                'schedule_oracle_update',
        ]);

        /* Fire our meta box setup function on the post editor screen. */
        add_action('load-post.php', [
                __CLASS__,
                'setup_meta_boxes',
        ]);
        add_action('load-post-new.php', [
                __CLASS__,
                'setup_meta_boxes',
        ]);

        add_action('mercury_asset_details', [
                __CLASS__,
                'fetch_asset_details',
        ]);

        add_action('mercury_get_price', [
                __CLASS__,
                'syncToken',
        ]);

        add_filter('manage_edit-' . self::mercury_native_asset_post_type . '_columns', [
                __CLASS__,
                'custom_columns',
        ]);
        add_filter('manage_' . self::mercury_native_asset_post_type . '_posts_custom_column', [
                __CLASS__,
                'show_columns',
        ]);

        add_filter('manage_edit-' . self::mercury_native_asset_post_type . '_sortable_columns', [
                __CLASS__,
                'sortable_columns',
        ]);

        add_filter('bulk_actions-edit-' . self::mercury_native_asset_post_type, static function ($bulk_actions) {
            return [
                    'set_accepted'     => __('Set Asset to Accepted', mercury_text_domain),
                    'set_not_accepted' => __('Set Asset to Not Accepted', mercury_text_domain),
            ];
        });

        add_filter('the_title', static function ($post_title, $post_id) {
            $the_post = get_post($post_id);
            if ($the_post && $the_post->post_type === self::mercury_native_asset_post_type) {
                return get_post_meta($post_id, 'ticker', true);
            }

            return $post_title;
        }, 10, 2);

        add_filter('post_row_actions', static function ($actions, $post) {
            if ($post->post_type == self::mercury_native_asset_post_type) {
                // Remove "Quick Edit"
                unset($actions['edit']);
                unset($actions['trash']);
                unset($actions['inline hide-if-no-js']);
            }

            return $actions;
        }, 10, 2);

        add_filter('handle_bulk_actions-edit-' . self::mercury_native_asset_post_type,
                static function ($redirect_url, $action, $post_ids) {
                    switch ($action) {
                        case 'set_accepted':
                            $accepted_value = 1;
                            break;
                        case 'set_not_accepted':
                            $accepted_value = 0;
                            break;
                        default:
                            return $redirect_url;
                    }

                    $update_count = 0;

                    foreach ($post_ids as $post_id) {
                        $update_count += update_post_meta($post_id, 'accepted', $accepted_value) ? 1 : 0;
                    }

                    if ($update_count) {
                        return add_query_arg('did_' . $action, $update_count, $redirect_url);
                    }

                    return $redirect_url;
                }, 10, 3);

        add_action('admin_notices', static function () {
            global $post;
            if ((!empty($_REQUEST['post_type']) && $_REQUEST['post_type'] === self::mercury_native_asset_post_type) || ($post && $post->post_type && $post->post_type === self::mercury_native_asset_post_type)) {
                printf('<div id="dyor_message" class="dyor notice notice-error is-dismissible"><p><strong>%s</strong></p><p>%s</p></div>',
                        __('WARNING: Do Your Own Research (DYOR)!', mercury_text_domain), __(<<<eof
The information about Cardano Native Assets is fetched from TapTools and other public information sources. While we normally
trust that data there are a variety of different ways (including manual modifications or malicious plugins) that this
information may be altered or modified. You should always verify this information yourself prior to marking a Native Asset
as an accepted payment type in your store.
eof, mercury_text_domain));
            }

            if (!empty($_REQUEST['did_set_accepted'])) {
                $num_changed = (int)$_REQUEST['did_set_accepted'];
                printf('<div id="set_accepted_message" class="updated notice is-dismissable"><p>' . __('Marked %d native assets as accepted by the store.',
                                mercury_text_domain) . '</p></div>', $num_changed);
            }

            if (!empty($_REQUEST['did_set_not_accepted'])) {
                $num_changed = (int)$_REQUEST['did_set_not_accepted'];
                printf('<div id="set_not_accepted_message" class="updated notice is-dismissable"><p>' . __('Marked %d native assets as NOT accepted by the store.',
                                mercury_text_domain) . '</p></div>', $num_changed);
            }
        });

        add_action('pre_get_posts', [
                __CLASS__,
                'customize_query',
        ]);

        if (class_exists('TaskManager')) {
            TaskManager::maybeSchedule('mercury_sync_assets', $cron_frequency);
            TaskManager::maybeSchedule('mercury_asset_details', 86400);
        }

    }

    public static function schedule_oracle_update($post_id) {
        global $post;
        $log = wc_get_logger();
        $log->debug("Scheduling an oracle update for...\r\n" . print_r($post, true), self::cron_log);

        Taskmanager::scheduleOnce('mercury_get_price', compact('post_id'));
    }

    public static function getToken($unit) {
        $token_id = post_exists($unit, '', '', self::mercury_native_asset_post_type);
        if (!$token_id) {
            return false;
        }

        $token      = get_post($token_id);
        $token_meta = get_post_meta($token_id);
        foreach ($token_meta as $key => $value) {
            $token->{$key} = $value[0];
        }

        return $token;
    }

    public static function formatQuantity($amount, $Token) {
        return round($amount / pow(10, $Token->decimals), $Token->decimals);
    }

    public static function fetch_asset_details() {
        $log = wc_get_logger();
        $log->info("Fetching asset details!", self::cron_log);
        $offset = 0;
        while ($token_registry = json_decode(file_get_contents('https://api.koios.rest/api/v1/asset_token_registry?select=policy_id,asset_name,ticker,description,url,decimals&offset=' . $offset))) {
            if (empty($token_registry)) {
                break;
            }
            foreach ($token_registry as $token) {
                $offset++;
                if (empty($token->ticker)) {
                    continue;
                }
                $asset              = new stdClass();
                $asset->unit        = $token->policy_id . $token->asset_name;
                $asset->ticker      = trim(trim($token->ticker), '$');
                $asset->description = $token->description;
                $asset->url         = $token->url;
                $asset->decimals    = $token->decimals;

                $token_id = post_exists($asset->unit, '', '', self::mercury_native_asset_post_type);
                if (!$token_id) {
                    $post_args = [
                            'post_date_gmt'  => date('Y-m-d H:i:s'),
                            'post_title'     => $asset->unit,
                            'post_status'    => 'publish',
                            'post_type'      => self::mercury_native_asset_post_type,
                            'comment_status' => 'closed',
                            'ping_status'    => 'closed',
                            'meta_input'     => [
                                    'accepted'    => 0,
                                    'price'       => 0,
                                    'ticker'      => $asset->ticker,
                                    'volume'      => 0,
                                    'unit'        => $asset->unit,
                                    // Extra input we can only get from the token registry (currently)
                                    'description' => $asset->description,
                                    'url'         => $asset->url,
                                    'decimals'    => $asset->decimals,
                            ],
                    ];

                    $token_id = wp_insert_post($post_args);
                }

                if ($token_id) {
                    update_post_meta($token_id, 'description', $asset->description);
                    update_post_meta($token_id, 'url', $asset->url);
                    update_post_meta($token_id, 'decimals', $asset->decimals);
                }
            }
        }
        $log->info("Fetched a total of {$offset} tokens from the Cardano Token Registry", self::cron_log);
    }

    public static function setup_meta_boxes() {
        add_action('add_meta_boxes', [
                __CLASS__,
                'add_boxes',
        ]);
    }

    public static function add_boxes() {
        add_meta_box('native-asset-identifier', __('Asset Identifier', mercury_text_domain), [
                __CLASS__,
                'render_identifier_box',
        ], self::mercury_native_asset_post_type, 'advanced', 'core');

        add_meta_box('native-asset-details', __('Asset Details', mercury_text_domain), [
                __CLASS__,
                'render_details_box',
        ], self::mercury_native_asset_post_type, 'advanced', 'core');

        add_meta_box('native-asset-price', __('Price Details', mercury_text_domain), [
                __CLASS__,
                'render_price_box',
        ], self::mercury_native_asset_post_type, 'advanced', 'core');
    }

    public static function render_identifier_box($post) {
        ?>
        <style>
            #titlediv, #post-body-content {
                display: none;
            }

            .flex {
                display: flex;
                flex-flow: row wrap;
            }

            .flex .col {
                padding: 0 1em
            }

            .flex .col:first-child {
                padding-left: 0
            }

            .flex .full {
                padding: 0;
                width: 100%;
            }

            strong {
                margin-right: 0.2em;
            }
        </style>
        <p>
            <label for="policy_id">Policy ID</label>
            <br/>
            <input class="widefat" type="text" name="policy_id" id="policy_id" value="<?php
            echo esc_attr(substr(get_post_meta($post->ID, 'unit', true), 0, 56)); ?>" size="30" readonly/>
        </p>
        <p>
            <label for="asset_id">Asset ID</label>
            <br/>
            <input class="widefat" type="text" name="asset_id" id="asset_id" value="<?php
            echo esc_attr(substr(get_post_meta($post->ID, 'unit', true), 56)); ?>" size="32" readonly/>
        </p>
        <?php
    }

    public static function render_details_box($post) {
        ?>
        <div class="flex">
            <div class="col">
                <label for="ticker">Ticker</label>
                <input class="widefat" type="text" name="ticker" id="ticker"
                       value="<?= esc_attr(get_post_meta($post->ID, 'ticker', true)); ?>" size="30" readonly/>
            </div>
            <div class="col">
                <label for="decimals">Decimals</label>
                <input class="widefat" type="text" name="decimals" id="decimals"
                       value="<?= esc_attr(get_post_meta($post->ID, 'decimals', true)); ?>" size="30" readonly/>
            </div>
            <div class="col">
                <label for="description">Description</label>
                <input class="widefat" type="text" name="description" id="description"
                       value="<?= esc_attr(get_post_meta($post->ID, 'description', true)); ?>" size="120" readonly/>
            </div>
            <?php
            if ($url = get_post_meta($post->ID, 'url', true)): ?>
                <div class="col">
                    <label>Project Website</label>
                    <br/>
                    <a href="<?= $url; ?>" target="_blank">Visit Website: <?= $url; ?></a>
                </div>
            <?php
            endif; ?>
        </div>
        <?php
    }

    public static function render_price_box($post) {
        $ticker   = get_post_meta($post->ID, 'ticker', true);
        $price    = (float)get_post_meta($post->ID, 'price', true);
        $decimals = (int)get_post_meta($post->ID, 'decimals', true);
        $ADAPrice = Pricefeeder::getAveragePrice()['price'];
        global $sendback;
        ?>
        <div class="flex">
            <div class="full flex" style="justify-content: start; align-items: center; align-content: center">
                <img src="<?= mercury_url; ?>/tt-logo.png" height="36px" alt="TapTools" style="padding-right: 1em;"/>
                <strong>Cardano Mercury for WooCommerce</strong> native asset price data is powered by
                <a href="https://taptools.io" target="_blank">TapTools</a>
            </div>
            <div class="col">
                <p>
                    <label for="current_price">Latest Price</label>
                    <input class="widefat" type="text" name="current_price" id="current_price" value="<?= $price; ?>"
                           size="30" readonly/>
                </p>
                <p>
                    <label for="30d_volume">30d Volume</label>
                    <input class="widefat" type="text" name="30d_volume" id="30d_volume"
                           value="<?= esc_attr(get_post_meta($post->ID, 'volume', true)); ?>" size="30" readonly/>
                </p>
            </div>
            <?php
            if ($price > 0): ?>
            <div class="col">
                <p>
                    <label for="na_per_ada">$<?= $ticker; ?> per 1₳</label>
                    <input class="widefat" type="text" name="na_per_ada" id="na_per_ada"
                           value="<?= number_format(1 / $price, $decimals); ?>" size="30" readonly/>
                </p>
                <p>
                    <label for="na_per_usd">$<?= $ticker; ?> per $1 USD</label>
                    <input class="widefat" type="text" name="na_per_usd" id="na_per_usd"
                           value="<?= number_format((1 / $price) / $ADAPrice, $decimals); ?>" size="30" readonly/>
                </p>
            </div><?php
            else:

            ?>
                <div class="col">
                    <p>
                        No price info found, use the button below to schedule an update using the TapTools API.
                    </p>
                    <button type="button" class="button button-primary button-large update-oracle"
                            data-post-id="<?= $post->ID; ?>" data-action-name="schedule_oracle">
                        Schedule Price Update
                    </button>
                    <!--                    <form method="get">-->
                    <!--                        <input type="hidden" name="post" id="post" value="--><?php
                    //= $post->ID;
                    ?><!--"/>-->
                    <!--                        <input type="hidden" name="action" id="action" value="schedule_oracle"/>-->
                    <!--                        <button type="submit" class="button button-primary button-large">-->
                    <!--                            Schedule Price Update-->
                    <!--                        </button>-->
                    <!--                    </form>-->
                </div>
                <script>
                    window.addEventListener('load', (event) => {
                        // console.log('Load Event', event);
                        const edit_url = '<?= get_admin_url('post.php'); ?>';
                        jQuery(document).ready(() => {
                            // console.log("jQuery", jQuery);
                            jQuery('.update-oracle').on('click', async ($e) => {
                                console.log("Update oracle clicked!", $e);
                                const schedule_url = `${edit_url}post.php?post=${$e.target.dataset.postId}&action=${$e.target.dataset.actionName}`
                                console.log("ajax URL", schedule_url);
                                const response = await jQuery.get(schedule_url);
                                console.log("What is the response?", response);
                            });
                        });
                    });
                </script>
            <?php
            endif; ?>
        </div>
        <?php
    }

    private static function get_post_type_args() {
        return [
                'label'               => 'Cardano Transaction',
                'labels'              => [
                        'name'               => sprintf(__('%s', mercury_text_domain),
                                self::mercury_native_asset_plural),
                        'singular_name'      => sprintf(__('%s', mercury_text_domain),
                                self::mercury_native_asset_singular),
                        'menu_name'          => sprintf(__('%s', mercury_text_domain),
                                self::mercury_native_asset_plural),
                        'all_items'          => sprintf(__('%s', mercury_text_domain),
                                self::mercury_native_asset_plural),
                        'add_new'            => __('Add New', mercury_text_domain),
                        'add_new_item'       => sprintf(__('Add New %s', mercury_text_domain),
                                self::mercury_native_asset_singular),
                        'edit_item'          => sprintf(__('View %s', mercury_text_domain),
                                self::mercury_native_asset_singular),
                        'new_item'           => sprintf(__('New %s', mercury_text_domain),
                                self::mercury_native_asset_singular),
                        'view_item'          => sprintf(__('View %s', mercury_text_domain),
                                self::mercury_native_asset_singular),
                        'search_items'       => sprintf(__('Search %s', mercury_text_domain),
                                self::mercury_native_asset_plural),
                        'not_found'          => sprintf(__('No %s found', mercury_text_domain),
                                self::mercury_native_asset_plural),
                        'not_found_in_trash' => sprintf(__('No %s found in Trash', mercury_text_domain),
                                self::mercury_native_asset_plural),
                        'parent_item_colon'  => sprintf(__('Parent %s:', mercury_text_domain),
                                self::mercury_native_asset_singular),
                ],
                'description'         => 'A record of Cardano Native Assets',
                'has_archive'         => false,
                'public'              => false,
                'hierarchical'        => false,
                'exclude_from_search' => true,
                'publicly_queryable'  => false,
                'show_ui'             => true,
                'show_in_menu'        => true,
                'show_in_nav_menus'   => true,
                'show_in_admin_bar'   => false,
                'show_in_rest'        => true,
                'rest_base'           => 'mercury/asset',
                'menu_position'       => null,
                'menu_icon'           => 'dashicons-money-alt',
                'capability_type'     => 'page',
                'capabilities'        => [
//                        'read'                   => true,
//                        'read_private_pages'     => true,
//                        'read_private_posts'     => true,
'delete_posts'           => false,
'delete_pages'           => false,
'delete_private_pages'   => false,
'delete_private_posts'   => false,
'delete_published_pages' => false,
'delete_published_posts' => false,
'delete_others_pages'    => false,
'delete_others_posts'    => false,
//                        'edit_others_pages'      => true,
//                        'edit_others_posts'      => true,
//                        'edit_pages'             => true,
//                        'edit_posts'             => true,
//                        'edit_private_pages'     => true,
//                        'edit_private_posts'     => true,
//                        'edit_published_pages'   => true,
//                        'edit_published_posts'   => true,

                ],
                //            'capabilities'        => [
                //                'read_posts'         => true,
                //                'read_private_posts' => true,
                //                'edit_posts'         => true,
                //                'create_posts'       => false,
                //            ],
                'map_meta_cap'        => true,
                'supports'            => [
                        'title',
                        //'custom-fields',
                ],
        ];
    }

    public static function customize_query($query) {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        if ($query->get('post_type') == self::mercury_native_asset_post_type) {

            $orderby = $query->get('orderby');

            if (!$orderby) {
                $orderby = 'na_volume';
                $query->set('order', 'DESC');
            }

            switch ($orderby) {
                case 'title':
                case 'na_ticker':
                    $query->set('orderby', 'meta_value');
                    $query->set('meta_key', 'ticker');
                    break;
                case 'na_price':
                    $query->set('orderby', 'meta_value');
                    $query->set('meta_key', 'price');
                    $query->set('meta_type', 'numeric');
                    break;
                case 'na_volume':
                    $query->set('orderby', 'meta_value');
                    $query->set('meta_key', 'volume');
                    $query->set('meta_type', 'numeric');
                    break;
                case 'na_accepted':
                    $query->set('orderby', 'meta_value');
                    $query->set('meta_key', 'accepted');
                    $query->set('meta_type', 'numeric');
                    break;
            }

            $search_term = trim($query->query_vars['s']);
            // Set to empty, otherwise it won't find anything
            $query->query_vars['s'] = '';

            if (!empty($search_term)) {
                $meta_query = [
                        'relation' => 'OR',
                        [
                                'key'     => 'ticker',
                                'value'   => $search_term,
                                'compare' => 'LIKE',
                        ],
                        [
                                'key'     => 'unit',
                                'value'   => $search_term,
                                'compare' => 'LIKE',
                        ],
                ];

                $query->set('meta_query', $meta_query);
            }
        }
    }

    public static function custom_columns($columns) {
        unset($columns['date']);
//        unset($columns['title']);

        $columns['meta_accepted'] = 'Accepted';
        $columns['meta_price']    = 'Price';
        $columns['meta_volume']   = 'Volume';
        $columns['title']         = 'Ticker';
        $columns['date']          = 'Date';

        return $columns;
    }

    public static function sortable_columns($columns) {
        $columns['meta_ticker']   = 'na_ticker';
        $columns['meta_accepted'] = 'na_accepted';
        $columns['meta_volume']   = 'na_volume';

        return $columns;
    }

    public static function show_columns($column) {
        global $post;

        switch ($column) {
            case 'post_id':
                echo $post->ID;
                break;
            case (bool)preg_match('/^meta_/', $column):
                $x    = substr($column, 5);
                $meta = get_post_meta($post->ID, $x, true);
                switch ($x) {
                    case 'price':
                        echo (float)$meta;
                        break;
                    case 'volume':
                        echo number_format((float)$meta, 6);
                        break;
                    case 'accepted':
                        echo $meta ? 'Yes' : 'No';
                        break;
                    case 'ticker':
                        echo "<strong style='font-size: larger'>{$meta}</strong>";
                        break;
                    default:
                        echo $meta;
                        break;
                }
                break;
//            case 'title':
//                echo get_post_meta($post->ID, 'ticker', true);
//                break;
        }

    }

    public static function getAPIKey() {
        $settings = get_option('woocommerce_cardano_mercury_settings');

        return $settings['taptoolsAPIKey'];
    }

    public static function checkPrices() {
        $log = wc_get_logger();
        $log->info("Checking prices for accepted native assets!", self::cron_log);

        $posts = get_posts([
                'numberposts' => -1,
                'post_type'   => self::mercury_native_asset_post_type,
                'meta_key'    => 'accepted',
                'meta_value'  => 1,
        ]);
    }

    public static function syncToken($post_id) {
        $log      = wc_get_logger();
        $the_post = get_post($post_id);

        if (!$the_post) {
            $log->error("Could not find a post w/ ID: {$post_id}", self::cron_log);
        }

//        $log->debug("Attempting to sync individual token information\r\n" . print_r($the_post, true), self::cron_log);

        $TT = new TapTools(self::getAPIKey());

        $prices = $TT->getTokenPrices([$the_post->post_title]);

        if ($prices->data) {
            foreach ($prices->data as $unit => $price) {
                $token_id = post_exists($unit, '', '', self::mercury_native_asset_post_type);
                if ($token_id) {
                    update_post_meta($token_id, 'price', $price);
                }
            }
        }

        $log->debug("Found prices?\r\n" . print_r($prices, true), self::cron_log);

        $volume_stats = $TT->getTokenVolume($the_post->post_title);

        if ($volume_stats->data) {
            $details = $volume_stats->data[0];
            update_post_meta($post_id, 'volume', $details->volume);
        }

        $log->debug("Found volume?\r\n" . print_r($volume_stats, true), self::cron_log);
    }

    public static function sync() {
        $log = wc_get_logger();

        $log->debug("Attempting Native Asset sync!", self::cron_log);

        $taptools_api_key = self::getAPIKey();

        $log->debug("TapTools API Key: {$taptools_api_key}", self::cron_log);

        $TT = new TapTools($taptools_api_key);

        $top_tokens = $TT->getTopVolume('30d', 1, 100);

        if (!count($top_tokens->data)) {
            $log->debug("No results returned?!", self::cron_log);

            return;
        }

        foreach ($top_tokens->data as $token) {
            $token_id = post_exists($token->unit, '', '', self::mercury_native_asset_post_type);
            if (!$token_id) {
                $post_args = [
                        'post_date_gmt'  => date('Y-m-d H:i:s'),
                        'post_title'     => $token->unit,
                        'post_status'    => 'publish',
                        'post_type'      => self::mercury_native_asset_post_type,
                        'comment_status' => 'closed',
                        'ping_status'    => 'closed',
                        'meta_input'     => [
                                'accepted' => 0,
                                'price'    => $token->price,
                                'ticker'   => trim($token->ticker, '$'),
                                'volume'   => $token->volume,
                                'unit'     => $token->unit,
                        ],
                ];

                $token_id = wp_insert_post($post_args);
            }

            if ($token_id) {
                // Update the metadata here...
                update_post_meta($token_id, 'price', $token->price);
                update_post_meta($token_id, 'ticker', trim($token->ticker, '$'));
                update_post_meta($token_id, 'volume', $token->volume);
                update_post_meta($token_id, 'unit', $token->unit);
            }
        }
    }

}