<?php
if (!defined('ABSPATH')) {
    die();
}

function setup_mercury_post_types() {

    $txn_post_type_arguments = [
        'label'               => 'Cardano Transaction',
        'labels'              => [
            'name'               => sprintf(__('%s', mercury_text_domain), mercury_txn_plural),
            'singular_name'      => sprintf(__('%s', mercury_text_domain), mercury_txn_singular),
            'menu_name'          => sprintf(__('%s', mercury_text_domain), mercury_txn_plural),
            'all_items'          => sprintf(__('%s', mercury_text_domain), mercury_txn_plural),
            'add_new'            => __('Add New', mercury_text_domain),
            'add_new_item'       => sprintf(__('Add New %s', mercury_text_domain), mercury_txn_singular),
            'edit_item'          => sprintf(__('Edit %s', mercury_text_domain), mercury_txn_singular),
            'new_item'           => sprintf(__('New %s', mercury_text_domain), mercury_txn_singular),
            'view_item'          => sprintf(__('View %s', mercury_text_domain), mercury_txn_singular),
            'search_items'       => sprintf(__('Search %s', mercury_text_domain), mercury_txn_plural),
            'not_found'          => sprintf(__('No %s found', mercury_text_domain), mercury_txn_plural),
            'not_found_in_trash' => sprintf(__('No %s found in Trash', mercury_text_domain), mercury_txn_plural),
            'parent_item_colon'  => sprintf(__('Parent %s:', mercury_text_domain), mercury_txn_singular),
        ],
        'description'         => 'A record of a Cardano transaction',
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
        'rest_base'           => 'mercury/transaction',
        'menu_position'       => null,
        'menu_icon'           => 'dashicons-money-alt',
        'capability_type'     => 'page',
        'capabilities'        => [
            'read_posts'         => true,
            'read_private_posts' => true,
            'edit_posts'         => true,
            'create_posts'       => false,
        ],
        'map_meta_cap'        => false,
        'supports'            => [
            'title',
            'custom-fields',
        ],
    ];

    if (!post_type_exists('mercury-transaction')) {
        register_post_type('mercury-transaction', $txn_post_type_arguments);
    }

    add_filter('manage_edit-mercury-transaction_columns', 'transaction_custom_columns');
    add_action('manage_mercury-transaction_posts_custom_column', 'transaction_show_columns');

}

function transaction_custom_columns($columns) {
    $columns['meta_ada_value']       = 'Value';
    $columns['meta_payment_address'] = 'Address';
    $columns['meta_txn_source']      = 'Source';

    return $columns;
}

function transaction_show_columns($column) {
    global $post;

    switch ($column) {
        case 'post_id':
            echo $post->ID;
            break;
        case (preg_match('/^meta_/', $column) ? true : false):
            $x    = substr($column, 5);
            $meta = get_post_meta($post->ID, $x);
            echo join(', ', $meta);
            break;
        case 'title':
            echo $post->post_title;
            break;
    }
}