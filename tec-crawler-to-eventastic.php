<?php
/**
 * Plugin Name: Downtown Sioux City Events Sync
 * Description: Synchronizes events from Downtown Sioux City API into a custom post type.
 * Version: 1.0
 * Author: Madden Media
 */

if (!defined('WPINC')) {
    die;
}

class SiouxCityEventSync {
    private $api_url = 'https://downtownsiouxcity.com/wp-json/wp/v2/event'; // API URL

    public function __construct() {
        add_action('wp', [$this, 'init_cron_jobs']);
        add_action('sync_daily_events', [$this, 'fetch_update_events']);
        add_action('sync_weekly_events', [$this, 'fetch_future_events']);
    }

    public function init_cron_jobs() {
        if (!wp_next_scheduled('sync_daily_events')) {
            wp_schedule_event(time(), 'daily', 'sync_daily_events');
        }
        if (!wp_next_scheduled('sync_weekly_events')) {
            wp_schedule_event(time(), 'weekly', 'sync_weekly_events');
        }
    }

    public function fetch_update_events() {
        $response = wp_remote_get($this->api_url . '?per_page=100'); // Adjust per_page as needed
        if (is_wp_error($response)) {
            error_log('Failed to fetch events: ' . $response->get_error_message());
            return;
        }

        $events = json_decode(wp_remote_retrieve_body($response), true);
        foreach ($events as $event) {
            $this->create_update_event($event);
        }
    }

    public function fetch_future_events() {
        $today = date('Y-m-d');
        $three_months_later = date('Y-m-d', strtotime('+3 months'));
        $response = wp_remote_get($this->api_url . "?after={$today}&before={$three_months_later}&per_page=100");
        if (is_wp_error($response)) {
            error_log('Failed to fetch future events: ' . $response->get_error_message());
            return;
        }

        $events = json_decode(wp_remote_retrieve_body($response), true);
        foreach ($events as $event) {
            $this->create_update_event($event, true);
        }
    }

    private function create_update_event($event_data, $is_new = false) {
        $existing_post_id = $this->get_post_by_event_id($event_data['id']);

        // NEEED TO MATCH UP TO FIELDS IN EVENTASTIC!!!!
        $post_data = [
            'post_type' => 'event',
            'post_title' => sanitize_text_field($event_data['title']['rendered']),
            'post_content' => sanitize_textarea_field($event_data['acf']['overview']),
            'post_status' => 'publish',
            'meta_input' => [
                'event_id' => $event_data['id'],
                'event_modified' => $event_data['modified'],
                'start_date' => $event_data['acf']['start'],
                'end_date' => $event_data['acf']['end'],
                //MORE HERE!!!!!
            ],
        ];

        if ($existing_post_id && !$is_new) {
            $post_data['ID'] = $existing_post_id;
            wp_update_post($post_data);
        } else {
            wp_insert_post($post_data);
        }
    }

    private function get_post_by_event_id($event_id) {
        $query = new WP_Query([
            'post_type' => 'event',
            'meta_query' => [
                [
                    'key' => 'event_id',
                    'value' => $event_id,
                    'compare' => '=',
                ],
            ],
            'posts_per_page' => 1,
        ]);

        if ($query->have_posts()) {
            return $query->posts[0]->ID;
        }

        return false;
    }
}

new SiouxCityEventSync();
